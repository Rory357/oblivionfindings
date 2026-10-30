import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import {
    PeopleContactDialog,
    PeopleContactsPanel,
    type PeopleContact,
} from './people-contacts';
import type { ReferralDetail } from './types';

const { post, reload } = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn() }));
vi.mock('axios', () => ({
    default: {
        post,
        isAxiosError: (error: { isAxiosError?: boolean }) =>
            error.isAxiosError === true,
    },
}));
vi.mock('@inertiajs/react', () => ({
    router: { reload, on: vi.fn(() => vi.fn()), visit: vi.fn() },
}));
vi.mock('@/components/wizard/primitives', async (importOriginal) => {
    const original =
        await importOriginal<typeof import('@/components/wizard/primitives')>();
    return {
        ...original,
        SelectInput: ({
            value,
            onChange,
            options,
            placeholder,
            disabled,
        }: {
            value: string;
            onChange: (value: string) => void;
            options: { value: string; label: string }[];
            placeholder: string;
            disabled?: boolean;
        }) => (
            <select
                aria-label={placeholder}
                value={value}
                disabled={disabled}
                onChange={(event) => onChange(event.target.value)}
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        ),
    };
});
vi.mock('./date-time', () => ({
    ReferralDateTime: ({
        label,
        value,
        onChange,
    }: {
        label: string;
        value: string;
        onChange: (value: string) => void;
    }) => (
        <input
            aria-label={label}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        />
    ),
    validReferralDateTime: (value: string) => value.includes('T'),
}));
const referral = {
    reference: '01JREFERRALPREVIEW000000001',
    version: 4,
    name: 'Casey Example',
    stage: 'assessment',
    authority_scopes: { collect: true, contact: true },
} as unknown as ReferralDetail;
const contact: PeopleContact = {
    id: '2d158157-9bfa-489f-979d-d7bcad586128',
    version: 1,
    reviewed_at: '2026-10-18T04:00:00Z',
    updated_at: '2026-10-18T04:00:00Z',
    review_current: true,
    details: {
        name: 'Morgan Example',
        role: 'supporter',
        relationship: 'Chosen support person',
        organisation: null,
        phone: '021 555 0188',
        email: null,
        channel: 'phone',
        safe_time: 'Afternoons only',
        leave_message: true,
        communication: 'Plain language',
        state: 'current',
        may_contact: true,
        purpose: 'Help arrange the support assessment',
        authority_note: null,
        review_note: 'Previous verification note',
        review_due_at: '2026-10-25T00:00:00Z',
        confirmed: true,
    },
};
beforeEach(() => {
    vi.clearAllMocks();
    Element.prototype.scrollTo = vi.fn();
    post.mockResolvedValue({ data: { saved: true } });
});

it('requires a reviewed confirmation and preserves the identical payload after an uncertain save', async () => {
    post.mockRejectedValueOnce({ isAxiosError: true });
    render(
        <PeopleContactDialog
            referral={referral}
            contact={null}
            onClose={vi.fn()}
        />,
    );
    fireEvent.change(screen.getByLabelText('Contact name'), {
        target: { value: 'Morgan Example' },
    });
    fireEvent.change(screen.getByLabelText('Relationship to the person'), {
        target: { value: 'Chosen support person' },
    });
    fireEvent.change(screen.getByLabelText('Why is this person involved?'), {
        target: { value: 'Help arrange the support assessment' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.change(
        screen.getByRole('combobox', { name: 'Choose review status' }),
        { target: { value: 'current' } },
    );
    fireEvent.change(screen.getByLabelText('Phone'), {
        target: { value: '021 555 0188' },
    });
    fireEvent.change(
        screen.getByRole('combobox', { name: 'Choose contact channel' }),
        { target: { value: 'phone' } },
    );
    fireEvent.click(
        screen.getByRole('checkbox', { name: /Contact is permitted/ }),
    );
    fireEvent.change(screen.getByLabelText('Review due'), {
        target: { value: '2026-10-25T13:00+13:00' },
    });
    fireEvent.change(
        screen.getByLabelText(
            'What was checked or why is the status changing?',
        ),
        {
            target: {
                value: 'Details, role and permission checked with the person',
            },
        },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(
        screen.getByRole('button', { name: 'Save contact review' }),
    );
    expect(post).not.toHaveBeenCalled();
    fireEvent.click(
        screen.getByRole('checkbox', { name: /I verified this person/ }),
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Save contact review' }),
    );
    await screen.findByRole('button', { name: 'Retry same review' });
    const issued = structuredClone(post.mock.calls[0][1]);
    fireEvent.click(screen.getByRole('button', { name: 'Retry same review' }));
    await waitFor(() => expect(post).toHaveBeenCalledTimes(2));
    expect(post.mock.calls[1][1]).toEqual(issued);
    expect(issued).toEqual(
        expect.objectContaining({
            version: 4,
            contact_version: 0,
            name: 'Morgan Example',
            role: 'supporter',
            may_contact: true,
            confirmed: true,
        }),
    );
    expect(await screen.findByText('Contact review saved')).toBeInTheDocument();
});

it('shows the stored NZ time, requires a fresh reason and retains original contact details when withdrawing', async () => {
    render(
        <PeopleContactDialog
            referral={referral}
            contact={contact}
            onClose={vi.fn()}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(screen.getByLabelText('Review due')).toHaveValue(
        '2026-10-25T13:00+13:00',
    );
    expect(
        screen.getByLabelText(
            'What was checked or why is the status changing?',
        ),
    ).toHaveValue('');
    fireEvent.change(
        screen.getByRole('combobox', { name: 'Choose review status' }),
        { target: { value: 'withdrawn' } },
    );
    expect(screen.getByLabelText('Phone')).toHaveAttribute('readonly');
    expect(
        screen.getByRole('checkbox', { name: /Contact is permitted/ }),
    ).not.toBeChecked();
    fireEvent.change(
        screen.getByLabelText(
            'What was checked or why is the status changing?',
        ),
        { target: { value: 'The person withdrew permission to make contact' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(
        screen.getByRole('button', { name: 'Save contact review' }),
    );
    await screen.findByText('Contact review saved');
    expect(post.mock.calls[0][1]).toEqual(
        expect.objectContaining({
            contact_id: contact.id,
            contact_version: 1,
            phone: contact.details.phone,
            state: 'withdrawn',
            may_contact: false,
            leave_message: false,
            confirmed: false,
        }),
    );
});

it('shows stale permission plainly and withholds editing controls for a read-only colleague', () => {
    render(
        <PeopleContactsPanel
            referral={{
                ...referral,
                people_contacts: [{ ...contact, review_current: false }],
            }}
            canManage={false}
        />,
    );
    expect(screen.getByText('Review needed')).toBeInTheDocument();
    expect(screen.getByText('Do not leave messages')).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Add contact' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Review contact' }),
    ).not.toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
});

it('allows permission withdrawal on a closed referral while preventing a new contact', () => {
    render(
        <PeopleContactsPanel
            referral={{
                ...referral,
                stage: 'closed',
                people_contacts: [{ ...contact, review_current: false }],
            }}
            canManage
        />,
    );
    expect(screen.getByRole('button', { name: 'Add contact' })).toBeDisabled();
    expect(
        screen.getByRole('button', { name: 'Review contact' }),
    ).toBeEnabled();
    expect(
        screen.getByText(/Existing contact permission can still be withdrawn/),
    ).toBeInTheDocument();
});
