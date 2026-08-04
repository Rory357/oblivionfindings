<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\FinancePermissionsSeeder;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(FinancePermissionsSeeder::class);
});

function createUserWithRole(string $roleName): User
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

// ─── Finance role CAN access finance dashboard ─────────────────────────

test('finance role can access finance dashboard', function () {
    $user = createUserWithRole('finance');

    $response = $this->actingAs($user)->get('/finance');

    $response->assertOk();
});

// ─── Support worker role CANNOT access finance dashboard ───────────────

test('support worker cannot access finance dashboard', function () {
    $user = createUserWithRole('support_worker');

    $response = $this->actingAs($user)->get('/finance');

    $response->assertForbidden();
});

// ─── Finance role CAN approve payment runs ─────────────────────────────

test('finance role can access payment runs approve route', function () {
    $user = createUserWithRole('finance');

    // POST to approve endpoint - will fail with 404 (no such payment run) but NOT 403
    $response = $this->actingAs($user)->post('/finance/payment-runs/999/approve');

    // Should not be 403 (forbidden) - 404 is expected since payment run doesn't exist
    expect($response->status())->not->toBe(403);
});

// ─── Non-finance role CANNOT approve payment runs ──────────────────────

test('non-finance role cannot approve payment runs', function () {
    $user = createUserWithRole('support_worker');

    $response = $this->actingAs($user)->post('/finance/payment-runs/999/approve');

    $response->assertForbidden();
});

// ─── Finance role CAN mark invoices as paid ────────────────────────────

test('finance role can access mark invoice as paid route', function () {
    $user = createUserWithRole('finance');

    // POST to mark-paid endpoint - will fail with 404 (no such invoice) but NOT 403
    $response = $this->actingAs($user)->post('/finance/invoices/999/mark-paid');

    expect($response->status())->not->toBe(403);
});

// ─── Auditor role CAN view but CANNOT modify finance data ──────────────

test('auditor can view finance dashboard', function () {
    $user = createUserWithRole('auditor');

    $response = $this->actingAs($user)->get('/finance');

    $response->assertOk();
});

test('auditor can view chart of accounts', function () {
    $user = createUserWithRole('auditor');

    $response = $this->actingAs($user)->get('/finance/accounts');

    expect($response->status())->not->toBe(403);
});

test('auditor cannot create journal entries', function () {
    $user = createUserWithRole('auditor');

    $response = $this->actingAs($user)->post('/finance/journals', [
        'description' => 'Test journal',
    ]);

    $response->assertForbidden();
});

test('auditor cannot manage ledger entries', function () {
    $user = createUserWithRole('auditor');

    $response = $this->actingAs($user)->post('/finance/accounts', [
        'name' => 'Test Account',
    ]);

    $response->assertForbidden();
});

test('auditor cannot approve payment runs', function () {
    $user = createUserWithRole('auditor');

    $response = $this->actingAs($user)->post('/finance/payment-runs/999/approve');

    $response->assertForbidden();
});

test('auditor cannot mark invoices as paid', function () {
    $user = createUserWithRole('auditor');

    $response = $this->actingAs($user)->post('/finance/invoices/999/mark-paid');

    $response->assertForbidden();
});

// ─── Admin role CAN access all finance routes ──────────────────────────

test('admin can access finance dashboard', function () {
    $user = createUserWithRole('admin');

    $response = $this->actingAs($user)->get('/finance');

    $response->assertOk();
});

test('admin can access chart of accounts', function () {
    $user = createUserWithRole('admin');

    $response = $this->actingAs($user)->get('/finance/accounts');

    expect($response->status())->not->toBe(403);
});

test('admin can access payment runs', function () {
    $user = createUserWithRole('admin');

    $response = $this->actingAs($user)->get('/finance/payment-runs');

    expect($response->status())->not->toBe(403);
});

test('admin can access payment run approve route', function () {
    $user = createUserWithRole('admin');

    $response = $this->actingAs($user)->post('/finance/payment-runs/999/approve');

    // Should not be 403 - 404 is fine (no such payment run)
    expect($response->status())->not->toBe(403);
});

test('admin can access mark invoice as paid route', function () {
    $user = createUserWithRole('admin');

    $response = $this->actingAs($user)->post('/finance/invoices/999/mark-paid');

    // Should not be 403 - 404 is fine (no such invoice)
    expect($response->status())->not->toBe(403);
});

test('admin can access financial reports', function () {
    $user = createUserWithRole('admin');

    $response = $this->actingAs($user)->get('/finance/reports');

    expect($response->status())->not->toBe(403);
});
