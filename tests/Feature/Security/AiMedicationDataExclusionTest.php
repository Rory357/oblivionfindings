<?php

/*
 * EA-013 / EA-014 (decision D5, 9 Oct): AI search and AI summaries are kept
 * for later but carry no medication data while llm.include_medication_data
 * is off (the default). When it is switched on, the per-person rule still
 * applies and controlled medicines are never included.
 */

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\GenerateSummaryJob;
use App\Models\Client;
use App\Models\ClientMedicalProfile;
use App\Models\ClientMedication;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Summary;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\Rag\ClientRagIndexer;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->house = Site::factory()->create(['name' => 'Kowhai House']);
    $this->client = Client::factory()->create(['site_id' => $this->house->id, 'first_name' => 'Aroha', 'last_name' => 'Ngata']);
    ClientMedicalProfile::query()->create([
        'client_id' => $this->client->id,
        'medical_history' => 'Asthma',
        'disabilities' => ['epilepsy', 'limited_mobility'],
        'allergies' => ['Peanuts', 'Penicillin'],
    ]);
    foreach ([['Paracetamol', false], ['Oxycodone', true]] as [$name, $controlled]) {
        ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => $name,
            'dosage' => '5mg',
            'controlled_drug' => $controlled,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'version' => 1,
        ]);
    }
    $this->ordinaryEvent = TimelineEvent::query()->create([
        'type' => 'progress_note',
        'client_id' => $this->client->id,
        'site_id' => $this->house->id,
        'occurred_at' => now()->subDay(),
        'subject' => 'Went swimming at the pools',
    ]);
    $this->medicationEvent = TimelineEvent::query()->create([
        'type' => 'medication_given',
        'client_id' => $this->client->id,
        'site_id' => $this->house->id,
        'occurred_at' => now()->subDay(),
        'subject' => 'Given: Oxycodone 5mg',
        'meta' => ['medication_name' => 'Oxycodone'],
    ]);

    $this->staffAt = function (array $permissions = [], ?string $roleName = null): User {
        $user = User::factory()->create(['role' => $roleName ?? 'manager', 'approved_at' => now()]);
        if ($roleName !== null) {
            $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());
        } else {
            $role = Role::query()->create(['name' => 'ai_med_'.$user->id, 'label' => 'AI '.$user->id, 'level' => 20, 'type' => 'custom']);
            $role->permissions()->sync(Permission::query()->whereIn('key', $permissions)->pluck('id'));
            $user->roles()->attach($role);
        }
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->house->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user;
    };
});

test('the AI search snapshot leaves out every medication record by default and reads array-cast profile lists', function () {
    expect(config('llm.include_medication_data'))->toBeFalse();
    $coordinator = ($this->staffAt)([], 'coordinator');

    $markdown = app(ClientRagIndexer::class)->buildMarkdown($this->client, 120, $coordinator);

    expect($markdown)
        ->toContain('Went swimming at the pools')
        ->toContain('epilepsy')
        ->not->toContain('Oxycodone')
        ->not->toContain('Paracetamol')
        ->not->toContain('Peanuts')
        ->not->toContain('medication_given');
});

test('with medication data switched on the snapshot keeps the person rule and never includes controlled medicines', function () {
    config(['llm.include_medication_data' => true]);
    $coordinator = ($this->staffAt)([], 'coordinator');
    $outsider = ($this->staffAt)(['clients.viewAny', 'rag.ask.any']);

    $forReader = app(ClientRagIndexer::class)->buildMarkdown($this->client, 120, $coordinator);
    $forOutsider = app(ClientRagIndexer::class)->buildMarkdown($this->client, 120, $outsider);

    expect($forReader)->toContain('Paracetamol')->not->toContain('Oxycodone');
    expect($forOutsider)->not->toContain('Paracetamol')->not->toContain('Oxycodone')->toContain('Went swimming at the pools');
});

test('timeline summaries never store medication events while the switch is off', function () {
    $coordinator = ($this->staffAt)([], 'coordinator');

    (new GenerateSummaryJob(
        'client',
        $this->client->id,
        now()->subDays(3)->toIso8601String(),
        now()->toIso8601String(),
        $coordinator->id,
    ))->handle();

    $summary = Summary::query()->sole();
    expect($summary->sources['timeline_event_ids'])->toContain($this->ordinaryEvent->id)
        ->not->toContain($this->medicationEvent->id);
    expect($summary->summary_text)->not->toContain('medication');
});

test('a stored summary made from medication events is not shown while the switch is off', function () {
    $coordinator = ($this->staffAt)([], 'coordinator');
    $from = now()->startOfDay();
    $to = (clone $from)->addDays(7)->endOfDay();
    Summary::query()->create([
        'scope_type' => 'client',
        'scope_id' => $this->client->id,
        'period_start' => $from,
        'period_end' => $to,
        'model' => 'local-deterministic',
        'prompt_version' => 'v1',
        'summary_text' => 'Given: Oxycodone 5mg twice.',
        'sources' => ['timeline_event_ids' => [$this->medicationEvent->id]],
        'generated_at' => now(),
    ]);

    $this->actingAs($coordinator)
        ->get('/summaries/clients/'.$this->client->id.'?from='.urlencode($from->toIso8601String()).'&to='.urlencode($to->toIso8601String()))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('summary', null));
});

test('asking AI search uploads a medication-free snapshot and removes the earlier snapshots from the vector store', function () {
    config(['llm.openai.api_key' => 'test-key']);
    $this->client->forceFill(['openai_vector_store_id' => 'vs_existing'])->save();
    $asker = ($this->staffAt)(['clients.viewAny', 'rag.ask.any', 'medications.view']);
    $uploaded = null;
    Http::fake(function (HttpRequest $request) use (&$uploaded) {
        $url = $request->url();
        if (str_ends_with($url, '/v1/files') && $request->method() === 'POST') {
            $uploaded = $request->body();

            return Http::response(['id' => 'file_new']);
        }
        if (str_contains($url, '/v1/vector_stores/vs_existing/files') && $request->method() === 'GET') {
            return Http::response(['data' => [['id' => 'file_old']], 'has_more' => false]);
        }
        if ($request->method() === 'DELETE') {
            return Http::response(['deleted' => true]);
        }
        if (str_contains($url, '/v1/vector_stores/vs_existing/files')) {
            return Http::response(['id' => 'file_new']);
        }

        return Http::response(['output' => [], 'output_text' => 'Not in context.']);
    });

    $this->actingAs($asker)
        ->post('/clients/'.$this->client->id.'/rag/ask', ['question' => 'What medicines were given?'])
        ->assertRedirect();

    expect((string) $uploaded)->not->toContain('Oxycodone')->not->toContain('Paracetamol')->toContain('Went swimming');
    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'DELETE'
        && str_contains($request->url(), 'file_old'));
});
