import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PersonLinkPopup } from './person-link-popup';

const options = {
    site: { id: 4, name: 'Synthetic House' },
    people: [
        { id: 11, name: 'Ana', consent: { active: true, consent_id: 3, until: '2027-03-31T00:00:00+13:00' }, record_href: '/clients/11', plans: [] },
        { id: 12, name: 'Tama', consent: { active: false, consent_id: null, until: null }, record_href: '/clients/12', plans: [] },
    ],
};

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

function choose(name: string) {
    fireEvent.click(screen.getByRole('combobox', { name: /Person/ }));
    fireEvent.click(screen.getByRole('option', { name }));
}

describe('Link to a person (pop-up 65)', () => {
    it('keeps Link device off and says where to record consent when it isn’t active', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: options });
        const post = vi.spyOn(axios, 'post');
        render(<PersonLinkPopup open device={{ id: 7, name: 'Synthetic bed sensor' }} onClose={() => undefined} onLinked={() => undefined} />);

        await waitFor(() => expect(screen.getByRole('combobox', { name: /Person/ })).not.toBeDisabled());
        choose('Tama');

        expect(screen.getByText(/Tama’s consent to monitoring sensors isn’t active/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Record consent' })).toHaveAttribute('href', '/clients/12');
        expect(screen.getByRole('button', { name: 'Link device' })).toBeDisabled();
        expect(post).not.toHaveBeenCalled();
    });

    it('links with the falls rule once consent is checked', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: options });
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 1 } });
        const linked = vi.fn();
        render(<PersonLinkPopup open device={{ id: 7, name: 'Synthetic bed sensor' }} onClose={() => undefined} onLinked={linked} />);

        await waitFor(() => expect(screen.getByRole('combobox', { name: /Person/ })).not.toBeDisabled());
        choose('Ana');

        expect(screen.getByText(/Consent checked: Ana’s consent to monitoring sensors is active/)).toBeInTheDocument();
        expect(screen.getByText('Alert night staff if Ana out of bed for over 10 minutes between 22:00 and 06:00')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Link device' }));

        await waitFor(() => expect(linked).toHaveBeenCalled());
        expect(post).toHaveBeenCalledWith('/security-devices/devices/7/care-link', {
            client_id: 11,
            purpose: 'falls',
            care_plan_id: null,
            rule: { minutes: 10, from: '22:00', to: '06:00' },
            note: null,
        });
    });
});
