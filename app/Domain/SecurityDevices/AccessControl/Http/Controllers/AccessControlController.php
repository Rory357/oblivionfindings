<?php

namespace App\Domain\SecurityDevices\AccessControl\Http\Controllers;

use App\Domain\SecurityDevices\AccessControl\Http\Requests\StoreAccessControlCredentialRequest;
use App\Domain\SecurityDevices\AccessControl\Http\Requests\StoreAccessControlScheduleRequest;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlCredential;
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
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'name' => 'A schedule with that name already exists at this Site.',
            ]);
        }

        return back()->with('success', 'Access schedule created.');
    }

    public function storeCredential(
        StoreAccessControlCredentialRequest $request,
        AccessControlLifecycleService $service,
    ): RedirectResponse {
        try {
            $service->issueCredential($request->user(), $request->validated());
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'reference_key' => 'That provider reference is already registered at this Site.',
            ]);
        }

        return back()->with('success', 'Physical access credential issued.');
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

        return back()->with('success', 'Physical access credential revoked.');
    }
}
