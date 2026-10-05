<?php

namespace Tests\Visual;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Faker\Generator;
use Faker\Provider\DateTime;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

final class FrozenVisualRuntime
{
    public const DATABASE = 'oblivion_findings_visual';

    public static function configure(
        string $environment,
        string $connection,
        string $database,
        string $instant,
        int $seed,
    ): void {
        if (! in_array($environment, ['local', 'testing'], true)) {
            throw new InvalidArgumentException('Frozen visual runtime requires a local or testing environment.');
        }
        if ($connection !== 'mysql' || $database !== self::DATABASE) {
            throw new InvalidArgumentException('Frozen visual runtime requires the dedicated disposable visual database.');
        }
        if ($seed < 1 || $seed > 2147483647) {
            throw new InvalidArgumentException('Frozen visual runtime requires an explicit positive random seed.');
        }
        if (! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/', $instant)) {
            throw new InvalidArgumentException('Frozen visual runtime requires an absolute ISO timestamp.');
        }

        $date = new DateTimeImmutable($instant);
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new InvalidArgumentException('Frozen visual runtime requires a valid absolute ISO timestamp.');
        }

        $frozen = CarbonImmutable::instance($date)->utc();
        Carbon::setTestNow(Carbon::instance($frozen));
        CarbonImmutable::setTestNow($frozen);
        mt_srand($seed);
    }

    /**
     * @return array{environment: string, connection: string, database: string, instant: string, seed: int}
     */
    public static function configureFromEnvironment(): array
    {
        $read = static function (string $key): string {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

            return is_string($value) ? $value : '';
        };
        if ($read('DB_URL') !== '') {
            throw new InvalidArgumentException('Frozen visual runtime cannot accept a database URL override.');
        }
        $seed = $read('VISUAL_RANDOM_SEED');
        if (! preg_match('/\A[1-9]\d{0,9}\z/', $seed)) {
            throw new InvalidArgumentException('Frozen visual runtime requires an explicit positive random seed.');
        }
        $options = [
            'environment' => $read('APP_ENV'),
            'connection' => $read('DB_CONNECTION'),
            'database' => $read('DB_DATABASE'),
            'instant' => $read('VISUAL_FROZEN_NOW'),
            'seed' => (int) $seed,
        ];
        self::configure(...$options);

        return $options;
    }

    public static function seedFaker(Container $app, int $seed): void
    {
        $app->afterResolving(Generator::class, static function (Generator $faker) use ($seed): void {
            $faker->addProvider(new class($faker) extends DateTime
            {
                protected static function getMaxTimestamp($max = 'now')
                {
                    if (is_numeric($max)) {
                        return (int) $max;
                    }
                    if ($max instanceof DateTimeInterface) {
                        return $max->getTimestamp();
                    }

                    return CarbonImmutable::parse(empty($max) ? 'now' : $max)->getTimestamp();
                }

                public static function dateTimeBetween($startDate = '-30 years', $endDate = 'now', $timezone = null)
                {
                    return parent::dateTimeBetween(
                        Carbon::createFromTimestamp(self::getMaxTimestamp($startDate)),
                        Carbon::createFromTimestamp(self::getMaxTimestamp($endDate)),
                        $timezone,
                    );
                }

                public static function dateTimeInInterval($date = '-30 years', $interval = '+5 days', $timezone = null)
                {
                    return parent::dateTimeInInterval(
                        Carbon::createFromTimestamp(self::getMaxTimestamp($date)),
                        $interval,
                        $timezone,
                    );
                }
            });
            $faker->seed($seed);
        });
    }
}
