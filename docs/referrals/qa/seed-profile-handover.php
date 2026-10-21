<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralProposal;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\ReferralHandoverPreview;
use App\Domain\Referrals\Services\ReferralPeopleContacts;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

$fixtureSource = match ($argv[2] ?? '') {
    'layout' => 'qa_profile_handover_layout', 'contact' => 'qa_contact_handover', 'documents' => 'qa_document_handover', 'people' => 'qa_people_contacts', 'support' => 'qa_support_contact_handover', default => 'qa_profile_handover',
};
$familyName = match ($argv[2] ?? '') {
    'layout' => 'ProfileHandoverLayoutQA', 'contact' => 'ContactHandoverQA', 'documents' => 'DocumentHandoverQA', 'people' => 'PeopleContactsQA', 'support' => 'SupportContactHandoverQA', default => 'ProfileHandoverQA',
};
$existing = CareReferral::where('source', $fixtureSource)->first();
if ($existing) {
    echo json_encode(['reference' => $existing->reference, 'existing' => true]).PHP_EOL;
    exit;
}
$path = null;
try {
    $result = DB::transaction(function () use (&$path, $fixtureSource, $familyName) {
        $actor = User::where('email', 'reviewer@referrals-preview.test')->sole();
        $person = Client::findOrFail(2);
        $settings = ReferralSettingsVersion::where('state', 'published')->orderByDesc('version')->firstOrFail();
        $referral = CareReferral::create(['settings_version_id' => $settings->id, 'site_id' => $person->site_id,
            'source' => $fixtureSource, 'entry_kind' => 'self', 'stage' => 'assessment', 'stage_changed_at' => now(),
            'owner_id' => $actor->id, 'team' => 'Fictional profile handover QA', 'requested_service' => 'supported_living',
            'identity' => ['first_name' => 'Fictional', 'last_name' => $familyName],
            'intake' => ['reason' => 'Demonstration only. No real person or service request.', 'relationship' => 'self'],
            'authority' => ['review' => ['version' => 1, 'state' => 'current', 'review_due_at' => now()->addMonth()->toISOString(), 'scopes' => ['collect' => true, 'service' => true, ...(in_array($fixtureSource, ['qa_people_contacts', 'qa_support_contact_handover'], true) ? ['contact' => true] : [])], 'note' => 'Fictional verification only; no real consent represented']],
            'assessment' => ['version' => 1, 'authority_version' => 1, 'review_due_at' => now()->addMonth()->toISOString(), 'identity_status' => 'verified', 'intention' => 'proceed', 'suitability' => 'suitable', 'goals' => 'Fictional daily living choices', 'participation' => 'Fictional plain-language participation review', 'safety_needs' => 'Fictional support needs reviewed for this demonstration'],
            'next_action' => 'Fictional funding assistance follow-up', 'next_due_at' => now()->addDay()]);
        $work = ReferralWorkItem::create(['referral_id' => $referral->id, 'owner_id' => $actor->id, 'kind' => 'follow_up', 'audience' => 'intake', 'status' => 'open', 'due_at' => now()->addDay(), 'details' => ['title' => 'Fictional funding assistance follow-up']]);
        $referral->update(['primary_work_item_id' => $work->id]);
        ReferralProposal::create(['referral_id' => $referral->id, 'version' => 1, 'authored_by' => $actor->id, 'authored_at' => now(), 'proposal' => [
            'funding_position' => 'Fictional funding assistance is still pending. No support start is authorised.', 'starts_on' => today()->toDateString(), 'ends_on' => today()->addMonth()->toDateString(),
            'lines' => [['key' => 'support', 'description' => 'Fictional daily living support', 'quantity' => null, 'unit' => 'hours', 'period' => 'week', 'estimate' => ['known' => false]]], 'allocations' => []]]);
        $source = ReferralEvidence::where('audience', 'assessment')->where('state', 'available')->firstOrFail();
        $bytes = Storage::disk($source->disk)->get($source->storage_path);
        if (! is_string($bytes) || ! hash_equals($source->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('Original fictional evidence digest mismatch.');
        }
        $path = ['disk' => $source->disk, 'path' => 'referral-evidence/'.Str::uuid().'.bin'];
        Storage::disk($path['disk'])->put($path['path'], $bytes);
        $evidence = ReferralEvidence::create(['referral_id' => $referral->id, 'audience' => 'assessment', 'state' => 'available', 'disk' => $path['disk'], 'storage_path' => $path['path'], 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes), 'metadata' => ['title' => 'Fictional reviewed handover evidence', 'mime' => 'application/pdf']]);
        if ($fixtureSource === 'qa_support_contact_handover') {
            $sourceContact = array_values(CareReferral::where('source', 'qa_people_contacts')->sole()->intake['people_contacts'])[0];
            app(ReferralPeopleContacts::class)->save($actor, $referral->id, $referral->fresh()->lock_version, [
                ...$sourceContact['details'], 'submission_key' => (string) Str::uuid(), 'contact_id' => (string) Str::uuid(), 'contact_version' => 0,
                'review_note' => 'Fictional copied demonstration contact checked for handover QA only',
            ]);
        }
        $preview = app(ReferralHandoverPreview::class)->inspect($actor, $referral->fresh());
        if (! $preview['preparation_checks_passed']) {
            throw new RuntimeException('Fixture still needs: '.implode(', ', collect($preview['checks'])->where('passed', false)->pluck('label')->all()));
        }

        return ['reference' => $referral->reference, 'version' => $referral->fresh()->lock_version, 'owner' => $actor->name, 'context_id' => $person->service_context_id, 'evidence_id' => $evidence->id, 'settings_version' => $settings->version];
    });
    echo json_encode($result).PHP_EOL;
} catch (Throwable $e) {
    if ($path) {
        Storage::disk($path['disk'])->delete($path['path']);
    }
    throw $e;
}
