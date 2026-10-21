<?php

use App\Services\Chat\ChatResponseSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

abstract class ChatResponseScheduleUnitTestCase extends TestCase
{
    private mixed $previousValidator = null;

    private mixed $previousContainerValidator = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousValidator = Validator::getFacadeRoot();
        $factory = new Factory(new Translator(new ArrayLoader, 'en'));
        Validator::swap($factory);
        // ValidationException::withMessages() resolves the validator from the container.
        $this->previousContainerValidator = Container::getInstance()->bound('validator') ? Container::getInstance()->make('validator') : null;
        Container::getInstance()->instance('validator', $factory);
    }

    protected function tearDown(): void
    {
        if ($this->previousContainerValidator) {
            Container::getInstance()->instance('validator', $this->previousContainerValidator);
        } else {
            Container::getInstance()->forgetInstance('validator');
        }
        if ($this->previousValidator) {
            Validator::swap($this->previousValidator);
        } else {
            Validator::clearResolvedInstance('validator');
        }
        parent::tearDown();
    }
}

uses(ChatResponseScheduleUnitTestCase::class);

function responseHoursFixture(array $changes = []): array
{
    return [...['timezone' => 'Pacific/Auckland', 'response_minutes' => 240,
        'weekly' => array_map(fn ($day) => ['day' => $day, 'start' => '08:00', 'end' => '18:00'], range(1, 5)), 'holidays' => []], ...$changes];
}

it('counts remaining service time across a weekend and configured holiday', function () {
    $clock = new ChatResponseSchedule;
    $received = CarbonImmutable::parse('2026-10-23 17:00:00', 'Pacific/Auckland');
    $due = $clock->dueAt($received, responseHoursFixture(['holidays' => ['2026-10-26']]));
    expect($due->setTimezone('Pacific/Auckland')->format('Y-m-d H:i:s'))->toBe('2026-10-27 11:00:00');
    expect($received->format('Y-m-d H:i:s'))->toBe('2026-10-23 17:00:00');
});

it('starts outside-hours requests at the next opening and permits the exact closing boundary', function () {
    $clock = new ChatResponseSchedule;
    expect($clock->dueAt(CarbonImmutable::parse('2026-10-19 06:00', 'Pacific/Auckland'), responseHoursFixture())->setTimezone('Pacific/Auckland')->format('H:i'))->toBe('12:00');
    expect($clock->dueAt(CarbonImmutable::parse('2026-10-19 14:00', 'Pacific/Auckland'), responseHoursFixture())->setTimezone('Pacific/Auckland')->format('H:i'))->toBe('18:00');
    expect($clock->dueAt(CarbonImmutable::parse('2026-10-19 18:00', 'Pacific/Auckland'), responseHoursFixture())->setTimezone('Pacific/Auckland')->format('Y-m-d H:i'))->toBe('2026-10-20 12:00');
});

it('excludes split-shift breaks and handles a midnight closing', function () {
    $clock = new ChatResponseSchedule;
    $schedule = responseHoursFixture(['response_minutes' => 120, 'weekly' => [['day' => 1, 'start' => '08:00', 'end' => '12:00'], ['day' => 1, 'start' => '13:00', 'end' => '24:00']]]);
    expect($clock->dueAt(CarbonImmutable::parse('2026-10-19 11:00', 'Pacific/Auckland'), $schedule)->setTimezone('Pacific/Auckland')->format('H:i'))->toBe('14:00');
    expect($clock->dueAt(CarbonImmutable::parse('2026-10-19 22:00', 'Pacific/Auckland'), $schedule)->setTimezone('Pacific/Auckland')->format('Y-m-d H:i'))->toBe('2026-10-20 00:00');
});

it('counts actual elapsed working time across New Zealand daylight-saving transitions', function () {
    $clock = new ChatResponseSchedule;
    $schedule = responseHoursFixture(['response_minutes' => 120, 'weekly' => [['day' => 7, 'start' => '01:00', 'end' => '05:00']]]);
    expect($clock->dueAt(CarbonImmutable::parse('2026-09-27 01:00', 'Pacific/Auckland'), $schedule)->setTimezone('Pacific/Auckland')->format('H:i P'))->toBe('04:00 +13:00');
    expect($clock->dueAt(CarbonImmutable::parse('2026-04-05 01:00', 'Pacific/Auckland'), $schedule)->setTimezone('Pacific/Auckland')->format('H:i P'))->toBe('02:00 +12:00');
});

it('does not infer schedules from missing or malformed configuration', function () {
    $clock = new ChatResponseSchedule;
    expect($clock->dueAt(CarbonImmutable::now(), null))->toBeNull();
    expect($clock->dueAt(CarbonImmutable::now(), ['response_minutes' => 240]))->toBeNull();
});

it('rejects invalid hours and ambiguous overlapping schedules', function (array $change) {
    expect(fn () => (new ChatResponseSchedule)->validate(responseHoursFixture($change)))->toThrow(ValidationException::class);
})->with([
    'no working hours' => [['weekly' => []]],
    'invalid timezone' => [['timezone' => 'Not/AZone']],
    'no target' => [['response_minutes' => 0]],
    'overnight unsplit' => [['weekly' => [['day' => 1, 'start' => '22:00', 'end' => '06:00']]]],
    'overlap' => [['weekly' => [['day' => 1, 'start' => '08:00', 'end' => '12:00'], ['day' => 1, 'start' => '11:00', 'end' => '15:00']]]],
    'invalid date' => [['holidays' => ['2026-02-30']]],
    'duplicate holiday' => [['holidays' => ['2026-12-25', '2026-12-25']]],
]);

it('normalises blank holiday lines without assuming a public holiday calendar', function () {
    expect((new ChatResponseSchedule)->validate(responseHoursFixture(['holidays' => ['', ' ', '2026-12-25', null]]))['holidays'])->toBe(['2026-12-25']);
});
