<?php

namespace App\Domain\SecurityDevices\Screens\Sites;

use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlertEvent;
use Carbon\CarbonImmutable;

/**
 * One device alert in the words of the Site page (Alerts tab 12h, Logbook 12i) and the Overview's
 * Activity (18c): who was told, what happened next and how it ended, from its own timeline. Nothing
 * is added that the timeline doesn't hold: the 6:00 am summary isn't built, so it's never said.
 *
 * A care rule's alert names its resident; `$hide` says whether this viewer may read that name
 * (false), must read "a person" instead of it (the name), or the resident isn't known (true).
 * Needs the alert's `events` (with `actor`) loaded.
 */
final class AlertWords
{
    /** CareRules' sentences, with the resident's name taken out. */
    private const array CARE_PATTERNS = [
        '/^.+? out of bed/u' => 'A person out of bed',
        '/^.+?’s door opened/u' => 'A person’s door opened',
        '/From .+?’s (falls|wandering) plan/u' => 'From a person’s $1 plan',
    ];

    public function __construct(private readonly DeviceAlert $alert, private readonly string|bool $hide = false) {}

    public function title(): string
    {
        return $this->text($this->alert->title) ?? 'An alert';
    }

    /** Any line of the alert's story, with the resident's name taken out when it must be. */
    public function text(?string $text): ?string
    {
        $text = $text === null ? null : trim($text);
        if ($text === null || $text === '') {
            return null;
        }
        if ($this->hide === false) {
            return $text;
        }
        if (is_string($this->hide) && $this->hide !== '') {
            $text = str_replace([$this->hide.'’s', $this->hide], ['a person’s', 'a person'], $text);
        }
        $text = (string) preg_replace(array_keys(self::CARE_PATTERNS), array_values(self::CARE_PATTERNS), $text);

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /** What the device said when the alert opened, for the Activity line under its title. */
    public function what(string $device): string
    {
        $opened = $this->first('opened');

        return $this->text($opened?->detail) ?? $this->text($opened?->text) ?? $device;
    }

    /** "Jordan Peri and Aroha Ngata told · app and email", or "Nobody was told". */
    public function told(): string
    {
        $told = $this->last('told');
        if ($told === null) {
            return 'Nobody was told';
        }
        $how = trim((string) $told->detail);

        return $told->text.($how !== '' ? ' · '.mb_strtolower($how) : '');
    }

    /** The logbook's line under an alert that opened: what it saw and who was told. */
    public function openedLine(): string
    {
        $opened = $this->first('opened');

        return implode(' · ', array_filter([$this->text($opened?->text), $this->told()]));
    }

    /** One line for the Activity table: who was told, then where it stands now. */
    public function next(CarbonImmutable $now): string
    {
        $told = $this->last('told')?->text ?? 'Nobody was told';
        $hazard = $this->alert->kind === 'scald_risk' && $this->alert->hazard_id !== null ? 'an H&S hazard was raised' : null;
        if ($this->alert->state === DeviceAlert::STATE_DONE) {
            $closed = $this->last('recovered');
            $how = match ($this->alert->close_reason) {
                'behind' => 'covered by the device in front of it',
                default => self::lower($this->text($closed?->text) ?? 'closed'),
            };
            $note = $this->alert->close_reason === 'done' ? $this->text($closed?->detail) : null;

            return implode(' · ', array_filter([$told, $hazard, $how.' at '.SiteStream::clock($this->alert->closed_at, $now, $this->alert->opened_at).($note !== null ? ': '.$note : '')]));
        }
        if ($this->alert->state === DeviceAlert::STATE_SNOOZED) {
            return implode(' · ', array_filter([$told, $hazard, 'snoozed until '.SiteStream::clock($this->alert->snoozed_until, $now)]));
        }
        $owner = $this->owner();

        return implode(' · ', array_filter([$told, $hazard, $owner !== null ? $owner.' has it' : 'still open, not acknowledged']));
    }

    /**
     * How it ended, for the Site's Alerts table: the badge and the line under it.
     *
     * @return array{badge: string, tone: string, note: string}
     */
    public function ended(CarbonImmutable $now): array
    {
        $alert = $this->alert;
        if ($alert->state === DeviceAlert::STATE_SNOOZED) {
            $snoozed = $this->last('snoozed');
            $why = $this->text($snoozed?->detail);

            return [
                'badge' => 'Snoozed',
                'tone' => 'neutral',
                'note' => 'Until '.SiteStream::clock($alert->snoozed_until, $now)
                    .($snoozed?->actor !== null ? ' · '.$snoozed->actor->name.($why !== null ? ': '.$why : '') : ($why !== null ? ' · '.$why : '')),
            ];
        }
        if ($alert->state === DeviceAlert::STATE_DONE) {
            $closed = $this->last('recovered');
            if ($alert->close_reason === 'done') {
                $note = $this->text($closed?->detail);

                return ['badge' => 'Done', 'tone' => 'success', 'note' => ($this->text($closed?->text) ?? 'Marked done').($note !== null ? ': '.$note : '')];
            }

            return $alert->close_reason === 'behind'
                ? ['badge' => 'Ended', 'tone' => 'neutral', 'note' => 'Covered by the device in front of it']
                : ['badge' => 'Ended', 'tone' => 'success', 'note' => $this->text($closed?->text) ?? 'Cleared by itself'];
        }
        $owner = $this->owner();
        if ($alert->kind === 'scald_risk' && $alert->hazard_id !== null) {
            return ['badge' => 'With H&S', 'tone' => 'info', 'note' => 'An H&S hazard was raised'.($owner !== null ? ' · '.$owner.' has it' : '')];
        }

        return [
            'badge' => 'Open',
            'tone' => $alert->severity === 'critical' ? 'critical' : 'warning',
            'note' => $owner !== null ? $owner.' has it' : 'Nobody has it yet',
        ];
    }

    /** The logbook's title for someone's action on the alert. */
    public function action(DeviceAlertEvent $event): string
    {
        $text = $this->text($event->text) ?? 'The alert changed';
        if ($event->kind === 'recovered') {
            return $this->alert->close_reason === 'done' ? (string) preg_replace('/\bmarked it done\b/u', 'marked the alert done', $text) : $text;
        }

        return match ($event->kind) {
            'acknowledged' => (string) preg_replace('/ has it$/u', ' took the alert', $text),
            'incident' => (string) preg_replace('/ from this alert$/u', ' from the alert', $text),
            default => (string) preg_replace('/\bit\b/u', 'the alert', $text, 1),
        };
    }

    /** Who has it now: the person asked to look after it, or who acknowledged it. */
    private function owner(): ?string
    {
        $assigned = $this->alert->assigned_to_user_id !== null ? $this->last('assigned') : null;
        if ($assigned !== null) {
            $named = preg_match('/asked (.+) to look after it$/u', (string) $assigned->text, $match) === 1 ? $match[1] : $assigned->actor?->name;
            if ($named !== null) {
                return $named;
            }
        }
        $acknowledged = $this->alert->acknowledged_at !== null ? $this->last('acknowledged') : null;

        return $acknowledged?->actor?->name;
    }

    private function first(string $kind): ?DeviceAlertEvent
    {
        return $this->alert->events->first(fn (DeviceAlertEvent $event): bool => $event->kind === $kind);
    }

    private function last(string $kind): ?DeviceAlertEvent
    {
        return $this->alert->events->last(fn (DeviceAlertEvent $event): bool => $event->kind === $kind);
    }

    /** Lower-cases a line's first letter unless it starts with a name ("Back online" → "back online"). */
    private static function lower(string $text): string
    {
        return preg_match('/^(Back|Passing|Answering|Battery|Ended|Changed|Now|Oblivion’s)\b/u', $text) === 1
            ? mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1)
            : $text;
    }
}
