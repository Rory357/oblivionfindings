<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\FleetMedicationTransitLog;
use App\Services\Fleet\ResidentTransportJourneyService;
use App\Services\Medication\Controlled\ControlledPolicy;
use App\Services\MedicationRuleService;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Witness decisions only: no application boot, database or transport mutation. */
class ControlledTransportWitnessTest extends TestCase
{
    private mixed $previousPolicy;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        $this->previousPolicy = $container->bound(ControlledPolicy::class)
            ? $container->make(ControlledPolicy::class) : null;
        $container->instance(ControlledPolicy::class, new class extends ControlledPolicy
        {
            protected function organisationValue(string $key): mixed
            {
                return 'off';
            }

            protected function houseValue(int $siteId, string $key): mixed
            {
                return $siteId === 4 ? 'off' : 'on';
            }
        });
    }

    protected function tearDown(): void
    {
        $container = Container::getInstance();
        $container->forgetInstance(ControlledPolicy::class);
        if ($this->previousPolicy !== null) {
            $container->instance(ControlledPolicy::class, $this->previousPolicy);
        }
        parent::tearDown();
    }

    public function test_historical_packing_requirement_remains_required_after_policy_is_disabled(): void
    {
        $rules = $this->createMock(MedicationRuleService::class);
        $rules->expects($this->never())->method('requirementsFor');
        $log = $this->log(true);
        $medication = $this->medication();

        $this->assertTrue($this->service($rules)->requiresAdministrationWitness($log, $medication));
        $this->assertTrue($log->witness_required);
        $this->assertFalse($medication->witness_required);
    }

    public function test_controlled_status_does_not_override_the_reviewed_house_policy(): void
    {
        $rules = $this->createMock(MedicationRuleService::class);
        $medication = $this->medication();
        $rules->expects($this->once())->method('requirementsFor')
            ->with($medication, false)->willReturn(['requires_countersign' => false]);
        $log = $this->log(false);

        $this->assertFalse($this->service($rules)->requiresAdministrationWitness($log, $medication));
        $this->assertFalse($log->witness_required, 'A current rule must not rewrite the historical packing snapshot.');
        $this->assertSame(4, (int) $medication->client->site_id);
    }

    public function test_explicit_current_order_requirement_wins_without_rewriting_packing(): void
    {
        $rules = $this->createMock(MedicationRuleService::class);
        $rules->expects($this->never())->method('requirementsFor');
        $medication = $this->medication();
        $medication->witness_required = true;
        $log = $this->log(false);

        $this->assertTrue($this->service($rules)->requiresAdministrationWitness($log, $medication));
        $this->assertFalse($log->witness_required);
        $this->assertTrue($medication->witness_required);
    }

    public function test_current_countersign_rules_apply_to_a_read_only_payload(): void
    {
        $rules = $this->createMock(MedicationRuleService::class);
        $medication = $this->medication();
        $rules->expects($this->once())->method('requirementsFor')
            ->with($medication, false)->willReturn(['requires_countersign' => true]);

        $this->assertTrue($this->service($rules)->requiresAdministrationWitness($this->log(false), $medication));
    }

    public function test_mutation_still_locks_the_current_rule_set(): void
    {
        $rules = $this->createMock(MedicationRuleService::class);
        $medication = $this->medication();
        $rules->expects($this->once())->method('requirementsFor')
            ->with($medication, true)->willReturn(['requires_countersign' => true]);

        $this->assertTrue($this->service($rules)->requiresAdministrationWitness($this->log(false), $medication, true));
    }

    public function test_a_current_canonical_resident_relation_is_not_replaced_by_a_payload_relation(): void
    {
        $rules = $this->createMock(MedicationRuleService::class);
        $rules->expects($this->never())->method('requirementsFor');
        $medication = $this->medication();
        $current = new Client(['site_id' => 5]);
        $current->id = 9;
        $medication->setRelation('client', $current);

        $this->assertTrue($this->service($rules)->requiresAdministrationWitness($this->log(false), $medication));
        $this->assertSame($current, $medication->client);
    }

    private function service(MedicationRuleService $rules): ResidentTransportJourneyService
    {
        $class = new ReflectionClass(ResidentTransportJourneyService::class);
        $service = $class->newInstanceWithoutConstructor();
        $class->getProperty('medicationRules')->setValue($service, $rules);

        return $service;
    }

    private function medication(): ClientMedication
    {
        return new ClientMedication(['client_id' => 9, 'controlled_drug' => true, 'witness_required' => false]);
    }

    private function log(bool $required): FleetMedicationTransitLog
    {
        $log = new FleetMedicationTransitLog(['client_id' => 9, 'witness_required' => $required, 'is_controlled_drug' => true]);
        $client = new Client(['site_id' => 4]);
        $client->id = 9;
        $log->setRelation('client', $client);

        return $log;
    }
}
