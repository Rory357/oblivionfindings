<?php

namespace App\Domain\Hr\Services;

use App\Domain\CompanyHub\Services\HubRecognition;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrKudosReply;
use App\Domain\Hr\Notifications\RecognitionMentionNotification;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Mentions are explicit current recipients; text never grants an audience. */
class HrRecognitionMentions
{
    public function __construct(private readonly FeedService $feed, private readonly HrCurrentStaffService $staff) {}

    public function ready(): bool
    {
        return Schema::hasTable('hr_recognition_mentions');
    }

    public static function rules(): array
    {
        return ['mention_ids' => ['sometimes', 'array', 'max:20'], 'mention_ids.*' => ['integer', 'min:1', 'distinct']];
    }

    public function options(User $actor, ?HrKudos $kudos = null, ?array $onlyIds = null): array
    {
        $actor = $actor->fresh();
        if (! $actor || ! $this->staff->isCurrent($actor) || ! $actor->canDo('hr.recognition.give')) {
            return [];
        }
        if ($kudos && ! $this->feed->canReplyKudos($kudos, $actor)) {
            return [];
        }

        return (new UserSiteAccessService)->applyStaffScope($this->staff->currentUsersQuery(), $actor)
            ->when($onlyIds !== null, fn ($query) => $query->whereKey($onlyIds))->orderBy('name')->get(['users.id', 'users.name'])
            ->filter(fn (User $person) => $person->canDo('hr.recognition.view') && $person->canDo('company_hub.view')
                && (! $kudos || app(HubRecognition::class)->describe($person, $kudos) !== null))
            ->map(fn (User $person) => ['id' => (int) $person->id, 'name' => $person->name])->values()->all();
    }

    public function sync(User $actor, HrKudos $kudos, array $ids, ?HrKudosReply $reply = null): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (! $this->ready()) {
            if ($ids !== []) {
                throw ValidationException::withMessages(['mention_ids' => 'Mentions are not available yet.']);
            }

            return;
        }
        $allowed = $ids === [] ? [] : array_column($this->options($actor, $kudos, $ids), 'id');
        if (array_diff($ids, $allowed) !== []) {
            throw ValidationException::withMessages(['mention_ids' => 'Choose current colleagues who can read this shout-out within your approved sites.']);
        }
        $source = $reply ? 'reply:'.$reply->id : 'post';
        $query = DB::table('hr_recognition_mentions')->where('kudos_id', $kudos->id)->where('source_key', $source);
        $existing = (clone $query)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $removed = array_diff($existing, $ids);
        (clone $query)->whereIn('user_id', $removed)->delete();
        if ($removed !== []) {
            DB::table('notifications')->where('type', RecognitionMentionNotification::class)
                ->where('data->kudos_id', $kudos->id)->where('data->source_key', $source)->whereIn('notifiable_id', $removed)->delete();
        }
        foreach (array_diff($ids, $existing) as $id) {
            DB::table('hr_recognition_mentions')->insert(['kudos_id' => $kudos->id, 'reply_id' => $reply?->id,
                'source_key' => $source, 'user_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            if ($id !== (int) $actor->id) {
                try {
                    User::find($id)?->notify(new RecognitionMentionNotification((int) $kudos->id, $reply?->id, (int) $actor->id));
                } catch (\Throwable $exception) {
                    Log::warning('Recognition mention notification could not be queued', ['kudos_id' => $kudos->id, 'recipient_id' => $id]);
                }
            }
        }
    }

    public function project(User $viewer, HrKudos $kudos, ?int $replyId = null): array
    {
        if (! $this->ready()) {
            return [];
        }
        $ids = DB::table('hr_recognition_mentions')->where('kudos_id', $kudos->id)->where('reply_id', $replyId)->pluck('user_id');

        return (new UserSiteAccessService)->applyStaffScope($this->staff->currentUsersQuery(), $viewer)->whereKey($ids)->get(['users.id', 'users.name'])
            ->filter(fn (User $person) => $person->canDo('hr.recognition.view') && $this->feed->canViewKudos($kudos, $person))
            ->map(fn (User $person) => ['id' => (int) $person->id, 'name' => $person->name])->values()->all();
    }

    public function clear(HrKudos $kudos, int $replyId): void
    {
        if ($this->ready()) {
            DB::table('hr_recognition_mentions')->where('kudos_id', $kudos->id)->where('reply_id', $replyId)->delete();
        }
        DB::table('notifications')->where('type', RecognitionMentionNotification::class)
            ->where('data->kudos_id', $kudos->id)->where('data->source_key', 'reply:'.$replyId)->delete();
    }
}
