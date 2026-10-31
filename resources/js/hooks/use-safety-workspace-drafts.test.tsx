import type { PendingVisit } from '@inertiajs/core';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { Button } from '@/components/ui/button';
import { SafetyFormDraftContext, useSafetyDraftState, useSafetyWorkspaceDrafts } from './use-safety-workspace-drafts';

const listeners = vi.hoisted(
    () => new Set<(event: { detail: { visit: PendingVisit } }) => void>(),
);

function DraftRegistration({ uncertain }: { uncertain: boolean }) {
    useSafetyDraftState({ dirty: uncertain, busy: false, uncertain }); return null;
}
function RecoveryWorkspace({ uncertain, onLeave }: { uncertain: boolean; onLeave: () => void }) {
    const drafts = useSafetyWorkspaceDrafts();
    return <SafetyFormDraftContext.Provider value={drafts.register}><DraftRegistration uncertain={uncertain} />
        <Button onClick={() => drafts.request(onLeave)}>Return to connected work</Button>{drafts.confirmation}</SafetyFormDraftContext.Provider>;
}
it('warns that an uncertain submission may already be recorded and preserves the decision to stay', () => {
    const leave = vi.fn(); render(<RecoveryWorkspace uncertain onLeave={leave} />);
    fireEvent.click(screen.getByRole('button', { name: 'Return to connected work' }));
    expect(screen.getByRole('alertdialog')).toHaveTextContent('Leave before this submission is confirmed?');
    expect(screen.getByText(/original submission may already be recorded/)).toBeInTheDocument();
    expect(screen.queryByText('Your unsaved entries will be discarded. Cancel to keep editing.')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' })); expect(leave).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Return to connected work' }));
    fireEvent.click(screen.getByRole('button', { name: 'Discard and continue' })); expect(leave).toHaveBeenCalledOnce();
});
it('withdraws the uncertainty prompt after genuine confirmation without blocking clean navigation', () => {
    const leave = vi.fn(); const { rerender } = render(<RecoveryWorkspace uncertain onLeave={leave} />);
    fireEvent.click(screen.getByRole('button', { name: 'Return to connected work' })); expect(leave).not.toHaveBeenCalled();
    rerender(<RecoveryWorkspace uncertain={false} onLeave={leave} />);
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Return to connected work' })); expect(leave).toHaveBeenCalledOnce();
});
vi.mock('@inertiajs/react', () => ({
    router: {
        on: (
            _name: string,
            callback: (event: { detail: { visit: PendingVisit } }) => void,
        ) => {
            listeners.add(callback);
            return () => listeners.delete(callback);
        },
    },
}));
function Workspace() {
    useSafetyWorkspaceDrafts();
    return null;
}
beforeEach(() => {
    listeners.clear();
    window.history.replaceState({}, '', '/hazards/8');
});
afterEach(cleanup);

it('keeps the current command URL when a clean-page inbox request finishes after typing begins', () => {
    const view = render(<Workspace />);
    const visit = {
        method: 'get',
        url: new URL(window.location.href),
        async: true,
        only: ['inbox'],
        reset: [],
        preserveState: true,
        preserveUrl: false,
    } as unknown as PendingVisit;
    listeners.forEach((callback) => callback({ detail: { visit } }));
    // This flag belongs to the request, including when its reply arrives late.
    window.history.replaceState({}, '', '/hazards/8?action=review');
    expect(visit.preserveUrl).toBe(true);
    view.unmount();
    expect(listeners.size).toBe(0);
});

it.each([
    { only: ['detail'] },
    { only: ['inbox', 'detail'] },
    { reset: ['detail'] },
    { preserveState: false },
    { async: false },
    { method: 'post' },
    { url: new URL('https://outside.example/hazards/8') },
    { url: new URL('http://localhost/hazards/9') },
    { url: new URL('http://localhost/hazards/8?action=close') },
])(
    'does not change ordinary record or other-address requests: %j',
    (changes) => {
        render(<Workspace />);
        const visit = {
            method: 'get',
            url: new URL(window.location.href),
            async: true,
            only: ['inbox'],
            reset: [],
            preserveState: true,
            preserveUrl: false,
            ...changes,
        } as unknown as PendingVisit;
        listeners.forEach((callback) => callback({ detail: { visit } }));
        expect(visit.preserveUrl).toBe(false);
    },
);
