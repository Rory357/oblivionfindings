<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Tracking\ClientLocationAccessService;
use App\Services\Tracking\ClientLocationEvidenceBusy;
use App\Services\Tracking\ClientTrackerModeProfiles;
use App\Services\Tracking\ClientTrackerModeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ClientTrackerModeController extends Controller
{
    public function index(Request $request, Client $client, ClientTrackerModeService $modes)
    {
        return $this->respond(fn () => $modes->read($request->user(), $client, $this->fingerprint($request)));
    }

    public function store(Request $request, Client $client, ClientTrackerModeService $modes)
    {
        return $this->respond(function () use ($request, $client, $modes) {
            $data = $request->validate([
                'access_fingerprint' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
                'mode' => ['required', Rule::in(array_keys(ClientTrackerModeProfiles::MODES))],
                'profile_id' => ['required', 'integer', 'min:1'], 'it_change_id' => ['required', 'integer', 'min:1'],
                'reason' => ['required', 'string', 'min:10', 'max:1000'], 'idempotency_key' => ['required', 'uuid'],
                'impact_acknowledged' => ['required', 'accepted'],
                ...array_fill_keys(['device_id', 'client_id', 'site_id', 'actor_id', 'capability', 'parameters',
                    'origin_context', 'step_up_confirmed_at', 'break_glass', 'approved_by_user_id', 'audit_tail_event_id'], ['missing']),
            ]);

            return $modes->request($request->user(), $client, $data, $this->confirmedAt($request));
        });
    }

    public function resume(Request $request, Client $client, string $command, ClientTrackerModeService $modes)
    {
        return $this->respond(function () use ($request, $client, $command, $modes) {
            $request->validate(array_fill_keys(['mode', 'profile_id', 'it_change_id', 'reason', 'idempotency_key', 'origin_context',
                'step_up_confirmed_at', 'device_id', 'client_id', 'site_id', 'actor_id', 'capability', 'parameters', 'break_glass'], ['missing']));

            return $modes->resume($request->user(), $client, $command, $this->fingerprint($request), $this->confirmedAt($request));
        });
    }

    public function identity(Request $request, Client $client, string $command, ClientTrackerModeService $modes, ClientLocationAccessService $access)
    {
        $assignment = $access->recheck($request->user(), $client, $this->fingerprint($request), true);
        $modes->owned($request->user(), $client, $assignment, $command);
        $request->session()->put('url.intended', '/operations/clients/'.$client->id.'?tab=location&tracker-mode='.$command);

        return redirect()->route('password.confirm')->withHeaders(ClientLocationAccessService::headers());
    }

    private function fingerprint(Request $request): string
    {
        return $request->validate(['access_fingerprint' => ['required', 'regex:/^[a-f0-9]{64}$/D']])['access_fingerprint'];
    }

    private function confirmedAt(Request $request): ?CarbonImmutable
    {
        $value = $request->session()->get('auth.password_confirmed_at');

        return is_numeric($value) ? CarbonImmutable::createFromTimestampUTC((int) $value) : null;
    }

    private function respond(callable $action)
    {
        try {
            return response()->json($action())->withHeaders(ClientLocationAccessService::headers());
        } catch (ClientLocationEvidenceBusy $error) {
            return response()->json(['message' => 'Access is being updated. Retry the same request.'], 503)
                ->withHeaders([...ClientLocationAccessService::headers(), 'Retry-After' => '3']);
        } catch (ValidationException $error) {
            return response()->json(['message' => 'Review the tracker mode request.', 'errors' => $error->errors()], 422)
                ->withHeaders(ClientLocationAccessService::headers());
        }
    }
}
