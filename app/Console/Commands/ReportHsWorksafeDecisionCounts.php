<?php

namespace App\Console\Commands;

use App\Models\HsEvent;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

final class ReportHsWorksafeDecisionCounts extends Command
{
    protected $signature = 'health-safety:worksafe-decision-counts {--json}';

    protected $description = 'Report explicit WorkSafe decision lifecycle counts without changing H&S events';

    public function handle(): int
    {
        $counts = [
            'undecided' => $this->undecided()->count(),
            'explicit_not_notifiable' => $this->explicitNotNotifiable()->count(),
            'notifiable_pending' => $this->notifiableWithStatus(HsEvent::WORKSAFE_PENDING)->count(),
            'notified' => $this->notifiableWithStatus(HsEvent::WORKSAFE_NOTIFIED)->count(),
            'acknowledged' => $this->notifiableWithStatus(HsEvent::WORKSAFE_ACKNOWLEDGED)->count(),
            'closed_legacy_false' => $this->closedLegacyFalse()->count(),
            'inconsistent' => $this->inconsistent()->count(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($counts, JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['State', 'Count'],
                collect($counts)
                    ->map(fn (int $count, string $state): array => [$state, $count])
                    ->values()
                    ->all(),
            );
        }

        return $counts['inconsistent'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function base(): Builder
    {
        return HsEvent::query()->withoutGlobalScopes();
    }

    private function undecided(): Builder
    {
        return $this->base()
            ->whereNull('worksafe_notifiable')
            ->whereNull('worksafe_status')
            ->whereNull('worksafe_decided_at')
            ->whereNull('worksafe_decided_by_user_id')
            ->whereNull('worksafe_decision_reason')
            ->whereNull('worksafe_decision_source');
    }

    private function explicitNotNotifiable(): Builder
    {
        return $this->base()
            ->where('worksafe_notifiable', false)
            ->whereNull('worksafe_status')
            ->whereNotNull('worksafe_decided_at')
            ->whereNotNull('worksafe_decided_by_user_id')
            ->whereNotNull('worksafe_decision_reason')
            ->whereNotNull('worksafe_decision_source');
    }

    private function notifiableWithStatus(string $status): Builder
    {
        return $this->base()
            ->where('worksafe_notifiable', true)
            ->where('worksafe_status', $status)
            ->whereNotNull('worksafe_decided_at')
            ->whereNotNull('worksafe_decided_by_user_id')
            ->whereNotNull('worksafe_decision_reason')
            ->whereNotNull('worksafe_decision_source');
    }

    private function closedLegacyFalse(): Builder
    {
        return $this->base()
            ->where('status', HsEvent::STATUS_CLOSED)
            ->where('worksafe_notifiable', false)
            ->whereNull('worksafe_decided_at')
            ->whereNull('worksafe_decided_by_user_id')
            ->whereNull('worksafe_decision_reason')
            ->whereNull('worksafe_decision_source');
    }

    private function inconsistent(): Builder
    {
        return $this->base()->where(function (Builder $query): void {
            $query
                ->where(function (Builder $undecided): void {
                    $undecided->whereNull('worksafe_notifiable')
                        ->where(function (Builder $state): void {
                            $state->whereNotNull('worksafe_status')
                                ->orWhereNotNull('worksafe_notified_at')
                                ->orWhereNotNull('worksafe_acknowledged_at')
                                ->orWhereNotNull('worksafe_decided_at')
                                ->orWhereNotNull('worksafe_decided_by_user_id')
                                ->orWhereNotNull('worksafe_decision_reason')
                                ->orWhereNotNull('worksafe_decision_source');
                        });
                })
                ->orWhere(function (Builder $notNotifiable): void {
                    $notNotifiable->where('worksafe_notifiable', false)
                        ->where(function (Builder $state): void {
                            $state->whereNotNull('worksafe_status')
                                ->orWhereNotNull('worksafe_notified_at')
                                ->orWhereNotNull('worksafe_acknowledged_at');
                        });
                })
                ->orWhere(function (Builder $openFalseWithoutDecision): void {
                    $openFalseWithoutDecision
                        ->where('worksafe_notifiable', false)
                        ->where('status', '!=', HsEvent::STATUS_CLOSED)
                        ->where(function (Builder $metadata): void {
                            $metadata->whereNull('worksafe_decided_at')
                                ->orWhereNull('worksafe_decided_by_user_id')
                                ->orWhereNull('worksafe_decision_reason')
                                ->orWhereNull('worksafe_decision_source');
                        });
                })
                ->orWhere(function (Builder $notifiableWithoutDecision): void {
                    $notifiableWithoutDecision
                        ->where('worksafe_notifiable', true)
                        ->where(function (Builder $metadata): void {
                            $metadata->whereNull('worksafe_status')
                                ->orWhereNull('worksafe_decided_at')
                                ->orWhereNull('worksafe_decided_by_user_id')
                                ->orWhereNull('worksafe_decision_reason')
                                ->orWhereNull('worksafe_decision_source');
                        });
                });
        });
    }
}
