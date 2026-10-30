<?php

namespace Tests\Unit\Visual;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Faker\Factory;
use Faker\Generator;
use Illuminate\Container\Container;
use Illuminate\Support\Env;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
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
        $absolute = new DateTimeImmutable('2026-06-01T13:00:00+13:00');
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
        $this->withHttpEnvironment([
            ...$this->visualEnvironment(),
            'DB_URL' => 'mysql://example.invalid/another_database',
        ], function (): void {
            try {
                FrozenVisualRuntime::configureFromEnvironment();
                self::fail('A database URL override was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('database URL override', $exception->getMessage());
                self::assertFalse(Carbon::hasTestNow());
                self::assertFalse(CarbonImmutable::hasTestNow());
            }
        });
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

    public function test_getenv_only_ci_context_wins_over_environment_file_fallback_and_matches_laravel(): void
    {
        $this->withHttpEnvironment($this->visualEnvironment(), function (string $directory): void {
            FrozenVisualRuntime::loadEnvironment($directory);
            $options = FrozenVisualRuntime::configureFromEnvironment();
            self::assertSame('testing', $options['environment']);
            self::assertSame('mysql', $options['connection']);
            self::assertSame(FrozenVisualRuntime::DATABASE, $options['database']);
            self::assertSame($options['database'], Env::get('DB_DATABASE'));
            self::assertSame($options['connection'], Env::get('DB_CONNECTION'));
            self::assertSame('2026-10-05T00:00:00+00:00', Carbon::now('UTC')->toIso8601String());
        });
    }

    #[DataProvider('unsafeInheritedContexts')]
    public function test_environment_file_cannot_disguise_an_unsafe_inherited_http_context(string $key, string $value, string $message): void
    {
        $this->withHttpEnvironment([$key => $value, ...array_diff_key($this->visualEnvironment(), [$key => true])], function (string $directory) use ($message): void {
            FrozenVisualRuntime::loadEnvironment($directory);
            try {
                FrozenVisualRuntime::configureFromEnvironment();
                self::fail('Unsafe inherited HTTP context was disguised by .env.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
                self::assertFalse(Carbon::hasTestNow());
                self::assertFalse(CarbonImmutable::hasTestNow());
            }
        }, true);
    }

    public static function unsafeInheritedContexts(): iterable
    {
        yield 'production process' => ['APP_ENV', 'production', 'local or testing'];
        yield 'shared process database' => ['DB_DATABASE', 'oblivion_findings', 'dedicated disposable'];
        yield 'different process connection' => ['DB_CONNECTION', 'sqlite', 'dedicated disposable'];
        yield 'process URL override' => ['DB_URL', 'mysql://example.invalid/shared', 'database URL override'];
    }

    public static function transportWallClocks(): iterable
    {
        yield 'wall clock later than frozen application' => ['2026-10-05T02:05:00Z'];
        yield 'wall clock earlier than frozen application' => ['2026-10-04T23:00:00Z'];
    }

    #[DataProvider('transportWallClocks')]
    public function test_cookie_transport_preserves_exact_lifetime_values_and_security_flags(string $wall): void
    {
        FrozenVisualRuntime::configure('testing', 'mysql', FrozenVisualRuntime::DATABASE, '2026-10-05T00:00:00Z', 20261005);
        $frozen = CarbonImmutable::now('UTC')->getTimestamp();
        $session = new Cookie('visual-session', 'synthetic-session', $frozen + 3600, '/app', 'example.test', true, true, false, 'strict', true);
        $token = new Cookie('XSRF-TOKEN', 'synthetic-token', $frozen + 3600, '/', null, false, false, false, 'lax');
        $response = new Response('unchanged body', 302, ['Location' => '/my-day', 'Cache-Control' => 'private, no-store']);
        $response->headers->setCookie($session);
        $response->headers->setCookie($token);
        $beforeHeaders = $response->headers->allPreserveCaseWithoutCookies();
        $wallNow = new DateTimeImmutable($wall);

        FrozenVisualRuntime::alignTransportCookieExpiry($response, $wallNow);

        $cookies = collect($response->headers->getCookies())->keyBy(fn (Cookie $cookie) => $cookie->getName());
        foreach ([$session, $token] as $original) {
            $adjusted = $cookies->get($original->getName());
            self::assertNotSame($original, $adjusted);
            self::assertSame($wallNow->getTimestamp() + 3600, $adjusted->getExpiresTime());
            foreach (['getValue', 'getPath', 'getDomain', 'isSecure', 'isHttpOnly', 'isRaw', 'getSameSite', 'isPartitioned'] as $method) {
                self::assertSame($original->{$method}(), $adjusted->{$method}());
            }
            self::assertSame($frozen + 3600, $original->getExpiresTime());
        }
        self::assertSame($beforeHeaders, $response->headers->allPreserveCaseWithoutCookies());
        self::assertSame('unchanged body', $response->getContent());
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('2026-10-05T00:00:00+00:00', Carbon::now('UTC')->toIso8601String());
        self::assertSame('2026-10-05T00:00:00+00:00', CarbonImmutable::now('UTC')->toIso8601String());
    }

    public function test_session_only_and_deletion_cookie_meanings_are_not_extended(): void
    {
        FrozenVisualRuntime::configure('testing', 'mysql', FrozenVisualRuntime::DATABASE, '2026-10-05T00:00:00Z', 20261005);
        $frozen = CarbonImmutable::now('UTC')->getTimestamp();
        $originals = [
            new Cookie('session-only', 'synthetic', 0),
            new Cookie('expired', 'synthetic', $frozen - 1),
            new Cookie('deleted', '', $frozen + 3600),
            new Cookie('null-value', null, $frozen + 3600),
        ];
        $response = new Response;
        foreach ($originals as $cookie) {
            $response->headers->setCookie($cookie);
        }
        FrozenVisualRuntime::alignTransportCookieExpiry($response, new DateTimeImmutable('2026-10-05T02:05:00Z'));
        self::assertSame($originals, $response->headers->getCookies());
    }

    public function test_cookie_transport_rejects_an_unfrozen_runtime_without_changing_response(): void
    {
        $response = new Response;
        $cookie = new Cookie('session', 'synthetic', time() + 3600);
        $response->headers->setCookie($cookie);
        try {
            FrozenVisualRuntime::alignTransportCookieExpiry($response, new DateTimeImmutable('now'));
            self::fail('Unfrozen cookie transport was accepted.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('guarded frozen visual runtime', $exception->getMessage());
            self::assertSame([$cookie], $response->headers->getCookies());
            self::assertFalse(Carbon::hasTestNow());
            self::assertFalse(CarbonImmutable::hasTestNow());
        }
    }

    public function test_cookie_transport_rejects_a_clock_changed_after_guarded_configuration(): void
    {
        FrozenVisualRuntime::configure('testing', 'mysql', FrozenVisualRuntime::DATABASE, '2026-10-05T00:00:00Z', 20261005);
        Carbon::setTestNow(Carbon::parse('2026-10-06T00:00:00Z'));
        $this->expectException(LogicException::class);
        FrozenVisualRuntime::alignTransportCookieExpiry(new Response, new DateTimeImmutable('now'));
    }

    public function test_sent_cookie_has_positive_wall_clock_max_age_with_old_frozen_time(): void
    {
        FrozenVisualRuntime::configure('testing', 'mysql', FrozenVisualRuntime::DATABASE, '2020-01-01T00:00:00Z', 20261005);
        $response = new Response;
        $response->headers->setCookie(new Cookie('session', 'synthetic', CarbonImmutable::now('UTC')->addHour()));
        FrozenVisualRuntime::alignTransportCookieExpiry($response, new DateTimeImmutable('now'));
        $cookie = $response->headers->getCookies()[0];
        self::assertGreaterThanOrEqual(3599, $cookie->getMaxAge());
        self::assertLessThanOrEqual(3600, $cookie->getMaxAge());
        self::assertStringNotContainsString('Max-Age=0', (string) $cookie);
        self::assertSame('2020-01-01T00:00:00+00:00', CarbonImmutable::now('UTC')->toIso8601String());
    }

    private function visualEnvironment(): array
    {
        return ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => FrozenVisualRuntime::DATABASE,
            'DB_URL' => '', 'VISUAL_FROZEN_NOW' => '2026-10-05T00:00:00Z', 'VISUAL_RANDOM_SEED' => '20261005'];
    }

    /** Pure cli-server environment simulation; no Laravel app or database is booted. */
    private function withHttpEnvironment(array $values, callable $assertions, bool $safeFile = false): void
    {
        $beforeEnv = $_ENV;
        $beforeServer = $_SERVER;
        $beforeProcess = [];
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'emar-frozen-visual-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $fallback = $safeFile ? $this->visualEnvironment() : ['APP_ENV' => 'local', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => 'oblivion_findings',
            'DB_URL' => '', 'VISUAL_FROZEN_NOW' => '2026-10-05T00:00:00Z', 'VISUAL_RANDOM_SEED' => '20261005'];
        file_put_contents($directory.DIRECTORY_SEPARATOR.'.env', implode("\n", array_map(fn ($key, $value) => $key.'='.$value, array_keys($fallback), $fallback))."\n");
        try {
            foreach ($values as $key => $value) {
                $beforeProcess[$key] = getenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key.'='.$value);
            }
            Env::enablePutenv();
            $assertions($directory);
        } finally {
            $_ENV = $beforeEnv;
            $_SERVER = $beforeServer;
            foreach ($beforeProcess as $key => $value) {
                putenv($value === false ? $key : $key.'='.$value);
            }
            Env::enablePutenv();
            unlink($directory.DIRECTORY_SEPARATOR.'.env');
            rmdir($directory);
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
