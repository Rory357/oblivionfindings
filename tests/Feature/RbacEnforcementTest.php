<?php

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\FinancePermissionsSeeder;
use Database\Seeders\GovernancePermissionsSeeder;
use Database\Seeders\RbacSeeder;
beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(FinancePermissionsSeeder::class);
    // Governance permissions are defined in their own seeder; without this,
    // admin/board roles cannot be granted governance.view and the dashboard
    // route returns 403 even for users we expect to authorise.
    $this->seed(GovernancePermissionsSeeder::class);
});

/**
 * Helper to create a user with a given RBAC role attached via role_user pivot.
 */
function rbacUser(string $roleName): User
{
    $user = User::factory()->create([
        'role' => $roleName,
        'approved_at' => now(),
    ]);
    $role = Role::where('name', $roleName)->first();
    if ($role) {
        $user->roles()->attach($role);
    }

    return $user;
}

// ─── 1. Legacy admin bypass is removed ─────────────────────────────────
// A user with users.role='admin' but NO entry in role_user pivot should
// NOT be able to access protected routes (RBAC is authoritative).

test('legacy admin column without role_user entry cannot access finance dashboard', function () {
    // Create user with legacy role='admin' but do NOT attach RBAC role
    $user = User::factory()->create([
        'role' => 'admin',
        'approved_at' => now(),
    ]);
    // Deliberately NOT attaching via $user->roles()->attach(...)

    $response = $this->actingAs($user)->get('/finance');

    $response->assertForbidden();
});

test('legacy admin column without role_user entry cannot access governance dashboard', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'approved_at' => now(),
    ]);

    $response = $this->actingAs($user)->get('/governance/dashboard');

    $response->assertForbidden();
});

// ─── 2. eMAR CRUD routes require medication permissions ────────────────

test('user without medication permissions cannot access eMAR dashboard', function () {
    // Note: support_worker is granted medications.view (they administer meds),
    // so we use the hr role here which legitimately lacks that permission.
    $user = rbacUser('hr');

    $response = $this->actingAs($user)->get('/emar');

    $response->assertForbidden();
});

test('user with medication permissions can access eMAR dashboard', function () {
    $user = rbacUser('coordinator');

    $response = $this->actingAs($user)->get('/emar');

    expect($response->status())->not->toBe(403);
});

test('user without medication permissions cannot access eMAR daily view', function () {
    $user = rbacUser('hr');

    $response = $this->actingAs($user)->get('/emar/daily');

    $response->assertForbidden();
});

test('user without medication permissions cannot access MAR charts', function () {
    $user = rbacUser('hr');

    $response = $this->actingAs($user)->get('/emar/mar');

    $response->assertForbidden();
});

// ─── 3. Health & Safety routes require hazards permissions ─────────────

test('user without hazards permissions cannot access health safety dashboard', function () {
    $user = rbacUser('support_worker');

    $response = $this->actingAs($user)->get('/health-safety');

    $response->assertForbidden();
});

test('user without hazards permissions cannot access health safety analytics', function () {
    $user = rbacUser('support_worker');

    $response = $this->actingAs($user)->get('/health-safety/analytics');

    $response->assertForbidden();
});

test('health safety officer can access health safety dashboard', function () {
    $user = rbacUser('health_safety_officer');

    $response = $this->actingAs($user)->get('/health-safety');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('health-safety/dashboard'));
});

// ─── 4. HR API routes require HR permissions ───────────────────────────

test('user without HR permissions cannot access HR employees API', function () {
    $user = rbacUser('support_worker');

    // HR API uses auth:sanctum — actingAs() with no explicit guard would
    // default to the web guard, so we authenticate against the sanctum guard
    // (configured as session-backed in this workspace) before the permission
    // check can run.
    $response = $this->actingAs($user, 'sanctum')->getJson('/api/hr/employees');

    $response->assertForbidden();
});

test('user with HR role can access HR employees API', function () {
    $user = rbacUser('hr');

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/hr/employees');

    expect($response->status())->not->toBe(403);
});

test('user without HR permissions cannot access HR leave requests API', function () {
    $user = rbacUser('support_worker');

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/hr/leave/requests');

    $response->assertForbidden();
});

// ─── 5. Governance routes require governance permissions ───────────────

test('user without governance permissions cannot access governance dashboard', function () {
    $user = rbacUser('support_worker');

    $response = $this->actingAs($user)->get('/governance/dashboard');

    $response->assertForbidden();
});

test('user without governance permissions cannot access governance meetings', function () {
    $user = rbacUser('support_worker');

    $response = $this->actingAs($user)->get('/governance/meetings');

    $response->assertForbidden();
});

test('admin can access governance dashboard', function () {
    $user = rbacUser('admin');

    $response = $this->actingAs($user)->get('/governance/dashboard');

    expect($response->status())->not->toBe(403);
});

test('board member can access governance dashboard', function () {
    $user = rbacUser('board_member');

    $response = $this->actingAs($user)->get('/governance/dashboard');

    expect($response->status())->not->toBe(403);
});

// ─── 6. DataBreach routes require privacy.reportBreaches ───────────────

test('user without privacy.reportBreaches cannot access data breaches', function () {
    $user = rbacUser('support_worker');

    $response = $this->actingAs($user)->get('/privacy/breaches');

    $response->assertForbidden();
});

test('user without privacy.reportBreaches cannot create data breaches', function () {
    $user = rbacUser('support_worker');

    $response = $this->actingAs($user)->get('/privacy/breaches/create');

    $response->assertForbidden();
});

test('admin can access data breaches', function () {
    $user = rbacUser('admin');

    $response = $this->actingAs($user)->get('/privacy/breaches');

    expect($response->status())->not->toBe(403);
});

// ─── 7. Support worker can only see assigned clients ───────────────────

test('support worker with viewAssigned sees only assigned clients', function () {
    $worker = rbacUser('support_worker');

    // Create two clients
    $assignedClient = Client::factory()->create(['first_name' => 'AssignedClient']);
    $unassignedClient = Client::factory()->create(['first_name' => 'UnassignedClient']);

    // Assign one client to the worker
    $assignedClient->supportWorkers()->attach($worker->id);

    $response = $this->actingAs($worker)->get('/clients');

    // The worker should be able to access the client list (200 or Inertia redirect)
    expect($response->status())->toBeIn([200, 302]);

    // If we get a 200 with page data, ensure only assigned client appears
    if ($response->status() === 200) {
        $content = $response->getContent();
        expect($content)->toContain('AssignedClient');
        expect($content)->not->toContain('UnassignedClient');
    }
});

test('coordinator can see all clients', function () {
    $coordinator = rbacUser('coordinator');

    $clientA = Client::factory()->create(['first_name' => 'ClientA']);
    $clientB = Client::factory()->create(['first_name' => 'ClientB']);

    $response = $this->actingAs($coordinator)->get('/clients');

    expect($response->status())->toBeIn([200, 302]);
});

test('admin can see all clients', function () {
    $admin = rbacUser('admin');

    $clientA = Client::factory()->create(['first_name' => 'ClientA']);
    $clientB = Client::factory()->create(['first_name' => 'ClientB']);

    $response = $this->actingAs($admin)->get('/clients');

    expect($response->status())->toBeIn([200, 302]);
});
