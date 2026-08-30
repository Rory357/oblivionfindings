<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Jobs\GenerateBoardPack;
use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Services\BoardPackAccessService;
use App\Domain\Governance\Services\BoardPackBuilderService;
use App\Domain\Governance\Services\GovernanceAuditService;
use App\Domain\Governance\Support\BoardPackPresenter;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class BoardPackController extends Controller
{
    public function __construct(
        protected BoardPackBuilderService $packService,
        protected BoardPackPresenter $presenter,
        protected BoardPackAccessService $access,
    ) {}

    public function index(Request $request)
    {
        $viewer = $request->user();
        $canManage = $this->access->canManage($viewer);
        $visibleQuery = $this->access->visibleQuery($viewer);

        $query = (clone $visibleQuery)
            ->with(['meeting:id,title,scheduled_at,meeting_type', 'generatedBy:id,name'])
            ->latest('id');

        if ($status = $request->string('status')->toString()) {
            if ($status === 'distributed') {
                $query->whereNotNull('distributed_at');
            } elseif ($status === 'draft') {
                $query->whereNull('distributed_at');
            }
        }

        $packs = $query->paginate(25)->withQueryString();

        $meetingsWithoutPack = [];
        if ($canManage) {
            // Only pack managers use the generation dialog or need draft meeting metadata.
            $meetingsWithoutPack = GovernanceMeeting::query()
                ->whereDoesntHave('boardPack')
                ->whereNotIn('status', ['cancelled', 'archived'])
                ->orderBy('scheduled_at')
                ->withCount('agendaItems')
                ->get(['id', 'title', 'scheduled_at', 'status'])
                ->map(fn (GovernanceMeeting $meeting) => [
                    'id' => $meeting->id,
                    'title' => $meeting->title,
                    'scheduled_at' => $meeting->scheduled_at?->toIso8601String(),
                    'status' => $meeting->status,
                    'agenda_items_count' => (int) ($meeting->agenda_items_count ?? 0),
                ])
                ->values()
                ->all();
        }

        return Inertia::render('Governance/Packs/Index', [
            'packs' => [
                'data' => collect($packs->items())
                    ->map(fn (BoardPack $pack) => $this->presentIndexPack($pack))
                    ->all(),
                'links' => $packs->linkCollection()->toArray(),
                'current_page' => $packs->currentPage(),
                'last_page' => $packs->lastPage(),
                'total' => $packs->total(),
            ],
            'filters' => [
                'status' => $request->string('status')->toString() ?: null,
            ],
            'summary' => [
                'total' => (clone $visibleQuery)->count(),
                'distributed' => (clone $visibleQuery)->whereNotNull('distributed_at')->count(),
                'draft' => (clone $visibleQuery)->whereNull('distributed_at')->count(),
            ],
            'meetings_without_pack' => $meetingsWithoutPack,
        ]);
    }

    public function show(Request $request, BoardPack $pack)
    {
        $this->access->concealUnlessVisible($request->user(), $pack);
        $pack->load(['meeting', 'snapshot', 'generatedBy']);
        $presented = $this->presenter->present($pack);

        return Inertia::render('Governance/Packs/Show', [
            'pack' => $this->presentShowPack($pack),
            'is_distributed' => $pack->isDistributed(),
            'can_mark_read' => $this->access->recipientBoardMemberId($request->user(), $pack) !== null,
            'read_count' => $pack->readCount(),
            'download_count' => $pack->downloadCount(),
            'manifestSections' => $presented['manifestSections'],
            'contentSections' => $presented['contentSections'],
            'distributionStats' => $presented['distributionStats'],
            'supplementaryAttachments' => $this->presentSupplementaryAttachments($pack),
        ]);
    }

    public function generate(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('generatePack', $meeting);

        // If a pack already exists, regenerate it instead
        $existingPack = $meeting->boardPack;
        if ($existingPack) {
            return $this->regenerateForMeeting($request, $meeting, $existingPack);
        }

        $runInline = app()->environment('local')
            || config('queue.default') === 'sync'
            || $request->boolean('sync');

        try {
            if ($runInline) {
                $pack = $this->packService->build($meeting);
                if ($request->expectsJson()) {
                    return response()->json([
                        'status' => 'generated',
                        'pack_id' => $pack->id,
                    ]);
                }

                return redirect()->route('governance.packs.show', $pack)
                    ->with('success', 'Board pack generated.');
            }

            // Dispatch async job for generation
            GenerateBoardPack::dispatch($meeting->id);

            if ($request->expectsJson()) {
                return response()->json(['status' => 'queued']);
            }

            return redirect()->route('governance.meetings.show', $meeting)
                ->with('success', 'Board pack generation started. You will be notified when complete.');
        } catch (\Throwable $e) {
            Log::error('Board pack generation failed', [
                'meeting_id' => $meeting->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Board pack generation failed: '.$e->getMessage(),
                ], 500);
            }

            return redirect()->back()->with('error', 'Board pack generation failed: '.$e->getMessage());
        }
    }

    protected function regenerateForMeeting(Request $request, GovernanceMeeting $meeting, BoardPack $existingPack)
    {
        try {
            $newPack = $this->packService->regenerate($existingPack);

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'generated',
                    'pack_id' => $newPack->id,
                ]);
            }

            return redirect()->route('governance.packs.show', $newPack)
                ->with('success', 'Board pack regenerated.');
        } catch (\Throwable $e) {
            Log::error('Board pack regeneration failed', [
                'meeting_id' => $meeting->id,
                'error' => $e->getMessage(),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Board pack regeneration failed: '.$e->getMessage(),
                ], 500);
            }

            return redirect()->back()->with('error', 'Board pack regeneration failed: '.$e->getMessage());
        }
    }

    public function distribute(Request $request, BoardPack $pack)
    {
        $validated = $request->validate([
            'board_member_ids' => 'nullable|array',
            'board_member_ids.*' => [
                'integer',
                Rule::exists('board_members', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->whereDate('term_start', '<=', today())
                    ->where(function ($term) {
                        $term->whereNull('term_end')
                            ->orWhereDate('term_end', '>=', today());
                    })),
            ],
        ]);

        $this->packService->distribute(
            $pack,
            $validated['board_member_ids'] ?? null
        );

        return redirect()->back()->with('success', 'Board pack distributed to members.');
    }

    public function download(Request $request, BoardPack $pack)
    {
        $viewer = $request->user();
        $this->access->concealUnlessVisible($viewer, $pack);

        // Never create a tracking event for a file that cannot be delivered.
        if (! $pack->file_path || ! Storage::exists($pack->file_path)) {
            abort(404, 'Pack file not found.');
        }

        $recipientBoardMemberId = $this->access->recipientBoardMemberId($viewer, $pack);

        if ($recipientBoardMemberId !== null) {
            $pack->recordDownload($recipientBoardMemberId);
        }
        GovernanceAuditService::log(
            'board_pack.downloaded',
            'BoardPack',
            $pack->id,
            [
                'board_member_id' => $recipientBoardMemberId,
                'user_id' => $viewer->id,
                'meeting_id' => $pack->governance_meeting_id,
                'managed_access' => $this->access->canManage($viewer),
            ]
        );

        return Storage::download($pack->file_path, basename($pack->file_path));
    }

    public function preview(GovernanceMeeting $meeting)
    {
        $this->authorize('distributePack', $meeting);

        $preview = $this->packService->preview($meeting);

        return response()->json($preview);
    }

    public function markAsRead(Request $request, BoardPack $pack)
    {
        $this->access->concealUnlessVisible($request->user(), $pack);
        $boardMemberId = $this->access->recipientBoardMemberId($request->user(), $pack);
        abort_unless($boardMemberId !== null, 404);

        $pack->recordRead($boardMemberId);

        return response()->json(['success' => true]);
    }

    public function regenerate(BoardPack $pack)
    {
        $this->authorize('distributePack', $pack->meeting);

        $newPack = $this->packService->regenerate($pack);

        return redirect()->route('governance.packs.show', $newPack)
            ->with('success', 'Board pack regenerated with fresh data.');
    }

    /**
     * Upload one or more supplementary documents and append them to the pack.
     * Auto-generated sections remain untouched; these files live alongside.
     */
    public function attachFiles(Request $request, BoardPack $pack)
    {
        // Route already gated by `governance.packs.manage`; no extra meeting-policy
        // check because attachments are not constrained by meeting status.

        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => [
                'required',
                'file',
                'max:20480', // 20 MB per file
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,webp,csv,txt,md',
            ],
        ]);

        $existing = is_array($pack->supplementary_attachments) ? $pack->supplementary_attachments : [];

        foreach ($request->file('files') as $file) {
            $directory = "governance/board-packs/{$pack->id}/supplementary";
            $extension = $file->getClientOriginalExtension() ?: $file->extension();
            $storedName = Str::uuid()->toString().($extension ? ".{$extension}" : '');
            $path = $file->storeAs($directory, $storedName, 'local');

            $existing[] = [
                'id' => Str::uuid()->toString(),
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'uploaded_at' => now()->toIso8601String(),
                'uploaded_by_id' => auth()->id(),
                'uploaded_by_name' => auth()->user()?->name,
            ];
        }

        $pack->update(['supplementary_attachments' => $existing]);

        GovernanceAuditService::log(
            'board_pack.attachment_added',
            'BoardPack',
            $pack->id,
            ['count' => count($request->file('files'))],
        );

        return $request->wantsJson()
            ? response()->json(['attachments' => $this->presentSupplementaryAttachments($pack->fresh())])
            : redirect()->back()->with('success', 'Document(s) added to the pack.');
    }

    /**
     * Remove a supplementary attachment (storage file + JSON entry).
     */
    public function deleteAttachment(Request $request, BoardPack $pack, string $attachment)
    {
        // Route already gated by `governance.packs.manage`.

        $existing = is_array($pack->supplementary_attachments) ? $pack->supplementary_attachments : [];
        $target = collect($existing)->firstWhere('id', $attachment);

        if (! $target) {
            abort(404, 'Attachment not found.');
        }

        if (isset($target['path']) && Storage::disk('local')->exists($target['path'])) {
            Storage::disk('local')->delete($target['path']);
        }

        $remaining = array_values(
            array_filter($existing, fn (array $row) => ($row['id'] ?? null) !== $attachment),
        );

        $pack->update(['supplementary_attachments' => $remaining]);

        GovernanceAuditService::log(
            'board_pack.attachment_removed',
            'BoardPack',
            $pack->id,
            ['attachment_id' => $attachment, 'original_name' => $target['original_name'] ?? null],
        );

        return $request->wantsJson()
            ? response()->json(['attachments' => $this->presentSupplementaryAttachments($pack->fresh())])
            : redirect()->back()->with('success', 'Attachment removed from the pack.');
    }

    /**
     * Stream a supplementary attachment back to the user.
     */
    public function downloadAttachment(Request $request, BoardPack $pack, string $attachment)
    {
        $viewer = $request->user();
        $this->access->concealUnlessVisible($viewer, $pack);

        $existing = is_array($pack->supplementary_attachments) ? $pack->supplementary_attachments : [];
        $target = collect($existing)->firstWhere('id', $attachment);

        if (! $target || empty($target['path']) || ! Storage::disk('local')->exists($target['path'])) {
            abort(404, 'Attachment not found.');
        }

        $recipientBoardMemberId = $this->access->recipientBoardMemberId($viewer, $pack);
        if ($recipientBoardMemberId !== null) {
            GovernanceAuditService::log(
                'board_pack.attachment_downloaded',
                'BoardPack',
                $pack->id,
                ['attachment_id' => $attachment, 'board_member_id' => $recipientBoardMemberId],
            );
        }

        return Storage::disk('local')->download(
            $target['path'],
            $target['original_name'] ?? 'attachment',
            ['Content-Type' => $target['mime_type'] ?? 'application/octet-stream'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function presentIndexPack(BoardPack $pack): array
    {
        return [
            'id' => (int) $pack->id,
            'meeting_id' => (int) $pack->governance_meeting_id,
            'meeting' => $pack->meeting ? [
                'id' => (int) $pack->meeting->id,
                'title' => $pack->meeting->title,
                'scheduled_at' => $pack->meeting->scheduled_at?->toIso8601String(),
                'meeting_type' => $pack->meeting->meeting_type,
            ] : null,
            'generatedBy' => $pack->generatedBy ? [
                'id' => (int) $pack->generatedBy->id,
                'name' => $pack->generatedBy->name,
            ] : null,
            'distributed_at' => $pack->distributed_at?->toIso8601String(),
            'created_at' => $pack->created_at?->toIso8601String(),
            'updated_at' => $pack->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentShowPack(BoardPack $pack): array
    {
        return [
            'id' => (int) $pack->id,
            'generated_at' => $pack->generated_at?->toIso8601String(),
            'distributed_at' => $pack->distributed_at?->toIso8601String(),
            'file_size' => $pack->file_size,
            'watermark_text' => $pack->watermark_text,
            'meeting' => [
                'title' => $pack->meeting->title,
                'scheduled_at' => $pack->meeting->scheduled_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Frontend-friendly view of the pack's supplementary attachments.
     *
     * @return array<int, array<string, mixed>>
     */
    public function presentSupplementaryAttachments(BoardPack $pack): array
    {
        $existing = is_array($pack->supplementary_attachments) ? $pack->supplementary_attachments : [];

        return collect($existing)->map(fn (array $row) => [
            'id' => $row['id'] ?? null,
            'original_name' => $row['original_name'] ?? 'attachment',
            'mime_type' => $row['mime_type'] ?? null,
            'size_bytes' => $row['size_bytes'] ?? null,
            'uploaded_at' => $row['uploaded_at'] ?? null,
            'uploaded_by_name' => $row['uploaded_by_name'] ?? null,
            'download_url' => isset($row['id'])
                ? "/governance/packs/{$pack->id}/attachments/{$row['id']}/download"
                : null,
        ])->all();
    }
}
