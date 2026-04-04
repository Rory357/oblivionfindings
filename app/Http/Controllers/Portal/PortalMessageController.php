<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientPhoto;
use App\Models\OpsConversation;
use App\Models\OpsConversationParticipant;
use App\Models\OpsMessage;
use App\Models\OpsMessageReaction;
use App\Models\Shift;
use App\Models\TimelineEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PortalMessageController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $conversations = OpsConversation::where('client_id', $client->id)
            ->where('conversation_type', 'family')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->with(['latestMessage', 'participants.user:id,name,last_seen_at,presence_status'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn ($convo) => [
                'id' => $convo->id,
                'title' => $convo->title,
                'updated_at' => $convo->updated_at?->toISOString(),
                'is_archived' => $convo->is_archived,
                'latest_message' => $convo->latestMessage ? [
                    'id' => $convo->latestMessage->id,
                    'content' => $convo->latestMessage->content,
                    'sender_type' => $convo->latestMessage->sender_type,
                    'created_at' => $convo->latestMessage->created_at?->toISOString(),
                ] : null,
                'participants' => $convo->participants->map(function ($p) {
                    $u = $p->user;
                    if (!$u) return null;
                    $presence = 'offline';
                    if ($u->presence_status === 'online' && $u->last_seen_at?->gt(now()->subMinutes(5))) $presence = 'online';
                    elseif ($u->last_seen_at?->gt(now()->subMinutes(15))) $presence = 'away';
                    return ['id' => $u->id, 'name' => $u->name, 'presence' => $presence];
                })->filter()->values(),
            ]);

        // Support workers for "new chat" picker
        $client->load(['supportWorkers:id,name,profile_photo_path,last_seen_at,presence_status', 'keyWorker:id,name,profile_photo_path,last_seen_at,presence_status']);
        $workers = collect();
        if ($client->keyWorker) $workers->push($client->keyWorker);
        foreach ($client->supportWorkers as $w) {
            if (!$workers->contains('id', $w->id)) $workers->push($w);
        }
        $derivePresence = function ($u) {
            if (!$u->last_seen_at) return 'offline';
            if ($u->presence_status === 'online' && $u->last_seen_at->gt(now()->subMinutes(5))) return 'online';
            if ($u->last_seen_at->gt(now()->subMinutes(15))) return 'away';
            return 'offline';
        };

        return inertia('portal/messages', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'avatar' => $client->avatar,
                'profile_photo_url' => $client->profile_photo_url,
            ],
            'conversations' => $conversations->values(),
            'supportWorkers' => $workers->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'presence' => $derivePresence($w),
            ])->values(),
            'currentUserId' => $user->id,
        ]);
    }

    public function show(Request $request, Client $client, OpsConversation $conversation)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        // Verify user is a participant of this conversation
        $isParticipant = $conversation->participants()
            ->where('user_id', $user->id)
            ->exists();
        abort_unless($isParticipant, 403);

        // Verify conversation belongs to the client
        abort_unless($conversation->client_id === $client->id, 403);

        $messages = $conversation->messages()->withTrashed()
            ->with(['sender:id,name,profile_photo_path', 'reactions.user:id,name'])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($msg) => [
                'id' => $msg->id,
                'content' => $msg->content,
                'sender_id' => $msg->sender_id,
                'sender_type' => $msg->sender_type,
                'message_type' => $msg->message_type,
                'attachments' => $msg->attachments,
                'is_pinned' => (bool) $msg->is_pinned,
                'is_read' => (bool) $msg->is_read,
                'read_at' => $msg->read_at?->toISOString(),
                'shift_id' => $msg->shift_id,
                'is_deleted' => $msg->trashed(),
                'reactions' => $msg->reactions
                    ->groupBy('emoji')
                    ->map(fn ($group, $emoji) => [
                        'emoji' => $emoji,
                        'count' => $group->count(),
                        'user_ids' => $group->pluck('user_id')->all(),
                        'user_names' => $group->map(fn ($r) => $r->user?->name)->filter()->values()->all(),
                    ])
                    ->values()
                    ->all(),
                'sender' => $msg->sender ? [
                    'id' => $msg->sender->id,
                    'name' => $msg->sender->name,
                    'avatar' => $msg->sender->avatar,
                ] : null,
                'created_at' => $msg->created_at?->toISOString(),
            ]);

        // Pinned messages
        $pinnedMessages = $conversation->messages()
            ->where('is_pinned', true)
            ->with('sender:id,name')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($msg) => [
                'id' => $msg->id,
                'content' => $msg->content,
                'sender_name' => $msg->sender?->name,
                'created_at' => $msg->created_at?->toISOString(),
            ]);

        // Mark unread messages as read
        $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'is_read' => true]);

        $participants = $conversation->participants()
            ->with('user:id,name,last_seen_at,presence_status')
            ->get()
            ->map(function ($p) {
                $u = $p->user;
                if (!$u) return null;
                $presence = 'offline';
                if ($u->presence_status === 'online' && $u->last_seen_at?->gt(now()->subMinutes(5))) $presence = 'online';
                elseif ($u->last_seen_at?->gt(now()->subMinutes(15))) $presence = 'away';
                return ['id' => $u->id, 'name' => $u->name, 'presence' => $presence];
            })
            ->filter()
            ->values();

        // Re-fetch conversations for sidebar (same as index)
        $allConversations = OpsConversation::where('client_id', $client->id)
            ->where('conversation_type', 'family')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->with(['latestMessage', 'participants.user:id,name,last_seen_at,presence_status'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(function ($convo) {
                return [
                    'id' => $convo->id,
                    'title' => $convo->title,
                    'updated_at' => $convo->updated_at?->toISOString(),
                    'latest_message' => $convo->latestMessage ? [
                        'content' => $convo->latestMessage->content,
                        'created_at' => $convo->latestMessage->created_at?->toISOString(),
                        'sender_name' => $convo->latestMessage->sender?->name,
                    ] : null,
                    'participants' => $convo->participants->map(function ($p) {
                        $u = $p->user;
                        if (!$u) return null;
                        $presence = 'offline';
                        if ($u->presence_status === 'online' && $u->last_seen_at?->gt(now()->subMinutes(5))) $presence = 'online';
                        elseif ($u->last_seen_at?->gt(now()->subMinutes(15))) $presence = 'away';
                        return ['id' => $u->id, 'name' => $u->name, 'presence' => $presence];
                    })->filter()->values(),
                ];
            });

        $client->load(['supportWorkers:id,name,profile_photo_path,last_seen_at,presence_status', 'keyWorker:id,name,profile_photo_path,last_seen_at,presence_status']);
        $workers = collect();
        if ($client->keyWorker) $workers->push($client->keyWorker);
        foreach ($client->supportWorkers as $w) {
            if (!$workers->contains('id', $w->id)) $workers->push($w);
        }
        $derivePresence = function ($u) {
            if (!$u->last_seen_at) return 'offline';
            if ($u->presence_status === 'online' && $u->last_seen_at->gt(now()->subMinutes(5))) return 'online';
            if ($u->last_seen_at->gt(now()->subMinutes(15))) return 'away';
            return 'offline';
        };

        return inertia('portal/messages', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
            ],
            'conversations' => $allConversations->values(),
            'supportWorkers' => $workers->map(fn ($w) => ['id' => $w->id, 'name' => $w->name, 'presence' => $derivePresence($w)])->values(),
            'currentUserId' => $user->id,
            'activeConversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'participants' => $participants,
            ],
            'activeMessages' => $messages->values(),
            'pinnedMessages' => $pinnedMessages->values(),
        ]);
    }

    public function toggleReaction(Request $request, Client $client, OpsMessage $message)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $validated = $request->validate([
            'emoji' => ['required', 'string', 'max:10'],
        ]);

        $existing = OpsMessageReaction::where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->where('emoji', $validated['emoji'])
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            OpsMessageReaction::create([
                'message_id' => $message->id,
                'user_id' => $user->id,
                'emoji' => $validated['emoji'],
            ]);
        }

        return redirect()->back();
    }

    public function togglePin(Request $request, Client $client, OpsMessage $message)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $message->update(['is_pinned' => !$message->is_pinned]);

        return redirect()->back();
    }

    public function archiveMessage(Request $request, Client $client, OpsMessage $message)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);
        abort_unless($message->sender_id === $user->id, 403);

        // Soft delete — data preserved for auditing
        $message->delete();

        return redirect()->back();
    }

    public function searchMessages(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $q = $request->query('q', '');
        if (strlen($q) < 2) return response()->json([]);

        $conversationIds = OpsConversation::where('client_id', $client->id)
            ->where('conversation_type', 'family')
            ->whereHas('participants', fn ($qb) => $qb->where('user_id', $user->id))
            ->pluck('id');

        $results = OpsMessage::whereIn('conversation_id', $conversationIds)
            ->where('content', 'like', "%{$q}%")
            ->with('sender:id,name')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($msg) => [
                'id' => $msg->id,
                'content' => $msg->content,
                'sender_name' => $msg->sender?->name,
                'conversation_id' => $msg->conversation_id,
                'created_at' => $msg->created_at?->toISOString(),
            ]);

        return response()->json($results);
    }

    public function storeMessage(Request $request, Client $client, OpsConversation $conversation)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $isParticipant = $conversation->participants()
            ->where('user_id', $user->id)
            ->exists();
        abort_unless($isParticipant, 403);
        abort_unless($conversation->client_id === $client->id, 403);

        $request->validate([
            'content' => 'nullable|string|max:5000',
            'attachment' => 'nullable|file|max:20480',
        ]);

        $content = $request->input('content', '');
        $messageType = 'text';
        $attachments = null;

        // Handle file attachment
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $isImage = str_starts_with($file->getMimeType(), 'image/');

            if ($isImage) {
                // Store as photo (same pattern as PortalPhotoController)
                $directory = "client-photos/{$client->id}";
                $storagePath = $file->store($directory, 'public');
                $thumbnailPath = null;

                // Generate thumbnail
                $fullPath = Storage::disk('public')->path($storagePath);
                if (file_exists($fullPath)) {
                    $imageInfo = @getimagesize($fullPath);
                    if ($imageInfo !== false) {
                        $sourceImage = match ($imageInfo[2]) {
                            IMAGETYPE_JPEG => @imagecreatefromjpeg($fullPath),
                            IMAGETYPE_PNG => @imagecreatefrompng($fullPath),
                            IMAGETYPE_GIF => @imagecreatefromgif($fullPath),
                            IMAGETYPE_WEBP => @imagecreatefromwebp($fullPath),
                            default => null,
                        };
                        if ($sourceImage) {
                            $origW = imagesx($sourceImage);
                            $origH = imagesy($sourceImage);
                            $maxDim = 400;
                            $ratio = min($maxDim / max($origW, 1), $maxDim / max($origH, 1), 1);
                            $newW = (int) round($origW * $ratio);
                            $newH = (int) round($origH * $ratio);
                            $thumb = imagecreatetruecolor($newW, $newH);
                            if (in_array($imageInfo[2], [IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
                                imagealphablending($thumb, false);
                                imagesavealpha($thumb, true);
                                imagefilledrectangle($thumb, 0, 0, $newW, $newH, imagecolorallocatealpha($thumb, 0, 0, 0, 127));
                            }
                            imagecopyresampled($thumb, $sourceImage, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
                            $thumbDir = "client-photos/{$client->id}/thumbs";
                            Storage::disk('public')->makeDirectory($thumbDir);
                            $thumbFile = pathinfo($storagePath, PATHINFO_FILENAME) . '_thumb.jpg';
                            $thumbnailPath = "{$thumbDir}/{$thumbFile}";
                            imagejpeg($thumb, Storage::disk('public')->path($thumbnailPath), 85);
                            imagedestroy($sourceImage);
                            imagedestroy($thumb);
                        }
                    }
                }

                $photo = ClientPhoto::create([
                    'client_id' => $client->id,
                    'uploaded_by_user_id' => $user->id,
                    'storage_path' => $storagePath,
                    'thumbnail_path' => $thumbnailPath,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'caption' => $content ?: 'Shared via chat',
                    'visibility' => 'family',
                    'status' => 'approved',
                ]);

                TimelineEvent::create([
                    'source_type' => ClientPhoto::class,
                    'source_id' => $photo->id,
                    'occurred_at' => now(),
                    'type' => 'photo_uploaded',
                    'actor_user_id' => $user->id,
                    'client_id' => $client->id,
                    'site_id' => $client->site_id,
                    'subject' => 'Photo shared in chat',
                    'visibility' => 'portal',
                    'is_pinned' => false,
                    'created_by' => $user->id,
                ]);

                $attachments = [[
                    'type' => 'photo',
                    'name' => $file->getClientOriginalName(),
                    'path' => $storagePath,
                    'thumbnail_path' => $thumbnailPath,
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'photo_id' => $photo->id,
                    'url' => Storage::disk('public')->url($storagePath),
                    'thumbnail_url' => $thumbnailPath ? Storage::disk('public')->url($thumbnailPath) : null,
                ]];
                $content = $content ?: '📸 Shared a photo';
                $messageType = 'attachment';

            } else {
                // Store as document (same pattern as PortalDocumentController)
                $path = $file->store("client_documents/{$client->id}", 'local');

                $doc = ClientDocument::create([
                    'client_id' => $client->id,
                    'uploaded_by_user_id' => $user->id,
                    'title' => $content ?: $file->getClientOriginalName(),
                    'category' => 'chat_upload',
                    'folder' => 'Chat Uploads',
                    'version' => '1',
                    'storage_disk' => 'local',
                    'storage_path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'portal_visible' => true,
                ]);

                TimelineEvent::create([
                    'source_type' => ClientDocument::class,
                    'source_id' => $doc->id,
                    'occurred_at' => now(),
                    'type' => 'document_uploaded',
                    'actor_user_id' => $user->id,
                    'client_id' => $client->id,
                    'site_id' => $client->site_id,
                    'subject' => 'Document shared in chat: ' . $file->getClientOriginalName(),
                    'visibility' => 'portal',
                    'is_pinned' => false,
                    'created_by' => $user->id,
                ]);

                $attachments = [[
                    'type' => 'document',
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'document_id' => $doc->id,
                ]];
                $content = $content ?: '📎 ' . $file->getClientOriginalName();
                $messageType = 'attachment';
            }
        }

        if (!$content && !$attachments) {
            return redirect()->back();
        }

        OpsMessage::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'sender_type' => 'family',
            'content' => $content,
            'message_type' => $messageType,
            'attachments' => $attachments,
            'client_id' => $client->id,
            'shift_id' => Shift::where('client_id', $client->id)->where('status', 'in_progress')->where('user_id', $user->id)->value('id'),
        ]);

        $conversation->update(['updated_at' => now()]);

        return redirect()->back();
    }

    public function startConversation(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $validated = $request->validate([
            'title' => 'nullable|string|max:200',
            'content' => 'required|string|max:5000',
            'worker_id' => 'nullable|integer|exists:users,id',
        ]);

        $workerId = $validated['worker_id'] ?? $client->key_worker_id;

        // Check if a family conversation already exists between this user and worker for this client
        if ($workerId) {
            $existing = OpsConversation::where('client_id', $client->id)
                ->where('conversation_type', 'family')
                ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
                ->whereHas('participants', fn ($q) => $q->where('user_id', $workerId))
                ->first();

            if ($existing) {
                // Add message to existing conversation
                OpsMessage::create([
                    'conversation_id' => $existing->id,
                    'sender_id' => $user->id,
                    'sender_type' => 'family',
                    'content' => $validated['content'],
                    'message_type' => 'text',
                    'client_id' => $client->id,
                ]);
                $existing->touch();

                return redirect("/portal/clients/{$client->id}/messages/{$existing->id}");
            }
        }

        // Create new conversation
        $workerName = $workerId ? \App\Models\User::find($workerId)?->name : null;
        $conversation = OpsConversation::create([
            'title' => $workerName ? "Chat with {$workerName}" : ($validated['title'] ?? 'Family Message'),
            'conversation_type' => 'family',
            'client_id' => $client->id,
        ]);

        OpsConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);

        if ($workerId) {
            OpsConversationParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $workerId,
            ]);
        }

        OpsMessage::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'sender_type' => 'family',
            'content' => $validated['content'],
            'message_type' => 'text',
            'client_id' => $client->id,
        ]);

        return redirect("/portal/clients/{$client->id}/messages/{$conversation->id}");
    }
}
