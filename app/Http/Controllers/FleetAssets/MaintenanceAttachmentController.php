<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Services\Fleet\MaintenanceAttachmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MaintenanceAttachmentController extends Controller
{
    public function __construct(private readonly MaintenanceAttachmentService $attachments) {}

    public function store(Request $request, int $workOrder)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $data = $request->validate([
            'parent_type' => ['required', 'string', 'in:work,report,check,action'],
            'parent_id' => ['required', 'integer', 'min:0'],
            'request_key' => ['required', 'string', 'min:8', 'max:100'],
            'file' => ['required', 'file', 'max:10240', 'mimetypes:image/jpeg,image/png,application/pdf'],
            'category' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $attachment = $this->attachments->upload($actor, $workOrder,
            $data['parent_type'], (int) $data['parent_id'], $data['request_key'], $data['file'],
            $data['category'] ?? null, $data['description'] ?? null);

        // The vehicle workspace uploads through fetch and needs a JSON answer.
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['attachment' => ['id' => $attachment->id], 'message' => 'Evidence saved.']);
        }

        return back()->with('success', 'Evidence saved.')
            ->with('maintenance_attachment_id', $attachment->id);
    }

    public function download(Request $request, int $workOrder, int $attachment)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $row = $this->attachments->download($actor, $workOrder, $attachment);

        return Storage::disk('private')->download($row->path, $row->original_name, [
            'Content-Type' => $row->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
