<?php

namespace Tests\Unit;

use App\Http\Controllers\Emar\PersonMedicationRecordController;
use App\Models\Client;
use App\Services\MarScheduleService;
use App\Services\Medication\MedicationRecordAccess;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/** Exercises the real header projection without booting the application or a database. */
class PersonMedicationRecordAgeTest extends TestCase
{
    private string $previousTimezone;

    private mixed $previousNow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTimezone = date_default_timezone_get();
        $this->previousNow = Carbon::getTestNow();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow($this->previousNow);
        date_default_timezone_set($this->previousTimezone);
        parent::tearDown();
    }

    #[DataProvider('birthdays')]
    public function test_the_header_uses_completed_years_on_the_worker_calendar_day(?string $birthDate, string $instant, ?int $expectedAge): void
    {
        Carbon::setTestNow(Carbon::parse($instant));
        $client = new Client;
        $client->setRawAttributes([
            'id' => 14,
            'first_name' => 'Age',
            'last_name' => 'Boundary',
            'date_of_birth' => $birthDate,
            'nhi_number' => null,
        ]);
        $client->setRelation('site', null)->setRelation('serviceContext', null);
        $access = (new ReflectionClass(MedicationRecordAccess::class))->newInstanceWithoutConstructor();
        $schedule = new class extends MarScheduleService
        {
            public function workerTimezone(): string
            {
                return 'Pacific/Auckland';
            }
        };
        $controller = new PersonMedicationRecordController($access, $schedule);
        $person = (new ReflectionMethod($controller, 'person'))->invoke($controller, $client);

        $this->assertSame($expectedAge, $person['age']);
        $this->assertSame('Age Boundary', $person['name']);
        $this->assertSame($birthDate, $client->date_of_birth?->toDateString(), 'A stored birth date must not shift through a timezone.');
    }

    public static function birthdays(): array
    {
        return [
            'before summer birthday midnight' => ['1984-10-04', '2026-10-03T10:59:59Z', 41],
            'summer birthday while UTC is yesterday' => ['1984-10-04', '2026-10-03T11:00:00Z', 42],
            'later on the birthday' => ['1984-10-04', '2026-10-04T07:15:00Z', 42],
            'before winter birthday midnight' => ['1984-07-04', '2026-07-03T11:59:59Z', 41],
            'winter birthday while UTC is yesterday' => ['1984-07-04', '2026-07-03T12:00:00Z', 42],
            'leap birthday before completed anniversary' => ['2000-02-29', '2026-02-27T11:00:00Z', 25],
            'leap birthday completed on March first' => ['2000-02-29', '2026-02-28T11:00:00Z', 26],
            'unknown birth date' => [null, '2026-10-03T11:00:00Z', null],
            'future birth date' => ['2026-10-05', '2026-10-03T11:00:00Z', null],
            'birth date is today' => ['2026-10-04', '2026-10-03T11:00:00Z', 0],
        ];
    }
}
