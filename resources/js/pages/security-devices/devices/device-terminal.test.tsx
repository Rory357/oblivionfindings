import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { TerminalTab } from './_device-terminal';
import type { TerminalTabData } from './_device-page-types';

const base: TerminalTabData = { ready: true, reason: null, hub: 'Synthetic House hub', host: '10.20.0.2', session: null, history: [] };

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

describe('Terminal tab (pg-sdr-3b, pop-up 60)', () => {
    it('says why it can’t open', () => {
        render(<TerminalTab deviceId={5} deviceName="Synthetic switch" terminal={{ ...base, ready: false, reason: 'Save a command-line login for this device first.' }} now={new Date()} />);

        expect(screen.getByText('Save a command-line login for this device first.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Open terminal' })).not.toBeInTheDocument();
    });

    it('says what happens first, then opens through the hub and sends each line typed', async () => {
        const post = vi.spyOn(axios, 'post').mockImplementation(async (url: string) =>
            url.endsWith('/terminal') ? { data: { session: '3f1e2d4c-5b6a-4789-9abc-def012345678', state: 'requested' } } : { data: { ok: true } },
        );
        vi.spyOn(axios, 'get').mockResolvedValue({ data: { state: 'open', reason: null, lines: [{ seq: 1, text: 'USW-24-PoE# ' }] } });
        render(<TerminalTab deviceId={5} deviceName="Synthetic switch" terminal={base} now={new Date()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Open terminal' }));
        expect(screen.getByText('Commands change the real device. A wrong one can take the house offline.')).toBeInTheDocument();
        expect(screen.getByText('By itself after 15 minutes without typing')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Open terminal tab' }));

        await waitFor(() => expect(screen.getByLabelText('Synthetic switch terminal')).toHaveTextContent('USW-24-PoE#'));
        expect(post).toHaveBeenCalledWith('/security-devices/devices/5/terminal');

        await waitFor(() => expect(screen.getByRole('textbox', { name: 'Command' })).not.toBeDisabled());
        fireEvent.change(screen.getByRole('textbox', { name: 'Command' }), { target: { value: 'info' } });
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        await waitFor(() => expect(post).toHaveBeenCalledWith('/security-devices/terminal/3f1e2d4c-5b6a-4789-9abc-def012345678/input', { line: 'info' }));

        fireEvent.click(screen.getByRole('button', { name: 'Ctrl+C' }));
        await waitFor(() => expect(post).toHaveBeenCalledWith('/security-devices/terminal/3f1e2d4c-5b6a-4789-9abc-def012345678/input', { interrupt: true }));
    });

    it('shows why a terminal closed, and the commands typed before', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: { state: 'closed', reason: 'idle', lines: [] } });
        render(
            <TerminalTab
                deviceId={5}
                deviceName="Synthetic switch"
                terminal={{ ...base, session: { id: '3f1e2d4c-5b6a-4789-9abc-def012345678', state: 'open' }, history: [{ id: 'x', who: 'Synthetic Admin', at: null, reason: 'idle', commands: ['show version'] }] }}
                now={new Date()}
            />,
        );

        await waitFor(() => expect(screen.getByText('Closed after 15 minutes without typing.')).toBeInTheDocument());
        expect(screen.getByText('show version')).toBeInTheDocument();
    });
});
