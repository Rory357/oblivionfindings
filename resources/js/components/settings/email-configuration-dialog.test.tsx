/* eslint-disable no-restricted-syntax -- Test doubles use raw buttons as simple controls. */
import { fireEvent, render, screen } from '@testing-library/react';
import axios from 'axios';
import type { PropsWithChildren, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
    EmailConnection,
    EmailSettingsState,
} from './email-configuration-contract';
import { EmailConfigurationDialog } from './email-configuration-dialog';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        put: vi.fn(),
        isAxiosError: (value: unknown) =>
            !!value && typeof value === 'object' && 'response' in value,
    },
}));
vi.mock('@/hooks/use-settings-leave-confirmation', () => ({
    useSettingsLeaveConfirmation: () => ({
        request: (run: () => void) => run(),
        confirmation: null,
    }),
}));
vi.mock('@/components/wizard/shell', () => ({
    WizardShell: ({
        children,
        footerStart,
        footerEnd,
        success,
        steps,
        onStepClick,
        pct,
        pctLabel,
    }: PropsWithChildren<{
        footerStart: ReactNode;
        footerEnd: ReactNode;
        success?: ReactNode;
        steps: readonly { key: string; label: string }[];
        onStepClick: (index: number) => void;
        pct: number;
        pctLabel: string;
    }>) => (
        <section>
            <p>{`${pctLabel}: ${pct}%`}</p>
            <nav>
                {steps.map((step, index) => (
                    <button key={step.key} onClick={() => onStepClick(index)}>
                        Go to {step.label}
                    </button>
                ))}
            </nav>
            {success ?? (
                <>
                    {children}
                    {footerStart}
                    {footerEnd}
                </>
            )}
        </section>
    ),
    WizardSuccessPane: ({
        title,
        actions,
    }: {
        title: string;
        actions: ReactNode;
    }) => (
        <section>
            <h2>{title}</h2>
            {actions}
        </section>
    ),
    ReviewCard: ({ children, title }: PropsWithChildren<{ title: string }>) => (
        <section>
            <h2>{title}</h2>
            {children}
        </section>
    ),
    ReviewRow: ({ label, value }: { label: string; value: ReactNode }) => (
        <p>
            {label}: {value}
        </p>
    ),
    WizardStepPane: ({ children }: PropsWithChildren) => <>{children}</>,
}));

const connection: EmailConnection = {
    id: 4,
    provider: 'google',
    configuration_version: 1,
    account_email: 'owner@example.test',
    mailbox_email: 'support@example.test',
    connected: true,
    sending_issue: null,
};

const initial: EmailSettingsState = {
    actor_id: 17,
    can_manage: true,
    smtp_password_saved: false,
    capture_mode: null,
    delivery_issue: null,
    last_test: null,
    connections: [connection],
    settings: {
        configuration_version: 2,
        provider: 'google',
        smtp_host: 'smtp.example.test',
        smtp_port: 587,
        smtp_encryption: 'tls',
        smtp_username: 'mailer',
        from_address: 'support@example.test',
        from_name: 'Support',
        support_enabled: true,
        support_connection_id: connection.id,
        support_connection_version: connection.configuration_version,
        public_reply_mode: 'link_only',
    },
};
const saved = (
    version: number,
    actor = initial.actor_id,
): EmailSettingsState => ({
    ...initial,
    actor_id: actor,
    settings: { ...initial.settings, configuration_version: version },
});
const error = (status: number) => ({ response: { status } });

function setup(current = initial) {
    const onSaved = vi.fn();
    const onAccessLost = vi.fn();
    render(
        <EmailConfigurationDialog
            initial={current}
            onSaved={onSaved}
            onAccessLost={onAccessLost}
            onClose={vi.fn()}
        />,
    );
    return { onSaved, onAccessLost };
}
function goToReview() {
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
}

describe('email settings save confirmation and recovery', () => {
    beforeEach(() => vi.clearAllMocks());

    it('keeps rail navigation free, but routes a submit with missing required identity fields back to that step', () => {
        setup({
            ...initial,
            settings: { ...initial.settings, from_name: '' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Go to Review' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Save email settings' }),
        );
        expect(
            screen.getByText('Complete the required fields before saving.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Choose the support identity'),
        ).toBeInTheDocument();
        expect(screen.getByText('Enter a sender name.')).toBeInTheDocument();
        expect(axios.put).not.toHaveBeenCalled();
    });

    it('does not require hidden connection or host settings while delivery is disabled', () => {
        setup({
            ...initial,
            settings: {
                ...initial.settings,
                provider: 'smtp',
                smtp_host: '',
                support_enabled: false,
                support_connection_id: null,
                support_connection_version: null,
            },
        });
        expect(
            screen.getByText('Settings completeness: 100%'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            screen.getByText('Choose the support identity'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Enter an SMTP host.'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Choose the approved support mailbox.'),
        ).not.toBeInTheDocument();
    });

    it('announces saved only after the expected next version for the current actor', async () => {
        vi.mocked(axios.put).mockResolvedValue({ data: { data: saved(3) } });
        const callbacks = setup();
        goToReview();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save email settings' }),
        );
        await screen.findByText('Email settings saved');
        expect(callbacks.onSaved).toHaveBeenCalledWith(saved(3));
        expect(axios.put).toHaveBeenCalledWith(
            '/settings/email',
            expect.objectContaining({
                expected_version: 2,
                expected_actor_id: 17,
            }),
            expect.anything(),
        );
    });

    it('does not claim success for an unconfirmed save and requires a read before retrying', async () => {
        vi.mocked(axios.put).mockResolvedValue({ data: { data: saved(2) } });
        vi.mocked(axios.get).mockResolvedValue({ data: { data: saved(3) } });
        const callbacks = setup();
        goToReview();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save email settings' }),
        );
        await screen.findByText(/The save outcome is unknown/);
        expect(callbacks.onSaved).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Save email settings' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Read saved settings' }),
        );
        await screen.findByText('Current saved version');
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Keep my entries with this version',
            }),
        );
        expect(callbacks.onSaved).toHaveBeenCalledWith(saved(3));
        expect(
            screen.getByRole('button', { name: 'Save email settings' }),
        ).toBeEnabled();
    });

    it('shows the same conflict-review path and adopts its reviewed version', async () => {
        const current: EmailSettingsState = {
            ...saved(3),
            smtp_password_saved: true,
            settings: {
                ...saved(3).settings,
                provider: 'smtp',
                smtp_host: 'smtp.saved.example.test',
                smtp_port: 465,
                smtp_encryption: 'ssl',
                smtp_username: 'saved-mailer',
                from_address: 'saved-support@example.test',
                from_name: 'Saved Support',
            },
        };
        vi.mocked(axios.put)
            .mockRejectedValueOnce(error(409))
            .mockResolvedValueOnce({ data: { data: saved(4) } });
        vi.mocked(axios.get).mockResolvedValue({ data: { data: current } });
        setup();
        goToReview();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save email settings' }),
        );
        await screen.findByText(/Another edit changed the saved settings/);
        fireEvent.click(
            screen.getByRole('button', { name: 'Read saved settings' }),
        );
        await screen.findByText('Current saved version');
        expect(
            screen.getByText('Sender name: Saved Support'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Sender and reply address: saved-support@example.test',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Support mailbox: Google Workspace · support@example.test · Version 1 · Connected',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText('SMTP host / port: smtp.saved.example.test:465'),
        ).toBeInTheDocument();
        expect(screen.getByText('SMTP encryption: ssl')).toBeInTheDocument();
        expect(
            screen.getByText('SMTP username: saved-mailer'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Saved SMTP password: Present — value hidden'),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Keep my entries with this version',
            }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Save email settings' }),
        );
        await screen.findByText('Email settings saved');
        expect(vi.mocked(axios.put).mock.calls[1][1]).toEqual(
            expect.objectContaining({
                expected_version: 3,
                from_name: 'Support',
            }),
        );
    });

    it('hands a mismatched actor or revoked access back to the page so it can conceal the dialog', async () => {
        vi.mocked(axios.put).mockResolvedValue({
            data: { data: saved(3, 99) },
        });
        const callbacks = setup();
        goToReview();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save email settings' }),
        );
        await screen.findByRole('button', { name: 'Save email settings' });
        expect(callbacks.onAccessLost).toHaveBeenCalledTimes(1);
        expect(callbacks.onSaved).not.toHaveBeenCalled();
    });

    it('stops waiting without treating the save as failed or successful', async () => {
        vi.mocked(axios.put).mockImplementation(
            (_url, _body, options) =>
                new Promise((_resolve, reject) =>
                    options?.signal?.addEventListener?.('abort', () =>
                        reject(new Error('aborted')),
                    ),
                ),
        );
        const callbacks = setup();
        goToReview();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save email settings' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Stop waiting' }));
        await screen.findByText(/The save outcome is unknown/);
        expect(callbacks.onSaved).not.toHaveBeenCalled();
    });
});
