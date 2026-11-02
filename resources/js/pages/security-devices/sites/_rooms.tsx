/**
 * The Site page's Rooms tab (S&D Phase F, drawing pg-sdr-12d): devices grouped by the room they're
 * placed in, with the reading that matters for each. Rooms come from the Site profile.
 */
import { BoldText } from '@/components/security-devices/house-mode-popups';
import {
    PlaceCards,
    QuietLine,
    SideBySide,
    SideColumn,
    type PlaceCardItem,
} from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import { Link } from '@inertiajs/react';
import { SiteFlagBadge } from './_parts';
import type { SiteRooms } from './_types';

export function RoomsTab({
    rooms,
    siteName,
}: {
    rooms: SiteRooms;
    siteName: string;
}) {
    const items: PlaceCardItem[] = rooms.items.map((room) => ({
        id: room.key,
        name: room.name,
        sub: room.sub,
        flag: <SiteFlagBadge flag={room.flag} />,
        facts: room.facts.map((fact) => ({
            tone: fact.tone,
            text: fact.href ? (
                <Link
                    href={fact.href}
                    className="underline-offset-2 hover:underline"
                >
                    <BoldText text={fact.text} />
                </Link>
            ) : (
                <BoldText text={fact.text} />
            ),
        })),
    }));
    if (rooms.unplaced) {
        const { count, names, more } = rooms.unplaced;
        items.push({
            id: 'unplaced',
            name: 'Not in a room yet',
            sub: `${count} ${count === 1 ? 'device' : 'devices'}`,
            flag: <StatusBadge variant="neutral">Place them</StatusBadge>,
            facts: [
                {
                    tone: 'none',
                    text:
                        names.join(', ') +
                        (more > 0 ? ` and ${more} more` : ''),
                },
            ],
        });
    }

    let main;
    if (rooms.room_count === 0 && items.length === 0) {
        main = (
            <QuietLine
                title="Rooms"
                text="This Site has no rooms yet, and no devices here to place."
            />
        );
    } else if (rooms.room_count === 0) {
        main = (
            <div className="flex flex-col gap-5">
                <QuietLine
                    title="Rooms"
                    text="This Site has no rooms yet, so its devices can’t be grouped by where they are."
                />
                <PlaceCards cols={3} items={items} />
            </div>
        );
    } else if (items.length === 0) {
        main = (
            <QuietLine
                title="Rooms"
                text="No devices are placed in a room yet."
            />
        );
    } else {
        main = (
            <PlaceCards
                title="Rooms"
                sub="Devices grouped by where they are"
                cols={3}
                items={items}
            />
        );
    }

    return (
        <SideBySide cols="minmax(0, 1fr) 360px">
            {main}
            <SideColumn>
                <QuietLine
                    title="Rooms come from the Site profile"
                    text={`Add or rename rooms in Sites & locations › ${siteName}.`}
                    action={
                        rooms.profile_href
                            ? {
                                  label: 'Open Site profile',
                                  href: rooms.profile_href,
                              }
                            : null
                    }
                />
            </SideColumn>
        </SideBySide>
    );
}
