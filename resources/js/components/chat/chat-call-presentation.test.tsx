import {
    act,
    cleanup,
    fireEvent,
    render,
    renderHook,
    screen,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ChatCallDock } from './chat-call-dock';
import { IncomingCallCard } from './incoming-call-card';
import { useCallSilence } from './use-call-silence';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});
const actions = () => ({
    onAnswer: vi.fn(),
    onDecline: vi.fn(),
    onSilence: vi.fn(),
    onEnableSound: vi.fn(),
});
const basic = {
    caller: 'Jo Example',
    detail: 'Voice call',
    working: false,
    permissionPending: false,
    silent: false,
    soundReady: true,
    soundEnabled: true,
};

describe('approved shared call presentation', () => {
    it('shows authoritative family identity and audience without inferring them from a client id', () => {
        render(
            <IncomingCallCard
                {...basic}
                {...actions()}
                presentation={{
                    kind: 'family',
                    caller_name: 'Jo Taylor',
                    caller_detail: 'Sam Taylor’s mother',
                    audience: 'About Sam Taylor · Calling the care team',
                }}
            />,
        );
        expect(screen.getByText('Family voice call')).toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'Jo Taylor' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Sam Taylor’s mother')).toBeInTheDocument();
        expect(
            screen.getByText('About Sam Taylor · Calling the care team'),
        ).toBeInTheDocument();
    });
    it('retains both actions and invitation context when its ringtone is silenced', () => {
        const callbacks = actions();
        render(<IncomingCallCard {...basic} {...callbacks} silent />);
        expect(
            screen.getByText('Ringtone silenced · Still calling'),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Unmute ringtone' }),
        );
        expect(callbacks.onSilence).toHaveBeenCalledOnce();
        expect(screen.getByRole('button', { name: 'Answer' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Decline' })).toBeEnabled();
    });
    it('preserves focus while announcing controls and makes them reachable by shortcut', () => {
        const view = render(
            <>
                <textarea aria-label="Draft" defaultValue="Unfinished note" />
                <input aria-label="Next field" />
            </>,
        );
        const draft = screen.getByRole('textbox', { name: 'Draft' });
        draft.focus();
        view.rerender(
            <>
                <textarea aria-label="Draft" defaultValue="Unfinished note" />
                <input aria-label="Next field" />
                <ChatCallDock>
                    <IncomingCallCard {...basic} {...actions()} />
                </ChatCallDock>
            </>,
        );
        expect(draft).toHaveFocus();
        expect(document.documentElement.dataset.chatCallDock).toBe('visible');
        screen.getByRole('textbox', { name: 'Next field' }).focus();
        fireEvent.keyDown(document, {
            altKey: true,
            shiftKey: true,
            code: 'KeyC',
        });
        expect(
            screen.getByRole('button', { name: 'Silence ringtone' }),
        ).toHaveFocus();
        view.rerender(
            <>
                <textarea aria-label="Draft" defaultValue="Unfinished note" />
                <input aria-label="Next field" />
            </>,
        );
        expect(
            screen.getByRole('textbox', { name: 'Next field' }),
        ).toHaveFocus();
        expect(draft).toHaveValue('Unfinished note');
        expect(document.documentElement.dataset.chatCallDock).toBeUndefined();
        expect(
            document.documentElement.style.getPropertyValue(
                '--chat-call-dock-height',
            ),
        ).toBe('');
    });
});

class Peer {
    static peers = new Set<Peer>();
    onmessage: ((event: { data: unknown }) => void) | null = null;
    constructor(public name: string) {
        Peer.peers.add(this);
    }
    postMessage(data: unknown) {
        for (const peer of Peer.peers)
            if (peer !== this && peer.name === this.name)
                peer.onmessage?.({ data });
    }
    close() {
        Peer.peers.delete(this);
    }
}

describe('per-invitation ringtone silence', () => {
    it('shares silence and explicit unmute with sibling and newly opened tabs', () => {
        vi.stubGlobal('BroadcastChannel', Peer);
        const first = renderHook(() => useCallSilence(11, 'call-a'));
        const second = renderHook(() => useCallSilence(11, 'call-a'));
        act(() => first.result.current.toggle());
        expect(first.result.current.silent).toBe(true);
        expect(second.result.current.silent).toBe(true);
        const late = renderHook(() => useCallSilence(11, 'call-a'));
        expect(late.result.current.silent).toBe(true);
        act(() => late.result.current.toggle());
        expect(first.result.current.silent).toBe(false);
        expect(second.result.current.silent).toBe(false);
    });
    it('does not silence a different call or another account and cleans up peers', () => {
        vi.stubGlobal('BroadcastChannel', Peer);
        const first = renderHook(({ call }) => useCallSilence(11, call), {
            initialProps: { call: 'call-a' },
        });
        const next = renderHook(() => useCallSilence(11, 'call-b'));
        const other = renderHook(() => useCallSilence(22, 'call-a'));
        act(() => first.result.current.toggle());
        expect(next.result.current.silent).toBe(false);
        expect(other.result.current.silent).toBe(false);
        first.rerender({ call: 'call-b' });
        expect(first.result.current.silent).toBe(false);
        first.unmount();
        next.unmount();
        other.unmount();
        expect(Peer.peers.size).toBe(0);
    });
    it('can silence locally when cross-tab broadcasting is unavailable', () => {
        vi.stubGlobal('BroadcastChannel', undefined);
        const hook = renderHook(() => useCallSilence(11, 'call-a'));
        act(() => hook.result.current.toggle());
        expect(hook.result.current.silent).toBe(true);
    });
});
