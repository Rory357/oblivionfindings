<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrAutomationRule;
use App\Domain\Hr\Models\HrAutomationRun;
use App\Domain\Hr\Notifications\HrScheduledReportReadyNotification;
use App\Domain\Hr\Services\HrReportingService;
use App\Models\User;
use App\Notifications\AppEventNotification;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class HrAutomationService
{
    public const ACTION_NOTIFY_USERS = 'notify_users';
    public const ACTION_NOTIFY_ROLE_GROUP = 'notify_role_group';
    public const ACTION_QUEUE_REPORT_EXPORT = 'queue_report_export';

    /**
     * @return array<int, string>
     */
    public function supportedActionTypes(): array
    {
        return [
            self::ACTION_NOTIFY_USERS,
            self::ACTION_NOTIFY_ROLE_GROUP,
            self::ACTION_QUEUE_REPORT_EXPORT,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handleEvent(?int $tenantId, string $eventType, array $payload): int
    {
        $rules = HrAutomationRule::query()
            ->forTenant($tenantId)
            ->active()
            ->where('event_type', $eventType)
            ->orderBy('id')
            ->get();

        if ($rules->isEmpty()) {
            return 0;
        }

        $executed = 0;

        foreach ($rules as $rule) {
            try {
                if (! $this->matchesConditions($payload, (array) ($rule->conditions ?? []))) {
                    $this->recordRun($rule, $tenantId, $eventType, $payload, 'skipped', 'Conditions did not match.');
                    continue;
                }

                $this->executeActions($rule, $tenantId, $eventType, $payload);
                $this->recordRun($rule, $tenantId, $eventType, $payload, 'success', 'Rule executed successfully.');
                $executed++;

                if ($rule->stop_on_match) {
                    break;
                }
            } catch (\Throwable $exception) {
                $this->recordRun($rule, $tenantId, $eventType, $payload, 'failed', $exception->getMessage());
            }
        }

        return $executed;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $conditions
     */
    protected function matchesConditions(array $payload, array $conditions): bool
    {
        $equals = collect(Arr::get($conditions, 'equals', []));
        foreach ($equals as $field => $expectedValue) {
            $actual = data_get($payload, (string) $field);
            if ((string) $actual !== (string) $expectedValue) {
                return false;
            }
        }

        $in = collect(Arr::get($conditions, 'in', []));
        foreach ($in as $field => $expectedList) {
            $actual = data_get($payload, (string) $field);
            $list = collect(is_array($expectedList) ? $expectedList : [$expectedList])->map(fn ($item) => (string) $item);
            if (! $list->contains((string) $actual)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function executeActions(HrAutomationRule $rule, ?int $tenantId, string $eventType, array $payload): void
    {
        $actions = collect((array) ($rule->actions ?? []));

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            $actionType = (string) ($action['type'] ?? '');

            match ($actionType) {
                self::ACTION_NOTIFY_USERS => $this->actionNotifyUsers($tenantId, $eventType, $payload, $action),
                self::ACTION_NOTIFY_ROLE_GROUP => $this->actionNotifyRoleGroup($eventType, $payload, $action),
                self::ACTION_QUEUE_REPORT_EXPORT => $this->actionQueueReportExport($tenantId, $action),
                default => null,
            };
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $action
     */
    protected function actionNotifyUsers(?int $tenantId, string $eventType, array $payload, array $action): void
    {
        $userIds = collect($action['user_ids'] ?? [])
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return;
        }

        $users = User::query()
            ->whereIn('id', $userIds->all())
            ->get();

        $title = (string) ($action['title'] ?? 'HR automation event');
        $body = (string) ($action['body'] ?? "Automation rule triggered for {$eventType}.");
        $url = isset($action['url']) ? (string) $action['url'] : null;

        $notification = new AppEventNotification([
            'kind' => 'hr_automation',
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'event_type' => $eventType,
            'tenant_id' => $tenantId,
            'data' => $payload,
        ]);

        $users->each(fn (User $user) => $user->notify($notification));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $action
     */
    protected function actionNotifyRoleGroup(string $eventType, array $payload, array $action): void
    {
        $roleGroup = (string) ($action['role_group'] ?? '');
        if ($roleGroup === '') {
            return;
        }

        $notificationService = app(\App\Services\NotificationService::class);
        $userIds = $notificationService->resolveRoleGroupUserIds($roleGroup);
        if ($userIds->isEmpty()) {
            return;
        }

        $users = User::query()->whereIn('id', $userIds->all())->get();

        $title = (string) ($action['title'] ?? 'HR automation event');
        $body = (string) ($action['body'] ?? "Automation rule triggered for {$eventType}.");
        $url = isset($action['url']) ? (string) $action['url'] : null;

        $notification = new AppEventNotification([
            'kind' => 'hr_automation',
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'event_type' => $eventType,
            'data' => $payload,
        ]);

        $users->each(fn (User $user) => $user->notify($notification));
    }

    /**
     * @param array<string, mixed> $action
     */
    protected function actionQueueReportExport(?int $tenantId, array $action): void
    {
        $reportType = (string) ($action['report_type'] ?? '');
        if ($reportType === '') {
            return;
        }

        $filters = is_array($action['filters'] ?? null) ? $action['filters'] : [];

        $reportingService = app(HrReportingService::class);
        $export = $reportingService->createExport(
            reportType: $reportType,
            tenantId: $tenantId,
            filters: $filters,
            generatedBy: null,
        );

        $recipientIds = collect($action['recipient_user_ids'] ?? [])
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($recipientIds->isEmpty()) {
            return;
        }

        User::query()
            ->whereIn('id', $recipientIds->all())
            ->chunkById(100, function ($users) use ($export) {
                foreach ($users as $user) {
                    $user->notify(new HrScheduledReportReadyNotification($export));
                }
            });
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function recordRun(
        HrAutomationRule $rule,
        ?int $tenantId,
        string $eventType,
        array $payload,
        string $status,
        string $message
    ): void {
        HrAutomationRun::query()->create([
            'rule_id' => $rule->id,
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'event_payload' => $payload,
            'status' => $status,
            'message' => mb_substr($message, 0, 1900),
            'executed_at' => now(),
        ]);

        $rule->update([
            'last_ran_at' => now(),
            'last_status' => $status,
            'last_error' => $status === 'failed' ? mb_substr($message, 0, 1900) : null,
        ]);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function actionOptions(): array
    {
        return [
            ['value' => self::ACTION_NOTIFY_USERS, 'label' => 'Notify users'],
            ['value' => self::ACTION_NOTIFY_ROLE_GROUP, 'label' => 'Notify role group'],
            ['value' => self::ACTION_QUEUE_REPORT_EXPORT, 'label' => 'Generate report export'],
        ];
    }
}
