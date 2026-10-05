<?php

namespace Tests\Unit\Visual;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Faker\Factory;
use Faker\Generator;
use Illuminate\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Visual\FrozenVisualRuntime;

require_once dirname(__DIR__, 2).'/visual/FrozenVisualRuntime.php';

final class FrozenVisualRuntimeTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        mt_srand();
        parent::tearDown();
    }

    public function test_freezes_mutable_and_immutable_clocks_to_one_absolute_instant(): void
    {
        FrozenVisualRuntime::configure('testing', 'mysql', FrozenVisualRuntime::DATABASE, '2026-10-05T13:00:00+13:00', 20261005);

        self::assertSame('2026-10-05T00:00:00+00:00', Carbon::now('UTC')->toIso8601String());
        self::assertSame('2026-10-05T00:00:00+00:00', \Illuminate\Support\Carbon::now('UTC')->toIso8601String());
        self::assertSame('2026-10-05T00:00:00+00:00', CarbonImmutable::now('UTC')->toIso8601String());
        self::assertSame('2026-10-05 13:00', Carbon::now('Pacific/Auckland')->format('Y-m-d H:i'));
        self::assertSame('2026-10-05 13:00', CarbonImmutable::now('Pacific/Auckland')->format('Y-m-d H:i'));
    }

    public function test_replays_random_and_faker_fixture_values(): void
    {
        $values = static function (): array {
            FrozenVisualRuntime::configure('local', 'mysql', FrozenVisualRuntime::DATABASE, '2026-10-05T00:00:00Z', 20261005);
            $app = new Container;
            $app->singleton(Generator::class, static fn (): Generator => Factory::create('en_NZ'));
            FrozenVisualRuntime::seedFaker($app, 20261005);
            $faker = $app->make(Generator::class);

            return [rand(1, 999999), mt_rand(1, 999999), $faker->name(), $faker->streetAddress(), $faker->dateTimeBetween('-1 year', 'now')->format('Y-m-d')];
        };

        self::assertSame($values(), $values());
    }

    public function test_faker_relative_bounds_use_the_frozen_clock_and_absolute_bounds_keep_their_meaning(): void
    {
        FrozenVisualRuntime::configure('testing', 'mysql', FrozenVisualRuntime::DATABASE, '2026-10-05T00:00:00Z', 20261005);
        $app = new Container;
        $app->singleton(Generator::class, static fn (): Generator => Factory::create('en_NZ'));
        FrozenVisualRuntime::seedFaker($app, 20261005);
        $faker = $app->make(Generator::class);

        self::assertSame(1791158400, $faker->dateTimeBetween('now', 'now')->getTimestamp());
        self::assertSame(1791158400, $faker->dateTimeInInterval('now', '+0 days')->getTimestamp());
        self::assertSame('2026-01-01', $faker->dateTimeBetween('first day of january this year', 'first day of january this year')->format('Y-m-d'));
        $absolute = new \DateTimeImmutable('2026-06-01T13:00:00+13:00');
        self::assertSame($absolute->getTimestamp(), $faker->dateTimeBetween($absolute, $absolute)->getTimestamp());
        $faker->seed(20261005);
        $expectedDate = $faker->date('Y-m-d', Carbon::now('UTC'));
        $faker->seed(20261005);
        self::assertSame($expectedDate, $faker->date('Y-m-d', 'now'));

        FrozenVisualRuntime::configure('testing', 'mysql', FrozenVisualRuntime::DATABASE, '2027-03-14T00:00:00Z', 20261005);
        self::assertSame('2027-03-14T00:00:00+00:00', $faker->dateTimeBetween('now', 'now', 'UTC')->format('c'));
    }

    public function test_environment_database_url_cannot_redirect_the_disposable_database(): void
    {
        $before = $_ENV;
        $_ENV = array_replace($_ENV, [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => FrozenVisualRuntime::DATABASE,
            'DB_URL' => 'mysql://example.invalid/another_database',
            'VISUAL_FROZEN_NOW' => '2026-10-05T00:00:00Z',
            'VISUAL_RANDOM_SEED' => '20261005',
        ]);

        try {
            FrozenVisualRuntime::configureFromEnvironment();
            self::fail('A database URL override was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('database URL override', $exception->getMessage());
            self::assertFalse(Carbon::hasTestNow());
            self::assertFalse(CarbonImmutable::hasTestNow());
        } finally {
            $_ENV = $before;
        }
    }

    #[DataProvider('invalidContexts')]
    public function test_rejects_unsafe_or_unqualified_context_without_changing_clocks(
        string $environment,
        string $connection,
        string $database,
        string $instant,
        int $seed,
    ): void {
        self::assertFalse(Carbon::hasTestNow());
        self::assertFalse(CarbonImmutable::hasTestNow());

        try {
            FrozenVisualRuntime::configure($environment, $connection, $database, $instant, $seed);
            self::fail('Invalid visual runtime context was accepted.');
        } catch (InvalidArgumentException) {
            self::assertFalse(Carbon::hasTestNow());
            self::assertFalse(CarbonImmutable::hasTestNow());
        }
    }

    public static function invalidContexts(): iterable
    {
        yield 'production' => ['production', 'mysql', FrozenVisualRuntime::DATABASE, '2026-10-05T00:00:00Z', 20261005];
        yield 'shared default database' => ['local', 'mysql', 'oblivion_findings', '2026-10-05T00:00:00Z', 20261005];
        yield 'different connection' => ['testing', 'sqlite', FrozenVisualRuntime::DATABASE, '2026-10-05T00:00:00Z', 20261005];
        yield 'missing instant' => ['testing', 'mysql', FrozenVisualRuntime::DATABASE, '', 20261005];
        yield 'unqualified local time' => ['testing', 'mysql', FrozenVisualRuntime::DATABASE, '2026-10-05T13:00:00', 20261005];
        yield 'invalid calendar day' => ['testing', 'mysql', FrozenVisualRuntime::DATABASE, '2026-02-30T00:00:00Z', 20261005];
        yield 'missing seed' => ['testing', 'mysql', FrozenVisualRuntime::DATABASE, '2026-10-05T00:00:00Z', 0];
    }
}
