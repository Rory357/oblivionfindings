import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import { webcrypto } from 'node:crypto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { maintenanceClockPreview } from './maintenance-clock-preview';
import {
    maintenancePreview,
    maintenanceWorkspace,
} from './maintenance-clock-test-fixtures';
import { maintenanceCommandFixture } from './maintenance-command-test-fixtures';
import { MonitoringPolicySettings } from './monitoring-policy-settings';
vi.mock('axios');
vi.mock('@inertiajs/react', () => ({ router: { reload: vi.fn() } }));
beforeEach(() => {
    sessionStorage.clear();
    if (!Element.prototype.scrollIntoView)
        Element.prototype.scrollIntoView = vi.fn();
    vi.stubGlobal('crypto', webcrypto);
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.patch).mockReset();
    vi.mocked(axios.isAxiosError).mockReset();
});
afterEach(() => cleanup());
async function choose(label: string, value: string | RegExp) {
    fireEvent.keyDown(screen.getByRole('combobox', { name: label }), {
        key: 'Enter',
    });
    fireEvent.click(await screen.findByRole('option', { name: value }));
}
async function plan(start = '2026-09-26T09:00', end = '2026-09-26T10:00') {
    fireEvent.click(screen.getByRole('tab', { name: 'Maintenance' }));
    fireEvent.click(
        await screen.findByRole('button', {
            name: 'Schedule window',
        }),
    );
    await waitFor(() =>
        expect(screen.getByRole('dialog')).toContainElement(
            document.activeElement as HTMLElement,
        ),
    );
    fireEvent.change(screen.getByLabelText('Window name', { exact: true }), {
        target: { value: 'Synthetic network maintenance' },
    });
    fireEvent.change(screen.getByLabelText('Starts', { exact: true }), {
        target: { value: start },
    });
    fireEvent.change(screen.getByLabelText('Ends', { exact: true }), {
        target: { value: end },
    });
    fireEvent.change(screen.getByLabelText('Maintenance reason'), {
        target: { value: 'Review a synthetic technical maintenance schedule.' },
    });
}
describe('maintenance clock review', () => {
    it('keeps an edited draft on close until discard is explicitly confirmed', async () => {
        render(<MonitoringPolicySettings workspace={maintenanceWorkspace()} />);
        await plan();
        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        await screen.findByRole('alertdialog', {
            name: 'Discard this settings draft?',
        });
        fireEvent.click(screen.getByRole('button', { name: 'Keep reviewing' }));
        expect(screen.getByLabelText('Maintenance reason')).toHaveValue(
            'Review a synthetic technical maintenance schedule.',
        );
        expect(axios.post).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        await screen.findByRole('alertdialog', {
            name: 'Discard this settings draft?',
        });
        fireEvent.click(screen.getByRole('button', { name: 'Discard draft' }));
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('previews exact NZ instants before one reviewed create', async () => {
        vi.mocked(axios.post).mockImplementation(async (url, body) =>
            url.endsWith('/preview')
                ? {
                      data: {
                          preview: maintenancePreview(
                              body as Record<string, unknown>,
                          ),
                      },
                  }
                : {
                      data: await maintenanceCommandFixture(
                          body as Record<string, unknown>,
                      ),
                  },
        );
        render(<MonitoringPolicySettings workspace={maintenanceWorkspace()} />);
        await plan();
        fireEvent.click(screen.getByRole('button', { name: 'Preview times' }));
        await screen.findByRole('button', { name: 'Save maintenance window' });
        expect(vi.mocked(axios.post).mock.calls).toHaveLength(1);
        const body = vi.mocked(axios.post).mock.calls[0][1];
        expect(body).toMatchObject({
            starts_at: '2026-09-26T09:00:00+12:00',
            ends_at: '2026-09-26T10:00:00+12:00',
            expected_actor_id: 7,
            dst_overlap: 'reject',
            dst_gap: 'reject',
        });
        expect(body).not.toHaveProperty('starts_at_offset');
        fireEvent.click(
            screen.getByRole('button', { name: 'Save maintenance window' }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(vi.mocked(axios.post).mock.calls).toHaveLength(2);
        expect(vi.mocked(axios.post).mock.calls[1][1]).toMatchObject(
            body as Record<string, unknown>,
        );
        expect(vi.mocked(axios.post).mock.calls[1][1]).toHaveProperty(
            'request_uuid',
            expect.stringMatching(/^[0-9a-f-]{36}$/),
        );
    });
    it('offers both repeated-hour choices and retains the selected offset', async () => {
        vi.mocked(axios.post).mockImplementation(async (_url, body) => ({
            data: {
                preview: maintenancePreview(body as Record<string, unknown>),
            },
        }));
        render(<MonitoringPolicySettings workspace={maintenanceWorkspace()} />);
        await plan('2026-04-05T02:15', '2026-04-05T02:45');
        expect(
            screen.getByLabelText('Starts clock occurrence'),
        ).toBeInTheDocument();
        await choose('Starts clock occurrence', /\+12:00/);
        await choose('Ends clock occurrence', /\+12:00/);
        fireEvent.click(screen.getByRole('button', { name: 'Preview times' }));
        await screen.findByRole('button', { name: 'Save maintenance window' });
        expect(vi.mocked(axios.post).mock.calls[0][1]).toMatchObject({
            starts_at: '2026-04-05T02:15:00+12:00',
            ends_at: '2026-04-05T02:45:00+12:00',
        });
    });
    it('rejects a response for another actor or timezone before review', async () => {
        vi.mocked(axios.post).mockImplementation(async (_url, body) => ({
            data: {
                preview: {
                    ...maintenancePreview(body as Record<string, unknown>),
                    actor_id: 8,
                },
            },
        }));
        render(<MonitoringPolicySettings workspace={maintenanceWorkspace()} />);
        await plan();
        fireEvent.click(screen.getByRole('button', { name: 'Preview times' }));
        await screen.findByText(/preview could not be verified/);
        expect(
            screen.queryByRole('button', { name: 'Save maintenance window' }),
        ).not.toBeInTheDocument();
        expect(vi.mocked(axios.post).mock.calls).toHaveLength(1);
    });
    it('clears account-private draft and ignores an old preview after an actor switch', async () => {
        let finish!: (value: unknown) => void;
        vi.mocked(axios.post).mockImplementation(
            async () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const w = maintenanceWorkspace();
        const { rerender } = render(<MonitoringPolicySettings workspace={w} />);
        await plan();
        fireEvent.click(screen.getByRole('button', { name: 'Preview times' }));
        await waitFor(() => expect(vi.mocked(axios.post)).toHaveBeenCalled());
        const body = vi.mocked(axios.post).mock.calls[0][1];
        rerender(
            <MonitoringPolicySettings workspace={{ ...w, actor_id: 8 }} />,
        );
        finish({
            data: {
                preview: maintenancePreview(body as Record<string, unknown>),
            },
        });
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(
            screen.queryByText('Synthetic network maintenance'),
        ).not.toBeInTheDocument();
    });
    it('rejects broadened preview metadata and a changed end instant', () => {
        const payload = {
            expected_actor_id: 7,
            site_id: 14,
            monitor_id: null,
            device_id: null,
            timezone: 'Pacific/Auckland',
            dst_overlap: 'reject',
            dst_gap: 'reject',
            starts_at: '2026-09-26T09:00:00+12:00',
            ends_at: '2026-09-26T10:00:00+12:00',
        };
        const p = maintenancePreview(payload);
        expect(maintenanceClockPreview(p, 7, payload)).toEqual(p);
        expect(() =>
            maintenanceClockPreview({ ...p, commits_change: true }, 7, payload),
        ).toThrow();
        p.occurrences[0].ends_at = '2026-09-25T23:00:00+00:00';
        expect(() => maintenanceClockPreview(p, 7, payload)).toThrow();
    });
    it('prefills a saved UTC interval in its selected zone and preserves the exact stored seconds on edit', async () => {
        vi.mocked(axios.post).mockImplementation(async (_url, body) => ({
            data: {
                preview: maintenancePreview(body as Record<string, unknown>),
            },
        }));
        vi.mocked(axios.patch).mockImplementation(async (_url, body) => ({
            data: await maintenanceCommandFixture(
                body as Record<string, unknown>,
                'update',
                21,
            ),
        }));
        const w = maintenanceWorkspace();
        w.maintenance = [
            {
                id: 21,
                version: 3,
                state: 'active',
                status: 'active',
                site_id: 14,
                site_name: 'Synthetic Clock Site',
                device_id: null,
                device_name: null,
                monitor_id: null,
                monitor_name: null,
                name: 'Stored UTC window',
                starts_at: '2026-04-04T14:15:17+00:00',
                ends_at: '2026-04-04T14:45:17+00:00',
                recurrence: null,
                recurrence_until: null,
                timezone: 'Pacific/Auckland',
                dst_overlap: 'later',
                dst_gap: 'reject',
                reason: 'Retain the reviewed clock and exact stored seconds.',
            },
        ];
        render(<MonitoringPolicySettings workspace={w} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Maintenance' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Edit' }));
        expect(screen.getByLabelText('Starts', { exact: true })).toHaveValue(
            '2026-04-05T02:15',
        );
        expect(
            screen.getByLabelText('Starts clock occurrence'),
        ).toHaveTextContent('+12:00');
        fireEvent.click(screen.getByRole('button', { name: 'Preview times' }));
        await screen.findByRole('button', { name: 'Save maintenance window' });
        expect(vi.mocked(axios.post).mock.calls[0][1]).toMatchObject({
            starts_at: '2026-04-04T14:15:17+00:00',
            ends_at: '2026-04-04T14:45:17+00:00',
        });
        expect(vi.mocked(axios.post).mock.calls[0][1]).not.toHaveProperty(
            'version',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Save maintenance window' }),
        );
        await waitFor(() => expect(axios.patch).toHaveBeenCalled());
        expect(vi.mocked(axios.patch).mock.calls[0][1]).toMatchObject({
            version: 3,
            starts_at: '2026-04-04T14:15:17+00:00',
        });
    });
    it('retains the draft and prevents another save when the saved identity cannot be confirmed', async () => {
        vi.mocked(axios.post).mockImplementation(async (url, body) =>
            url.endsWith('/preview')
                ? {
                      data: {
                          preview: maintenancePreview(
                              body as Record<string, unknown>,
                          ),
                      },
                  }
                : { data: '<html>Unconfirmed response</html>' },
        );
        render(<MonitoringPolicySettings workspace={maintenanceWorkspace()} />);
        await plan();
        fireEvent.click(screen.getByRole('button', { name: 'Preview times' }));
        await screen.findByRole('button', { name: 'Save maintenance window' });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save maintenance window' }),
        );
        await screen.findByText(/save could not be confirmed/);
        expect(
            screen.getByRole('button', { name: 'Save maintenance window' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save maintenance window' }),
        );
        expect(vi.mocked(axios.post).mock.calls).toHaveLength(2);
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        expect(
            screen.getByLabelText('Window name', { exact: true }),
        ).toHaveValue('Synthetic network maintenance');
        expect(
            screen.getByRole('button', { name: 'Preview times' }),
        ).toBeDisabled();
    });
    it('aborts a pending preview when the scope changes and ignores its late response', async () => {
        let finish!: (value: unknown) => void;
        vi.mocked(axios.post).mockImplementation(
            async () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const w = maintenanceWorkspace();
        w.sites.push({ id: 15, name: 'Second approved Site' });
        render(<MonitoringPolicySettings workspace={w} />);
        await plan();
        fireEvent.click(screen.getByRole('button', { name: 'Preview times' }));
        await waitFor(() => expect(axios.post).toHaveBeenCalled());
        const call = vi.mocked(axios.post).mock.calls[0];
        await choose('Site', 'Second approved Site');
        expect(call[2]?.signal?.aborted).toBe(true);
        finish({
            data: {
                preview: maintenancePreview(call[1] as Record<string, unknown>),
            },
        });
        await waitFor(() =>
            expect(
                screen.getByLabelText('Site', { exact: true }),
            ).toHaveTextContent('Second approved Site'),
        );
        expect(
            screen.queryByRole('button', { name: 'Save maintenance window' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Preview times' }),
        ).toBeEnabled();
    });
    it('shows validation errors for recurrence and reason without clearing the plan', async () => {
        vi.mocked(axios.isAxiosError).mockReturnValue(true);
        vi.mocked(axios.post).mockRejectedValue({
            isAxiosError: true,
            response: {
                status: 422,
                data: {
                    errors: {
                        recurrence: ['Choose a supported recurrence.'],
                        reason: ['Explain the planned technical activity.'],
                    },
                },
            },
        });
        render(<MonitoringPolicySettings workspace={maintenanceWorkspace()} />);
        await plan();
        fireEvent.click(screen.getByRole('button', { name: 'Preview times' }));
        await screen.findByText('Choose a supported recurrence.');
        expect(
            screen.getByText('Explain the planned technical activity.'),
        ).toBeInTheDocument();
        expect(
            screen.getByLabelText('Window name', { exact: true }),
        ).toHaveValue('Synthetic network maintenance');
        expect(
            screen.queryByRole('button', { name: 'Save maintenance window' }),
        ).not.toBeInTheDocument();
    });
});
