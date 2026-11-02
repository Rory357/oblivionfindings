import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { BulkActionConfirm, bulkResultLines, confirmIdentityHref, takeBulkResume, type BulkAction } from './bulk-action-popups';

vi.mock('axios', async () => {
    const actual = await vi.importActual<typeof import('axios')>('axios');
    return { default: { ...actual.default, get: vi.fn(), post: vi.fn(), isAxiosError: actual.default.isAxiosError } };
});

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return { ...actual, router: { ...actual.router, visit: vi.fn(), reload: vi.fn() } };
});

const action = (overrides: Partial<BulkAction> = {}): BulkAction => ({
    key: 'access.door.unlock_timed',
    workspace: 'security',
    label: 'Unlock door for a short time',
    risk: 'medium',
    impact: 'Each door unlocks for the time you choose, then locks again.',
    expected: 'The door reports it is locked again.',
    needs_approval: false,
    needs_change: false,
    elevated: false,
    phrase: 'BULK 3 DEVICES',
    parameters: [],
    ready: 2,
    devices: [
        { id: 11, name: 'North service door', site: 'Synthetic House', ready: true, reason: null, changes: [] },
        { id: 12, name: 'South service door', site: 'Synthetic House', ready: true, reason: null, changes: [] },
        { id: 13, name: 'Garage door', site: 'Synthetic House', ready: false, reason: 'Oblivion can’t send this to it yet.', changes: [] },
    ],
    ...overrides,
});

const batchReply = {
    id: 7,
    created: true,
    summary: { included: 2, excluded: 1, ready: 2, awaitingApproval: 0 },
    targets: [
        { device: { id: 11, name: 'North service door' }, included: true, status: 'ready', reason: null },
        { device: { id: 12, name: 'South service door' }, included: true, status: 'ready', reason: null },
        { device: { id: 13, name: 'Garage door' }, included: false, status: null, reason: 'No approved execution and reconciliation adapter is currently available.' },
    ],
};

describe('Devices bulk bar: run a governed action (S&D rebuild C6)', () => {
    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('says how many devices and which, then starts one batch and sends the ready ones', async () => {
        vi.mocked(axios.post).mockResolvedValueOnce({ data: batchReply }).mockResolvedValueOnce({ data: { processed: 2, skipped: 0 } });
        const onDone = vi.fn();
        render(<BulkActionConfirm open action={action()} ticked={[11, 12, 13]} stepUpCurrent={false} onClose={vi.fn()} onDone={onDone} />);

        expect(screen.getByText('Run “Unlock door for a short time” on 3 devices?')).toBeTruthy();
        expect(screen.getByText('North service door')).toBeTruthy();
        expect(screen.getByText('Garage door')).toBeTruthy();
        expect(screen.getByText('Oblivion can’t send this to it yet.')).toBeTruthy();
        expect(screen.getAllByText('Left out').length).toBeGreaterThan(0);
        expect(screen.queryByText(/Confirm it’s you/)).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Run on 2 devices' }));
        expect(await screen.findByText('Say why in at least 10 characters.')).toBeTruthy();
        expect(axios.post).not.toHaveBeenCalled();

        fireEvent.change(screen.getByLabelText(/Why/), { target: { value: 'Contractors need both service doors for the lift repair.' } });
        fireEvent.click(screen.getByRole('button', { name: 'Run on 2 devices' }));

        await waitFor(() => expect(onDone).toHaveBeenCalledTimes(1));
        const [url, body] = vi.mocked(axios.post).mock.calls[0];
        expect(url).toBe('/security-devices/command-batches');
        expect(body).toMatchObject({
            workspace: 'security',
            device_ids: [11, 12, 13],
            capability: 'access.door.unlock_timed',
            parameters: {},
            reason: 'Contractors need both service doors for the lift repair.',
            it_change_ids: {},
            impact_acknowledged: false,
            confirmation_text: null,
        });
        expect((body as { idempotency_key: string }).idempotency_key).toMatch(/^bulk_devices_[A-Za-z0-9:_-]+$/);
        expect(vi.mocked(axios.post).mock.calls[1][0]).toBe('/security-devices/command-batches/7/dispatch');
        expect(onDone.mock.calls[0][0]).toMatchObject({ label: 'Unlock door for a short time', sent: 2, waitingApproval: 0, leftOut: [{ name: 'Garage door' }] });
    });

    it('keeps the elevated checks: identity first, then the combined impact and the typed count', async () => {
        const elevated = action({ elevated: true, risk: 'high', needs_approval: true });
        const { rerender } = render(<BulkActionConfirm open action={elevated} ticked={[11, 12, 13]} stepUpCurrent={false} onClose={vi.fn()} onDone={vi.fn()} />);

        expect(screen.getByText(/Confirm it’s you first/)).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Ask to run on 2 devices' }) as HTMLButtonElement).disabled).toBe(true);

        rerender(<BulkActionConfirm open action={elevated} ticked={[11, 12, 13]} stepUpCurrent onClose={vi.fn()} onDone={vi.fn()} />);
        vi.mocked(axios.post).mockResolvedValueOnce({ data: { ...batchReply, summary: { included: 2, excluded: 1, ready: 0, awaitingApproval: 2 } } });
        fireEvent.change(screen.getByLabelText(/Why/), { target: { value: 'Both doors stay open for the fire drill walk-through.' } });
        fireEvent.click(screen.getByRole('button', { name: 'Ask to run on 2 devices' }));
        expect(await screen.findByText('Type BULK 3 DEVICES exactly.')).toBeTruthy();
        expect(axios.post).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('checkbox'));
        fireEvent.change(screen.getByLabelText(/Type BULK 3 DEVICES to confirm/), { target: { value: 'BULK 3 DEVICES' } });
        fireEvent.click(screen.getByRole('button', { name: 'Ask to run on 2 devices' }));

        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
        expect(vi.mocked(axios.post).mock.calls[0][1]).toMatchObject({ impact_acknowledged: true, confirmation_text: 'BULK 3 DEVICES' });
    });

    it('shows the server’s refusal and keeps everything entered', async () => {
        const refusal = Object.assign(new Error('Unprocessable'), {
            isAxiosError: true,
            response: { status: 422, data: { errors: { confirmation_text: ['Recent identity confirmation is required for this bulk action.'] } } },
        });
        vi.mocked(axios.post).mockRejectedValueOnce(refusal);
        render(<BulkActionConfirm open action={action()} ticked={[11, 12, 13]} stepUpCurrent={false} onClose={vi.fn()} onDone={vi.fn()} />);
        fireEvent.change(screen.getByLabelText(/Why/), { target: { value: 'Contractors need both service doors today.' } });
        fireEvent.click(screen.getByRole('button', { name: 'Run on 2 devices' }));

        expect(await screen.findByText('Recent identity confirmation is required for this bulk action.')).toBeTruthy();
        expect((screen.getByLabelText(/Why/) as HTMLTextAreaElement).value).toBe('Contractors need both service doors today.');
    });

    it('words the result and the way back from “Confirm it’s you”', () => {
        expect(bulkResultLines({ label: 'Restart device', sent: 2, waitingApproval: 1, waitingOther: 0, leftOut: [{ name: 'Hall switch', reason: 'Its maker doesn’t offer this action for it.' }], sendUnconfirmed: false })).toEqual({
            done: 'Restart device: sent to 2 devices · 1 device waits for an approver. Each device’s logbook has the result.',
            warning: 'Left out: Hall switch (Its maker doesn’t offer this action for it).',
        });
        expect(confirmIdentityHref(action(), [11, 12, 13])).toBe(
            '/security-devices/command-batches/confirm-identity?workspace=security&return=devices&ids=11%2C12%2C13&action=access.door.unlock_timed',
        );

        window.history.replaceState(null, '', '/security-devices/devices?tab=network&bulk=11%2C12%2Cx&bulk_action=device.reboot');
        expect(takeBulkResume()).toEqual({ ids: [11, 12], action: 'device.reboot' });
        expect(window.location.search).toBe('?tab=network');
        expect(takeBulkResume()).toBeNull();
    });
});
