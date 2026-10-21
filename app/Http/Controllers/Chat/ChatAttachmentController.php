<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\OpsMessage;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatAttachmentPublication;
use App\Services\Chat\ChatVoiceInspector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ChatAttachmentController extends Controller
{
    private const PREVIEW_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];

    public function __construct(private readonly ChatAccess $access) {}

    private function permitted(Request $request, int $attachment): array
    {
        $file = DB::table('chat_attachments')->where('id', $attachment)->first();
        abort_unless($file, 404);
        $message = OpsMessage::findOrFail($file->message_id);
        $this->access->message($request->user(), $message);
        abort_unless(Storage::disk('local')->exists($file->storage_path), 404);

        return [$file, $message];
    }

    public function metadata(Request $request, int $attachment)
    {
        [$file, $message] = $this->permitted($request, $attachment);
        $voice = ($file->media_kind ?? 'file') === 'voice_note';
        $supported = $voice || in_array($file->mime_type, self::PREVIEW_TYPES, true);

        return response()->json([
            'id' => (int) $file->id, 'message_id' => (int) $message->id, 'conversation_id' => (int) $message->conversation_id,
            'name' => $file->name, 'mime_type' => $file->mime_type, 'size_bytes' => (int) $file->size_bytes,
            'sender_name' => $message->sender?->name ?? 'Former participant', 'created_at' => $message->created_at?->toISOString(),
            'audience_label' => $message->conversation->conversation_type === 'family' && $message->conversation->care_team_cover
                ? 'Family participants and authorised care-team cover' : 'Current conversation members',
            'preview_url' => $supported ? route('chat.attachment.preview', $file->id, false) : null,
            'download_url' => route('chat.attachment', $file->id, false),
            'caption' => $file->caption,
            'media_kind' => $file->media_kind, 'duration_ms' => $file->duration_ms,
            'can_publish' => ! $voice && app(ChatAttachmentPublication::class)->canPublish($request->user(), $message->conversation),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, int $attachment)
    {
        [$file] = $this->permitted($request, $attachment);
        $disk = Storage::disk('local');
        $voice = ($file->media_kind ?? 'file') === 'voice_note';
        abort_unless($voice || in_array($file->mime_type, self::PREVIEW_TYPES, true), 415, 'This file format can be downloaded but cannot be previewed here.');
        abort_unless($disk->size($file->storage_path) <= 25 * 1024 * 1024, 413, 'This file is too large for the in-app preview. Download the original.');
        abort_unless($voice ? ChatVoiceInspector::storedBytesMatch($file, $disk->path($file->storage_path)) : $disk->mimeType($file->storage_path) === $file->mime_type, 415, 'This file does not match its recorded format.');
        if (str_starts_with($file->mime_type, 'image/')) {
            $dimensions = @getimagesize($disk->path($file->storage_path));
            abort_unless($dimensions && $dimensions[0] * $dimensions[1] <= 40000000, 413, 'This image is too large or could not be opened. Download the original.');
        }

        // Fetch into the app's image/PDF renderer. Direct navigation remains a download.
        return $disk->download($file->storage_path, $file->name, [
            'Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Cross-Origin-Resource-Policy' => 'same-origin',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
        ]);
    }
}
