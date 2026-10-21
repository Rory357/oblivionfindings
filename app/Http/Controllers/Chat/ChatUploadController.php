<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\ChatUpload;
use App\Models\OpsConversation;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatFileScan;
use App\Services\Chat\ChatUploads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class ChatUploadController extends Controller
{
    public function __construct(private ChatAccess $access, private ChatUploads $uploads) {}

    public function index(Request $request, OpsConversation $conversation)
    {
        return response()->json(['uploads' => $this->uploads->current($conversation, $request->user())])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, OpsConversation $conversation, string $upload)
    {
        $this->access->authorize($request->user(), $conversation, true);
        $record = ChatUpload::find($upload);
        if (! $record) {
            return response()->json(['id' => $upload, 'conversation_id' => $conversation->id, 'state' => 'missing'])->header('Cache-Control', 'private, no-store');
        }
        $this->uploads->authorize($record, $conversation, $request->user());

        return $this->response($record);
    }

    public function store(Request $request, OpsConversation $conversation, string $upload)
    {
        $this->access->authorize($request->user(), $conversation, true);
        $request->validate(['media_kind' => ['sometimes', 'in:file,voice_note']]);
        $kind = $request->input('media_kind', 'file');
        $formats = $kind === 'voice_note'
            ? 'mimetypes:audio/webm,video/webm,audio/ogg,application/ogg,audio/mp4,video/mp4,audio/x-m4a'
            : 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,csv,txt,rtf';
        $request->validate(['file' => ['required', 'file', 'max:20480', $formats]]);

        return $this->response($this->uploads->store($conversation, $request->user(), $upload, $request->file('file'), $kind));
    }

    public function retry(Request $request, OpsConversation $conversation, string $upload)
    {
        return $this->response($this->uploads->scan($conversation, $request->user(), $upload));
    }

    public function destroy(Request $request, OpsConversation $conversation, string $upload)
    {
        return $this->response($this->uploads->discard($conversation, $request->user(), $upload));
    }

    private function response(ChatUpload $upload)
    {
        return response()->json($this->uploads->project($upload))->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, OpsConversation $conversation, string $upload)
    {
        return $this->content($request, $conversation, $upload, true);
    }

    public function download(Request $request, OpsConversation $conversation, string $upload)
    {
        return $this->content($request, $conversation, $upload, false);
    }

    private function content(Request $request, OpsConversation $conversation, string $id, bool $preview)
    {
        $upload = ChatUpload::findOrFail($id);
        $this->uploads->authorize($upload, $conversation, $request->user());
        abort_unless($upload->state === 'ready' && ! $upload->message_id && $upload->storage_path, 409, 'This private upload is not available for preview. Check its current status.');
        $disk = Storage::disk('local');
        abort_unless($disk->exists($upload->storage_path), 404);
        $path = $disk->path($upload->storage_path);
        abort_unless((new ChatFileScan('clean', $upload->sha256))->verifies($path), 422, 'The private upload changed after its safety check. Choose the original file again.');
        if ($preview) {
            $types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
            $voice = $upload->media_kind === 'voice_note' && in_array($upload->mime_type, ['audio/webm', 'audio/ogg', 'audio/mp4'], true)
                && $upload->duration_ms >= 50 && $upload->duration_ms <= 120500;
            abort_unless($voice || in_array($upload->mime_type, $types, true), 415, 'Download this file to review its contents.');
            abort_unless($disk->size($upload->storage_path) <= 25 * 1024 * 1024, 413);
            if (str_starts_with($upload->mime_type, 'image/')) {
                $dimensions = @getimagesize($path);
                abort_unless($dimensions && $dimensions[0] * $dimensions[1] <= 40000000, 413, 'This image is too large for the preview. Download the original.');
            }
        }

        return $disk->download($upload->storage_path, $upload->name, [
            'Content-Type' => $upload->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Cross-Origin-Resource-Policy' => 'same-origin', 'Content-Security-Policy' => "sandbox; default-src 'none'",
        ]);
    }
}
