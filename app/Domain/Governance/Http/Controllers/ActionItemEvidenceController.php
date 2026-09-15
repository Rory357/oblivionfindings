<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\ActionItemEvidence;
use App\Domain\Governance\Services\GovernanceAuditService;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proof that an action is done (audit P0-5): upload files to the action, list
 * them by name, download them through an authorised route, and remove a
 * mistaken upload before the action is marked as done.
 *
 * Files are stored on the private disk; the action policy decides who may add
 * (`update`) or open (`view`) them, so evidence never reaches anyone outside
 * the action's audience.
 */
class ActionItemEvidenceController extends Controller
{
    use ServesPrivateAttachments;

    /** Extensions accepted as evidence (checked against the file's real content). */
    public const ALLOWED_EXTENSIONS = 'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,webp,csv,txt';

    public const MAX_KILOBYTES = 20480;

    public function store(Request $request, ActionItem $action): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $action);

        if ($action->status === 'complete') {
            return $this->refuse($request, "This action is already done, so its evidence can't be changed.");
        }

        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => ['required', 'file', 'max:'.self::MAX_KILOBYTES, 'mimes:'.self::ALLOWED_EXTENSIONS],
        ], [
            'files.required' => 'Choose at least one file.',
            'files.min' => 'Choose at least one file.',
            'files.max' => 'Add up to 10 files at a time.',
            'files.*.required' => "One of the files didn't upload. Try again.",
            'files.*.file' => "One of the files didn't upload. Try again.",
            'files.*.uploaded' => "One of the files didn't upload. Try again.",
            'files.*.max' => 'Each file must be 20 MB or smaller.',
            'files.*.mimes' => 'Evidence must be a PDF, a Word, Excel or PowerPoint file, an image (JPG, PNG, GIF or WebP), or a CSV or text file. To use an email, save it as a PDF first.',
        ]);

        $stored = [];

        DB::transaction(function () use ($request, $action, &$stored) {
            foreach ($request->file('files') as $file) {
                $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
                $path = $file->storeAs(
                    "governance/actions/{$action->id}/evidence",
                    Str::uuid()->toString().'.'.$extension,
                    self::$PRIVATE_ATTACHMENT_DISK,
                );

                $stored[] = ActionItemEvidence::create([
                    'action_item_id' => $action->id,
                    'disk' => self::$PRIVATE_ATTACHMENT_DISK,
                    'path' => $path,
                    'original_name' => Str::limit($this->safeName($file->getClientOriginalName()), 250, ''),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => (int) $file->getSize(),
                    'uploaded_by' => $request->user()->id,
                ]);
            }
        });

        GovernanceAuditService::log('action.evidence_added', 'ActionItem', (int) $action->id, [
            'count' => count($stored),
        ]);

        $message = count($stored) === 1 ? 'Evidence added.' : count($stored).' files added as evidence.';

        return $request->wantsJson()
            ? response()->json(['message' => $message, 'evidence' => $this->presentEvidence($action, $request)])
            : redirect()->back()->with('success', $message);
    }

    public function destroy(Request $request, ActionItem $action, ActionItemEvidence $evidence): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $action);
        $this->ensureBelongs($action, $evidence);

        if ($action->status === 'complete') {
            return $this->refuse($request, "This action is already done, so its evidence can't be changed.");
        }

        $user = $request->user();
        if ((int) $evidence->uploaded_by !== (int) $user->id && ! $user->canDo('governance.actions.manage')) {
            abort(403, 'Only the person who added this file, or someone who manages actions, can remove it.');
        }

        $name = $evidence->original_name;
        $disk = $evidence->disk ?: self::$PRIVATE_ATTACHMENT_DISK;
        $path = $evidence->path;

        $evidence->delete();

        if ($path !== '' && Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }

        GovernanceAuditService::log('action.evidence_removed', 'ActionItem', (int) $action->id, [
            'original_name' => $name,
        ]);

        return $request->wantsJson()
            ? response()->json(['message' => 'File removed.', 'evidence' => $this->presentEvidence($action, $request)])
            : redirect()->back()->with('success', 'File removed.');
    }

    public function download(ActionItem $action, ActionItemEvidence $evidence): StreamedResponse
    {
        $this->authorize('view', $action);
        $this->ensureBelongs($action, $evidence);

        return $this->streamPrivateAttachment(
            $evidence->disk,
            $evidence->path,
            $evidence->original_name,
            $evidence->mime_type,
        );
    }

    /**
     * Evidence recorded before uploads existed (typed managed paths). Served by
     * position so the path itself is never exposed to the page.
     */
    public function downloadEarlier(ActionItem $action, int $index): StreamedResponse
    {
        $this->authorize('view', $action);

        $paths = is_array($action->evidence_attachments) ? array_values($action->evidence_attachments) : [];
        $entry = $paths[$index] ?? null;
        $path = is_array($entry) ? (string) ($entry['path'] ?? $entry['file_path'] ?? '') : (string) $entry;

        abort_if(trim($path) === '', 404, "That file couldn't be found.");

        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return $this->streamPrivateAttachment($disk, $path, basename($path));
            }
        }

        abort(404, "That file couldn't be found.");
    }

    /** @return array<int, array<string, mixed>> */
    private function presentEvidence(ActionItem $action, Request $request): array
    {
        $user = $request->user();
        $canManage = $user->canDo('governance.actions.manage');

        return $action->evidence()
            ->with('uploadedBy:id,name')
            ->get()
            ->map(fn (ActionItemEvidence $evidence) => [
                ...$evidence->present(),
                'can_remove' => $action->status !== 'complete'
                    && ($canManage || (int) $evidence->uploaded_by === (int) $user->id),
            ])
            ->values()
            ->all();
    }

    private function ensureBelongs(ActionItem $action, ActionItemEvidence $evidence): void
    {
        abort_unless((int) $evidence->action_item_id === (int) $action->id, 404, "That file couldn't be found.");
    }

    private function refuse(Request $request, string $message): RedirectResponse|JsonResponse
    {
        return $request->wantsJson()
            ? response()->json(['message' => $message], 422)
            : redirect()->back()->with('error', $message);
    }

    /** Keep the visible file name, but drop any directory parts or control characters. */
    private function safeName(string $name): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $name))));

        return $clean !== '' ? $clean : 'evidence';
    }
}
