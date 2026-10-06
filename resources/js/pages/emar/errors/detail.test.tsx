import { router } from '@inertiajs/react';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ErrorDetail } from './_detail';
import type { ErrorRecord } from './_shared';

vi.mock('@inertiajs/react', () => ({ router: { post: vi.fn() } }));
vi.mock('@/components/files/file-preview-dialog', () => ({
    FilePreviewDialog: () => null,
}));

const error: ErrorRecord = {
    id: 91,
    ref: 'ME-91',
    error_type: 'omission',
    severity: 'minor',
    stage: 'triage',
    status: 'open',
    sac: {
        enabled: false,
        proposed: null,
        confirmed: null,
        confirmed_at: null,
    },
    reached_client: 'no',
    harm_level: 'none',
    summary: 'Fictional missed dose',
    description: 'A fictional account for dialog recovery.',
    immediate_action: null,
    contributing_factors: null,
    review_notes: null,
    outcome: null,
    preventive_actions: null,
    close_note: null,
    occurred_at: '2026-10-04T20:00:00Z',
    reported_at: '2026-10-04T20:05:00Z',
    closed_at: null,
    triage_due_at: null,
    investigation_due_at: null,
    client_id: 41,
    client: { id: 41, first_name: 'Preview', last_name: 'Person' },
    site_name: 'Preview house',
    medication: null,
    owner: null,
    reported_by_user: { id: 51, name: 'Preview worker' },
    incident: null,
    mar_url: null,
    entries: [],
    actions: [],
    close_blockers: [],
    can_close: false,
    can_reopen: false,
    attachments: [],
};

afterEach(async () => {
    cleanup();
    vi.clearAllMocks();
    await new Promise((resolve) => window.setTimeout(resolve, 0));
});

function openAccount() {
    const onClose = vi.fn();
    render(
        <ErrorDetail
            error={error}
            canManage={false}
            canReadInvestigation={false}
            canRecord
            staff={[]}
            onClose={onClose}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', {
            name: /^Accounts\s*Added, never edited$/,
        }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Add your account' }));
    return onClose;
}

describe('existing medication error account recovery', () => {
    it('keeps unsaved text on Back, then discards only the account when explicitly requested', async () => {
        const onClose = openAccount();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Your account' }),
            { target: { value: 'My unsaved account.' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        const guard = await screen.findByRole('dialog', {
            name: 'Discard your changes?',
        });
        expect(onClose).not.toHaveBeenCalled();
        fireEvent.click(
            within(guard).getByRole('button', { name: 'Keep editing' }),
        );
        await waitFor(() =>
            expect(
                screen.queryByRole('dialog', { name: 'Discard your changes?' }),
            ).not.toBeInTheDocument(),
        );
        expect(
            screen.getByRole('textbox', { name: 'Your account' }),
        ).toHaveValue('My unsaved account.');
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Discard changes' }),
        );
        expect(
            screen.queryByRole('textbox', { name: 'Your account' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: 'Add your account',
            }),
        ).toBeInTheDocument();
        expect(onClose).not.toHaveBeenCalled();
        expect(router.post).not.toHaveBeenCalled();
    });

    it('guards closing the record while the account is dirty', async () => {
        const onClose = openAccount();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Your account' }),
            { target: { value: 'Keep this account until reviewed.' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        expect(
            await screen.findByRole('dialog', {
                name: 'Discard your changes?',
            }),
        ).toBeInTheDocument();
        expect(onClose).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard changes' }),
        );
        expect(onClose).toHaveBeenCalledOnce();
        expect(router.post).not.toHaveBeenCalled();
    });

    it('lets an untouched account go back and prevents close while saving the original account command', () => {
        const onClose = openAccount();
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        expect(
            screen.queryByRole('dialog', { name: 'Discard your changes?' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Add your account',
            }),
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Your account' }),
            { target: { value: 'Account ready for review.' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Review' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        expect(router.post).toHaveBeenCalledWith(
            '/emar/errors/91/accounts',
            { text: 'Account ready for review.' },
            expect.any(Object),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        expect(onClose).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('dialog', { name: 'Discard your changes?' }),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
    });
});
