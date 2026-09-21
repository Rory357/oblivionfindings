<?php

namespace Tests\Unit\Tracking;

use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Http\Requests\StoreDeviceCommandBatchRequest;
use App\Domain\SecurityDevices\Management\Http\Requests\StoreDeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandAuditService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandContractVerifier;
use App\Http\Requests\Operations\StoreClientLocateRequest;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Tests\Support\CommittedDatabaseTestCase;
use Tests\Support\DeviceCommandAuditFixture;

class DeviceCommandAuditTailTest extends CommittedDatabaseTestCase
{
    private string $primary;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->primary = DB::getDefaultConnection();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertIsolatedTestConnection(DB::connection());
        config(['database.connections.audit_interleaving' => [...DB::connection()->getConfig(), 'name' => 'audit_interleaving']]);
        $other = DB::connection('audit_interleaving');
        $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $this->assertSame(DB::connection()->getDatabaseName(), $other->getDatabaseName());
        $this->assertNotSame(DB::connection()->getPdo(), $other->getPdo());
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->primary);
        DB::connection('audit_interleaving')->rollBack(0);
        DB::rollBack(0);
        DB::purge('audit_interleaving');
        $this->travelBack();
        parent::tearDown();
    }

    public function test_adjacent_and_non_adjacent_requests_append_independently_with_empty_and_existing_tails(): void
    {
        $audit = app(DeviceCommandAuditService::class);
        foreach ([false, true] as $existing) {
            $requests = [DeviceCommandAuditFixture::request(), DeviceCommandAuditFixture::request(), DeviceCommandAuditFixture::request()];
            if ($existing) {
                foreach ($requests as $request) {
                    $audit->append($request, null, 'first');
                }
            }
            DB::beginTransaction();
            $first = $audit->append($requests[0], null, 'held_open');
            DB::setDefaultConnection('audit_interleaving');
            foreach ([$requests[1], $requests[2]] as $independent) {
                // Actual canonical INSERT while request one's transaction remains open.
                $event = $audit->append($independent, null, 'overlapping');
                $committed = DeviceCommandRequest::query()->findOrFail($independent->id);
                $this->assertSame($event->id, $committed->audit_tail_event_id);
                $this->assertSame($existing ? 2 : 1, $committed->auditEvents()->count());
            }
            DB::setDefaultConnection($this->primary);
            DB::commit();
            $this->assertSame($first->id, $requests[0]->fresh()->audit_tail_event_id);
        }
    }

    public function test_simultaneous_first_appends_serialize_on_the_parent_and_retry_keeps_one_chain(): void
    {
        $request = DeviceCommandAuditFixture::request();
        $audit = app(DeviceCommandAuditService::class);
        DB::beginTransaction();
        $first = $audit->append($request, null, 'first');
        DB::setDefaultConnection('audit_interleaving');
        try {
            $audit->append($request, null, 'competing_first');
            $this->fail('A second first append must wait for the same request.');
        } catch (QueryException $error) {
            $this->assertSame(1205, (int) $error->errorInfo[1]);
        }
        DB::setDefaultConnection($this->primary);
        DB::commit();
        DB::setDefaultConnection('audit_interleaving');
        $second = $audit->append($request, null, 'retry');
        $this->assertSame($first->event_hash, $second->previous_hash);
        $this->assertSame(2, $request->auditEvents()->count());
    }

    public function test_current_tail_survives_an_older_repeatable_read_snapshot(): void
    {
        $request = DeviceCommandAuditFixture::request();
        $audit = app(DeviceCommandAuditService::class);
        DB::beginTransaction();
        $this->assertNull($request->fresh()->audit_tail_event_id);
        $this->assertSame(0, $request->auditEvents()->count());
        DB::setDefaultConnection('audit_interleaving');
        $first = $audit->append($request, null, 'committed_after_snapshot');
        DB::setDefaultConnection($this->primary);
        $this->assertNull($request->fresh()->audit_tail_event_id, 'The ordinary RR snapshot is demonstrably old.');
        $second = $audit->append($request, null, 'current_lock');
        $this->assertSame($first->event_hash, $second->previous_hash);
        DB::commit();
        $this->assertSame($second->id, $request->fresh()->audit_tail_event_id);
    }

    public function test_hash_bytes_terminal_state_signature_and_caller_are_unchanged_by_internal_tail_write(): void
    {
        // Existing storage uses second precision. Choose a representable instant
        // while checking the unchanged canonical hash's six-digit time format.
        $this->travelTo(now()->startOfSecond());
        $request = DeviceCommandAuditFixture::request(CommandStatus::Failed);
        $original = $request->getAttributes();
        $audit = app(DeviceCommandAuditService::class);
        $event = $audit->append($request, null, 'evidence_exported', ['z' => 2, 'a' => ['y' => 1, 'b' => 0]]);
        $expectedHash = hash('sha256', implode('|', [$request->command_uuid, '', '', 'evidence_exported', '{"a":{"b":0,"y":1},"z":2}', $event->occurred_at->utc()->format('Y-m-d\TH:i:s.u\Z')]));
        $this->assertSame($expectedHash, $event->event_hash);
        $this->assertSame($original, $request->getAttributes());
        $fresh = $request->fresh();
        foreach ($original as $key => $value) {
            if ($key !== 'audit_tail_event_id') {
                $this->assertSame($value, $fresh->getRawOriginal($key), $key);
            }
        }
        $this->assertTrue(app(DeviceCommandContractVerifier::class)->verify($fresh));
        $this->assertArrayNotHasKey('audit_tail_event_id', $fresh->toArray());
        $this->assertFalse($fresh->isFillable('audit_tail_event_id'));
    }

    public function test_insert_and_pointer_roll_back_on_failure_and_with_an_enclosing_transaction(): void
    {
        $request = DeviceCommandAuditFixture::request();
        $audit = app(DeviceCommandAuditService::class);
        $first = $audit->append($request, null, 'initial');
        $fail = true;
        DB::listen(function (QueryExecuted $query) use (&$fail): void {
            if ($fail && str_starts_with($query->sql, 'insert into `device_command_audit_events`')) {
                throw new \RuntimeException('Synthetic failure after event insert, before pointer write.');
            }
        });
        try {
            $audit->append($request, null, 'must_rollback');
            $this->fail('Injected failure must escape the transaction.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Synthetic failure', $error->getMessage());
        } finally {
            $fail = false;
        }
        $this->assertSame(1, $request->auditEvents()->count());
        $this->assertSame($first->id, $request->fresh()->audit_tail_event_id);
        DB::beginTransaction();
        $request->status = CommandStatus::Queued;
        $request->save();
        $audit->append($request, null, 'outer_rollback');
        DB::rollBack();
        $this->assertSame(CommandStatus::Ready, $request->fresh()->status);
        $this->assertSame(1, $request->auditEvents()->count());
        $this->assertSame($first->id, $request->fresh()->audit_tail_event_id);
    }

    public function test_model_injection_and_missing_or_foreign_tail_evidence_are_rejected(): void
    {
        $request = DeviceCommandAuditFixture::request();
        $other = DeviceCommandAuditFixture::request();
        $audit = app(DeviceCommandAuditService::class);
        $event = $audit->append($other, null, 'foreign_tail');
        foreach ([$event->id, $event->id + 1000000] as $tail) {
            DB::table('device_command_requests')->where('id', $request->id)->update(['audit_tail_event_id' => $tail]);
            try {
                $audit->append($request, null, 'rejected');
                $this->fail('Missing or foreign audit evidence must fail closed.');
            } catch (\UnexpectedValueException $error) {
                $this->assertStringContainsString('Audit tail evidence', $error->getMessage());
            }
            $this->assertSame(0, $request->auditEvents()->count());
        }
        $guarded = $other->fresh();
        $guarded->forceFill(['audit_tail_event_id' => null]);
        try {
            $guarded->save();
            $this->fail('Ordinary model clearing must be rejected.');
        } catch (\UnexpectedValueException) {
            $this->assertSame($event->id, $other->fresh()->audit_tail_event_id);
        }
        $injected = new DeviceCommandRequest;
        $injected->forceFill(['audit_tail_event_id' => null]);
        $this->expectException(\UnexpectedValueException::class);
        $injected->save();
    }

    public function test_external_request_inputs_reject_internal_pointer_even_when_null(): void
    {
        foreach ([
            new StoreDeviceCommandRequest,
            new StoreDeviceCommandBatchRequest,
            new StoreClientLocateRequest,
        ] as $form) {
            foreach ([null, 12] as $value) {
                $validator = Validator::make(['audit_tail_event_id' => $value], $form->rules());
                $this->assertTrue($validator->errors()->has('audit_tail_event_id'), $form::class);
            }
        }
    }
}
