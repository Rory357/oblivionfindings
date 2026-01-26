<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\TimelineEvent;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class SystemDocumentsAndNotesSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('role', 'admin')->first();
        $workers = User::query()->where('role', 'support_worker')->get();
        if (!$admin || $workers->isEmpty()) {
            return;
        }

        Storage::disk('public')->makeDirectory('client-documents');

        $clients = Client::query()->with(['site'])->get();
        foreach ($clients as $i => $client) {
            $actor = $client->supportWorkers()->first() ?: $workers->random();

            // Pinned handover note
            TimelineEvent::create([
                'source_type' => 'note',
                'source_id' => null,
                'occurred_at' => now()->subDays(3),
                'type' => 'handover',
                'actor_user_id' => $actor->id,
                'client_id' => $client->id,
                'shift_id' => null,
                'site_id' => $client->site_id,
                'subject' => 'Handover: key preferences',
                'body' => 'Prefers quiet mornings. Remind about hydration. Use visual schedule when anxious.',
                'meta' => ['seed' => true],
                'visibility' => 'staff',
                'is_pinned' => ($i % 3 === 0),
                'created_by' => $actor->id,
            ]);

            // Routine shift note (non-pinned)
            TimelineEvent::create([
                'source_type' => 'note',
                'source_id' => null,
                'occurred_at' => now()->subHours(6),
                'type' => 'note',
                'actor_user_id' => $actor->id,
                'client_id' => $client->id,
                'shift_id' => null,
                'site_id' => $client->site_id,
                'subject' => 'Daily note',
                'body' => 'Good engagement today. Completed planned activity. No concerns noted.',
                'meta' => ['seed' => true],
                'visibility' => 'staff',
                'is_pinned' => false,
                'created_by' => $actor->id,
            ]);

            // Seed a client document (with a real stored file)
            $docPath = "client-documents/support-plan-{$client->id}.txt";
            if (!Storage::disk('public')->exists($docPath)) {
                Storage::disk('public')->put($docPath, "Support Plan (Seed) for {$client->first_name} {$client->last_name}\n\nThis is demo content for testing downloads and portal visibility.\n");
            }

            ClientDocument::updateOrCreate(
                ['client_id' => $client->id, 'title' => 'Support Plan (Seed)'],
                [
                    'uploaded_by_user_id' => $admin->id,
                    'category' => 'support_plan',
                    'version' => '1.0',
                    'effective_date' => now()->subMonths(1)->toDateString(),
                    'expiry_date' => now()->addMonths(11)->toDateString(),
                    'portal_visible' => ($i % 4 === 0),
                    'notes' => 'Seeded support plan document.',
                    'storage_disk' => 'public',
                    'storage_path' => $docPath,
                    'original_name' => "support-plan-{$client->id}.txt",
                    'mime_type' => 'text/plain',
                    'size_bytes' => Storage::disk('public')->size($docPath),
                ]
            );
        }
    }
}
