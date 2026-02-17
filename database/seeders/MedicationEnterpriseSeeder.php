<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationAllergy;
use App\Models\MedicationInteraction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class MedicationEnterpriseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedDrugInteractions();
        $this->seedSampleAllergies();
        $this->enhanceExistingMedications();
    }

    /**
     * Seed drug interaction reference data
     */
    private function seedDrugInteractions(): void
    {
        $interactions = [
            [
                'medication_a' => 'Warfarin',
                'medication_b' => 'Aspirin',
                'severity' => 'major',
                'description' => 'Increased risk of bleeding when Warfarin is used with Aspirin.',
                'clinical_effects' => 'Enhanced anticoagulant effect, potential for serious bleeding.',
                'management' => 'Monitor INR closely. Consider alternative analgesics.',
            ],
            [
                'medication_a' => 'Warfarin',
                'medication_b' => 'Paracetamol',
                'severity' => 'moderate',
                'description' => 'Regular use of Paracetamol may increase INR.',
                'clinical_effects' => 'Potential increase in anticoagulant effect with regular use.',
                'management' => 'Monitor INR if regular paracetamol use required.',
            ],
            [
                'medication_a' => 'Lorazepam',
                'medication_b' => 'Morphine',
                'severity' => 'major',
                'description' => 'Additive CNS depression. Increased sedation and respiratory depression.',
                'clinical_effects' => 'Profound sedation, respiratory depression, hypotension.',
                'management' => 'Use lowest effective doses. Monitor respiratory function.',
            ],
            [
                'medication_a' => 'Metformin',
                'medication_b' => 'Contrast dye',
                'severity' => 'contraindicated',
                'description' => 'Risk of lactic acidosis when Metformin used with iodinated contrast.',
                'clinical_effects' => 'Acute kidney injury, lactic acidosis.',
                'management' => 'Withhold metformin 48 hours before and after contrast.',
            ],
            [
                'medication_a' => 'ACE inhibitors',
                'medication_b' => 'Potassium supplements',
                'severity' => 'moderate',
                'description' => 'Risk of hyperkalemia.',
                'clinical_effects' => 'Elevated potassium levels, cardiac arrhythmias.',
                'management' => 'Monitor potassium levels regularly.',
            ],
            [
                'medication_a' => 'Digoxin',
                'medication_b' => 'Amiodarone',
                'severity' => 'major',
                'description' => 'Amiodarone increases digoxin levels.',
                'clinical_effects' => 'Digoxin toxicity: nausea, arrhythmias, confusion.',
                'management' => 'Reduce digoxin dose by 50% when starting amiodarone.',
            ],
        ];

        foreach ($interactions as $interaction) {
            MedicationInteraction::firstOrCreate(
                [
                    'medication_a' => $interaction['medication_a'],
                    'medication_b' => $interaction['medication_b'],
                ],
                $interaction
            );
        }
    }

    /**
     * Seed sample allergies for demonstration
     */
    private function seedSampleAllergies(): void
    {
        $clients = Client::query()->limit(5)->get();
        $users = User::query()->whereNotNull('email')->limit(3)->get();

        if ($clients->isEmpty() || $users->isEmpty()) {
            return;
        }

        $allergies = [
            [
                'allergen' => 'Penicillin',
                'reaction' => 'Rash, hives, difficulty breathing',
                'severity' => 'severe',
            ],
            [
                'allergen' => 'Aspirin',
                'reaction' => 'Stomach pain, nausea',
                'severity' => 'moderate',
            ],
            [
                'allergen' => 'Shellfish',
                'reaction' => 'Anaphylaxis',
                'severity' => 'life_threatening',
            ],
            [
                'allergen' => 'Latex',
                'reaction' => 'Contact dermatitis',
                'severity' => 'mild',
            ],
            [
                'allergen' => 'Sulfa drugs',
                'reaction' => 'Skin rash, fever',
                'severity' => 'moderate',
            ],
        ];

        foreach ($clients as $index => $client) {
            $allergy = $allergies[$index % count($allergies)];
            
            MedicationAllergy::firstOrCreate(
                [
                    'client_id' => $client->id,
                    'allergen' => $allergy['allergen'],
                ],
                [
                    'reaction' => $allergy['reaction'],
                    'severity' => $allergy['severity'],
                    'identified_date' => Carbon::now()->subMonths(rand(1, 24)),
                    'recorded_by' => $users->random()->id,
                ]
            );
        }
    }

    /**
     * Enhance existing medications with enterprise fields
     */
    private function enhanceExistingMedications(): void
    {
        $medications = ClientMedication::query()->get();

        foreach ($medications as $medication) {
            $updates = [];

            // Add structured dose if not present
            if (!$medication->dose_amount && $medication->dosage) {
                // Try to parse dosage like "1g" or "500mg"
                if (preg_match('/(\d+(?:\.\d+)?)\s*(mg|g|mcg|ml|units|tablet|capsule)/i', $medication->dosage, $matches)) {
                    $updates['dose_amount'] = $matches[1];
                    $updates['dose_unit'] = strtolower($matches[2]);
                }
            }

            // Add indication
            if (!$medication->indication) {
                $indications = [
                    'Paracetamol' => 'Pain relief, fever reduction',
                    'Lorazepam' => 'Anxiety, acute agitation',
                    'Morphine' => 'Moderate to severe pain',
                    'Aspirin' => 'Pain, inflammation, cardiovascular protection',
                    'Warfarin' => 'Anticoagulation',
                    'Metformin' => 'Type 2 diabetes',
                    'Amlodipine' => 'Hypertension, angina',
                ];

                foreach ($indications as $drug => $indication) {
                    if (stripos($medication->name, $drug) !== false) {
                        $updates['indication'] = $indication;
                        break;
                    }
                }
            }

            // Randomly mark some as high risk
            if (!$medication->high_risk && rand(1, 10) === 1) {
                $highRiskMeds = ['Warfarin', 'Morphine', 'Insulin', 'Methotrexate', 'Digoxin'];
                foreach ($highRiskMeds as $riskMed) {
                    if (stripos($medication->name, $riskMed) !== false) {
                        $updates['high_risk'] = true;
                        break;
                    }
                }
            }

            // Set version if not set
            if (!$medication->version) {
                $updates['version'] = 1;
            }

            if (!empty($updates)) {
                $medication->update($updates);
            }
        }
    }
}
