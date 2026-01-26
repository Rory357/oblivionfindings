<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientCondition;
use App\Models\ClientEmergencyContact;
use App\Models\ClientMedicalProfile;
use App\Models\ClientRisk;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class SystemClientsSeeder extends Seeder
{
    public function run(): void
    {
        $site = Site::query()->first();
        $serviceContext = ServiceContext::query()->first();

        $workers = User::query()
            ->where('role', 'support_worker')
            ->orderBy('id')
            ->get();

        if (!$site || !$serviceContext || $workers->isEmpty()) {
            return;
        }

        // Create a fixed set of clients for repeatable testing.
        $seedClients = [
            ['first_name' => 'Rosie', 'last_name' => 'Ngata'],
            ['first_name' => 'Wiremu', 'last_name' => 'Tait'],
            ['first_name' => 'Aroha', 'last_name' => 'Kingi'],
            ['first_name' => 'Mila', 'last_name' => 'Singh'],
            ['first_name' => 'Oliver', 'last_name' => 'Chen'],
            ['first_name' => 'Hana', 'last_name' => 'Patel'],
            ['first_name' => 'Noah', 'last_name' => 'Williams'],
            ['first_name' => 'Sofia', 'last_name' => 'Brown'],
            ['first_name' => 'Liam', 'last_name' => 'Davis'],
            ['first_name' => 'Amelia', 'last_name' => 'Wilson'],
            ['first_name' => 'Jack', 'last_name' => 'Taylor'],
            ['first_name' => 'Isla', 'last_name' => 'Martin'],
        ];

        $clients = collect();

        foreach ($seedClients as $idx => $c) {
            $email = strtolower($c['first_name'] . '.' . $c['last_name']) . '@client.demo.test';

            $client = Client::updateOrCreate(
                ['email' => $email],
                [
                    'site_id' => $site->id,
                    'service_context_id' => $serviceContext->id,
                    'first_name' => $c['first_name'],
                    'last_name' => $c['last_name'],
                    'preferred_name' => $c['first_name'],
                    'gender' => ['female', 'male'][($idx % 2)],
                    'status' => 'active',
                    'phone' => '021 ' . str_pad((string) rand(1000000, 9999999), 7, '0', STR_PAD_LEFT),
                    'address_line_1' => (string) rand(1, 40) . ' Example Road',
                    'suburb' => 'Auckland Central',
                    'city' => 'Auckland',
                    'postcode' => '1010',
                    'funding_type' => ['NASC', 'ACC', 'Private'][($idx % 3)],
                    'funding_notes' => 'Seeded for demo/testing.',
                ]
            );

            $clients->push($client);

            // Assign 2–3 support workers.
            $client->supportWorkers()->sync(
                $workers->random(rand(2, 3))->pluck('id')->all()
            );

            // Medical profile
            ClientMedicalProfile::updateOrCreate(
                ['client_id' => $client->id],
                [
                    'medical_history' => ($idx % 2 === 0)
                        ? 'Long-term support needs. Previous hospitalisations: none recent.'
                        : 'Health history includes periodic GP follow-ups. No recent admissions.',
                    'disabilities' => ($idx % 2 === 0)
                        ? 'Intellectual disability'
                        : 'Autism spectrum disorder',
                    'allergies' => ($idx % 3 === 0) ? 'Penicillin' : null,
                    'notes' => 'Seeded medical profile for realistic demo testing.',
                ]
            );

            // Emergency contacts
            ClientEmergencyContact::updateOrCreate(
                ['client_id' => $client->id, 'email' => 'kin.' . $client->id . '@demo.test'],
                [
                    'name' => 'Next of Kin ' . $client->first_name,
                    'relationship' => 'Parent/Guardian',
                    'phone' => '027 ' . str_pad((string) rand(1000000, 9999999), 7, '0', STR_PAD_LEFT),
                    'notes' => 'Primary contact',
                ]
            );

            // Conditions
            if ($idx % 2 === 0) {
                ClientCondition::updateOrCreate(
                    ['client_id' => $client->id, 'label' => 'Epilepsy'],
                    ['severity' => 'medium', 'notes' => 'Monitor triggers and report seizures.']
                );
            }

            ClientCondition::updateOrCreate(
                ['client_id' => $client->id, 'label' => 'Anxiety'],
                ['severity' => 'low', 'notes' => 'Use de-escalation plan.']
            );

            // Risks (schema: label, severity, controls, review_date, active)
            ClientRisk::updateOrCreate(
                ['client_id' => $client->id, 'label' => 'Falls risk'],
                [
                    'severity' => 'medium',
                    'controls' => 'Night light, non-slip socks, adequate lighting, staff supervision when needed.',
                    'review_date' => now()->addMonths(3)->toDateString(),
                    'active' => true,
                ]
            );

            // ----------------------
            // Onboarding overrides example (schema-safe)
            // ----------------------
            if ($idx % 4 === 0 && Schema::hasTable('client_onboarding_overrides')) {
                // Identify the "section/key" column name
                $sectionColumn = null;
                foreach (['section', 'key', 'module', 'area', 'tab', 'slug', 'type'] as $candidate) {
                    if (Schema::hasColumn('client_onboarding_overrides', $candidate)) {
                        $sectionColumn = $candidate;
                        break;
                    }
                }

                if (!$sectionColumn) {
                    throw new \RuntimeException(
                        "client_onboarding_overrides exists but no identifier column found (expected one of: section, key, module, area, tab, slug, type)."
                    );
                }

                // Identify the boolean flag column for "not applicable"
                $flagColumn = null;
                foreach (['marked_not_applicable', 'is_not_applicable', 'is_na'] as $candidate) {
                    if (Schema::hasColumn('client_onboarding_overrides', $candidate)) {
                        $flagColumn = $candidate;
                        break;
                    }
                }

                // Notes column (optional)
                $notesColumn = Schema::hasColumn('client_onboarding_overrides', 'notes') ? 'notes' : null;

                $where = [
                    'client_id' => $client->id,
                    $sectionColumn => 'medications',
                ];

                $values = [];
                if ($flagColumn) {
                    $values[$flagColumn] = true;
                }

                if ($notesColumn) {
                    $values[$notesColumn] = 'No regular medications at present.';
                }

                $now = now();
                if (Schema::hasColumn('client_onboarding_overrides', 'updated_at')) {
                    $values['updated_at'] = $now;
                }
                if (Schema::hasColumn('client_onboarding_overrides', 'created_at')) {
                    $values['created_at'] = $now;
                }

                DB::table('client_onboarding_overrides')->updateOrInsert($where, $values);
            }
        }

        // ----------------------
        // Portal users (client + next-of-kin)
        // ----------------------
        $clientRole = Role::query()->where('name', 'client')->first();
        $nokRole = Role::query()->where('name', 'next_of_kin')->first();

        $portalPassword = Hash::make('password');

        foreach ($clients->take(4) as $client) {
            // Client portal user
            $uClient = User::updateOrCreate(
                ['email' => 'portal.client.' . $client->id . '@demo.test'],
                [
                    'name' => $client->first_name . ' ' . $client->last_name . ' (Portal)',
                    'password' => $portalPassword,
                    'role' => 'client',
                    'approved_at' => now(),
                    'email_verified_at' => now(),
                ]
            );

            if ($clientRole) {
                $uClient->roles()->syncWithoutDetaching([$clientRole->id]);
            }
            $uClient->portalClients()->syncWithoutDetaching([$client->id => ['relation' => 'self']]);

            // Next-of-kin portal user
            $uNok = User::updateOrCreate(
                ['email' => 'portal.nok.' . $client->id . '@demo.test'],
                [
                    'name' => 'Guardian of ' . $client->first_name,
                    'password' => $portalPassword,
                    'role' => 'next_of_kin',
                    'approved_at' => now(),
                    'email_verified_at' => now(),
                ]
            );

            if ($nokRole) {
                $uNok->roles()->syncWithoutDetaching([$nokRole->id]);
            }
            $uNok->portalClients()->syncWithoutDetaching([$client->id => ['relation' => 'guardian']]);
        }
    }
}
