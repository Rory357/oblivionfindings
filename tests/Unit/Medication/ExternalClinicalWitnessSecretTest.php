<?php

namespace Tests\Unit\Medication;

use App\Support\Medication\ScrubNestedWitnessSecrets;
use PHPUnit\Framework\TestCase;

final class ExternalClinicalWitnessSecretTest extends TestCase
{
    public function test_order_source_witness_pin_cannot_survive_validation_old_input(): void
    {
        $input = ['decision' => 'accept', 'source' => ['prescriber' => 'Named clinician', 'witness_id' => 12, 'witness_pin' => '123456'],
            'items' => [['source' => ['witness_pin' => '654321', 'reference' => 'Source reference']]]];
        $safe = ScrubNestedWitnessSecrets::scrub($input);
        $this->assertArrayNotHasKey('witness_pin', $safe['source']);
        $this->assertArrayNotHasKey('witness_pin', $safe['items'][0]['source']);
        $this->assertSame('Named clinician', $safe['source']['prescriber']);
        $this->assertSame('Source reference', $safe['items'][0]['source']['reference']);
    }
}
