<?php

/*
 * EA-015 / EA-016 / EA-018: a medication alert about a person goes only to
 * people who pass the same per-person gate as the bell and the record; its
 * link is one the recipient can open; and the All Tasks 3-day manager
 * escalation of a medication item goes only to managers who can open it.
 */

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationAlert;
use App\Models\MedicationReview;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Notifications\MedicationAlertNotification;
use App\Services\Medication\Alerts\MedicationAlertCatalogue;
use App\Services\Medication\Alerts\MedicationAlertRecipients;
use App\Services\Medication\Alerts\MedicationAlertSubject;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow(Carbon::parse('2026-10-05 20:30:00', 'Pacific/Auckland')->utc());
    $this->seed(RbacSeeder::class);
    $this->house = Site::factory()->create(['is_active' => true, 'name' => 'Kowhai House']);
    $this->staffAt = function (Site $site, string $role): User {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => today()->subYear(), 'end_date' => null,
        ]);

        return $user->fresh();
    };
    $this->rosteredOn = fn (User $worker, Client $client) => Shift::factory()->create([
        'client_id' => $client->id, 'site_id' => $client->site_id, 'user_id' => $worker->id,
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(10), 'status' => 'scheduled',
        'published_at' => now()->subDay(),
    ]);
});

afterEach(fn () => Carbon::setTestNow());

test('a person alert is not told to rostered staff who cannot open that person', function () {
    $aroha = Client::factory()->create(['site_id' => $this->house->id, 'first_name' => 'Aroha', 'last_name' => 'Ngata', 'status' => 'active']);
    $bella = Client::factory()->create(['site_id' => $this->house->id, 'first_name' => 'Bella', 'last_name' => 'Ngata', 'status' => 'active']);
    $relief = ($this->staffAt)($this->house, 'support_worker');
    ($this->rosteredOn)($relief, $bella);
    $assigned = ($this->staffAt)($this->house, 'support_worker');
    $aroha->supportWorkers()->attach($assigned->id);
    ($this->rosteredOn)($assigned, $aroha);
    $lead = ($this->staffAt)($this->house, 'team_lead');

    $result = app(MedicationAlertRecipients::class)->resolve(
        MedicationAlertCatalogue::OVERDUE,
        new MedicationAlertSubject(
            key: 'overdue:test', siteId: $this->house->id, title: 'Overdue dose',
            message: 'Aroha N. — Metformin, 8:00 pm dose — has no outcome.', shortMessage: 'A dose at Kowhai House has no outcome.',
            clientId: $aroha->id,
        ),
        now(),
    );

    $told = collect($result['told'])->map(fn ($c) => (int) $c['user']->id);
    // The seeded house lead reads the whole house (clients.viewAny), so the
    // person gate lets them through; the relief worker rostered for someone
    // else at the same house is not told.
    expect($told)->toContain($assigned->id)
        ->toContain($lead->id)
        ->not->toContain($relief->id);
    expect($result['not_told_person'] ?? null)->toContain($relief->id)->not->toContain($lead->id);
});

test('a stock alert link is the medicine’s stock page for stock staff and the person’s chart otherwise', function () {
    $aroha = Client::factory()->create(['site_id' => $this->house->id, 'status' => 'active']);
    $order = ClientMedication::factory()->create([
        'client_id' => $aroha->id, 'name' => 'Salbutamol', 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
    ]);
    $alert = MedicationAlert::query()->create([
        'type' => MedicationAlertCatalogue::OUT_OF_STOCK, 'dedupe_key' => 'out:test', 'open_key' => 'outOfStock:out:test',
        'site_id' => $this->house->id, 'client_id' => $aroha->id, 'controlled' => false,
        'title' => 'Out of stock', 'message' => 'Salbutamol for Aroha N. is out of stock.', 'short_message' => 'A medicine is out of stock.',
        'action_url' => '/emar/stock/packs?medication_id='.$order->id, 'severity' => 'critical',
        'subject' => ['client_id' => $aroha->id, 'client_medication_id' => $order->id], 'status' => 'open', 'raised_at' => now(),
    ]);
    $coordinator = ($this->staffAt)($this->house, 'coordinator');
    $assigned = ($this->staffAt)($this->house, 'support_worker');
    $aroha->supportWorkers()->attach($assigned->id);

    $forStock = (new MedicationAlertNotification($alert))->toArray($coordinator)['action_url'];
    $forWorker = (new MedicationAlertNotification($alert))->toArray($assigned)['action_url'];

    expect($forStock)->toBe('/emar/stock/packs?medication_id='.$order->id);
    expect($forWorker)->not->toStartWith('/emar/stock')->toContain('client_id='.$aroha->id);
    expect((new MedicationAlertNotification($alert))->toPush($assigned)['data']['url'])->not->toStartWith('/emar/stock');
});

test('the 3-day escalation of a medication task reaches only managers who can open it', function () {
    $otherHouse = Site::factory()->create(['is_active' => true]);
    $aroha = Client::factory()->create(['site_id' => $this->house->id, 'first_name' => 'Aroha', 'last_name' => 'Ngata', 'status' => 'active']);
    MedicationReview::query()->create([
        'client_id' => $aroha->id, 'review_type' => 'routine', 'status' => 'scheduled',
        'scheduled_date' => now()->subDays(5)->toDateString(),
    ]);
    $localCoordinator = ($this->staffAt)($this->house, 'coordinator');
    $remoteCoordinator = ($this->staffAt)($otherHouse, 'coordinator');
    $finance = ($this->staffAt)($this->house, 'finance');

    $this->artisan('tasks:escalate')->assertExitCode(0);

    $overdue = fn (User $user) => $user->notifications()->get()
        ->filter(fn ($n) => str_contains((string) ($n->data['title'] ?? ''), 'Aroha'))->count();
    expect($overdue($localCoordinator))->toBeGreaterThan(0);
    expect($overdue($remoteCoordinator))->toBe(0);
    expect($overdue($finance))->toBe(0);
});
