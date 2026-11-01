<?php

namespace App\Domain\SecurityDevices\Screens\Access;

use App\Domain\SecurityDevices\Access\Models\AccessEvent;
use App\Domain\SecurityDevices\Access\Models\AccessPerson;
use Carbon\CarbonImmutable;

/**
 * The one rule for who a door event names on the Access page (D14, D19). Only staff matched at
 * that Site are named, and only for 90 days. A resident is always "Resident fob" (it's their
 * home). Anyone not matched to staff is never named, so a resident not yet marked as one can't
 * be named by accident. The server already clears names after 90 days; this hides them too.
 */
final class AccessNames
{
    public const int KEEP_DAYS = 90;

    public static function isResident(?AccessPerson $person): bool
    {
        return $person !== null && ($person->kind === 'resident' || $person->client_id !== null);
    }

    public static function isMatchedStaff(?AccessPerson $person): bool
    {
        return $person !== null && ! self::isResident($person) && $person->kind === 'staff'
            && $person->match_state === 'matched' && $person->user_id !== null;
    }

    /** True while a name on this event may still be shown. */
    public static function kept(CarbonImmutable $occurredAt, CarbonImmutable $now): bool
    {
        return $occurredAt->gte($now->subDays(self::KEEP_DAYS));
    }

    /**
     * Who the event names, as the page shows it.
     *
     * @return array{text: string, named: bool, person_id: int|null}
     */
    public static function who(AccessEvent $event, ?AccessPerson $person, CarbonImmutable $now): array
    {
        if (self::isResident($person)) {
            return ['text' => 'Resident fob', 'named' => false, 'person_id' => null];
        }
        if ($person === null && in_array($event->kind, ['forced', 'held_open'], true)) {
            return ['text' => '—', 'named' => false, 'person_id' => null];
        }
        if ($person === null && $event->method === 'button') {
            return ['text' => '—', 'named' => false, 'person_id' => null];
        }
        if (! self::kept($event->occurred_at, $now)) {
            return ['text' => 'Name not kept after 90 days', 'named' => false, 'person_id' => null];
        }
        if (self::isMatchedStaff($person)) {
            $name = self::staffName($person) ?? trim((string) $event->actor_name);

            return $name !== ''
                ? ['text' => $name, 'named' => true, 'person_id' => (int) $person->id]
                : ['text' => 'Staff member', 'named' => false, 'person_id' => (int) $person->id];
        }
        if ($person !== null) {
            return ['text' => 'Not matched to staff', 'named' => false, 'person_id' => null];
        }

        return ['text' => $event->kind === 'denied' ? 'Unknown card' : 'Not known', 'named' => false, 'person_id' => null];
    }

    /** A matched staff member's name from HR (the users table), never from the browser. */
    public static function staffName(?AccessPerson $person): ?string
    {
        if (! self::isMatchedStaff($person)) {
            return null;
        }
        $name = trim((string) ($person->relationLoaded('user') ? $person->user?->name : $person->user()->value('name')));

        return $name !== '' ? $name : null;
    }

    /** The name UniFi has for someone (staff not yet matched, contractors). Never used for residents. */
    public static function unifiName(AccessPerson $person): string
    {
        $name = trim(trim((string) $person->first_name).' '.trim((string) $person->last_name));

        return $name !== '' ? $name : 'No name in UniFi';
    }
}
