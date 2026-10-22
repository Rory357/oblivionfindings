<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$mode = $argv[2] ?? '';
if (! in_array($mode, ['prepare', 'restore'], true)) {
    throw new RuntimeException('Choose prepare or restore.');
}
$marker = __DIR__.'/runtime/forms-browser.json';
$author = User::where('email', 'author@referrals-preview.test')->sole();
$reviewer = User::where('email', 'reviewer@referrals-preview.test')->sole();
$settings = app(ReferralSettings::class);
if ($mode === 'prepare') {
    if (is_file($marker)) {
        throw new RuntimeException('Existing form QA marker requires restore/review before another preparation.');
    }
    $base = $settings->effective();
    $configuration = $base->configuration;
    $configuration['forms']['fields'] = [...($configuration['forms']['fields'] ?? []),
        ['id' => 'qa_preferred_introduction', 'label' => 'Fictional preferred introduction', 'help' => 'Demonstration only. Do not enter real personal information.', 'type' => 'text', 'options' => [], 'enabled' => true, 'public' => true, 'required' => 'intake', 'audience' => 'intake', 'destination' => 'referral_intake', 'service_ids' => ['supported_living'], 'purposes' => ['support']],
        ['id' => 'qa_preferred_date', 'label' => 'Fictional preferred date', 'help' => 'An enquiry date is not a confirmed appointment.', 'type' => 'date', 'options' => [], 'enabled' => true, 'public' => true, 'required' => 'optional', 'audience' => 'intake', 'destination' => 'referral_intake', 'service_ids' => ['supported_living'], 'purposes' => ['support']],
        ['id' => 'qa_review_question', 'label' => 'Fictional funding preparation note', 'help' => 'Source information only, not funding approval.', 'type' => 'text', 'options' => [], 'enabled' => true, 'public' => false, 'required' => 'handover', 'audience' => 'funding', 'destination' => 'referral_funding', 'service_ids' => ['supported_living'], 'purposes' => ['support']],
    ];
    // Persist recovery information before publishing an isolated demonstration policy.
    file_put_contents($marker, json_encode(['database' => $database, 'base_id' => $base->id, 'prepared_id' => null], JSON_PRETTY_PRINT));
    $published = DB::transaction(function () use ($settings, $author, $reviewer, $configuration, $base) {
        $draft = $settings->draft($author, $configuration, $base->id, 'Fictional additional form browser QA only');

        return $settings->publish($reviewer, $draft->id, now()->toISOString());
    });
    file_put_contents($marker, json_encode(['database' => $database, 'base_id' => $base->id, 'prepared_id' => $published->id], JSON_PRETTY_PRINT));
} else {
    $state = json_decode(file_get_contents($marker), true, flags: JSON_THROW_ON_ERROR);
    if ($state['database'] !== $database) {
        throw new RuntimeException('Marker database mismatch');
    }
    if (isset($state['restored_id'])) {
        echo json_encode($state).PHP_EOL;

        return;
    }
    $current = $settings->effective();
    if ($current->id !== $state['prepared_id']) {
        throw new RuntimeException('Current publication changed; manual review required');
    }
    $configuration = ReferralSettingsVersion::findOrFail($state['base_id'])->configuration;
    $known = array_column($configuration['forms']['fields'] ?? [], 'id');
    foreach ($current->configuration['forms']['fields'] as $field) {
        if (! in_array($field['id'], $known, true)) {
            $configuration['forms']['fields'][] = [...$field, 'enabled' => false];
        }
    }
    $published = DB::transaction(function () use ($settings, $author, $reviewer, $configuration, $current) {
        $draft = $settings->draft($author, $configuration, $current->id, 'Restore prior policy; archive fictional browser questions without deleting history');

        return $settings->publish($reviewer, $draft->id, now()->toISOString());
    });
    $state['restored_id'] = $published->id;
    file_put_contents($marker, json_encode($state, JSON_PRETTY_PRINT));
}
echo json_encode(['published_id' => $published->id, 'version' => $published->version, 'mode' => $mode]).PHP_EOL;
