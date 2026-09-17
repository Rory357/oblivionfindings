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

        // What this viewer is reading: the latest version of each meeting's
        // pack they can open, whether they've confirmed reading it, and the
        // pack for the next meeting.
        $reading = $this->readingOverview($viewer, $visibleQuery);

        $query = (clone $visibleQuery)
            ->with(['meeting:id,title,scheduled_at,meeting_type', 'generatedBy:id,name'])
            ->latest('id');

        if ($status = $request->string('status')->toString()) {
            if ($status === 'distributed' || $status === 'current') {
                $query->whereNotNull('distributed_at')->where('is_current', true);
            } elseif ($status === 'draft') {
                $query->whereNull('distributed_at');
            } elseif ($status === 'superseded') {
                $query->where('is_current', false)->where('build_status', 'published');
            } elseif ($status === 'failed') {
                $query->where('build_status', 'failed');
            } elseif ($status === 'unread') {
                $query->whereIn('id', $reading['unread_ids'] ?: [0]);
            }
        }

        $packs = $query->paginate(25)->withQueryString();

        $meetingsWithoutPack = [];
        if ($canManage) {
            // Only pack managers use the generation dialog or need draft meeting metadata.
            $meetingsWithoutPack = GovernanceMeeting::query()
                ->whereDoesntHave('boardPacks')
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
                    ->map(fn (BoardPack $pack) => $this->presentIndexPack($pack, $canManage, $reading))
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
                'distributed' => (clone $visibleQuery)->whereNotNull('distributed_at')->where('is_current', true)->count(),
                'draft' => (clone $visibleQuery)->whereNull('distributed_at')->count(),
                'superseded' => (clone $visibleQuery)->where('is_current', false)->where('build_status', 'published')->count(),
                'failed' => (clone $visibleQuery)->where('build_status', 'failed')->count(),
                'unread' => count($reading['unread_ids']),
            ],
            'is_recipient' => $reading['board_member_id'] !== null,
            'next_meeting_pack' => $reading['next_meeting_pack'],
            'meetings_without_pack' => $meetingsWithoutPack,
        ]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<BoardPack>  $visibleQuery
     * @return array{board_member_id: int|null, latest_ids: array<int, int>, unread_ids: array<int, int>, read_at: array<int, string>, next_meeting_pack: array<string, mixed>|null}
     */
    private function readingOverview($viewer, $visibleQuery): array
    {
        $boardMemberId = \App\Domain\Governance\Models\BoardMember::query()
            ->active()
            ->where('user_id', $viewer->id)
            ->value('id');
        $boardMemberId = $boardMemberId === null ? null : (int) $boardMemberId;

        $packs = (clone $visibleQuery)
            ->with('meeting:id,title,scheduled_at')
            ->get(['id', 'governance_meeting_id', 'revision_number', 'distributed_at', 'distributed_to', 'read_tracking', 'build_status']);

        $latest = $packs
            ->filter(fn (BoardPack $pack) => $pack->isDistributed() && $pack->isPublished())
            ->groupBy('governance_meeting_id')
            ->map(fn ($group) => $group->sortByDesc('revision_number')->first());

        $readAt = [];
        $unread = [];

        if ($boardMemberId !== null) {
            foreach ($packs as $pack) {
                $receipt = $pack->getMemberReceipt($boardMemberId);
                if ($receipt !== null && isset($receipt['read_at'])) {
                    $readAt[(int) $pack->id] = (string) $receipt['read_at'];
                }
            }

            foreach ($latest as $pack) {
                $isRecipient = in_array($boardMemberId, array_map('intval', $pack->distributed_to ?? []), true);
                if ($isRecipient && ! isset($readAt[(int) $pack->id])) {
                    $unread[] = (int) $pack->id;
                }
            }
        }

        $next = $latest
            ->filter(fn (BoardPack $pack) => $pack->meeting?->scheduled_at && $pack->meeting->scheduled_at->gte(now()->startOfDay()))
            ->sortBy(fn (BoardPack $pack) => $pack->meeting->scheduled_at)
            ->first();

        return [
            'board_member_id' => $boardMemberId,
            'latest_ids' => $latest->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'unread_ids' => $unread,
            'read_at' => $readAt,
            'next_meeting_pack' => $next ? [
                'id' => (int) $next->id,
                'meeting_title' => $next->meeting->title,
                'scheduled_at' => $next->meeting->scheduled_at?->toIso8601String(),
                'read' => isset($readAt[(int) $next->id]),
            ] : null,
        ];
    }

    public function show(Request $request, BoardPack $pack)
    {
        $viewer = $request->user();
        $this->access->concealUnlessVisible($viewer, $pack);
        $pack->load(['meeting', 'snapshot', 'generatedBy', 'supersedes']);
        $presented = $this->presenter->present($pack);
        $canManage = $this->access->canManage($viewer);

        $boardMemberId = $this->access->recipientBoardMemberId($viewer, $pack);
        $hasRead = $boardMemberId ? $pack->hasMemberRead($boardMemberId) : false;
        $myReceipt = $boardMemberId ? $pack->getMemberReceipt($boardMemberId) : null;

        // Versions this viewer can actually open — a member never sees (or is
        // linked to) a draft or failed version they would get a 404 for.
        $versions = BoardPack::query()
            ->where('governance_meeting_id', $pack->governance_meeting_id)
            ->with('meeting')
            ->orderByDesc('revision_number')
            ->get()
            ->filter(fn (BoardPack $p) => (int) $p->id === (int) $pack->id || $this->access->canView($viewer, $p))
            ->values();

        $allRevisions = $versions
            ->map(fn (BoardPack $p) => [
                'id' => $p->id,
                'revision_number' => (int) ($p->revision_number ?? 1),
                'build_status' => $p->build_status ?? 'published',
                'is_current' => (bool) ($p->is_current ?? true),
                'generated_at' => $p->generated_at?->toIso8601String(),
                'distributed_at' => $p->distributed_at?->toIso8601String(),
                'file_size' => $p->file_size,
            ])
            ->all();

        $newer = $versions
            ->filter(fn (BoardPack $p) => (int) ($p->revision_number ?? 1) > (int) ($pack->revision_number ?? 1))
            ->sortByDesc('revision_number')
            ->first();

        $meetingAccess = app(\App\Domain\Governance\Services\GovernanceRecordAccessService::class);

        return Inertia::render('Governance/Packs/Show', [
            'pack' => $this->presentShowPack($pack),
            'all_revisions' => $allRevisions,
            // Only a newer version this viewer can open (for members: one that
            // has been sent to them).
            'newer_version' => $newer ? [
                'id' => (int) $newer->id,
                'revision_number' => (int) ($newer->revision_number ?? 1),
                'is_distributed' => $newer->isDistributed(),
            ] : null,
            'is_distributed' => $pack->isDistributed(),
            'can_manage' => $canManage,
            'can_mark_read' => $boardMemberId !== null && $pack->isDistributed() && ! $hasRead,
            'is_recipient' => $boardMemberId !== null,
            'has_read' => $hasRead,
            'my_receipt' => $myReceipt,
            'read_count' => $canManage ? $pack->readCount() : null,
            'download_count' => $canManage ? $pack->downloadCount() : null,
            'meeting_url' => $pack->meeting && $meetingAccess->canViewMeeting($viewer, $pack->meeting)
                ? "/governance/meetings/{$pack->meeting->id}"
                : null,
            'readingSections' => $this->presenter->readingSections($pack, $viewer),
            'manifestSections' => $presented['manifestSections'],
            'contentSections' => $presented['contentSections'],
            // Who has read or downloaded the pack is for pack managers only.
            'distributionStats' => $canManage ? $presented['distributionStats'] : null,
            'distribution_recipient_count' => $canManage && ! $pack->isDistributed() && $pack->isPublished()
                ? $this->packService->distributionRecipients($pack)->count()
                : null,
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
                    ->with('success', "Draft board pack generated. Members can't see it until you send it to them.");
            }

            // Dispatch async job for generation
            GenerateBoardPack::dispatch($meeting->id);

            if ($request->expectsJson()) {
                return response()->json(['status' => 'queued']);
            }

            return redirect()->route('governance.meetings.show', $meeting)
                ->with('success', "The board pack is being generated. You'll get a notification when the draft is ready.");
        } catch (\Throwable $e) {
            Log::error('Board pack generation failed', [
                'meeting_id' => $meeting->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $message = "The board pack couldn't be generated. Try again in a few minutes, and contact support if it keeps happening.";

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => $message,
                ], 500);
            }

            return redirect()->back()->with('error', $message);
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
                ->with('success', $this->newVersionMessage($newPack));
        } catch (\Throwable $e) {
            Log::error('Board pack regeneration failed', [
                'meeting_id' => $meeting->id,
                'error' => $e->getMessage(),
            ]);

            $message = "A new version of the board pack couldn't be generated. Try again in a few minutes, and contact support if it keeps happening.";

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => $message,
                ], 500);
            }

            return redirect()->back()->with('error', $message);
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
        ], [
            'board_member_ids.*.exists' => 'One of the people chosen is no longer a current board member. Reload the page and try again.',
        ]);

        if ($pack->isFailed()) {
            return redirect()->back()->with('error', "This version couldn't be prepared, so it can't be sent. Create a new version first.");
        }

        $this->packService->distribute(
            $pack,
            $validated['board_member_ids'] ?? null
        );

        $count = count($pack->fresh()->distributed_to ?? []);

        return redirect()->back()->with('success', $count === 1
            ? 'Board pack sent to 1 board member.'
            : "Board pack sent to {$count} board members.");
    }

    private function newVersionMessage(BoardPack $newPack): string
    {
        $version = (int) ($newPack->revision_number ?? 1);
        $previous = $version - 1;

        return $previous >= 1
            ? "Version {$version} created as a draft. Members keep seeing version {$previous} until you send version {$version} to them."
            : "Version {$version} created as a draft. Members can't see it until you send it to them.";
    }

    public function download(Request $request, BoardPack $pack)
    {
        $viewer = $request->user();
        $this->access->concealUnlessVisible($viewer, $pack);

        // Never create a tracking event for a file that cannot be delivered.
        $disk = Storage::disk('local');
        $hasFile = $pack->file_path && ($disk->exists($pack->file_path) || file_exists(storage_path('app/'.$pack->file_path)));
        if (! $hasFile) {
            abort(404, "The pack file couldn't be found. Ask the board secretary to create a new version of the pack.");
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
                'revision_number' => $pack->revision_number,
                'managed_access' => $this->access->canManage($viewer),
            ]
        );

        if ($disk->exists($pack->file_path)) {
            return $disk->download($pack->file_path, basename($pack->file_path));
        }

        return response()->download(storage_path('app/'.$pack->file_path), basename($pack->file_path));
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

        $receipt = $pack->recordRead($boardMemberId, $request->user()->id);

        GovernanceAuditService::log(
            'board_pack.read',
            'BoardPack',
            $pack->id,
            [
                'board_member_id' => $boardMemberId,
                'user_id' => $request->user()->id,
                'revision_number' => $pack->revision_number,
                'receipt_id' => $receipt['receipt_id'] ?? null,
            ]
        );

        return response()->json([
            'success' => true,
            'receipt' => $receipt,
            'revision_number' => $pack->revision_number,
        ]);
    }

    public function regenerate(BoardPack $pack)
    {
        $this->authorize('distributePack', $pack->meeting);

        $newPack = $this->packService->regenerate($pack);

        return redirect()->route('governance.packs.show', $newPack)
            ->with('success', $this->newVersionMessage($newPack));
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
        ], [
            'files.required' => 'Choose at least one file.',
            'files.max' => 'Add up to 10 files at a time.',
            'files.*.max' => 'Each file must be 20 MB or smaller.',
            'files.*.mimes' => 'Files must be PDF, Word, Excel, PowerPoint, an image, CSV or text.',
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
            : redirect()->back()->with('success', count($request->file('files')) === 1 ? 'Document added to the pack.' : 'Documents added to the pack.');
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
            abort(404, "That file couldn't be found.");
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
            : redirect()->back()->with('success', 'File removed from the pack.');
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
            abort(404, "That file couldn't be found.");
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
     * @param  array{latest_ids: array<int, int>, read_at: array<int, string>}  $reading
     * @return array<string, mixed>
     */
    private function presentIndexPack(BoardPack $pack, bool $canManage, array $reading): array
    {
        return [
            'id' => (int) $pack->id,
            'meeting_id' => (int) $pack->governance_meeting_id,
            'revision_number' => (int) ($pack->revision_number ?? 1),
            'supersedes_id' => $pack->supersedes_id ? (int) $pack->supersedes_id : null,
            'build_status' => $pack->build_status ?? 'published',
            // Managers track the pointer; members only see a pack as replaced
            // once a newer version has been sent to them.
            'is_current' => $canManage
                ? (bool) ($pack->is_current ?? true)
                : in_array((int) $pack->id, $reading['latest_ids'], true),
            'my_read_at' => $reading['read_at'][(int) $pack->id] ?? null,
            'actual_document_count' => $pack->actualDocumentCount(),
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
            // Reading and download counts are for pack managers only.
            'read_count' => $canManage ? $pack->readCount() : null,
            'download_count' => $canManage ? $pack->downloadCount() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentShowPack(BoardPack $pack): array
    {
        return [
            'id' => (int) $pack->id,
            'revision_number' => (int) ($pack->revision_number ?? 1),
            'supersedes_id' => $pack->supersedes_id ? (int) $pack->supersedes_id : null,
            'build_status' => $pack->build_status ?? 'published',
            'error_reference' => $pack->error_reference,
            'is_current' => (bool) ($pack->is_current ?? true),
            'generated_at' => $pack->generated_at?->toIso8601String(),
            'distributed_at' => $pack->distributed_at?->toIso8601String(),
            'file_size' => $pack->file_size,
            'watermark_text' => $pack->watermark_text,
            'meeting' => [
                'id' => (int) $pack->meeting->id,
                'title' => $pack->meeting->title,
                'scheduled_at' => $pack->meeting->scheduled_at?->toIso8601String(),
                'location' => $pack->meeting->location,
                'meeting_type' => $pack->meeting->meeting_type,
            ],
            'actual_document_count' => $pack->actualDocumentCount(),
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
