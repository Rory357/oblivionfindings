<?php

use App\Exceptions\TaskProviderNavigationException;
use App\Models\Role;
use App\Models\User;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;

function navigationTestProvider(
    string $source,
    array $items = [],
    ?Throwable $failure = null,
): TaskProvider {
    return new class($source, $items, $failure) implements TaskProvider
    {
        public int $calls = 0;

        public function __construct(
            private readonly string $source,
            private readonly array $items,
            private readonly ?Throwable $failure,
        ) {}

        public function sourceKey(): string
        {
            return $this->source;
        }

        public function label(): string
        {
            return ucfirst($this->source);
        }

        public function canView(User $user): bool
        {
            return true;
        }

        public function authorizedTasks(User $user, array $filters = []): array
        {
            $this->calls++;

            if ($this->failure) {
                throw $this->failure;
            }

            return $this->items;
        }
    };
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    Cache::clear();
});

it('reports one provider failure while retaining healthy navigation counts and a strict task feed', function () {
    Exceptions::fake();
    $user = User::factory()->create(['approved_at' => now()]);
    $healthyItem = new TaskItem(
        id: 'healthy-1',
        source: 'healthy',
        sourceLabel: 'Healthy tasks',
        ref: null,
        title: 'Visible healthy task',
        status: 'open',
        bucket: TaskItem::BUCKET_OPEN,
        severity: 'low',
        assignee: ['id' => $user->id, 'name' => $user->name],
    );
    $failure = new RuntimeException('Simulated safety provider failure.');
    $aggregator = new TaskAggregator([
        navigationTestProvider('healthy', [$healthyItem]),
        navigationTestProvider('safety_checks', failure: $failure),
    ]);

    expect($aggregator->navigationBadgeFor($user))->toBe([
        'view' => true,
        'badge' => 1,
        'degraded' => true,
    ]);

    Exceptions::assertReported(function (TaskProviderNavigationException $exception) use ($failure, $user) {
        return $exception->sourceKey === 'safety_checks'
            && $exception->userId === $user->id
            && $exception->getPrevious() === $failure
            && $exception->context()['surface'] === 'shared_navigation_badge';
    });

    expect(fn () => $aggregator->itemsFor($user))
        ->toThrow(RuntimeException::class, 'Simulated safety provider failure.');
});

it('renders administrator support worker and clinical lead landing pages when a navigation provider fails', function () {
    Exceptions::fake();
    $failingProvider = navigationTestProvider(
        'landing_failure',
        failure: new RuntimeException('Simulated landing badge failure.'),
    );
    $this->app->instance(TaskAggregator::class, new TaskAggregator([$failingProvider]));

    $landingPages = [
        'admin' => '/dashboard',
        'support_worker' => '/my-day',
        'clinical_lead' => '/my-day',
    ];

    foreach ($landingPages as $roleName => $url) {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());
        ensureCanonicalHrStaffProfile($user);

        $assertLandingPage = fn () => $this->actingAs($user)
            ->get($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.can.tasks.view', true)
                ->where('auth.can.tasks.badge', 0)
                ->where('auth.can.tasks.badgeDegraded', true)
            );

        $assertLandingPage();

        if ($roleName === 'admin') {
            $assertLandingPage();
            expect($failingProvider->calls)->toBe(2);
        }
    }

    expect($failingProvider->calls)->toBeGreaterThanOrEqual(4);
    Exceptions::assertReported(fn (TaskProviderNavigationException $exception) => $exception->sourceKey === 'landing_failure');
});
