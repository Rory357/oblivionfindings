<?php

namespace Database\Seeders;

use App\Enums\ServiceType;
use App\Models\IncidentTemplate;
use App\Models\ServiceContext;
use App\Models\Site;
use Illuminate\Database\Seeder;

class SystemCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // ----------------------
        // Sites
        // ----------------------
        $siteA = Site::firstOrCreate(
            ['name' => 'Kauri House'],
            [
                'address_line_1' => '12 Kauri Street',
                'suburb' => 'Grey Lynn',
                'city' => 'Auckland',
                'postcode' => '1021',
                'country' => 'New Zealand',
                'is_active' => true,
            ]
        );

        $siteB = Site::firstOrCreate(
            ['name' => 'Harbour Respite'],
            [
                'address_line_1' => '8 Quay Road',
                'suburb' => 'Devonport',
                'city' => 'Auckland',
                'postcode' => '0624',
                'country' => 'New Zealand',
                'is_active' => true,
            ]
        );

        // ----------------------
        // Service contexts
        // ----------------------
        ServiceContext::firstOrCreate(
            ['type' => ServiceType::Residential->value, 'site_id' => $siteA->id],
            [
                'name' => 'Residential (Kauri House)',
                'description' => '24/7 supported living',
                'is_active' => true,
            ]
        );

        ServiceContext::firstOrCreate(
            ['type' => ServiceType::HomeSupport->value, 'site_id' => $siteA->id],
            [
                'name' => 'Home Support',
                'description' => 'Community/home visits and appointments',
                'is_active' => true,
            ]
        );

        ServiceContext::firstOrCreate(
            ['type' => ServiceType::PlannedRespite->value, 'site_id' => $siteB->id],
            [
                'name' => 'Respite (Harbour)',
                'description' => 'Short stay / respite care',
                'is_active' => true,
            ]
        );

        // ----------------------
        // Incident templates
        // Schema: name, type, severity, default_description, prompts, checklist, is_active
        // ----------------------
        $templates = [
            [
                'name' => 'Medication incident',
                'type' => 'medication',
                'severity' => 'high',
                'default_description' => 'Use this when there is a medication error, discrepancy, missed dose, or adverse reaction.',
                'prompts' => [
                    ['key' => 'med_name', 'label' => 'Medication', 'type' => 'text', 'required' => true],
                    ['key' => 'dose', 'label' => 'Dose', 'type' => 'text', 'required' => false],
                    ['key' => 'what_happened', 'label' => 'What happened?', 'type' => 'textarea', 'required' => true],
                    ['key' => 'immediate_actions', 'label' => 'Immediate actions taken', 'type' => 'textarea', 'required' => false],
                ],
                'checklist' => [
                    ['label' => 'Ensure client is safe and stable', 'required' => true],
                    ['label' => 'Inform manager/supervisor', 'required' => true],
                    ['label' => 'Contact GP/pharmacy if required', 'required' => false],
                    ['label' => 'Complete follow-up actions', 'required' => false],
                ],
            ],
            [
                'name' => 'Fall / injury',
                'type' => 'fall',
                'severity' => 'medium',
                'default_description' => 'Use this for falls, slips, trips, injuries, or suspected injuries.',
                'prompts' => [
                    ['key' => 'injury', 'label' => 'Injury details', 'type' => 'textarea', 'required' => false],
                    ['key' => 'witnessed', 'label' => 'Was it witnessed?', 'type' => 'select', 'options' => ['Yes', 'No'], 'required' => false],
                    ['key' => 'where', 'label' => 'Where did it happen?', 'type' => 'text', 'required' => false],
                    ['key' => 'what_happened', 'label' => 'What happened?', 'type' => 'textarea', 'required' => true],
                ],
                'checklist' => [
                    ['label' => 'Assess for injury and provide first aid', 'required' => true],
                    ['label' => 'Escalate if medical review required', 'required' => false],
                    ['label' => 'Document observations', 'required' => true],
                ],
            ],
            [
                'name' => 'Behaviour / escalation',
                'type' => 'behaviour',
                'severity' => 'medium',
                'default_description' => 'Use this for behavioural incidents, escalation, absconding risk, or distress events.',
                'prompts' => [
                    ['key' => 'triggers', 'label' => 'Known triggers', 'type' => 'textarea', 'required' => false],
                    ['key' => 'deescalation', 'label' => 'De-escalation steps used', 'type' => 'textarea', 'required' => false],
                    ['key' => 'what_happened', 'label' => 'What happened?', 'type' => 'textarea', 'required' => true],
                    ['key' => 'outcome', 'label' => 'Outcome / current state', 'type' => 'textarea', 'required' => false],
                ],
                'checklist' => [
                    ['label' => 'Ensure safety of client and others', 'required' => true],
                    ['label' => 'Use behaviour support plan if applicable', 'required' => false],
                    ['label' => 'Notify manager if threshold met', 'required' => false],
                ],
            ],
        ];

        foreach ($templates as $t) {
            IncidentTemplate::updateOrCreate(
                ['name' => $t['name']], // ✅ correct column
                [
                    'type' => $t['type'],
                    'severity' => $t['severity'],
                    'default_description' => $t['default_description'],
                    'prompts' => $t['prompts'],
                    'checklist' => $t['checklist'],
                    'is_active' => true,
                ]
            );
        }
    }
}
