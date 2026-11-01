<?php

namespace App\Domain\SecurityDevices\Moves;

use App\Models\SiteRoom;
use Illuminate\Support\Collection;

/**
 * Which of a Site's rooms a name mentions (S&D data moves): a room counts only as a whole word or
 * phrase, ignoring case, so "Bedroom 1" is not found in "Bedroom 10 sensor" and "Hall" is not found
 * in "Hallway". A move suggests a room only when exactly one room is found.
 */
final class RoomNames
{
    /**
     * @param  Collection<int, SiteRoom>  $rooms  one Site's rooms
     * @return list<SiteRoom>
     */
    public static function mentionedIn(string $text, Collection $rooms): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $found = [];
        foreach ($rooms as $room) {
            $name = trim((string) $room->name);
            if ($name === '') {
                continue;
            }
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu';
            if (preg_match($pattern, $text) === 1) {
                $found[] = $room;
            }
        }

        return $found;
    }

    /**
     * The one room a name points to, or null when it names none or more than one.
     *
     * @param  Collection<int, SiteRoom>  $rooms
     */
    public static function onlyRoomIn(string $text, Collection $rooms): ?SiteRoom
    {
        $found = self::mentionedIn($text, $rooms);

        return count($found) === 1 ? $found[0] : null;
    }
}
