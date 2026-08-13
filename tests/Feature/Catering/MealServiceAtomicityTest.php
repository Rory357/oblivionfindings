<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\MealProduct;
use App\Models\MealRecipe;
use App\Models\MealRecipeIngredient;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteMealInventoryItem;
use App\Models\SiteMealInventoryMovement;
use App\Models\SiteMealPlanEntry;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\Catering\InventoryMovementRecorder;
use App\Services\Catering\MealServiceCommand;
use Database\Seeders\CateringPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(CateringPermissionsSeeder::class);

    $this->stockSite = Site::factory()->create([
        'name' => 'Atomic Catering Site',
        'type' => 'house',
        'is_active' => true,
    ]);
    $this->otherStockSite = Site::factory()->create([
        'name' => 'Other Catering Site',
        'type' => 'house',
        'is_active' => true,
    ]);
    $this->stockWorker = caterStockWorkerAt($this->stockSite);
});

function caterStockWorkerAt(Site $site): User
{
    $user = User::factory()->create([
        'name' => 'Atomic Catering Worker',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $user->roles()->sync([
        Role::query()->where('name', 'support_worker')->firstOrFail()->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    return $user->fresh();
}

/**
 * @param  array<int, array{product: MealProduct, quantity: float, unit?: string}>  $ingredients
 * @return array{MealRecipe, array<int, MealRecipeIngredient>}
 */
function caterStockRecipe(array $ingredients, string $name = 'Atomic meal recipe'): array
{
    $recipe = MealRecipe::query()->create([
        'name' => $name,
        'scope' => 'shared',
        'is_active' => true,
        'serves_default' => 4,
    ]);
    $rows = [];
    foreach ($ingredients as $index => $ingredient) {
        $rows[] = MealRecipeIngredient::query()->create([
            'recipe_id' => $recipe->id,
            'product_id' => $ingredient['product']->id,
            'quantity' => $ingredient['quantity'],
            'unit' => $ingredient['unit'] ?? $ingredient['product']->default_unit,
            'sort_order' => $index,
        ]);
    }

    return [$recipe, $rows];
}

function caterStockEntry(Site $site, MealRecipe $recipe): SiteMealPlanEntry
{
    return SiteMealPlanEntry::query()->create([
        'site_id' => $site->id,
        'plan_date' => today()->toDateString(),
        'meal_slot' => 'lunch',
        'source_type' => 'recipe',
        'recipe_id' => $recipe->id,
        'servings' => 4,
        'client_ids' => [],
    ]);
}

function caterStockProduct(string $name): MealProduct
{
    return MealProduct::query()->create([
        'name' => $name,
        'default_unit' => 'each',
        'is_active' => true,
    ]);
}

function caterStockSeedQuantity(Site $site, MealProduct $product, float $quantity): void
{
    app(InventoryMovementRecorder::class)->record(
        site: $site,
        productId: $product->id,
        delta: $quantity,
        unit: $product->default_unit,
        reason: 'delivery',
        note: 'Atomic meal test opening stock',
    );
}

test('serve retries are idempotent and unserve reverses the exact immutable occurrence', function (): void {
    Notification::fake();
    $timelineBefore = TimelineEvent::query()->count();
    $databaseNotificationsBefore = DB::table('notifications')->count();
    $firstProduct = caterStockProduct('Atomic rice');
    $secondProduct = caterStockProduct('Atomic beans');
    [$recipe, $ingredients] = caterStockRecipe([
        ['product' => $firstProduct, 'quantity' => 1.0],
        ['product' => $firstProduct, 'quantity' => 0.5],
        ['product' => $secondProduct, 'quantity' => 2.0],
    ]);
    $entry = caterStockEntry($this->stockSite, $recipe);
    caterStockSeedQuantity($this->stockSite, $firstProduct, 20);
    caterStockSeedQuantity($this->stockSite, $secondProduct, 20);

    $this->actingAs($this->stockWorker)
        ->postJson("/sites/{$this->stockSite->id}/meal-plan/{$entry->id}/serve")
        ->assertOk()
        ->assertJsonPath('status', 'Served · 2 ingredients deducted from stock');

    $entry->refresh();
    expect($entry->served_at)->not->toBeNull()
        ->and($entry->version)->toBe(2)
        ->and($entry->meal_service_sequence)->toBe(1)
        ->and($entry->meal_service_movement_count)->toBe(2);

    $serveMovements = SiteMealInventoryMovement::query()
        ->where('meal_service_action', SiteMealInventoryMovement::MEAL_SERVICE_ACTION_SERVE)
        ->orderBy('product_id')
        ->get();
    expect($serveMovements)->toHaveCount(2)
        ->and($serveMovements->pluck('meal_service_key')->unique()->values()->all())
        ->toBe(["meal-plan:{$entry->id}:service:1"])
        ->and((float) $serveMovements->firstWhere('product_id', $firstProduct->id)->delta)->toBe(-1.5)
        ->and((float) $serveMovements->firstWhere('product_id', $secondProduct->id)->delta)->toBe(-2.0)
        ->and($serveMovements->firstWhere('product_id', $firstProduct->id)->meal_recipe_ingredient_ids)
        ->toBe([$ingredients[0]->id, $ingredients[1]->id]);

    $this->actingAs($this->stockWorker)
        ->postJson("/sites/{$this->stockSite->id}/meal-plan/{$entry->id}/serve")
        ->assertOk()
        ->assertJsonPath('status', 'Already served');
    expect(SiteMealInventoryMovement::query()->whereNotNull('meal_service_key')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', MealServiceCommand::AUDIT_SERVED)->count())->toBe(1)
        ->and($entry->fresh()->version)->toBe(2);

    // The mutable recipe is no longer an inverse source. Reversal must use the
    // exact persisted deltas even when the live quantities have since changed.
    $ingredients[0]->update(['quantity' => 100]);
    $ingredients[1]->update(['quantity' => 100]);
    $ingredients[2]->update(['quantity' => 100]);

    $this->actingAs($this->stockWorker)
        ->postJson("/sites/{$this->stockSite->id}/meal-plan/{$entry->id}/unserve")
        ->assertOk()
        ->assertJsonPath('status', 'Un-served · stock restored');

    $reversals = SiteMealInventoryMovement::query()
        ->where('meal_service_action', SiteMealInventoryMovement::MEAL_SERVICE_ACTION_UNSERVE)
        ->orderBy('product_id')
        ->get();
    expect($reversals)->toHaveCount(2)
        ->and((float) $reversals->firstWhere('product_id', $firstProduct->id)->delta)->toBe(1.5)
        ->and((float) $reversals->firstWhere('product_id', $secondProduct->id)->delta)->toBe(2.0)
        ->and($reversals->pluck('reversal_of_id')->sort()->values()->all())
        ->toBe($serveMovements->pluck('id')->sort()->values()->all())
        ->and($entry->fresh()->version)->toBe(3);

    $this->actingAs($this->stockWorker)
        ->postJson("/sites/{$this->stockSite->id}/meal-plan/{$entry->id}/unserve")
        ->assertOk()
        ->assertJsonPath('status', 'Not served');
    expect(SiteMealInventoryMovement::query()->whereNotNull('meal_service_key')->count())->toBe(4)
        ->and(AuditLog::query()->where('action', MealServiceCommand::AUDIT_UNSERVED)->count())->toBe(1)
        ->and($entry->fresh()->version)->toBe(3);

    // A legitimate later service is a new server-authoritative occurrence,
    // retaining one product/action effect per occurrence key.
    $this->actingAs($this->stockWorker)
        ->postJson("/sites/{$this->stockSite->id}/meal-plan/{$entry->id}/serve")
        ->assertOk()
        ->assertJsonPath('status', 'Served · 2 ingredients deducted from stock');
    expect($entry->fresh()->meal_service_sequence)->toBe(2)
        ->and($entry->fresh()->version)->toBe(4)
        ->and(SiteMealInventoryMovement::query()
            ->where('meal_service_action', SiteMealInventoryMovement::MEAL_SERVICE_ACTION_SERVE)
            ->where('meal_service_key', "meal-plan:{$entry->id}:service:2")
            ->count())->toBe(2)
        ->and(AuditLog::query()->where('action', MealServiceCommand::AUDIT_SERVED)->count())->toBe(2);

    foreach ([$firstProduct, $secondProduct] as $product) {
        $item = SiteMealInventoryItem::query()
            ->where('site_id', $this->stockSite->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
        $journalQuantity = (float) SiteMealInventoryMovement::query()
            ->where('site_id', $this->stockSite->id)
            ->where('product_id', $product->id)
            ->sum('delta');
        expect((float) $item->current_qty)->toBe($journalQuantity);
    }

    expect(DB::table('site_meal_inventory_movements')
        ->selectRaw('meal_service_key, product_id, meal_service_action, COUNT(*) AS aggregate_count')
        ->whereNotNull('meal_service_key')
        ->groupBy('meal_service_key', 'product_id', 'meal_service_action')
        ->havingRaw('COUNT(*) > 1')
        ->count())->toBe(0)
        ->and(TimelineEvent::query()->count())->toBe($timelineBefore)
        ->and(DB::table('notifications')->count())->toBe($databaseNotificationsBefore);
    Notification::assertNothingSent();

    expect(fn () => $serveMovements->first()->update(['note' => 'tampered']))
        ->toThrow(LogicException::class, 'Inventory movements are immutable.');
    expect(fn () => SiteMealInventoryMovement::query()->whereKey($serveMovements->first()->id)->delete())
        ->toThrow(LogicException::class, 'Inventory movements are immutable.');
    expect(fn () => DB::table('meal_products')->where('id', $firstProduct->id)->delete())
        ->toThrow(QueryException::class)
        ->and(SiteMealInventoryMovement::query()->whereIn('id', $serveMovements->modelKeys())->count())->toBe(2);
});

test('wrong Site and mismatched nested scope deny before meal or stock side effects', function (): void {
    $product = caterStockProduct('Scoped atomic product');
    [$sharedRecipe] = caterStockRecipe([
        ['product' => $product, 'quantity' => 1],
    ], 'Scoped shared recipe');
    $otherEntry = caterStockEntry($this->otherStockSite, $sharedRecipe);
    caterStockSeedQuantity($this->stockSite, $product, 10);

    $movementCount = SiteMealInventoryMovement::query()->count();
    $this->actingAs($this->stockWorker)
        ->postJson("/sites/{$this->stockSite->id}/meal-plan/{$otherEntry->id}/serve")
        ->assertNotFound();
    $this->actingAs($this->stockWorker)
        ->postJson("/sites/{$this->otherStockSite->id}/meal-plan/{$otherEntry->id}/serve")
        ->assertForbidden();
    expect(fn () => app(MealServiceCommand::class)->serve(
        $this->otherStockSite->id,
        $otherEntry->id,
        $this->stockWorker->id,
    ))->toThrow(AuthorizationException::class);

    $otherRecipe = MealRecipe::query()->create([
        'name' => 'Wrong house recipe occurrence',
        'scope' => 'house',
        'site_id' => $this->otherStockSite->id,
        'is_active' => true,
        'serves_default' => 4,
    ]);
    MealRecipeIngredient::query()->create([
        'recipe_id' => $otherRecipe->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit' => 'each',
        'sort_order' => 0,
    ]);
    $forgedEntry = caterStockEntry($this->stockSite, $otherRecipe);

    $this->actingAs($this->stockWorker)
        ->postJson("/sites/{$this->stockSite->id}/meal-plan/{$forgedEntry->id}/serve")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recipe_id');

    $otherClient = Client::factory()->create(['site_id' => $this->otherStockSite->id]);
    $forgedClientEntry = caterStockEntry($this->stockSite, $sharedRecipe);
    $forgedClientEntry->forceFill(['client_ids' => [$otherClient->id]])->save();
    $this->actingAs($this->stockWorker)
        ->postJson("/sites/{$this->stockSite->id}/meal-plan/{$forgedClientEntry->id}/serve")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('client_ids.0');

    expect($otherEntry->fresh()->served_at)->toBeNull()
        ->and($forgedEntry->fresh()->served_at)->toBeNull()
        ->and($forgedClientEntry->fresh()->served_at)->toBeNull()
        ->and($otherEntry->fresh()->version)->toBe(1)
        ->and($forgedEntry->fresh()->version)->toBe(1)
        ->and($forgedClientEntry->fresh()->version)->toBe(1)
        ->and(SiteMealInventoryMovement::query()->count())->toBe($movementCount)
        ->and(AuditLog::query()->where('action', MealServiceCommand::AUDIT_SERVED)->count())->toBe(0);
});

test('a failure after an earlier product mutation rolls back state journal quantity and audit', function (): void {
    $firstProduct = caterStockProduct('Rollback product A');
    $secondProduct = caterStockProduct('Rollback product B');
    [$recipe, $ingredients] = caterStockRecipe([
        ['product' => $firstProduct, 'quantity' => 1],
        ['product' => $secondProduct, 'quantity' => 1],
    ], 'Rollback recipe');
    $entry = caterStockEntry($this->stockSite, $recipe);
    caterStockSeedQuantity($this->stockSite, $firstProduct, 10);
    caterStockSeedQuantity($this->stockSite, $secondProduct, 10);
    $serviceKey = "meal-plan:{$entry->id}:service:1";

    // The second product's pre-existing identity injects a unique-key failure
    // after the first product has already been mutated inside the transaction.
    SiteMealInventoryMovement::query()->create([
        'site_id' => $this->stockSite->id,
        'product_id' => $secondProduct->id,
        'delta' => 0,
        'unit' => 'each',
        'reason' => 'plan_consumption',
        'reference_type' => SiteMealPlanEntry::class,
        'reference_id' => $entry->id,
        'meal_service_key' => $serviceKey,
        'meal_service_action' => SiteMealInventoryMovement::MEAL_SERVICE_ACTION_SERVE,
        'meal_recipe_id' => $recipe->id,
        'meal_recipe_ingredient_ids' => [$ingredients[1]->id],
        'performed_by' => $this->stockWorker->id,
        'performed_at' => now(),
    ]);
    $beforeMovementIds = SiteMealInventoryMovement::query()->pluck('id')->all();
    $beforeAuditCount = AuditLog::query()->count();

    expect(fn () => app(MealServiceCommand::class)->serve(
        $this->stockSite->id,
        $entry->id,
        $this->stockWorker->id,
    ))->toThrow(QueryException::class);

    expect($entry->fresh()->served_at)->toBeNull()
        ->and($entry->fresh()->version)->toBe(1)
        ->and($entry->fresh()->meal_service_sequence)->toBe(0)
        ->and(SiteMealInventoryMovement::query()->pluck('id')->all())->toBe($beforeMovementIds)
        ->and((float) SiteMealInventoryItem::query()->where('product_id', $firstProduct->id)->value('current_qty'))->toBe(10.0)
        ->and((float) SiteMealInventoryItem::query()->where('product_id', $secondProduct->id)->value('current_qty'))->toBe(10.0)
        ->and(AuditLog::query()->count())->toBe($beforeAuditCount)
        ->and(AuditLog::query()->where('action', MealServiceCommand::AUDIT_SERVED)->count())->toBe(0);
});

test('unserve refuses unlinked legacy stock and later dependent stocktakes without partial effects', function (): void {
    $product = caterStockProduct('Unsafe reversal product');
    [$recipe] = caterStockRecipe([
        ['product' => $product, 'quantity' => 1],
    ], 'Unsafe reversal recipe');
    $entry = caterStockEntry($this->stockSite, $recipe);
    caterStockSeedQuantity($this->stockSite, $product, 10);

    $this->actingAs($this->stockWorker)
        ->postJson("/sites/{$this->stockSite->id}/meal-plan/{$entry->id}/serve")
        ->assertOk();
    app(InventoryMovementRecorder::class)->stocktake(
        site: $this->stockSite,
        productId: $product->id,
        newQty: 8,
        unit: 'each',
        performedBy: $this->stockWorker->id,
        note: 'Dependent physical count',
    );
    $quantityBefore = (float) SiteMealInventoryItem::query()->where('product_id', $product->id)->value('current_qty');
    $movementCountBefore = SiteMealInventoryMovement::query()->count();

    $this->actingAs($this->stockWorker)
        ->postJson("/sites/{$this->stockSite->id}/meal-plan/{$entry->id}/unserve")
        ->assertConflict();
    expect($entry->fresh()->served_at)->not->toBeNull()
        ->and($entry->fresh()->version)->toBe(2)
        ->and((float) SiteMealInventoryItem::query()->where('product_id', $product->id)->value('current_qty'))->toBe($quantityBefore)
        ->and(SiteMealInventoryMovement::query()->count())->toBe($movementCountBefore)
        ->and(AuditLog::query()->where('action', MealServiceCommand::AUDIT_UNSERVED)->count())->toBe(0);

    $legacyEntry = caterStockEntry($this->stockSite, $recipe);
    $legacyEntry->forceFill([
        'served_at' => now(),
        'served_by' => $this->stockWorker->id,
        'meal_service_sequence' => 0,
    ])->save();
    app(InventoryMovementRecorder::class)->record(
        site: $this->stockSite,
        productId: $product->id,
        delta: -1,
        unit: 'each',
        reason: 'plan_consumption',
        referenceType: SiteMealPlanEntry::class,
        referenceId: $legacyEntry->id,
        performedBy: $this->stockWorker->id,
    );
    $legacyQuantity = (float) SiteMealInventoryItem::query()->where('product_id', $product->id)->value('current_qty');
    $legacyMovementCount = SiteMealInventoryMovement::query()->count();

    $this->actingAs($this->stockWorker)
        ->postJson("/sites/{$this->stockSite->id}/meal-plan/{$legacyEntry->id}/unserve")
        ->assertConflict();
    expect($legacyEntry->fresh()->served_at)->not->toBeNull()
        ->and($legacyEntry->fresh()->version)->toBe(1)
        ->and((float) SiteMealInventoryItem::query()->where('product_id', $product->id)->value('current_qty'))->toBe($legacyQuantity)
        ->and(SiteMealInventoryMovement::query()->count())->toBe($legacyMovementCount);
});

test('parallel serve unserve and opposing actions serialize without duplicate effects', function (): void {
    Notification::fake();
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');
    $timelineBefore = TimelineEvent::query()->count();
    $databaseNotificationsBefore = DB::table('notifications')->count();

    $product = caterStockProduct('Concurrent service product');
    [$recipe] = caterStockRecipe([
        ['product' => $product, 'quantity' => 1],
    ], 'Concurrent service recipe');
    $entry = caterStockEntry($this->stockSite, $recipe);
    caterStockSeedQuantity($this->stockSite, $product, 10);
    $database = $connection->getDatabaseName();

    // Make fixtures visible to independent MySQL workers. The replacement
    // transaction in finally keeps RefreshDatabase teardown balanced.
    $connection->commit();

    try {
        $serveStatuses = caterStockConcurrentRound(
            $connection,
            $database,
            $this->stockSite->id,
            $entry->id,
            $this->stockWorker->id,
            ['serve', 'serve'],
        );
        expect($serveStatuses)->toBe(['already_served', 'served'])
            ->and(SiteMealInventoryMovement::query()
                ->where('meal_service_action', SiteMealInventoryMovement::MEAL_SERVICE_ACTION_SERVE)
                ->count())->toBe(1)
            ->and(AuditLog::query()->where('action', MealServiceCommand::AUDIT_SERVED)->count())->toBe(1);

        $unserveStatuses = caterStockConcurrentRound(
            $connection,
            $database,
            $this->stockSite->id,
            $entry->id,
            $this->stockWorker->id,
            ['unserve', 'unserve'],
        );
        expect($unserveStatuses)->toBe(['not_served', 'unserved'])
            ->and(SiteMealInventoryMovement::query()
                ->where('meal_service_action', SiteMealInventoryMovement::MEAL_SERVICE_ACTION_UNSERVE)
                ->count())->toBe(1)
            ->and(AuditLog::query()->where('action', MealServiceCommand::AUDIT_UNSERVED)->count())->toBe(1);

        $raceStatuses = caterStockConcurrentRound(
            $connection,
            $database,
            $this->stockSite->id,
            $entry->id,
            $this->stockWorker->id,
            ['serve', 'unserve'],
        );
        expect(in_array($raceStatuses, [
            ['not_served', 'served'],
            ['served', 'unserved'],
        ], true))->toBeTrue();

        $item = SiteMealInventoryItem::query()
            ->where('site_id', $this->stockSite->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
        $journalQuantity = (float) SiteMealInventoryMovement::query()
            ->where('site_id', $this->stockSite->id)
            ->where('product_id', $product->id)
            ->sum('delta');
        $serveOccurrenceCount = SiteMealInventoryMovement::query()
            ->where('meal_service_action', SiteMealInventoryMovement::MEAL_SERVICE_ACTION_SERVE)
            ->distinct()
            ->count('meal_service_key');
        $unserveOccurrenceCount = SiteMealInventoryMovement::query()
            ->where('meal_service_action', SiteMealInventoryMovement::MEAL_SERVICE_ACTION_UNSERVE)
            ->distinct()
            ->count('meal_service_key');
        expect((float) $item->current_qty)->toBe($journalQuantity)
            ->and(DB::table('site_meal_inventory_movements')
                ->selectRaw('meal_service_key, product_id, meal_service_action, COUNT(*) AS aggregate_count')
                ->whereNotNull('meal_service_key')
                ->groupBy('meal_service_key', 'product_id', 'meal_service_action')
                ->havingRaw('COUNT(*) > 1')
                ->count())->toBe(0)
            ->and(AuditLog::query()->where('action', MealServiceCommand::AUDIT_SERVED)->count())->toBe($serveOccurrenceCount)
            ->and(AuditLog::query()->where('action', MealServiceCommand::AUDIT_UNSERVED)->count())->toBe($unserveOccurrenceCount)
            ->and($entry->fresh()->version)->toBe(1 + $serveOccurrenceCount + $unserveOccurrenceCount)
            ->and(TimelineEvent::query()->count())->toBe($timelineBefore)
            ->and(DB::table('notifications')->count())->toBe($databaseNotificationsBefore);
        Notification::assertNothingSent();
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        DB::table('site_meal_inventory_movements')->whereNotNull('reversal_of_id')->delete();
        DB::table('site_meal_inventory_movements')->where('site_id', $this->stockSite->id)->delete();
        DB::table('site_meal_inventory_items')->where('site_id', $this->stockSite->id)->delete();
        DB::table('meal_recipe_ingredients')->where('recipe_id', $recipe->id)->delete();
        DB::table('site_meal_plan_entries')->where('id', $entry->id)->delete();
        DB::table('meal_recipes')->where('id', $recipe->id)->delete();
        DB::table('meal_products')->where('id', $product->id)->delete();
        DB::table('audit_logs')->delete();
        DB::table('permission_user')->where('user_id', $this->stockWorker->id)->delete();
        DB::table('role_user')->where('user_id', $this->stockWorker->id)->delete();
        DB::table('hr_employee_profiles')->where('user_id', $this->stockWorker->id)->delete();
        DB::table('users')->where('id', $this->stockWorker->id)->delete();
        DB::table('sites')->whereIn('id', [$this->stockSite->id, $this->otherStockSite->id])->delete();

        $connection->beginTransaction();
    }
});

/**
 * @param  array<int, 'serve'|'unserve'>  $actions
 * @return array<int, string>
 */
function caterStockConcurrentRound(
    ConnectionInterface $connection,
    string $database,
    int $siteId,
    int $entryId,
    int $actorId,
    array $actions,
): array {
    $token = (string) Str::uuid();
    $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."cater-stock-release-{$token}";
    $readyPaths = [];
    $attemptPaths = [];
    $processes = [];

    $connection->beginTransaction();
    Site::query()->whereKey($siteId)->lockForUpdate()->firstOrFail();

    try {
        foreach ($actions as $index => $action) {
            $readyPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."cater-stock-ready-{$index}-{$token}";
            $attemptPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."cater-stock-attempt-{$index}-{$token}";
            $processes[] = caterStockStartWorker(
                $database,
                $siteId,
                $entryId,
                $actorId,
                $action,
                $readyPaths[$index],
                $attemptPaths[$index],
                $releasePath,
            );
        }

        caterStockWaitForFiles($readyPaths, 'Concurrent meal-service workers did not become ready.');
        touch($releasePath);
        caterStockWaitForFiles($attemptPaths, 'Concurrent meal-service workers did not reach the command.');
        usleep(250_000);
        foreach ($processes as $process) {
            if (! $process->isRunning()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'A meal-service worker exited before lock release.');
            }
        }

        $connection->commit();
        $statuses = [];
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'A meal-service concurrency worker failed.');
            }
            $statuses[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR)['status'];
        }
        sort($statuses);

        return $statuses;
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach ([...$readyPaths, ...$attemptPaths, $releasePath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

function caterStockStartWorker(
    string $database,
    int $siteId,
    int $entryId,
    int $actorId,
    string $action,
    string $readyPath,
    string $attemptPath,
    string $releasePath,
): Process {
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
file_put_contents($argv[6], (string) Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
$deadline = microtime(true) + 15;
while (! is_file($argv[8])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the meal-service release barrier.');
    }
    usleep(10_000);
}
file_put_contents($argv[7], 'attempting');
$action = (string) $argv[5];
if (! in_array($action, ['serve', 'unserve'], true)) {
    throw new RuntimeException('Unsupported meal-service worker action.');
}
$result = $app->make(App\Services\Catering\MealServiceCommand::class)->{$action}(
    (int) $argv[2],
    (int) $argv[3],
    (int) $argv[4],
);
echo json_encode(['status' => $result->status, 'movement_count' => $result->movementCount], JSON_THROW_ON_ERROR);
PHP;

    $process = new Process([
        PHP_BINARY,
        '-r',
        $worker,
        base_path(),
        (string) $siteId,
        (string) $entryId,
        (string) $actorId,
        $action,
        $readyPath,
        $attemptPath,
        $releasePath,
    ], base_path(), [
        'APP_ENV' => 'testing',
        'DB_DATABASE' => $database,
    ]);
    $process->setTimeout(30);
    $process->start();

    return $process;
}

/** @param  array<int, string>  $paths */
function caterStockWaitForFiles(array $paths, string $message): void
{
    $deadline = microtime(true) + 15;
    while (collect($paths)->contains(fn (string $path) => ! is_file($path))) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException($message);
        }
        usleep(10_000);
    }
}
