<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientCondition;
use App\Models\ClientEmergencyContact;
use App\Models\ClientMedicalProfile;
use App\Models\ClientMedication;
use Illuminate\Database\Seeder;

class DebugMedicalData extends Seeder
{
    public function run(): void
    {
        $client = Client::where('first_name', 'James')->where('last_name', 'Anderson')->first();
        
        if (!$client) {
            $this->command->error('James Anderson not found');
            return;
        }
        
        $this->command->info("Client ID: {$client->id}");
        $this->command->info("Name: {$client->first_name} {$client->last_name}");
        $this->command->info('');
        
        // Check medications
        $meds = ClientMedication::where('client_id', $client->id)->get();
        $this->command->info("Medications: {$meds->count()}");
        foreach ($meds as $m) {
            $this->command->info("  - {$m->name} ({$m->state})");
        }
        
        // Check conditions
        $conds = ClientCondition::where('client_id', $client->id)->get();
        $this->command->info("Conditions: {$conds->count()}");
        foreach ($conds as $c) {
            $this->command->info("  - {$c->label}");
        }
        
        // Check contacts
        $contacts = ClientEmergencyContact::where('client_id', $client->id)->get();
        $this->command->info("Contacts: {$contacts->count()}");
        foreach ($contacts as $c) {
            $this->command->info("  - {$c->name}");
        }
        
        // Check profile
        $profile = ClientMedicalProfile::where('client_id', $client->id)->first();
        $this->command->info("Profile: " . ($profile ? 'yes' : 'no'));
        if ($profile) {
            $this->command->info("  Allergies: " . ($profile->allergies ? 'yes' : 'no'));
            $this->command->info("  History: " . ($profile->medical_history ? 'yes' : 'no'));
        }
    }
}
