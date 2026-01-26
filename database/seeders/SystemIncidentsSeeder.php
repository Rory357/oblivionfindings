<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientIncidentAttachment;
use App\Models\IncidentFollowup;
use App\Models\IncidentTemplate;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class SystemIncidentsSeeder extends Seeder
{
    public function run(): void
    {
        $manager = User::query()->where('role', 'provider_manager')->first();
        $workers = User::query()->where('role', 'support_worker')->get();

        $templateMed = IncidentTemplate::query()->where('type', 'medication')->first();
        $templateFall = IncidentTemplate::query()->where('type', 'fall')->first();

        if (!$manager || $workers->isEmpty()) {
            return;
        }

        // Ensure a small dummy attachment exists.
        Storage::disk('public')->makeDirectory('incidents');
        $dummyPath = 'incidents/seed_attachment.txt';
        if (!Storage::disk('public')->exists($dummyPath)) {
            Storage::disk('public')->put($dummyPath, "Seed attachment for demo incidents.\n");
        }

        $clients = Client::query()->with('supportWorkers')->get();

        foreach ($clients as $idx => $client) {
            $reporter = $client->supportWorkers->first() ?: $workers->random();
            $shift = Shift::query()->where('client_id', $client->id)->orderByDesc('starts_at')->first();

            // 1) Draft (editable)
            ClientIncident::create([
                'client_id' => $client->id,
                'reported_by' => $reporter->id,
                'shift_id' => null,
                'template_id' => $templateFall?->id,
                'type' => 'fall',
                'severity' => 'low',
                'status' => 'draft',
                'occurred_at' => now()->subHours(2),
                'title' => 'Minor fall (draft)',
                'description' => 'Seed draft incident (editable until submitted).',
                'requires_followup' => false,
                'portal_visible' => false,
            ]);

            // 2) Submitted + needs followup (some left open)
            $submitted = ClientIncident::create([
                'client_id' => $client->id,
                'reported_by' => $reporter->id,
                'shift_id' => $shift?->id,
                'template_id' => $templateMed?->id,
                'type' => 'medication',
                'severity' => ($idx % 3 === 0) ? 'high' : 'medium',
                'status' => 'submitted',
                'occurred_at' => now()->subDays(1)->setTime(10, 15),
                'title' => 'Medication discrepancy',
                'description' => 'Seed submitted incident with follow-ups.',
                'requires_followup' => true,
                'immediate_action_taken' => 'Notified on-call; monitored client.',
                'submitted_at' => now()->subDays(1)->setTime(12, 0),
                'portal_visible' => ($idx % 4 === 0),
            ]);

            // Attachment
            ClientIncidentAttachment::create([
                'incident_id' => $submitted->id,
                'uploaded_by' => $reporter->id,
                'disk' => 'public',
                'original_name' => 'seed_attachment.txt',
                'path' => $dummyPath,
                'mime' => 'text/plain',
                'mime_type' => 'text/plain',
                'size' => Storage::disk('public')->size($dummyPath),
                'portal_visible' => ($idx % 4 === 0),
                'notes' => 'Seed attachment',
            ]);

            // Followups (one completed, one open)
            $assignee = $workers->random();
            IncidentFollowup::create([
                'client_incident_id' => $submitted->id,
                'assigned_to_user_id' => $assignee->id,
                'due_at' => now()->addDays(1),
                'completed_at' => now()->subHours(6),
                'notes' => 'Completed follow-up (seed).',
                'created_by' => $manager->id,
            ]);
            IncidentFollowup::create([
                'client_incident_id' => $submitted->id,
                'assigned_to_user_id' => $assignee->id,
                'due_at' => now()->addDays(2),
                'completed_at' => ($idx % 5 === 0) ? now()->subHours(2) : null,
                'notes' => ($idx % 5 === 0) ? 'Completed second follow-up (seed).' : null,
                'created_by' => $manager->id,
            ]);

            // 3) Reviewed
            $reviewed = ClientIncident::create([
                'client_id' => $client->id,
                'reported_by' => $reporter->id,
                'shift_id' => $shift?->id,
                'template_id' => $templateFall?->id,
                'type' => 'fall',
                'severity' => 'medium',
                'status' => 'reviewed',
                'occurred_at' => now()->subDays(2)->setTime(9, 0),
                'title' => 'Fall requiring review',
                'description' => 'Seed reviewed incident ready to close once followups complete.',
                'requires_followup' => true,
                'submitted_at' => now()->subDays(2)->setTime(10, 0),
                'reviewed_by' => $manager->id,
                'reviewed_at' => now()->subDays(2)->setTime(15, 0),
                'review_notes' => 'Reviewed; ensure documentation complete.',
                'portal_visible' => true,
            ]);

            // Followup completed (so it can be closed)
            IncidentFollowup::create([
                'client_incident_id' => $reviewed->id,
                'assigned_to_user_id' => $workers->random()->id,
                'due_at' => now()->subDays(1),
                'completed_at' => now()->subDays(1)->setTime(14, 0),
                'notes' => 'Completed follow-up; all actions done.',
                'created_by' => $manager->id,
            ]);

            // 4) Closed
            ClientIncident::create([
                'client_id' => $client->id,
                'reported_by' => $reporter->id,
                'shift_id' => $shift?->id,
                'template_id' => $templateFall?->id,
                'type' => 'fall',
                'severity' => 'low',
                'status' => 'closed',
                'occurred_at' => now()->subDays(5)->setTime(8, 30),
                'title' => 'Fall (closed)',
                'description' => 'Seed closed incident (locked).',
                'requires_followup' => false,
                'submitted_at' => now()->subDays(5)->setTime(9, 15),
                'reviewed_by' => $manager->id,
                'reviewed_at' => now()->subDays(5)->setTime(10, 0),
                'closed_by' => $manager->id,
                'closed_at' => now()->subDays(4)->setTime(16, 0),
                'closed_outcome' => 'Resolved',
                'closed_notes' => 'No injury; updated support plan.',
                'portal_visible' => true,
            ]);

            // 5) Closed then reopened (for testing the new flow)
            if ($idx % 4 === 0) {
                $reopened = ClientIncident::create([
                    'client_id' => $client->id,
                    'reported_by' => $reporter->id,
                    'shift_id' => $shift?->id,
                    'template_id' => $templateMed?->id,
                    'type' => 'medication',
                    'severity' => 'high',
                    'status' => 'reviewed',
                    'occurred_at' => now()->subDays(3)->setTime(11, 0),
                    'title' => 'Medication incident (reopened)',
                    'description' => 'Seed incident that was closed and reopened.',
                    'requires_followup' => true,
                    'submitted_at' => now()->subDays(3)->setTime(12, 0),
                    'reviewed_by' => $manager->id,
                    'reviewed_at' => now()->subDays(3)->setTime(16, 0),
                    'closed_by' => $manager->id,
                    'closed_at' => now()->subDays(2)->setTime(10, 0),
                    'closed_outcome' => 'Resolved',
                    'closed_notes' => 'Initial closure (seed).',
                    'reopened_by' => $manager->id,
                    'reopened_at' => now()->subDays(1)->setTime(9, 0),
                    'reopened_reason' => 'Additional information received; requires further follow-up.',
                    'portal_visible' => true,
                ]);

                // A follow-up outstanding after reopen
                IncidentFollowup::create([
                    'client_incident_id' => $reopened->id,
                    'assigned_to_user_id' => $workers->random()->id,
                    'due_at' => now()->addDay(),
                    'completed_at' => null,
                    'notes' => null,
                    'created_by' => $manager->id,
                ]);
            }
        }
    }
}
