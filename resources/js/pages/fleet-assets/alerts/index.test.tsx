import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AlertResponseDialog, type FleetAlert } from './_dialogs';
vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn(), post: vi.fn(), visit: vi.fn() },
}));
const alert: FleetAlert = {
    can_open_control_room: true,
    id: 12,
    can_respond: true,
    reference: 'CR-12',
    source: 'fleet',
    alert_type: 'inspection_due',
    severity: 'high',
    status: 'triaging',
    triggered_at: null,
    acknowledged_at: null,
    resolved_at: null,
    updated_at: null,
    notes: null,
    site: null,
    asset: null,
    assigned_to: null,
};
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});
describe('Fleet alert response', () => {
    it('includes optional notes and the expected state of every selected alert in a bulk response', async () => {
        const request = vi
            .fn()
            .mockResolvedValue({
                ok: true,
                status: 200,
                json: async () => ({ updated: 2 }),
            });
        vi.stubGlobal('fetch', request);
        render(
            <AlertResponseDialog
                alerts={[
                    { ...alert, status: 'open' },
                    { ...alert, id: 13, reference: 'CR-13', status: 'open' },
                ]}
                action="acknowledge"
                onClose={vi.fn()}
                onSaved={vi.fn()}
                onRefresh={vi.fn()}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.change(screen.getByLabelText('Response notes (optional)'), {
            target: { value: 'Checking both vehicles' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Acknowledge' }));
        await screen.findByText('Response recorded');
        expect(request.mock.calls[0][0]).toBe(
            '/fleet-assets/alerts/bulk-action',
        );
        expect(JSON.parse(request.mock.calls[0][1].body)).toMatchObject({
            action: 'acknowledge',
            ids: [12, 13],
            notes: 'Checking both vehicles',
            expected_statuses: { 12: 'open', 13: 'open' },
        });
    });
    it('validates a skipped response step and retains notes when the source changed', async () => {
        const fetch = vi.fn().mockResolvedValue({
            ok: false,
            status: 409,
            json: async () => ({ message: 'This alert changed.' }),
        });
        vi.stubGlobal('fetch', fetch);
        const onClose = vi.fn();
        render(
            <AlertResponseDialog
                alerts={[alert]}
                action="resolve"
                onClose={onClose}
                onSaved={vi.fn()}
                onRefresh={vi.fn()}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: /Review.*Confirm the response/i,
            }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Resolve with notes' }),
        );
        expect(screen.getByLabelText('Resolution notes')).toHaveAttribute(
            'aria-invalid',
            'true',
        );
        expect(fetch).not.toHaveBeenCalled();
        fireEvent.change(screen.getByLabelText('Resolution notes'), {
            target: { value: 'Vehicle checked; no remaining alert response.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Resolve with notes' }),
        );
        await waitFor(() =>
            expect(screen.getByText('This alert changed.')).toBeVisible(),
        );
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({
            expected_status: 'triaging',
            resolution_notes: 'Vehicle checked; no remaining alert response.',
        });
        expect(
            screen.getByText('Vehicle checked; no remaining alert response.'),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.getByRole('alertdialog')).toHaveTextContent(
            'Discard response draft?',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Continue editing' }),
        );
        expect(onClose).not.toHaveBeenCalled();
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => ({ ...alert, status: 'confirmed' }),
        });
        fireEvent.click(screen.getByRole('button', { name: 'Review latest' }));
        await waitFor(() =>
            expect(screen.getByText(/Latest state loaded/)).toBeVisible(),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.getByLabelText('Resolution notes')).toHaveValue(
            'Vehicle checked; no remaining alert response.',
        );
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => ({ id: alert.id, status: 'resolved' }),
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Resolve with notes' }),
        );
        await waitFor(() =>
            expect(screen.getByText('Response recorded')).toBeVisible(),
        );
        expect(JSON.parse(fetch.mock.calls[2][1].body)).toMatchObject({
            expected_status: 'confirmed',
            resolution_notes: 'Vehicle checked; no remaining alert response.',
        });
    });
});
