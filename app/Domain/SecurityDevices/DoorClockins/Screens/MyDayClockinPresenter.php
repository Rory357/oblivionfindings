<?php

namespace App\Domain\SecurityDevices\DoorClockins\Screens;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\SecurityDevices\DoorClockins\DoorClockinRules;
use App\Domain\SecurityDevices\DoorClockins\DoorClockinSetup;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockin;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockinDoor;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockinNotice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The person's own clock-in suggestion on My Day (drawing pg-sdr-19g, pop-up 88): their open
 * suggestion, and what they were told when it was turned on at their Site (Privacy Act IPP3).
 * Only ever the signed-in person's own row; nothing about anyone else.
 */
final class MyDayClockinPresenter
{
    public function __construct(private readonly DoorClockinSetup $setup) {}

    /** @return array<string, mixed>|null */
    public function forUser(User $user, ?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now('UTC');
        try {
            return $this->build($user, $now);
        } catch (Throwable $exception) {
            // My Day never fails for this band: it just doesn't show.
            report($exception);

            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function build(User $user, CarbonImmutable $now): ?array
    {
        $row = DoorClockin::query()
            ->where('user_id', $user->id)
            ->where('kind', DoorClockin::ROSTERED)
            ->whereNotNull('badge_at')
            ->whereNull('closed_at')
            ->where(fn ($query) => $query->whereNull('answer')->orWhere('answer', 'claiming'))
            ->where('day', '>=', DoorClockinRules::local($now)->subDay()->toDateString())
            ->where(fn ($query) => $query->whereNull('shift_ends_at')->orWhere('shift_ends_at', '>', $now))
            ->with(['door:id,name', 'site:id,name'])
            ->latest('badge_at')
            ->first();
        if ($row === null || ! $row->isWaiting()) {
            return null;
        }
        // Clocked in some other way meanwhile, or the Site turned it off: nothing to choose.
        $clockedIn = HrAttendanceSession::query()->where('user_id', $user->id)
            ->where(fn ($query) => $query->where('shift_id', $row->shift_id)->orWhere(fn ($open) => $open->open()))
            ->exists();
        $door = DoorClockinDoor::query()->with('notice.sender:id,name')->where('door_id', $row->door_id)->first();
        if ($clockedIn || ! DoorClockinDoor::query()->where('site_id', $row->site_id)->exists()) {
            return null;
        }
        $notice = $door?->notice ?? DoorClockinNotice::query()->with('sender:id,name')->latest('sent_at')->limit(20)->get()
            ->first(fn (DoorClockinNotice $notice): bool => in_array((int) $row->site_id, array_map('intval', (array) $notice->site_ids), true));

        return [
            'id' => (int) $row->id,
            'site' => (string) ($row->site?->name ?? ''),
            'door' => (string) ($row->door?->name ?? 'A door'),
            'place' => DoorClockinRules::doorPlace($row->site?->name, $row->door?->name),
            'method' => DoorClockinRules::methodWord($row->method),
            'badge_at' => $row->badge_at->toIso8601String(),
            'shift' => [
                'starts_at' => $row->shift_starts_at?->toIso8601String(),
                'ends_at' => $row->shift_ends_at?->toIso8601String(),
            ],
            'badge_time' => (bool) $this->setup->config()->badge_time,
            'notice' => $notice !== null ? [
                'body' => (string) $notice->body,
                'sent_at' => $notice->sent_at->toIso8601String(),
                'by' => (string) ($notice->sender?->name ?? 'your manager'),
                'by_email' => (bool) $notice->by_email,
            ] : null,
        ];
    }
}
