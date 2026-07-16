<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Http\Requests\HealthSafety\UploadHsCorrectiveActionEvidenceRequest;
use App\Models\HsAttachment;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Services\UserSiteAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class HsCorrectiveActionEvidenceController extends Controller
{
    use ServesPrivateAttachments;

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function store(
        UploadHsCorrectiveActionEvidenceRequest $request,
        HsEvent $event,
        HsCorrectiveAction $action,
    ): RedirectResponse {
        $event = $this->resolveEvent($request, $event);
        $path = null;

        try {
            DB::transaction(function () use (
                $request,
                $event,
                $action,
                &$path,
            ): void {
                $action = $this->lockAction($event, $action);
                $this->authorizeParticipant($request, $action);
                $this->assertEvidenceMutable($action);

                $file = $request->file('file');
                $path = $file->store(
                    "health-safety/corrective-actions/{$action->id}",
                    'private',
                );
                if (! is_string($path) || $path === '') {
                    throw new RuntimeException('Corrective-action evidence could not be stored.');
                }

                $description = trim((string) $request->validated('description', ''));
                $action->attachments()->create([
                    'uploaded_by' => $request->user()->id,
                    'original_name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'disk' => 'private',
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'description' => $description !== '' ? $description : null,
                ]);
            });
        } catch (Throwable $error) {
            if (is_string($path) && $path !== '') {
                $disk = Storage::disk('private');
                if ($disk->exists($path) && ! $disk->delete($path)) {
                    throw new RuntimeException(
                        'Corrective-action evidence cleanup failed after a persistence error.',
                        0,
                        $error,
                    );
                }
            }

            throw $error;
        }

        return back()->with('success', 'Completion evidence uploaded.');
    }

    public function download(
        Request $request,
        HsEvent $event,
        HsCorrectiveAction $action,
        HsAttachment $attachment,
    ): StreamedResponse {
        $event = $this->resolveEvent($request, $event);
        abort_unless((int) $action->hs_event_id === (int) $event->id, 404);
        $this->authorizeParticipant($request, $action);
        $this->assertAttachmentBelongsTo($action, $attachment);

        $response = $this->streamPrivateAttachment(
            $attachment->disk,
            $attachment->path,
            $attachment->original_name,
            $attachment->mime_type,
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    public function destroy(
        Request $request,
        HsEvent $event,
        HsCorrectiveAction $action,
        HsAttachment $attachment,
    ): RedirectResponse {
        $event = $this->resolveEvent($request, $event);
        $deletedFile = null;

        try {
            DB::transaction(function () use (
                $request,
                $event,
                $action,
                $attachment,
                &$deletedFile,
            ): void {
                $action = $this->lockAction($event, $action);
                $this->authorizeParticipant($request, $action);
                $this->assertEvidenceMutable($action);
                $attachment = HsAttachment::query()
                    ->whereKey($attachment->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertAttachmentBelongsTo($action, $attachment);
                $this->assertRemovalPreservesCompletedEvidence(
                    $action,
                    $attachment,
                );

                $disk = $attachment->disk ?: 'private';
                if ($attachment->path && Storage::disk($disk)->exists($attachment->path)) {
                    $deletedFile = [
                        'disk' => $disk,
                        'path' => $attachment->path,
                        'contents' => Storage::disk($disk)->get($attachment->path),
                    ];
                    if (! Storage::disk($disk)->delete($attachment->path)) {
                        throw new RuntimeException('Completion evidence could not be removed.');
                    }
                }
                $attachment->delete();
            });
        } catch (Throwable $error) {
            if (is_array($deletedFile)
                && ! Storage::disk($deletedFile['disk'])->exists($deletedFile['path'])) {
                if (! Storage::disk($deletedFile['disk'])->put(
                    $deletedFile['path'],
                    $deletedFile['contents'],
                )) {
                    throw new RuntimeException(
                        'Completion evidence restoration failed after a database error.',
                        0,
                        $error,
                    );
                }
            }

            throw $error;
        }

        return back()->with('success', 'Completion evidence removed.');
    }

    private function resolveEvent(
        Request $request,
        HsEvent $event,
    ): HsEvent {
        $query = HsEvent::query();
        $this->siteAccess->applyHsEventScope(
            $query,
            $request->user(),
            [],
        );

        return $query->findOrFail($event->id);
    }

    private function lockAction(
        HsEvent $event,
        HsCorrectiveAction $action,
    ): HsCorrectiveAction {
        $action = HsCorrectiveAction::query()
            ->whereKey($action->id)
            ->lockForUpdate()
            ->firstOrFail();
        abort_unless((int) $action->hs_event_id === (int) $event->id, 404);

        return $action;
    }

    private function authorizeParticipant(
        Request $request,
        HsCorrectiveAction $action,
    ): void {
        $user = $request->user();
        abort_unless(
            $user
                && ((int) $action->assigned_to_user_id === (int) $user->id
                    || $user->canDo('hazards.manage')),
            403,
        );
    }

    private function assertEvidenceMutable(HsCorrectiveAction $action): void
    {
        if ($action->acceptsEvidenceChanges()) {
            return;
        }

        throw ValidationException::withMessages([
            'evidence' => 'Evidence cannot be changed after this action is verified or closed.',
        ]);
    }

    private function assertAttachmentBelongsTo(
        HsCorrectiveAction $action,
        HsAttachment $attachment,
    ): void {
        abort_unless(
            (int) $attachment->attachable_id === (int) $action->id
                && $attachment->attachable_type === $action->getMorphClass(),
            404,
        );
    }

    private function assertRemovalPreservesCompletedEvidence(
        HsCorrectiveAction $action,
        HsAttachment $attachment,
    ): void {
        if ($action->status !== HsCorrectiveAction::STATUS_COMPLETED
            || filled($action->completion_notes)
            || ! empty($action->completion_evidence_paths)
            || $action->attachments()
                ->whereKeyNot($attachment->id)
                ->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'evidence' => 'Keep at least one completion note or file while this action awaits verification.',
        ]);
    }
}
