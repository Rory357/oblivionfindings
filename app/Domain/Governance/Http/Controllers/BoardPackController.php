<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Services\BoardPackBuilderService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class BoardPackController extends Controller
{
    public function __construct(
        protected BoardPackBuilderService $packService
    ) {}

    public function show(BoardPack $pack)
    {
        $pack->load(['meeting', 'snapshot']);

        return Inertia::render('Governance/Packs/Show', [
            'pack' => $pack,
            'is_distributed' => $pack->isDistributed(),
            'read_count' => $pack->readCount(),
            'download_count' => $pack->downloadCount(),
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
            \App\Domain\Governance\Jobs\GenerateBoardPack::dispatch($meeting->id);

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
                    'message' => 'Board pack generation failed: ' . $e->getMessage(),
                ], 500);
            }

            return redirect()->back()->with('error', 'Board pack generation failed: ' . $e->getMessage());
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
                    'message' => 'Board pack regeneration failed: ' . $e->getMessage(),
                ], 500);
            }

            return redirect()->back()->with('error', 'Board pack regeneration failed: ' . $e->getMessage());
        }
    }

    public function distribute(Request $request, BoardPack $pack)
    {
        $validated = $request->validate([
            'board_member_ids' => 'nullable|array',
            'board_member_ids.*' => 'exists:board_members,id',
        ]);

        $this->packService->distribute(
            $pack,
            $validated['board_member_ids'] ?? null
        );

        return redirect()->back()->with('success', 'Board pack distributed to members.');
    }

    public function download(BoardPack $pack)
    {
        $boardMember = auth()->user()->boardMember;

        if (!$boardMember) {
            abort(403, 'Board access required.');
        }

        if (!$pack->isDistributed()) {
            abort(403, 'Pack not yet distributed.');
        }

        $distributedTo = $pack->distributed_to ?? [];
        if (!in_array($boardMember->id, $distributedTo)) {
            abort(403, 'You are not authorized to access this pack.');
        }

        // Record download
        $pack->recordDownload($boardMember->id);

        // Return file
        if (!Storage::exists($pack->file_path)) {
            abort(404, 'Pack file not found.');
        }

        return Storage::download($pack->file_path, basename($pack->file_path));
    }

    public function preview(GovernanceMeeting $meeting)
    {
        $this->authorize('distributePack', $meeting);

        $preview = $this->packService->preview($meeting);

        return response()->json($preview);
    }

    public function markAsRead(BoardPack $pack)
    {
        $boardMember = auth()->user()->boardMember;

        if (!$boardMember) {
            abort(403);
        }

        $pack->recordRead($boardMember->id);

        return response()->json(['success' => true]);
    }

    public function regenerate(BoardPack $pack)
    {
        $this->authorize('distributePack', $pack->meeting);

        $newPack = $this->packService->regenerate($pack);

        return redirect()->route('governance.packs.show', $newPack)
            ->with('success', 'Board pack regenerated with fresh data.');
    }
}
