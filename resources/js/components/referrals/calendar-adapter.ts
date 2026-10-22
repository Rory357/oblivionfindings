import type { CalendarItem } from '@/lib/calendar/recur';
import type {
    CalendarDataAdapter,
    CreateSeed,
} from '@/pages/sites/calendar/SiteCalendar';
import type { SourceDef } from '@/pages/sites/calendar/_parts';
import type { Permissions } from './types';

export function referralFollowUpSeed(seed: CreateSeed, now = new Date()): Date {
    const date = new Date(seed.date);
    if (seed.hour !== undefined) {
        date.setHours(
            Math.floor(seed.hour),
            Math.round((seed.hour % 1) * 60),
            0,
            0,
        );
        return date;
    }
    date.setHours(9, 0, 0, 0);
    if (date.toDateString() === now.toDateString() && date <= now) {
        // A new-entry click must not default to a time already in the past.
        return new Date(
            Math.ceil((now.getTime() + 30 * 60_000) / (15 * 60_000)) *
                15 *
                60_000,
        );
    }
    return date;
}

export function referralCalendarAdapter(
    permissions: Permissions,
    onCreate: (seed: CreateSeed) => void,
): CalendarDataAdapter {
    const sourceFilters: SourceDef[] = [
        {
            key: 'referral-follow-up',
            label: 'Next actions & follow-ups',
            short: 'Follow-ups',
            group: 'auto',
            icon: 'CheckSquare',
            origin: 'Referrals',
        },
        {
            key: 'referral-appointment',
            label: 'Calls & meetings',
            short: 'Appointments',
            group: 'auto',
            icon: 'CalendarDays',
            origin: 'Referrals',
        },
        ...(permissions['assessment.view']
            ? [
                  {
                      key: 'referral-assessment',
                      label: 'Assessment work',
                      short: 'Assessments',
                      group: 'auto',
                      icon: 'ClipboardList',
                      origin: 'Referrals',
                  },
              ]
            : []),
        ...(permissions['funding.view']
            ? [
                  {
                      key: 'referral-funding',
                      label: 'Funding assistance',
                      short: 'Funding',
                      group: 'auto',
                      icon: 'ShieldCheck',
                      origin: 'Referrals',
                  },
              ]
            : []),
    ];
    return {
        title: 'Referral calendar',
        subline: 'Open follow-ups and appointments · Times shown in NZ time',
        backLink: { href: '/operations/referrals', label: 'Referrals' },
        mineLink: {
            href: '/tasks?sources=referrals&assigned=me',
            label: 'My referral tasks',
        },
        initialView: 'month',
        sourceFilters,
        responsiveAgenda: true,
        mobileSummary: 'Open referral work',
        allowSubscriptions: false,
        allowExport: false,
        showApprovalMeter: false,
        showCompletionMeter: false,
        timeZoneLabel: 'NZ time',
        interactionHint: 'Open a follow-up to reschedule or record its outcome',
        searchPlaceholder: 'Find a follow-up in this period…',
        onCreate,
        loadItems: async ({ start, end, signal }) => {
            const events = new Map<string, CalendarItem>();
            let page: number | null = 1;
            while (page !== null) {
                signal?.throwIfAborted();
                if (page > 100)
                    throw new Error(
                        'Choose a shorter calendar range to load this volume of work.',
                    );
                const query = new URLSearchParams({
                    start: start.toISOString(),
                    end: end.toISOString(),
                    page: String(page),
                });
                const response = await fetch(
                    `/operations/referrals/calendar/events?${query}`,
                    {
                        signal,
                        cache: 'no-store',
                        headers: { Accept: 'application/json' },
                    },
                );
                if (!response.ok)
                    throw Object.assign(
                        new Error('The referral calendar could not be loaded.'),
                        { status: response.status },
                    );
                const data = (await response.json()) as {
                    events: CalendarItem[];
                    next_page: number | null;
                };
                if (
                    !Array.isArray(data.events) ||
                    (data.next_page !== null && data.next_page !== page + 1)
                )
                    throw new Error(
                        'Unexpected calendar response. Reload the calendar.',
                    );
                data.events.forEach((event) => events.set(event.id, event));
                page = data.next_page;
            }
            return { events: [...events.values()] };
        },
    };
}
