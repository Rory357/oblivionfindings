<?php

use App\Domain\Privacy\Retention\RetentionContractException;
use App\Domain\Privacy\Retention\RetentionExecutionService;
use App\Domain\Privacy\Retention\RetentionOwnerRegistry;
use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralFundingDecision;
use App\Domain\Referrals\Models\ReferralIntakeDraft;
use App\Domain\Referrals\Models\ReferralOutbox;
use App\Domain\Referrals\Models\ReferralServiceAgreement;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\ReferralLifecycle;
use App\Domain\Referrals\Services\ReferralReports;
use App\Domain\Referrals\Services\ReferralRetention;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\DataRetentionExecutionItem;
use App\Models\DataRetentionPolicy;
use App\Models\LegalHold;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Inertia\Testing\AssertableInertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

function referralRetentionPolicy(string $owner = CareReferral::class, array $attributes = []): DataRetentionPolicy
{
    return DataRetentionPolicy::factory()->create([
        'model_type' => $owner, 'policy_name' => 'Referral retention '.Str::random(6),
        'retention_period_years' => null, 'archive_after_years' => null, 'hard_delete_after_years' => 2,
        'retention_conditions' => null, 'applies_to_soft_deleted' => true, 'legal_hold_exemption' => true,
        'active_case_exemption' => false, 'active' => true, 'execution_state' => 'draft', ...$attributes,
    ]);
}

function approvedReferralRetentionPolicy(string $owner = CareReferral::class, array $attributes = []): DataRetentionPolicy
{
    $service = app(RetentionExecutionService::class);
    $policy = referralRetentionPolicy($owner, $attributes);
    $service->preview($policy, User::factory()->create());
    $service->approve($policy->fresh(), User::factory()->create());

    return $policy->fresh();
}

/** A referral closed three years ago, with fictional details in every place a person's details can sit. */
function closedReferralWithHistory(Site $site, User $actor, array $overrides = []): CareReferral
{
    $referral = referralTestRecord($site, [
        'stage' => 'closed', 'closure_reason' => 'another_provider', 'closed_at' => now()->subYears(3),
        'priority' => 'high', 'owner_id' => $actor->id, 'next_action' => '', 'next_due_at' => null,
        'identity' => ['first_name' => 'Aroha', 'last_name' => 'Fictional', 'date_of_birth' => '1990-02-03'],
        'intake' => ['region' => 'Rawene', 'goals' => 'Live closer to whānau', 'submitted_contact' => ['name' => 'Mere Fictional', 'phone' => '021 000 0000']],
        ...$overrides,
    ]);
    $referral->forceFill(['created_at' => now()->subYears(3)->subMonth()])->saveQuietly();
    $long = now()->subYears(3)->subWeeks(2);
    ReferralEvent::query()->create(['referral_id' => $referral->id, 'actor_id' => $actor->id, 'kind' => 'stage.changed', 'audience' => 'intake', 'occurred_at' => $long,
        'payload' => ['previous_stage' => 'new', 'stage' => 'triage', 'reason' => 'Spoke with Aroha Fictional', 'settings_version_id' => $referral->settings_version_id]]);
    ReferralWorkItem::query()->create(['referral_id' => $referral->id, 'owner_id' => $actor->id, 'kind' => 'call', 'audience' => 'intake', 'status' => 'cancelled',
        'details' => ['title' => 'Call Aroha back'], 'due_at' => $long]);
    $case = ReferralFundingCase::query()->create(['referral_id' => $referral->id, 'navigator_id' => $actor->id, 'settings_version_id' => $referral->settings_version_id,
        'pathway' => 'dss_nasc', 'status' => 'withdrawn', 'personal_income' => false, 'details' => ['notes' => 'Household income details for Aroha']]);
    ReferralFundingDecision::query()->create(['funding_case_id' => $case->id, 'version' => 1, 'outcome' => 'declined', 'recorded_by' => $actor->id, 'recorded_at' => $long,
        'decision' => ['outcome' => 'declined', 'summary' => 'NASC letter for Aroha Fictional', 'units' => 12]]);
    ReferralOutbox::query()->create(['referral_id' => $referral->id, 'deduplication_key' => 'acknowledgement:'.$referral->reference, 'kind' => 'public_acknowledgement',
        'state' => 'delivered', 'message' => ['recipient' => 'aroha@example.test', 'reference' => $referral->reference], 'available_at' => $long]);
    $path = 'referral-evidence/'.Str::uuid().'.bin';
    Storage::disk('private')->put($path, 'fictional letter');
    ReferralEvidence::query()->create(['referral_id' => $referral->id, 'audience' => 'assessment', 'state' => 'available', 'disk' => 'private', 'storage_path' => $path,
        'sha256' => hash('sha256', 'fictional letter'), 'size_bytes' => 16, 'uploaded_by' => $actor->id,
        'metadata' => ['title' => 'GP letter for Aroha', 'category' => 'health', 'mime' => 'application/pdf', 'expires_at' => null]]);

    return $referral->fresh();
}

beforeEach(function () {
    Storage::fake('private');
});

it('registers closed referrals, their documents and message copies as owners that anonymise and never delete', function () {
    $registry = app(RetentionOwnerRegistry::class);
    expect($registry->identifiers())->toContain(CareReferral::class, ReferralEvidence::class, ReferralOutbox::class)
        // Drafts expire by themselves and settings versions hold no personal details: no policy owner.
        ->not->toContain(ReferralIntakeDraft::class)->not->toContain(ReferralSettingsVersion::class);

    foreach ([CareReferral::class, ReferralEvidence::class, ReferralOutbox::class] as $owner) {
        $adapter = $registry->resolve($owner);
        $adapter->validateNativeContract(new DataRetentionPolicy(['model_type' => $owner, 'retention_conditions' => null]));
        // Without soft deletes only "Anonymise after" can run, so no retention period ever deletes a row.
        expect($adapter->usesSoftDeletes())->toBeFalse()
            ->and($registry->describe($owner)['guidance'])->toHaveKeys(['summary', 'removes', 'keeps', 'blocked_by', 'period', 'field_labels']);
    }
    expect($registry->describe(CareReferral::class)['guidance']['elsewhere'])->toHaveCount(4)
        ->and($registry->resolve(CareReferral::class)->cutoffColumn())->toBe('closed_at');

    // Lane G's proposal → service agreement link is contract evidence: registered, but kept while the
    // person's record or the agreement exists (both are protected from deletion).
    $link = $registry->resolve(ReferralServiceAgreement::class);
    $link->validateNativeContract(new DataRetentionPolicy(['model_type' => ReferralServiceAgreement::class, 'retention_conditions' => null]));
    expect($registry->identifiers())->toContain(ReferralServiceAgreement::class)
        ->and(array_keys($link->protections))->toBe(['person_record_gone', 'agreement_gone', 'no_referral_hold'])
        ->and($link->anonymizationFields)->toBe(['receipt' => 'structured'])
        ->and($link->usesSoftDeletes())->toBeFalse()
        ->and($registry->describe(ReferralServiceAgreement::class)['guidance']['summary'])->toContain('contract evidence')
        ->and($registry->describe(ReferralServiceAgreement::class)['guidance']['blocked_by'])->toContain('The service agreement still existing');
});

it('previews only referrals closed past the period with no hold, open work or linked person, and changes nothing', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = User::factory()->create();
    $eligible = closedReferralWithHistory($site, $actor);
    $held = closedReferralWithHistory($site, $actor);
    LegalHold::factory()->create(['holdable_type' => CareReferral::class, 'holdable_id' => $held->id, 'status' => 'active', 'imposed_at' => now()]);
    $busy = closedReferralWithHistory($site, $actor);
    ReferralWorkItem::query()->create(['referral_id' => $busy->id, 'owner_id' => $actor->id, 'kind' => 'call', 'audience' => 'intake', 'status' => 'open',
        'details' => ['title' => 'Still to do'], 'due_at' => now()->addDay()]);
    $linked = closedReferralWithHistory($site, $actor, ['client_id' => Client::factory()->create(['status' => 'inactive'])->id]);
    closedReferralWithHistory($site, $actor, ['closed_at' => now()->subMonths(6)]);
    referralTestRecord($site, ['stage' => 'triage']);

    $snapshot = app(RetentionExecutionService::class)->preview(referralRetentionPolicy(), User::factory()->create());

    expect($snapshot['eligible_count'])->toBe(1)
        ->and($snapshot['exempt_count'])->toBe(3)
        ->and(collect($snapshot['actions']['anonymize']['protected'])->pluck('count', 'key')->all())
        ->toBe(['closed' => 0, 'not_linked_to_person' => 1, 'no_open_work' => 1, 'not_already_anonymised' => 0])
        ->and($snapshot['anonymization_fields'])->toBe(['identity', 'intake', 'authority', 'assessment', 'next_action'])
        ->and($snapshot['guidance']['summary'])->toContain('never became a supported person');
    expect($eligible->fresh()->identity['first_name'])->toBe('Aroha')
        ->and($linked->fresh()->identity['first_name'])->toBe('Aroha');
});

it('anonymises an approved closed referral and everything that names the person, keeping its outline for reports', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = User::factory()->create();
    $referral = closedReferralWithHistory($site, $actor);
    $held = closedReferralWithHistory($site, $actor);
    LegalHold::factory()->create(['holdable_type' => CareReferral::class, 'holdable_id' => $held->id, 'status' => 'active', 'imposed_at' => now()]);
    $file = ReferralEvidence::query()->where('referral_id', $referral->id)->sole()->storage_path;
    $heldFile = ReferralEvidence::query()->where('referral_id', $held->id)->sole()->storage_path;
    expect(DB::table('care_referral_search_tokens')->where('referral_id', $referral->id)->count())->toBeGreaterThan(0);

    $policy = approvedReferralRetentionPolicy();
    $outcome = app(RetentionExecutionService::class)->execute($policy, 'manual', User::factory()->create());
    expect($outcome['status'])->toBe('completed')->and($outcome['result']['anonymized'])->toBe(1);

    $after = $referral->fresh();
    expect(array_keys($after->identity))->toBe(['retention_redacted_at'])
        ->and($after->intake)->toBe([])->and($after->authority)->toBe([])->and($after->assessment)->toBe([])
        ->and($after->next_action)->toBe('')
        ->and($after->only(['stage', 'closure_reason', 'priority', 'source', 'site_id', 'owner_id', 'reference']))
        ->toBe(['stage' => 'closed', 'closure_reason' => 'another_provider', 'priority' => 'high', 'source' => 'website', 'site_id' => $site->id, 'owner_id' => $actor->id, 'reference' => $referral->reference])
        ->and($after->closed_at->toISOString())->toBe($referral->closed_at->toISOString());

    // History keeps its structure (stage moves, version) for reports, without the note.
    expect(ReferralEvent::query()->where('referral_id', $referral->id)->sole()->payload)
        ->toBe(['previous_stage' => 'new', 'stage' => 'triage', 'reason' => null, 'settings_version_id' => $referral->settings_version_id])
        ->and(ReferralWorkItem::query()->where('referral_id', $referral->id)->sole()->details)->toBe(['title' => null]);
    $case = ReferralFundingCase::query()->where('referral_id', $referral->id)->sole();
    $decision = ReferralFundingDecision::query()->where('funding_case_id', $case->id)->sole();
    // Funding evidence is redacted, never deleted: pathway, status and outcome stay.
    expect($case->only(['pathway', 'status']))->toBe(['pathway' => 'dss_nasc', 'status' => 'withdrawn'])
        ->and($case->details)->toBe(['notes' => null])
        ->and($decision->outcome)->toBe('declined')
        ->and($decision->decision)->toBe(['outcome' => 'declined', 'summary' => null, 'units' => 12])
        ->and(ReferralOutbox::query()->where('referral_id', $referral->id)->sole()->message)->toBe(['recipient' => null, 'reference' => null]);
    $evidence = ReferralEvidence::query()->where('referral_id', $referral->id)->sole();
    expect($evidence->state)->toBe('disposed')
        ->and($evidence->metadata['title'])->toBe(ReferralRetention::REMOVED_TITLE)
        ->and($evidence->metadata['category'])->toBe('health');
    Storage::disk('private')->assertMissing($file);
    expect(DB::table('care_referral_search_tokens')->where('referral_id', $referral->id)->count())->toBe(0);

    // The held referral, its file and its search entries are untouched.
    expect($held->fresh()->identity['first_name'])->toBe('Aroha')
        ->and(DB::table('care_referral_search_tokens')->where('referral_id', $held->id)->count())->toBeGreaterThan(0);
    Storage::disk('private')->assertExists($heldFile);

    $item = DataRetentionExecutionItem::query()->where('owner_key', CareReferral::class)->where('record_id', $referral->id)->sole();
    expect($item->evidence['related'])->toMatchArray(['history_entries' => 1, 'follow_ups' => 1, 'funding_cases' => 1, 'funding_decisions' => 1, 'message_copies' => 1, 'documents_removed' => 1])
        ->and($item->evidence['protections_checked'])->toBe(['closed', 'not_linked_to_person', 'no_open_work', 'not_already_anonymised']);

    // The record reads as removed, still opens, and still counts in reports.
    $viewer = referralTestUser($site, ['viewAny', 'reports.view', 'reopen']);
    $this->actingAs($viewer)->getJson("/operations/referrals/{$referral->reference}/data")->assertOk()
        ->assertJsonPath('referral.name', 'Details removed under the retention policy')
        ->assertJsonPath('referral.retention.anonymised_at', $after->identity['retention_redacted_at'])
        ->assertJsonPath('referral.identity', []);
    $this->get("/operations/referrals/{$referral->reference}")->assertOk();
    config(['referral-reports.small_group_threshold' => 1]);
    $created = CarbonImmutable::parse($after->created_at)->timezone('Pacific/Auckland')->toDateString();
    $report = app(ReferralReports::class)->build($viewer, app(ReferralReports::class)->filters(['from' => $created, 'to' => $created]), CarbonImmutable::now('UTC'));
    expect(array_column($report['groups']['closure_reason'], 'count', 'key'))->toBe(['another_provider' => 2]);

    // It can't be reopened, and a second policy finds nothing left to do.
    expect(fn () => app(ReferralLifecycle::class)->reopen($viewer, $referral->id, $after->lock_version, [
        'reason' => 'Asked to look again', 'next_action' => 'Call back', 'next_due_at' => now()->addDay()->toISOString(),
    ]))->toThrow(HttpException::class);
    $again = app(RetentionExecutionService::class)->preview(referralRetentionPolicy(), User::factory()->create());
    expect($again['eligible_count'])->toBe(0)
        ->and(collect($again['actions']['anonymize']['protected'])->pluck('count', 'key')->all())->toMatchArray(['not_already_anonymised' => 1]);
});

it('removes documents and message copies sooner under their own policies, only for closed referrals', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = User::factory()->create();
    $closed = closedReferralWithHistory($site, $actor);
    $linked = closedReferralWithHistory($site, $actor, ['client_id' => Client::factory()->create(['status' => 'inactive'])->id]);
    $open = closedReferralWithHistory($site, $actor, ['stage' => 'assessment', 'closed_at' => null, 'closure_reason' => null]);
    ReferralOutbox::query()->create(['referral_id' => $closed->id, 'deduplication_key' => 'pending:'.$closed->reference, 'kind' => 'staff_message',
        'state' => 'pending', 'message' => ['recipient' => 'still-sending@example.test'], 'available_at' => now()]);
    ReferralEvidence::query()->update(['created_at' => now()->subYears(3)]);
    ReferralOutbox::query()->update(['created_at' => now()->subYears(3)]);
    $files = ReferralEvidence::query()->pluck('storage_path', 'referral_id');
    $service = app(RetentionExecutionService::class);

    $documents = approvedReferralRetentionPolicy(ReferralEvidence::class);
    expect($documents->preview_snapshot)->toMatchArray(['eligible_count' => 1, 'exempt_count' => 2]);
    $service->execute($documents, 'manual', User::factory()->create());
    expect(ReferralEvidence::query()->where('referral_id', $closed->id)->sole()->state)->toBe('disposed')
        ->and(ReferralEvidence::query()->whereIn('referral_id', [$linked->id, $open->id])->pluck('state')->unique()->all())->toBe(['available']);
    Storage::disk('private')->assertMissing($files[$closed->id]);
    Storage::disk('private')->assertExists($files[$linked->id]);
    Storage::disk('private')->assertExists($files[$open->id]);

    $messages = approvedReferralRetentionPolicy(ReferralOutbox::class);
    expect($messages->preview_snapshot)->toMatchArray(['eligible_count' => 1, 'exempt_count' => 3]);
    $service->execute($messages, 'manual', User::factory()->create());
    expect(ReferralOutbox::query()->where('referral_id', $closed->id)->where('state', 'delivered')->sole()->message)->toBe(['recipient' => null, 'reference' => null])
        ->and(ReferralOutbox::query()->where('state', 'pending')->sole()->message)->toBe(['recipient' => 'still-sending@example.test'])
        ->and(ReferralOutbox::query()->where('referral_id', $linked->id)->sole()->message['recipient'])->toBe('aroha@example.test');

    // Neither policy touches the referral record itself.
    expect($closed->fresh()->identity['first_name'])->toBe('Aroha');
});

it('binds each referral owner’s full contract into its approval from the start', function () {
    $service = app(RetentionExecutionService::class);
    $policy = referralRetentionPolicy();
    $settingsOnly = [
        'owner_key' => CareReferral::class, 'retention_period_years' => null, 'archive_after_years' => null,
        'hard_delete_after_years' => 2, 'retention_conditions' => null, 'applies_to_soft_deleted' => true,
        'legal_hold_exemption' => true, 'active_case_exemption' => false, 'active' => true,
    ];
    ksort($settingsOnly);

    expect($service->fingerprint($policy))->not->toBe(hash('sha256', json_encode($settingsOnly, JSON_THROW_ON_ERROR)))
        ->and($service->fingerprint($policy->fresh()))->toBe($service->fingerprint($policy))
        ->and(app(RetentionOwnerRegistry::class)->resolve(CareReferral::class)->extendedContract())->toBe([
            'cutoff_column' => 'closed_at',
            'protections' => ['closed', 'not_linked_to_person', 'no_open_work', 'not_already_anonymised'],
            'contract_version' => ReferralRetention::CONTRACT_VERSION,
        ]);
});

it('clears staff message addresses and text after the period, keeping delivery status, times and history', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = User::factory()->create();
    $closed = closedReferralWithHistory($site, $actor);
    $sentAt = CarbonImmutable::now()->subYears(3)->subWeek()->startOfSecond();
    // The shape Lane M's ReferralMessaging queues for an email to the referrer.
    $message = ReferralOutbox::query()->create([
        'referral_id' => $closed->id, 'deduplication_key' => 'staff:'.Str::uuid(), 'kind' => 'staff_message', 'state' => 'delivered',
        'available_at' => $sentAt, 'sent_at' => $sentAt, 'delivered_at' => $sentAt->addMinute(),
        'message' => [
            'channel' => 'email', 'recipient' => 'referrer', 'recipient_name' => 'Mere Fictional', 'address' => 'mere@example.test',
            'template_id' => 'first_contact', 'template_label' => 'First contact', 'subject' => 'About Aroha',
            'body' => 'Kia ora Mere, thank you for referring Aroha.', 'edited' => false, 'document_request_id' => null,
            'sender_id' => $actor->id, 'request_hash' => hash('sha256', 'fixture request'),
        ],
    ]);
    $message->forceFill(['created_at' => now()->subYears(3)])->saveQuietly();
    // History never held the address or text, only who it went to and a summary naming them.
    ReferralEvent::query()->create(['referral_id' => $closed->id, 'actor_id' => $actor->id, 'kind' => 'communication.queued', 'audience' => 'intake', 'occurred_at' => $sentAt,
        'payload' => ['outbox_id' => $message->id, 'template' => 'First contact', 'template_id' => 'first_contact', 'channel' => 'email', 'recipient' => 'referrer',
            'recipient_name' => 'Mere Fictional', 'summary' => '“First contact” by email to Mere Fictional', 'edited' => false, 'document_request_reference' => null]]);

    expect(app(RetentionOwnerRegistry::class)->describe(ReferralOutbox::class)['guidance']['summary'])->toContain('emails and texts staff sent');

    $policy = approvedReferralRetentionPolicy(ReferralOutbox::class, ['retention_conditions' => ['kind' => 'staff_message']]);
    expect($policy->preview_snapshot)->toMatchArray(['eligible_count' => 1, 'exempt_count' => 0]);
    app(RetentionExecutionService::class)->execute($policy, 'manual', User::factory()->create());

    $after = $message->fresh();
    expect($after->message)->toBe([
        'channel' => 'email', 'recipient' => 'referrer', 'recipient_name' => null, 'address' => null,
        'template_id' => 'first_contact', 'template_label' => 'First contact', 'subject' => null,
        'body' => ReferralRetention::REMOVED_MESSAGE, 'edited' => false, 'document_request_id' => null,
        'sender_id' => $actor->id, 'request_hash' => null,
    ])
        ->and($after->only(['kind', 'state']))->toBe(['kind' => 'staff_message', 'state' => 'delivered'])
        ->and($after->sent_at->toISOString())->toBe($sentAt->toISOString())
        ->and($after->delivered_at->toISOString())->toBe($sentAt->addMinute()->toISOString());
    // The policy's condition left the acknowledgement copy alone.
    expect(ReferralOutbox::query()->where('referral_id', $closed->id)->where('kind', 'public_acknowledgement')->sole()->message['recipient'])->toBe('aroha@example.test');

    // Lane M's communications list still reads the redacted copy.
    $this->actingAs(referralTestUser($site, ['viewAny']))->getJson("/operations/referrals/{$closed->reference}/communications")->assertOk()
        ->assertJsonPath('communications.data.0.id', $message->id)
        ->assertJsonPath('communications.data.0.state', 'delivered')
        ->assertJsonPath('communications.data.0.recipient', '')
        ->assertJsonPath('communications.data.0.template', 'First contact')
        ->assertJsonPath('communications.data.0.body', ReferralRetention::REMOVED_MESSAGE);

    // Anonymising the referral later keeps the history entry's structure and drops the name.
    app(RetentionExecutionService::class)->execute(approvedReferralRetentionPolicy(), 'manual', User::factory()->create());
    expect(ReferralEvent::query()->where('referral_id', $closed->id)->where('kind', 'communication.queued')->sole()->payload)->toBe([
        'outbox_id' => $message->id, 'template' => 'First contact', 'template_id' => 'first_contact', 'channel' => 'email', 'recipient' => 'referrer',
        'recipient_name' => null, 'summary' => null, 'edited' => false, 'document_request_reference' => null,
    ])
        ->and(ReferralOutbox::query()->where('referral_id', $closed->id)->where('kind', 'public_acknowledgement')->sole()->message['recipient'])->toBeNull();
});

it('sets referral retention periods as governed policies that still need a preview and a second person', function () {
    $this->seed(RbacSeeder::class);
    $admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    $service = app(RetentionExecutionService::class);

    // Not set up yet: the suggested periods are pre-filled.
    $rows = app(ReferralRetention::class)->periodRows($service);
    expect(array_column($rows, 'years', 'key'))->toBe(['referral_closed_junk' => 1, 'referral_closed_other' => 2, 'referral_documents' => 1, 'referral_messages' => 1])
        ->and(collect($rows)->pluck('policy')->filter()->all())->toBe([]);
    $this->actingAs($admin)->get('/privacy/retention')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('referralRetention', 4)->where('referralRetention.1.years', 2));

    $periods = ['referral_closed_junk' => 1, 'referral_closed_other' => 3, 'referral_documents' => 1, 'referral_messages' => 1];
    $this->actingAs($admin)->post('/privacy/retention/referral-periods', ['periods' => $periods])->assertRedirect()->assertSessionHas('success');
    $policies = DataRetentionPolicy::query()->whereNotNull('managed_key')->get()->keyBy('managed_key');
    expect($policies)->toHaveCount(4)
        ->and($policies['referral_closed_other']->only(['model_type', 'hard_delete_after_years', 'retention_period_years', 'execution_state']))
        ->toBe(['model_type' => CareReferral::class, 'hard_delete_after_years' => 3, 'retention_period_years' => null, 'execution_state' => 'draft'])
        ->and($policies['referral_closed_junk']->retention_conditions)->toBe(['closure_reason' => ['spam', 'test', 'duplicate']])
        ->and($policies['referral_closed_other']->retention_conditions)->toBe(['closure_reason' => ['not_in' => ['spam', 'test', 'duplicate']]])
        ->and($policies['referral_documents']->model_type)->toBe(ReferralEvidence::class)
        ->and($policies['referral_messages']->model_type)->toBe(ReferralOutbox::class);

    // Nothing runs on the setting alone: it needs a preview and a different person's approval.
    $policy = $policies['referral_closed_other'];
    $service->preview($policy, $admin);
    expect(fn () => $service->approve($policy->fresh(), $admin))->toThrow(RetentionContractException::class);
    $service->approve($policy->fresh(), User::factory()->create());

    // Saving the same periods keeps that approval; changing one sends it back for review.
    $this->actingAs($admin)->post('/privacy/retention/referral-periods', ['periods' => $periods]);
    expect($policy->fresh()->execution_state)->toBe('approved');
    $this->actingAs($admin)->post('/privacy/retention/referral-periods', ['periods' => [...$periods, 'referral_closed_other' => 4]]);
    expect($policy->fresh()->only(['hard_delete_after_years', 'execution_state', 'approved_fingerprint']))
        ->toBe(['hard_delete_after_years' => 4, 'execution_state' => 'draft', 'approved_fingerprint' => null])
        ->and(DataRetentionPolicy::query()->whereNotNull('managed_key')->count())->toBe(4);

    $this->actingAs($admin)->post('/privacy/retention/referral-periods', ['periods' => [...$periods, 'referral_documents' => 0]])
        ->assertSessionHasErrors('periods.referral_documents');
    $statuses = collect(app(ReferralRetention::class)->periodRows($service))->keyBy('key');
    expect($statuses['referral_closed_other']['years'])->toBe(4)
        ->and($statuses['referral_closed_other']['policy']['execution_state'])->toBe('draft');

    // The review page says when each period counts from.
    $this->get('/privacy/retention/review')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('policies.0.owner.guidance.period_basis', fn ($basis) => is_string($basis) && $basis !== ''));
});

it('anonymises the referrals that led to a person’s support when their record is anonymised', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = User::factory()->create();
    $client = Client::factory()->create(['status' => 'inactive', 'first_name' => 'Aroha', 'last_name' => 'Fictional']);
    $client->forceFill(['created_at' => now()->subYears(3), 'updated_at' => now()->subYears(3)])->save();
    $converted = closedReferralWithHistory($site, $actor, [
        'stage' => 'converted', 'closure_reason' => null, 'closed_at' => null, 'client_id' => $client->id, 'converted_at' => now()->subYears(3),
    ]);
    ReferralConversion::query()->create(['referral_id' => $converted->id, 'client_id' => $client->id, 'converted_by' => $actor->id, 'created_person' => true,
        'converted_at' => now()->subYears(3), 'receipt' => ['created_person' => true, 'person_name' => 'Aroha Fictional', 'funding_case_count' => 1]]);
    $held = closedReferralWithHistory($site, $actor, ['client_id' => $client->id]);
    LegalHold::factory()->create(['holdable_type' => CareReferral::class, 'holdable_id' => $held->id, 'status' => 'active', 'imposed_at' => now()]);
    $someoneElse = closedReferralWithHistory($site, $actor);
    $file = ReferralEvidence::query()->where('referral_id', $converted->id)->sole()->storage_path;

    $policy = approvedReferralRetentionPolicy(Client::class);
    expect($policy->preview_snapshot['guidance']['summary'])->toContain('also anonymises the referrals');
    app(RetentionExecutionService::class)->execute($policy, 'manual', User::factory()->create());

    expect($client->fresh()->first_name)->toBe('[REDACTED]');
    $after = $converted->fresh();
    expect(array_keys($after->identity))->toBe(['retention_redacted_at'])
        ->and($after->intake)->toBe([])->and($after->assessment)->toBe([])
        ->and($after->only(['stage', 'client_id', 'priority', 'source']))->toBe(['stage' => 'converted', 'client_id' => $client->id, 'priority' => 'high', 'source' => 'website'])
        ->and($after->converted_at)->not->toBeNull();
    // The conversion receipt keeps its facts and funding outcomes stay; free text, files and search entries go.
    expect(ReferralConversion::query()->where('referral_id', $converted->id)->sole()->receipt)
        ->toBe(['created_person' => true, 'person_name' => null, 'funding_case_count' => 1]);
    $case = ReferralFundingCase::query()->where('referral_id', $converted->id)->sole();
    expect(ReferralFundingDecision::query()->where('funding_case_id', $case->id)->sole()->outcome)->toBe('declined')
        ->and(ReferralEvent::query()->where('referral_id', $converted->id)->sole()->payload['reason'])->toBeNull()
        ->and(ReferralEvidence::query()->where('referral_id', $converted->id)->sole()->state)->toBe('disposed')
        ->and(DB::table('care_referral_search_tokens')->where('referral_id', $converted->id)->count())->toBe(0);
    Storage::disk('private')->assertMissing($file);

    // A held referral and other people's referrals are left as they are.
    expect($held->fresh()->identity['first_name'])->toBe('Aroha')
        ->and($someoneElse->fresh()->identity['first_name'])->toBe('Aroha');
    $item = DataRetentionExecutionItem::query()->where('owner_key', Client::class)->where('record_id', $client->id)->sole();
    expect($item->evidence['related'])->toMatchArray(['referrals_anonymised' => 1, 'referrals_held' => 1, 'conversion_receipts' => 1, 'documents_removed' => 1])
        ->and(AuditLog::query()->where('action', 'data_retention.referral_anonymised_with_person')->count())->toBe(1);
});
