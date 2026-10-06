<?php

namespace Tests\Unit\Support;

use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class MedicationWorkflowDefaultsTest extends TestCase
{
    public function test_completed_workflows_default_to_enabled_without_installation_overrides(): void
    {
        $this->assertSame([true, true, true], $this->flags([]));
    }

    #[DataProvider('individualOverrides')]
    public function test_each_installation_can_disable_one_workflow_without_disabling_the_others(string $environment, array $expected): void
    {
        $this->assertSame($expected, $this->flags([$environment => 'false']));
    }

    public static function individualOverrides(): array
    {
        return [
            'packs' => ['MEDICATION_STOCK_LOTS_ENABLED', [false, true, true]],
            'forgotten PIN' => ['MEDICATION_FORGOTTEN_PIN_ENABLED', [true, false, true]],
            'leave Away' => ['MEDICATION_AWAY_FROM_LEAVE', [true, true, false]],
        ];
    }

    public function test_all_three_installation_overrides_can_disable_the_workflows(): void
    {
        $this->assertSame([false, false, false], $this->flags([
            'MEDICATION_STOCK_LOTS_ENABLED' => 'false',
            'MEDICATION_FORGOTTEN_PIN_ENABLED' => 'false',
            'MEDICATION_AWAY_FROM_LEAVE' => 'false',
        ]));
    }

    /** Load the actual config against a private in-memory environment, without changing process variables. */
    private function flags(array $overrides): array
    {
        $property = new ReflectionProperty(Env::class, 'repository');
        $original = $property->getValue();
        $repository = RepositoryBuilder::createWithNoAdapters()->addAdapter(ArrayAdapter::class)->make();
        foreach ($overrides as $key => $value) {
            $repository->set($key, $value);
        }
        $property->setValue(null, $repository);
        try {
            $config = require __DIR__.'/../../../config/medications.php';

            return [$config['stock_lots_enabled'], $config['witness_pin']['forgotten_fallback_enabled'], $config['away']['from_leave']];
        } finally {
            $property->setValue(null, $original);
        }
    }
}
