<?php

namespace Tests\Unit;

use App\Domain\Shifts\Handovers\OperationsHandoverIntent;
use PHPUnit\Framework\TestCase;

class OperationsHandoverIntentTest extends TestCase
{
    public function test_canonical_json_keeps_unicode_line_terminators_slashes_and_list_order(): void
    {
        $this->assertSame('{"a":[2,1],"z":"/' . "\u{2028}\u{2029}" . '"}', OperationsHandoverIntent::json(['z' => "/\u{2028}\u{2029}", 'a' => [2, 1]]));
    }

    public function test_empty_submit_fields_are_an_object_not_a_list_in_the_hash(): void
    {
        $fields = new \stdClass;
        $canonical = ['action' => 'submit', 'shift_id' => 3, 'handover_id' => 4, 'expected_version' => 5,
            'expected_source' => str_repeat('a', 64), 'fields' => $fields];
        $hash = hash('sha256', OperationsHandoverIntent::json($canonical));
        $intent = OperationsHandoverIntent::fromBody('submit', 3, 4, ['request_id' => '018bcfe5-5680-7000-8000-000000000001',
            'expected_source' => str_repeat('a', 64), 'expected_version' => 5, 'fields' => [], 'intent_hash' => $hash]);
        $this->assertSame($hash, $intent->hash);
        $canonical['fields'] = [];
        $this->assertNotSame($hash, hash('sha256', OperationsHandoverIntent::json($canonical)));
    }

    public function test_non_contract_numeric_values_are_refused(): void
    {
        $this->expectException(\LogicException::class);
        OperationsHandoverIntent::json(['field' => 1.5]);
    }
}
