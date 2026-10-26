<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$mode = $argv[2] ?? '';
if (! in_array($mode, ['prepare', 'restore'], true)) {
    throw new RuntimeException('Choose prepare or restore.');
}
$marker = __DIR__.'/runtime/checklists-browser.json';
$author = User::where('email', 'author@referrals-preview.test')->sole();
$reviewer = User::where('email', 'reviewer@referrals-preview.test')->sole();
$settings = app(ReferralSettings::class);
if ($mode === 'prepare') {
    if (is_file($marker)) {
        throw new RuntimeException('Existing checklist QA marker requires review before preparation.');
    }
    $base = $settings->effective();
    $configuration = $base->configuration;
    $configuration['checklists'] = [...($configuration['checklists'] ?? []), [
        'id' => 'qa_preparation', 'label' => 'Fictional funding preparation', 'enabled' => true,
        'audience' => 'funding', 'role_id' => null, 'service_ids' => ['supported_living'], 'gate' => 'handover',
        'items' => [['id' => 'qa_next_steps', 'title' => 'Discuss application next steps', 'instruction' => 'Demonstration only. This review does not approve funding or start support.', 'enabled' => true, 'required' => true]],
    ]];
    file_put_contents($marker, json_encode(['database' => $database, 'base_id' => $base->id, 'prepared_id' => null], JSON_PRETTY_PRINT));
    [$published, $record] = DB::transaction(function () use ($settings, $author, $reviewer, $configuration, $base) {
        $draft = $settings->draft($author, $configuration, $base->id, 'Fictional checklist browser QA only');
        $published = $settings->publish($reviewer, $draft->id, now()->toISOString());
        // A separate, explicitly fictional fixture. No person, approval or service-start record is copied.
        $record = CareReferral::where('reference', '01M56D8GMKQTZ5PB6RSG9CSPJH')->sole()->replicate();
        $record->fill(['reference' => null, 'settings_version_id' => $published->id, 'owner_id' => $author->id,
            'site_id' => CareReferral::where('reference', '01M56ACS2FJVSWQTTN8TW1DZ8B')->sole()->site_id,
            'identity' => ['first_name' => 'Fictional Checklist', 'last_name' => 'QA'], 'intake' => [], 'assessment' => [],
            'authority' => ['review' => ['state' => 'current', 'scopes' => ['collect' => true], 'review_due_at' => now()->addDay()->toISOString(), 'note' => 'Synthetic collection-only authority for fictional UI testing; no actual consent or service authority.']],
            'stage' => 'assessment', 'lock_version' => 1, 'next_action' => 'Fictional checklist browser review only']);
        $record->save();

        return [$published, $record];
    });
    $state = ['database' => $database, 'base_id' => $base->id, 'prepared_id' => $published->id, 'reference' => $record->reference];
    file_put_contents($marker, json_encode($state, JSON_PRETTY_PRINT));
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
    $known = array_column($configuration['checklists'] ?? [], 'id');
    foreach ($current->configuration['checklists'] as $template) {
        if (! in_array($template['id'], $known, true)) {
            $configuration['checklists'][] = [...$template, 'enabled' => false];
        }
    }
    $published = DB::transaction(function () use ($settings, $author, $reviewer, $configuration, $current) {
        $draft = $settings->draft($author, $configuration, $current->id, 'Restore prior policy; archive fictional checklist without deleting history');

        return $settings->publish($reviewer, $draft->id, now()->toISOString());
    });
    $state['restored_id'] = $published->id;
    file_put_contents($marker, json_encode($state, JSON_PRETTY_PRINT));
}
echo json_encode([...$state, 'version' => $published->version, 'mode' => $mode]).PHP_EOL;
