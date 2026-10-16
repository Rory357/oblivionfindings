<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrKudosReply;
use App\Domain\Hr\Notifications\FeedReplyNotification;
use App\Domain\Hr\Notifications\RecognitionMentionNotification;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Canonical reply projection and author withdrawal, shared by HR and the Hub. */
class HrRecognitionReplies
{
    public function __construct(private readonly FeedService $feed) {}

    public function ready(): bool
    {
        return Schema::hasColumn('hr_kudos_replies', 'author_removed_at') && Schema::hasColumn('hr_kudos_replies', 'version');
    }

    public function canParticipate(HrKudos $kudos, User $viewer): bool
    {
        return $this->feed->canReplyKudos($kudos, $viewer)
            && (new UserSiteAccessService)->applyStaffScope(User::query()->whereKey($viewer->id), $viewer)->exists();
    }

    public function socialReady(): bool
    {
        return $this->ready() && Schema::hasColumn('hr_kudos_replies', 'parent_id') && Schema::hasColumn('hr_kudos_replies', 'hidden_at');
    }

    public function canModerate(HrKudos $kudos, User $viewer): bool
    {
        return $this->socialReady() && $this->feed->canViewKudos($kudos, $viewer) && $viewer->canDo('hr.recognition.view')
            && (new UserSiteAccessService)->applyStaffScope(User::query()->whereKey($viewer->id), $viewer)->exists()
            && ($viewer->canDo('hr.employees.manage') || ($viewer->canDo('company_hub.view') && $viewer->canDo('company_hub.moderate')));
    }

    public static function createRules(): array
    {
        return ['actor_id' => 'required|integer|min:1', 'body' => 'required|string|min:1|max:2000',
            'parent_id' => 'nullable|integer|min:1', ...HrRecognitionMentions::rules(), ...HrRecognitionMedia::rules(),
            'user_id' => 'prohibited', 'author_id' => 'prohibited', 'kudos_id' => 'prohibited'];
    }

    public function create(User $actor, HrKudos $kudos, array $input): HrKudosReply
    {
        $data = Validator::make($input, self::createRules())->validate();

        return app(HrRecognitionMedia::class)->guarded(function (HrRecognitionMedia $media) use ($actor, $kudos, $data): HrKudosReply {
            return DB::transaction(function () use ($actor, $kudos, $data, $media): HrKudosReply {
                [$actor, $current] = $this->writeContext($actor, $kudos, $data);
                $parent = null;
                if (! empty($data['parent_id'])) {
                    $this->assertSocialReady();
                    $parent = HrKudosReply::visible()->where('kudos_id', $current->id)->whereKey($data['parent_id'])->lockForUpdate()->firstOrFail();
                    abort_unless((new UserSiteAccessService)->applyStaffScope(app(HrCurrentStaffService::class)->currentUsersQuery(), $actor)
                        ->whereKey($parent->user_id)->exists(), 404);
                    // Bound nesting prevents unbounded recursive renderers and cycles.
                    $depth = 1;
                    $ancestor = $parent;
                    while ($ancestor->parent_id) {
                        abort_if(++$depth >= 8, 422, 'This conversation is already deeply nested. Add a new comment instead.');
                        $ancestor = HrKudosReply::where('kudos_id', $current->id)->findOrFail($ancestor->parent_id);
                    }
                }
                $body = trim($data['body']);
                if ($body === '') {
                    throw ValidationException::withMessages(['body' => 'Write a comment before posting.']);
                }
                $reply = HrKudosReply::create(['kudos_id' => $current->id, 'user_id' => $actor->id, 'body' => $body,
                    ...($this->socialReady() ? ['parent_id' => $parent?->id] : [])]);
                app(HrRecognitionMentions::class)->sync($actor, $current, $data['mention_ids'] ?? [], $reply);
                $media->store($actor, $current, $data['images'] ?? [], $reply);
                $ids = [(int) $current->from_user_id, (int) $current->to_user_id, (int) $parent?->user_id];
                if ($current->isTeamRecognition() && (int) $actor->id === (int) $current->from_user_id) {
                    $ids = [...$ids, ...$current->recipients()->pluck('users.id')->all()];
                }
                foreach (array_unique(array_filter($ids)) as $id) {
                    $recipient = User::find($id);
                    if ($id !== (int) $actor->id && $recipient && $recipient->canDo('hr.recognition.view') && $this->feed->canViewKudos($current, $recipient)) {
                        try {
                            $recipient->notify(new FeedReplyNotification($actor->name, $current->isTeamRecognition() ? 'your team recognition' : 'your kudos', $body,
                                $current->isTeamRecognition() ? (int) $current->id : null, (int) $reply->id));
                        } catch (\Throwable $exception) {
                            Log::warning('Recognition reply notification could not be queued', ['kudos_id' => $current->id, 'reply_id' => $reply->id, 'recipient_id' => $id]);
                        }
                    }
                }

                return $reply;
            });
        });
    }

    private function writeContext(User $actor, HrKudos $kudos, array $data, bool $moderation = false): array
    {
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        $current = HrKudos::whereKey($kudos->id)->lockForUpdate()->firstOrFail();
        abort_unless($this->feed->canViewKudos($current, $actor), 404);
        abort_unless($moderation ? $this->canModerate($current, $actor) : $this->canParticipate($current, $actor), 403);
        abort_if((int) $data['actor_id'] !== (int) $actor->id, 409, 'Your signed-in account changed. Reload the conversation.');

        return [$actor, $current];
    }

    private function assertSocialReady(): void
    {
        if (! $this->socialReady()) {
            throw ValidationException::withMessages(['reply' => 'Conversation changes are not available yet.']);
        }
    }

    public function edit(User $actor, HrKudos $kudos, int $replyId, array $data): void
    {
        DB::transaction(function () use ($actor, $kudos, $replyId, $data): void {
            [$actor, $current] = $this->writeContext($actor, $kudos, $data);
            $this->assertSocialReady();
            $reply = HrKudosReply::visible()->where('kudos_id', $current->id)->whereKey($replyId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $reply->user_id === (int) $actor->id, 403);
            abort_if((int) $data['version'] !== (int) $reply->version, 409, 'This comment changed. Reload the conversation before editing.');
            $body = trim($data['body']);
            if ($body === '') {
                throw ValidationException::withMessages(['body' => 'Write a comment before saving.']);
            }
            $previous = (int) $reply->version;
            $reply->forceFill(['body' => $body, 'edited_at' => now(), 'version' => $previous + 1])->save();
            app(HrRecognitionMentions::class)->sync($actor, $current, $data['mention_ids'] ?? [], $reply);
            // A previous text preview must never survive an author edit.
            DB::table('notifications')->where('type', FeedReplyNotification::class)->where('data->kudos_reply_id', $reply->id)->delete();
            AuditLog::create(['user_id' => $actor->id, 'action' => 'hr.feed.kudos_reply.edited', 'auditable_type' => HrKudosReply::class,
                'auditable_id' => $reply->id, 'meta' => ['kudos_id' => (int) $current->id, 'previous_version' => $previous, 'version' => $previous + 1]]);
        });
    }

    public function moderate(User $actor, HrKudos $kudos, int $replyId, array $data): void
    {
        DB::transaction(function () use ($actor, $kudos, $replyId, $data): void {
            [$actor, $current] = $this->writeContext($actor, $kudos, $data, moderation: true);
            $this->assertSocialReady();
            abort_unless($this->canModerate($current, $actor), 403);
            $reply = HrKudosReply::where('kudos_id', $current->id)->whereNull('author_removed_at')->whereKey($replyId)->lockForUpdate()->firstOrFail();
            abort_if((int) $data['version'] !== (int) $reply->version, 409, 'This comment changed. Reload before moderating it.');
            $reason = trim($data['reason']);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Give a reason for this moderation change.']);
            }
            $previous = (int) $reply->version;
            $reply->forceFill(['hidden_at' => $data['hidden'] ? now() : null, 'moderated_by' => $actor->id,
                'moderation_reason' => $reason, 'version' => $previous + 1])->save();
            DB::table('notifications')->where('type', FeedReplyNotification::class)->where('data->kudos_reply_id', $reply->id)->delete();
            DB::table('notifications')->where('type', RecognitionMentionNotification::class)
                ->where('data->kudos_id', $current->id)->where('data->source_key', 'reply:'.$reply->id)->delete();
            AuditLog::create(['user_id' => $actor->id, 'action' => 'hr.feed.kudos_reply.'.($data['hidden'] ? 'hidden' : 'restored'),
                'auditable_type' => HrKudosReply::class, 'auditable_id' => $reply->id,
                'meta' => ['kudos_id' => (int) $current->id, 'reason' => $reason, 'previous_version' => $previous, 'version' => $previous + 1]]);
        });
    }

    /** @return Collection<int,array<string,mixed>> */
    public function project(User $viewer, HrKudos $kudos): Collection
    {
        $viewer = $viewer->fresh();
        $current = HrKudos::find($kudos->id);
        if (! $viewer || ! $current || ! $this->feed->canViewKudos($current, $viewer)) {
            return collect();
        }
        $canRemove = $this->ready() && $this->canParticipate($current, $viewer);
        $canModerate = $this->canModerate($current, $viewer);

        // Re-read even when a caller retained an eager-loaded thread before removal.
        $all = HrKudosReply::where('kudos_id', $current->id)->with('user:id,name')->orderBy('created_at')->orderBy('id')->get();
        $byId = $all->keyBy('id');
        $retained = [];
        foreach ($all->whereNotNull('body') as $reply) {
            $parent = $reply->parent_id;
            for ($depth = 0; $parent && $depth < 8 && ! isset($retained[$parent]); $depth++) {
                $retained[$parent] = true;
                $parent = $byId->get($parent)?->parent_id;
            }
        }

        return $all->filter(fn ($reply) => $reply->body !== null || isset($retained[$reply->id]))
            ->map(function (HrKudosReply $reply) use ($canRemove, $canModerate, $viewer, $current): array {
                $removed = $reply->body === null || $reply->author_removed_at !== null;
                $hidden = $reply->hidden_at !== null;
                $readable = ! $removed && (! $hidden || $canModerate);

                return [
                    'id' => (int) $reply->id, 'user_id' => (int) $reply->user_id,
                    'name' => $reply->user?->name ?? 'Former colleague', 'body' => $readable ? $reply->body : null,
                    'parent_id' => $reply->parent_id ? (int) $reply->parent_id : null,
                    'removed' => $removed, 'hidden' => $hidden,
                    'created_at' => $reply->created_at?->toIso8601String(),
                    'edited_at' => $reply->edited_at?->toIso8601String(),
                    'version' => (int) ($reply->version ?? 1),
                    'can_remove' => ! $removed && $canRemove && (int) $reply->user_id === (int) $viewer->id,
                    'can_edit' => $readable && ! $hidden && $this->socialReady() && $canRemove && (int) $reply->user_id === (int) $viewer->id,
                    'can_moderate' => ! $removed && $canModerate,
                    'moderation_reason' => $canModerate ? $reply->moderation_reason : null,
                    'mentions' => $readable ? app(HrRecognitionMentions::class)->project($viewer, $current, (int) $reply->id) : [],
                    'images' => $readable ? app(HrRecognitionMedia::class)->project($current, (int) $reply->id) : [],
                ];
            });
    }

    public function remove(User $viewer, HrKudos $kudos, int $replyId, array $context): void
    {
        DB::transaction(function () use ($viewer, $kudos, $replyId, $context): void {
            $viewer = $viewer->fresh();
            abort_unless($viewer, 403);
            $current = HrKudos::whereKey($kudos->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->feed->canViewKudos($current, $viewer), 404);
            abort_unless($this->canParticipate($current, $viewer), 403);
            abort_if((int) $context['actor_id'] !== (int) $viewer->id, 409, 'Your signed-in account changed. Reload the conversation.');
            if (! $this->ready()) {
                throw ValidationException::withMessages(['reply' => 'Reply removal is not available yet.']);
            }
            $reply = HrKudosReply::where('kudos_id', $current->id)->whereKey($replyId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $reply->user_id === (int) $viewer->id, 403);
            abort_if((int) $context['version'] !== (int) $reply->version, 409, 'This reply has changed. Reload the conversation.');
            if ($reply->author_removed_at) {
                return;
            }
            $previousVersion = (int) $reply->version;
            $reply->forceFill(['body' => null, 'author_removed_at' => now(), 'version' => $previousVersion + 1])->save();
            app(HrRecognitionMentions::class)->clear($current, (int) $reply->id);
            app(HrRecognitionMedia::class)->remove($current, (int) $reply->id);

            // New bell notifications carry this source ID; remove their text too.
            DB::table('notifications')->where('type', FeedReplyNotification::class)
                ->where('data->kudos_reply_id', $reply->id)->delete();
            AuditLog::create([
                'user_id' => $viewer->id, 'action' => 'hr.feed.kudos_reply.removed',
                'auditable_type' => HrKudosReply::class, 'auditable_id' => $reply->id,
                'meta' => ['kudos_id' => (int) $current->id, 'reply_id' => (int) $reply->id, 'reason' => 'author_removed',
                    'previous_version' => $previousVersion, 'version' => $previousVersion + 1],
            ]);
        });
    }
}
