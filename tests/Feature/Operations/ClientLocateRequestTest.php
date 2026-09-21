<?php

namespace Tests\Feature\Operations;

use App\Domain\SecurityDevices\Management\Data\ClientLocationCommandOrigin;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Http\Requests\StoreDeviceCommandBatchRequest;
use App\Domain\SecurityDevices\Management\Http\Requests\StoreDeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Jobs\DispatchDeviceCommand;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandContractVerifier;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandQueueService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ClientLocateFixture;
use Tests\TestCase;
use UnexpectedValueException;

class ClientLocateRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Notification::fake();
        Mail::fake();
    }

    public function test_signed_client_request_resumes_the_same_contract_after_real_step_up(): void
    {
        $fixture = ClientLocateFixture::make();
        $key = 'client-location:'.Str::uuid();
        $service = app(DeviceCommandRequestService::class);
        $input = fn ($confirmed) => new CommandRequestInput('tracking.location_refresh', [], 'Request the current location for this agreed check.', $key,
            stepUpConfirmedAt: $confirmed, originContext: $fixture['origin']);
        $command = $service->request($fixture['device'], $fixture['actor'], $input(null));
        $this->assertSame(CommandStatus::AwaitingStepUp, $command->status);
        $this->assertSame($fixture['origin']->toArray(), ClientLocationCommandOrigin::fromArray($command->origin_context)->toArray());
        $this->assertTrue(app(DeviceCommandContractVerifier::class)->verify($command));
        $this->assertArrayNotHasKey('origin_context', $command->toArray());
        $expiry = $command->expires_at->toISOString();
        $resumed = $service->request($fixture['device'], $fixture['actor'], $input(CarbonImmutable::now()));
        $this->assertSame($command->id, $resumed->id);
        $this->assertSame($expiry, $resumed->expires_at->toISOString());
        $this->assertSame(CommandStatus::Ready, $resumed->status);
        $queued = app(DeviceCommandQueueService::class)->queue($resumed, $fixture['actor']);
        $this->assertSame(CommandStatus::Queued, $queued->status);
        $replay = $service->request($fixture['device'], $fixture['actor'], $input(CarbonImmutable::now()));
        $this->assertSame($queued->id, $replay->id);
        $this->assertSame(CommandStatus::Queued, $replay->status);
        Queue::assertPushed(DispatchDeviceCommand::class, 1);
        Http::assertNothingSent();
    }

    public function test_removing_the_signed_context_cannot_downgrade_to_a_generic_request(): void
    {
        $fixture = ClientLocateFixture::make();
        $command = app(DeviceCommandRequestService::class)->request($fixture['device'], $fixture['actor'],
            new CommandRequestInput('tracking.location_refresh', [], 'Request the location for an agreed check.', 'client-location:'.Str::uuid(),
                stepUpConfirmedAt: CarbonImmutable::now(), originContext: $fixture['origin']));
        try {
            $command->origin_context = null;
            $command->save();
            $this->fail('The origin must be immutable.');
        } catch (UnexpectedValueException) {
            $this->assertTrue(true);
        }
        DB::table('device_command_requests')->where('id', $command->id)->update(['origin_context' => null]);
        $this->assertFalse(app(DeviceCommandContractVerifier::class)->verify($command->fresh()));
        try {
            app(DeviceCommandQueueService::class)->queue($command->fresh(), $fixture['actor']);
            $this->fail('A stripped context must not queue.');
        } catch (ValidationException) {
            $this->assertSame(CommandStatus::Blocked, $command->fresh()->status);
            $this->assertSame('signature_invalid', $command->fresh()->blocked_reason_code);
        }
        Queue::assertNothingPushed();
    }

    public function test_withdrawn_resident_consent_blocks_an_existing_ready_request(): void
    {
        $fixture = ClientLocateFixture::make();
        $command = app(DeviceCommandRequestService::class)->request($fixture['device'], $fixture['actor'],
            new CommandRequestInput('tracking.location_refresh', [], 'Request the location for an agreed check.', 'client-location:'.Str::uuid(),
                stepUpConfirmedAt: CarbonImmutable::now(), originContext: $fixture['origin']));
        $fixture['consent']->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
        try {
            app(DeviceCommandQueueService::class)->queue($command, $fixture['actor']);
            $this->fail('Withdrawn consent must prevent queueing.');
        } catch (ValidationException) {
            $this->assertSame('client_location_context_changed', $command->fresh()->blocked_reason_code);
            $this->assertSame(CommandStatus::Blocked, $command->fresh()->status);
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_generic_http_inputs_reject_origin_injection_even_when_null(): void
    {
        foreach ([new StoreDeviceCommandRequest, new StoreDeviceCommandBatchRequest] as $request) {
            foreach (['origin_context', 'origin', 'client_id', 'assignment_id', 'consent_id', 'access_fingerprint'] as $key) {
                $validation = Validator::make([$key => null], [$key => $request->rules()[$key]]);
                $this->assertTrue($validation->fails(), $request::class.' '.$key);
            }
        }
    }

    public function test_context_requires_exact_typed_versioned_shape(): void
    {
        $fixture = ClientLocateFixture::make();
        foreach ([['version' => 2], ['client_id' => (string) $fixture['client']->id], ['extra' => true], ['access_fingerprint' => 'missing']] as $changes) {
            try {
                ClientLocationCommandOrigin::fromArray(array_replace($fixture['origin']->toArray(), $changes));
                $this->fail('Malformed context was accepted.');
            } catch (UnexpectedValueException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_http_identity_resume_and_reopen_preserve_one_request_without_disclosing_contract_fields(): void
    {
        $f = ClientLocateFixture::make();
        $url = '/operations/clients/'.$f['client']->id.'/location/locate-requests';
        $data = ['reason' => 'Check the agreed pickup location.', 'idempotency_key' => (string) Str::uuid(), 'access_fingerprint' => $f['fingerprint']];
        $this->actingAs($f['actor'])->getJson($url.'?access_fingerprint='.$f['fingerprint'])->assertOk()->assertJsonPath('available', true);
        $first = $this->postJson($url, $data)->assertCreated()->assertJsonPath('request.status', 'awaiting_step_up');
        $id = $first->json('request.id');
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('origin_context', $first->getContent());
        $this->get($url.'/'.$id.'/confirm-identity?access_fingerprint='.$f['fingerprint'])->assertRedirect(route('password.confirm'));
        $this->assertSame('/operations/clients/'.$f['client']->id.'?tab=location&locate='.$id, session('url.intended'));
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->postJson($url.'/'.$id.'/resume', ['access_fingerprint' => $f['fingerprint']])->assertOk()->assertJsonPath('request.status', 'queued');
        $replay = $this->postJson($url, $data)->assertCreated()->assertJsonPath('request.id', $id);
        $this->assertSame($first->json('request.expires_at'), $replay->json('request.expires_at'));
        $this->getJson($url.'?access_fingerprint='.$f['fingerprint'])->assertOk()->assertJsonPath('request.id', $id);
        $this->postJson($url, [...$data, 'reason' => 'A different reason must not replace the signed reason.'])->assertUnprocessable();
        Queue::assertPushed(DispatchDeviceCommand::class, 1);
    }

    public function test_http_rejects_forged_inputs_and_stale_or_future_session_confirmation(): void
    {
        $f = ClientLocateFixture::make();
        $url = '/operations/clients/'.$f['client']->id.'/location/locate-requests';
        $data = ['reason' => 'Check the agreed pickup location.', 'idempotency_key' => (string) Str::uuid(), 'access_fingerprint' => $f['fingerprint']];
        $this->actingAs($f['actor'])->postJson($url, [...$data, 'step_up_confirmed_at' => now()->toISOString()])->assertUnprocessable();
        $this->postJson($url, [...$data, 'reason' => '  short  '])->assertUnprocessable();
        $this->postJson($url, [...$data, 'origin_context' => null])->assertUnprocessable();
        foreach ([now()->subHour()->timestamp, now()->addHour()->timestamp] as $timestamp) {
            $this->withSession(['auth.password_confirmed_at' => $timestamp])->postJson($url, [...$data, 'idempotency_key' => (string) Str::uuid()])
                ->assertCreated()->assertJsonPath('request.status', 'awaiting_step_up');
        }
        Queue::assertNothingPushed();
    }

    public function test_http_conceals_foreign_request_and_rechecks_withdrawal_and_assignment_change(): void
    {
        $f = ClientLocateFixture::make();
        $other = ClientLocateFixture::make();
        $url = '/operations/clients/'.$f['client']->id.'/location/locate-requests';
        $this->actingAs($f['actor']);
        $response = $this->postJson($url, ['reason' => 'Check the agreed pickup location.', 'idempotency_key' => (string) Str::uuid(), 'access_fingerprint' => $f['fingerprint']])->assertCreated();
        $status = $url.'/'.$response->json('request.id').'?access_fingerprint='.$f['fingerprint'];
        $this->actingAs($other['actor'])->getJson($status)->assertForbidden();
        $this->getJson('/operations/clients/'.$other['client']->id.'/location/locate-requests/'.$response->json('request.id').'?access_fingerprint='.$other['fingerprint'])->assertNotFound();
        $this->actingAs($f['actor']);
        $f['assignment']->update(['retention_days' => 5]);
        $this->getJson($status)->assertForbidden();
        $f['consent']->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
        $this->getJson($status)->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_unavailable_tracker_does_not_create_a_command_and_legacy_html_redirect_is_preserved(): void
    {
        $f = ClientLocateFixture::make();
        $f['device']->update(['config' => ['management' => ['capabilities' => []]]]);
        $url = '/operations/clients/'.$f['client']->id.'/location/locate-requests';
        $this->actingAs($f['actor'])->getJson($url.'?access_fingerprint='.$f['fingerprint'])->assertOk()->assertJsonPath('available', false);
        $this->postJson($url, ['reason' => 'Check the agreed pickup location.', 'idempotency_key' => (string) Str::uuid(), 'access_fingerprint' => $f['fingerprint']])->assertUnprocessable();
        $this->post('/operations/clients/'.$f['client']->id.'/location/locate-now')->assertRedirect();
        Queue::assertNothingPushed();
    }
}
