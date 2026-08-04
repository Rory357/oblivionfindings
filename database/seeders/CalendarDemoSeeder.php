<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Shift;
use App\Models\Site;
use App\Models\SiteCalendarEvent;
use App\Models\SiteMealPlanEntry;
use Illuminate\Database\Seeder;

class CalendarDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSiteCalendar();

        $userId = 1;
        $clients = Client::take(6)->get();

        if ($clients->isEmpty()) {
            $this->command->warn('No clients found — skipping calendar demo seed.');
            return;
        }

        $now = now();
        $locations = ['Kauri House', 'Harbour Respite', 'Main Site', 'Rimu Lodge', 'Totara Place'];

        $shifts = [
            // Yesterday (completed)
            ['client_idx' => 0, 'day' => -1, 'start_h' => 7,  'end_h' => 11, 'status' => 'completed', 'loc' => 0],
            ['client_idx' => 1, 'day' => -1, 'start_h' => 12, 'end_h' => 16, 'status' => 'completed', 'loc' => 1],
            ['client_idx' => 2, 'day' => -1, 'start_h' => 17, 'end_h' => 21, 'status' => 'completed', 'loc' => 2],

            // Today
            ['client_idx' => 3, 'day' => 0, 'start_h' => 7,  'end_h' => 11, 'status' => 'completed', 'loc' => 0],
            ['client_idx' => 0, 'day' => 0, 'start_h' => 12, 'end_h' => 16, 'status' => 'in_progress', 'loc' => 1],
            ['client_idx' => 4, 'day' => 0, 'start_h' => 17, 'end_h' => 21, 'status' => 'scheduled', 'loc' => 3],

            // Tomorrow
            ['client_idx' => 1, 'day' => 1, 'start_h' => 6,  'end_h' => 10, 'status' => 'scheduled', 'loc' => 0],
            ['client_idx' => 2, 'day' => 1, 'start_h' => 11, 'end_h' => 15, 'status' => 'scheduled', 'loc' => 2],
            ['client_idx' => 5, 'day' => 1, 'start_h' => 16, 'end_h' => 20, 'status' => 'scheduled', 'loc' => 4],

            // Day +2
            ['client_idx' => 3, 'day' => 2, 'start_h' => 8,  'end_h' => 16, 'status' => 'scheduled', 'loc' => 1],

            // Day +3
            ['client_idx' => 0, 'day' => 3, 'start_h' => 9,  'end_h' => 13, 'status' => 'scheduled', 'loc' => 2],
            ['client_idx' => 4, 'day' => 3, 'start_h' => 14, 'end_h' => 18, 'status' => 'scheduled', 'loc' => 0],

            // Day +4
            ['client_idx' => 1, 'day' => 4, 'start_h' => 7,  'end_h' => 11, 'status' => 'scheduled', 'loc' => 3],
            ['client_idx' => 5, 'day' => 4, 'start_h' => 12, 'end_h' => 17, 'status' => 'scheduled', 'loc' => 4],

            // Day +5
            ['client_idx' => 2, 'day' => 5, 'start_h' => 8,  'end_h' => 12, 'status' => 'scheduled', 'loc' => 1],
            ['client_idx' => 3, 'day' => 5, 'start_h' => 13, 'end_h' => 17, 'status' => 'scheduled', 'loc' => 0],
        ];

        $created = 0;
        foreach ($shifts as $s) {
            $clientIdx = $s['client_idx'] % $clients->count();
            $client = $clients[$clientIdx];
            $day = $now->copy()->addDays($s['day']);

            Shift::create([
                'user_id' => $userId,
                'client_id' => $client->id,
                'starts_at' => $day->copy()->setTime($s['start_h'], 0),
                'ends_at' => $day->copy()->setTime($s['end_h'], 0),
                'status' => $s['status'],
                'location' => $locations[$s['loc']],
                'notes' => 'Seeded for calendar demo',
            ]);
            $created++;
        }

        $this->command->info("Created {$created} demo shifts for user {$userId}.");
    }

    /**
     * Seed manual Site Calendar events + a few meal-plan obligations on a house so
     * the redesigned unified calendar (/calendar and the site profile tab) shows data.
     */
    private function seedSiteCalendar(): void
    {
        $site = Site::query()->where('type', 'house')->orderBy('id')->first();

        if (! $site) {
            $this->command->warn('No house site — skipping site calendar demo seed.');
            return;
        }

        $userId = 1;
        // Author demo times in the business timezone, then store UTC so they
        // render at the intended NZ wall-clock on the unified calendar.
        $tz = config('app.worker_timezone', 'Pacific/Auckland');
        $now = now($tz);

        $events = [
            ['event_type' => 'general', 'title' => 'Resident house meeting', 'day' => 1, 'h' => 16, 'dur' => 1, 'status' => 'approved', 'approval' => 'not_required'],
            ['event_type' => 'maintenance', 'title' => 'Boiler service — Aqua-Safe', 'day' => 2, 'h' => 10, 'dur' => 2, 'status' => 'pending', 'approval' => 'pending'],
            ['event_type' => 'site_visit', 'title' => 'Registered Manager visit', 'day' => 3, 'h' => 11, 'dur' => 1, 'status' => 'approved', 'approval' => 'not_required'],
            ['event_type' => 'inspection', 'title' => 'Monthly fire alarm test', 'day' => 4, 'h' => 9, 'dur' => 1, 'status' => 'approved', 'approval' => 'not_required', 'rrule' => 'FREQ=MONTHLY'],
            ['event_type' => 'general', 'title' => 'Weekly H&S walkaround', 'day' => 0, 'h' => 8, 'dur' => 1, 'status' => 'approved', 'approval' => 'not_required', 'rrule' => 'FREQ=WEEKLY'],
        ];

        $createdEvents = 0;
        foreach ($events as $e) {
            $start = $now->copy()->addDays($e['day'])->setTime($e['h'], 0);
            SiteCalendarEvent::create([
                'site_id' => $site->id,
                'event_type' => $e['event_type'],
                'title' => $e['title'],
                'start_at' => $start->copy()->utc(),
                'end_at' => $start->copy()->addHours($e['dur'])->utc(),
                'recurrence_rule' => $e['rrule'] ?? null,
                'created_by_user_id' => $userId,
                'owner_user_id' => $userId,
                'status' => $e['status'],
                'approval_status' => $e['approval'],
            ]);
            $createdEvents++;
        }

        $meals = [
            ['day' => 0, 'slot' => 'lunch', 'name' => 'Roast chicken & veg'],
            ['day' => 0, 'slot' => 'dinner', 'name' => 'Veg lasagne'],
            ['day' => 1, 'slot' => 'lunch', 'name' => 'Jacket potatoes'],
            ['day' => 2, 'slot' => 'dinner', 'name' => 'Sunday roast'],
            ['day' => 3, 'slot' => 'breakfast', 'name' => 'Porridge & fruit'],
        ];

        $createdMeals = 0;
        foreach ($meals as $m) {
            SiteMealPlanEntry::create([
                'site_id' => $site->id,
                'plan_date' => $now->copy()->addDays($m['day'])->toDateString(),
                'meal_slot' => $m['slot'],
                'source_type' => 'ad_hoc',
                'ad_hoc_name' => $m['name'],
                'servings' => 4,
                'created_by' => $userId,
            ]);
            $createdMeals++;
        }

        $this->command->info("Created {$createdEvents} site calendar events and {$createdMeals} meal entries on {$site->name}.");
    }
}
