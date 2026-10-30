<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Models\HubRevision;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Operational poll state never changes an approved content snapshot or vote key. */
class HubPolls
{
    public function ready(): bool
    {
        return Schema::hasTable('hub_poll_states');
    }

    private function access(): HubAccess
    {
        return new HubAccess(app(HrCurrentStaffService::class), new UserSiteAccessService);
    }

    public function actor(User $user): User
    {
        $viewer = User::find($user->id);
        abort_unless($viewer, 403);
        $this->access()->assertEnter($viewer);
        abort_unless((new UserSiteAccessService)->applyStaffScope(User::query(), $viewer)->whereKey($viewer->id)->exists(), 403);

        return $viewer;
    }

    public function visibleRevision(User $viewer, HubPage $page): ?HubRevision
    {
        return $this->access()->visibleRevision($viewer, $page);
    }

    public function revision(User $viewer, HubPage $page): HubRevision
    {
        return $this->access()->assertRead($viewer, $page);
    }

    /** Explicit instants are UTC; legacy dates retain their original local end of day. */
    public static function closingInstant(?string $value, bool $allowLegacy = true): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            if ($allowLegacy && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.worker_timezone', 'Pacific/Auckland'));
                if ($date && $date->format('Y-m-d') === $value) {
                    return $date->endOfDay()->utc();
                }
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D', $value)) {
                $date = CarbonImmutable::parse($value);
                if ($date->format('Y-m-d\TH:i:s') === substr($value, 0, 19)) {
                    return $date->utc();
                }
            }
        } catch (\Throwable) {
            // Invalid calendar dates must not be silently normalised into another day.
        }
        throw ValidationException::withMessages(['closes_at' => 'Choose an exact closing time with its timezone.']);
    }

    private function state(HubPage $page, array $block): ?object
    {
        return $this->ready() ? DB::table('hub_poll_states')->where('hub_page_id', $page->id)->where('poll_key', HubParticipation::pollKey($block))->first() : null;
    }

    private function schedule(HubRevision $revision, array $block, ?object $state): array
    {
        $applied = $state && (int) $state->applied_revision_id === (int) $revision->id;
        $deadline = $applied && $state->closes_at_override
            ? CarbonImmutable::parse($state->closes_at_override, 'UTC')
            : self::closingInstant($block['closes_at'] ?? null);

        return ['closed' => ($applied && $state->manual_closed_at !== null) || ($deadline && $deadline->lte(now())),
            'closes_at' => $deadline?->toISOString(), 'version' => (int) ($state->version ?? 0)];
    }

    /** Keep only current canonical choices; identities stay inside this service. */
    public function votes(HubPage $page, array $block): Collection
    {
        $key = HubParticipation::pollKey($block);

        return DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('kind', 'vote')
            ->whereNull('hidden_at')->whereNull('author_removed_at')->whereNull('moderator_hidden_at')->whereNull('parent_hidden_at')->whereNull('legacy_hidden_at')
            ->orderByDesc('id')->get(['user_id', 'body', 'created_at'])->map(function ($vote) use ($key, $block): ?array {
                $data = json_decode((string) $vote->body, true);
                if (! is_array($data) || ($data['key'] ?? null) !== $key) {
                    return null;
                }
                $choice = $data['choice'] ?? null;
                // Older form submissions stored the validated index as a numeric string.
                $validChoice = is_int($choice) || (is_string($choice) && preg_match('/^[0-7]$/D', $choice));
                if (! $validChoice || ! array_key_exists((int) $choice, $block['options'] ?? [])) {
                    return null;
                }

                return ['user_id' => (int) $vote->user_id, 'choice' => (int) $choice, 'created_at' => $vote->created_at];
            })->filter()->unique('user_id')->values();
    }

    public function project(User $viewer, HubPage $page, HubRevision $revision, array $block): array
    {
        $schedule = $this->schedule($revision, $block, $this->state($page, $block));
        $votes = $this->votes($page, $block);
        $mine = $votes->firstWhere('user_id', (int) $viewer->id)['choice'] ?? null;
        $visible = $mine !== null || $schedule['closed'];

        return ['key' => HubParticipation::pollKey($block), 'mine' => $mine, ...$schedule, 'timezone' => 'Pacific/Auckland',
            'results_visible' => $visible, 'total' => $visible ? $votes->count() : null,
            'counts' => $visible ? collect($block['options'])->map(fn ($option, $index) => $votes->where('choice', $index)->count())->all() : null,
            'can_manage' => $this->ready() && $viewer->canDo('company_hub.publish') && $this->access()->canEdit($viewer, $page)];
    }

    public function assertVote(User $viewer, HubPage $page, HubRevision $revision, array $block, array $data): void
    {
        $this->context($viewer, $revision, $block, $data);
        $schedule = $this->schedule($revision, $block, $this->state($page, $block));
        abort_if(isset($data['poll_version']) && (int) $data['poll_version'] !== $schedule['version'], 409, 'This poll changed. Reload before voting.');
        abort_if($schedule['closed'], 422, 'This poll is closed.');
        abort_unless(array_key_exists($data['choice'], $block['options']), 422);
    }

    private function context(User $viewer, HubRevision $revision, array $block, array $data): void
    {
        abort_if(isset($data['actor_id']) && (int) $data['actor_id'] !== (int) $viewer->id, 409, 'Your signed-in account changed. Reopen the poll.');
        abort_if(isset($data['revision_id']) && (int) $data['revision_id'] !== (int) $revision->id, 409, 'This page changed. Reload the current poll.');
        abort_unless(hash_equals(HubParticipation::pollKey($block), $data['poll_key']), 409, 'This poll has changed. Reload to see the current question.');
    }

    public function transition(User $user, HubPage $page, string $blockId, array $data): array
    {
        return DB::transaction(function () use ($user, $page, $blockId, $data): array {
            $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $viewer = $this->actor($user);
            $revision = $this->access()->assertRead($viewer, $page);
            $block = HubContent::visibleBlocks($revision->content)->firstWhere('id', $blockId);
            abort_unless($block && $block['type'] === 'poll', 404);
            abort_unless($viewer->canDo('company_hub.publish') && $this->access()->canEdit($viewer, $page), 403);
            $this->context($viewer, $revision, $block, $data);
            abort_unless($this->ready(), 422, 'Poll management is not available yet. Reload after the update is complete.');
            $state = $this->state($page, $block);
            $schedule = $this->schedule($revision, $block, $state);
            abort_unless((int) $data['version'] === $schedule['version'], 409, 'This poll changed. Reload before managing it.');
            $deadline = isset($data['closes_at']) ? self::closingInstant($data['closes_at'], false) : null;
            if ($data['action'] === 'reopen') {
                if ($deadline && $deadline->lte(now())) {
                    throw ValidationException::withMessages(['closes_at' => 'Choose a future closing time to reopen this poll.']);
                }
                if (! $deadline && $schedule['closes_at'] && CarbonImmutable::parse($schedule['closes_at'])->lte(now())) {
                    throw ValidationException::withMessages(['closes_at' => 'Choose a future closing time to reopen this poll.']);
                }
            } elseif ($deadline !== null) {
                throw ValidationException::withMessages(['closes_at' => 'A new closing time belongs to reopening the poll.']);
            }
            $applied = $state && (int) $state->applied_revision_id === (int) $revision->id;
            $override = $deadline?->format('Y-m-d H:i:s.u') ?? ($applied ? $state->closes_at_override : null);
            $values = ['applied_revision_id' => $revision->id, 'manual_closed_at' => $data['action'] === 'close' ? now() : null,
                'closes_at_override' => $override, 'version' => $schedule['version'] + 1, 'changed_by' => $viewer->id, 'updated_at' => now()];
            $key = HubParticipation::pollKey($block);
            if ($state) {
                DB::table('hub_poll_states')->where('id', $state->id)->update($values);
            } else {
                DB::table('hub_poll_states')->insert(['hub_page_id' => $page->id, 'poll_key' => $key, 'created_at' => now(), ...$values]);
            }
            AuditLog::create(['user_id' => $viewer->id, 'action' => 'company_hub.poll.'.$data['action'], 'auditable_type' => HubPage::class, 'auditable_id' => $page->id,
                'meta' => ['revision_id' => $revision->id, 'block_id' => $blockId, 'poll_key' => $key, 'old_version' => $schedule['version'], 'new_version' => $values['version'], 'closes_at' => $deadline?->toISOString()]]);

            return ['saved' => true, 'page_id' => (int) $page->id, 'block_id' => $blockId, 'key' => $key, 'version' => $values['version']];
        });
    }
}
