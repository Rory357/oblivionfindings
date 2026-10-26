<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$mode = $argv[2] ?? '';
if (! in_array($mode, ['prepare', 'restore', 'inspect'], true)) {
    throw new RuntimeException('Choose prepare, restore or inspect.');
}
$marker = __DIR__.'/runtime/routing-browser.json';
$author = User::where('email', 'author@referrals-preview.test')->sole();
$reviewer = User::where('email', 'reviewer@referrals-preview.test')->sole();
$settings = app(ReferralSettings::class);
$current = $settings->effective();
if ($mode === 'inspect') {
    $record = CareReferral::where('reference', $argv[3] ?? '')->sole();
    if (($record->identity['last_name'] ?? '') !== 'Routing QA' || ($record->identity['first_name'] ?? '') !== 'Fictional Funding') {
        throw new RuntimeException('Fictional funding-routing browser record required');
    }
    $fundingCases = DB::table('care_referral_funding_cases')->where('referral_id', $record->id)->count();
    if ((int) $record->owner_id !== $reviewer->id || $record->stage !== 'new' || $record->client_id !== null || $fundingCases !== 0
        || ($record->intake['reported_pathway']['status'] ?? '') !== 'reported_unverified') {
        throw new RuntimeException('Fictional browser outcome did not match the expected unverified intake assignment');
    }
    echo json_encode(['passed' => true, 'reference' => $record->reference, 'owner' => $reviewer->name, 'settings_version_id' => $record->settings_version_id,
        'version' => $record->lock_version, 'stage' => $record->stage, 'funding_cases' => $fundingCases, 'person_id' => $record->client_id,
        'reported_pathway' => $record->intake['reported_pathway']]).PHP_EOL;

    return;
}
if ($mode === 'prepare') {
    if (is_file($marker)) {
        throw new RuntimeException('Existing routing QA marker requires review before preparation.');
    }
    $state = ['database' => $database, 'base_id' => $current->id, 'prepared_id' => null];
    file_put_contents($marker, json_encode($state, JSON_PRETTY_PRINT));
    $configuration = $current->configuration;
    $configuration['routing'] = ['enabled' => true, 'fallback_owner_ids' => [$author->id], 'fallback_max_open' => 1000, 'rules' => [
        ['id' => 'qa_funding_navigator', 'label' => 'Fictional funding navigator', 'enabled' => true,
            'service_ids' => ['supported_living'], 'regions' => [], 'funding_situations' => ['need_help'], 'pathway_ids' => ['dss_nasc'],
            'site_id' => $author->hrEmployeeProfile->primary_site_id, 'team' => 'Fictional navigation QA', 'owner_ids' => [$reviewer->id], 'max_open' => 1000],
    ]];
} else {
    $state = json_decode(file_get_contents($marker), true, flags: JSON_THROW_ON_ERROR);
    if ($state['database'] !== $database) {
        throw new RuntimeException('Marker database mismatch');
    }
    if (isset($state['restored_id'])) {
        echo json_encode($state).PHP_EOL;

        return;
    }
    if ($current->id !== $state['prepared_id']) {
        throw new RuntimeException('Current publication changed; manual review required');
    }
    $configuration = ReferralSettingsVersion::findOrFail($state['base_id'])->configuration;
}
$published = DB::transaction(function () use ($settings, $author, $reviewer, $configuration, $current, $mode) {
    $draft = $settings->draft($author, $configuration, $current->id, $mode === 'prepare' ? 'Fictional funding-routing browser QA only' : 'Restore previous routing after fictional browser QA');

    return $settings->publish($reviewer, $draft->id, now()->toISOString());
});
$state[$mode === 'prepare' ? 'prepared_id' : 'restored_id'] = $published->id;
file_put_contents($marker, json_encode($state, JSON_PRETTY_PRINT));
echo json_encode([...$state, 'version' => $published->version, 'mode' => $mode]).PHP_EOL;
