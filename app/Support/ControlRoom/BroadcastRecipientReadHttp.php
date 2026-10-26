<?php

namespace App\Support\ControlRoom;

use App\Services\ControlRoom\BroadcastRecipientReadCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\AcceptHeader;

/** HTTP adaptation only. The command service owns every authority/effect check. */
final class BroadcastRecipientReadHttp
{
    public function __construct(private readonly BroadcastRecipientReadCommandService $commands) {}

    /** Null permits only the existing empty ordinary-notification request. */
    public function single(Request $request, string $notification): ?JsonResponse
    {
        return $this->post($request, 'single', $notification);
    }

    public function bulk(Request $request): ?JsonResponse
    {
        return $this->post($request, 'bulk');
    }

    /** Broadcast acknowledgement is unsupported; never dispatch a read here. */
    public function acknowledge(Request $request): ?JsonResponse
    {
        return $this->legacyEmpty($request)
            ? null
            : $this->json($this->commands->invalidRequest($request->user(), 'single'));
    }

    public function reconcile(Request $request, string $command): JsonResponse
    {
        try {
            if (! $this->acceptsJson($request)
                || $request->getContent() !== ''
                || $request->request->count() !== 0
                || $request->files->count() !== 0
                || ! BroadcastCommandInput::canonicalUuid($command)) {
                throw new InvalidArgumentException;
            }
            $expectedActor = BroadcastRecipientReadInput::reconcileActor(
                (string) $request->server('QUERY_STRING', ''),
            );
        } catch (InvalidArgumentException) {
            return $this->json($this->commands->invalidRequest($request->user(), 'reconcile'));
        }

        return $this->json($this->commands->reconcile($request->user(), $expectedActor, $command));
    }

    private function post(Request $request, string $operation, ?string $notification = null): ?JsonResponse
    {
        if ($this->legacyEmpty($request)) {
            return null;
        }

        try {
            if (! $this->acceptsJson($request)
                || strtolower(trim(explode(';', (string) $request->header('Content-Type'), 2)[0])) !== 'application/json'
                || $request->server('QUERY_STRING', '') !== ''
                || $request->query->count() !== 0
                || $request->files->count() !== 0
                || ($operation === 'single' && ! BroadcastCommandInput::canonicalUuid($notification))) {
                throw new InvalidArgumentException;
            }
            // Every nonempty body takes this strict path. Missing/forged protocol
            // markers, duplicate keys and malformed JSON cannot become legacy.
            $input = BroadcastRecipientReadInput::parse($request->getContent());
        } catch (InvalidArgumentException) {
            return $this->json($this->commands->invalidRequest($request->user(), $operation));
        }

        $result = $operation === 'single'
            ? $this->commands->readSingle($request->user(), $input->expectedActorId, $notification, $input->commandId)
            : $this->commands->readBatch($request->user(), $input->expectedActorId, $input->commandId);

        return $this->json($result);
    }

    private function legacyEmpty(Request $request): bool
    {
        // Existing Inertia ordinary actions send {}. This is an empty object,
        // not an incomplete typed command; no fields/arrays/query are allowed.
        $raw = $request->getContent();

        return $request->server('QUERY_STRING', '') === ''
            && $request->query->count() === 0
            && $request->request->count() === 0
            && $request->files->count() === 0
            && ($raw === '' || preg_match('/\A\s*\{\s*\}\s*\z/D', $raw) === 1);
    }

    private function acceptsJson(Request $request): bool
    {
        $item = AcceptHeader::fromString((string) $request->header('Accept'))->get('application/json');

        return $item !== null && $item->getQuality() > 0;
    }

    /** @param array{http_status:int,body:array} $result */
    private function json(array $result): JsonResponse
    {
        return response()->json($result['body'], $result['http_status'], [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
