<?php

namespace App\Domain\SecurityDevices\Endpoints\Http\Controllers;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptContract;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScripts;
use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;
use stdClass;

final class EndpointScriptCatalogueController extends Controller
{
    /**
     * Catalogue reads and writes lock rows with NOWAIT. When two people act
     * at once the loser gets MySQL 3572; answer that with a retryable 503
     * (as the script policy service already does) instead of a 500.
     */
    public function callAction($method, $parameters)
    {
        try {
            return parent::callAction($method, $parameters);
        } catch (QueryException $error) {
            if (! EndpointScripts::isLockContention($error)) {
                throw $error;
            }

            return response()->json(['message' => 'Another change to this script is being saved. Try again in a moment.'], 503)
                ->header('Retry-After', '2')
                ->header('Cache-Control', 'private, no-store')
                ->header('X-Content-Type-Options', 'nosniff');
        }
    }

    public function index(Request $request, EndpointScripts $scripts)
    {
        $query = $this->query($request, ['expected_actor_id', 'status', 'search', 'cursor'], false);
        $dto = $scripts->index($request->user(), $query);
        if (! $request->wantsJson() || $request->header('X-Inertia')) {
            // Script names, publishers and review notes must not come back
            // through the browser's Back button after someone signs out.
            Inertia::encryptHistory();
        }
        $response = $request->wantsJson() && ! $request->header('X-Inertia')
            ? response()->json($dto) : Inertia::render('security-devices/scripts/index', $dto)->toResponse($request);

        return $response->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function confirmIdentity(Request $request, EndpointScripts $scripts)
    {
        $query = $this->query($request, ['expected_actor_id']);
        $scripts->beginIdentity($request, $request->user(), $query['expected_actor_id']);

        return redirect()->route('password.confirm')->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $script, EndpointScripts $scripts): JsonResponse
    {
        $query = $this->query($request, ['expected_actor_id']);

        return $this->reply($scripts->show($request->user(), $script, $query['expected_actor_id']));
    }

    public function recovery(Request $request, string $request_key, EndpointScripts $scripts): JsonResponse
    {
        $query = $this->query($request, ['expected_actor_id']);

        return $this->reply($scripts->recoverUpload($request->user(), $request_key, $query['expected_actor_id']));
    }

    public function actionRecovery(Request $request, string $script, string $request_key, EndpointScripts $scripts): JsonResponse
    {
        $query = $this->query($request, ['expected_actor_id']);

        return $this->reply($scripts->recoverAction($request->user(), $script, $request_key, $query['expected_actor_id']));
    }

    public function material(Request $request, string $script, EndpointScripts $scripts): JsonResponse
    {
        $query = $this->query($request, ['expected_actor_id']);

        return $this->reply($scripts->material($request->user(), $script, $query['expected_actor_id']));
    }

    public function upload(Request $request, EndpointScripts $scripts): JsonResponse
    {
        $body = $this->body($request, 1900000);
        $keys = array_keys(get_object_vars($body));
        sort($keys);
        $expected = ['expected_actor_id', 'request_key', 'reason', 'metadata', 'review', 'artifact_base64', 'runtime_manifest_base64'];
        sort($expected);
        abort_unless($keys === $expected && $body->metadata instanceof stdClass && $body->review instanceof stdClass, 422);
        $artifact = $this->binary($body->artifact_base64, EndpointScriptContract::MAX_SCRIPT_BYTES);
        $runtime = $this->binary($body->runtime_manifest_base64, 1048576);

        return $this->reply($scripts->upload($request->user(),
            ['expected_actor_id' => $body->expected_actor_id, 'request_key' => $body->request_key, 'reason' => $body->reason],
            EndpointScriptContract::encode($body->metadata), EndpointScriptContract::encode($body->review), $artifact, $runtime));
    }

    public function submit(Request $request, string $script, EndpointScripts $scripts): JsonResponse
    {
        return $this->write($request, $script, 'submit', $scripts);
    }

    public function review(Request $request, string $script, EndpointScripts $scripts): JsonResponse
    {
        return $this->write($request, $script, 'review', $scripts);
    }

    public function withdraw(Request $request, string $script, EndpointScripts $scripts): JsonResponse
    {
        return $this->write($request, $script, 'withdraw', $scripts);
    }

    private function write(Request $request, string $uuid, string $action, EndpointScripts $scripts): JsonResponse
    {
        return $this->reply($scripts->transition($request->user(), $uuid, $action, get_object_vars($this->body($request, 8192))));
    }

    private function reply(array $dto): JsonResponse
    {
        return response()->json($dto)->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    private function binary(mixed $encoded, int $limit): string
    {
        abort_unless(is_string($encoded) && strlen($encoded) <= 4 * (int) ceil($limit / 3), 422);
        $bytes = base64_decode($encoded, true);
        abort_unless(is_string($bytes) && strlen($bytes) > 0 && strlen($bytes) <= $limit && base64_encode($bytes) === $encoded, 422);

        return $bytes;
    }

    private function body(Request $request, int $maximum): stdClass
    {
        abort_unless($request->isSecure(), 400);
        abort_unless((string) $request->server('QUERY_STRING', '') === '', 400);
        abort_unless(strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0])) === 'application/json', 415);
        try {
            return EndpointScriptContract::decode($request->getContent(), $maximum);
        } catch (InvalidArgumentException) {
            abort(422, 'Invalid script request.');
        }
    }

    /** Check raw keys before PHP's query parser can collapse duplicate intent. */
    private function query(Request $request, array $allowed, bool $actorRequired = true): array
    {
        abort_unless($request->isSecure() && $request->getContent() === '', 400);
        $raw = (string) $request->server('QUERY_STRING', '');
        abort_unless(strlen($raw) <= 4096, 400);
        $values = [];
        foreach ($raw === '' ? [] : explode('&', $raw) as $pair) {
            abort_unless($pair !== '' && str_contains($pair, '=') && ! preg_match('/%(?![0-9a-f]{2})/i', $pair), 400);
            [$key, $value] = explode('=', $pair, 2);
            $key = urldecode($key);
            $value = urldecode($value);
            abort_unless(in_array($key, $allowed, true) && ! array_key_exists($key, $values) && mb_check_encoding($value, 'UTF-8'), 400);
            $values[$key] = $value;
        }
        // The raw query is the closed, duplicate-checked source of intent.
        // TrimStrings/ConvertEmptyStringsToNull have already changed query()
        // values (including our generated search=), so do not compare them.
        if (isset($values['expected_actor_id'])) {
            abort_unless(preg_match('/\A[1-9][0-9]{0,17}\z/', $values['expected_actor_id']), 400);
            $values['expected_actor_id'] = (int) $values['expected_actor_id'];
        } else {
            abort_if($actorRequired, 400);
        }

        return $values;
    }
}
