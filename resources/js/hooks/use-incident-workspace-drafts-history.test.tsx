import { initializeSafetyHistory } from '@/lib/safety-history';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { useIncidentWorkspaceDrafts } from './use-incident-workspace-drafts';

const state = vi.hoisted(() => ({ cancelAll: vi.fn(), visit: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    router: { on: () => () => {}, cancelAll: state.cancelAll, visit: state.visit },
}));
vi.mock('@/components/confirm-dialog', () => ({
    ConfirmDialog: ({ open, title, description, onClose, onConfirm }: {
        open: boolean; title: string; description: ReactNode; onClose: () => void; onConfirm: () => void;
    }) => open ? <div role="dialog" aria-label={title}><div>{description}</div>
        <button onClick={onClose}>Keep entries</button><button onClick={onConfirm}>Leave</button>
    </div> : null,
}));

// Use the real shared adapter and the DOM's asynchronous History API traversal.
// Real Inertia, encrypted-page and browser behaviour still require browser acceptance.
beforeAll(() => initializeSafetyHistory());
afterEach(async () => {
    cleanup();
    await act(async () => { await Promise.resolve(); });
    vi.clearAllMocks();
});

function Workspace() {
    const drafts = useIncidentWorkspaceDrafts(9);
    const [command, setCommand] = useState('');
    return <main>
        <input aria-label="Original command" value={command} readOnly />
        <button onClick={() => {
            setCommand('original-command-key');
            drafts.onRecordingWindowDraft({ dirty: true, busy: false, uncertain: true });
        }}>Unconfirmed save</button>
        <button onClick={() => {
            setCommand('');
            drafts.onRecordingWindowDraft({ dirty: false, busy: false });
        }}>Confirmed save</button>
        {drafts.confirmation}
    </main>;
}

async function prepare(direction: 'back' | 'forward') {
    window.history.replaceState({ page: 'list' }, '', '/incidents');
    window.history.pushState({ page: 'incident' }, '', '/incidents/9');
    if (direction === 'forward') {
        window.history.pushState({ page: 'destination' }, '', '/my-day');
        window.history.back();
        await waitFor(() => expect(window.location.pathname).toBe('/incidents/9'));
    }
}

describe('incident command recovery through browser traversal', () => {
    it.each(['back', 'forward'] as const)('retains the original command on %s, cancels departure, then permits explicit leave', async (direction) => {
        await prepare(direction);
        render(<Workspace />);
        fireEvent.click(screen.getByRole('button', { name: 'Unconfirmed save' }));
        const length = window.history.length;
        act(() => window.history[direction]());
        await screen.findByRole('dialog', { name: 'Leave before this submission is confirmed?' });
        expect(window.location.pathname).toBe('/incidents/9');
        expect(window.history.length).toBe(length);
        expect(screen.getByLabelText('Original command')).toHaveValue('original-command-key');
        expect(state.cancelAll).toHaveBeenCalledOnce();
        fireEvent.click(screen.getByRole('button', { name: 'Keep entries' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Original command')).toHaveValue('original-command-key');
        act(() => window.history[direction]());
        await screen.findByRole('dialog', { name: 'Leave before this submission is confirmed?' });
        fireEvent.click(screen.getByRole('button', { name: 'Leave' }));
        await waitFor(() => expect(window.location.pathname).toBe(direction === 'back' ? '/incidents' : '/my-day'));
        expect(window.history.length).toBe(length);
        expect(state.visit).not.toHaveBeenCalled();
    });

    it('drops old account command and leave approval when the account-keyed workspace changes', async () => {
        await prepare('back');
        const view = render(<Workspace key="actor-1:incident-9" />);
        fireEvent.click(screen.getByRole('button', { name: 'Unconfirmed save' }));
        act(() => window.history.back());
        await screen.findByRole('dialog', { name: 'Leave before this submission is confirmed?' });
        view.rerender(<Workspace key="actor-2:incident-9" />);
        expect(screen.getByLabelText('Original command')).toHaveValue('');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        await act(async () => { await Promise.resolve(); });
        act(() => window.history.back());
        await waitFor(() => expect(window.location.pathname).toBe('/incidents'));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('clears an obsolete leave decision after confirmation without silently navigating away', async () => {
        await prepare('back');
        render(<Workspace />);
        fireEvent.click(screen.getByRole('button', { name: 'Unconfirmed save' }));
        act(() => window.history.back());
        await screen.findByRole('dialog', { name: 'Leave before this submission is confirmed?' });
        fireEvent.click(screen.getByRole('button', { name: 'Confirmed save' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(window.location.pathname).toBe('/incidents/9');
        fireEvent.click(screen.getByRole('button', { name: 'Unconfirmed save' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(state.visit).not.toHaveBeenCalled();
    });
});
