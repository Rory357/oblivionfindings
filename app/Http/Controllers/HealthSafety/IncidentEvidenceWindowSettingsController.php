<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Services\Incidents\IncidentEvidenceWindowPolicySettings;
use App\Services\Incidents\IncidentRecordingReconstructionValues as Values;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** JSON settings transport; the canonical owner rechecks current actor and approved Site scope. */
final class IncidentEvidenceWindowSettingsController extends Controller
{
    public function __construct(private readonly IncidentEvidenceWindowPolicySettings $settings) {}

    public function index(Request $request): JsonResponse
    {
        $this->queryKeys($request, ['window_policy_page']);
        $page = $request->query('window_policy_page', '1');
        $page = $this->decimal($page, 'window_policy_page', 1000000);
        return $this->json($this->settings->editor($request->user(), $page)
            + ['evidence_window_viewer_id' => (int) $request->user()->id]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->queryKeys($request, []);
        abort_unless($request->isJson(), 415);
        // Closed command shape, typed actor/revision and audit stay exclusively with the owner.
        $receipt = $this->settings->save($request->user(), $request->json()->all());
        return $this->json(['receipt' => $receipt, 'evidence_window_viewer_id' => (int) $request->user()->id]);
    }

    public function recover(Request $request, string $idempotency_key): JsonResponse
    {
        $this->queryKeys($request, ['expected_actor_id']);
        $actor = $this->decimal($request->query('expected_actor_id'), 'expected_actor_id', PHP_INT_MAX);
        $key = Values::uuid($idempotency_key, 'idempotency_key');
        return $this->json(['receipt' => $this->settings->recover($request->user(), $actor, $key),
            'evidence_window_viewer_id' => (int) $request->user()->id]);
    }

    private function queryKeys(Request $request, array $allowed): void
    {
        // Error responses on these JSON endpoints also use the normal JSON exception renderer.
        $request->headers->set('Accept', 'application/json');
        if (array_diff(array_keys($request->query()), $allowed) !== []) {
            Values::fail('query', 'Use only the supported settings query fields.');
        }
    }

    private function decimal(mixed $value, string $field, int $max): int
    {
        if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1
            || strlen($value) > strlen((string) $max)
            || (strlen($value) === strlen((string) $max) && strcmp($value, (string) $max) > 0)) {
            Values::fail($field, 'Use a canonical positive whole number in the supported range.');
        }
        return (int) $value;
    }

    private function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}