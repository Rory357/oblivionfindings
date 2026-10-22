<?php

use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralServiceAgreement;
use App\Domain\Referrals\Services\ReferralAgreementTerms;
use App\Domain\Referrals\Services\ReferralProposals;
use App\Domain\Referrals\Services\ReferralServiceAgreements;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceAgreement;
use App\Models\ServiceAgreementStatusChange;
use App\Models\User;
use App\Notifications\AppEventNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralAgreementFixtures.php';

/** Weekly periods the agreement must cover for the fixture proposal (today until a month from today). */
function referralAgreementWeeks(array $f): int
{
    $terms = $f['proposal']->proposal;

    return app(ReferralAgreementTerms::class)->periods(CarbonImmutable::parse($terms['starts_on'], 'Pacific/Auckland'),
        CarbonImmutable::parse($terms['ends_on'], 'Pacific/Auckland'), 'week');
}

function referralAgreementError(callable $action): HttpException
{
    try {
        $action();
    } catch (HttpException $exception) {
        return $exception;
    }
    throw new RuntimeException('Expected the agreement command to be refused.');
}

it('creates the person’s draft agreement from the approved, accepted proposal without retyping and records it', function () {
    $f = referralAgreementFixture();
    $service = app(ReferralServiceAgreements::class);
    $before = $f['referral']->fresh()->lock_version;
    $preview = $service->inspect($f['author'], $f['referral']->fresh());
    expect($preview['state'])->toBe('ready')->and($preview['can_create'])->toBeTrue()->and($preview['blockers'])->toBe([])
        ->and($preview['preview']['lines'][0]['issue'])->toBeNull();

    $receipt = $service->create($f['author'], $f['referral']->id, $before, referralAgreementInput($f));
    $weeks = referralAgreementWeeks($f);
    $agreement = ServiceAgreement::query()->findOrFail($receipt['agreement']['id']);
    $line = $agreement->lineItems()->sole();
    $terms = $f['proposal']->proposal;

    // Never auto-activated: Finance's own submit and independent approval make it active.
    expect($receipt['replayed'])->toBeFalse()->and($agreement->status)->toBe('draft')
        ->and($agreement->approved_at)->toBeNull()->and($agreement->submitted_for_approval_at)->toBeNull()
        ->and((int) $agreement->client_id)->toBe($f['person']->id)->and((int) $agreement->created_by)->toBe($f['author']->id)
        ->and($agreement->title)->toBe('Supported living plan')->and($agreement->agreement_type)->toBe('nasc')->and($agreement->funding_body)->toBe('DSS / NASC')
        ->and($agreement->starts_at->toDateString())->toBe($terms['starts_on'])->and($agreement->ends_at->toDateString())->toBe($terms['ends_on'])
        ->and($agreement->gst_inclusive)->toBeFalse()->and($agreement->total_budget)->toBe(number_format(480 * $weeks, 2, '.', ''))
        ->and($agreement->budget_used)->toBe('0.00')->and($agreement->hourly_rate)->toBe('60.00')
        ->and($agreement->allocated_hours_per_week)->toBe('8.00')->and($agreement->total_hours)->toBe(number_format(8 * $weeks, 2, '.', ''))
        ->and((int) $agreement->source_referral_id)->toBe($f['referral']->id)->and((int) $agreement->source_referral_proposal_id)->toBe($f['proposal']->id)
        ->and($agreement->notes)->toContain($f['referral']->reference);
    expect($line->description)->toBe('Daily living support')->and($line->unit)->toBe('hour')->and($line->unit_price)->toBe('60.00')
        ->and($line->quantity)->toBe(number_format(8 * $weeks, 2, '.', ''))->and($line->budget_allocated)->toBe(number_format(480 * $weeks, 2, '.', ''))
        ->and($line->budget_used)->toBe('0.00')->and($line->category)->toBe('Supported living');

    // The draft already satisfies the service-start terms check once Finance approves it.
    $match = app(ReferralAgreementTerms::class)->match($f['proposal'], $agreement, [['line_key' => 'daily-support', 'line_item_id' => $line->id]]);
    expect($match['ready'])->toBeTrue()->and($match['issues'])->toBe([]);

    $link = ReferralServiceAgreement::query()->sole();
    $event = ReferralEvent::query()->where('referral_id', $f['referral']->id)->where('kind', 'proposal.agreement_created')->sole();
    expect((int) $link->service_agreement_id)->toBe($agreement->id)->and($link->proposal_version)->toBe(1)
        ->and((int) $link->created_by)->toBe($f['author']->id)->and($link->mapping_digest)->toHaveLength(64)
        ->and($event->audience)->toBe('funding')->and((int) $event->payload['service_agreement_id'])->toBe($agreement->id)
        ->and($event->payload['status'])->toBe('draft')->and($event->payload['proposal_version'])->toBe(1)
        ->and(ServiceAgreementStatusChange::query()->where('service_agreement_id', $agreement->id)->sole()->to_status)->toBe('draft')
        ->and($f['referral']->fresh()->lock_version)->toBe($before + 1);

    $after = $service->inspect($f['author'], $f['referral']->fresh());
    expect($after['state'])->toBe('created')->and($after['agreement']['status'])->toBe('draft')
        ->and($after['agreement']['outdated'])->toBeFalse()->and($after['agreement']['can_open'])->toBeTrue();
});

it('returns the same agreement when the same proposal version is submitted again', function () {
    $f = referralAgreementFixture();
    $service = app(ReferralServiceAgreements::class);
    $input = referralAgreementInput($f);
    $first = $service->create($f['author'], $f['referral']->id, $input['version'], $input);
    // A retry with the now-stale referral version still returns the existing result.
    $second = $service->create($f['author'], $f['referral']->id, $input['version'], $input);
    expect($second['replayed'])->toBeTrue()->and($second['agreement']['id'])->toBe($first['agreement']['id'])
        ->and(ServiceAgreement::query()->count())->toBe(1)->and(ReferralServiceAgreement::query()->count())->toBe(1)
        ->and(ReferralEvent::query()->where('kind', 'proposal.agreement_created')->count())->toBe(1)
        ->and($f['referral']->fresh()->lock_version)->toBe($input['version'] + 1);
});

it('requires the referral to be handed over to a person first', function () {
    $f = referralAgreementFixture(convert: false);
    $service = app(ReferralServiceAgreements::class);
    expect($service->inspect($f['author'], $f['referral']->fresh())['state'])->toBe('not_converted');
    $error = referralAgreementError(fn () => $service->create($f['author'], $f['referral']->id, $f['referral']->fresh()->lock_version, referralAgreementInput($f)));
    expect($error->getStatusCode())->toBe(422)->and($error->getMessage())->toContain('Complete the onboarding handover first')
        ->and(ServiceAgreement::query()->count())->toBe(0);
});

it('requires current internal approval and the person’s acceptance of the version', function () {
    $f = referralAgreementFixture(accept: false, approve: false);
    $service = app(ReferralServiceAgreements::class);
    $preview = $service->inspect($f['author'], $f['referral']->fresh());
    expect($preview['state'])->toBe('blocked')
        ->and($preview['blockers'])->toContain('Proposal version 1 needs current independent internal approval.')
        ->and($preview['blockers'])->toContain('Proposal version 1 needs the person’s current acceptance.');
    $error = referralAgreementError(fn () => $service->create($f['author'], $f['referral']->id, $f['referral']->fresh()->lock_version, referralAgreementInput($f)));
    expect($error->getStatusCode())->toBe(422)->and($error->getMessage())->toContain('independent internal approval')
        ->and(ServiceAgreement::query()->count())->toBe(0)->and(ReferralServiceAgreement::query()->count())->toBe(0);
});

it('blocks a line without a price with a clear reason instead of writing zero', function () {
    $f = referralAgreementFixture();
    $input = referralProposalInput($f['tax'], $f['case'], $f['decision']);
    $input['previous_proposal_id'] = $f['proposal']->id;
    $input['reason'] = 'Add evening support that still needs a price';
    $input['lines'][] = [...$input['lines'][0], 'key' => 'evening', 'description' => 'Evening support', 'quantity' => '2', 'unit_rate' => null, 'tax_rate_id' => null, 'price_basis' => 'unknown'];
    $revision = app(ReferralProposals::class)->save($f['author'], $f['referral']->id, $f['referral']->fresh()->lock_version, $input);
    $service = app(ReferralServiceAgreements::class);
    $preview = $service->inspect($f['author'], $f['referral']->fresh());
    $unpriced = collect($preview['preview']['lines'])->firstWhere('key', 'evening');
    expect($preview['state'])->toBe('blocked')->and($unpriced['issue'])->toContain('never created at $0')
        ->and($unpriced['budget'])->toBeNull()->and($unpriced['unit_price'])->toBeNull()
        ->and(collect($preview['blockers'])->contains(fn ($blocker) => str_starts_with($blocker, 'Evening support: No price yet')))->toBeTrue();
    $error = referralAgreementError(fn () => $service->create($f['author'], $f['referral']->id, $f['referral']->fresh()->lock_version,
        ['proposal_id' => $revision->id, 'proposal_version' => 2, 'acknowledge_draft' => true]));
    expect($error->getStatusCode())->toBe(422)->and($error->getMessage())->toContain('Evening support: No price yet')
        ->and(ServiceAgreement::query()->count())->toBe(0);
});

it('warns that an agreement is from an older version and never edits it', function () {
    $f = referralAgreementFixture();
    $service = app(ReferralServiceAgreements::class);
    $first = $service->create($f['author'], $f['referral']->id, $f['referral']->fresh()->lock_version, referralAgreementInput($f));
    $original = ServiceAgreement::query()->with('lineItems')->findOrFail($first['agreement']['id']);
    $snapshot = app(ReferralAgreementTerms::class)->fingerprint($original);

    $proposals = app(ReferralProposals::class);
    $input = referralProposalInput($f['tax'], $f['case'], $f['decision']);
    $input['previous_proposal_id'] = $f['proposal']->id;
    $input['reason'] = 'The agreed hourly rate changed after review';
    $input['lines'][0]['unit_rate'] = '62';
    $revision = $proposals->save($f['author'], $f['referral']->id, $f['referral']->fresh()->lock_version, $input);

    $inspect = $service->inspect($f['author'], $f['referral']->fresh());
    expect($inspect['state'])->toBe('blocked')->and($inspect['agreement']['outdated'])->toBeTrue()
        ->and($inspect['agreement']['warning'])->toStartWith('Agreement is from version 1; review it.')
        ->and(app(ReferralAgreementTerms::class)->fingerprint($original->fresh()))->toBe($snapshot);

    $proposals->accept($f['author'], $f['referral']->id, $f['referral']->fresh()->lock_version, $revision->id, referralProposalAcceptance($f['evidence']));
    $proposals->approve($f['approver'], $f['referral']->id, $f['referral']->fresh()->lock_version, $revision->id, referralProposalApproval());
    expect($service->inspect($f['author'], $f['referral']->fresh())['state'])->toBe('ready');

    $next = ['proposal_id' => $revision->id, 'proposal_version' => 2, 'acknowledge_draft' => true];
    $error = referralAgreementError(fn () => $service->create($f['author'], $f['referral']->id, $f['referral']->fresh()->lock_version, $next));
    expect($error->getStatusCode())->toBe(422)->and($error->getMessage())->toContain('will not be changed');

    $second = $service->create($f['author'], $f['referral']->id, $f['referral']->fresh()->lock_version, [...$next, 'acknowledge_earlier_agreement' => true]);
    expect($second['agreement']['id'])->not->toBe($first['agreement']['id'])
        ->and(app(ReferralAgreementTerms::class)->fingerprint($original->fresh()))->toBe($snapshot)
        ->and($original->fresh()->status)->toBe('draft')
        ->and(ReferralServiceAgreement::query()->orderBy('proposal_version')->pluck('proposal_version')->all())->toBe([1, 2]);
    $latest = $service->inspect($f['author'], $f['referral']->fresh());
    expect($latest['state'])->toBe('created')->and($latest['agreement']['proposal_version'])->toBe(2)
        ->and($latest['earlier'])->toHaveCount(1)->and($latest['earlier'][0]['proposal_version'])->toBe(1);
});

it('needs the referral handover rights and Finance’s agreement permission', function () {
    $f = referralAgreementFixture(agreementPermissions: []);
    $service = app(ReferralServiceAgreements::class);
    $preview = $service->inspect($f['author'], $f['referral']->fresh());
    expect($preview['can_create'])->toBeFalse()->and($preview['permission_reason'])->toContain('permission to create service agreements');
    $error = referralAgreementError(fn () => $service->create($f['author'], $f['referral']->id, $f['referral']->fresh()->lock_version, referralAgreementInput($f)));
    expect($error->getStatusCode())->toBe(403);

    // Agreement rights without the referral handover right are not enough either.
    handoverCanonicalPermissions($f['author'], ['service_agreements.create', 'service_agreements.viewAny']);
    $f['author']->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.convert')->firstOrFail()->id, ['allowed' => false]);
    $author = $f['author']->fresh();
    expect($service->inspect($author, $f['referral']->fresh())['permission_reason'])->toContain('hand over to onboarding');
    $error = referralAgreementError(fn () => $service->create($author, $f['referral']->id, $f['referral']->fresh()->lock_version, referralAgreementInput($f)));
    expect($error->getStatusCode())->toBe(403)->and(ServiceAgreement::query()->count())->toBe(0);
});

it('serves the review and creates the draft through the referral endpoints', function () {
    $f = referralAgreementFixture();
    $reference = $f['referral']->reference;
    $this->actingAs($f['author'])->getJson("/operations/referrals/{$reference}/service-agreement")
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('state', 'ready')->assertJsonPath('can_create', true)
        ->assertJsonPath('preview.lines.0.unit', 'hour')->assertJsonPath('preview.header.agreement_type', 'nasc')
        ->assertJsonCount(5, 'approval_steps');
    $this->actingAs($f['author'])->postJson("/operations/referrals/{$reference}/service-agreement", referralAgreementInput($f))
        ->assertOk()->assertJsonPath('saved', true)->assertJsonPath('agreement.status', 'draft');
    // A colleague who reads the funding but cannot create agreements sees the status only.
    $this->actingAs($f['approver'])->getJson("/operations/referrals/{$reference}/service-agreement")
        ->assertOk()->assertJsonPath('state', 'created')->assertJsonPath('can_create', false);
    expect(ServiceAgreement::query()->count())->toBe(1);
});

/** A user holding only the named role (no per-user overrides), with a current HR profile at the site. */
function referralAgreementRoleUser(array $f, string $roleName): User
{
    $user = User::factory()->create(['approved_at' => now(), 'role' => $roleName]);
    ensureCanonicalHrStaffProfile($user, $f['site']);
    $user->roles()->attach(Role::query()->firstOrCreate(['name' => $roleName], ['label' => ucfirst($roleName)]));

    return $user->fresh();
}

it('lets provider managers create the draft and keeps approval independent of its creator and submitter', function () {
    $f = referralAgreementFixture(agreementPermissions: []);
    foreach (['provider_manager', 'finance', 'coordinator'] as $name) {
        Role::query()->firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
    }
    (require base_path('database/migrations/2026_10_20_090000_grant_referral_role_presets.php'))->up();
    $grant = require base_path('database/migrations/2026_10_21_130000_grant_provider_manager_service_agreements.php');
    $grant->up();
    $grant->up();
    $financeRead = require base_path('database/migrations/2026_10_23_090000_grant_finance_service_agreement_view.php');
    $financeRead->up();
    $financeRead->up();
    $agreementKeys = fn (string $name) => Role::query()->where('name', $name)->firstOrFail()->permissions()
        ->where('key', 'like', 'service_agreements.%')->pluck('key')->sort()->values()->all();
    // Provider managers create and approve (Stephan, 21 Oct 2026); Finance only reads, so its notice opens
    // (23 Oct 2026); coordinators get nothing.
    expect($agreementKeys('provider_manager'))->toBe(['service_agreements.create', 'service_agreements.update', 'service_agreements.viewAny'])
        ->and($agreementKeys('finance'))->toBe(['service_agreements.viewAny'])->and($agreementKeys('coordinator'))->toBe([]);
    // The person-record access a seeded provider manager already holds (RbacSeeder).
    Role::query()->where('name', 'provider_manager')->firstOrFail()->permissions()
        ->syncWithoutDetaching(Permission::query()->whereIn('key', ['clients.viewAny', 'clients.update'])->pluck('id')->all());
    [$creator, $submitter, $approver] = [referralAgreementRoleUser($f, 'provider_manager'), referralAgreementRoleUser($f, 'provider_manager'), referralAgreementRoleUser($f, 'provider_manager')];

    $service = app(ReferralServiceAgreements::class);
    expect($service->inspect($creator, $f['referral']->fresh())['can_create'])->toBeTrue();
    $receipt = $service->create($creator, $f['referral']->id, $f['referral']->fresh()->lock_version, referralAgreementInput($f));
    $agreement = ServiceAgreement::query()->findOrFail($receipt['agreement']['id']);
    expect((int) $agreement->created_by)->toBe($creator->id)->and($agreement->status)->toBe('draft');

    $this->actingAs($submitter)->post(route('operations.service_agreements.submit_for_approval', $agreement))->assertRedirect();
    // Neither the creator nor the submitter may approve; a third provider manager can.
    $this->actingAs($creator)->post(route('operations.service_agreements.approve', $agreement))->assertForbidden();
    $this->actingAs($submitter)->post(route('operations.service_agreements.approve', $agreement))->assertForbidden();
    expect($agreement->fresh()->status)->toBe('pending_approval');
    $this->actingAs($approver)->post(route('operations.service_agreements.approve', $agreement))->assertRedirect();
    expect($agreement->fresh()->status)->toBe('active')->and((int) $agreement->fresh()->approved_by)->toBe($approver->id);
});

it('notifies only the finance role group that a draft from the referral needs review', function () {
    $f = referralAgreementFixture();
    $finance = referralAgreementRoleUser($f, 'finance');
    $admin = referralAgreementRoleUser($f, 'admin');
    Notification::fake();
    $service = app(ReferralServiceAgreements::class);
    $input = referralAgreementInput($f);
    $receipt = $service->create($f['author'], $f['referral']->id, $input['version'], $input);
    $id = $receipt['agreement']['id'];
    Notification::assertSentTo($finance, AppEventNotification::class, fn (AppEventNotification $notice) => $notice->payload['event_key'] === ReferralServiceAgreements::FINANCE_EVENT
        && $notice->payload['title'] === 'Draft service agreement from referral '.$f['referral']->reference.' needs review'
        && str_ends_with((string) $notice->payload['url'], '/operations/service-agreements/'.$id)
        && $notice->payload['entity_id'] === $id && ! str_contains(json_encode($notice->payload), 'Casey Example'));
    Notification::assertNotSentTo($admin, AppEventNotification::class);
    Notification::assertNotSentTo($f['author'], AppEventNotification::class);
    // A retry returns the same draft and does not notify again.
    $service->create($f['author'], $f['referral']->id, $input['version'], $input);
    Notification::assertSentToTimes($finance, AppEventNotification::class, 1);
});

it('allows a fresh draft from the same version after Finance deletes one, keeping the deleted link as history', function () {
    $f = referralAgreementFixture();
    $service = app(ReferralServiceAgreements::class);
    $first = $service->create($f['author'], $f['referral']->id, $f['referral']->fresh()->lock_version, referralAgreementInput($f));
    ServiceAgreement::query()->findOrFail($first['agreement']['id'])->delete();

    $inspect = $service->inspect($f['author'], $f['referral']->fresh());
    expect($inspect['state'])->toBe('ready')->and($inspect['agreement'])->toBeNull()
        ->and($inspect['earlier'])->toHaveCount(1)->and($inspect['earlier'][0]['status'])->toBe('deleted')
        ->and($inspect['earlier'][0]['can_open'])->toBeFalse();

    // No "leave it unchanged" confirmation is needed for a deleted agreement.
    $input = referralAgreementInput($f);
    $fresh = $service->create($f['author'], $f['referral']->id, $input['version'], $input);
    expect($fresh['replayed'])->toBeFalse()->and($fresh['agreement']['id'])->not->toBe($first['agreement']['id'])
        ->and(ServiceAgreement::withTrashed()->findOrFail($first['agreement']['id'])->trashed())->toBeTrue()
        ->and(ReferralServiceAgreement::query()->where('proposal_id', $f['proposal']->id)->count())->toBe(2);
    $event = ReferralEvent::query()->where('kind', 'proposal.agreement_created')->orderByDesc('id')->firstOrFail();
    expect((int) $event->payload['replaces_deleted_agreement_id'])->toBe($first['agreement']['id']);

    // Idempotency still holds for the live draft.
    $retry = $service->create($f['author'], $f['referral']->id, $input['version'], $input);
    expect($retry['replayed'])->toBeTrue()->and($retry['agreement']['id'])->toBe($fresh['agreement']['id'])
        ->and(ServiceAgreement::query()->count())->toBe(1);
    $after = $service->inspect($f['author'], $f['referral']->fresh());
    expect($after['state'])->toBe('created')->and($after['agreement']['id'])->toBe($fresh['agreement']['id'])
        ->and($after['earlier'])->toHaveCount(1)->and($after['earlier'][0]['status'])->toBe('deleted');
});
