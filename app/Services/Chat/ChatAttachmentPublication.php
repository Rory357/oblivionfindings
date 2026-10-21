<?php

namespace App\Services\Chat;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientPhoto;
use App\Models\OpsConversation;
use App\Models\OpsMessage;
use App\Models\User;
use App\Services\Clients\ClientPhotoStorage;
use App\Services\Portal\PortalClientSectionAccess;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

final class ChatAttachmentPublication
{
    public function __construct(private readonly ChatAccess $access) {}

    public function canPublish(User $actor, OpsConversation $conversation): bool
    {
        return app(HrCurrentStaffService::class)->isCurrent($actor) && $actor->canDo('clients.update')
            && $this->access->allows($actor, $conversation, true)
            && (! $conversation->client || (! $actor->canAccessClientPortal($conversation->client)
                && Gate::forUser($actor)->allows('view', $conversation->client)
                && Gate::forUser($actor)->allows('update', $conversation->client)));
    }

    private function source(User $actor, int $id, bool $includeRemoved = false): array
    {
        $file = DB::table('chat_attachments')->where('id', $id)->first();
        abort_unless($file, 404);
        $message = OpsMessage::query()->when($includeRemoved, fn ($query) => $query->withTrashed())->findOrFail($file->message_id);
        $conversation = $this->access->message($actor, $message, true);
        abort_unless($this->canPublish($actor, $conversation), 403);

        return [$file, $message, $conversation];
    }

    private function target(User $actor, OpsConversation $conversation, int $id): Client
    {
        abort_if($conversation->client_id && (int) $conversation->client_id !== $id, 403);
        $client = Client::findOrFail($id);
        Gate::forUser($actor)->authorize('view', $client);
        Gate::forUser($actor)->authorize('update', $client);
        abort_if($actor->canAccessClientPortal($client), 403);

        return $client;
    }

    private function writable(User $actor, OpsConversation $conversation): void
    {
        $policy = app(ChatSettings::class)->forConversation($conversation);
        abort_unless($policy['enabled'] && $policy['files_enabled'] && ($conversation->conversation_type !== 'family' || $policy['family_enabled'])
            && ! $conversation->is_archived && ! $conversation->participants()->where('user_id', $actor->id)->where('is_archived_for_user', true)->exists(), 409, 'This conversation is currently read-only.');
    }

    private function destinations(object $file): array
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($file->storage_path), 404);
        $mime = $disk->mimeType($file->storage_path);
        abort_unless($mime === $file->mime_type, 409, 'The attachment format changed. Refresh it before publishing.');
        $size = $disk->size($file->storage_path);
        $result = [];
        if ($size <= 20 * 1024 * 1024 && array_intersect(MimeTypes::getDefault()->getExtensions($mime), ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'jpg', 'jpeg', 'png', 'gif', 'txt', 'rtf'])) {
            $result[] = 'documents';
        }
        if ($size <= 10 * 1024 * 1024 && in_array($mime, ClientPhotoStorage::SAFE_IMAGE_MIME_TYPES, true)) {
            $dimensions = @getimagesize($disk->path($file->storage_path));
            if ($dimensions && $dimensions[0] * $dimensions[1] <= 40000000) {
                $result[] = 'photos';
            }
        }

        return $result;
    }

    public function options(User $actor, int $id, string $query): array
    {
        [$file, , $conversation] = $this->source($actor, $id);
        $this->writable($actor, $conversation);
        $sites = app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['clinical.accessAllSites', 'sites.viewAll']);
        $clients = Client::query()->whereIn('site_id', $sites)
            ->when($conversation->client_id, fn ($builder) => $builder->whereKey($conversation->client_id))
            ->when($query !== '', fn ($builder) => $builder->where(fn ($names) => $names->where('first_name', 'like', '%'.addcslashes($query, '%_\\').'%')->orWhere('last_name', 'like', '%'.addcslashes($query, '%_\\').'%')))
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')->lazy(100)
            ->filter(fn (Client $client) => Gate::forUser($actor)->allows('view', $client) && Gate::forUser($actor)->allows('update', $client) && ! $actor->canAccessClientPortal($client))
            ->take(31)->collect();

        return ['clients' => $clients->take(30)->map(fn ($client) => ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name)])->values(),
            'has_more' => $clients->count() > 30, 'locked_client_id' => $conversation->client_id,
            'destinations' => $this->destinations($file)];
    }

    private function reviewed(User $actor, object $file, OpsConversation $conversation, Client $client, string $destination, bool $familyVisible): array
    {
        abort_unless(in_array($destination, $this->destinations($file), true), 422, 'This file cannot be added to the selected record. Photos allow images up to 10 MB; documents allow supported files up to 20 MB.');
        $readers = $familyVisible ? $client->portalUsers()->orderBy('users.id')->get()->unique('id')
            ->filter(fn ($person) => app(PortalClientSectionAccess::class)->for($person, $client)['has_family_information_consent'])
            ->map(fn ($person) => ['id' => $person->id, 'name' => $person->name])->values()->all() : [];
        $name = trim($client->first_name.' '.$client->last_name);
        $fingerprint = hash_file('sha256', Storage::disk('local')->path($file->storage_path));
        abort_unless(is_string($fingerprint), 503, 'The attachment could not be checked. Please retry.');
        $token = hash('sha256', json_encode([1, $actor->id, $file->id, $file->name, $file->mime_type, $fingerprint, $conversation->id,
            $client->id, $client->site_id, $name, $destination, $familyVisible, $readers, 'current-and-future-authorised-record-readers'], JSON_THROW_ON_ERROR));

        return ['client' => ['id' => $client->id, 'name' => $name], 'file_name' => $file->name,
            'destination' => $destination, 'family_visible' => $familyVisible, 'family_readers' => $readers,
            'record_audience' => 'People authorised to view this client’s '.($destination === 'photos' ? 'Photos' : 'Documents').', now and in future.',
            'audience_token' => $token];
    }

    public function review(User $actor, int $id, array $data): array
    {
        [$file, , $conversation] = $this->source($actor, $id);
        $this->writable($actor, $conversation);
        $client = $this->target($actor, $conversation, (int) $data['client_id']);

        return $this->reviewed($actor, $file, $conversation, $client, $data['destination'], (bool) $data['family_visible']);
    }

    public function publish(User $inputActor, int $id, array $data): array
    {
        $createdPath = null;
        try {
            return DB::transaction(function () use ($inputActor, $id, $data, &$createdPath) {
                $actor = User::whereKey($inputActor->id)->lockForUpdate()->firstOrFail();
                [$file, $message, $source] = $this->source($actor, $id, true);
                $conversation = OpsConversation::whereKey($source->id)->lockForUpdate()->firstOrFail();
                $message = OpsMessage::withTrashed()->whereKey($message->id)->lockForUpdate()->firstOrFail();
                $this->access->message($actor, $message, true);
                $client = $this->target($actor, $conversation, (int) $data['client_id']);
                $client = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
                $this->target($actor, $conversation, $client->id);
                $hash = hash('sha256', json_encode([$id, $client->id, $data['destination'], (bool) $data['family_visible'], $data['audience_token']], JSON_THROW_ON_ERROR));
                $receipt = DB::table('chat_attachment_publications')->where('actor_id', $actor->id)->where('mutation_id', $data['mutation_id'])->first();
                if ($receipt) {
                    abort_unless(hash_equals($receipt->request_hash, $hash), 409, 'This publication reference was already used for different details.');

                    return $this->result($client, $receipt->destination, $receipt->record_id, true);
                }
                abort_if($message->trashed(), 404);
                $this->writable($actor, $conversation);
                DB::table('client_portal_users')->where('client_id', $client->id)->orderBy('id')->lockForUpdate()->get(['id']);
                $review = $this->reviewed($actor, $file, $conversation, $client, $data['destination'], (bool) $data['family_visible']);
                abort_unless(hash_equals($review['audience_token'], $data['audience_token']), 409, 'The file, client or audience changed. Review the current details before publishing.');
                $disk = Storage::disk('local');
                $createdPath = ($data['destination'] === 'photos' ? 'client-photos/' : 'client_documents/').$client->id.'/'.Str::uuid();
                abort_unless($disk->copy($file->storage_path, $createdPath), 503, 'The copy could not be saved. Please retry.');
                $copiedFile = clone $file;
                $copiedFile->storage_path = $createdPath;
                $copiedReview = $this->reviewed($actor, $copiedFile, $conversation, $client, $data['destination'], (bool) $data['family_visible']);
                abort_unless(hash_equals($review['audience_token'], $copiedReview['audience_token']), 409, 'The attachment changed while copying. Review it again before publishing.');
                $common = ['client_id' => $client->id, 'uploaded_by_user_id' => $actor->id, 'storage_disk' => 'local',
                    'storage_path' => $createdPath, 'original_name' => $file->name, 'mime_type' => $file->mime_type, 'size_bytes' => $disk->size($createdPath)];
                $record = $data['destination'] === 'photos'
                    ? ClientPhoto::create($common + ['caption' => null, 'thumbnail_path' => null, 'visibility' => $data['family_visible'] ? 'family' : 'staff_only',
                        'status' => 'approved', 'approved_by_user_id' => $actor->id, 'approved_at' => now()])
                    : ClientDocument::create($common + ['title' => mb_substr($file->name, 0, 200), 'portal_visible' => (bool) $data['family_visible']]);
                DB::table('chat_attachment_publications')->insert(['attachment_id' => $id, 'actor_id' => $actor->id, 'client_id' => $client->id,
                    'mutation_id' => $data['mutation_id'], 'request_hash' => $hash, 'destination' => $data['destination'], 'record_id' => $record->id, 'created_at' => now()]);
                AuditLog::create(['user_id' => $actor->id, 'client_id' => $client->id, 'action' => 'chat.attachment.published',
                    'meta' => ['conversation_id' => $conversation->id, 'attachment_id' => $id, 'destination' => $data['destination'], 'record_id' => $record->id, 'family_visible' => (bool) $data['family_visible']]]);

                return $this->result($client, $data['destination'], $record->id, false);
            });
        } catch (\Throwable $failure) {
            if ($createdPath) {
                Storage::disk('local')->delete($createdPath);
            }
            throw $failure;
        }
    }

    private function result(Client $client, string $destination, int $id, bool $existing): array
    {
        $exists = ($destination === 'photos' ? ClientPhoto::query() : ClientDocument::query())->where('client_id', $client->id)->whereKey($id)->exists();

        return ['record_id' => $id, 'destination' => $destination, 'client_id' => $client->id, 'already_created' => $existing,
            'href' => $exists ? route('clients.show', ['client' => $client->id, 'tab' => $destination], false) : null];
    }
}
