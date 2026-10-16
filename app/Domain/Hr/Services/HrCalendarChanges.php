<?php

namespace App\Domain\Hr\Services;

use App\Domain\CompanyHub\Services\HubNotificationPreferences;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrCalendarEventAttachment;
use App\Domain\Hr\Notifications\CalendarEventChangedNotification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Private, in-memory comparison of one canonical live edit, under its root lock. */
class HrCalendarChanges
{
    public const CONTEXT = 'calendar.change_context';

    private array $fileCache = [];

    public function capture(HrCalendarEvent $event, string $scope, ?string $date): array
    {
        $this->fileCache = [];
        $root = $event->recurrence_parent_id ? $event->recurrenceParent : $event;
        $scope = $event->recurrence_parent_id ? 'this' : $scope;
        $date ??= $event->exception_date?->toDateString();
        $participants = [];
        foreach (DB::table('hr_calendar_registrations')->where('event_id', $root->id)->whereIn('status', ['going', 'waiting', 'maybe'])->get() as $row) {
            if (($scope === 'this' && $row->occurrence_date !== $date) || ($scope === 'following' && $row->occurrence_date < $date)) {
                continue;
            }
            $person = User::find($row->user_id);
            $fingerprint = $person ? $this->fingerprint($person, $root, $row->occurrence_date) : null;
            if ($fingerprint !== null) {
                $participants[] = ['user_id' => $row->user_id, 'registration_id' => $row->id, 'date' => $row->occurrence_date, 'fingerprint' => $fingerprint];
            }
        }
        if (! $root->registration_enabled) {
            $legacyDate = $date ?? collect(app(HrCalendarRecurrence::class)->between($root, now()->startOfDay(), Carbon::parse('9999-12-31')->endOfDay(), 2))
                ->first(fn ($row) => app(HrCalendarCancellation::class)->instant($root, $row['start'])->isFuture())['date'] ?? null;
            foreach ($root->attendees()->where('audience_type', 'person')->whereIn('rsvp_status', ['yes', 'maybe'])->get() as $attendee) {
                $person = User::find($attendee->user_id);
                $fingerprint = $person && $legacyDate ? $this->fingerprint($person, $root, $legacyDate) : null;
                if ($fingerprint !== null) {
                    $participants[] = ['user_id' => $attendee->user_id, 'registration_id' => null, 'date' => $legacyDate, 'fingerprint' => $fingerprint];
                }
            }
        }

        return ['root_id' => $root->id, 'version' => (int) $root->edit_version, 'scope' => $scope, 'date' => $date, 'participants' => $participants];
    }

    /** Caller keeps this inside the canonical update/publication transaction. */
    public function deliver(User $actor, array $context, HrCalendarEvent $saved): int
    {
        return DB::transaction(function () use ($actor, $context, $saved): int {
            $this->fileCache = [];
            $root = HrCalendarEvent::whereKey($saved->recurrence_parent_id ?? $saved->id)->lockForUpdate()->first();
            $event = HrCalendarEvent::find($saved->id);
            if (! $root || ! $event || ! $this->actorAllowed($actor, $event)) {
                return 0;
            }
            $sent = [];
            foreach ($context['participants'] as $before) {
                $person = User::find($before['user_id']);
                if (! $person || (int) $person->id === (int) $actor->id || isset($sent[$person->id])) {
                    continue;
                }
                if ($before['registration_id']) {
                    $row = DB::table('hr_calendar_registrations')->where('id', $before['registration_id'])->where('event_id', $root->id)->where('user_id', $person->id)->first();
                    if (! $row || ! in_array($row->status, ['going', 'waiting', 'maybe'], true)) {
                        continue;
                    }
                    $date = $row->occurrence_date;
                } else {
                    $date = $context['scope'] === 'following' ? $root->starts_at->toDateString() : $before['date'];
                }
                $after = $this->fingerprint($person, $event, $date);
                if ($after === null || hash_equals($before['fingerprint'], $after) || ! app(HubNotificationPreferences::class)->allowsEvent($person, $event)) {
                    continue;
                }
                $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'oblivion.calendar.change|'.$context['root_id'].'|'.$context['version'].'|'.$context['scope'].'|'.($context['date'] ?? '').'|'.$person->id)->toString();
                if (DB::table('notifications')->where('id', $id)->exists()) {
                    continue;
                }
                $notice = new CalendarEventChangedNotification($event->id, $date, $actor->id, (int) $root->edit_version);
                $notice->id = $id;
                if (! $notice->shouldSend($person, 'database')) {
                    continue;
                }
                $person->notifyNow($notice);
                $sent[$person->id] = true;
            }

            return count($sent);
        }, 3);
    }

    public function canDeliver(User $person, HrCalendarEvent $event, string $date, int $actorId, int $version): bool
    {
        $root = $event->recurrence_parent_id ? $event->recurrenceParent : $event;
        $actor = User::find($actorId);

        if (! ($root && (int) $root->edit_version === $version && $actor && $this->actorAllowed($actor, $event)
            && (int) $person->id !== $actorId && $this->participant($person, $event, $date)
            && app(HubNotificationPreferences::class)->allowsEvent($person, $event))) {
            return false;
        }
        try {
            $row = app(HrEventRegistrationService::class)->occurrence($person, $event, $date);
        } catch (HttpExceptionInterface) {
            return false;
        }

        return app(HrCalendarHubContent::class)->finishedAt(Carbon::parse($row['end']), (bool) $row['allDay'])->isFuture();
    }

    private function actorAllowed(User $actor, HrCalendarEvent $event): bool
    {
        $actor = $actor->fresh();
        $root = $event->recurrence_parent_id ? $event->recurrenceParent : $event;

        return $actor && $root && ! $root->archived_at && ! $event->archived_at
            && app(HrCurrentStaffService::class)->isCurrent($actor) && app(HrCalendarAccessService::class)->canManageEvent($actor, $event)
            && ($actor->canDo('hr.calendar.view') || $actor->canDo('company_hub.view'))
            && (! $root->hub_managed || ($actor->canDo('company_hub.publish') && $actor->canDo('company_hub.write') && $actor->canDo('company_hub.view') && $actor->canDo('hr.calendar.view')));
    }

    private function participant(User $person, HrCalendarEvent $event, string $date): bool
    {
        $person = $person->fresh();
        $root = $event->recurrence_parent_id ? $event->recurrenceParent : $event;
        if (! $person || ! $root || $root->archived_at || $event->archived_at || (! $person->canDo('company_hub.view') && ! $person->canDo('hr.calendar.view'))
            || ! app(HrCalendarReminderRecipients::class)->inAudience($person, $root, $event) || app(HrCalendarCancellation::class)->cancelled($event, $date)) {
            return false;
        }
        $row = DB::table('hr_calendar_registrations')->where('event_id', $root->id)->where('occurrence_date', $date)->where('user_id', $person->id)->first();
        if ($row) {
            return in_array($row->status, ['going', 'waiting', 'maybe'], true);
        }

        return ! $root->registration_enabled && $root->attendees()->where('audience_type', 'person')->where('user_id', $person->id)->whereIn('rsvp_status', ['yes', 'maybe'])->exists();
    }

    private function fingerprint(User $person, HrCalendarEvent $event, string $date): ?string
    {
        if (! $this->participant($person, $event, $date)) {
            return null;
        }
        try {
            $row = app(HrEventRegistrationService::class)->occurrence($person, $event, $date);
        } catch (HttpExceptionInterface) {
            return null;
        }
        if (! app(HrCalendarHubContent::class)->finishedAt(Carbon::parse($row['end']), (bool) $row['allDay'])->isFuture()) {
            return null;
        }
        $props = $row['extendedProps'];
        $effective = HrCalendarEvent::find($props['eventId']);
        $root = $effective->recurrence_parent_id ? $effective->recurrenceParent : $effective;
        $files = $this->fileCache[$root->id.'|'.$effective->id] ??= HrCalendarEventAttachment::whereIn('event_id', array_filter([$root->id, $effective->id]))->orderBy('id')->get()->mapWithKeys(function ($file): array {
            $hash = null;
            $disk = Storage::disk($file->disk ?: 'private');
            $stream = $disk->exists($file->path) ? $disk->readStream($file->path) : false;
            if (is_resource($stream)) {
                try {
                    $context = hash_init('sha256');
                    hash_update_stream($context, $stream);
                    $hash = hash_final($context);
                } finally {
                    fclose($stream);
                }
            }

            return [$file->id => ['name' => $file->original_name, 'mime' => $file->mime, 'size' => (int) $file->size, 'hash' => $hash]];
        });
        $content = $props['hubContent'] ?? [];
        $cover = $content['cover'] ?? null;
        $public = ['title' => $row['title'], 'start' => $row['start'], 'end' => $row['end'], 'all_day' => $row['allDay'],
            'category' => $props['category'], 'location' => $props['location'], 'description' => $props['description'],
            'host' => isset($content['host']) ? ['id' => $content['host']['id'], 'name' => $content['host']['name']] : null,
            'agenda' => $content['agenda'] ?? '', 'cover' => $cover ? ['file' => $files->get($cover['id']), 'alt' => $cover['alt']] : null,
            'files' => array_map(fn ($file) => $files->get($file['id']), $props['attachments'] ?? []),
            'registration' => collect($props['registration'] ?? [])->only(['enabled', 'capacity', 'closes_before_minutes'])->all()];

        return hash('sha256', json_encode($public, JSON_THROW_ON_ERROR));
    }
}
