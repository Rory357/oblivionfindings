import { router } from '@inertiajs/react';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import MarGovernanceDialogs, {
    ManageAlertsDialog,
} from './mar-governance-dialogs';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.useRealTimers();
});

describe('INR recording review', () => {
    it('keeps alert and reminder actions in their respective sections', () => {
        const post = vi.spyOn(router, 'post').mockImplementation(() => {});
        render(
            <ManageAlertsDialog
                clientId={42}
                onClose={vi.fn()}
                suppression={{ suppressed: false, reason: null }}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Add alert' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Save setting' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: /Reminders.*Medication-admin alerts/,
            }),
        );
        expect(
            screen.queryByRole('button', { name: 'Add alert' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Save setting' }));
        expect(post).toHaveBeenCalledWith(
            '/emar/clients/42/alert-suppression',
            expect.objectContaining({ suppress_med_admin_alerts: false }),
            expect.any(Object),
        );
    });
    it('retains supplied details across steps and sends the existing command only after review', () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-10-04T00:00:00Z'));
        const post = vi.spyOn(router, 'post').mockImplementation(() => {});
        const onClose = vi.fn();
        render(
            <MarGovernanceDialogs
                modal="inr"
                onClose={onClose}
                clientId={42}
                attentionAlerts={[]}
                awaitingVerification={[]}
                corrections={[]}
                witnesses={[]}
                medications={[]}
                suppression={{ suppressed: false, reason: null }}
            />,
        );
        fireEvent.change(screen.getByLabelText(/INR value/), {
            target: { value: '2.4' },
        });
        fireEvent.click(screen.getByRole('button', { name: /Tested on:/ }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Sun 4 October 2026' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Use date' }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(post).not.toHaveBeenCalled();
        fireEvent.change(screen.getByLabelText(/Recorded instruction/), {
            target: { value: 'Instruction supplied with synthetic result' },
        });
        fireEvent.change(screen.getByLabelText(/Instruction source/), {
            target: { value: 'Synthetic clinician, 4 October' },
        });
        fireEvent.change(
            screen.getByLabelText(/Why this result is not linked/),
            { target: { value: 'Medicine link awaiting review' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(post).not.toHaveBeenCalled();
        expect(
            screen.getByText('Instruction supplied with synthetic result'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        expect(screen.getByLabelText(/Instruction source/)).toHaveValue(
            'Synthetic clinician, 4 October',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Record INR' }));
        expect(post).toHaveBeenCalledWith(
            '/emar/clients/42/inr',
            expect.objectContaining({
                inr_value: '2.4',
                tested_on: '2026-10-04',
                instruction_source: 'Synthetic clinician, 4 October',
                request_uuid: expect.any(String),
            }),
            expect.any(Object),
        );
        expect(onClose).not.toHaveBeenCalled();
        act(() => {
            post.mock.calls[0][2]?.onSuccess?.({} as never);
        });
        expect(screen.getByText('INR recorded')).toBeInTheDocument();
        expect(onClose).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        expect(onClose).toHaveBeenCalledOnce();
    });
});
