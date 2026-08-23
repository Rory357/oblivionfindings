<?php

use App\Domain\Finance\Http\Middleware\RejectUnsupportedConsolidation;
use App\Domain\Finance\Models\FinAccountMapping;
use App\Domain\Finance\Models\FinConsolidationEntity;
use App\Domain\Finance\Models\FinConsolidationGroup;
use App\Domain\Finance\Models\FinConsolidationRun;
use App\Domain\Finance\Models\FinIntercompanyTransaction;
use App\Domain\Finance\Models\FinJournal;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('keeps every legacy route name behind the consolidation quarantine', function () {
    foreach ([
        'finance.consolidation.index',
        'finance.consolidation.store',
        'finance.consolidation.show',
        'finance.consolidation.add-entity',
        'finance.consolidation.remove-entity',
        'finance.consolidation.runs',
        'finance.consolidation.run',
        'finance.consolidation.show-run',
        'finance.consolidation.mapping',
        'finance.consolidation.mapping.update',
        'finance.intercompany.index',
        'finance.intercompany.store',
        'finance.intercompany.post',
    ] as $routeName) {
        $route = Route::getRoutes()->getByName($routeName);

        expect($route)->not->toBeNull();

        $middleware = $route->gatherMiddleware();

        expect($middleware)->toContain(RejectUnsupportedConsolidation::class)
            ->and($middleware)->toContain('permission:finance.admin')
            ->and(array_search(RejectUnsupportedConsolidation::class, $middleware, true))
            ->toBeLessThan(array_search('permission:finance.admin', $middleware, true));
    }
});

it('conceals the quarantined surface before evaluating finance permissions', function () {
    $user = User::factory()->create([
        'organization_id' => 1,
        'approved_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('finance.consolidation.index'))
        ->assertNotFound();
});

it('conceals every legacy entry point without mutating consolidation state', function () {
    $user = User::factory()->create([
        'organization_id' => 1,
        'approved_at' => now(),
    ]);
    $permission = Permission::query()->firstOrCreate(
        ['key' => 'finance.admin'],
        ['description' => 'Administer finance settings'],
    );
    $user->permissionOverrides()->sync([
        $permission->id => ['allowed' => true],
    ]);

    $group = FinConsolidationGroup::query()->create([
        'name' => 'Legacy consolidation group',
        'parent_organization_id' => 1,
        'base_currency_code' => 'NZD',
        'is_active' => true,
        'created_by' => $user->id,
    ]);
    $fromEntity = FinConsolidationEntity::query()->create([
        'group_id' => $group->id,
        'organization_id' => 101,
        'entity_name' => 'Legacy source entity',
        'ownership_percentage' => '100.00',
        'consolidation_method' => 'full',
        'currency_code' => 'NZD',
        'is_active' => true,
    ]);
    $toEntity = FinConsolidationEntity::query()->create([
        'group_id' => $group->id,
        'organization_id' => 202,
        'entity_name' => 'Legacy destination entity',
        'ownership_percentage' => '100.00',
        'consolidation_method' => 'full',
        'currency_code' => 'NZD',
        'is_active' => true,
    ]);
    $run = FinConsolidationRun::query()->create([
        'group_id' => $group->id,
        'period_from' => '2026-07-01',
        'period_to' => '2026-07-31',
        'status' => 'draft',
        'created_by' => $user->id,
    ]);
    $transaction = FinIntercompanyTransaction::query()->create([
        'group_id' => $group->id,
        'from_entity_id' => $fromEntity->id,
        'to_entity_id' => $toEntity->id,
        'transaction_date' => '2026-07-15',
        'description' => 'Legacy intercompany charge',
        'amount' => '100.00',
        'status' => 'pending',
        'created_by' => $user->id,
    ]);

    $before = [
        'groups' => FinConsolidationGroup::query()->count(),
        'entities' => FinConsolidationEntity::query()->count(),
        'runs' => FinConsolidationRun::query()->count(),
        'transactions' => FinIntercompanyTransaction::query()->count(),
        'mappings' => FinAccountMapping::query()->count(),
        'journals' => FinJournal::query()->count(),
    ];

    $requests = [
        fn () => $this->get(route('finance.consolidation.index')),
        fn () => $this->post(route('finance.consolidation.store'), [
            'name' => 'Unsupported group',
            'base_currency_code' => 'NZD',
        ]),
        fn () => $this->get(route('finance.consolidation.show', $group)),
        fn () => $this->post(route('finance.consolidation.add-entity', $group), [
            'organization_id' => 999999,
            'entity_name' => 'Arbitrary entity',
            'ownership_percentage' => '100.00',
            'consolidation_method' => 'full',
            'currency_code' => 'NZD',
        ]),
        fn () => $this->delete(route('finance.consolidation.remove-entity', [$group, $fromEntity])),
        fn () => $this->get(route('finance.consolidation.runs', $group)),
        fn () => $this->post(route('finance.consolidation.run', $group), [
            'period_from' => '2026-07-01',
            'period_to' => '2026-07-31',
        ]),
        fn () => $this->get(route('finance.consolidation.show-run', [$group, $run])),
        fn () => $this->get(route('finance.consolidation.mapping', $group)),
        fn () => $this->put(route('finance.consolidation.mapping.update', $group), [
            'mappings' => [],
        ]),
        fn () => $this->get(route('finance.intercompany.index', $group)),
        fn () => $this->post(route('finance.intercompany.store', $group), [
            'from_entity_id' => $fromEntity->id,
            'to_entity_id' => $toEntity->id,
            'transaction_date' => '2026-07-20',
            'description' => 'Unsupported charge',
            'amount' => '25.00',
        ]),
        fn () => $this->post(route('finance.intercompany.post', [$group, $transaction])),
        // A retry is the same concealed no-op as the first run attempt.
        fn () => $this->post(route('finance.consolidation.run', $group), [
            'period_from' => '2026-07-01',
            'period_to' => '2026-07-31',
        ]),
    ];

    $this->actingAs($user);
    foreach ($requests as $request) {
        $request()->assertNotFound();
    }

    expect(FinConsolidationGroup::query()->count())->toBe($before['groups'])
        ->and(FinConsolidationEntity::query()->count())->toBe($before['entities'])
        ->and(FinConsolidationRun::query()->count())->toBe($before['runs'])
        ->and(FinIntercompanyTransaction::query()->count())->toBe($before['transactions'])
        ->and(FinAccountMapping::query()->count())->toBe($before['mappings'])
        ->and(FinJournal::query()->count())->toBe($before['journals'])
        ->and($run->refresh()->status)->toBe('draft')
        ->and($transaction->refresh()->status)->toBe('pending');
});
