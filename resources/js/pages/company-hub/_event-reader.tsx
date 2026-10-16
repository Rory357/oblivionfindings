import { Button } from '@/components/ui/button';
import { usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EventBody } from './_event-detail';
import type { HubEvent, HubProps } from './_types';

export function eventReaderUrl(event: HubEvent) {
    const date =
        event.extendedProps.occurrenceDate ??
        event.extendedProps.registration?.date ??
        event.start.slice(0, 10);
    return `/company-hub/events/${event.extendedProps.eventId}?date=${encodeURIComponent(date)}`;
}

export function HubEventReader({
    event,
    events,
    bookmarks,
    preview,
    onSelect,
    close,
    returnLabel,
    onRecognise,
    sampleRoster,
    sample = false,
}: {
    event: HubEvent;
    events: HubEvent[];
    bookmarks: HubProps['bookmarks'];
    preview?: boolean;
    onSelect: (event: HubEvent) => void;
    close: () => void;
    returnLabel: string;
    onRecognise?: () => void;
    sampleRoster?: import('./_event-attendance').Roster;
    sample?: boolean;
}) {
    const actorId = Number(
        (usePage().props.auth as { user?: { id: number } })?.user?.id,
    );
    const [busy, setBusy] = useState(false);
    const reader = useRef<HTMLElement>(null);
    useEffect(() => {
        const frame = requestAnimationFrame(() => {
            reader.current?.focus({ preventScroll: true });
            reader.current?.scrollIntoView?.({ block: 'start' });
        });
        return () => cancelAnimationFrame(frame);
    }, [event.id]);
    return (
        <article
            ref={reader}
            tabIndex={-1}
            aria-label={event.title}
            className="scroll-mt-32 space-y-6 outline-none"
        >
            <Button
                variant="ghost"
                disabled={busy}
                onClick={close}
                className="-ml-3"
            >
                <ArrowLeft className="size-4" />
                {returnLabel}
            </Button>
            <EventBody
                key={`${actorId}-${event.id}-${event.extendedProps.editVersion}`}
                page
                event={event}
                events={events}
                bookmarks={bookmarks}
                preview={!!preview}
                sampleRoster={preview ? sampleRoster : undefined}
                sample={preview && sample}
                onSelect={onSelect}
                onEdited={close}
                actorId={actorId}
                commandBusy={busy}
                onCommandBusy={setBusy}
                onRecognise={onRecognise}
            />
        </article>
    );
}
