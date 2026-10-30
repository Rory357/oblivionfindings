<?php

// Disposable, explicitly fictional browser fixture. Restore publishes the prior policy again.
require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralConfiguration;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralProposals;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

$marker = __DIR__.'/runtime/company-funding-fixture.json';
if (($argv[2] ?? '') === 'restore') {
    $fixture = json_decode(file_get_contents($marker), true, flags: JSON_THROW_ON_ERROR);
    if ($fixture['database'] !== $database) {
        throw new RuntimeException('Preview fixture mismatch.');
    }
    $original = ReferralSettingsVersion::findOrFail($fixture['prior_settings_id']);
    $restored = DB::transaction(function () use ($original, $version) {
        $pointer = ReferralConfiguration::lockForUpdate()->findOrFail(1);
        $reason = 'Fictional QA: restore prior policy after temporary company-funding browser test.';
        $restored = $version->reason === $reason && $version->configuration === $original->configuration ? $version
            : ReferralSettingsVersion::create(['version' => ReferralSettingsVersion::max('version') + 1, 'state' => 'published', 'base_version_id' => $pointer->published_version_id,
                'configuration' => $original->configuration, 'reason' => $reason, 'effective_at' => now(), 'published_at' => now()]);
        if ($pointer->published_version_id !== $restored->id) {
            $pointer->update(['published_version_id' => $restored->id, 'lock_version' => $pointer->lock_version + 1]);
        }

        return $restored;
    });
    echo json_encode(['restored_version' => $restored->version]).PHP_EOL;
    exit;
}
if (is_file($marker)) {
    throw new RuntimeException('Fixture already exists; do not duplicate it.');
}
$fixture = DB::transaction(function () use ($version, $database): array {
    $configuration = $version->configuration;
    $configuration['proposals']['temporary_funding_enabled'] = true;
    $configuration['proposals']['approval_limits'][] = ['currency' => 'NZD', 'period' => 'total', 'role_id' => null, 'limit_cents' => 100000];
    $settings = ReferralSettingsVersion::create(['version' => ReferralSettingsVersion::max('version') + 1, 'state' => 'published', 'configuration' => $configuration,
        'reason' => 'Fictional QA only: temporary policy for company-funding browser test.', 'effective_at' => now(), 'published_at' => now()]);
    $source = CareReferral::where('reference', '01M55ZQRKFN2MMX8HVQ1DXY9V2')->sole();
    $actor = User::where('email', 'author@referrals-preview.test')->sole();
    $record = CareReferral::create(['settings_version_id' => $settings->id, 'site_id' => $source->site_id, 'owner_id' => $actor->id,
        'stage' => 'assessment', 'source' => 'phone', 'entry_kind' => 'self', 'requested_service' => $source->requested_service,
        'identity' => ['first_name' => 'Fictional', 'last_name' => 'Company funding QA'], 'intake' => ['qa_simulation' => true],
        'authority' => $source->authority, 'assessment' => [], 'next_action' => 'Fictional browser checks only; no real budget or care authorization',
        'next_due_at' => now()->addDay(), 'stage_changed_at' => now(), 'lock_version' => 1]);
    $evidence = ReferralEvidence::findOrFail(3)->replicate(['reference']);
    $evidence->referral_id = $record->id;
    $evidence->metadata = [...$evidence->metadata, 'title' => 'Fictional file-selection simulation only', 'source' => 'QA fixture; does not authorize a real company budget'];
    $evidence->save();
    $proposals = app(ReferralProposals::class);
    $old = $proposals->latest($source);
    $body = Arr::only($old->proposal, ['title', 'starts_on', 'ends_on', 'support_rationale', 'funding_position', 'capacity_plan', 'person_participation', 'reason', 'lines', 'allocations']);
    $body['previous_proposal_id'] = null;
    $body['title'] = 'Fictional total-term company support';
    $body['allocations'] = [];
    $body['lines'] = array_map(fn ($line) => [...Arr::only($line, ['key', 'service_id', 'description', 'quantity', 'unit', 'unit_label', 'period', 'currency', 'unit_rate', 'tax_rate_id', 'price_basis', 'price_source']), 'period' => 'total'], $body['lines']);
    $proposal = $proposals->save($actor, $record->id, 1, $body);

    return ['database' => $database, 'prior_settings_id' => $version->id, 'settings_version' => $settings->version, 'reference' => $record->reference, 'proposal_id' => $proposal->id, 'evidence_id' => $evidence->id];
});
file_put_contents($marker, json_encode($fixture, JSON_THROW_ON_ERROR));
echo json_encode($fixture).PHP_EOL;
