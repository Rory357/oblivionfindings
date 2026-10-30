<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Cctv\Models\HikvisionDiscoveryRequest;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

final class HikvisionDiscoveryImmutabilityTest extends TestCase
{
    public function test_original_signed_binding_and_terminal_receipts_cannot_be_reinterpreted_before_any_database_write(): void
    {
        $resolver = Model::getConnectionResolver();
        $dispatcher = Model::getEventDispatcher();
        $connection = new class(fn () => throw new \RuntimeException('This model test must never connect.'), 'unreachable', '', ['driver' => 'mysql']) extends MySqlConnection
        {
            protected function escapeString($value)
            {
                return "'".str_replace("'", "''", $value)."'";
            }
        };
        $fake = new ConnectionResolver(['synthetic' => $connection]);
        $fake->setDefaultConnection('synthetic');
        Model::setConnectionResolver($fake);
        Model::setEventDispatcher(new Dispatcher(new Container));
        Model::clearBootedModels();
        try {
            foreach ([
                ['actor_id' => 99], ['operation_hash' => str_repeat('b', 64)], ['credential_lease_grant_id' => 99],
                ['issued_at' => '2026-10-11 00:00:01'], ['authority_until' => '2026-10-11 00:00:16'],
                ['status' => 'queued'], ['operation' => null],
            ] as $changes) {
                $row = $this->row();
                $attributes = $row->getAttributes();
                $row->setRawAttributes(array_replace($attributes, $changes));
                $denied = false;
                $queries = $connection->pretend(function () use ($row, &$denied): void {
                    try {
                        $row->save();
                    } catch (\LogicException) {
                        $denied = true;
                    }
                });
                self::assertTrue($denied, 'Changed original signed binding was allowed: '.implode(',', array_keys($changes)));
                self::assertSame([], $queries, 'Denied model mutation reached a database write.');
            }
            foreach (['expired', 'failed', 'revoked', 'observed'] as $status) {
                $row = $this->row(['status' => $status, 'operation' => null]);
                $row->status = 'queued';
                $denied = false;
                $queries = $connection->pretend(function () use ($row, &$denied): void {
                    try {
                        $row->save();
                    } catch (\LogicException) {
                        $denied = true;
                    }
                });
                self::assertTrue($denied, 'A terminal receipt was reopened.');
                self::assertSame([], $queries);
            }
            // Expiry is a valid update: retain the original signed tuple/hash,
            // erase its sealed operation, and reach the real MySQL update grammar.
            $row = $this->row();
            $row->setRawAttributes(array_replace($row->getAttributes(), ['status' => 'expired', 'reason_code' => 'lease_expired', 'operation' => null]));
            $queries = $connection->pretend(fn () => $row->save());
            self::assertCount(1, $queries);
            self::assertStringContainsString('update `cctv_hikvision_discovery_requests`', $queries[0]['query']);
            self::assertSame(str_repeat('a', 64), $row->operation_hash);
            self::assertNull($row->getAttributes()['operation']);
        } finally {
            if ($resolver) {
                Model::setConnectionResolver($resolver);
            } else {
                Model::unsetConnectionResolver();
            }
            if ($dispatcher) {
                Model::setEventDispatcher($dispatcher);
            } else {
                Model::unsetEventDispatcher();
            }
            Model::clearBootedModels();
        }
    }

    private function row(array $changes = []): HikvisionDiscoveryRequest
    {
        $row = new HikvisionDiscoveryRequest;
        $row->timestamps = false;
        $row->exists = true;
        $row->setDateFormat('Y-m-d H:i:s');
        $row->setRawAttributes(array_replace(['id' => 1, 'actor_id' => 1, 'status' => 'dispatched', 'operation' => 'synthetic-encrypted-operation', 'operation_hash' => str_repeat('a', 64), 'credential_lease_grant_id' => 1, 'issued_at' => '2026-10-11 00:00:00', 'authority_until' => '2026-10-11 00:00:15', 'result_hash' => null], $changes), true);

        return $row;
    }
}
