<?php

namespace App\Domain\SecurityDevices\Reports;

use App\Domain\Privacy\Services\PrivacyRecipients;
use App\Domain\SecurityDevices\Setup\Models\SecurityDeviceSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The monthly report email (pg-sdr-18b): a scheduled email Oblivion sends itself on the 1st of
 * each month at 7:00 am NZ time, to people chosen from those who may see reports. Off until someone
 * turns it on. Stored with the other Security & devices switches (security_device_settings) and,
 * like them, every change is audited and shows in Setup › Change history.
 *
 * Who may change it: people who see reports and decide who is told about alerts (the same key as
 * Setup's alert notices), so no new permission is needed.
 */
final class MonthlyReportEmail
{
    public const string ON = 'reports.monthly_email';

    public const string RECIPIENTS = 'reports.monthly_email.recipients';

    /** The month last sent, so a second run on the 1st never sends it twice. Not a setting people see. */
    public const string SENT = 'reports.monthly_email.sent_month';

    public const string CHANGE = 'securityDevices.automations.manage';

    public const int SEND_HOUR = 7;

    public const int MAX_RECIPIENTS = 25;

    public static function canChange(User $viewer): bool
    {
        return $viewer->canDo(MonthlyReport::VIEW) && $viewer->canDo(self::CHANGE);
    }

    /** When the email for the month that ends at $monthEnd goes: the 1st, 7:00 am NZ time. */
    public static function sendTime(CarbonImmutable $monthEnd): CarbonImmutable
    {
        return $monthEnd->setTimezone(MonthlyReport::NZ)->startOfMonth()->setTime(self::SEND_HOUR, 0)->utc();
    }

    public function on(): bool
    {
        return $this->stored(self::ON) === true;
    }

    /** @return list<int> */
    public function recipientIds(): array
    {
        $value = $this->stored(self::RECIPIENTS);

        return is_array($value) ? array_values(array_unique(array_map('intval', array_filter($value, 'is_numeric')))) : [];
    }

    /**
     * People who may get it now: approved, with an email address, holding the reports key.
     *
     * @return Collection<int, User>
     */
    public function choices(): Collection
    {
        return PrivacyRecipients::withPermission(MonthlyReport::VIEW)
            ->filter(fn (User $user): bool => $user->isApproved() && filled($user->email))
            ->sortBy(fn (User $user): string => mb_strtolower((string) $user->name))
            ->values();
    }

    /**
     * The chosen people who may still get it (a person who lost the key since is skipped).
     *
     * @return Collection<int, User>
     */
    public function recipients(): Collection
    {
        $ids = $this->recipientIds();

        return $ids === [] ? collect() : $this->choices()->filter(fn (User $user): bool => in_array((int) $user->id, $ids, true))->values();
    }

    /** @return array<string, mixed> */
    public function forViewer(User $viewer, CarbonImmutable $now): array
    {
        $canChange = self::canChange($viewer);
        $person = fn (User $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name];
        $monthStart = $now->setTimezone(MonthlyReport::NZ)->startOfMonth();
        $next = self::sendTime($monthStart->utc());
        if ($now->gte($next)) {
            $next = self::sendTime($monthStart->addMonth()->utc());
        }

        return [
            'on' => $this->on(),
            'recipients' => $this->recipients()->map($person)->values()->all(),
            'next_at' => $next->toIso8601String(),
            'can_change' => $canChange,
            'choices' => $canChange ? $this->choices()->map($person)->values()->all() : [],
        ];
    }

    /**
     * Turn the email on or off and choose who gets it. Each real change is audited with its old
     * and new value.
     *
     * @param  list<mixed>  $recipientIds
     * @return list<string> the keys that changed
     */
    public function update(User $actor, bool $on, array $recipientIds): array
    {
        if (! self::canChange($actor)) {
            throw new AuthorizationException('You can’t change the monthly email.');
        }
        $allowed = $this->choices()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $ids = array_values(array_unique(array_map('intval', array_filter($recipientIds, 'is_numeric'))));
        sort($ids);
        if (array_diff($ids, $allowed) !== []) {
            throw ValidationException::withMessages(['recipients' => 'Choose people who can see reports.']);
        }
        if (count($ids) > self::MAX_RECIPIENTS) {
            throw ValidationException::withMessages(['recipients' => 'Choose up to '.self::MAX_RECIPIENTS.' people.']);
        }
        if ($on && $ids === []) {
            throw ValidationException::withMessages(['recipients' => 'Choose at least one person to email.']);
        }

        return DB::transaction(function () use ($actor, $on, $ids): array {
            $changed = [];
            foreach ([self::ON => $on, self::RECIPIENTS => $ids] as $key => $value) {
                $row = SecurityDeviceSetting::query()->where('key', $key)->lockForUpdate()->first();
                $before = $row?->value ?? ($key === self::ON ? false : []);
                if ($before === $value) {
                    continue;
                }
                $row ??= new SecurityDeviceSetting(['key' => $key]);
                $row->value = $value;
                $row->updated_by_user_id = $actor->id;
                $row->save();
                AuditLogger::logOrFail('security_devices.settings.changed', $row, [
                    'actor_id' => (int) $actor->id,
                    'key' => $key,
                    'from' => $before,
                    'to' => $value,
                    'loosens_safety' => false,
                    'reason' => null,
                ]);
                $changed[] = $key;
            }

            return $changed;
        }, 3);
    }

    /** Claim a month for sending; false when it was already sent. */
    public function claim(string $month): bool
    {
        return DB::transaction(function () use ($month): bool {
            $row = SecurityDeviceSetting::query()->where('key', self::SENT)->lockForUpdate()->first();
            if ($row !== null && $row->value === $month) {
                return false;
            }
            $row ??= new SecurityDeviceSetting(['key' => self::SENT]);
            $row->value = $month;
            $row->save();

            return true;
        }, 3);
    }

    /** Undo a claim when nothing was queued, so a later run sends the month. */
    public function release(string $month): void
    {
        SecurityDeviceSetting::query()->where('key', self::SENT)->where('value', $month)->delete();
    }

    private function stored(string $key): mixed
    {
        return SecurityDeviceSetting::query()->where('key', $key)->value('value');
    }
}
