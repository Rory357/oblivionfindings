<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceDocument;
use App\Domain\SecurityDevices\Services\DeviceDocumentLifecycleService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\Request;

/**
 * Document uploads for Security & Devices detail pages.
 *
 * Files are stored on the private disk under `device_documents/{device_id}/`
 * and the DeviceDocument row tracks title, category, effective / expiry
 * dates, uploader, integrity hash, and reasoned removal evidence.
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
        private readonly DeviceDocumentLifecycleService $lifecycle,
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

        $document = $this->lifecycle->stageUpload($device, $user, $request->file('file'), $validated);

        if ($document->isDownloadable()) {
            return redirect()->back()->with('success', 'Document uploaded and verified in private storage.');
        }

        return redirect()->back()->with(
            'warning',
            'The document is safely staged but not yet available. Automatic storage recovery will retry it.',
        );
    }

    public function download(Request $request, Device $device, DeviceDocument $document)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.view'), 403);
        $this->access->assertCanViewDevice($user, $device);
        abort_unless($document->device_id === $device->id, 404);
        abort_unless($document->isDownloadable(), 404);

        try {
            $contents = $this->lifecycle->verifiedContents($document);
        } catch (DomainException) {
            abort(409, 'This private document could not pass integrity verification. Storage recovery is required before download.');
        }

        return response()->streamDownload(function () use (&$contents): void {
            try {
                echo $contents;
            } finally {
                if (function_exists('sodium_memzero')) {
                    sodium_memzero($contents);
                }
            }
        }, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, Device $device, DeviceDocument $document)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.update'), 403);
        $this->access->assertCanViewDevice($user, $device);
        abort_unless($document->device_id === $device->id, 404);
        abort_if($document->isRemoved(), 404);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'],
        ]);
        $removed = $this->lifecycle->requestRemoval($device, $document, $user, $validated['reason']);

        if ($removed->isRemoved() && $removed->storage_deleted_at !== null) {
            return redirect()->back()->with(
                'success',
                'Document removed. Its reasoned lifecycle and integrity evidence remains in history.',
            );
        }

        return redirect()->back()->with(
            'warning',
            'Removal is recorded and the document is unavailable. Automatic private-storage recovery will finish the quarantine cleanup.',
        );
    }
}
