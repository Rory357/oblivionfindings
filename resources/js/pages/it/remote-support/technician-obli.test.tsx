import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    TechnicianObliError,
    diagnosticPreview,
    technicianObliJSON,
} from './obli-transport';
import { TechnicianObli } from './technician-obli';
vi.mock('@inertiajs/react', () => ({ router: { on: () => () => undefined } }));
vi.mock('./obli-transport', async (importOriginal) => ({
    ...(await importOriginal<typeof import('./obli-transport')>()),
    technicianObliJSON: vi.fn(),
}));
const scope = { actorId: 9001, deviceId: 9100, ticketId: 7 };
const options = {
    actor_id: 9001,
    device_id: 9100,
    ticket_id: 7,
    version: 2,
    enabled: true,
    provider: 'openai',
    model: 'test-model',
    chat_retention_days: 7,
    diagnostic_categories: ['system', 'services'],
    mode: 'read_suggest',
    can_preview: true,
    can_assist: true,
};
function preview() {
    return {
        actor_id: 9001,
        device_id: 9100,
        ticket_id: 7,
        snapshot_version: 'a'.repeat(64),
        observed_at: new Date().toISOString(),
        received_at: new Date().toISOString(),
        fresh_until: new Date(Date.now() + 120000).toISOString(),
        groups: ['system', 'services'],
        snapshot: {
            schema: 1,
            observed_at: new Date().toISOString(),
            system: {
                windows_version: '10.0.26100',
                architecture: 'amd64',
                memory_total_bytes: 17179869184,
                memory_available_bytes: 8589934592,
                uptime_seconds: 3600,
                cpu_percent: 4.5,
                disks: [
                    {
                        name: 'C:',
                        total_bytes: 536870912000,
                        free_bytes: 268435456000,
                    },
                ],
            },
            services: [{ name: 'print_spooler', state: 'stopped' }],
        },
        completeness: {
            system: { complete: true, missing_fields: [] },
            services: { complete: false },
        },
    };
}
function observation() {
    const v = preview();
    v.snapshot.observed_at = v.observed_at;
    return v;
}
const api = vi.mocked(technicianObliJSON);
beforeEach(() => {
    vi.resetAllMocks();
    api.mockResolvedValue(options);
});
afterEach(cleanup);
async function open() {
    fireEvent.click(screen.getByRole('button', { name: 'Open Obli' }));
    await screen.findByLabelText('Ask about this computer');
}
function compose(text = 'The printer is offline') {
    fireEvent.change(screen.getByLabelText('Ask about this computer'), {
        target: { value: text },
    });
    fireEvent.click(
        screen.getByRole('checkbox', { name: /Send this question/ }),
    );
}
function send() {
    fireEvent.click(screen.getByRole('button', { name: 'Ask Obli' }));
}

describe('technician Obli privacy and recovery', () => {
    it('does not call the provider or read observations on page open; requires reviewed consent', async () => {
        render(
            <TechnicianObli
                scope={scope}
                computerName="Reception PC"
                available
            />,
        );
        expect(api).not.toHaveBeenCalled();
        await open();
        fireEvent.change(screen.getByLabelText('Ask about this computer'), {
            target: { value: 'Help' },
        });
        expect(screen.getByRole('button', { name: 'Ask Obli' })).toBeDisabled();
        expect(api.mock.calls.map((call) => call[1])).toEqual(['options']);
    });
    it('sends source references only, and retains completed sources as historical references', async () => {
        api.mockImplementation(async (_scope, action, _signal, command) => {
            if (action === 'options') return options;
            if (action === 'diagnostics') return observation();
            return {
                request_uuid: command!.request_uuid,
                status: 'completed',
                text: 'Check the printer queue.',
            };
        });
        render(
            <TechnicianObli
                scope={scope}
                computerName="Reception PC"
                available
            />,
        );
        await open();
        fireEvent.click(
            screen.getByRole('button', { name: 'Preview computer checks' }),
        );
        await screen.findByLabelText('Include system health');
        fireEvent.click(screen.getByLabelText('Include system health'));
        compose();
        send();
        await screen.findByText('Check the printer queue.');
        const first = api.mock.calls.find((call) => call[1] === 'chat')![3]!;
        expect(first.messages[0].computer_diagnostics).toEqual({
            snapshot_version: 'a'.repeat(64),
            groups: ['system'],
        });
        expect(JSON.stringify(first)).not.toContain('memory_total_bytes');
        compose('What should I check next?');
        send();
        await waitFor(() =>
            expect(
                api.mock.calls.filter((call) => call[1] === 'chat'),
            ).toHaveLength(2),
        );
        const next = api.mock.calls.filter((call) => call[1] === 'chat')[1][3]!;
        expect(next.messages[0].computer_diagnostics?.source_request_uuid).toBe(
            first.request_uuid,
        );
    });
    it('uses GET after a lost response and preserves the original request for retry', async () => {
        api.mockResolvedValueOnce(options)
            .mockRejectedValueOnce(Error('Lost response'))
            .mockResolvedValueOnce({
                request_uuid: 'unused',
                status: 'pending',
                text: null,
            });
        render(
            <TechnicianObli
                scope={scope}
                computerName="Reception PC"
                available
            />,
        );
        await open();
        compose();
        send();
        await screen.findByRole('button', { name: 'Check reply' });
        const first = api.mock.calls[1][3]!;
        api.mockResolvedValueOnce({
            request_uuid: first.request_uuid,
            status: 'completed',
            text: 'Recovered reply',
        });
        fireEvent.click(screen.getByRole('button', { name: 'Check reply' }));
        await waitFor(() =>
            expect(api.mock.calls[2][1]).toBe('chat/' + first.request_uuid),
        );
        expect(api.mock.calls[2][3]).toBeUndefined();
        await screen.findByRole('button', { name: 'Retry same request' });
        api.mockResolvedValueOnce({
            request_uuid: first.request_uuid,
            status: 'completed',
            text: 'Recovered reply',
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry same request' }),
        );
        await screen.findByText('Recovered reply');
        expect(api.mock.calls[3][3]).toEqual(first);
    });
    it('clears private content on scope change and ignores late replies', async () => {
        let resolve: (value: unknown) => void = () => undefined;
        api.mockResolvedValueOnce(options).mockImplementationOnce(
            () =>
                new Promise((r) => {
                    resolve = r;
                }),
        );
        const view = render(
            <TechnicianObli
                scope={scope}
                computerName="Reception PC"
                available
            />,
        );
        await open();
        compose('Private question');
        send();
        const command = api.mock.calls[1][3]!;
        view.rerender(
            <TechnicianObli
                scope={{ ...scope, deviceId: 9200 }}
                computerName="Office PC"
                available
            />,
        );
        await act(async () =>
            resolve({
                request_uuid: command.request_uuid,
                status: 'completed',
                text: 'Private reply',
            }),
        );
        expect(screen.queryByText('Private reply')).not.toBeInTheDocument();
        expect(
            screen.queryByDisplayValue('Private question'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Open Obli' }),
        ).toBeInTheDocument();
    });
    it('requires fresh preview and fresh consent after source_changed', async () => {
        api.mockResolvedValueOnce(options)
            .mockResolvedValueOnce(observation())
            .mockRejectedValueOnce(
                new TechnicianObliError(409, 'source_changed'),
            );
        render(
            <TechnicianObli
                scope={scope}
                computerName="Reception PC"
                available
            />,
        );
        await open();
        fireEvent.click(
            screen.getByRole('button', { name: 'Preview computer checks' }),
        );
        await screen.findByLabelText('Include system health');
        fireEvent.click(screen.getByLabelText('Include system health'));
        compose();
        send();
        await screen.findByText(/computer checks changed/);
        expect(
            screen.queryByLabelText('Include system health'),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Ask Obli' })).toBeDisabled();
        expect(screen.getByLabelText('Ask about this computer')).toHaveValue(
            'The printer is offline',
        );
    });
    it('does not mistake post-provider context_busy for permission to submit a new question', async () => {
        api.mockResolvedValueOnce(options).mockRejectedValueOnce(
            new TechnicianObliError(503, 'context_busy'),
        );
        render(
            <TechnicianObli
                scope={scope}
                computerName="Reception PC"
                available
            />,
        );
        await open();
        compose();
        send();
        await screen.findByRole('button', { name: 'Check reply' });
        expect(screen.getByLabelText('Ask about this computer')).toBeDisabled();
        expect(
            screen.queryByRole('button', { name: 'Ask Obli' }),
        ).not.toBeInTheDocument();
    });
    it('purges source-bearing retries when current authority is denied', async () => {
        api.mockResolvedValueOnce(options).mockRejectedValueOnce(
            new TechnicianObliError(403, 'source_unavailable'),
        );
        render(
            <TechnicianObli
                scope={scope}
                computerName="Reception PC"
                available
            />,
        );
        await open();
        compose('Private question');
        send();
        await screen.findByText(/Private content has been cleared/);
        expect(
            screen.queryByDisplayValue('Private question'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Retry same request' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Check reply' }),
        ).toBeInTheDocument();
    });
    it('allows authorised read-only diagnostics while AI chat is disabled', async () => {
        api.mockResolvedValueOnce({
            ...options,
            enabled: false,
            can_assist: false,
        }).mockResolvedValueOnce(observation());
        render(
            <TechnicianObli
                scope={scope}
                computerName="Reception PC"
                available
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Open Obli' }));
        await screen.findByRole('button', { name: 'Preview computer checks' });
        fireEvent.click(
            screen.getByRole('button', { name: 'Preview computer checks' }),
        );
        await screen.findByLabelText('Include system health');
        expect(screen.getByLabelText('Include system health')).toBeDisabled();
        expect(
            screen.queryByLabelText('Ask about this computer'),
        ).not.toBeInTheDocument();
    });
    it('rejects mismatched scopes and unexpected diagnostic content', () => {
        expect(() =>
            diagnosticPreview({ ...observation(), device_id: 1 }, scope),
        ).toThrow();
        const v = observation();
        Object.assign(v.snapshot.system, {
            username: 'should never be present',
        });
        expect(() => diagnosticPreview(v, scope)).toThrow();
        const duplicate = observation();
        duplicate.snapshot.services.push({ ...duplicate.snapshot.services[0] });
        expect(() => diagnosticPreview(duplicate, scope)).toThrow();
    });
    it('expires reviewed observations before a new question can attach them', async () => {
        const value = observation();
        value.fresh_until = new Date(Date.now() - 1000).toISOString();
        api.mockResolvedValueOnce(options).mockResolvedValueOnce(value);
        render(
            <TechnicianObli
                scope={scope}
                computerName="Reception PC"
                available
            />,
        );
        await open();
        fireEvent.click(
            screen.getByRole('button', { name: 'Preview computer checks' }),
        );
        await screen.findByText(/checks have expired/);
        expect(screen.getByLabelText('Include system health')).toBeDisabled();
    });
    it('stops waiting without discarding an uncertain request', async () => {
        api.mockResolvedValueOnce(options).mockImplementationOnce(
            async (_scope, _action, signal) =>
                new Promise((_resolve, reject) =>
                    signal.addEventListener(
                        'abort',
                        () => reject(new DOMException('Aborted', 'AbortError')),
                        { once: true },
                    ),
                ),
        );
        render(
            <TechnicianObli
                scope={scope}
                computerName="Reception PC"
                available
            />,
        );
        await open();
        compose();
        send();
        fireEvent.click(
            await screen.findByRole('button', { name: 'Stop waiting' }),
        );
        await screen.findByRole('button', { name: 'Check reply' });
        expect(
            api.mock.calls.filter((call) => call[1] === 'chat'),
        ).toHaveLength(1);
        expect(screen.getByLabelText('Ask about this computer')).toBeDisabled();
    });
    it('displays an authorised recovered answer without rebuilding cleared private history', async () => {
        api.mockResolvedValueOnce(options).mockRejectedValueOnce(
            new TechnicianObliError(403, 'source_unavailable'),
        );
        render(
            <TechnicianObli
                scope={scope}
                computerName="Reception PC"
                available
            />,
        );
        await open();
        compose('Private question');
        send();
        await screen.findByText(/Private content has been cleared/);
        const command = api.mock.calls[1][3]!;
        api.mockResolvedValueOnce(options).mockResolvedValueOnce({
            request_uuid: command.request_uuid,
            status: 'completed',
            text: 'Recovered authorised answer',
        });
        fireEvent.click(screen.getByRole('button', { name: 'Open Obli' }));
        await screen.findByLabelText('Ask about this computer');
        fireEvent.click(screen.getByRole('button', { name: 'Check reply' }));
        await screen.findByText('Recovered authorised answer');
        expect(screen.queryByText('Private question')).not.toBeInTheDocument();
        expect(
            screen.queryByLabelText('Ask about this computer'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'Start a new conversation to ask another question.',
            ),
        ).toBeInTheDocument();
    });
});
