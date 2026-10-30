<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Services\Tasks\TaskItem;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class ReferralFollowUpPolicy
{
    public static function defaults(): array
    {
        // Preserve the shared historical behaviour until a changed version is published.
        return ['manager_after_days' => 3, 'day_basis' => 'calendar', 'office_hours_only' => false];
    }

    public function configuration(string $source, int $id): ?array
    {
        $referralId = match ($source) {
            'referral_work' => ReferralWorkItem::whereKey($id)->value('referral_id'),
            'referral_next' => $id,
            default => null,
        };
        $versionId = $referralId ? CareReferral::whereKey($referralId)->value('settings_version_id') : null;

        return $versionId ? ReferralSettingsVersion::whereKey($versionId)->where('state', 'published')->value('configuration') : null;
    }

    public function allowsNotification(TaskItem $item): bool
    {
        $configuration = $this->configuration($item->identitySource(), $item->numericId());

        return $configuration !== null && self::windowOpen($configuration, now());
    }

    public function managerDue(TaskItem $item): bool
    {
        $configuration = $this->configuration($item->identitySource(), $item->numericId());
        // A task feed may have been projected before staff rescheduled the work.
        $due = match ($item->identitySource()) {
            'referral_work' => ReferralWorkItem::whereKey($item->numericId())->where('status', 'open')
                ->whereHas('referral', fn ($query) => $query->where('stage', '!=', 'closed'))->value('due_at'),
            'referral_next' => CareReferral::whereKey($item->numericId())->whereNull('primary_work_item_id')
                ->where('stage', '!=', 'closed')->value('next_due_at'),
            default => null,
        };

        return $configuration !== null && $due !== null
            && self::thresholdReached($configuration, CarbonImmutable::parse($due), now());
    }

    public static function windowOpen(array $configuration, DateTimeInterface $at): bool
    {
        $policy = $configuration['follow_up'] ?? self::defaults();
        if (! ($policy['office_hours_only'] ?? false)) {
            return true;
        }
        $calendar = $configuration['business_calendar'] ?? null;
        if (ReferralWorkingTime::seconds($at, $at, $calendar) === null) {
            return false;
        }
        $local = CarbonImmutable::instance($at)->timezone($calendar['timezone']);

        return in_array($local->isoWeekday(), $calendar['weekdays'], true)
            && ! in_array($local->toDateString(), $calendar['holidays'], true)
            && $local->format('H:i') >= $calendar['opening_time']
            && $local->format('H:i') < $calendar['closing_time'];
    }

    public static function thresholdReached(array $configuration, DateTimeInterface $due, DateTimeInterface $at): bool
    {
        if (! self::windowOpen($configuration, $at)) {
            return false;
        }
        $policy = $configuration['follow_up'] ?? self::defaults();
        $days = (int) ($policy['manager_after_days'] ?? 0);
        if ($days < 1 || $days > 30) {
            return false;
        }
        if (($policy['day_basis'] ?? null) === 'calendar') {
            return CarbonImmutable::instance($due)->lte(CarbonImmutable::instance($at)->subDays($days));
        }
        if (($policy['day_basis'] ?? null) !== 'business') {
            return false;
        }
        $calendar = $configuration['business_calendar'] ?? null;
        $elapsed = ReferralWorkingTime::seconds($due, $at, $calendar);
        if ($elapsed === null) {
            return false;
        }
        $minutes = fn ($time) => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        $daySeconds = ($minutes($calendar['closing_time']) - $minutes($calendar['opening_time'])) * 60;

        return $elapsed >= $days * $daySeconds;
    }
}
