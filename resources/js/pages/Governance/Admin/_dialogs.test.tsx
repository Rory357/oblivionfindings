import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        Link: ({ href, children }: { href: string; children: ReactNode }) => (
            <a href={href}>{children}</a>
        ),
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setDataState] = React.useState(initial);
            return {
                data,
                errors: {},
                processing: false,
                isDirty: false,
                setData: (key: keyof T, value: unknown) =>
                    setDataState((current) => ({ ...current, [key]: value })),
                transform: vi.fn(),
                put: vi.fn(),
                post: vi.fn(),
            };
        },
    };
});

import { appointmentChip } from './BoardMembers';
import { BoardMemberWizardDialog, appointmentUpdatePayload } from './_dialogs';

describe('board appointments', () => {
    afterEach(cleanup);

    it('sends null when the term end is cleared, so the term becomes ongoing', () => {
        expect(
            appointmentUpdatePayload({ board_role: 'member', is_active: true, term_end: '' }),
        ).toEqual({ board_role: 'member', is_active: true, term_end: null });
        expect(
            appointmentUpdatePayload({ board_role: 'chair', is_active: false, term_end: '2027-06-30' }).term_end,
        ).toBe('2027-06-30');
    });

    it('asks for a person, not a staff member, and says how to get someone a login', () => {
        render(
            <BoardMemberWizardDialog
                open
                onClose={vi.fn()}
                availableUsers={[]}
                canInvitePeople
            />,
        );

        expect(screen.getByText('Person')).toBeTruthy();
        expect(screen.queryByText(/staff member/i)).toBeNull();
        expect(screen.getByText(/People need an Oblivion Care login first/)).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Invite someone' }).getAttribute('href')).toBe(
            '/settings/users',
        );
        expect(screen.getByText('No people without a board seat')).toBeTruthy();
    });

    it('explains why the start date is locked when editing', () => {
        render(
            <BoardMemberWizardDialog
                open
                onClose={vi.fn()}
                availableUsers={[]}
                member={{
                    id: 4,
                    user: { id: 9, name: 'Aroha Chair', email: 'aroha@example.test' },
                    board_role: 'chair',
                    term_start: '2025-01-01',
                    term_end: '2027-01-01',
                    is_active: true,
                }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        expect(
            screen.getByText(/start date can't be changed — remove and re-appoint/),
        ).toBeTruthy();
    });

    it('shows one plain status chip', () => {
        const base = { id: 1, user: null, board_role: 'member', term_start: '2025-01-01', term_end: null };
        expect(appointmentChip({ ...base, is_active: true, standing: 'active' }).label).toBe('Current member');
        expect(appointmentChip({ ...base, is_active: true, standing: 'active', ending_soon: true }).label).toBe(
            'Term ending soon',
        );
        expect(appointmentChip({ ...base, is_active: false, standing: 'inactive' }).label).toBe('Not active');
    });
});
