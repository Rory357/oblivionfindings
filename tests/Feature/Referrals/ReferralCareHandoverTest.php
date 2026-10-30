<?php

use App\Domain\Referrals\Models\ReferralCareHandover;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Services\ReferralCareHandoverRevisions;
use App\Domain\Referrals\Services\ReferralCareHandovers;
use App\Domain\Referrals\Services\ReferralConversionService;
use App\Domain\Referrals\Services\ReferralHandoverPreview;
use App\Models\Client;
use App\Models\ClientOnboardingWorkflow;
use App\Models\Permission;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralConversionFixtures.php';

function revisedCareFixture(): array
{
    [, $actor, $referral, , $document, $data] = conversionFixture();
    $receipt = app(ReferralConversionService::class)->convert($actor, $referral->id, 1, $data);
    $referral->refresh()->update(['assessment' => [...$referral->assessment, 'version' => 2, 'goals' => 'Use the library with chosen support']]);
    $input = ['submission_key' => (string) Str::uuid(), 'acknowledge_care_handover' => true,
        'reason' => 'Goals and communication reviewed with the person', 'assessment_version' => 2,
        'authority_version' => 1, 'evidence_ids' => [$document->id], 'person_fingerprint' => ReferralHandoverPreview::fingerprint(Client::findOrFail($receipt['person']['id']))];

    return [$actor, $referral, $document, $receipt, $input];
}

it('publishes a new care snapshot and returns one immutable receipt across exact retries', function () {
    [$actor, $referral, , $receipt, $input] = revisedCareFixture();
    $workflow = ClientOnboardingWorkflow::findOrFail($receipt['workflow_id']);
    expect(app(ReferralCareHandovers::class)->forWorkflow($actor, $workflow)[0]['status'])->toBe('review_required');
    $original = ReferralConversion::where('referral_id', $referral->id)->firstOrFail()->receipt;
    $service = app(ReferralCareHandoverRevisions::class);
    $first = $service->publish($actor, $referral->id, 2, $input);
    $again = $service->publish($actor, $referral->id, 2, $input);
    expect($again)->toBe($first)->and($first['published_version'])->toBe(2)->and(ReferralCareHandover::count())->toBe(1)
        ->and($referral->fresh()->lock_version)->toBe(3)->and(Client::findOrFail($receipt['person']['id'])->status)->toBe('onboarding')
        ->and(ReferralConversion::where('referral_id', $referral->id)->firstOrFail()->receipt)->toBe($original);
    $care = app(ReferralCareHandovers::class)->forWorkflow($actor, $workflow)[0];
    expect($care['status'])->toBe('current')->and($care['handover_version'])->toBe(2)
        ->and($care['care']['goals'])->toBe('Use the library with chosen support');
    expect(fn () => ReferralCareHandover::firstOrFail()->update(['snapshot' => []]))->toThrow(LogicException::class);
    expect(ReferralCareHandover::firstOrFail()->toArray())->not->toHaveKey('snapshot');
    expect(fn () => $service->publish($actor, $referral->id, 3, [...$input, 'reason' => 'A different request with the same token']))->toThrow(HttpException::class);
});

it('rejects stale reviews withdrawn authority and changed evidence without publishing partial care', function () {
    [$actor, $referral, $document, , $input] = revisedCareFixture();
    $service = app(ReferralCareHandoverRevisions::class);
    expect(fn () => $service->publish($actor, $referral->id, 2, [...$input, 'assessment_version' => 1]))->toThrow(HttpException::class);
    $authority = $referral->authority;
    $referral->update(['authority' => ['review' => [...$authority['review'], 'state' => 'withdrawn']]]);
    expect(fn () => $service->publish($actor, $referral->id, 2, $input))->toThrow(HttpException::class);
    $referral->update(['authority' => $authority]);
    Storage::disk('private')->put($document->storage_path, 'Changed document bytes');
    expect(fn () => $service->publish($actor, $referral->id, 2, $input))->toThrow(HttpException::class);
    expect(ReferralCareHandover::count())->toBe(0)->and($referral->fresh()->lock_version)->toBe(2);
});

it('requires current source and canonical permissions even when replaying a saved care publication', function () {
    [$actor, $referral, , , $input] = revisedCareFixture();
    $service = app(ReferralCareHandoverRevisions::class);
    $service->publish($actor, $referral->id, 2, $input);
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'clients.update')->firstOrFail()->id, ['allowed' => false]);
    $this->actingAs($actor->fresh())->getJson(route('referrals.handover.care.preview', $referral->reference))->assertForbidden();
    expect(fn () => $service->publish($actor->fresh(), $referral->id, 2, $input))->toThrow(HttpException::class);
    expect(ReferralCareHandover::count())->toBe(1);
});

it('rechecks the linked person and never falls back to an older care release after a later review changes', function () {
    [$actor, $referral, , $receipt, $input] = revisedCareFixture();
    $person = Client::findOrFail($receipt['person']['id']);
    $person->update(['preferred_name' => 'A newly confirmed preferred name']);
    $service = app(ReferralCareHandoverRevisions::class);
    expect(fn () => $service->publish($actor, $referral->id, 2, $input))->toThrow(HttpException::class);
    $input['person_fingerprint'] = ReferralHandoverPreview::fingerprint($person->fresh());
    $service->publish($actor, $referral->id, 2, $input);
    $referral->refresh()->update(['assessment' => [...$referral->assessment, 'version' => 3, 'identity_status' => 'disputed']]);
    $care = app(ReferralCareHandovers::class)->forWorkflow($actor, ClientOnboardingWorkflow::findOrFail($receipt['workflow_id']))[0];
    expect($care['handover_version'])->toBe(2)->and($care['status'])->toBe('review_required')->and($care['care'])->toBeNull();
});
