import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    formPost: vi.fn(),
    routerPost: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
    const React = await vi.importActual<typeof import('react')>('react');
    return {
        Head: () => null,
        Link: ({ href, children, ...rest }: { href: string; children: ReactNode }) => (
            <a href={href} {...rest}>
                {children}
            </a>
        ),
        router: {
            post: inertia.routerPost,
            visit: vi.fn(),
            replace: vi.fn(),
        },
        usePage: () => ({ props: { flash: {} }, url: '/governance/policies/5' }),
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
                post: (url: string) => inertia.formPost(url, data),
                put: vi.fn(),
                reset: vi.fn(),
                clearErrors: vi.fn(),
            };
        },
    };
});

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

import PolicyShow from './Show';
import { confirmationChip, confirmedOf } from './_shared';
import { editStatusOptions } from './_dialogs';

type ShowProps = Parameters<typeof PolicyShow>[0];

const basePolicy = {
    id: 5,
    title: 'Conflicts of interest',
    reference: 'POL-ABC123',
    category: 'governance',
    description: 'How members declare interests',
    content: 'Members declare every interest.',
    change_summary: null,
    status: 'active',
    version: 3,
    effective_date: '2026-01-01',
    review_date: '2099-01-01',
    requires_attestation: true,
    attestation_frequency: null,
    approved_by_user: { name: 'Chair' },
    approved_at: '2026-01-01T00:00:00Z',
};

function renderShow(overrides: Partial<ShowProps> = {}) {
    const props = {
        auth: { user: { id: 1, name: 'Member' }, can: {} },
        policy: basePolicy,
        confirmation: {
            required: true,
            state: 'to_confirm',
            effective_from: '2026-01-01',
            frequency: null,
            my_confirmation: null,
            can_confirm: true,
            board_confirmed: 2,
            board_total: 7,
        },
        confirmations: null,
        versions: { previous: null, newer: null },
        canEdit: false,
        canApprove: false,
        canStartVersion: false,
        ...overrides,
    } as unknown as ShowProps;
    return render(<PolicyShow {...props} />);
}

describe('Policy page — read and confirm', () => {
    beforeEach(() => {
        inertia.formPost.mockReset();
        inertia.routerPost.mockReset();
    });
    afterEach(cleanup);

    it('starts with the confirm box unticked and only posts once it is ticked', () => {
        renderShow();

        const checkbox = screen.getByRole('checkbox', {
            name: /i have read version 3 of this policy/i,
        });
        expect(checkbox.getAttribute('aria-checked')).toBe('false');

        const button = screen.getByRole('button', {
            name: /confirm i've read this policy/i,
        }) as HTMLButtonElement;
        expect(button.disabled).toBe(true);

        fireEvent.click(checkbox);
        expect(button.disabled).toBe(false);
        fireEvent.click(button);

        expect(inertia.formPost).toHaveBeenCalledWith(
            '/governance/policies/5/attest',
            expect.objectContaining({ acknowledged: true }),
        );
    });

    it('shows a receipt instead of the form once the member has confirmed', () => {
        renderShow({
            confirmation: {
                required: true,
                state: 'confirmed',
                effective_from: '2026-01-01',
                frequency: null,
                my_confirmation: {
                    version: 3,
                    confirmed_at: '2026-09-06T21:00:00Z',
                    due_again_on: null,
                },
                can_confirm: false,
                board_confirmed: 3,
                board_total: 7,
            },
        } as Partial<ShowProps>);

        expect(
            screen.getByText('You confirmed version 3 on 7 September 2026.'),
        ).toBeTruthy();
        expect(screen.queryByRole('checkbox')).toBeNull();
        expect(
            screen.queryByRole('button', { name: /confirm i've read this policy/i }),
        ).toBeNull();
    });

    it('offers no confirm button before the policy comes into effect', () => {
        renderShow({
            confirmation: {
                required: true,
                state: 'not_yet_in_effect',
                effective_from: '2099-03-01',
                frequency: null,
                my_confirmation: null,
                can_confirm: false,
                board_confirmed: 0,
                board_total: 7,
            },
        } as Partial<ShowProps>);

        expect(
            screen.getByText(/comes into effect on 1 March 2099/i),
        ).toBeTruthy();
        expect(
            screen.queryByRole('button', { name: /confirm i've read this policy/i }),
        ).toBeNull();
    });

    it('asks before approving and publishing', () => {
        renderShow({
            policy: { ...basePolicy, status: 'draft', approved_by_user: null },
            canApprove: true,
            confirmation: {
                required: true,
                state: 'not_approved',
                effective_from: null,
                frequency: null,
                my_confirmation: null,
                can_confirm: false,
                board_confirmed: 0,
                board_total: 7,
            },
        } as Partial<ShowProps>);

        fireEvent.click(screen.getByRole('button', { name: /^approve$/i }));
        expect(inertia.routerPost).not.toHaveBeenCalled();
        expect(screen.getByText('Approve and publish this policy?')).toBeTruthy();
        expect(
            screen.getByText(/board members will be asked to confirm they've read it/i),
        ).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: /approve and publish/i }));
        expect(inertia.routerPost).toHaveBeenCalledWith(
            '/governance/policies/5/approve',
            {},
            expect.anything(),
        );
    });
});

describe('Policy wording helpers', () => {
    it('never counts more confirmations than board members', () => {
        expect(confirmedOf(9, 7)).toBe('7 of 7');
        expect(confirmedOf(3, 7)).toBe('3 of 7');
    });

    it('uses read-and-confirm wording for every state', () => {
        expect(confirmationChip('to_confirm').label).toBe('To confirm');
        expect(confirmationChip('confirmed').label).toBe('Confirmed');
        expect(confirmationChip('not_yet_in_effect', '2026-10-01').label).toBe(
            'Comes into effect on 1 Oct 2026',
        );
        const labels = (
            [
                'not_required',
                'not_approved',
                'replaced',
                'not_yet_in_effect',
                'to_confirm',
                'due_again',
                'confirmed',
            ] as const
        ).map((state) => confirmationChip(state).label.toLowerCase());
        expect(labels.join(' ')).not.toMatch(/attest|sign-off/);
    });

    it('never lets Edit make a policy approved', () => {
        expect(editStatusOptions('draft').map((o) => o.value)).toEqual([
            'draft',
            'under_review',
            'archived',
        ]);
        expect(editStatusOptions('active').map((o) => o.value)).toEqual([
            'active',
            'archived',
        ]);
    });
});
