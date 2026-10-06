import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { LeaveExcursionsTab, type LeaveItem } from './leave-excursions';

vi.mock('@inertiajs/react', () => ({ router: { put: vi.fn() } }));

describe('actual leave history', () => {
    it.each(['2026-10-05T12:00:00+13:00', '2026-10-05T09:00:00+13:00'])(
        'keeps both same-version actions on refresh, including a return at %s',
        (returnedAt) => {
            const errors = vi
                .spyOn(console, 'error')
                .mockImplementation(() => {});
            try {
                const approved: LeaveItem = {
                    id: 21,
                    status: 'approved',
                    version: 2,
                    destination: 'Synthetic family visit',
                    history: [
                        {
                            action: 'approve',
                            version: 2,
                            actor_id: 34,
                            occurred_at: '2026-10-05T08:00:00+13:00',
                            reason: null,
                        },
                    ],
                };
                const { rerender } = render(
                    <LeaveExcursionsTab clientId={16} leave={[approved]} />,
                );
                const completed: LeaveItem = {
                    ...approved,
                    status: 'completed',
                    version: 3,
                    departed_at: '2026-10-05T09:00:00+13:00',
                    returned_at: returnedAt,
                    history: [
                        ...approved.history!,
                        {
                            action: 'depart',
                            version: 3,
                            actor_id: 34,
                            occurred_at: '2026-10-05T09:00:00+13:00',
                            reason: null,
                        },
                        {
                            action: 'return',
                            version: 3,
                            actor_id: 34,
                            occurred_at: returnedAt,
                            reason: null,
                        },
                    ],
                };
                rerender(
                    <LeaveExcursionsTab clientId={16} leave={[completed]} />,
                );
                rerender(
                    <LeaveExcursionsTab
                        clientId={16}
                        leave={[
                            { ...completed, history: [...completed.history!] },
                        ]}
                    />,
                );
                expect(screen.getAllByText(/^approve ·/)).toHaveLength(1);
                expect(screen.getAllByText(/^depart ·/)).toHaveLength(1);
                expect(screen.getAllByText(/^return ·/)).toHaveLength(1);
                expect(errors).not.toHaveBeenCalled();
            } finally {
                errors.mockRestore();
            }
        },
    );
});
