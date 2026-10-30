import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { expect, it } from 'vitest';
import {
    ContactReviewFields,
    contactDraft,
    contactReviewPayload,
    contactReviewValid,
    type ContactProfile,
} from './contact-review-fields';

it('keeps unknown contact instructions unconfirmed and requires a usable agreed channel', () => {
    const draft = contactDraft();
    expect(contactReviewValid(draft)).toBe(false);
    const ready = {
        ...draft,
        email: ' person@example.test ',
        channel: 'email',
        leave_message: 'no',
        verification_note: 'Reviewed with the person',
        confirmed: true,
    };
    expect(contactReviewValid(ready)).toBe(true);
    expect(contactReviewPayload(ready)).toEqual(
        expect.objectContaining({
            email: 'person@example.test',
            phone: null,
            leave_message: false,
        }),
    );
    expect(contactReviewValid({ ...ready, email: '' })).toBe(false);
    expect(contactReviewValid({ ...ready, channel: 'none', email: '' })).toBe(
        true,
    );
    expect(
        contactReviewValid({ ...ready, channel: 'none', leave_message: 'yes' }),
    ).toBe(false);
});

it('keeps established values read-only in handover and invalidates confirmation after edits', () => {
    const profile: ContactProfile = {
        phone: '021 existing',
        email: null,
        preferences: null,
        review_current: false,
        can_add: true,
        fingerprint: 'test',
    };
    function Editor() {
        const [draft, setDraft] = useState({
            ...contactDraft(profile),
            confirmed: true,
        });
        return (
            <ContactReviewFields
                existing={profile}
                draft={draft}
                onChange={setDraft}
            />
        );
    }
    render(<Editor />);
    expect(screen.getByLabelText(/Person’s phone/)).toHaveAttribute('readonly');
    expect(screen.getByLabelText('Person’s email')).not.toHaveAttribute(
        'readonly',
    );
    const confirmation = screen.getByRole('checkbox', {
        name: /These contact details belong/,
    });
    expect(confirmation).toBeChecked();
    fireEvent.change(screen.getByLabelText('Person’s email'), {
        target: { value: 'person@example.test' },
    });
    expect(confirmation).not.toBeChecked();
});
