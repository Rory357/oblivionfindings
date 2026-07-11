<?php

use App\Models\Client;
use App\Models\ClientMedicalProfile;
use App\Models\ClientRisk;
use App\Support\ClientSafetyPayload;

it('omits every medical and risk signal when those sections are not authorised', function () {
    $client = new Client([
        'risk_level' => 'critical',
        'safeguarding_flag' => true,
    ]);
    $client->setRelation('medicalProfile', new ClientMedicalProfile([
        'allergies' => ['penicillin'],
        'disabilities' => ['epilepsy'],
    ]));
    $client->setRelation('risks', collect([
        new ClientRisk([
            'label' => 'Restricted risk detail',
            'severity' => 'critical',
            'active' => true,
        ]),
    ]));

    $payload = ClientSafetyPayload::forClient(
        $client,
        includeMedical: false,
        includeRisks: false,
    );
    $summary = ClientSafetyPayload::summaryForClient(
        $client,
        includeMedical: false,
        includeRisks: false,
    );

    expect($payload)->toMatchArray([
        'has_any' => false,
        'allergies' => [],
        'critical_risks' => [],
        'other_risks_count' => 0,
        'active_risks_count' => 0,
        'care_flags' => [],
        'risk_level' => null,
        'safeguarding_flag' => false,
    ])->and($summary)->toMatchArray([
        'has_any' => false,
        'allergies_count' => 0,
        'critical_risks_count' => 0,
        'active_risks_count' => 0,
        'safeguarding' => false,
        'risk_level' => null,
        'top_allergy' => null,
        'top_risk' => null,
    ]);
});

it('can expose medical and risk safety signals independently', function () {
    $client = new Client([
        'risk_level' => 'high',
        'safeguarding_flag' => true,
    ]);
    $client->setRelation('medicalProfile', new ClientMedicalProfile([
        'allergies' => ['penicillin'],
        'disabilities' => ['epilepsy'],
    ]));
    $client->setRelation('risks', collect([
        new ClientRisk([
            'label' => 'Restricted risk detail',
            'severity' => 'critical',
            'active' => true,
        ]),
    ]));

    $medicalOnly = ClientSafetyPayload::forClient(
        $client,
        includeMedical: true,
        includeRisks: false,
    );
    $riskOnly = ClientSafetyPayload::forClient(
        $client,
        includeMedical: false,
        includeRisks: true,
    );

    expect($medicalOnly['allergies'])->toHaveCount(1)
        ->and($medicalOnly['critical_risks'])->toBe([])
        ->and(collect($medicalOnly['care_flags'])->pluck('key')->all())->toBe(['disability_epilepsy'])
        ->and($medicalOnly['risk_level'])->toBeNull()
        ->and($medicalOnly['safeguarding_flag'])->toBeFalse()
        ->and($riskOnly['allergies'])->toBe([])
        ->and($riskOnly['critical_risks'])->toHaveCount(1)
        ->and(collect($riskOnly['care_flags'])->pluck('key')->all())->toBe([
            'safeguarding',
            'risk_level_high',
        ])
        ->and($riskOnly['risk_level'])->toBe('high')
        ->and($riskOnly['safeguarding_flag'])->toBeTrue();
});
