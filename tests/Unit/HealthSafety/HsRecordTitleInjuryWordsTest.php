<?php

namespace Tests\Unit\HealthSafety;

use App\Support\HealthSafety\HsRecordTitle;
use PHPUnit\Framework\TestCase;

/** Readiness audit F3 (6 Nov 2026): the injury title keeps acronyms and capitals the person typed. */
final class HsRecordTitleInjuryWordsTest extends TestCase
{
    public function test_an_ordinary_body_part_reads_as_part_of_the_sentence(): void
    {
        $this->assertSame('Strain left wrist', HsRecordTitle::injuryWords('strain', 'Left wrist'));
        $this->assertSame('Cut left index finger', HsRecordTitle::injuryWords('laceration', '  Left   index finger '));
    }

    public function test_acronyms_and_typed_capitals_are_kept(): void
    {
        $this->assertSame('Strain ACL right knee', HsRecordTitle::injuryWords('strain', 'ACL right knee'));
        $this->assertSame('Strain AUDIT TEST', HsRecordTitle::injuryWords('strain', 'AUDIT TEST'));
        $this->assertSame('Bruise a', HsRecordTitle::injuryWords('contusion', 'A'));
    }

    public function test_kinds_without_a_body_part_ignore_it(): void
    {
        $this->assertSame('Work illness', HsRecordTitle::injuryWords('illness', 'Lungs'));
    }
}
