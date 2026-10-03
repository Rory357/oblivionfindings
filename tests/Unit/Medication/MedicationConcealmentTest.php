<?php

namespace Tests\Unit\Medication;

use App\Services\Medication\MedicationConcealment;
use PHPUnit\Framework\TestCase;

class MedicationConcealmentTest extends TestCase
{
    private const ROWS = [
        ['name' => 'Metformin', 'cd' => false],
        ['name' => 'Methylphenidate', 'cd' => true],
        ['name' => 'Clonazepam', 'cd' => true],
    ];

    public function test_cross_person_lists_leave_controlled_rows_out_and_count_them(): void
    {
        $result = (new MedicationConcealment(false))->leaveOut(self::ROWS, fn (array $row) => $row['cd']);

        $this->assertSame(['Metformin'], array_column($result['rows'], 'name'));
        $this->assertSame(2, $result['hidden']);
    }

    public function test_a_persons_record_keeps_controlled_rows_redacted_and_counted(): void
    {
        $result = (new MedicationConcealment(false))->redact(
            self::ROWS,
            fn (array $row) => $row['cd'],
            fn (array $row) => ['name' => 'Controlled medicine', 'cd' => true],
        );

        $this->assertSame(
            ['Metformin', 'Controlled medicine', 'Controlled medicine'],
            array_column($result['rows'], 'name'),
        );
        $this->assertSame(2, $result['hidden']);
    }

    public function test_a_controlled_reader_sees_everything(): void
    {
        $concealment = new MedicationConcealment(true);

        $this->assertSame(3, count($concealment->leaveOut(self::ROWS, fn (array $row) => $row['cd'])['rows']));
        $this->assertSame(0, $concealment->redact(self::ROWS, fn (array $row) => $row['cd'], fn () => null)['hidden']);
        $this->assertFalse($concealment->hides(true));
    }
}
