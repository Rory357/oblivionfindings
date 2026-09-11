<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItTicketMergeService;
use App\Models\AuditLog;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    foreach (['agent' => 'hr', 'requester' => 'support_worker'] as $key => $role) {
        $this->{$key} = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $this->{$key}->roles()->syncWithoutDetaching([Role::where('name', $role)->firstOrFail()->id]);
        HrEmployeeProfile::factory()->create(['user_id' => $this->{$key}->id,
            'primary_site_id' => $this->site->id, 'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);
    }
    $this->ticketFields = ['site_id' => $this->site->id, 'requester_user_id' => $this->requester->id,
        'requested_for_user_id' => $this->requester->id, 'status' => 'open', 'work_type' => 'incident',
        'is_organisation_wide' => false, 'title' => 'Printer connection failure'];
    $this->source = ItTicket::factory()->create($this->ticketFields);
});

test('duplicate suggestions rank explainable title matches without writing or inspecting conversation content', function () {
    $matching = ItTicket::factory()->create([...$this->ticketFields, 'title' => 'PRINTER — Connection failure!']);
    $ordinary = ItTicket::factory()->create([...$this->ticketFields, 'title' => 'A different request']);
    $ordinary->comments()->create(['author_user_id' => $this->agent->id,
        'body' => $this->source->title, 'is_internal' => true]);
    $before = [ItTicketEvent::count(), AuditLog::count(), $this->source->fresh()->getAttributes()];

    $candidates = app(ItTicketMergeService::class)->candidates($this->source, $this->agent);
    expect(array_column($candidates, 'id'))->toBe([$matching->id, $ordinary->id])
        ->and($candidates[0]['duplicate_reasons'])->toBe(['same_title'])
        ->and($candidates[1]['duplicate_reasons'])->toBe([])
        ->and(array_keys($candidates[0]))->toBe(['id', 'reference', 'title', 'priority', 'status', 'lock_version', 'duplicate_reasons'])
        ->and([ItTicketEvent::count(), AuditLog::count(), $this->source->fresh()->getAttributes()])->toBe($before);
    $this->actingAs($this->agent)->getJson('/it/tickets/'.$this->source->id)
        ->assertOk()->assertJsonPath('mergeTargets.0.id', $matching->id)
        ->assertJsonPath('mergeTargets.0.duplicate_reasons', ['same_title']);
});

test('service suggestions require two distinct substantial title words and matching site and work type', function () {
    $service = ItService::factory()->create();
    $this->source->update(['it_service_id' => $service->id]);
    $matching = ItTicket::factory()->create([...$this->ticketFields, 'it_service_id' => $service->id,
        'title' => 'Intermittent printer connection problem']);
    foreach ([['title' => 'Printer printer printer'], ['it_service_id' => null], ['work_type' => 'service_request']] as $overrides) {
        ItTicket::factory()->create([...$this->ticketFields, 'it_service_id' => $service->id,
            'title' => 'Printer connection problem', ...$overrides]);
    }
    $candidates = app(ItTicketMergeService::class)->candidates($this->source, $this->agent);
    expect($candidates[0]['id'])->toBe($matching->id)
        ->and($candidates[0]['duplicate_reasons'])->toBe(['same_service_and_title_words'])
        ->and(collect($candidates)->skip(1)->pluck('duplicate_reasons')->all())->toBe([[], [], []]);
});

test('duplicate suggestions conceal other sites audiences closed and merged tickets from staff and requesters', function () {
    $visible = ItTicket::factory()->create($this->ticketFields);
    foreach ([['site_id' => Site::factory()->create()->id], ['requested_for_user_id' => $this->agent->id],
        ['status' => 'closed'], ['merged_into_ticket_id' => $visible->id]] as $overrides) {
        ItTicket::factory()->create([...$this->ticketFields, ...$overrides]);
    }
    expect(array_column(app(ItTicketMergeService::class)->candidates($this->source, $this->agent), 'id'))
        ->toBe([$visible->id]);
    $this->actingAs($this->requester)->getJson('/it/tickets/'.$this->source->id)
        ->assertOk()->assertJsonPath('mergeTargets', []);
    expect(fn () => app(ItTicketMergeService::class)->candidates($this->source, $this->requester))
        ->toThrow(ModelNotFoundException::class);
});

test('suggestion reads revalidate stale source and actor instead of trusting old page models', function () {
    ItTicket::factory()->create($this->ticketFields);
    ItTicket::query()->whereKey($this->source->id)->update(['status' => 'closed']);
    expect(app(ItTicketMergeService::class)->candidates($this->source, $this->agent))->toBe([]);
    User::query()->whereKey($this->agent->id)->update(['approved_at' => null]);
    expect(fn () => app(ItTicketMergeService::class)->candidates($this->source, $this->agent))
        ->toThrow(ModelNotFoundException::class);
});

test('requester intake suggestions disclose only accessible open records and do not persist anything', function () {
    $other = User::factory()->create();
    ItTicket::factory()->create([...$this->ticketFields, 'requester_user_id' => $other->id, 'requested_for_user_id' => $other->id]);
    ItTicket::factory()->create([...$this->ticketFields, 'status' => 'closed']);
    $before = [ItTicket::count(), ItTicketEvent::count(), AuditLog::count()];
    $query = ['actor_user_id' => $this->requester->id, 'query_uuid' => (string) Str::uuid(),
        'title' => 'Printer connection FAILURE!', 'site_id' => $this->site->id, 'work_type' => 'incident'];
    $this->actingAs($this->requester)->postJson('/it/tickets/duplicate-suggestions', $query)->assertOk()
        ->assertJsonPath('viewer_user_id', $this->requester->id)->assertJsonPath('query_uuid', $query['query_uuid'])
        ->assertJsonPath('source_id', null)->assertJsonCount(1, 'matches')
        ->assertJsonPath('matches.0.id', $this->source->id)->assertJsonPath('matches.0.reasons', ['same_title'])
        ->assertJsonPath('matches.0.href', '/it/tickets/'.$this->source->id);
    expect([ItTicket::count(), ItTicketEvent::count(), AuditLog::count()])->toBe($before);
});

test('intake read rejects unapproved scope mismatched actors invalid context and inactive services', function () {
    $query = ['actor_user_id' => $this->agent->id, 'query_uuid' => (string) Str::uuid(),
        'title' => 'Printer connection failure', 'site_id' => $this->site->id, 'work_type' => 'incident'];
    $this->actingAs($this->agent)->postJson('/it/tickets/duplicate-suggestions', [...$query, 'site_id' => Site::factory()->create()->id])->assertForbidden();
    $this->postJson('/it/tickets/duplicate-suggestions', [...$query, 'actor_user_id' => $this->requester->id])->assertForbidden();
    $this->postJson('/it/tickets/duplicate-suggestions', [...$query, 'title' => 'ab'])->assertUnprocessable();
    $this->postJson('/it/tickets/duplicate-suggestions', [...$query, 'it_service_id' => ItService::factory()->create(['is_active' => false])->id])->assertUnprocessable();
    $this->actingAs($this->requester)->postJson('/it/tickets/duplicate-suggestions', [...$query,
        'actor_user_id' => $this->requester->id, 'work_type' => 'security_request'])->assertForbidden();
});

test('triage suggestions use current canonical context exclude self and reauthorize the source', function () {
    $match = ItTicket::factory()->create($this->ticketFields);
    $query = ['actor_user_id' => $this->agent->id, 'query_uuid' => (string) Str::uuid(), 'title' => 'Forged query ignored'];
    $path = '/it/tickets/'.$this->source->id.'/duplicate-suggestions';
    $this->actingAs($this->agent)->postJson($path, $query)->assertOk()->assertJsonCount(1, 'matches')
        ->assertJsonPath('source_id', $this->source->id)->assertJsonPath('matches.0.id', $match->id);
    $this->source->update(['title' => 'Entirely changed context']);
    $this->postJson($path, $query)->assertOk()->assertJsonCount(0, 'matches');
    $this->source->update(['site_id' => Site::factory()->create()->id]);
    $this->postJson($path, $query)->assertNotFound();
    $this->actingAs($this->requester)->postJson($path, [...$query, 'actor_user_id' => $this->requester->id])->assertForbidden();
});

test('intake matches stay bounded and disclose no hidden total', function () {
    ItTicket::factory()->count(12)->create($this->ticketFields);
    $this->actingAs($this->agent)->postJson('/it/tickets/duplicate-suggestions', [
        'actor_user_id' => $this->agent->id, 'query_uuid' => (string) Str::uuid(),
        'title' => $this->source->title, 'site_id' => $this->site->id, 'work_type' => 'incident',
    ])->assertOk()->assertJsonCount(10, 'matches')->assertJsonMissingPath('total');
});
