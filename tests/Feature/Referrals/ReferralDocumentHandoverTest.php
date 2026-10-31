<?php

use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Services\ReferralCareHandoverRevisions;
use App\Domain\Referrals\Services\ReferralConversionService;
use App\Domain\Referrals\Services\ReferralDocumentHandovers;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\Permission;
use App\Models\TimelineEvent;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralConversionFixtures.php';

function documentHandoverFixture(): array
{
    [$site, $actor, $referral, $context, $source, $data] = conversionFixture();
    handoverCanonicalPermissions($actor, ['referrals.evidence.manage', 'referrals.assessment.manage']);
    $source->update(['metadata' => [...$source->metadata, 'expires_at' => now()->addWeek()->toISOString()]]);
    $data['document_handover'] = [['evidence_id' => $source->id, 'title' => 'Reviewed fictional care document', 'purpose' => 'Whole file checked for the agreed staff care purpose', 'confirmed' => true]];

    return [$site, $actor->fresh(), $referral, $context, $source, $data];
}

it('creates one private canonical document link with an immutable receipt and guarded source bytes', function () {
    [, $actor, $referral, , $source, $data] = documentHandoverFixture();
    $convert = app(ReferralConversionService::class);
    $result = $convert->convert($actor, $referral->id, 1, $data);
    expect($convert->convert($actor, $referral->id, 1, $data))->toBe($result);
    $person = Client::findOrFail($result['person']['id']);
    $document = ClientDocument::where('client_id', $person->id)->sole();
    expect($document->portal_visible)->toBeFalse()->and($document->source_referral_evidence_id)->toBe($source->id)
        ->and($person->status)->toBe('onboarding')->and($person->service_start_date)->toBeNull()
        ->and($document->getRawOriginal('referral_release'))->not->toContain('Whole file')
        ->and($document->toArray())->not->toHaveKey('referral_release');
    expect(hash('sha256', app(ReferralDocumentHandovers::class)->bytes($actor, $person, $document)))->toBe($source->sha256);
    expect(ReferralConversion::where('referral_id', $referral->id)->sole()->receipt['document_mappings'][0]['document_id'])->toBe($document->id);
    expect(TimelineEvent::where('source_type', ClientDocument::class)->where('source_id', $document->id)->count())->toBe(0);
    expect(AuditLog::where('auditable_type', ClientDocument::class)->where('auditable_id', $document->id)->pluck('meta')->toJson())->not->toContain('Reviewed fictional care document', 'Whole file');
    $source->update(['state' => 'quarantined']);
    $this->actingAs($actor)->get('/operations/clients/'.$person->id.'/documents/'.$document->id.'/download')->assertConflict();
});

it('rejects funding releases and missing evidence-management permission without a partial conversion', function () {
    [, $actor, $referral, , $source, $data] = documentHandoverFixture();
    $private = $source->replicate(['reference']);
    $private->forceFill(['audience' => 'funding'])->save();
    $data['document_handover'][0]['evidence_id'] = $private->id;
    expect(fn () => app(ReferralConversionService::class)->convert($actor, $referral->id, 1, $data))->toThrow(HttpException::class);
    expect(Client::count())->toBe(0)->and(ClientDocument::count())->toBe(0)->and(ReferralConversion::count())->toBe(0);
    $data['document_handover'][0]['evidence_id'] = $source->id;
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.evidence.manage')->sole()->id, ['allowed' => false]);
    expect(fn () => app(ReferralConversionService::class)->convert($actor->fresh(), $referral->id, 1, $data))->toThrow(HttpException::class);
    expect(Client::count())->toBe(0)->and(ClientDocument::count())->toBe(0);
});

it('requires a fresh explicit release after authority changes and keeps the original mapping', function () {
    [, $actor, $referral, , $source, $data] = documentHandoverFixture();
    $result = app(ReferralConversionService::class)->convert($actor, $referral->id, 1, $data);
    $person = Client::findOrFail($result['person']['id']);
    $document = ClientDocument::where('client_id', $person->id)->sole();
    $referral->refresh()->update(['authority' => ['review' => [...$referral->authority['review'], 'version' => 2]], 'assessment' => [...$referral->assessment, 'version' => 2, 'authority_version' => 2]]);
    expect(fn () => app(ReferralDocumentHandovers::class)->bytes($actor, $person, $document))->toThrow(HttpException::class);
    $care = app(ReferralCareHandoverRevisions::class);
    $preview = $care->preview($actor, $referral->fresh());
    $input = ['submission_key' => (string) Str::uuid(), 'acknowledge_care_handover' => true, 'reason' => 'New whole-file review for current care preparation', 'assessment_version' => 2,
        'authority_version' => 2, 'person_fingerprint' => $preview['person']['fingerprint'], 'evidence_ids' => [$source->id], 'document_handover' => $data['document_handover']];
    $saved = $care->publish($actor, $referral->id, 2, $input);
    expect($care->publish($actor, $referral->id, 2, $input))->toBe($saved);
    expect($document->fresh()->referral_release['version'])->toBe(2)->and(ClientDocument::count())->toBe(1);
    expect(hash('sha256', app(ReferralDocumentHandovers::class)->bytes($actor, $person, $document->fresh())))->toBe($source->sha256);
    expect(ReferralConversion::where('referral_id', $referral->id)->sole()->receipt['document_mappings'][0]['version'])->toBe(1);
});

it('hides restricted sources and cannot widen a care release into portal or medication-chart sharing', function () {
    [, $actor, $referral, , , $data] = documentHandoverFixture();
    $result = app(ReferralConversionService::class)->convert($actor, $referral->id, 1, $data);
    $person = Client::findOrFail($result['person']['id']);
    $document = ClientDocument::where('client_id', $person->id)->sole();
    $this->actingAs($actor)->putJson('/operations/clients/'.$person->id.'/documents/'.$document->id, ['portal_visible' => true])->assertUnprocessable();
    $this->putJson('/operations/clients/'.$person->id.'/documents/'.$document->id, ['category' => 'med_chart'])->assertUnprocessable();
    $referral->refresh()->update(['restricted' => true]);
    expect(ClientDocument::query()->visibleReferralReleases($actor)->count())->toBe(0);
    $this->get('/operations/clients/'.$person->id.'/documents/'.$document->id.'/download')->assertNotFound();
});

it('removes the canonical link without deleting the original evidence file', function () {
    [, $actor, $referral, , $source, $data] = documentHandoverFixture();
    $result = app(ReferralConversionService::class)->convert($actor, $referral->id, 1, $data);
    $document = ClientDocument::where('client_id', $result['person']['id'])->sole();
    $this->actingAs($actor)->delete('/operations/clients/'.$document->client_id.'/documents/'.$document->id)->assertRedirect();
    expect(ClientDocument::count())->toBe(0)->and(Storage::disk('private')->exists($source->storage_path))->toBeTrue();
    expect($source->fresh())->not->toBeNull();
});
