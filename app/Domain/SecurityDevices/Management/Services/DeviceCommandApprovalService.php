<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointRemoteAccess;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptAuthority;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptContract;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptIdentity;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptPolicy;
use App\Domain\SecurityDevices\Management\Data\CommandDecisionInput;
use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Enums\ManagementLevel;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandApproval;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class DeviceCommandApprovalService
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceManagementAuthorizationService $authorization,
        private readonly CommandCapabilityRegistry $capabilities,
        private readonly CommandChangeEligibilityService $changeEligibility,
        private readonly DeviceCommandAuditService $audit,
    ) {}

    public function decide(
        DeviceCommandRequest $request,
        User $approver,
        CommandDecisionInput $input,
    ): DeviceCommandRequest {
        if ($request->capability === EndpointScriptContract::CAPABILITY) {
            abort_unless($input->expectedActorId === (int) $approver->id, 409);

            return DB::transaction(function () use ($request, $approver, $input): DeviceCommandRequest {
                $locked = DeviceCommandRequest::query()->whereKey($request->id)->lock('for update nowait')->firstOrFail();
                $locked->setRelation('device', Device::query()->whereKey($locked->device_id)->lock('for update nowait')->firstOrFail());
                $decide = function () use ($locked, $approver, $input): DeviceCommandRequest {
                    app(AuthorizationEvidenceLockService::class)->lockForUsersWithoutWaiting([(int) $approver->id], ['*']);
                    $current = app(EndpointRemoteAccess::class)->actor((int) $approver->id, $locked->device, 'securityDevices.commands.approve');
                    abort_unless($current->two_factor_confirmed_at && app(EndpointScriptIdentity::class)->confirmed($current), 403);

                    return $this->decideCanonical($locked, $current, $input);
                };
                // Rejection must remain possible after publication/runtime withdrawal.
                if ($input->decision === CommandApprovalDecision::Rejected) {
                    return $decide();
                }
                if (! app(EndpointScriptPolicy::class)->available()) {
                    throw ValidationException::withMessages(['command' => 'Reviewed script execution is not available.']);
                }
                $requester = User::query()->findOrFail($locked->requested_by_user_id);

                return app(EndpointScriptAuthority::class)->withBinding($locked->device, $requester, $locked->encrypted_parameters,
                    function (User $current, Device $device, array $binding) use ($locked, $decide): DeviceCommandRequest {
                        app(EndpointScriptAuthority::class)->assertBinding($locked, $binding);

                        return $decide();
                    });
            });
        }

        return $this->decideCanonical($request, $approver, $input);
    }

    private function decideCanonical(
        DeviceCommandRequest $request,
        User $approver,
        CommandDecisionInput $input,
    ): DeviceCommandRequest {
        abort_unless($approver->canDo('securityDevices.devices.view'), 403);
        abort_unless($approver->canDo('securityDevices.commands.approve'), 403);
        $this->access->assertCanViewDevice($approver, $request->device);
        $capability = $this->capabilities->definition($request->capability);
        $authorization = $this->authorization->evaluate(
            $approver,
            $request->device,
            $capability,
            ManagementLevel::Observe,
            true,
        );
        abort_unless($authorization->allowed, $authorization->concealed ? 404 : 403);

        $comment = trim($input->comment);
        Validator::make(['comment' => $comment], [
            'comment' => ['required', 'string', 'min:10', 'max:1000'],
        ])->validate();

        if ((int) $request->requested_by_user_id === (int) $approver->id) {
            throw ValidationException::withMessages([
                'decision' => 'The requester cannot approve or reject their own device command.',
            ]);
        }

        $expired = false;
        $result = DB::transaction(function () use ($request, $approver, $input, $comment, &$expired): DeviceCommandRequest {
            $locked = DeviceCommandRequest::query()
                ->with(['device', 'requestedBy'])
                ->lockForUpdate()
                ->findOrFail($request->id);
            $now = CarbonImmutable::now('UTC')->startOfSecond();

            if ($locked->expires_at->lessThanOrEqualTo($now)) {
                if (! $locked->status->isTerminal()) {
                    $locked->status = CommandStatus::Expired;
                    $locked->save();
                    $this->audit->append($locked, $approver, 'expired_before_decision', [
                        'status' => CommandStatus::Expired->value,
                    ]);
                }
                $expired = true;

                return $locked;
            }

            if ($locked->status !== CommandStatus::AwaitingApproval) {
                throw ValidationException::withMessages([
                    'decision' => 'This command is not awaiting an approval decision.',
                ]);
            }

            $capability = $this->capabilities->definition($locked->capability);
            $authorization = $this->authorization->evaluate(
                $approver,
                $locked->device,
                $capability,
                ManagementLevel::Observe,
                true,
            );
            abort_unless($authorization->allowed, $authorization->concealed ? 404 : 403);
            if ($capability->requiresChange || ($locked->capability === EndpointScriptContract::CAPABILITY
                && $locked->it_change_id !== null && $input->decision === CommandApprovalDecision::Approved)) {
                $changeCanBeReviewed = $locked->it_change_id !== null
                    && $locked->requestedBy !== null
                    && $this->changeEligibility->isEligible(
                        (int) $locked->it_change_id,
                        $locked->requestedBy,
                        $locked->device,
                        (int) $locked->site_id,
                        $now,
                    )
                    && $this->changeEligibility->isEligible(
                        (int) $locked->it_change_id,
                        $approver,
                        $locked->device,
                        (int) $locked->site_id,
                        $now,
                    );
                if (! $changeCanBeReviewed) {
                    throw ValidationException::withMessages([
                        'decision' => 'The linked IT Change is not currently eligible or visible to both requester and reviewer.',
                    ]);
                }
            }

            DeviceCommandApproval::query()->create([
                'device_command_request_id' => $locked->id,
                'decided_by_user_id' => $approver->id,
                'decision' => $input->decision,
                'comment' => $comment,
                'decided_at' => $now,
            ]);

            if ($input->decision === CommandApprovalDecision::Rejected) {
                $locked->status = CommandStatus::Rejected;
                $locked->rejected_at = $now;
                $locked->safe_failure_reason = 'Rejected by an independent reviewer.';
            } else {
                $locked->approved_by_user_id = $approver->id;
                $locked->approved_at = $now;
                $locked->status = ! $this->stepUpIsCurrent($locked, $now)
                    ? CommandStatus::AwaitingStepUp
                    : CommandStatus::Ready;
            }
            $locked->save();
            $this->audit->append($locked, $approver, $input->decision->value, [
                'decision' => $input->decision->value,
                'status' => $locked->status->value,
            ]);

            return $locked;
        });

        if ($expired) {
            throw ValidationException::withMessages([
                'decision' => 'This command expired before the approval decision and was not executed.',
            ]);
        }

        return $result;
    }

    private function stepUpIsCurrent(DeviceCommandRequest $request, CarbonImmutable $now): bool
    {
        $capability = $this->capabilities->definition($request->capability);
        if (! $capability->requiresStepUp) {
            return true;
        }
        if ($request->step_up_confirmed_at === null || $request->step_up_confirmed_at->isFuture()) {
            return false;
        }

        $maxAge = max(60, (int) config('security_devices.step_up_max_age_seconds', 900));

        return $request->step_up_confirmed_at->greaterThanOrEqualTo($now->subSeconds($maxAge));
    }
}
