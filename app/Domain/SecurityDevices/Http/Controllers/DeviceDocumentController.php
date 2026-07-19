<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceDocument;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Document uploads for Security & Devices detail pages.
 *
 * Files are stored on the `local` disk under `device_documents/{device_id}/`
 * and the DeviceDocument row tracks title, category, effective / expiry
 * dates, and uploader. Delete also removes the underlying file so orphan
 * blobs do not accumulate.
 */
class DeviceDocumentController extends Controller
{
    private const ALLOWED_CATEGORIES = [
        'manual',
        'install_photo',
        'compliance_cert',
        'firmware_notes',
        'configuration',
        'network_diagram',
        'other',
    ];

    private const MAX_SIZE_KB = 20480; // 20 MB

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function store(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.update'), 403);
        $this->access->assertCanViewDevice($user, $device);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_SIZE_KB],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:'.implode(',', self::ALLOWED_CATEGORIES)],
            'version' => ['nullable', 'string', 'max:64'],
            'effective_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $file = $request->file('file');
        $disk = 'local';
        $storagePath = $file->store("device_documents/{$device->id}", $disk);

        if ($storagePath === false) {
            return redirect()->back()->with('error', 'Failed to store uploaded file.');
        }

        DeviceDocument::create([
            'device_id' => $device->id,
            'uploaded_by_user_id' => $user->id,
            'title' => $validated['title'],
            'category' => $validated['category'],
            'version' => $validated['version'] ?? null,
            'effective_date' => $validated['effective_date'] ?? null,
            'expiry_date' => $validated['expiry_date'] ?? null,
            'storage_disk' => $disk,
            'storage_path' => $storagePath,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize() ?: 0,
            'notes' => $validated['notes'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Document uploaded.');
    }

    public function download(Request $request, Device $device, DeviceDocument $document)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.view'), 403);
        $this->access->assertCanViewDevice($user, $device);
        abort_unless($document->device_id === $device->id, 404);

        return Storage::disk($document->storage_disk)
            ->download($document->storage_path, $document->original_name);
    }

    public function destroy(Request $request, Device $device, DeviceDocument $document)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.update'), 403);
        $this->access->assertCanViewDevice($user, $device);
        abort_unless($document->device_id === $device->id, 404);

        // Remove the underlying blob first; only delete the row if it succeeds
        // (or if the blob was already missing — treat that as a soft recovery).
        try {
            Storage::disk($document->storage_disk)->delete($document->storage_path);
        } catch (\Throwable) {
            // Ignore missing-file errors — we still want to clean up the row.
        }

        $document->delete();

        return redirect()->back()->with('success', 'Document removed.');
    }
}
