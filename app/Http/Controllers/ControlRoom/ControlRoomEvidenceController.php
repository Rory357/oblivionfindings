<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ControlRoom\Concerns\AuthorizesControlRoomAlertAccess;
use App\Models\ControlRoom\EvidenceItem;
use App\Models\ControlRoom\EvidencePack;
use App\Models\ControlRoomAlert;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use ZipArchive;

class ControlRoomEvidenceController extends Controller
{
    use AuthorizesControlRoomAlertAccess;

    /**
     * List evidence packs for an alert with their items.
     */
    public function index(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $packs = EvidencePack::where('alert_id', $alert->id)
            ->with(['evidenceItems' => fn ($q) => $q->orderBy('created_at', 'desc')])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (EvidencePack $pack) => [
                'id' => $pack->id,
                'title' => $pack->title,
                'status' => $pack->status,
                'item_count' => $pack->item_count,
                'created_at' => $pack->created_at?->toISOString(),
                'items' => $pack->evidenceItems->map(fn (EvidenceItem $item) => [
                    'id' => $item->id,
                    'type' => $item->type,
                    'title' => $item->title,
                    'description' => $item->description,
                    'file_path' => $item->storage_path,
                    'file_size' => $item->file_size,
                    'mime_type' => $item->mime_type,
                    'external_system' => $item->external_system,
                    'external_ref' => $item->external_ref,
                    'metadata' => $item->metadata,
                    'created_at' => $item->created_at?->toISOString(),
                ])->values(),
            ]);

        return response()->json(['packs' => $packs]);
    }

    /**
     * Create a new evidence pack for an alert.
     */
    public function storePack(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
        ]);

        $pack = EvidencePack::create([
            'alert_id' => $alert->id,
            'title' => $data['title'],
            'status' => 'collecting',
            'item_count' => 0,
            'created_by_user_id' => $user->id,
        ]);

        AuditLogger::log('controlRoom.evidence.packCreated', $alert, [
            'alert_id' => $alert->id,
            'pack_id' => $pack->id,
        ]);

        return back()->with('success', 'Evidence pack created.');
    }

    /**
     * Add an evidence item to a pack.
     *
     * Handles three cases: file upload, text note, or CCTV bookmark.
     */
    public function storeItem(Request $request, ControlRoomAlert $alert, int $pack)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $pack = $this->nestedAlertResources()->evidencePack($user, $alert, $pack);

        if ($pack->status !== 'collecting') {
            return back()->withErrors(['pack' => 'Cannot add items to a completed or exported pack.']);
        }

        $itemType = $request->input('item_type');

        if ($itemType === 'note') {
            return $this->storeNoteItem($request, $pack, $user);
        }

        if ($itemType === 'cctv_bookmark') {
            return $this->storeCctvBookmarkItem($request, $pack, $user);
        }

        // Default: file upload
        return $this->storeFileItem($request, $pack, $user);
    }

    /**
     * Remove an evidence item.
     */
    public function destroyItem(
        Request $request,
        ControlRoomAlert $alert,
        int $pack,
        int $item,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $item = $this->nestedAlertResources()->evidenceItem($user, $alert, $pack, $item);
        $pack = $item->evidencePack;

        if ($pack->status !== 'collecting') {
            return back()->withErrors(['pack' => 'Cannot remove items from a completed or exported pack.']);
        }

        // Delete stored file if one exists
        if ($item->storage_path && Storage::disk('local')->exists($item->storage_path)) {
            Storage::disk('local')->delete($item->storage_path);
        }

        $item->delete();

        $pack->update([
            'item_count' => $pack->evidenceItems()->count(),
        ]);

        AuditLogger::log('controlRoom.evidence.itemDeleted', $pack->alert, [
            'pack_id' => $pack->id,
            'item_id' => $item->id,
        ]);

        return back()->with('success', 'Evidence item removed.');
    }

    /**
     * Download a single evidence item's file.
     *
     * Uploads are validated to a safe allowlist at store time; serving as an
     * attachment (never inline) keeps any document from executing in-browser.
     */
    public function downloadItem(
        Request $request,
        ControlRoomAlert $alert,
        int $pack,
        int $item,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $item = $this->nestedAlertResources()->evidenceItem($user, $alert, $pack, $item);
        $pack = $item->evidencePack;

        abort_unless($item->storage_path && Storage::disk('local')->exists($item->storage_path), 404);

        AuditLogger::log('controlRoom.evidence.itemDownloaded', $pack->alert, [
            'pack_id' => $item->evidence_pack_id,
            'item_id' => $item->id,
        ]);

        $filename = $item->title ?: basename($item->storage_path);

        return Storage::disk('local')->download($item->storage_path, $filename, [
            'Content-Type' => $item->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Mark an evidence pack as complete.
     */
    public function completePack(Request $request, ControlRoomAlert $alert, int $pack)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $pack = $this->nestedAlertResources()->evidencePack($user, $alert, $pack);

        if ($pack->status !== 'collecting') {
            return back()->withErrors(['pack' => 'Only packs with status "collecting" can be completed.']);
        }

        $pack->update(['status' => 'complete']);

        AuditLogger::log('controlRoom.evidence.packCompleted', $pack->alert, [
            'pack_id' => $pack->id,
        ]);

        return back()->with('success', 'Evidence pack marked as complete.');
    }

    /**
     * Export an evidence pack as a ZIP download.
     */
    public function export(Request $request, ControlRoomAlert $alert, int $pack)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $pack = $this->nestedAlertResources()->evidencePack($user, $alert, $pack);

        if ($pack->status !== 'complete') {
            return back()->withErrors(['pack' => 'Only completed packs can be exported.']);
        }

        $items = $pack->evidenceItems()->get();
        $zipFilename = 'evidence-pack-'.$pack->id.'-'.now()->format('Ymd-His').'.zip';
        $zipPath = storage_path('app/temp/'.$zipFilename);

        // Ensure temp directory exists
        if (! is_dir(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return back()->withErrors(['export' => 'Failed to create ZIP archive.']);
        }

        // Add a manifest text file
        $manifest = "Evidence Pack: {$pack->title}\n";
        $manifest .= "Pack ID: {$pack->id}\n";
        $manifest .= "Alert ID: {$pack->alert_id}\n";
        $manifest .= 'Exported: '.now()->toDateTimeString()."\n";
        $manifest .= "Exported by: {$user->name}\n";
        $manifest .= str_repeat('-', 50)."\n\n";

        foreach ($items as $item) {
            $manifest .= "Item #{$item->id}: {$item->type}\n";
            $manifest .= '  Title: '.($item->title ?? 'N/A')."\n";
            $manifest .= '  Description: '.($item->description ?? 'N/A')."\n";
            $manifest .= '  Created: '.$item->created_at?->toDateTimeString()."\n";

            if ($item->storage_path && Storage::disk('local')->exists($item->storage_path)) {
                $filename = basename($item->storage_path);
                $zip->addFile(Storage::disk('local')->path($item->storage_path), "files/{$filename}");
                $manifest .= "  File: files/{$filename}\n";
            }

            if ($item->type === 'cctv_bookmark') {
                $manifest .= "  External System: {$item->external_system}\n";
                $manifest .= "  External Ref: {$item->external_ref}\n";
                if ($item->metadata) {
                    $manifest .= '  Metadata: '.json_encode($item->metadata, JSON_PRETTY_PRINT)."\n";
                }
            }

            $manifest .= "\n";
        }

        $zip->addFromString('manifest.txt', $manifest);
        $zip->close();

        $pack->update([
            'status' => 'exported',
            'export_path' => 'temp/'.$zipFilename,
            'exported_at' => now(),
            'exported_by_user_id' => $user->id,
        ]);

        AuditLogger::log('controlRoom.evidence.packExported', $pack->alert, [
            'pack_id' => $pack->id,
        ]);

        return response()->download($zipPath, $zipFilename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /* ------------------------------------------------------------------
     * Private helpers
     * ---------------------------------------------------------------- */

    private function storeFileItem(Request $request, EvidencePack $pack, $user)
    {
        $request->validate([
            'file' => [
                'required',
                File::types(['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'])
                    ->max(10 * 1024), // 10 MB
            ],
        ]);

        $file = $request->file('file');
        $alertId = $pack->alert_id;
        $storagePath = $file->store("evidence/{$alertId}/{$pack->id}", 'local');

        $mimeType = $file->getMimeType();
        $type = str_starts_with($mimeType, 'image/') ? 'photo' : 'document';

        $item = EvidenceItem::create([
            'evidence_pack_id' => $pack->id,
            'type' => $type,
            'title' => $file->getClientOriginalName(),
            'storage_path' => $storagePath,
            'mime_type' => $mimeType,
            'file_size' => $file->getSize(),
            'captured_at' => now(),
            'captured_by_user_id' => $user->id,
        ]);

        $pack->update([
            'item_count' => $pack->evidenceItems()->count(),
        ]);

        AuditLogger::log('controlRoom.evidence.itemAdded', $pack->alert, [
            'pack_id' => $pack->id,
            'item_id' => $item->id,
            'type' => $type,
        ]);

        return back()->with('success', 'File uploaded.');
    }

    private function storeNoteItem(Request $request, EvidencePack $pack, $user)
    {
        $request->validate([
            'content' => ['required', 'string', 'max:5000'],
        ]);

        $item = EvidenceItem::create([
            'evidence_pack_id' => $pack->id,
            'type' => 'note',
            'title' => 'Note',
            'description' => $request->input('content'),
            'captured_at' => now(),
            'captured_by_user_id' => $user->id,
        ]);

        $pack->update([
            'item_count' => $pack->evidenceItems()->count(),
        ]);

        AuditLogger::log('controlRoom.evidence.itemAdded', $pack->alert, [
            'pack_id' => $pack->id,
            'item_id' => $item->id,
            'type' => 'note',
        ]);

        return back()->with('success', 'Note added.');
    }

    private function storeCctvBookmarkItem(Request $request, EvidencePack $pack, $user)
    {
        $data = $request->validate([
            'camera_id' => ['required', 'string', 'max:100'],
            'timestamp' => ['required', 'date'],
        ]);

        $item = EvidenceItem::create([
            'evidence_pack_id' => $pack->id,
            'type' => 'cctv_bookmark',
            'title' => 'CCTV Bookmark - Camera '.$data['camera_id'],
            'external_system' => 'cctv',
            'external_ref' => $data['camera_id'],
            'metadata' => [
                'camera_id' => $data['camera_id'],
                'timestamp' => $data['timestamp'],
            ],
            'captured_at' => now(),
            'captured_by_user_id' => $user->id,
        ]);

        $pack->update([
            'item_count' => $pack->evidenceItems()->count(),
        ]);

        AuditLogger::log('controlRoom.evidence.itemAdded', $pack->alert, [
            'pack_id' => $pack->id,
            'item_id' => $item->id,
            'type' => 'cctv_bookmark',
        ]);

        return back()->with('success', 'CCTV bookmark added.');
    }
}
