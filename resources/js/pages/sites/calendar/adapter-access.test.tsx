import type { CalendarItem } from '@/lib/calendar/recur';
import { act, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { expect, it, vi } from 'vitest';
import SiteCalendar, { type CalendarDataAdapter } from './SiteCalendar';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        url: '/operations/referrals/calendar',
        props: { auth: { user: { id: 1 }, can: {} } },
    }),
    useForm: (data: unknown) => ({ data, processing: false, errors: {} }),
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: {},
}));

const event: CalendarItem = {
    id: 'referral_work-1',
    title: 'Private referral appointment',
    source: 'checklist',
    group: 'auto',
    start: new Date().toISOString(),
    end: null,
    allDay: false,
    status: 'scheduled',
    owner: { id: 1, name: 'Fixture staff' },
    site: null,
    room: null,
    ref: 'FIXTURE',
    link: '/operations/referrals/FIXTURE',
    editable: false,
};

it.each(['period', 'rail'])(
    'clears both calendar projections when the %s request denies access and ignores a late success',
    async (deniedFeed) => {
        const initial: CalendarDataAdapter = {
            loadItems: async () => ({ events: [event] }),
            allowExport: false,
            allowSubscriptions: false,
        };
        const { rerender } = render(
            <SiteCalendar
                context="page"
                scope="global"
                canCreate={false}
                dataAdapter={initial}
            />,
        );
        await waitFor(() =>
            expect(screen.getAllByText(event.title).length).toBeGreaterThan(0),
        );
        let resolveLate!: (value: { events: CalendarItem[] }) => void;
        const late = new Promise<{ events: CalendarItem[] }>((resolve) => {
            resolveLate = resolve;
        });
        const updated: CalendarDataAdapter = {
            ...initial,
            loadItems: ({ start, end }) => {
                const rail = end.getTime() - start.getTime() > 60 * 86400000;
                return (deniedFeed === 'rail') === rail
                    ? Promise.reject(
                          Object.assign(new Error('Access withdrawn'), {
                              status: 403,
                          }),
                      )
                    : late;
            },
        };
        rerender(
            <SiteCalendar
                context="page"
                scope="global"
                canCreate={false}
                dataAdapter={updated}
            />,
        );
        await screen.findByText(
            "You don't have permission to view these calendar entries.",
        );
        expect(screen.queryAllByText(event.title)).toHaveLength(0);
        await act(async () => resolveLate({ events: [event] }));
        expect(screen.queryAllByText(event.title)).toHaveLength(0);
    },
);
