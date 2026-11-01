<?php

namespace App\Support\Respite;

use App\Support\WorkerClock;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Respite forms post NZ wall-clock times (`datetime-local` and `date` inputs,
 * no offset). Storage is UTC, so each Respite date/time input is read in the
 * worker timezone and converted once, at the write boundary. A value that
 * already carries an offset (an ISO string with Z or +13:00, or a Carbon)
 * keeps its instant, so converting it twice changes nothing.
 *
 * Day sums (nights) run on NZ calendar days: the UTC date of a morning NZ
 * arrival is the day before.
 */
class RespiteWallClock
{
    /**
     * An NZ wall-clock input as a UTC Carbon for a datetime column. Daylight
     * saving follows PHP: a time skipped by the spring change moves an hour
     * later, and a time repeated by the autumn change reads as standard time
     * (+12:00).
     */
    public static function toUtc(DateTimeInterface|string|null $value): ?Carbon
    {
        $utc = WorkerClock::toUtc($value);

        return $utc ? Carbon::instance($utc) : null;
    }

    /**
     * Convert the named inputs that are present and filled; others pass through.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public static function inputsToUtc(array $input, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== null && $input[$field] !== '') {
                $input[$field] = self::toUtc($input[$field]);
            }
        }

        return $input;
    }

    /**
     * Whole nights between two instants, counted on NZ calendar days: a 2 pm
     * arrival and a 10 am departure two days later is 2 nights, whatever the
     * UTC dates or a daylight-saving change in between.
     */
    public static function nights(DateTimeInterface $start, DateTimeInterface $end): int
    {
        return (int) Carbon::parse(WorkerClock::localDate($start), 'UTC')
            ->diffInDays(Carbon::parse(WorkerClock::localDate($end), 'UTC'));
    }
}
