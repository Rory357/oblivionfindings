<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Enums\ItTicketDraftPurpose;
use App\Domain\It\Exceptions\ItTicketDraftException;
use App\Domain\It\Services\ItTicketDraftAttachmentService;
use App\Domain\It\Services\ItTicketDraftService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ItTicketDraftController extends Controller
{
    public function __construct(private readonly ItTicketDraftService $drafts) {}

    public function context(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'purpose' => ['required', Rule::enum(ItTicketDraftPurpose::class)],
            'ticket_id' => ['nullable', 'integer', 'min:1'], 'request_uuid' => ['nullable', 'uuid'],
        ]);

        return response()->json(['draft' => $this->drafts->initialize($actor, ItTicketDraftPurpose::from($data['purpose']),
            isset($data['ticket_id']) ? (int) $data['ticket_id'] : null, $data['request_uuid'] ?? null)]);
    }

    public function show(Request $request, string $draftUuid): JsonResponse
    {
        return response()->json(['draft' => $this->drafts->inspect($this->actor($request), $draftUuid)]);
    }

    public function resume(Request $request, string $draftUuid): JsonResponse
    {
        return response()->json($this->drafts->resume($this->actor($request), $draftUuid));
    }

    public function update(Request $request, string $draftUuid): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'], 'fields' => ['present', 'array'],
            'step_index' => ['required', 'integer', 'between:0,20'],
            'base_ticket_version' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json(['draft' => $this->drafts->save($actor, $draftUuid, (int) $data['expected_revision'],
            $data['fields'], (int) $data['step_index'], isset($data['base_ticket_version']) ? (int) $data['base_ticket_version'] : null)]);
    }

    public function validateCandidate(Request $request, string $draftUuid): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'], 'candidate_uuid' => ['required', 'uuid'],
            'fields' => ['present', 'array'], 'step_index' => ['required', 'integer', 'between:0,20'],
            'base_ticket_version' => ['nullable', 'integer', 'min:1'],
            'bound_scopes' => ['sometimes', 'array', 'list', 'max:100'], 'bound_scopes.*' => ['array'],
        ]);

        return response()->json($this->drafts->validateCandidate($actor, $draftUuid, (int) $data['expected_revision'],
            $data['candidate_uuid'], $data['fields'], (int) $data['step_index'],
            isset($data['base_ticket_version']) ? (int) $data['base_ticket_version'] : null, $data['bound_scopes'] ?? []));
    }

    public function destroy(Request $request, string $draftUuid): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:0']]);

        return response()->json(['draft' => $this->drafts->discard($actor, $draftUuid, (int) $data['expected_revision'])]);
    }

    public function validateLocalCandidate(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'purpose' => ['required', Rule::enum(ItTicketDraftPurpose::class)],
            'ticket_id' => ['nullable', 'integer', 'min:1'], 'request_uuid' => ['nullable', 'uuid'],
            'memory_uuid' => ['required', 'uuid'], 'candidate_uuid' => ['required', 'uuid'],
            'fields' => ['present', 'array'], 'step_index' => ['required', 'integer', 'between:0,20'],
            'base_ticket_version' => ['nullable', 'integer', 'min:1'], 'bound_scopes' => ['sometimes', 'array', 'list', 'max:100'],
            'bound_scopes.*' => ['array'],
        ]);

        return response()->json($this->drafts->validateLocalCandidate($actor, ItTicketDraftPurpose::from($data['purpose']),
            isset($data['ticket_id']) ? (int) $data['ticket_id'] : null, $data['request_uuid'] ?? null,
            $data['memory_uuid'], $data['candidate_uuid'], $data['fields'], (int) $data['step_index'],
            isset($data['base_ticket_version']) ? (int) $data['base_ticket_version'] : null, $data['bound_scopes'] ?? []));
    }

    public function startNew(Request $request, string $draftUuid): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:0']]);

        return response()->json(['draft' => $this->drafts->startNew($actor, $draftUuid, (int) $data['expected_revision'])]);
    }

    public function upload(Request $request, string $draftUuid): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'], 'upload_uuid' => ['required', 'uuid'],
            'attachment' => ['required', 'file'],
        ]);

        return response()->json(app(ItTicketDraftAttachmentService::class)->upload(
            $actor, $draftUuid, (int) $data['expected_revision'], $data['upload_uuid'], $request->file('attachment'),
        ));
    }

    public function removeAttachment(Request $request, string $draftUuid, int $attachmentId): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:0']]);

        return response()->json(app(ItTicketDraftAttachmentService::class)->remove(
            $actor, $draftUuid, (int) $data['expected_revision'], $attachmentId,
        ));
    }

    /** A stale signed-in page may never upload its old actor's private buffer. */
    private function actor(Request $request): User
    {
        $data = $request->validate(['actor_user_id' => ['required', 'integer', 'min:1']]);
        if ((int) $data['actor_user_id'] !== (int) $request->user()->id) {
            throw new ItTicketDraftException('access_unavailable', 403, 'The signed-in account has changed. This draft cannot be used by this account.');
        }

        return $request->user();
    }
}
