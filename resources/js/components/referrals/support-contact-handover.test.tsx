import {
    ProfileSupportContactsCard,
    type SupportContact,
} from '@/components/clients/profile/support-contacts-card';
import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import type { PeopleContact } from './people-contacts';
import {
    SupportContactHandoverFields,
    useSupportContactHandovers,
} from './support-contact-handover';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));
const source: PeopleContact = {
    id: '2d158157-9bfa-489f-979d-d7bcad586128',
    version: 3,
    review_current: true,
    reviewed_at: '2026-10-18T04:00:00Z',
    updated_at: '2026-10-18T04:00:00Z',
    details: {
        name: 'Morgan Example',
        role: 'supporter',
        relationship: 'Chosen supporter',
        organisation: null,
        phone: '021 555 0188',
        email: null,
        channel: 'phone',
        safe_time: 'Afternoons only',
        leave_message: false,
        communication: 'Plain language',
        state: 'current',
        may_contact: true,
        purpose: 'Help arrange appointments',
        authority_note: null,
        review_note: 'Reviewed with the person',
        review_due_at: '2026-10-25T00:00:00Z',
        confirmed: true,
    },
};
function Harness({ current = true }: { current?: boolean }) {
    const state = useSupportContactHandovers();
    return (
        <>
            <SupportContactHandoverFields
                contacts={[{ ...source, review_current: current }]}
                reviews={state.reviews}
                onChange={state.setReviews}
            />
            <output data-testid="valid">{String(state.valid)}</output>
            <output data-testid="payload">
                {JSON.stringify(state.reviews)}
            </output>
        </>
    );
}
it('requires an explicit care purpose and confirmation, invalidates edited approval and removes deselected contacts', () => {
    render(<Harness />);
    fireEvent.click(
        screen.getByText('People involved in ongoing care · optional'),
    );
    const select = screen.getByRole('checkbox', {
        name: 'Include Morgan Example in ongoing care',
    });
    fireEvent.click(select);
    expect(screen.getByTestId('valid')).toHaveTextContent('false');
    expect(screen.getByText('Do not leave messages')).toBeInTheDocument();
    fireEvent.change(
        screen.getByLabelText('Why is this contact needed for ongoing care?'),
        { target: { value: 'Help coordinate ongoing care visits' } },
    );
    const confirm = screen.getByRole('checkbox', {
        name: /I checked the existing person contacts/,
    });
    fireEvent.click(confirm);
    expect(screen.getByTestId('valid')).toHaveTextContent('true');
    expect(screen.getByTestId('payload')).toHaveTextContent(
        '"contact_version":3',
    );
    fireEvent.change(
        screen.getByLabelText('Why is this contact needed for ongoing care?'),
        { target: { value: 'Changed purpose for ongoing appointments' } },
    );
    expect(confirm).not.toBeChecked();
    fireEvent.click(select);
    expect(screen.getByTestId('payload')).toHaveTextContent('[]');
});
it('prevents selection of a contact whose review is stale', () => {
    render(<Harness current={false} />);
    fireEvent.click(
        screen.getByText('People involved in ongoing care · optional'),
    );
    expect(screen.getByRole('checkbox')).toBeDisabled();
    expect(
        screen.getByText(/Review needed before handover/),
    ).toBeInTheDocument();
});
it('withholds stale canonical contact details even if an older client payload still contains them', () => {
    const contact: SupportContact = {
        id: 2,
        version: 1,
        review_current: false,
        purpose: 'Arrange care visits',
        released_at: '2026-10-18T04:00:00Z',
        review_url: null,
        details: source.details,
    };
    render(<ProfileSupportContactsCard contacts={[contact]} />);
    expect(screen.getByText('Review needed')).toBeInTheDocument();
    expect(screen.queryByText('021 555 0188')).not.toBeInTheDocument();
    expect(screen.queryByText('Arrange care visits')).not.toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
});
