<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StoreClientLocateRequest;
use App\Models\Client;
use App\Services\Tracking\ClientLocationAccessService;
use App\Services\Tracking\ClientLocationEvidenceBusy;
use App\Services\Tracking\ClientLocationLocateService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ClientLocationLocateController extends Controller
{
    public function index(Request $request, Client $client, ClientLocationLocateService $locate)
    {
        return $this->respond(fn () => $locate->availability($request->user(), $client, $this->fingerprint($request)));
    }

    public function store(StoreClientLocateRequest $request, Client $client, ClientLocationLocateService $locate)
    {
        return $this->respond(fn () => $locate->request($request->user(), $client, $request->validated(), $this->confirmedAt($request)), 201);
    }

    public function show(Request $request, Client $client, string $command, ClientLocationLocateService $locate)
    {
        return $this->respond(fn () => $locate->status($request->user(), $client, $command, $this->fingerprint($request)));
    }

    public function resume(Request $request, Client $client, string $command, ClientLocationLocateService $locate)
    {
        return $this->respond(function () use ($request, $client, $command, $locate) {
            $request->validate(array_fill_keys(['reason', 'idempotency_key', 'step_up_confirmed_at', 'origin_context',
                'device_id', 'client_id', 'capability', 'parameters', 'actor_id', 'site_id', 'break_glass', 'it_change_id', 'audit_tail_event_id'], ['missing']));

            return $locate->resume($request->user(), $client, $command, $this->fingerprint($request), $this->confirmedAt($request));
        });
    }

    public function confirmIdentity(Request $request, Client $client, string $command, ClientLocationLocateService $locate)
    {
        $locate->status($request->user(), $client, $command, $this->fingerprint($request));
        // A fixed local destination; the URL never accepts a caller-supplied redirect.
        $request->session()->put('url.intended', '/operations/clients/'.$client->id.'?tab=location&locate='.$command);

        return redirect()->route('password.confirm')->withHeaders(ClientLocationAccessService::headers());
    }

    private function confirmedAt(Request $request): ?CarbonImmutable
    {
        $timestamp = $request->session()->get('auth.password_confirmed_at');

        return is_numeric($timestamp) ? CarbonImmutable::createFromTimestampUTC((int) $timestamp) : null;
    }

    private function fingerprint(Request $request): string
    {
        return $request->validate(['access_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']])['access_fingerprint'];
    }

    private function respond(callable $read, int $status = 200)
    {
        try {
            $body = $read();
        } catch (ClientLocationEvidenceBusy $error) {
            // Services have unwound their owning transaction before this catch.
            return response()->json(['message' => 'Access is being updated. Retry the same request in a moment.'], 503)
                ->withHeaders([...ClientLocationAccessService::headers(), 'Retry-After' => '3']);
        } catch (ValidationException $error) {
            $body = ['message' => 'Please review this request.', 'errors' => $error->errors()];
            $status = 422;
        } catch (AuthorizationException|ModelNotFoundException|HttpExceptionInterface $error) {
            $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode()
                : ($error instanceof ModelNotFoundException ? 404 : 403);
            $body = ['message' => 'This location request is no longer available. Reload the client to check current access.'];
        }

        return response()->json($body, $status)->withHeaders(ClientLocationAccessService::headers());
    }
}
