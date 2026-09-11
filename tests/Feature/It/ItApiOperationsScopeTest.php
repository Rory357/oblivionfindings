<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItApiOperationsPresenter;
use App\Domain\It\Services\ItServiceIdentityCredentialService;
use App\Models\ItApiRequest;
use App\Models\ItServiceIdentity;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Schema;

function apiOperationsManager(): User
{
    $manager = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $role = Role::query()->create([
        'name' => 'api-operations-'.str()->uuid(),
        'label' => 'API operations scope test authority',
        'level' => 50,
        'type' => 'custom',
    ]);
    $role->permissions()->attach(Permission::query()
        ->whereIn('key', ['it.view', 'it.manage'])
        ->pluck('id'));
    $manager->roles()->attach($role);

    return $manager;
}

/** @param  list<Site>  $sites */
function apiOperationsAssignSites(User $user, array $sites): void
{
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'employee_number' => 'API-OPERATIONS-'.$user->id,
        'work_email' => $user->email,
        'primary_site_id' => $sites[0]->id,
        'secondary_site_ids' => collect($sites)->skip(1)->pluck('id')->all(),
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
}

function apiOperationsIdentity(User $manager, Site $site, string $name): ItServiceIdentity
{
    return app(ItServiceIdentityCredentialService::class)->create($manager, [
        'name' => $name,
        'description' => 'Scoped Operations audit coverage.',
        'actor_user_id' => $manager->id,
        'abilities' => ['work:read'],
        'allowed_work_types' => ['incident'],
        'allowed_site_ids' => [$site->id],
        'allowed_fields' => ['create' => [], 'read' => [], 'update' => []],
        'require_signature' => false,
        'rate_limit_per_minute' => 60,
    ])['identity'];
}

function apiOperationsFailedRequest(ItServiceIdentity $identity, string $key, int $status): void
{
    ItApiRequest::query()->create([
        'service_identity_id' => $identity->id,
        'method' => 'PATCH',
        'path' => '/api/v1/it/work-items/1',
        'idempotency_key' => $key,
        'request_hash' => hash('sha256', $key),
        'response_status' => $status,
        'response_body' => ['error' => 'safe test failure'],
        'completed_at' => now(),
    ]);
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

test('operations API errors are restricted to identities the current manager can manage', function () {
    $visibleSite = Site::factory()->create();
    $formerSite = Site::factory()->create();
    $hiddenSite = Site::factory()->create();

    $manager = apiOperationsManager();
    apiOperationsAssignSites($manager, [$visibleSite, $formerSite]);
    $visible = apiOperationsIdentity($manager, $visibleSite, 'Visible Operations identity');
    $noLongerApproved = apiOperationsIdentity($manager, $formerSite, 'Former-site Operations identity');

    $otherManager = apiOperationsManager();
    apiOperationsAssignSites($otherManager, [$hiddenSite]);
    $hidden = apiOperationsIdentity($otherManager, $hiddenSite, 'Hidden Operations identity');

    HrEmployeeProfile::query()->where('user_id', $manager->id)->sole()->update([
        'secondary_site_ids' => [],
        'updated_by' => $manager->id,
    ]);

    apiOperationsFailedRequest($visible, 'visible-error-one', 422);
    apiOperationsFailedRequest($visible, 'visible-error-two', 500);
    apiOperationsFailedRequest($noLongerApproved, 'former-site-error', 500);
    apiOperationsFailedRequest($hidden, 'hidden-identity-error', 403);

    $this->actingAs($manager)
        ->get('/it/setup')
        ->assertInertia(fn ($page) => $page
            ->where('apiIdentities.0.id', $visible->id)
            ->has('apiIdentities', 1)
            ->where('operationsAudit.api.identities', 1)
            ->where('operationsAudit.api_health.total', 2)
            ->has('operationsAudit.api_health.rows', 2)
            ->where('operationsAudit.api_health.rows.0.identity_name', 'Visible Operations identity')
            ->where('operationsAudit.api.request_errors', 2));

    $unrelatedManager = apiOperationsManager();
    apiOperationsAssignSites($unrelatedManager, [$visibleSite]);

    $this->actingAs($unrelatedManager)
        ->get('/it/setup')
        ->assertInertia(fn ($page) => $page
            ->has('apiIdentities', 0)
            ->where('operationsAudit.api.identities', 0)
            ->where('operationsAudit.api_health.total', 0)
            ->has('operationsAudit.api_health.rows', 0)
            ->where('operationsAudit.api_health.identities_url', null)
            ->where('operationsAudit.api.request_errors', 0));
});

test('API diagnostics keep unknown legacy outcomes distinct and project only safe recovery metadata', function () {
    $manager = apiOperationsManager();
    $site = Site::factory()->create();
    apiOperationsAssignSites($manager, [$site]);
    $identity = apiOperationsIdentity($manager, $site, 'Diagnostics connector');
    $private = 'PRIVATE_BEARER_SECRET_BODY';
    $common = ['service_identity_id' => $identity->id, 'method' => 'POST', 'path' => '/api/v1/it/work-items',
        'request_hash' => hash('sha256', $private), 'response_body' => ['message' => $private]];
    $success = ItApiRequest::query()->create(array_merge($common, ['idempotency_key' => $private.'1',
        'response_status' => 201, 'execution_state' => 'committed', 'attempt_count' => 2,
        'last_attempt_at' => now()->subHour(), 'completed_at' => now()->subHour()]));
    $rolledBack = ItApiRequest::query()->create(array_merge($common, ['idempotency_key' => $private.'2',
        'response_status' => 500, 'execution_state' => 'rolled_back', 'attempt_count' => 3, 'completed_at' => now()]));
    $legacy = ItApiRequest::query()->create(array_merge($common, ['idempotency_key' => $private.'3',
        'response_status' => 500, 'completed_at' => now()]));
    $validation = ItApiRequest::query()->create(array_merge($common, ['idempotency_key' => $private.'4',
        'response_status' => 422, 'execution_state' => 'rolled_back', 'attempt_count' => 1, 'completed_at' => now()]));
    $pending = ItApiRequest::query()->create(array_merge($common, ['idempotency_key' => $private.'5',
        'path' => '/private/'.$private, 'created_at' => now()->subDays(2)]));
    $read = ItApiRequest::query()->create(array_merge($common, ['method' => 'GET',
        'path' => '/api/v1/it/work-items/987654321', 'response_status' => 200,
        'execution_state' => 'committed', 'attempt_count' => 1, 'completed_at' => now()]));

    $response = $this->actingAs($manager)->get('/it/setup?tab=operations')->assertOk();
    $response->assertDontSee($private)->assertDontSee('987654321');
    $response->assertInertia(function ($page) use ($read, $pending, $validation, $legacy, $rolledBack, $success) {
        $page->where('operationsAudit.api_health.total', 6)->where('operationsAudit.api_health.failures', 3)
            ->where('operationsAudit.api_health.pending', 1)
            ->where('operationsAudit.api_health.oldest_pending_at', $pending->created_at->toIso8601String())
            ->where('operationsAudit.api_health.last_success_at', $read->completed_at->toIso8601String())
            ->where('operationsAudit.api_health.rows', function ($rows) use ($read, $pending, $validation, $legacy, $rolledBack, $success) {
                $rows = collect($rows)->keyBy('id');
                expect($rows[$read->id]['operation'])->toBe('read')->and($rows[$read->id]['recovery'])->toBe('read_again')
                    ->and($rows[$pending->id]['operation'])->toBe('unknown')->and($rows[$pending->id]['outcome'])->toBe('pending')
                    ->and($rows[$validation->id]['recovery'])->toBe('correct_request')
                    ->and($rows[$legacy->id]['outcome'])->toBe('unknown')->and($rows[$legacy->id]['recovery'])->toBe('reconcile')
                    ->and($rows[$legacy->id]['attempt_count'])->toBeNull()
                    ->and($rows[$rolledBack->id]['recovery'])->toBe('retry_same_request')->and($rows[$rolledBack->id]['attempt_count'])->toBe(3)
                    ->and($rows[$success->id]['recovery'])->toBe('already_applied');

                return true;
            });
    });
    $identity->forceFill(['revoked_at' => now()])->save();
    $this->get('/it/setup')->assertInertia(fn ($page) => $page->where('operationsAudit.api_health.rows.0.recovery', 'review_identity'));
});

test('API diagnostic aggregates include older pages and absent outcome columns remain unavailable', function () {
    $manager = apiOperationsManager();
    $site = Site::factory()->create();
    apiOperationsAssignSites($manager, [$site]);
    $identity = apiOperationsIdentity($manager, $site, 'Paged diagnostics');
    foreach (range(1, 27) as $index) {
        apiOperationsFailedRequest($identity, 'page-key-'.$index, 500);
    }
    $this->actingAs($manager)->get('/it/setup?tab=operations')->assertInertia(fn ($page) => $page
        ->where('operationsAudit.api_health.total', 27)->has('operationsAudit.api_health.rows', 25)
        ->where('operationsAudit.api_health.next_url', '/it/setup?tab=operations&api_request_page=2#it-api-request-history'));
    $this->get('/it/setup?tab=operations&api_request_page=999')->assertInertia(fn ($page) => $page
        ->where('operationsAudit.api_health.total', 27)->where('operationsAudit.api_health.page', 2)
        ->has('operationsAudit.api_health.rows', 2)->where('operationsAudit.api_health.next_url', null)
        ->where('operationsAudit.api_health.previous_url', '/it/setup?tab=operations&api_request_page=1#it-api-request-history'));
    $schema = Schema::getFacadeRoot();
    $schemaProxy = Mockery::mock($schema);
    $schemaProxy->shouldReceive('hasColumns')->once()->with('it_api_requests',
        ['execution_state', 'attempt_count', 'last_attempt_at'])->andReturn(false);
    Schema::swap($schemaProxy);
    try {
        $health = app(ItApiOperationsPresenter::class)->operations($manager, collect([$identity]));
    } finally {
        Schema::swap($schema);
    }
    expect($health['available'])->toBeFalse()->and($health['total'])->toBeNull()->and($health['rows'])->toBe([]);
});

function apiSearchReceipt(ItServiceIdentity $identity, array $attributes = []): ItApiRequest
{
    return ItApiRequest::query()->create(array_merge([
        'service_identity_id' => $identity->id, 'method' => 'POST', 'path' => '/api/v1/it/work-items',
        'idempotency_key' => 'search-'.str()->uuid(), 'request_hash' => hash('sha256', 'synthetic'),
        'response_status' => 201, 'execution_state' => 'committed', 'attempt_count' => 1,
        'completed_at' => now(),
    ], $attributes));
}

test('API search pages the matching history without hiding global failures or pending evidence', function () {
    $manager = apiOperationsManager();
    $site = Site::factory()->create();
    apiOperationsAssignSites($manager, [$site]);
    $matching = apiOperationsIdentity($manager, $site, 'Repair connector');
    $other = apiOperationsIdentity($manager, $site, 'Other connector');
    foreach (range(1, 27) as $index) {
        apiSearchReceipt($matching, ['execution_state' => 'rolled_back', 'response_status' => 422]);
    }
    $success = apiSearchReceipt($other);
    $pending = apiSearchReceipt($other, ['completed_at' => null, 'response_status' => 500, 'created_at' => now()->subDays(2)]);
    $query = 'tab=operations&automation_from=2026-09-11&automation_to=2026-09-11&q=Repair%20connector';
    $next = '/it/setup?tab=operations&automation_from=2026-09-11&automation_to=2026-09-11&q=Repair+connector&api_request_page=2#it-api-request-history';
    $this->actingAs($manager)->get('/it/setup?'.$query)->assertInertia(fn ($page) => $page
        ->where('operationsAudit.api_health.search_query', 'Repair connector')
        ->where('operationsAudit.api_health.total', 29)->where('operationsAudit.api_health.history_total', 27)
        ->where('operationsAudit.api_health.failures', 28)->where('operationsAudit.api_health.pending', 1)
        ->where('operationsAudit.api_health.oldest_pending_at', $pending->created_at->toIso8601String())
        ->where('operationsAudit.api_health.last_success_at', $success->completed_at->toIso8601String())
        ->has('operationsAudit.api_health.rows', 25)->where('operationsAudit.api_health.next_url', $next)
        ->where('operationsAudit.api_health.links.2.url', $next));
    $this->get($next)->assertInertia(fn ($page) => $page
        ->where('operationsAudit.api_health.history_total', 27)->where('operationsAudit.api_health.page', 2)
        ->has('operationsAudit.api_health.rows', 2));
    $this->get('/it/setup?tab=operations&q=no-match&api_request_page=999')->assertInertia(fn ($page) => $page
        ->where('operationsAudit.api_health.total', 29)->where('operationsAudit.api_health.failures', 28)
        ->where('operationsAudit.api_health.pending', 1)->where('operationsAudit.api_health.history_total', 0)
        ->where('operationsAudit.api_health.page', 1)->has('operationsAudit.api_health.rows', 0));
    $this->get('/it/setup?tab=operations')->assertInertia(fn ($page) => $page
        ->where('operationsAudit.api_health.history_total', 29)->where('operationsAudit.api_health.search_query', ''));
});

test('API public operation classification remains strict for legacy paths and matches search results', function () {
    $manager = apiOperationsManager();
    $site = Site::factory()->create();
    apiOperationsAssignSites($manager, [$site]);
    $identity = apiOperationsIdentity($manager, $site, 'Operation fixture');
    $cases = [
        ['POST', '/api/v1/it/work-items', 'create'], ['POST', 'api/v1/it/work-items', 'create'],
        ['POST', '///api/v1/it/work-items', 'create'], ['PATCH', '/api/v1/it/work-items/0001', 'update'],
        ['GET', '/api/v1/it/work-items/123', 'read'], ['POST', '/api/v1/it/work-items/1/relationships', 'link'],
        ['POST', '/api/v1/it/work-items/1/comments', 'comment'], ['POST', '/api/v1/it/work-items/1/transitions', 'transition'],
        ['post', '/api/v1/it/work-items', 'unknown'], ['GET', '/API/v1/it/work-items/1', 'unknown'],
        ['GET', "/api/v1/it/work-items/1\n", 'unknown'], ['GET', "/api/v1/it/work-items/1\r\n", 'unknown'],
        ['GET', "/api/v1/it/work-items/1\u{2028}", 'unknown'], ['POST', "/api/v1/it/work-items/1/comments\n", 'unknown'],
        ['GET', '/api/v1/it/work-items/1?secret=private', 'unknown'], ['PATCH', '/api/v1/it/work-items/-1', 'unknown'],
        ['GET', '/api/v1/it/work-items/1/', 'unknown'], ['GET', '/api/v1/it/work-items/١', 'unknown'],
        ['POST', '/api/v1/it/work-items/1/COMMENTS', 'unknown'], ['POST ', '/api/v1/it/work-items', 'unknown'],
        ['POST', "/api/v1/it/work-items\n", 'unknown'],
    ];
    $expected = [];
    foreach ($cases as [$method, $path, $operation]) {
        $receipt = apiSearchReceipt($identity, compact('method', 'path'));
        $expected[$receipt->id] = $operation;
    }
    $presenter = app(ItApiOperationsPresenter::class);
    $all = $presenter->operations($manager, collect([$identity]));
    expect(collect($all['rows'])->pluck('operation', 'id')->sortKeys()->all())->toBe(collect($expected)->sortKeys()->all());
    foreach (['create' => 'Create ticket', 'update' => 'Update ticket', 'read' => 'Read ticket', 'link' => 'Link tickets',
        'comment' => 'Add reply', 'transition' => 'Change status', 'unknown' => 'Unclassified request'] as $operation => $search) {
        $result = $presenter->operations($manager, collect([$identity]), 1, ['q' => $search]);
        expect(collect($result['rows'])->pluck('id')->sort()->values()->all())
            ->toBe(collect($expected)->filter(fn ($value) => $value === $operation)->keys()->sort()->values()->all());
    }
});

test('API outcome and error-category searches use recorded evidence rather than assuming completion', function () {
    $manager = apiOperationsManager();
    $site = Site::factory()->create();
    apiOperationsAssignSites($manager, [$site]);
    $identity = apiOperationsIdentity($manager, $site, 'Outcome fixture');
    $pending = apiSearchReceipt($identity, ['completed_at' => null, 'response_status' => 500]);
    $unknown = apiSearchReceipt($identity, ['execution_state' => 'unknown', 'response_status' => 500]);
    $success = apiSearchReceipt($identity);
    $categories = [401 => 'authentication', 403 => 'permission', 404 => 'unavailable record',
        409 => 'conflict', 422 => 'validation', 429 => 'rate limit', 400 => 'request rejected'];
    $rejected = [];
    foreach ($categories as $status => $label) {
        $rejected[$status] = apiSearchReceipt($identity, ['execution_state' => 'rolled_back', 'response_status' => $status]);
    }
    $presenter = app(ItApiOperationsPresenter::class);
    $search = fn ($q) => collect($presenter->operations($manager, collect([$identity]), 1, ['q' => $q])['rows'])->pluck('id')->sort()->values()->all();
    expect($search('No completed outcome'))->toBe([$pending->id])
        ->and($search('Outcome unverified'))->toBe([$unknown->id])
        ->and($search('Not applied'))->toBe(collect($rejected)->pluck('id')->sort()->values()->all())
        ->and($search('server failure'))->toBe([$pending->id, $unknown->id])
        ->and($search('500'))->toBe([$pending->id, $unknown->id])
        ->and($search('No recorded failure'))->toBe([$success->id]);
    foreach ($categories as $status => $label) {
        expect($search($label))->toBe([$rejected[$status]->id]);
    }
});

test('API search cannot infer hidden identities, record paths, response bodies or retry credentials', function () {
    $manager = apiOperationsManager();
    $site = Site::factory()->create();
    apiOperationsAssignSites($manager, [$site]);
    $identity = apiOperationsIdentity($manager, $site, 'Visible connector');
    $private = 'private-search-diagnostic-marker';
    $receipt = apiSearchReceipt($identity, ['path' => '/api/v1/it/work-items/987654321', 'method' => 'GET',
        'idempotency_key' => $private, 'request_hash' => hash('sha256', $private), 'response_body' => ['error' => $private]]);
    $other = apiOperationsManager();
    apiOperationsAssignSites($other, [$site]);
    $hidden = apiOperationsIdentity($other, $site, 'Hidden connector');
    apiSearchReceipt($hidden);
    $presenter = app(ItApiOperationsPresenter::class);
    foreach ([$private, '987654321', hash('sha256', $private), '%', "' OR 1=1 --", 'Hidden connector'] as $query) {
        $result = $presenter->operations($manager, collect([$identity, $hidden]), 1, ['q' => $query]);
        expect($result['total'])->toBe(1)->and($result['history_total'])->toBe(0)->and($result['rows'])->toBe([]);
    }
    $result = $presenter->operations($manager, collect([$identity, $hidden]), 1, ['q' => '  Receipt '.$receipt->id.'  ']);
    expect($result['search_query'])->toBe('Receipt '.$receipt->id)->and($result['history_total'])->toBe(1)
        ->and(json_encode($result['rows']))->not->toContain($private, '987654321', 'idempotency_key', 'request_hash', 'response_body');
    $identity->forceFill(['revoked_at' => now()])->save();
    $result = $presenter->operations($manager, collect([$identity]), 1, ['q' => 'Visible connector']);
    expect($result['rows'][0]['recovery'])->toBe('review_identity');
    HrEmployeeProfile::query()->where('user_id', $manager->id)->sole()->update(['is_active' => false]);
    $denied = $presenter->operations($manager, collect([$identity]), 1, ['q' => 'Visible connector']);
    expect($denied['total'])->toBe(0)->and($denied['history_total'])->toBe(0)->and($denied['rows'])->toBe([]);
});
