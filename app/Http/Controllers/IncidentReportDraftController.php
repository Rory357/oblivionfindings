<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Incidents\IncidentReportDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentReportDraftController extends Controller
{
    public function __construct(private readonly IncidentReportDraftService $drafts) {}

    public function show(Request $request, string $requestUuid): JsonResponse
    {
        $draft = $this->drafts->findOwned($this->actor($request), $requestUuid);
        abort_unless($draft, 404);

        return response()->json([
            'request_uuid' => $draft->request_uuid,
            'revision' => (int) $draft->revision,
            'saved_at' => $draft->saved_at?->toIso8601String(),
            'expires_at' => $draft->expires_at?->toIso8601String(),
            'draft' => $draft->encrypted_payload,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, string $requestUuid): JsonResponse
    {
        $actor = $this->actor($request);
        $canManageFollowups = $actor->canDo('incidents.followups.manage');
        $scope = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'mode' => ['required', 'in:incident,near_miss'],
            'entry_context' => ['required', 'in:incidents,health_safety,control_room'],
            'step_index' => ['required', 'integer', 'min:0', 'max:8'],
            'form' => ['required', 'array'],
            'form.client_id' => ['nullable', 'integer'],
            'form.site_id' => ['nullable', 'integer'],
            'form.shift_id' => ['nullable', 'integer'],
        ]);
        $this->drafts->assertWritableScope(
            $actor,
            $requestUuid,
            $scope['form'],
        );

        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'mode' => ['required', 'in:incident,near_miss'],
            'entry_context' => ['required', 'in:incidents,health_safety,control_room'],
            'step_index' => ['required', 'integer', 'min:0', 'max:8'],
            'form' => ['required', 'array'],
            'form.type' => ['nullable', 'string', 'max:120'],
            'form.client_id' => ['nullable', 'integer'],
            'form.site_id' => ['nullable', 'integer'],
            'form.shift_id' => ['nullable', 'integer'],
            'form.occurred_date' => ['nullable', 'date_format:Y-m-d'],
            'form.occurred_time' => ['nullable', 'date_format:H:i'],
            'form.description' => ['nullable', 'string', 'max:10000'],
            'form.severity' => ['nullable', 'in:low,medium,high,critical'],
            'form.potential_severity' => ['nullable', 'in:low,medium,high,critical'],
            'form.potential_consequence' => ['nullable', 'string', 'max:5000'],
            'form.hazard' => ['nullable', 'string', 'max:5000'],
            'form.immediate_action_taken' => ['nullable', 'string', 'max:5000'],
            'form.witnesses' => ['nullable', 'string', 'max:5000'],
            'form.harm_or_injury' => ['nullable', 'string', 'max:2000'],
            'form.consequence' => ['nullable', 'string', 'max:5000'],
            'form.is_notifiable' => ['sometimes', 'boolean'],
            'form.worksafe_reference' => ['nullable', 'string', 'max:255'],
            'form.worksafe_notification_status' => ['nullable', 'in:pending,notified,acknowledged'],
            'form.site_preserved' => ['sometimes', 'boolean'],
            'form.followups' => ['nullable', 'array', 'max:20'],
            'form.followups.*.notes' => ['nullable', 'string', 'max:5000'],
            'form.followups.*.assigned_to_user_id' => $canManageFollowups
                ? ['nullable', 'integer', 'exists:users,id']
                : ['prohibited'],
            'form.followups.*.due_at' => ['nullable', 'date_format:Y-m-d'],
            'form.stay' => ['sometimes', 'boolean'],
        ]);

        $draft = $this->drafts->save(
            $actor,
            $requestUuid,
            $data['mode'],
            $data['entry_context'],
            (int) $data['step_index'],
            $data['form'],
            (int) $data['expected_revision'],
        );

        return response()->json([
            'request_uuid' => $draft->request_uuid,
            'revision' => (int) $draft->revision,
            'saved_at' => $draft->saved_at?->toIso8601String(),
            'expires_at' => $draft->expires_at?->toIso8601String(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request, string $requestUuid): JsonResponse
    {
        $this->drafts->discardOwned($this->actor($request), $requestUuid);

        return response()->json(null, 204);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
