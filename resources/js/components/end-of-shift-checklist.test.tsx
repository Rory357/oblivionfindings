import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';

import EndOfShiftChecklist from '@/components/end-of-shift-checklist';

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

vi.mock('@/hooks/use-mobile', () => ({
    useIsMobile: () => false,
}));

describe('EndOfShiftChecklist', () => {
    it('resets transient fields when the checklist is reopened', () => {
        const session = {
            id: 10,
            shift_id: 20,
            client_name: 'Ari Kauri',
            break_minutes: 15,
            tasks: [],
            end_of_shift_blockers: [
                {
                    key: 'incidents_draft',
                    label: 'Submit draft incidents',
                    detail: '1 incident report is still a draft.',
                    count: 1,
                    action_url: '/incidents?shift_id=20',
                    blocking: true,
                },
            ],
        };

        const { rerender } = render(
            <EndOfShiftChecklist
                session={session}
                open
                onOpenChange={vi.fn()}
            />,
        );

        fireEvent.change(screen.getByLabelText('Break minutes'), {
            target: { value: '45' },
        });
        fireEvent.change(screen.getByLabelText('Reason to end anyway'), {
            target: { value: 'Needed' },
        });
        fireEvent.change(screen.getByLabelText('Optional notes'), {
            target: { value: 'Temporary note' },
        });

        rerender(
            <EndOfShiftChecklist
                session={session}
                open={false}
                onOpenChange={vi.fn()}
            />,
        );
        rerender(
            <EndOfShiftChecklist
                session={session}
                open
                onOpenChange={vi.fn()}
            />,
        );

        expect(screen.getByLabelText('Break minutes')).toHaveValue(15);
        expect(screen.getByLabelText('Reason to end anyway')).toHaveValue('');
        expect(screen.getByLabelText('Optional notes')).toHaveValue('');
    });

    it('disables force submit for clinical blockers without manager override capability', () => {
        const session = {
            id: 11,
            shift_id: 21,
            client_name: 'Ari Kauri',
            break_minutes: 0,
            tasks: [],
            can_force_clinical_blockers: false,
            end_of_shift_blockers: [
                {
                    key: 'meds_unsigned',
                    label: 'Sign medication records',
                    detail: '1 scheduled medication still needs a MAR entry.',
                    count: 1,
                    action_url: '/meds/today',
                    blocking: true,
                },
            ],
        };

        render(
            <EndOfShiftChecklist
                session={session}
                open
                onOpenChange={vi.fn()}
            />,
        );

        expect(
            screen.getByText(
                'Unsigned medication or draft incident blockers need a manager override.',
            ),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: /manager override required/i }),
        ).toBeDisabled();
    });
});
