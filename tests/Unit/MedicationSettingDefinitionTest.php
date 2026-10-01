<?php

namespace Tests\Unit;

use App\Services\Medication\Settings\MedicationSettingDefinition;
use PHPUnit\Framework\TestCase;

/**
 * eMAR P11: one loosens() rule decides the destructive confirmation, the
 * change-history label and the restore warning.
 */
class MedicationSettingDefinitionTest extends TestCase
{
    public function test_ranked_options_loosen_only_towards_the_looser_end(): void
    {
        $restricted = $this->definition(rank: ['off', 'cosigner', 'block']);

        $this->assertTrue($restricted->loosens('block', 'cosigner'));
        $this->assertTrue($restricted->loosens('block', 'off'));
        $this->assertTrue($restricted->loosens('cosigner', 'off'));
        $this->assertFalse($restricted->loosens('off', 'block'));
        $this->assertFalse($restricted->loosens('cosigner', 'block'));
        $this->assertFalse($restricted->loosens('block', 'block'));
        // A value outside the ranking is never called looser.
        $this->assertFalse($restricted->loosens('block', 'unknown'));
    }

    public function test_numbers_loosen_in_their_direction_and_switching_off_can_be_the_loosest(): void
    {
        $attempts = $this->definition(numeric: ['direction' => MedicationSettingDefinition::HIGHER_IS_LOOSER, 'off' => null, 'off_is_loosest' => false]);
        $this->assertTrue($attempts->loosens('5', '10'));
        $this->assertFalse($attempts->loosens('5', '3'));

        $lockout = $this->definition(numeric: ['direction' => MedicationSettingDefinition::HIGHER_IS_STRICTER, 'off' => null, 'off_is_loosest' => false]);
        $this->assertTrue($lockout->loosens('15', '5'));
        $this->assertFalse($lockout->loosens('15', '60'));

        $renewal = $this->definition(numeric: ['direction' => MedicationSettingDefinition::HIGHER_IS_LOOSER, 'off' => 'none', 'off_is_loosest' => true]);
        $this->assertTrue($renewal->loosens('6', 'none'), 'Turning renewal off loosens it.');
        $this->assertTrue($renewal->loosens('6', '12'));
        $this->assertFalse($renewal->loosens('none', '12'), 'Switching a check on is never looser.');
        $this->assertFalse($renewal->loosens('12', '6'));
    }

    public function test_unknown_stored_values_read_as_the_default(): void
    {
        $definition = $this->definition(rank: ['off', 'block']);

        $this->assertSame('off', $definition->normalise('always'));
        $this->assertSame('off', $definition->normalise(null));
        $this->assertSame('block', $definition->normalise('block'));
        $this->assertSame('Block — refuse', $definition->format('block'));
    }

    /**
     * @param  list<string>|null  $rank
     * @param  array{direction: string, off: string|null, off_is_loosest: bool}|null  $numeric
     */
    private function definition(?array $rank = null, ?array $numeric = null): MedicationSettingDefinition
    {
        return new MedicationSettingDefinition(
            group: 'test',
            key: 'setting',
            storageKey: 'medications.test.setting',
            scope: MedicationSettingDefinition::SCOPE_ORGANISATION,
            section: 'safety',
            label: 'A test setting',
            options: ['off' => 'Off', 'block' => 'Block — refuse'],
            default: 'off',
            rank: $rank,
            numeric: $numeric,
        );
    }
}
