import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import type { MyDayIncident, MyDayTaskFollowup } from '../lib/types';
import { DigestPanel } from './digest-panel';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: { visit: vi.fn() },
}));

vi.mock('@/hooks/use-my-day-labels', () => ({
    useMyDayLabels: () => (key: string) =>
        key === 'digest_open' ? 'Open' : key,
}));

const incident: MyDayIncident = {
    id: 17,
    title: 'Existing incident',
    description: 'Recorded incident summary',
    client_name: 'Mere',
    severity: 'medium',
    status: 'open',
    occurred_at: '2026-10-21T09:00:00+13:00',
    url: '/incidents/17?return_to=%2Fmy-day',
    requires_followup: false,
};

const followup: MyDayTaskFollowup = {
    id: 'followup-92',
    type: 'followup',
    title: 'Review the incident',
    priority: 'medium',
    status: 'pending',
    source_url: '/incidents/31?return_to=%2Fmy-day',
    due_at: null,
    created_at: '2026-10-21T10:00:00+13:00',
    meta: {},
};

describe('My Day existing incident return links', () => {
    it.each(['attention', 'handover'] as const)(
        'keeps incident and follow-up destinations while preserving other links in %s',
        (mode) => {
            render(
                <DigestPanel
                    mode={mode}
                    incidents={[incident]}
                    alertTasks={[
                        followup,
                        {
                            ...followup,
                            id: 'note-8',
                            type: 'note_followup',
                            title: 'Read the shift note',
                            source_url: '/control-room/shifts',
                        },
                        {
                            ...followup,
                            id: 'alert-9',
                            type: 'alert',
                            title: 'Review the alert',
                            source_url: '/control-room/alerts/9',
                        },
                    ]}
                    notifications={[]}
                />,
            );

            const hrefs = screen
                .getAllByRole('link', { name: 'Open' })
                .map((link) => link.getAttribute('href'));
            expect(hrefs).toHaveLength(4);
            expect(hrefs).toEqual(
                expect.arrayContaining([
                    '/incidents/17?return_to=%2Fmy-day',
                    // The incident ID is distinct from the follow-up row ID.
                    '/incidents/31?return_to=%2Fmy-day',
                    '/control-room/shifts',
                    '/control-room/alerts/9',
                ]),
            );
            for (const href of hrefs) {
                if (!href?.startsWith('/incidents/')) continue;
                const url = new URL(href, 'https://example.test');
                expect([...url.searchParams]).toEqual([['return_to', '/my-day']]);
                expect(url.hash).toBe('');
            }
        },
    );

    it('drops old destinations when a refreshed payload removes their records', () => {
        const { rerender } = render(
            <DigestPanel
                mode="attention"
                incidents={[incident]}
                alertTasks={[followup]}
                notifications={[]}
            />,
        );
        expect(screen.getAllByRole('link', { name: 'Open' })).toHaveLength(2);
        rerender(
            <DigestPanel
                mode="attention"
                incidents={[]}
                alertTasks={[]}
                notifications={[]}
            />,
        );
        expect(
            screen.queryByRole('link', { name: 'Open' }),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('Existing incident')).not.toBeInTheDocument();
        expect(screen.queryByText('Review the incident')).not.toBeInTheDocument();
    });
});
