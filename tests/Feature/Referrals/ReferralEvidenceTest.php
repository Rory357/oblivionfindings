<?php

use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Services\ReferralEvidenceFiles;
use App\Models\Site;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

beforeEach(function () {
    Storage::fake('private');
});

function referralEvidenceFixture(): array
{
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'evidence.manage', 'funding.view', 'funding.manage']);
    $record = referralTestRecord($site, ['owner_id' => $actor->id, 'authority' => ['review' => ['state' => 'current', 'version' => 1,
        'review_due_at' => now()->addMonth()->toISOString(), 'scopes' => ['collect' => true, 'assist' => true]]]]);

    return [$site, $actor, $record];
}

function referralEvidenceInput(array $overrides = []): array
{
    return ['version' => 1, 'title' => 'External funding letter', 'audience' => 'funding', 'category' => 'external_decision', 'source' => 'The person supplied the letter',
        'reason' => 'Review the external award with the person', 'expires_at' => null, 'previous_version_id' => null,
        'file' => UploadedFile::fake()->createWithContent('decision.pdf', "%PDF-1.4\nFictional funding letter\n%%EOF"), ...$overrides];
}

function referralScanResult(MalwareScanDisposition $state): MalwareScanResult
{
    return new MalwareScanResult($state, 'test-scanner', $state === MalwareScanDisposition::Unavailable ? 'scanner_unavailable' : null);
}

it('quarantines unavailable scans and requires a successful retry before authorized download', function () {
    [$site, $actor, $record] = referralEvidenceFixture();
    $this->mock(MalwareScanner::class)->shouldReceive('scanPath')->twice()->andReturn(referralScanResult(MalwareScanDisposition::Unavailable), new MalwareScanResult(MalwareScanDisposition::Clean, 'replacement-test-scanner'));
    $response = $this->actingAs($actor)->post(route('referrals.documents.store', $record->reference), referralEvidenceInput(), ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('document.state', 'quarantined');
    $id = $response->json('document.id');
    $this->get(route('referrals.documents.download', [$record->reference, $id]))->assertStatus(409);
    $this->postJson(route('referrals.documents.rescan', [$record->reference, $id]), ['version' => 2])->assertOk();
    expect(ReferralEvidence::findOrFail($id)->metadata['scanner'])->toBe('replacement-test-scanner');
    $this->get(route('referrals.documents.download', [$record->reference, $id]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertSee('Fictional funding letter');
    expect(ReferralEvent::where('referral_id', $record->id)->where('kind', 'evidence.downloaded')->count())->toBe(1);
    $reader = referralTestUser($site, ['viewAny']);
    $this->actingAs($reader)->getJson(route('referrals.documents.index', ['reference' => $record->reference, 'audience' => 'funding']))->assertForbidden();
    $this->get(route('referrals.documents.download', [$record->reference, $id]))->assertNotFound();
});

it('blocks rejected and altered evidence and does not expose paths or scanner details', function () {
    [, $actor, $record] = referralEvidenceFixture();
    $this->mock(MalwareScanner::class)->shouldReceive('scanPath')->twice()->andReturn(referralScanResult(MalwareScanDisposition::Infected), referralScanResult(MalwareScanDisposition::Clean));
    $first = $this->actingAs($actor)->post(route('referrals.documents.store', $record->reference), referralEvidenceInput(), ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('document.state', 'rejected')->assertJsonMissingPath('document.storage_path');
    $this->get(route('referrals.documents.download', [$record->reference, $first->json('document.id')]))->assertStatus(409);
    $second = $this->post(route('referrals.documents.store', $record->reference), referralEvidenceInput(['version' => 2]), ['Accept' => 'application/json'])->assertCreated();
    $document = ReferralEvidence::findOrFail($second->json('document.id'));
    Storage::disk('private')->put($document->storage_path, 'Altered content');
    expect(app(ReferralEvidenceFiles::class)->bytes($document))->toBeNull();
    $this->get(route('referrals.documents.download', [$record->reference, $document->id]))->assertStatus(409);
});

it('preserves replacement history and denies a document from a different parent', function () {
    [$site, $actor, $record] = referralEvidenceFixture();
    $this->mock(MalwareScanner::class)->shouldReceive('scanPath')->twice()->andReturn(referralScanResult(MalwareScanDisposition::Clean));
    $first = $this->actingAs($actor)->post(route('referrals.documents.store', $record->reference), referralEvidenceInput(), ['Accept' => 'application/json'])->assertCreated();
    $firstId = $first->json('document.id');
    $second = $this->post(route('referrals.documents.store', $record->reference), referralEvidenceInput(['version' => 2, 'previous_version_id' => $firstId]), ['Accept' => 'application/json'])->assertCreated();
    expect(ReferralEvidence::findOrFail($firstId)->metadata['superseded_by_id'])->toBe($second->json('document.id'));
    $this->get(route('referrals.documents.download', [$record->reference, $firstId]))->assertOk();
    $other = referralTestRecord($site);
    $this->get(route('referrals.documents.download', [$other->reference, $firstId]))->assertNotFound();
    $this->postJson(route('referrals.documents.rescan', [$other->reference, $firstId]), ['version' => 1])->assertNotFound();
});

it('rejects stale uploads atomically and removes the uncommitted private file', function () {
    [, $actor, $record] = referralEvidenceFixture();
    $this->mock(MalwareScanner::class)->shouldReceive('scanPath')->once()->andReturn(referralScanResult(MalwareScanDisposition::Clean));
    $this->actingAs($actor)->post(route('referrals.documents.store', $record->reference), referralEvidenceInput(['version' => 5]), ['Accept' => 'application/json'])->assertConflict();
    expect(ReferralEvidence::where('referral_id', $record->id)->count())->toBe(0)->and(Storage::disk('private')->allFiles('referral-evidence'))->toBe([]);
});

it('requires collection authority and the write permission for the selected audience', function () {
    [, $actor, $record] = referralEvidenceFixture();
    $this->mock(MalwareScanner::class)->shouldNotReceive('scanPath');
    $this->actingAs($actor)->post(route('referrals.documents.store', $record->reference), referralEvidenceInput(['audience' => 'assessment']), ['Accept' => 'application/json'])->assertForbidden();
    $record->update(['authority' => ['review' => ['state' => 'withdrawn', 'scopes' => []]]]);
    $this->post(route('referrals.documents.store', $record->reference), referralEvidenceInput(), ['Accept' => 'application/json'])->assertUnprocessable();
    expect(Storage::disk('private')->allFiles('referral-evidence'))->toBe([]);
});
