<?php

namespace App\Domain\SecurityDevices\AccessControl\Http\Controllers;

use App\Domain\SecurityDevices\AccessControl\Http\Requests\DeactivateAccessControlScheduleRequest;
use App\Domain\SecurityDevices\AccessControl\Http\Requests\StoreAccessControlCredentialRequest;
use App\Domain\SecurityDevices\AccessControl\Http\Requests\StoreAccessControlScheduleRequest;
use App\Domain\SecurityDevices\AccessControl\Http\Requests\UpdateAccessControlScheduleRequest;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlCredential;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlSchedule;
use App\Domain\SecurityDevices\AccessControl\Services\AccessControlLifecycleService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

class AccessControlController extends Controller
{
    public function storeSchedule(
        StoreAccessControlScheduleRequest $request,
        AccessControlLifecycleService $service,
    ): RedirectResponse {
        try {
            $service->createSchedule($request->user(), $request->validated());
        } catch (QueryException $exception) {
            if (! $this->isDuplicateScheduleName($exception)) {
                throw $exception;
            }
            throw ValidationException::withMessages([
                'name' => 'A schedule with that name already exists at this Site.',
            ]);
        }

        return back()->with('success', 'Schedule created in Oblivion Findings. Provider reconciliation is still required.');
    }

    public function storeCredential(
        StoreAccessControlCredentialRequest $request,
        AccessControlLifecycleService $service,
    ): RedirectResponse {
        try {
            $service->issueCredential($request->user(), $request->validated());
        } catch (QueryException $exception) {
            if (! $this->isDuplicateCredentialReference($exception)) {
                throw $exception;
            }
            throw ValidationException::withMessages([
                'reference_key' => 'That provider reference is already registered at this Site.',
            ]);
        }

        return back()->with('success', 'Credential issue requested. Access remains unconfirmed until provider reconciliation succeeds.');
    }

    public function updateSchedule(
        UpdateAccessControlScheduleRequest $request,
        AccessControlSchedule $accessSchedule,
        AccessControlLifecycleService $service,
    ): RedirectResponse {
        try {
            $service->updateSchedule($request->user(), $accessSchedule, $request->validated());
        } catch (QueryException $exception) {
            if (! $this->isDuplicateScheduleName($exception)) {
                throw $exception;
            }
            throw ValidationException::withMessages([
                'name' => 'A schedule with that name already exists at this Site.',
            ]);
        }

        return back()->with('success', 'Schedule updated in Oblivion Findings. Provider reconciliation is still required.');
    }

    public function deactivateSchedule(
        DeactivateAccessControlScheduleRequest $request,
        AccessControlSchedule $accessSchedule,
        AccessControlLifecycleService $service,
    ): RedirectResponse {
        $service->deactivateSchedule($request->user(), $accessSchedule, $request->validated());

        return back()->with('success', 'Schedule deactivated in Oblivion Findings. Provider reconciliation is still required.');
    }

    public function revoke(
        Request $request,
        AccessControlCredential $accessCredential,
        AccessControlLifecycleService $service,
    ): RedirectResponse {
        abort_unless($request->user()?->canDo('securityDevices.accessControl.manage'), 403);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $service->revokeCredential($request->user(), $accessCredential, $data['reason']);

        return back()->with('success', 'Credential revocation requested. Revocation remains unconfirmed until provider reconciliation succeeds.');
    }

    private function isDuplicateScheduleName(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'access_schedules_site_name_unique')
            || (str_contains($message, 'access_control_schedules.site_id')
                && str_contains($message, 'access_control_schedules.name'));
    }

    private function isDuplicateCredentialReference(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'access_credentials_site_reference_unique')
            || (str_contains($message, 'access_control_credentials.site_id')
                && str_contains($message, 'access_control_credentials.reference_key'));
    }
}
