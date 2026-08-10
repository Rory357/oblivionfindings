<?php

namespace App\Domain\SecurityDevices\Credentials\Http\Controllers;

use App\Domain\SecurityDevices\Credentials\Http\Requests\RotateCredentialReferenceRequest;
use App\Domain\SecurityDevices\Credentials\Http\Requests\StoreCredentialReferenceRequest;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Credentials\Services\CredentialReferenceManager;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class CredentialReferenceController extends Controller
{
    public function store(
        StoreCredentialReferenceRequest $request,
        CredentialReferenceManager $manager,
    ): RedirectResponse {
        try {
            $manager->register($request->user(), $request->validated());
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'reference_key' => 'That reference alias or secret-manager path is already registered.',
            ]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'reference_key' => $exception->getMessage(),
            ]);
        }

        return back()->with('success', 'Credential reference registered. Test it before use.');
    }

    public function rotate(
        RotateCredentialReferenceRequest $request,
        CredentialReference $credentialReference,
        CredentialReferenceManager $manager,
    ): RedirectResponse {
        try {
            $manager->rotate(
                $request->user(),
                $credentialReference,
                (string) $request->validated('secret_manager_reference'),
            );
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'secret_manager_reference' => 'That secret-manager path is already registered.',
            ]);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages([
                'secret_manager_reference' => $exception->getMessage(),
            ]);
        }

        return back()->with('success', 'Credential reference rotated and suspended until its test passes.');
    }

    public function test(
        Request $request,
        CredentialReference $credentialReference,
        CredentialReferenceManager $manager,
    ): RedirectResponse {
        abort_unless($request->user()?->canDo('securityDevices.commands.admin'), 403);
        try {
            $manager->test($request->user(), $credentialReference);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'credential_reference' => $exception->getMessage(),
            ]);
        }

        return back()->with('success', 'Credential reference test passed and the reference is active.');
    }

    public function revoke(
        Request $request,
        CredentialReference $credentialReference,
        CredentialReferenceManager $manager,
    ): RedirectResponse {
        abort_unless($request->user()?->canDo('securityDevices.commands.admin'), 403);
        $manager->revoke($request->user(), $credentialReference);

        return back()->with('success', 'Credential reference revoked. New leases are blocked.');
    }
}
