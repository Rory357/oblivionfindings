/* eslint-disable no-restricted-syntax -- Test doubles use raw buttons as simple controls. */
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useIncidentWorkspaceDrafts } from './use-incident-workspace-drafts';

type Visit = { method: string; url: URL; preserveState: boolean };
type BeforeEvent = { detail: { visit: Visit }; preventDefault: () => void };
const state = vi.hoisted(() => ({
    before: new Set<(event: BeforeEvent) => void>(),
    visit: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    router: { on: (_name: string, callback: (event: BeforeEvent) => void) => {
        state.before.add(callback); return () => { state.before.delete(callback); };
    }, visit: state.visit, cancelAll: vi.fn() },
}));
vi.mock('@/components/confirm-dialog', () => ({
    ConfirmDialog: ({ open, title, description, onClose, onConfirm }: {
        open: boolean; title: string; description: ReactNode; onClose: () => void; onConfirm: () => void;
    }) => open ? <div role="dialog" aria-label={title}><p>{description}</p>
        <button onClick={onClose}>Stay here</button><button onClick={onConfirm}>Leave</button>
    </div> : null,
}));
afterEach(() => { cleanup(); state.visit.mockReset(); });
function Harness() {
    const drafts = useIncidentWorkspaceDrafts(9);
    return <><button onClick={() => drafts.onRecordingWindowDraft({ dirty: true, busy: false, uncertain: true })}>Unconfirmed save</button>
        <button onClick={() => drafts.onRecordingWindowDraft({ dirty: false, busy: false, uncertain: false })}>Confirmed save</button>
        <button onClick={() => drafts.onRecordingReferenceDraft({ dirty: true, busy: false, uncertain: true })}>Unconfirmed reference</button>
        {drafts.confirmation}</>;
}
function before(path: string, preserveState = false) {
    const event = { detail: { visit: { method: 'get', url: new URL(path, window.location.origin), preserveState } }, preventDefault: vi.fn() };
    act(() => { for (const callback of state.before) callback(event); });
    return event;
}
describe('incident evidence draft navigation', () => {
    it('guards the independent reference command after the evidence window is confirmed', () => {
        render(<Harness />);
        fireEvent.click(screen.getByRole('button', { name: 'Unconfirmed reference' }));
        fireEvent.click(screen.getByRole('button', { name: 'Confirmed save' }));
        expect(before('/incidents').preventDefault).toHaveBeenCalledOnce();
        expect(screen.getByRole('dialog', { name: 'Leave before this submission is confirmed?' })).toBeVisible();
    });
    it('protects an uncertain save while allowing same-record access refresh without abandoning the command', () => {
        render(<Harness />);
        fireEvent.click(screen.getByRole('button', { name: 'Unconfirmed save' }));
        expect(before('/incidents/9?section=evidence', true).preventDefault).not.toHaveBeenCalled();
        expect(before('/incidents').preventDefault).toHaveBeenCalledOnce();
        expect(screen.getByRole('dialog', { name: 'Leave before this submission is confirmed?' })).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Stay here' }));
        expect(state.visit).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Confirmed save' }));
        expect(before('/incidents').preventDefault).not.toHaveBeenCalled();
    });
    it('requires an explicit leave decision before discarding an unresolved incident command', () => {
        render(<Harness />);
        fireEvent.click(screen.getByRole('button', { name: 'Unconfirmed save' }));
        before('/incidents');
        fireEvent.click(screen.getByRole('button', { name: 'Leave' }));
        expect(state.visit).toHaveBeenCalledTimes(1);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
});
