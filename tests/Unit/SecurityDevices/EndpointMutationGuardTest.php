<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointDelivery;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointMutationGuard;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class EndpointMutationGuardTest extends TestCase
{
    private Manager $database;

    private Container $previousContainer;

    private $previousResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousResolver = Model::getConnectionResolver();
        $app = new Application;
        Container::setInstance($app);
        $this->database = new Manager($app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->bootEloquent();
        $schema = $this->database->getConnection()->getSchemaBuilder();
        $schema->create('device_command_requests', function (Blueprint $t) {
            $t->id();
            $t->string('capability');
        });
        $schema->create('device_command_attempts', function (Blueprint $t) {
            $t->id();
            $t->integer('device_command_request_id');
        });
        $schema->create('endpoint_deliveries', function (Blueprint $t) {
            $t->id();
            $t->integer('endpoint_agent_id');
            $t->string('agent_uuid');
            $t->integer('device_command_attempt_id');
            $t->timestamp('claimed_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->json('result')->nullable();
            $t->json('restart_readback')->nullable();
            $t->json('software_readback')->nullable();
            $t->timestamp('readback_received_at')->nullable();
        });
        $db = $this->database->getConnection();
        $db->table('device_command_requests')->insert([['id' => 1, 'capability' => 'endpoint.service.manage'], ['id' => 2, 'capability' => 'endpoint.computer.restart'], ['id' => 3, 'capability' => 'endpoint.software.install']]);
        $db->table('device_command_attempts')->insert([['id' => 1, 'device_command_request_id' => 1], ['id' => 2, 'device_command_request_id' => 2], ['id' => 3, 'device_command_request_id' => 3]]);
    }

    protected function tearDown(): void
    {
        $this->previousResolver === null ? Model::unsetConnectionResolver() : Model::setConnectionResolver($this->previousResolver);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function check(bool $blocked): void
    {
        $agent = (new EndpointAgent)->forceFill(['id' => 7, 'uuid' => 'agent-current', 'boot_id' => 'boot-current']);
        $own = (new EndpointDelivery)->forceFill(['id' => 100]);
        try {
            (new EndpointMutationGuard)->assertAvailable($agent, $own);
            self::assertFalse($blocked);
        } catch (HttpException $e) {
            self::assertTrue($blocked);
            self::assertSame(409, $e->getStatusCode());
        }
    }

    public function test_other_live_and_stale_unsettled_claims_block_both_directions_but_own_and_unclaimed_do_not(): void
    {
        $db = $this->database->getConnection();
        $row = ['id' => 100, 'endpoint_agent_id' => 7, 'agent_uuid' => 'agent-current', 'device_command_attempt_id' => 2, 'claimed_at' => '2026-10-08 00:00:00', 'completed_at' => null];
        $db->table('endpoint_deliveries')->insert($row);
        $this->check(false);
        $db->table('endpoint_deliveries')->insert([...$row, 'id' => 101, 'device_command_attempt_id' => 1]);
        $this->check(true);
        $db->table('endpoint_deliveries')->where('id', 101)->update(['completed_at' => '2026-10-08 00:00:01']);
        $this->check(false);
        $db->table('endpoint_deliveries')->where('id', 101)->update(['device_command_attempt_id' => 2, 'completed_at' => null]);
        $this->check(true);
        $db->table('endpoint_deliveries')->where('id', 101)->update(['claimed_at' => null]);
        $this->check(false);
        $db->table('endpoint_deliveries')->where('id', 101)->update(['claimed_at' => '2026-10-08 00:00:00', 'agent_uuid' => 'old-incarnation']);
        $this->check(false);
    }

    public function test_api_acceptance_and_same_boot_crash_receipts_keep_guard_until_validated_changed_boot(): void
    {
        $db = $this->database->getConnection();
        $row = ['id' => 101, 'endpoint_agent_id' => 7, 'agent_uuid' => 'agent-current', 'device_command_attempt_id' => 2, 'claimed_at' => '2026-10-08 00:00:00', 'completed_at' => '2026-10-08 00:00:01', 'result' => json_encode(['status' => 'awaiting_boot', 'mutation_started' => true]), 'restart_readback' => null];
        $db->table('endpoint_deliveries')->insert($row);
        $this->check(true);
        $db->table('endpoint_deliveries')->where('id', 101)->update(['restart_readback' => json_encode(['boot_id' => 'old-boot'])]);
        $this->check(true);
        $db->table('endpoint_deliveries')->where('id', 101)->update(['restart_readback' => json_encode(['boot_id' => 'boot-current'])]);
        $this->check(false);
        $db->table('endpoint_deliveries')->where('id', 101)->update(['result' => json_encode(['status' => 'uncertain', 'mutation_started' => true]), 'restart_readback' => null]);
        $this->check(true);
        $db->table('endpoint_deliveries')->where('id', 101)->update(['restart_readback' => json_encode(['boot_id' => 'boot-current'])]);
        $this->check(false);
        $db->table('endpoint_deliveries')->where('id', 101)->update(['result' => json_encode(['status' => 'failed', 'reason_code' => 'restart_refused', 'mutation_started' => true]), 'restart_readback' => null]);
        $this->check(false);
    }

    public function test_installer_boundary_blocks_until_durable_quiescence_and_does_not_relock_after_later_boot(): void
    {
        $db = $this->database->getConnection();
        $row = ['id' => 101, 'endpoint_agent_id' => 7, 'agent_uuid' => 'agent-current', 'device_command_attempt_id' => 3, 'claimed_at' => '2026-10-08 00:00:00', 'completed_at' => '2026-10-08 00:00:01', 'result' => json_encode(['status' => 'awaiting_observation', 'mutation_started' => true, 'exit_code' => 0])];
        $db->table('endpoint_deliveries')->insert($row);
        $this->check(true);
        $db->table('endpoint_deliveries')->where('id', 101)->update(['software_readback' => json_encode(['boot_id' => 'old-boot', 'transaction_quiescent' => false]), 'readback_received_at' => '2026-10-08 00:00:02']);
        $this->check(true);
        $db->table('endpoint_deliveries')->where('id', 101)->update(['software_readback' => json_encode(['boot_id' => 'boot-before-unrelated-reboot', 'transaction_quiescent' => true])]);
        $this->check(false);
        // Settled historical installs do not consume the live bounded candidate budget.
        for ($id = 102; $id <= 202; $id++) {
            $db->table('endpoint_deliveries')->insert([...$row, 'id' => $id, 'software_readback' => json_encode(['boot_id' => 'old-boot', 'transaction_quiescent' => true]), 'readback_received_at' => '2026-10-08 00:00:02']);
        }
        $this->check(false);
        $db->table('endpoint_deliveries')->where('id', 101)->update(['result' => json_encode(['status' => 'uncertain', 'mutation_started' => true]), 'software_readback' => null, 'readback_received_at' => null]);
        $this->check(true);
        $db->table('endpoint_deliveries')->where('id', 101)->update(['result' => json_encode(['status' => 'failed', 'mutation_started' => true, 'exit_code' => 1618])]);
        $this->check(true);
    }

    public function test_existing_native_guard_remains_available_before_additive_software_schema(): void
    {
        $schema = $this->database->getConnection()->getSchemaBuilder();
        $schema->table('endpoint_deliveries', fn (Blueprint $t) => $t->dropColumn('software_readback'));
        $this->check(false);
        $this->database->getConnection()->table('endpoint_deliveries')->insert(['id' => 101, 'endpoint_agent_id' => 7, 'agent_uuid' => 'agent-current', 'device_command_attempt_id' => 1, 'claimed_at' => '2026-10-08 00:00:00']);
        $this->check(true);
        $this->database->getConnection()->table('endpoint_deliveries')->where('id', 101)->update(['device_command_attempt_id' => 3, 'completed_at' => '2026-10-08 00:00:01', 'result' => json_encode(['status' => 'uncertain', 'mutation_started' => true])]);
        $this->check(true);
    }
}
