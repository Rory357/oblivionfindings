import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import ClockInCard from '@/components/clock-in-card';

vi.mock('@inertiajs/react', () => ({
    router: {
        post: vi.fn(),
    },
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({
        props: { my_day_labels: {}, auth: { user: { id: 99 } } },
    }),
}));

describe('ClockInCard', () => {
    beforeEach(() => {
        window.localStorage.clear();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('uses the shared end-of-shift checklist instead of its legacy confirm dialog', () => {
        render(
            <ClockInCard
                canClock
                activeShift={null}
                eligibleShiftCount={0}
                openSession={{
                    id: 10,
                    clock_in_at: new Date().toISOString(),
                    shift_id: 20,
                    client_name: 'Ari Kauri',
                    shift_starts_at: new Date().toISOString(),
                    shift_ends_at: new Date(
                        Date.now() + 60 * 60 * 1000,
                    ).toISOString(),
                    location: 'Matai House',
                    break_minutes: 0,
                    handover_submitted: true,
                    tasks: [],
                    end_of_shift_blockers: [],
                }}
            />,
        );

        const clockOutButton = document.querySelector(
            '[data-test="clock-out-button"]',
        );
        expect(clockOutButton).not.toBeNull();
        fireEvent.click(clockOutButton as Element);

        expect(screen.queryByText('End this shift?')).not.toBeInTheDocument();
        expect(screen.getByText('End shift for Ari Kauri')).toBeVisible();
    });

    it('restores the saved server handover draft in the shared checklist', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: () =>
                    Promise.resolve({
                        people: [{ id: 7, name: 'Ari Kauri' }],
                        handover_id: 31,
                        review_url: null,
                        expected_version: 2,
                        status: 'draft',
                        saved_at: new Date().toISOString(),
                        worker_notes: {
                            shared_notes: 'Saved handover draft.',
                            people: [
                                {
                                    client_id: 7,
                                    notes: 'Slept well.',
                                    no_updates: false,
                                    not_supported: false,
                                    follow_up_needed: false,
                                },
                            ],
                        },
                    }),
            }),
        );

        render(
            <ClockInCard
                canClock
                activeShift={null}
                eligibleShiftCount={0}
                openSession={{
                    id: 10,
                    clock_in_at: new Date().toISOString(),
                    shift_id: 20,
                    client_name: 'Ari Kauri',
                    shift_starts_at: new Date().toISOString(),
                    shift_ends_at: new Date(
                        Date.now() + 60 * 60 * 1000,
                    ).toISOString(),
                    location: 'Matai House',
                    break_minutes: 0,
                    handover_submitted: false,
                    tasks: [],
                    end_of_shift_blockers: [
                        {
                            key: 'handover_missing',
                            label: 'Write handover',
                            detail: 'The next shift still needs a handover.',
                            count: 1,
                            action_url: '#handover',
                            blocking: true,
                        },
                    ],
                }}
            />,
        );

        const clockOutButton = document.querySelector(
            '[data-test="clock-out-button"]',
        );
        fireEvent.click(clockOutButton as Element);

        expect(
            await screen.findByDisplayValue('Saved handover draft.'),
        ).toBeVisible();
        expect(vi.mocked(fetch)).toHaveBeenCalledWith(
            '/attendance/shifts/20/handover-draft',
            expect.objectContaining({ cache: 'no-store' }),
        );
    });
});
