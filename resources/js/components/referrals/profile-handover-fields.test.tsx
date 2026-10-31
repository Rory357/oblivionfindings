import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { expect, it } from 'vitest';
import {
    ProfileHandoverFields,
    profileHandoverPayload,
    profileHandoverValid,
    type ProfileHandoverDraft,
} from './profile-handover-fields';

function Editor() {
    const [draft, setDraft] = useState<ProfileHandoverDraft>({
        values: {},
        note: '',
        confirmed: false,
    });
    return (
        <ProfileHandoverFields
            fields={[
                {
                    key: 'languages',
                    label: 'Languages',
                    current: null,
                    can_add: true,
                },
                {
                    key: 'ethnicity',
                    label: 'Ethnicity',
                    current: 'Existing choice',
                    can_add: false,
                },
            ]}
            draft={draft}
            onChange={setDraft}
        />
    );
}

it('keeps optional unknown values empty and existing choices read-only', () => {
    render(<Editor />);
    fireEvent.click(screen.getByText(/Culture and language/));
    expect(screen.getByLabelText(/Languages/)).toHaveValue('');
    expect(screen.getByText('Existing choice')).toBeInTheDocument();
    expect(
        screen.queryByRole('textbox', { name: 'Ethnicity' }),
    ).not.toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
});

it('invalidates confirmation whenever reviewed values or their evidence change', () => {
    render(<Editor />);
    fireEvent.click(screen.getByText(/Culture and language/));
    fireEvent.change(screen.getByLabelText(/Languages/), {
        target: { value: 'English' },
    });
    const checkbox = screen.getByRole('checkbox', {
        name: /person’s own details/,
    });
    fireEvent.click(checkbox);
    expect(checkbox).toBeChecked();
    fireEvent.change(screen.getByLabelText(/Languages/), {
        target: { value: 'English, te reo Māori' },
    });
    expect(checkbox).not.toBeChecked();
    fireEvent.click(checkbox);
    fireEvent.change(
        screen.getByLabelText('How were these profile details verified?'),
        { target: { value: 'The person reviewed these values' } },
    );
    expect(checkbox).not.toBeChecked();
});

it('omits empty optional values and requires meaningful verification before handover', () => {
    const draft = {
        values: { languages: 'English, te reo Māori, English', iwi: ' ' },
        note: 'Reviewed together',
        confirmed: true,
    };
    expect(profileHandoverPayload(draft)).toEqual({
        fields: { languages: ['English', 'te reo Māori'] },
        verification_note: 'Reviewed together',
        confirmed: true,
    });
    expect(profileHandoverValid(draft)).toBe(true);
    expect(profileHandoverValid({ ...draft, confirmed: false })).toBe(false);
    expect(profileHandoverValid({ ...draft, note: '   ' })).toBe(false);
    expect(
        profileHandoverPayload({
            values: { languages: ', ,', iwi: '' },
            note: '',
            confirmed: false,
        }),
    ).toBeUndefined();
});
