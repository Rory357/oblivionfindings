import { router } from '@inertiajs/react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import MarGovernanceDialogs, {
    ManageAlertsDialog,
} from './mar-governance-dialogs';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
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
        const post = vi.spyOn(router, 'post').mockImplementation(() => {});
        render(
            <MarGovernanceDialogs
                modal="inr"
                onClose={vi.fn()}
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
        fireEvent.change(screen.getByLabelText(/Tested on/), {
            target: { value: '2026-10-04' },
        });
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
    });
});
