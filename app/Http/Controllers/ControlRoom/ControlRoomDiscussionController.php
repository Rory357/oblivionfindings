<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Concerns\RespondsToInertiaOrJson;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ControlRoom\Concerns\AuthorizesControlRoomAlertAccess;
use App\Models\ControlRoom\AlertDiscussion;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ControlRoomDiscussionController extends Controller
{
    use AuthorizesControlRoomAlertAccess;
    use RespondsToInertiaOrJson;

    /**
     * Return threaded discussions for an alert.
     */
    public function index(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $discussions = AlertDiscussion::where('alert_id', $alert->id)
            ->whereNull('parent_id')
            ->with(['user:id,name', 'replies.user:id,name'])
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn (AlertDiscussion $discussion) => [
                'id' => $discussion->id,
                'user_id' => $discussion->user_id,
                'user_name' => $discussion->user?->name,
                'content' => $discussion->content,
                'type' => $discussion->type,
                'is_internal' => $discussion->is_internal,
                'attachments' => $discussion->attachments,
                'mentions' => $discussion->mentions,
                'edited_at' => $discussion->edited_at?->toISOString(),
                'created_at' => $discussion->created_at?->toISOString(),
                'updated_at' => $discussion->updated_at?->toISOString(),
                'reply_count' => $discussion->replies->count(),
                'replies' => $discussion->replies->map(fn (AlertDiscussion $reply) => [
                    'id' => $reply->id,
                    'user_id' => $reply->user_id,
                    'user_name' => $reply->user?->name,
                    'content' => $reply->content,
                    'type' => $reply->type,
                    'is_internal' => $reply->is_internal,
                    'attachments' => $reply->attachments,
                    'mentions' => $reply->mentions,
                    'edited_at' => $reply->edited_at?->toISOString(),
                    'created_at' => $reply->created_at?->toISOString(),
                    'updated_at' => $reply->updated_at?->toISOString(),
                ])->values(),
            ]);

        return response()->json(['discussions' => $discussions]);
    }

    /**
     * Create a discussion entry for an alert.
     */
    public function store(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'type' => ['sometimes', 'string', 'in:comment,internal_note,status_update,escalation_note,resolution_note'],
            'is_internal' => ['sometimes', 'boolean'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('control_room_alert_discussions', 'id')
                    ->where(fn ($query) => $query->where('alert_id', $alert->id)),
            ],
            'mentions' => ['nullable', 'array'],
            'mentions.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);

        $this->assertCanUseMentionedStaff($user, $data['mentions'] ?? []);

        // Handle file attachments
        $attachments = [];
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $path = $file->store("discussions/{$alert->id}", 'local');
                $attachments[] = [
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ];
            }
        }

        $discussion = AlertDiscussion::create([
            'alert_id' => $alert->id,
            'user_id' => $user->id,
            'parent_id' => $data['parent_id'] ?? null,
            'content' => $data['content'],
            'type' => $data['type'] ?? 'comment',
            'is_internal' => $data['is_internal'] ?? true,
            'attachments' => ! empty($attachments) ? $attachments : null,
            'mentions' => $data['mentions'] ?? null,
        ]);

        AuditLogger::log('controlRoom.discussion.created', $alert, [
            'alert_id' => $alert->id,
            'discussion_id' => $discussion->id,
            'type' => $discussion->type,
        ]);

        if ($request->header('X-Inertia')) {
            return $this->inertiaOrJson($request, 'Comment posted.');
        }

        return response()->json(['discussion' => $discussion], 201);
    }

    /**
     * Edit a discussion entry (own only).
     */
    public function update(Request $request, AlertDiscussion $discussion)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->assertCanAccessAlert($user, $discussion->alert);
        abort_unless($discussion->user_id === $user->id, 403);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
        ]);

        $discussion->update([
            'content' => $data['content'],
            'edited_at' => now(),
        ]);

        AuditLogger::log('controlRoom.discussion.updated', $discussion->alert, [
            'alert_id' => $discussion->alert_id,
            'discussion_id' => $discussion->id,
        ]);

        if ($request->header('X-Inertia')) {
            return $this->inertiaOrJson($request, 'Comment updated.');
        }

        return response()->json(['discussion' => $discussion->fresh()]);
    }

    /**
     * Soft-delete a discussion (own or manager).
     */
    public function destroy(Request $request, AlertDiscussion $discussion)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->assertCanAccessAlert($user, $discussion->alert);
        $isOwner = $user && $discussion->user_id === $user->id;
        $canManage = $user && $user->canDo('controlRoom.alerts.manage');
        abort_unless($isOwner || $canManage, 403);

        $discussion->update([
            'content' => '[deleted]',
            'attachments' => null,
        ]);

        AuditLogger::log('controlRoom.discussion.deleted', $discussion->alert, [
            'alert_id' => $discussion->alert_id,
            'discussion_id' => $discussion->id,
        ]);

        if ($request->header('X-Inertia')) {
            return $this->inertiaOrJson($request, 'Comment deleted.');
        }

        return response()->json(['message' => 'Discussion deleted.']);
    }

    /**
     * @param  array<int, int|string>  $userIds
     */
    private function assertCanUseMentionedStaff(User $user, array $userIds): void
    {
        $uniqueUserIds = collect($userIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($uniqueUserIds === []) {
            return;
        }

        $query = $this->accessibleStaffQuery($user)
            ->whereIn('id', $uniqueUserIds);

        abort_if(
            $query->count() !== count($uniqueUserIds),
            403,
            'You are not authorized to mention one or more staff members for this alert.',
        );
    }
}
