<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$existing = CareReferral::where('source', 'qa_secure_document_requests')->first();
if ($existing) {
    echo json_encode(['reference' => $existing->reference, 'current_settings' => app(ReferralSettings::class)->effective()->version]).PHP_EOL;
    exit;
}
$result = DB::transaction(function () use ($version): array {
    $author = User::where('email', 'author@referrals-preview.test')->sole();
    $reviewer = User::where('email', 'reviewer@referrals-preview.test')->sole();
    $settings = app(ReferralSettings::class);
    $configuration = $version->configuration;
    $enabled = [...$configuration, 'document_requests' => ['enabled' => true, 'maximum_days' => 7]];
    $draft = $settings->draft($author, $enabled, $version->id, 'Fictional isolated browser acceptance for document collection.');
    $publication = $settings->publish($reviewer, $draft->id, now()->toISOString());
    $source = CareReferral::where('source', 'qa_people_contacts')->firstOrFail();
    $referral = CareReferral::create(['settings_version_id' => $publication->id, 'site_id' => $source->site_id, 'owner_id' => $reviewer->id,
        'stage' => 'assessment', 'source' => 'qa_secure_document_requests', 'entry_kind' => 'support', 'requested_service' => 'supported_living',
        'team' => 'Fictional document QA', 'identity' => ['first_name' => 'Fictional', 'last_name' => 'Document Request'],
        'intake' => ['reason' => 'Fictional acceptance walkthrough only.'], 'assessment' => [],
        'authority' => ['review' => ['state' => 'current', 'version' => 1, 'review_due_at' => now()->addMonth()->toISOString(), 'scopes' => ['collect' => true, 'contact' => true]]],
        'next_action' => 'Review the requested fictional funding letter', 'next_due_at' => now()->addDay(), 'stage_changed_at' => now()]);
    // Restore the prior effective configuration before this transaction commits.
    // Only the one fictional case retains the independently published enabled version.
    $restore = $settings->draft($author, $configuration, $publication->id, 'Restore the prior public configuration after isolated document request fixture preparation.');
    $restored = $settings->publish($reviewer, $restore->id, now()->toISOString());

    return ['reference' => $referral->reference, 'fixture_settings' => $publication->version, 'current_settings' => $restored->version];
});
echo json_encode($result).PHP_EOL;
