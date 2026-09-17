import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { FileText } from 'lucide-react';
import type { ComponentProps } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { SpecialistCommandWizard } from '../specialist-create-wizard';
import { specialistCreationFrom } from '../specialist-creation-result';

const account = vi.hoisted(() => ({ id: 7, reload: vi.fn() }));
vi.mock('@inertiajs/react', async (original) => ({
    ...(await original<typeof import('@inertiajs/react')>()),
    usePage: () => ({ props: { auth: { user: { id: account.id } } } }),
    router: { reload: account.reload },
}));

function props(
    overrides: Partial<ComponentProps<typeof SpecialistCommandWizard>> = {},
): ComponentProps<typeof SpecialistCommandWizard> {
    return {
        open: true,
        onClose: vi.fn(),
        onDiscard: vi.fn(),
        title: 'Edit investigation',
        description: 'Review proposed investigation details.',
        icon: FileText,
        submitLabel: 'Save investigation',
        processing: false,
        dirty: true,
        errors: {},
        review: [{ label: 'Root cause', value: 'Retained proposal' }],
        onSubmit: vi.fn((event) => event.preventDefault()),
        children: (
            <label>
                Root cause
                <input defaultValue="Retained proposal" />
            </label>
        ),
        ...overrides,
    };
}

beforeEach(() => {
    account.id = 7;
    account.reload.mockReset();
});
afterEach(cleanup);

it('requires a proposal review before submitting and freezes editing while saving', () => {
    const initial = props();
    const { rerender } = render(<SpecialistCommandWizard {...initial} />);
    expect(
        screen.queryByRole('button', { name: 'Save investigation' }),
    ).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Review' }));
    expect(
        within(
            screen.getByRole('region', { name: 'Review details' }),
        ).getByText('Retained proposal'),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Save investigation' }));
    expect(initial.onSubmit).toHaveBeenCalledTimes(1);
    rerender(<SpecialistCommandWizard {...initial} processing />);
    expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
    expect(screen.getByLabelText('Root cause')).toBeDisabled();
});

it('keeps a stale proposal and requires current-record review before version adoption without saving', () => {
    const adopt = vi.fn();
    const initial = props({
        expectedVersion: 3,
        current: {
            version: 4,
            review: [
                { label: 'Root cause', value: 'Newer saved investigation' },
            ],
        },
        onVersionReviewed: adopt,
    });
    render(<SpecialistCommandWizard {...initial} />);
    expect(screen.getByLabelText('Root cause')).toHaveValue(
        'Retained proposal',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Review' }));
    expect(
        screen.getByRole('button', { name: 'Save investigation' }),
    ).toBeDisabled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Review current record' }),
    );
    expect(
        within(
            screen.getByRole('region', { name: 'Current record' }),
        ).getByText('Newer saved investigation'),
    ).toBeVisible();
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Keep my changes against this version',
        }),
    );
    expect(adopt).toHaveBeenCalledWith(4);
    expect(initial.onSubmit).not.toHaveBeenCalled();
    expect(screen.getByLabelText('Root cause')).toHaveValue(
        'Retained proposal',
    );
});

it('reloads instead of adopting a version already rejected by the server', () => {
    const adopt = vi.fn();
    render(
        <SpecialistCommandWizard
            {...props({
                expectedVersion: 3,
                current: { version: 3, review: [] },
                errors: { expected_version: 'Stale' },
                onVersionReviewed: adopt,
            })}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Reload current record' }),
    );
    expect(account.reload).toHaveBeenCalledTimes(1);
    expect(adopt).not.toHaveBeenCalled();
    expect(
        screen.queryByRole('button', {
            name: 'Keep my changes against this version',
        }),
    ).not.toBeInTheDocument();
});

it('requires explicit discard and conceals an old-account buffer immediately', () => {
    const initial = props();
    const { rerender } = render(<SpecialistCommandWizard {...initial} />);
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.getByRole('alertdialog')).toBeVisible();
    expect(initial.onDiscard).not.toHaveBeenCalled();
    fireEvent.click(
        within(screen.getByRole('alertdialog')).getByRole('button', {
            name: 'Cancel',
        }),
    );
    expect(screen.getByLabelText('Root cause')).toHaveValue(
        'Retained proposal',
    );
    account.id = 9;
    rerender(<SpecialistCommandWizard {...initial} />);
    expect(screen.queryByLabelText('Root cause')).not.toBeInTheDocument();
    expect(initial.onDiscard).toHaveBeenCalledTimes(1);
    expect(initial.onClose).toHaveBeenCalledTimes(1);
});

it('accepts a creation receipt only for the current account and workspace', () => {
    const receipt = {
        specialist_id: 23,
        reference: 'IT-000321',
        workspace: 'changes',
        actor_user_id: 7,
    };
    expect(
        specialistCreationFrom({ flash: { it_ticket: receipt } }, 'changes', 7),
    ).toEqual({ id: 23, reference: 'IT-000321', workspace: 'changes' });
    expect(
        specialistCreationFrom(
            { flash: { it_ticket: receipt } },
            'problems',
            7,
        ),
    ).toBe('unconfirmed');
    expect(
        specialistCreationFrom({ flash: { it_ticket: receipt } }, 'changes', 9),
    ).toBe('unconfirmed');
    expect(
        specialistCreationFrom(
            { flash: { it_ticket: { ...receipt, specialist_id: -1 } } },
            'changes',
            7,
        ),
    ).toBe('unconfirmed');
});
