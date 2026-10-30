import { fireEvent, render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { HomeSaved } from './_home-saved';
import { hubData, hubStory } from './_test-fixtures';
import type { HubEvent } from './_types';

vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
}));

describe('Home saved shortcuts', () => {
    it('uses exact authorised document roots, deduplicates and hides unavailable bookmarks', () => {
        const file = {
            ...hubStory,
            document_id: 7,
            title: 'Safety handbook PDF',
            reader_url: '/company-hub/documents/7',
        };
        const data = hubData({
            pages: [hubStory],
            resourceItems: [file, file],
            bookmarks: [
                { subject_type: 'media', subject_id: 999 },
                { subject_type: 'media', subject_id: 7 },
                { subject_type: 'media', subject_id: 7 },
            ],
        });
        const { rerender } = render(
            <HomeSaved data={data} onEvent={vi.fn()} />,
        );
        expect(
            screen.getAllByRole('link', {
                name: 'Safety handbook PDF Document',
            }),
        ).toHaveLength(1);
        expect(
            screen.getByRole('link', { name: 'Safety handbook PDF Document' }),
        ).toHaveAttribute('href', '/company-hub/documents/7');
        expect(screen.queryByText(hubStory.title)).not.toBeInTheDocument();
        rerender(
            <HomeSaved
                data={{ ...data, resourceItems: [] }}
                onEvent={vi.fn()}
            />,
        );
        expect(
            screen.queryByText('Safety handbook PDF'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Browse resources' }),
        ).toBeInTheDocument();
    });

    it('caps the summary at three and reflects removed saves without keeping stale rows', () => {
        const pages = [1, 2, 3, 4].map((id) => ({
            ...hubStory,
            id,
            title: `Story ${id}`,
            slug: `story-${id}`,
        }));
        const data = hubData({
            pages,
            bookmarks: pages.map((page) => ({
                subject_type: 'page',
                subject_id: page.id,
            })),
        });
        const { rerender } = render(
            <HomeSaved data={data} onEvent={vi.fn()} />,
        );
        const summary = screen.getByRole('region', { name: 'Saved for later' });
        expect(within(summary).getAllByRole('listitem')).toHaveLength(3);
        expect(screen.queryByText('Story 4')).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'View all' })).toHaveAttribute(
            'href',
            '/company-hub?view=saved',
        );
        rerender(
            <HomeSaved
                data={{ ...data, bookmarks: data.bookmarks.slice(1) }}
                onEvent={vi.fn()}
            />,
        );
        expect(screen.queryByText('Story 1')).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Story 4 Company news' }),
        ).toHaveAttribute('href', '/company-hub/read/story-4');
    });

    it('opens the authorised recurring occurrence through the existing event reader', () => {
        const event: HubEvent = {
            id: '42:2026-10-20',
            title: 'Learning morning',
            start: '2026-10-20T09:00:00+13:00',
            end: '2026-10-20T10:00:00+13:00',
            allDay: false,
            extendedProps: {
                eventId: 45,
                recurrenceParentId: 42,
                occurrenceDate: '2026-10-20',
                recurring: true,
                location: null,
                description: null,
                createdBy: 'Learning team',
                myRsvp: null,
                site: null,
                editVersion: 1,
            },
        };
        const onEvent = vi.fn();
        render(
            <HomeSaved
                data={hubData({
                    events: [event, event],
                    bookmarks: [{ subject_type: 'event', subject_id: 42 }],
                })}
                onEvent={onEvent}
            />,
        );
        expect(
            screen.getAllByRole('button', { name: 'Learning morning Event' }),
        ).toHaveLength(1);
        fireEvent.click(
            screen.getByRole('button', { name: 'Learning morning Event' }),
        );
        expect(onEvent).toHaveBeenCalledWith(event);
    });

    it('resolves saved historic and distant events independently of upcoming dates and hides withdrawn saved projections', () => {
        const event = {
            id: 'event-42',
            title: 'Historic staff gathering',
            start: '2024-01-01T00:00:00Z',
            end: '2024-01-01T01:00:00Z',
            allDay: false,
            extendedProps: {
                eventId: 43,
                savedSubjectId: 42,
                recurrenceParentId: 42,
                occurrenceDate: null,
                recurring: true,
                location: null,
                description: null,
                createdBy: 'Company team',
                myRsvp: null,
                site: null,
                editVersion: 1,
            },
        } satisfies HubEvent;
        const data = hubData({
            events: [],
            savedEvents: [event],
            bookmarks: [{ subject_type: 'event', subject_id: 42 }],
        });
        const onEvent = vi.fn();
        const { rerender } = render(
            <HomeSaved data={data} onEvent={onEvent} />,
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Historic staff gathering Event',
            }),
        );
        expect(onEvent).toHaveBeenCalledWith(event);
        rerender(
            <HomeSaved data={{ ...data, savedEvents: [] }} onEvent={onEvent} />,
        );
        expect(screen.queryByText(event.title)).not.toBeInTheDocument();
    });
});
