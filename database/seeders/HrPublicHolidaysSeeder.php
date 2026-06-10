<?php

namespace Database\Seeders;

use App\Domain\Hr\Models\HrPublicHoliday;
use Illuminate\Database\Seeder;

class HrPublicHolidaysSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->holidays() as $holiday) {
            HrPublicHoliday::query()->updateOrCreate(
                [
                    'tenant_id' => null,
                    'name' => $holiday['name'],
                    'year' => $holiday['year'],
                    'region' => $holiday['region'],
                ],
                [
                    'date' => $holiday['date'],
                    'is_national' => $holiday['is_national'],
                ],
            );
        }
    }

    /**
     * @return array<int, array{name: string, date: string, region: string, is_national: bool, year: int}>
     */
    private function holidays(): array
    {
        return [
            ...$this->national2026(),
            ...$this->regional2026(),
            ...$this->national2027(),
            ...$this->regional2027(),
        ];
    }

    /**
     * @return array<int, array{name: string, date: string, region: string, is_national: bool, year: int}>
     */
    private function national2026(): array
    {
        return $this->nationalRows(2026, [
            ["New Year's Day", '2026-01-01'],
            ["Day after New Year's Day", '2026-01-02'],
            ['Waitangi Day', '2026-02-06'],
            ['Good Friday', '2026-04-03'],
            ['Easter Monday', '2026-04-06'],
            ['ANZAC Day', '2026-04-27'],
            ["King's Birthday", '2026-06-01'],
            ['Matariki', '2026-07-10'],
            ['Labour Day', '2026-10-26'],
            ['Christmas Day', '2026-12-25'],
            ['Boxing Day', '2026-12-28'],
        ]);
    }

    /**
     * @return array<int, array{name: string, date: string, region: string, is_national: bool, year: int}>
     */
    private function regional2026(): array
    {
        return $this->regionalRows(2026, [
            ['Wellington Anniversary Day', '2026-01-19', 'wellington'],
            ['Northland Anniversary Day', '2026-01-26', 'northland'],
            ['Auckland Anniversary Day', '2026-01-26', 'auckland'],
            ['Nelson Anniversary Day', '2026-02-02', 'nelson'],
            ['Taranaki Anniversary Day', '2026-03-09', 'taranaki'],
            ['Otago Anniversary Day', '2026-03-23', 'otago'],
            ['Southland Anniversary Day', '2026-04-07', 'southland'],
            ['South Canterbury Anniversary Day', '2026-09-28', 'south-canterbury'],
            ["Hawke's Bay Anniversary Day", '2026-10-23', 'hawkes-bay'],
            ['Marlborough Anniversary Day', '2026-11-02', 'marlborough'],
            ['Canterbury Anniversary Day', '2026-11-13', 'canterbury'],
            ['Chatham Islands Anniversary Day', '2026-11-30', 'chatham-islands'],
            ['Westland Anniversary Day', '2026-11-30', 'westland'],
        ]);
    }

    /**
     * @return array<int, array{name: string, date: string, region: string, is_national: bool, year: int}>
     */
    private function national2027(): array
    {
        return $this->nationalRows(2027, [
            ["New Year's Day", '2027-01-01'],
            ["Day after New Year's Day", '2027-01-04'],
            ['Waitangi Day', '2027-02-08'],
            ['Good Friday', '2027-03-26'],
            ['Easter Monday', '2027-03-29'],
            ['ANZAC Day', '2027-04-26'],
            ["King's Birthday", '2027-06-07'],
            ['Matariki', '2027-06-25'],
            ['Labour Day', '2027-10-25'],
            ['Christmas Day', '2027-12-27'],
            ['Boxing Day', '2027-12-28'],
        ]);
    }

    /**
     * @return array<int, array{name: string, date: string, region: string, is_national: bool, year: int}>
     */
    private function regional2027(): array
    {
        return $this->regionalRows(2027, [
            ['Wellington Anniversary Day', '2027-01-25', 'wellington'],
            ['Northland Anniversary Day', '2027-02-01', 'northland'],
            ['Auckland Anniversary Day', '2027-02-01', 'auckland'],
            ['Nelson Anniversary Day', '2027-02-01', 'nelson'],
            ['Taranaki Anniversary Day', '2027-03-08', 'taranaki'],
            ['Otago Anniversary Day', '2027-03-22', 'otago'],
            ['Southland Anniversary Day', '2027-03-30', 'southland'],
            ['South Canterbury Anniversary Day', '2027-09-27', 'south-canterbury'],
            ["Hawke's Bay Anniversary Day", '2027-10-22', 'hawkes-bay'],
            ['Marlborough Anniversary Day', '2027-11-01', 'marlborough'],
            ['Canterbury Anniversary Day', '2027-11-12', 'canterbury'],
            ['Chatham Islands Anniversary Day', '2027-11-29', 'chatham-islands'],
            ['Westland Anniversary Day', '2027-11-29', 'westland'],
        ]);
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $dates
     * @return array<int, array{name: string, date: string, region: string, is_national: bool, year: int}>
     */
    private function nationalRows(int $year, array $dates): array
    {
        return array_map(fn (array $row) => [
            'name' => $row[0],
            'date' => $row[1],
            'region' => 'national',
            'is_national' => true,
            'year' => $year,
        ], $dates);
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string}>  $dates
     * @return array<int, array{name: string, date: string, region: string, is_national: bool, year: int}>
     */
    private function regionalRows(int $year, array $dates): array
    {
        return array_map(fn (array $row) => [
            'name' => $row[0],
            'date' => $row[1],
            'region' => $row[2],
            'is_national' => false,
            'year' => $year,
        ], $dates);
    }
}
