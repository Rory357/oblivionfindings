<?php

namespace App\Services\Tasks\Providers;

use App\Models\Client;
use App\Models\SafeguardingConcern;
use App\Models\User;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

class SafeguardingConcernProvider implements TaskProvider
{
    public function sourceKey(): string
    {
        return 'safeguarding';
    }

    public function label(): string
    {
        return 'Safeguarding Concerns';
    }

    public function canView(User $user): bool
    {
        // Mirrors routes/safeguarding.php: the register is gated by safeguarding.viewAny.
        return $user->canDo('safeguarding.viewAny');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = SafeguardingConcern::query()
            ->with(['assignedTo:id,name', 'site:id,name'])
            ->orderByDesc('reported_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereNotIn('status', SafeguardingConcern::TERMINAL_STATUSES);
        }

        return $query->get()->map(function (SafeguardingConcern $concern) {
            // Need-to-know: titles use the concern-type label only, and sensitive
            // rows never expose free-text or the subject (mirrors the register's
            // per-row redaction without widening access).
            $sensitive = (bool) $concern->is_sensitive;

            return new TaskItem(
                id: 'safeguarding-'.$concern->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $concern->reference_number,
                title: ucfirst(str_replace('_', ' ', (string) $concern->concern_type)).' concern',
                status: (string) $concern->status,
                bucket: match (true) {
                    in_array($concern->status, SafeguardingConcern::TERMINAL_STATUSES, true) => TaskItem::BUCKET_DONE,
                    $concern->status === 'reported' => TaskItem::BUCKET_OPEN,
                    default => TaskItem::BUCKET_IN_PROGRESS,
                },
                severity: TaskItem::normaliseSeverity($concern->severity),
                assignee: $concern->assignedTo
                    ? ['id' => $concern->assignedTo->id, 'name' => $concern->assignedTo->name]
                    : null,
                client: (! $sensitive && $concern->subject_type === Client::class && $concern->subject_id)
                    ? ['id' => (int) $concern->subject_id, 'name' => (string) $concern->subject_name]
                    : null,
                site: $concern->site
                    ? ['id' => $concern->site->id, 'name' => $concern->site->name]
                    : null,
                dueAt: null,
                createdAt: optional($concern->created_at)->toIso8601String(),
                link: "/safeguarding?concern={$concern->id}",
                type: 'Concern',
                description: (! $sensitive && $concern->description)
                    ? str($concern->description)->limit(140)->toString()
                    : null,
            );
        })->all();
    }
}
