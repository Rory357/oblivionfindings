<?php

use App\Models\ConsentType;

// The shared setup refuses persistent schemas and requires the fictional provider.
require __DIR__.'/seed-onboarding-access.php';

$type = ConsentType::firstOrCreate(
    ['name' => 'Fictional QA initial support consent'],
    [
        'category' => 'essential',
        'description' => 'Fictional workflow test only. No real consent or legal authority is represented.',
        'purpose' => 'Fictional agreement to the initial daily-living and community support plan for 18–24 October 2026.',
        'legal_basis' => 'Fictional QA policy only; not a legal determination.',
        'is_mandatory' => true,
        'requires_capacity_assessment' => false,
        'allows_withdrawal' => true,
        'withdrawal_notice_days' => 0,
        'validity_period_days' => 7,
        'renewal_required' => true,
        'renewal_reminder_days' => 1,
        'withdrawal_implications' => 'Fictional scenario: review support arrangements and the person’s choices.',
        'version' => 1,
        'active' => true,
    ],
);
echo json_encode(['fictional_mandatory_consent_type' => $type->only(['id', 'name', 'version'])]).PHP_EOL;
