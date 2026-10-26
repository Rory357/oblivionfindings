import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import type { ContactProfile } from '../contact-review-fields';
import { ProfileContactPreferencesCard } from './contact-preferences-card';

const { put, reload, preserveAndRun } = vi.hoisted(() => ({
    put: vi.fn(),
    reload: vi.fn(),
    preserveAndRun: vi.fn((run: () => void) => run()),
}));
vi.mock('axios', () => ({
    default: {
        put,
        isAxiosError: (error: { isAxiosError?: boolean }) =>
            !!error.isAxiosError,
    },
}));
vi.mock('@inertiajs/react', () => ({ router: { reload } }));
vi.mock('@/hooks/use-settings-leave-confirmation', () => ({
    useSettingsLeaveConfirmation: () => ({
        confirmation: null,
        request: (run: () => void) => run(),
        preserveAndRun,
    }),
}));
const profile: ContactProfile = {
    phone: '021 fictional',
    email: 'person@example.test',
    can_add: false,
    review_current: true,
    fingerprint: 'review-version',
    preferences: {
        phone: '021 fictional',
        email: 'person@example.test',
        channel: 'email',
        leave_message: false,
        safe_time: 'Mornings only',
        communication: null,
    },
};

beforeEach(() => {
    vi.clearAllMocks();
    put.mockResolvedValue({ data: { saved: true } });
});
function openReview() {
    render(<ProfileContactPreferencesCard id={1} profile={profile} canEdit />);
    fireEvent.click(
        screen.getByRole('button', { name: 'Review contact details' }),
    );
    fireEvent.change(
        screen.getByRole('textbox', { name: /How were contact details/ }),
        { target: { value: 'Reviewed together with the person' } },
    );
    fireEvent.click(
        screen.getByRole('checkbox', { name: /These contact details belong/ }),
    );
}

it('refreshes confirmed saved contacts without triggering the unsaved-draft guard', async () => {
    openReview();
    fireEvent.click(
        screen.getByRole('button', { name: 'Save contact review' }),
    );
    await waitFor(() =>
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
    );
    expect(put).toHaveBeenCalledWith(
        '/operations/clients/1/contact-preferences',
        expect.objectContaining({
            fingerprint: 'review-version',
            review: expect.objectContaining({
                email: 'person@example.test',
                confirmed: true,
                leave_message: false,
            }),
        }),
    );
    expect(preserveAndRun).toHaveBeenCalledOnce();
    expect(reload).toHaveBeenCalledWith({
        only: ['client'],
        preserveScroll: true,
    });
});

it('keeps an uncertain contact save visible and blocks another write until reload', async () => {
    put.mockRejectedValueOnce({ isAxiosError: true });
    openReview();
    fireEvent.click(
        screen.getByRole('button', { name: 'Save contact review' }),
    );
    await screen.findByRole('button', { name: 'Reload profile' });
    expect(screen.getByRole('alert')).toHaveTextContent('uncertain');
    expect(screen.getByLabelText('Person’s email')).toHaveValue(
        'person@example.test',
    );
    expect(screen.getByLabelText('Person’s email')).toBeDisabled();
    expect(
        screen.queryByRole('button', { name: 'Save contact review' }),
    ).not.toBeInTheDocument();
    expect(put).toHaveBeenCalledOnce();
});

it('shows stale instructions honestly and hides editing without permission', () => {
    render(
        <ProfileContactPreferencesCard
            id={1}
            profile={{ ...profile, review_current: false }}
            canEdit={false}
        />,
    );
    expect(screen.getByRole('status')).toHaveTextContent(
        'changed since the last review',
    );
    expect(
        screen.queryByRole('button', { name: 'Review contact details' }),
    ).not.toBeInTheDocument();
    expect(screen.getByText('Do not leave a message')).toBeInTheDocument();
});
