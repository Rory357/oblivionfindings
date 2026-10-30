import { Button } from '@/components/ui/button';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { chatRequest } from './api';
import { callRecoveryKey } from './call-recovery-journal';
import { createCallTestLocks } from './call-recovery-test-locks';
import {
    ChatCallButton,
    ChatCallingProvider,
    useChatCalling,
} from './chat-calling';
import { claimChatMicrophone } from './chat-microphone';
import {
    ChatPreferencesProvider,
    defaultChatPreferences,
    type ChatPreferencesSnapshot,
} from './chat-personal-preferences';
import type { CallPresentation } from './incoming-call-card';
import type { ChatConversation } from './types';

type FakeRoom = {
    emit: (event: string, payload?: unknown) => void;
    disconnect: ReturnType<typeof vi.fn>;
    connect: ReturnType<typeof vi.fn>;
    switchActiveDevice: ReturnType<typeof vi.fn>;
    localParticipant: {
        setMicrophoneEnabled: ReturnType<typeof vi.fn>;
        audioTrackPublications: Map<
            string,
            { track: { stop: ReturnType<typeof vi.fn> } }
        >;
    };
};
const state = vi.hoisted(() => ({
    rooms: [] as FakeRoom[],
    roomOptions: [] as unknown[],
    poll: null as (() => Promise<boolean>) | null,
    connectResult: null as Promise<void> | null,
    ringtoneResult: null as Promise<boolean> | null,
    failAudio: false,
    ringtoneEnabled: false,
}));
vi.mock('./api', () => ({ chatRequest: vi.fn(), setChatDraftActor: vi.fn() }));
vi.mock('./chat-realtime', () => ({ useChatRealtime: () => 'live' }));
vi.mock('./use-call-ringtone', () => ({
    useCallRingtone: (_actor: unknown, _call: unknown, enabled: boolean) => {
        state.ringtoneEnabled = enabled;
        return {
            ready: true,
            enable: () => state.ringtoneResult ?? Promise.resolve(true),
        };
    },
}));
vi.mock('./chat-update-watch', () => ({
    watchChatUpdates: (poll: () => Promise<boolean>) => {
        state.poll = poll;
        void poll();
        return () => undefined;
    },
}));
vi.mock('./call-summary', () => ({
    CallSummary: ({ call }: { call: { id: string } | null }) =>
        call ? <div>Record for {call.id}</div> : null,
}));
vi.mock('./chat-thread', () => ({
    ChatThread: ({ family }: { family: boolean }) => (
        <div>
            {family
                ? 'Family call conversation fixture'
                : 'Staff call conversation fixture'}
        </div>
    ),
}));
vi.mock('@inertiajs/react', () => ({
    Link: ({
        children,
        href,
        className,
    }: {
        children: ReactNode;
        href: string;
        className?: string;
    }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));
vi.mock('livekit-client', () => ({
    RoomEvent: {
        TrackSubscribed: 'track',
        TrackUnsubscribed: 'untrack',
        AudioPlaybackStatusChanged: 'playback',
        Reconnecting: 'reconnecting',
        Reconnected: 'reconnected',
        Disconnected: 'disconnected',
        ActiveSpeakersChanged: 'speakers',
        ActiveDeviceChanged: 'device',
    },
    Track: { Kind: { Audio: 'audio' } },
    Room: class {
        state = 'connected';
        switchActiveDevice = vi.fn(async () => true);
        getActiveDevice = vi.fn(() => 'default');
        listeners = new Map<string, Set<(payload?: unknown) => void>>();
        localParticipant = {
            setMicrophoneEnabled: vi.fn(async () => undefined),
            audioTrackPublications: new Map([
                ['mic', { track: { stop: vi.fn() } }],
            ]),
        };
        connect = vi.fn(() => state.connectResult ?? Promise.resolve());
        disconnect = vi.fn(async () => undefined);
        startAudio = vi.fn(async () => {
            if (state.failAudio) throw new Error('Autoplay blocked');
        });
        canPlaybackAudio = true;
        constructor(options: unknown) {
            state.roomOptions.push(options);
            state.rooms.push(this);
        }
        on(event: string, listener: (payload?: unknown) => void) {
            const listeners = this.listeners.get(event) ?? new Set();
            listeners.add(listener);
            this.listeners.set(event, listeners);
        }
        off(event: string, listener: (payload?: unknown) => void) {
            this.listeners.get(event)?.delete(listener);
        }
        emit(event: string, payload?: unknown) {
            this.listeners.get(event)?.forEach((listener) => listener(payload));
        }
    },
}));

function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (error: Error) => void;
    const promise = new Promise<T>((done, fail) => {
        resolve = done;
        reject = fail;
    });
    return { promise, resolve, reject };
}
function caller(id = 'call-a', actor = 11) {
    return {
        id,
        conversation_id: 17,
        client_id: 8,
        client_name: 'Fictional client',
        title: `Conversation ${id}`,
        status: 'connected',
        host_id: actor,
        started_by: actor,
        connected_at: null as string | null,
        ended_at: null,
        ring_until: '2099-01-01T00:00:00Z',
        my_status: 'accepted',
        my_invitation_id: 'fixture-invitation' as string | undefined,
        can_record_note: true,
        participants: [] as {
            id: number;
            name: string;
            status: string;
            invitation_id?: string;
        }[],
        presentation: {
            kind: 'staff',
            caller_name: 'Rohan',
            caller_detail: null,
            audience: 'Just you and Rohan',
            viewer_is_family: false,
        } as CallPresentation,
    };
}
type CallFixture = ReturnType<typeof caller>;
const status = (calls: CallFixture[] = []) => ({
    ready: true,
    availability: 'available',
    sound_enabled: true,
    calls,
});
function Controls({ actor = 11 }: { actor?: number }) {
    const calling = useChatCalling()!;
    return (
        <>
            <Button
                onClick={() =>
                    void calling
                        .start({ id: 17 } as ChatConversation, [
                            actor === 22 ? 11 : 22,
                        ])
                        .catch(() => undefined)
                }
            >
                Start fixture call
            </Button>
            <Button
                onClick={() =>
                    void calling
                        .start(
                            { id: 17 } as ChatConversation,
                            [22],
                            'invited',
                            { microphone: 'usb', speaker: 'headset' },
                        )
                        .catch(() => undefined)
                }
            >
                Start device fixture call
            </Button>
            <Button onClick={() => void calling.setAvailability('available')}>
                Become available
            </Button>
            <Button onClick={() => calling.cancelPreflight()}>
                Cancel permission wait
            </Button>
            <ChatCallButton
                actorId={11}
                conversation={
                    {
                        id: 17,
                        can_call: true,
                        can_send: true,
                    } as ChatConversation
                }
            />
        </>
    );
}
function View({ actor = 11 }: { actor?: number }) {
    return (
        <ChatCallingProvider actorId={actor} enabled>
            <Controls actor={actor} />
        </ChatCallingProvider>
    );
}

describe('shared call lifecycle', () => {
    let currentCall: CallFixture;
    let calls: CallFixture[];
    beforeEach(() => {
        localStorage.clear();
        vi.clearAllMocks();
        state.rooms.length = 0;
        state.roomOptions.length = 0;
        state.connectResult = null;
        state.ringtoneResult = null;
        state.failAudio = false;
        currentCall = caller();
        calls = [];
        vi.stubGlobal('navigator', {
            locks: createCallTestLocks(),
            mediaDevices: {
                getUserMedia: vi.fn(async () => ({
                    getTracks: () => [{ stop: vi.fn() }],
                })),
            },
        });
        vi.mocked(chatRequest).mockImplementation(async (path, options) => {
            if (path.startsWith('/chat/api/calls?')) return status(calls);
            if (path.endsWith('/call-options'))
                return {
                    people: [
                        {
                            id: 22,
                            name: 'Jo Example',
                            available: true,
                            cover: false,
                        },
                    ],
                };
            if (path.endsWith('/calls') && options?.method === 'POST') {
                calls = [currentCall];
                return {
                    call: currentCall,
                    attempt_state: 'started',
                    can_join: true,
                };
            }
            if (path.endsWith('/join')) {
                currentCall = { ...currentCall, my_status: 'accepted' };
                calls = [currentCall];
                return {
                    call: currentCall,
                    connection: {
                        url: 'wss://voice.example.test',
                        token: 'fixture',
                    },
                };
            }
            if (path.endsWith('/leave')) calls = [];
            if (path.includes('/outcome?'))
                return {
                    call: {
                        ...currentCall,
                        outcome: 'ended',
                        ended_at: '2026-10-18T01:00:00Z',
                    },
                    followup: {
                        conversation: {
                            id: currentCall.conversation_id,
                            title: currentCall.title,
                            can_send: true,
                            can_call: true,
                        },
                        family: false,
                        callbacks: false,
                    },
                };
            return {};
        });
    });

    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('does not start a call over a voice note and releases call ownership after leaving', async () => {
        const releaseNote = claimChatMicrophone('voice_note');
        render(<View />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Start fixture call' }),
        );
        await act(async () => undefined);
        expect(navigator.mediaDevices.getUserMedia).not.toHaveBeenCalled();
        expect(state.rooms).toHaveLength(0);
        releaseNote();
        fireEvent.click(
            screen.getByRole('button', { name: 'Start fixture call' }),
        );
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        expect(() => claimChatMicrophone('voice_note')).toThrow(
            /Finish the voice call/,
        );
        await waitFor(() =>
            expect(screen.getByRole('button', { name: 'Leave' })).toBeEnabled(),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Leave' }));
        await waitFor(() =>
            expect(
                screen.queryByRole('region', { name: 'Ongoing voice call' }),
            ).not.toBeInTheDocument(),
        );
        const releaseAfterCall = claimChatMicrophone('voice_note');
        releaseAfterCall();
    });

    it('keeps an incoming invitation retryable while a voice message owns capture', async () => {
        const releaseNote = claimChatMicrophone('voice_note');
        currentCall = { ...caller('incoming', 22), my_status: 'invited' };
        calls = [currentCall];
        render(<View />);
        fireEvent.click(await screen.findByRole('button', { name: 'Answer' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Answer' }),
            ).toBeEnabled(),
        );
        expect(screen.getByRole('alert')).toHaveTextContent(/voice message/);
        expect(navigator.mediaDevices.getUserMedia).not.toHaveBeenCalled();
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(
                    ([path]) =>
                        path.endsWith('/join') || path.endsWith('/leave'),
                ),
        ).toBe(false);
        releaseNote();
        fireEvent.click(screen.getByRole('button', { name: 'Answer' }));
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        expect(navigator.mediaDevices.getUserMedia).toHaveBeenCalledTimes(1);
    });

    it('starts a care-team request without treating responders as conference participants', async () => {
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) =>
            path.endsWith('/call-options')
                ? (Promise.resolve({
                      people: [
                          {
                              id: 22,
                              name: 'Mere',
                              available: true,
                              cover: true,
                          },
                      ],
                      care_team: { allowed: true, available: true },
                      max_participants: 2,
                  }) as never)
                : fallback(path, options),
        );
        render(<View />);
        const start = screen.getByRole('button', { name: 'Start voice call' });
        await waitFor(() => expect(start).toBeEnabled());
        fireEvent.click(start);
        const team = await screen.findByRole('radio', {
            name: /Available care team/,
        });
        expect(team).toBeChecked();
        expect(
            screen.queryByRole('checkbox', { name: /Mere/ }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Check microphone' }),
        );
        await screen.findByRole('button', { name: 'Check again' });
        fireEvent.click(screen.getByRole('button', { name: 'Call care team' }));
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        const request = vi
            .mocked(chatRequest)
            .mock.calls.find(
                ([path, options]) =>
                    path.endsWith('/calls') && options?.method === 'POST',
            )!;
        expect(JSON.parse(request[1]!.body as string)).toMatchObject({
            routing_mode: 'care_team',
            participant_ids: [],
        });
    });

    it('keeps an unavailable care team from ringing and makes the fallback clear', async () => {
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) =>
            path.endsWith('/call-options')
                ? (Promise.resolve({
                      people: [],
                      care_team: { allowed: true, available: false },
                      max_participants: 8,
                  }) as never)
                : fallback(path, options),
        );
        render(<View />);
        const start = screen.getByRole('button', { name: 'Start voice call' });
        await waitFor(() => expect(start).toBeEnabled());
        fireEvent.click(start);
        expect(
            await screen.findByRole('button', { name: 'Call care team' }),
        ).toBeDisabled();
        expect(
            screen.getByText(/Nobody is available to call right now/),
        ).toBeInTheDocument();
        expect(navigator.mediaDevices.getUserMedia).not.toHaveBeenCalled();
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(
                    ([path, options]) =>
                        path.endsWith('/calls') && options?.method === 'POST',
                ),
        ).toBe(false);
    });

    it('keeps explicitly selected group calls separate and enforces the returned participant limit', async () => {
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) =>
            path.endsWith('/call-options')
                ? (Promise.resolve({
                      people: [
                          {
                              id: 22,
                              name: 'Mere',
                              available: true,
                              cover: true,
                          },
                          {
                              id: 33,
                              name: 'Alex',
                              available: true,
                              cover: false,
                          },
                      ],
                      care_team: { allowed: true, available: true },
                      max_participants: 2,
                  }) as never)
                : fallback(path, options),
        );
        render(<View />);
        const start = screen.getByRole('button', { name: 'Start voice call' });
        await waitFor(() => expect(start).toBeEnabled());
        fireEvent.click(start);
        fireEvent.click(
            await screen.findByRole('radio', {
                name: 'Choose people for a call',
            }),
        );
        fireEvent.click(screen.getByRole('checkbox', { name: /Mere/ }));
        expect(screen.getByRole('checkbox', { name: /Alex/ })).toBeDisabled();
        expect(
            screen.getByText('2 / 2 participants, including you'),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Check microphone' }),
        );
        await screen.findByRole('button', { name: 'Check again' });
        fireEvent.click(screen.getByRole('button', { name: 'Call' }));
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        const request = vi
            .mocked(chatRequest)
            .mock.calls.find(
                ([path, options]) =>
                    path.endsWith('/calls') && options?.method === 'POST',
            )!;
        expect(JSON.parse(request[1]!.body as string)).toMatchObject({
            routing_mode: 'invited',
            participant_ids: [22],
        });
    });

    it('opens call messages without navigation, preserves work, and removes the view on account change', async () => {
        const view = render(
            <ChatCallingProvider actorId={11} enabled>
                <textarea
                    aria-label="Unfinished work"
                    defaultValue="Keep this note"
                />
                <Controls />
            </ChatCallingProvider>,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Start fixture call' }),
        );
        const message = await screen.findByRole('button', { name: 'Message' });
        message.focus();
        fireEvent.click(message);
        const dialog = await screen.findByRole('dialog');
        expect(
            await within(dialog).findByText('Staff call conversation fixture'),
        ).toBeInTheDocument();
        expect(document.querySelector('.chat-call-dock')).toHaveAttribute(
            'aria-hidden',
            'true',
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Keep working' }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(message).toHaveFocus();
        expect(
            screen.getByRole('textbox', { name: 'Unfinished work' }),
        ).toHaveValue('Keep this note');
        fireEvent.click(message);
        await screen.findByRole('dialog');
        view.rerender(
            <ChatCallingProvider actorId={33} enabled>
                <Controls />
            </ChatCallingProvider>,
        );
        expect(
            screen.queryByText('Staff call conversation fixture'),
        ).not.toBeInTheDocument();
        expect(
            state.rooms[0].localParticipant.audioTrackPublications.get('mic')!
                .track.stop,
        ).toHaveBeenCalled();
    });

    it('uses the portal role from call metadata and never guesses when it is missing', async () => {
        currentCall.presentation.viewer_is_family = true;
        render(<View />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Start fixture call' }),
        );
        fireEvent.click(await screen.findByRole('button', { name: 'Message' }));
        await screen.findByText('Family call conversation fixture');
        currentCall = {
            ...currentCall,
            presentation: {
                ...currentCall.presentation,
                viewer_is_family: undefined,
            },
        };
        calls = [currentCall];
        await act(async () => {
            await state.poll?.();
        });
        expect(
            screen.queryByText('Family call conversation fixture'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(/Conversation details are refreshing/),
        ).toBeInTheDocument();
    });

    it('reviews ending a host call separately and returns keyboard focus when cancelled', async () => {
        render(<View />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Start fixture call' }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Expand current call' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'End for everyone' }),
        );
        await screen.findByRole('heading', {
            name: 'End the call for everyone?',
        });
        expect(screen.getAllByRole('dialog')).toHaveLength(1);
        fireEvent.click(screen.getByRole('button', { name: 'Keep call open' }));
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(
            screen.getByRole('button', { name: 'Expand current call' }),
        ).toHaveFocus();
        expect(state.rooms[0].disconnect).not.toHaveBeenCalled();
    });

    it('can decline while microphone permission is pending and releases any late stream', async () => {
        currentCall = { ...caller('incoming', 22), my_status: 'invited' };
        calls = [currentCall];
        const permission = deferred<MediaStream>();
        const stop = vi.fn();
        vi.mocked(navigator.mediaDevices.getUserMedia).mockReturnValueOnce(
            permission.promise,
        );
        render(<View />);
        fireEvent.click(await screen.findByRole('button', { name: 'Answer' }));
        await waitFor(() =>
            expect(navigator.mediaDevices.getUserMedia).toHaveBeenCalledOnce(),
        );
        expect(
            screen.getByText(/Waiting for microphone permission/),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Decline' }));
        await waitFor(() =>
            expect(
                screen.queryByRole('button', { name: 'Answer' }),
            ).not.toBeInTheDocument(),
        );
        await act(async () =>
            permission.resolve({
                getTracks: () => [{ stop }],
            } as unknown as MediaStream),
        );
        expect(stop).toHaveBeenCalledOnce();
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(([path]) => path.endsWith('/join')),
        ).toBe(false);
        expect(state.rooms).toHaveLength(0);
    });

    it('cancels outgoing permission wait without ringing and does not close a reopened picker', async () => {
        const permission = deferred<MediaStream>();
        const stop = vi.fn();
        vi.mocked(navigator.mediaDevices.getUserMedia).mockReturnValueOnce(
            permission.promise,
        );
        render(<View />);
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Start voice call' }),
            ).toBeEnabled(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Start voice call' }),
        );
        fireEvent.click(
            await screen.findByRole('checkbox', { name: /Jo Example/ }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Check microphone' }),
        );
        await screen.findByText(/Nobody has been called/);
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Start voice call' }),
        );
        await screen.findByRole('dialog');
        await act(async () =>
            permission.resolve({
                getTracks: () => [{ stop }],
            } as unknown as MediaStream),
        );
        expect(stop).toHaveBeenCalledOnce();
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(
                    ([path, options]) =>
                        path.endsWith('/calls') && options?.method === 'POST',
                ),
        ).toBe(false);
    });

    it('does not abandon a call request once it has reached the server', async () => {
        const pending = deferred<{
            call: CallFixture;
            attempt_state: 'started';
            can_join: true;
        }>();
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) =>
            path.endsWith('/calls') && options?.method === 'POST'
                ? pending.promise
                : fallback(path, options),
        );
        render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await waitFor(() =>
            expect(
                vi
                    .mocked(chatRequest)
                    .mock.calls.some(
                        ([path, options]) =>
                            path.endsWith('/calls') &&
                            options?.method === 'POST',
                    ),
            ).toBe(true),
        );
        fireEvent.click(screen.getByText('Cancel permission wait'));
        fireEvent.click(screen.getByText('Start fixture call'));
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.filter(
                    ([path, options]) =>
                        path.endsWith('/calls') && options?.method === 'POST',
                ),
        ).toHaveLength(1);
        calls = [currentCall];
        await act(async () =>
            pending.resolve({
                call: currentCall,
                attempt_state: 'started',
                can_join: true,
            }),
        );
        await screen.findByRole('region', { name: 'Ongoing voice call' });
    });

    it('binds call requests to the rendered account across an account change', async () => {
        const view = render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        await waitFor(() =>
            expect(
                vi
                    .mocked(chatRequest)
                    .mock.calls.some(([path]) => path.endsWith('/connected')),
            ).toBe(true),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Leave' }));
        await waitFor(() =>
            expect(
                screen.queryByRole('region', { name: 'Ongoing voice call' }),
            ).not.toBeInTheDocument(),
        );
        for (const [, options] of vi.mocked(chatRequest).mock.calls)
            expect(new Headers(options?.headers).get('X-Chat-Actor')).toBe(
                '11',
            );
        vi.mocked(chatRequest).mockClear();
        view.rerender(<View actor={22} />);
        await waitFor(() => expect(chatRequest).toHaveBeenCalled());
        for (const [, options] of vi.mocked(chatRequest).mock.calls)
            expect(new Headers(options?.headers).get('X-Chat-Actor')).toBe(
                '22',
            );
    });

    it('serialises starting a call before the server response returns', async () => {
        const pending = deferred<{
            call: CallFixture;
            attempt_state: 'started';
            can_join: true;
        }>();
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) =>
            path.endsWith('/calls') && options?.method === 'POST'
                ? pending.promise
                : fallback(path, options),
        );
        render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        fireEvent.click(screen.getByText('Start fixture call'));
        await waitFor(() =>
            expect(
                vi
                    .mocked(chatRequest)
                    .mock.calls.filter(
                        ([path]) => path === '/chat/api/conversations/17/calls',
                    ),
            ).toHaveLength(1),
        );
        calls = [currentCall];
        await act(async () =>
            pending.resolve({
                call: currentCall,
                attempt_state: 'started',
                can_join: true,
            }),
        );
    });

    it('does not let a status read started before joining close the new call', async () => {
        const oldRead = deferred<ReturnType<typeof status>>();
        vi.mocked(chatRequest).mockImplementationOnce(() => oldRead.promise);
        render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        await waitFor(() =>
            expect(
                state.rooms[0].localParticipant.setMicrophoneEnabled,
            ).toHaveBeenCalledWith(true),
        );
        await act(async () => oldRead.resolve(status()));
        expect(
            screen.getByRole('region', { name: 'Ongoing voice call' }),
        ).toBeInTheDocument();
        expect(state.rooms[0].disconnect).not.toHaveBeenCalled();
    });

    it('never enables the microphone after the server removes a connecting call', async () => {
        const connecting = deferred<void>();
        state.connectResult = connecting.promise;
        render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        calls = [];
        await act(async () => {
            await state.poll?.();
        });
        await act(async () => connecting.resolve());
        expect(
            state.rooms[0].localParticipant.setMicrophoneEnabled,
        ).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('region', { name: 'Ongoing voice call' }),
        ).not.toBeInTheDocument();
    });

    it('does not show an old account call record after a delayed leave response', async () => {
        const leaving = deferred<object>();
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) =>
            path.endsWith('/leave') ? leaving.promise : fallback(path, options),
        );
        const view = render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        await waitFor(() =>
            expect(screen.getByRole('button', { name: 'Leave' })).toBeEnabled(),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Leave' }));
        await waitFor(() =>
            expect(
                vi
                    .mocked(chatRequest)
                    .mock.calls.some(([path]) => path.endsWith('/leave')),
            ).toBe(true),
        );
        calls = [];
        view.rerender(<View actor={22} />);
        await act(async () => leaving.resolve({}));
        expect(screen.queryByText('Record for call-a')).not.toBeInTheDocument();
    });

    it('ignores old room events after the account changes', async () => {
        const view = render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        await waitFor(() =>
            expect(screen.getByRole('button', { name: 'Leave' })).toBeEnabled(),
        );
        const oldRoom = state.rooms[0];
        currentCall = caller('call-b', 22);
        calls = [];
        view.rerender(<View actor={22} />);
        await waitFor(() =>
            expect(
                screen.queryByRole('region', { name: 'Ongoing voice call' }),
            ).not.toBeInTheDocument(),
        );
        fireEvent.click(screen.getByText('Start fixture call'));
        await screen.findByText('Conversation call-b');
        await waitFor(() =>
            expect(screen.getByRole('button', { name: 'Leave' })).toBeEnabled(),
        );
        act(() => state.rooms[1].emit('reconnecting'));
        act(() => oldRoom.emit('reconnected'));
        expect(
            within(
                screen.getByRole('region', { name: 'Ongoing voice call' }),
            ).getByRole('status'),
        ).toHaveTextContent('Reconnecting audio');
    });

    it('does not reopen the microphone when a start response belongs to the old account', async () => {
        const starting = deferred<{
            call: CallFixture;
            attempt_state: 'started';
            can_join: true;
        }>();
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) =>
            path === '/chat/api/conversations/17/calls'
                ? starting.promise
                : fallback(path, options),
        );
        const view = render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await waitFor(() =>
            expect(
                vi
                    .mocked(chatRequest)
                    .mock.calls.some(
                        ([path]) => path === '/chat/api/conversations/17/calls',
                    ),
            ).toBe(true),
        );
        view.rerender(<View actor={22} />);
        await act(async () =>
            starting.resolve({
                call: currentCall,
                attempt_state: 'started',
                can_join: true,
            }),
        );
        expect(navigator.mediaDevices.getUserMedia).toHaveBeenCalledTimes(1);
        expect(state.rooms).toHaveLength(0);
    });

    it('stops capture immediately and keeps a new call when old disconnection finishes late', async () => {
        const disconnecting = deferred<void>();
        const view = render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        await waitFor(() =>
            expect(screen.getByRole('button', { name: 'Leave' })).toBeEnabled(),
        );
        const oldRoom = state.rooms[0];
        oldRoom.disconnect.mockReturnValue(disconnecting.promise);
        currentCall = caller('call-b', 22);
        calls = [];
        view.rerender(<View actor={22} />);
        expect(
            oldRoom.localParticipant.audioTrackPublications.get('mic')?.track
                .stop,
        ).toHaveBeenCalled();
        fireEvent.click(screen.getByText('Start fixture call'));
        await screen.findByText('Conversation call-b');
        await act(async () => disconnecting.resolve());
        expect(screen.getByText('Conversation call-b')).toBeInTheDocument();
        expect(state.rooms[1].disconnect).not.toHaveBeenCalled();
    });

    it('keeps an incoming invitation available when microphone permission is refused', async () => {
        currentCall = { ...caller(), started_by: 22, my_status: 'invited' };
        calls = [currentCall];
        vi.mocked(navigator.mediaDevices.getUserMedia).mockRejectedValue(
            new DOMException('Microphone blocked', 'NotAllowedError'),
        );
        render(<View />);
        fireEvent.click(await screen.findByRole('button', { name: 'Answer' }));
        await waitFor(() =>
            expect(navigator.mediaDevices.getUserMedia).toHaveBeenCalled(),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Microphone blocked',
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Answer' }),
            ).toBeEnabled(),
        );
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(
                    ([path]) =>
                        path.endsWith('/leave') || path.endsWith('/join'),
                ),
        ).toBe(false);
    });

    function loseFirstAnswer() {
        currentCall = {
            ...caller('incoming', 22),
            my_status: 'invited',
            my_invitation_id: 'invitation-1',
        };
        calls = [currentCall];
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        let joins = 0;
        vi.mocked(chatRequest).mockImplementation(async (path, options) => {
            if (path.endsWith('/join') && joins++ === 0) {
                currentCall = { ...currentCall, my_status: 'accepted' };
                calls = [currentCall];
                throw new Error('Answer response lost');
            }
            if (path.includes('/outcome?'))
                return { call: { ...currentCall, outcome: 'ongoing' } };
            return fallback(path, options);
        });
    }

    it('checks a lost answer without joining and explicitly retries the same invitation', async () => {
        loseFirstAnswer();
        render(<View />);
        fireEvent.click(await screen.findByRole('button', { name: 'Answer' }));
        await screen.findByRole('region', { name: 'Call connection recovery' });
        expect(state.rooms).toHaveLength(0);
        expect(
            screen.getByRole('button', { name: 'Start voice call' }),
        ).toBeDisabled();
        expect(
            screen.queryByRole('button', { name: 'Try connecting' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Check call' }));
        await screen.findByRole('button', { name: 'Try connecting' });
        expect(navigator.mediaDevices.getUserMedia).toHaveBeenCalledTimes(1);
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.filter(([path]) => path.endsWith('/join')),
        ).toHaveLength(1);
        fireEvent.click(screen.getByRole('button', { name: 'Try connecting' }));
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        const joins = vi
            .mocked(chatRequest)
            .mock.calls.filter(([path]) => path.endsWith('/join'));
        expect(joins).toHaveLength(2);
        expect(joins[1][1]?.body).toBe(joins[0][1]?.body);
        expect(state.rooms).toHaveLength(1);
    });

    it('keeps stop intent after a lost cancellation and a read that still sees the call', async () => {
        loseFirstAnswer();
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        let stops = 0;
        vi.mocked(chatRequest).mockImplementation((path, options) => {
            if (path.endsWith('/leave') && stops++ === 0)
                return Promise.reject(new Error('Stop response lost'));
            return fallback(path, options);
        });
        render(<View />);
        fireEvent.click(await screen.findByRole('button', { name: 'Answer' }));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Stop my attempt' }),
        );
        await screen.findByRole('alert');
        fireEvent.click(screen.getByRole('button', { name: 'Check call' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Retry stopping' }),
            ).toBeEnabled(),
        );
        expect(
            screen.queryByRole('button', { name: 'Try connecting' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Retry stopping' }));
        await waitFor(() =>
            expect(
                screen.queryByRole('region', {
                    name: 'Call connection recovery',
                }),
            ).not.toBeInTheDocument(),
        );
        const leaves = vi
            .mocked(chatRequest)
            .mock.calls.filter(([path]) => path.endsWith('/leave'));
        expect(leaves).toHaveLength(2);
        expect(leaves[1][1]?.body).toBe(leaves[0][1]?.body);
        expect(state.rooms).toHaveLength(0);
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.filter(([path]) => path.endsWith('/join')),
        ).toHaveLength(1);
    });

    it('removes an old account answer recovery and ignores a late read', async () => {
        loseFirstAnswer();
        const view = render(<View />);
        fireEvent.click(await screen.findByRole('button', { name: 'Answer' }));
        await screen.findByRole('region', { name: 'Call connection recovery' });
        const read = deferred<object>();
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) =>
            path.includes('/outcome?') ? read.promise : fallback(path, options),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Check call' }));
        calls = [];
        view.rerender(<View actor={44} />);
        await act(async () =>
            read.resolve({ call: { ...currentCall, outcome: 'ongoing' } }),
        );
        expect(
            screen.queryByRole('region', { name: 'Call connection recovery' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Conversation incoming'),
        ).not.toBeInTheDocument();
        expect(state.rooms).toHaveLength(0);
    });

    it('hides identity and retry actions when checking an answer is denied', async () => {
        loseFirstAnswer();
        render(<View />);
        fireEvent.click(await screen.findByRole('button', { name: 'Answer' }));
        await screen.findByRole('region', { name: 'Call connection recovery' });
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) =>
            path.includes('/outcome?')
                ? Promise.reject(
                      Object.assign(new Error('Denied'), { status: 403 }),
                  )
                : fallback(path, options),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Check call' }));
        await screen.findByRole('heading', { name: 'Call access changed' });
        expect(
            screen.queryByText('Conversation incoming'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Try connecting' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Stop my attempt' }),
        ).not.toBeInTheDocument();
        expect(state.rooms).toHaveLength(0);
    });

    it.each([
        { id: 'different-call' },
        { conversation_id: 99 },
        { my_invitation_id: 'newer-invitation' },
    ])('rejects a mismatched answer-recovery result %j', async (patch) => {
        loseFirstAnswer();
        render(<View />);
        fireEvent.click(await screen.findByRole('button', { name: 'Answer' }));
        await screen.findByRole('region', { name: 'Call connection recovery' });
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) =>
            path.includes('/outcome?')
                ? Promise.resolve({
                      call: { ...currentCall, outcome: 'ongoing', ...patch },
                  })
                : fallback(path, options),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Check call' }));
        await screen.findByRole('alert');
        expect(
            screen.queryByRole('button', { name: 'Try connecting' }),
        ).not.toBeInTheDocument();
        expect(state.rooms).toHaveLength(0);
    });

    it('can stop a recovered answer while microphone permission is pending', async () => {
        loseFirstAnswer();
        render(<View />);
        fireEvent.click(await screen.findByRole('button', { name: 'Answer' }));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Check call' }),
        );
        await screen.findByRole('button', { name: 'Try connecting' });
        const permission = deferred<MediaStream>();
        const stop = vi.fn();
        vi.mocked(navigator.mediaDevices.getUserMedia).mockReturnValueOnce(
            permission.promise,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Try connecting' }));
        await screen.findByText(/Allow microphone access to reconnect/);
        fireEvent.click(
            screen.getByRole('button', { name: 'Stop my attempt' }),
        );
        await waitFor(() =>
            expect(
                screen.queryByRole('region', {
                    name: 'Call connection recovery',
                }),
            ).not.toBeInTheDocument(),
        );
        await act(async () =>
            permission.resolve({
                getTracks: () => [{ stop }],
            } as unknown as MediaStream),
        );
        expect(stop).toHaveBeenCalledOnce();
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.filter(([path]) => path.endsWith('/join')),
        ).toHaveLength(1);
        expect(state.rooms).toHaveLength(0);
    });

    it('bounds a stalled answer and releases capture without automatically retrying', async () => {
        currentCall = { ...caller('incoming', 22), my_status: 'invited' };
        calls = [currentCall];
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) =>
            path.endsWith('/join')
                ? new Promise((_resolve, reject) =>
                      options?.signal?.addEventListener(
                          'abort',
                          () =>
                              reject(
                                  new DOMException('Timed out', 'AbortError'),
                              ),
                          { once: true },
                      ),
                  )
                : fallback(path, options),
        );
        vi.useFakeTimers();
        try {
            render(<View />);
            await act(async () => undefined);
            fireEvent.click(screen.getByRole('button', { name: 'Answer' }));
            await act(async () => undefined);
            await act(async () => vi.advanceTimersByTimeAsync(30000));
            expect(
                screen.getByRole('region', {
                    name: 'Call connection recovery',
                }),
            ).toBeInTheDocument();
            expect(
                vi
                    .mocked(chatRequest)
                    .mock.calls.filter(([path]) => path.endsWith('/join')),
            ).toHaveLength(1);
            const release = claimChatMicrophone('voice_note');
            release();
            const event = new Event('beforeunload', { cancelable: true });
            window.dispatchEvent(event);
            expect(event.defaultPrevented).toBe(true);
        } finally {
            vi.useRealTimers();
        }
    });

    it('keeps failed audio cleanup visible and retries leaving without reconnecting', async () => {
        const connection = deferred<void>();
        state.connectResult = connection.promise;
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        let attempts = 0;
        vi.mocked(chatRequest).mockImplementation((path, options) => {
            if (path.endsWith('/leave') && attempts++ === 0)
                return Promise.reject(new Error('Cleanup response lost'));
            return fallback(path, options);
        });
        render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await waitFor(() => expect(state.rooms).toHaveLength(1));
        // Reject after the server has accepted the invitation but before audio.
        await act(async () =>
            connection.reject(new Error('Audio unavailable')),
        );
        await screen.findByText(/Audio is off. Retry leaving/);
        expect(
            state.rooms[0].localParticipant.setMicrophoneEnabled,
        ).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Start voice call' }),
        ).toBeDisabled();
        expect(
            screen.queryByRole('region', { name: 'Call result' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Leave' }));
        await screen.findByRole('heading', { name: 'Call ended' });
        expect(attempts).toBe(2);
        expect(state.rooms).toHaveLength(1);
        expect(state.rooms[0].disconnect).toHaveBeenCalled();
    });

    it('retains the call and offers audio enablement when browser autoplay is blocked', async () => {
        state.failAudio = true;
        render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await screen.findByRole('button', {
            name: 'Enable call audio',
        });
        await waitFor(() =>
            expect(screen.getByRole('button', { name: 'Leave' })).toBeEnabled(),
        );
        expect(state.rooms[0].disconnect).not.toHaveBeenCalled();
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(([path]) => path.endsWith('/connected')),
        ).toBe(true);
    });

    it('stops a late microphone operation after leaving instead of restoring capture', async () => {
        render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        await waitFor(() =>
            expect(screen.getByRole('button', { name: 'Mute' })).toBeEnabled(),
        );
        const changing = deferred<void>();
        const session = state.rooms[0];
        session.localParticipant.setMicrophoneEnabled.mockReturnValue(
            changing.promise,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Mute' }));
        expect(screen.getByRole('button', { name: 'Leave' })).toBeEnabled();
        fireEvent.click(screen.getByRole('button', { name: 'Leave' }));
        await act(async () => changing.resolve());
        expect(
            session.localParticipant.audioTrackPublications.get('mic')?.track
                .stop.mock.calls.length,
        ).toBeGreaterThanOrEqual(2);
        expect(
            screen.queryByRole('region', { name: 'Ongoing voice call' }),
        ).not.toBeInTheDocument();
    });

    it.each([false, true])(
        'keeps ringing off until personal quiet preferences are known (quiet=%s)',
        async (quiet) => {
            const pending = deferred<ChatPreferencesSnapshot>();
            vi.mocked(chatRequest).mockImplementation(async (path) =>
                path === '/chat/api/personal-preferences'
                    ? pending.promise
                    : status(),
            );
            render(
                <ChatPreferencesProvider actorId={11} enabled>
                    <View />
                </ChatPreferencesProvider>,
            );
            await waitFor(() =>
                expect(chatRequest).toHaveBeenCalledWith(
                    expect.stringContaining('/chat/api/calls?'),
                    expect.anything(),
                ),
            );
            expect(state.ringtoneEnabled).toBe(false);
            await act(async () =>
                pending.resolve({
                    actor_id: 11,
                    version: 'a'.repeat(64),
                    values: { ...defaultChatPreferences, quiet_enabled: quiet },
                }),
            );
            await waitFor(() => expect(state.ringtoneEnabled).toBe(!quiet));
        },
    );

    it('does not write availability for a new account after delayed ringtone permission', async () => {
        const permission = deferred<boolean>();
        state.ringtoneResult = permission.promise;
        const view = render(<View />);
        fireEvent.click(screen.getByText('Become available'));
        view.rerender(<View actor={22} />);
        await act(async () => permission.resolve(true));
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(
                    ([path, options]) =>
                        path === '/chat/api/availability' &&
                        options?.body !== '{}',
                ),
        ).toBe(false);
    });

    it('shows an audio disconnection even when a connected duration exists', async () => {
        currentCall.connected_at = new Date().toISOString();
        render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        await waitFor(() =>
            expect(screen.getByRole('button', { name: 'Leave' })).toBeEnabled(),
        );
        act(() => state.rooms[0].emit('disconnected'));
        expect(
            within(
                screen.getByRole('region', { name: 'Ongoing voice call' }),
            ).getByRole('status'),
        ).toHaveTextContent(
            'Audio disconnected. Leave the call and try again.',
        );
        expect(screen.getByRole('button', { name: 'Mute' })).toBeDisabled();
    });

    it('refreshes the permitted group roster and host controls without claiming invited people are connected', async () => {
        currentCall.participants = [
            { id: 11, name: 'Alex', status: 'connected' },
            { id: 22, name: 'Mere', status: 'accepted' },
            { id: 33, name: 'Rohan', status: 'invited' },
            { id: 44, name: 'Other cover', status: 'answered_elsewhere' },
        ];
        render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        await waitFor(() =>
            expect(screen.getByRole('button', { name: 'Leave' })).toBeEnabled(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Expand current call' }),
        );
        const roster = screen.getByRole('region', {
            name: 'Call participants',
        });
        expect(
            within(roster).getByText('Mere').closest('li'),
        ).toHaveTextContent('Connecting');
        expect(
            within(roster).getByText('Rohan').closest('li'),
        ).toHaveTextContent('Invited');
        expect(
            within(roster).queryByText('Other cover'),
        ).not.toBeInTheDocument();
        expect(
            within(roster).getByText('Alex (you)').closest('li'),
        ).toHaveTextContent('Host');
        expect(
            screen.getByRole('button', { name: 'End for everyone' }),
        ).toBeEnabled();
        currentCall = {
            ...currentCall,
            host_id: 22,
            participants: [
                { id: 11, name: 'Alex', status: 'connected' },
                {
                    id: 22,
                    name: 'Mere',
                    status: 'connected',
                    invitation_id: 'mere-media',
                },
                { id: 33, name: 'Rohan', status: 'expired' },
            ],
        };
        calls = [currentCall];
        await act(async () => {
            await state.poll?.();
        });
        expect(
            within(roster).getByText('Mere').closest('li'),
        ).toHaveTextContent('Host');
        expect(
            within(roster).getByText('Alex (you)').closest('li'),
        ).not.toHaveTextContent('Host');
        expect(
            within(roster).getByText('Rohan').closest('li'),
        ).toHaveTextContent('No answer');
        expect(
            screen.queryByRole('button', { name: 'End for everyone' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Leave call' }),
        ).toBeEnabled();
        act(() =>
            state.rooms[0].emit('speakers', [
                { identity: 'mere-media' },
                { identity: 'unlisted-media' },
            ]),
        );
        expect(
            within(roster).getByText('Speaking').closest('li'),
        ).toHaveTextContent('Mere');
        expect(within(roster).getAllByText('Speaking')).toHaveLength(1);
        act(() => state.rooms[0].emit('reconnecting'));
        expect(within(roster).queryByText('Speaking')).not.toBeInTheDocument();
        // An authoritative refresh removing a person's access also removes their identity.
        currentCall = {
            ...currentCall,
            participants: currentCall.participants.filter(
                (person) => person.id !== 33,
            ),
        };
        calls = [currentCall];
        await act(async () => {
            await state.poll?.();
        });
        expect(within(roster).queryByText('Rohan')).not.toBeInTheDocument();
    });

    it('ends the old media session when the same call has a new invitation and requires an explicit answer', async () => {
        currentCall.my_invitation_id = 'old-invitation';
        render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        await waitFor(() =>
            expect(screen.getByRole('button', { name: 'Leave' })).toBeEnabled(),
        );
        const firstRoom = state.rooms[0];
        calls = [
            {
                ...currentCall,
                my_status: 'invited',
                my_invitation_id: 'new-invitation',
            },
        ];
        await act(async () => {
            await state.poll?.();
        });
        expect(
            firstRoom.localParticipant.audioTrackPublications.get('mic')?.track
                .stop,
        ).toHaveBeenCalled();
        expect(
            screen.queryByRole('region', { name: 'Ongoing voice call' }),
        ).not.toBeInTheDocument();
        // The original caller may also be invited back by the new host.
        expect(
            await screen.findByRole('button', { name: /^Answer$/ }),
        ).toBeEnabled();
        expect(state.rooms).toHaveLength(1);
        const join = vi
            .mocked(chatRequest)
            .mock.calls.find(([path]) => path.endsWith('/join'))!;
        expect(JSON.parse(join[1]!.body as string).invitation_id).toBe(
            'old-invitation',
        );
    });

    it.each([401, 403, 419])(
        'stops capture and removes call information after status denial %s',
        async (code) => {
            let denied = false;
            const fallback = vi.mocked(chatRequest).getMockImplementation()!;
            vi.mocked(chatRequest).mockImplementation((path, options) =>
                denied && path.startsWith('/chat/api/calls?')
                    ? Promise.reject(
                          Object.assign(new Error('Unavailable'), {
                              status: code,
                          }),
                      )
                    : fallback(path, options),
            );
            render(<View />);
            fireEvent.click(screen.getByText('Start fixture call'));
            await screen.findByRole('region', { name: 'Ongoing voice call' });
            await waitFor(() =>
                expect(
                    screen.getByRole('button', { name: 'Leave' }),
                ).toBeEnabled(),
            );
            denied = true;
            await act(async () => {
                await state.poll?.();
            });
            expect(
                state.rooms[0].localParticipant.audioTrackPublications.get(
                    'mic',
                )?.track.stop,
            ).toHaveBeenCalled();
            expect(
                screen.queryByRole('region', { name: 'Ongoing voice call' }),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByText('Record for call-a'),
            ).not.toBeInTheDocument();
        },
    );

    it('checks microphone permission before ringing any outgoing participants', async () => {
        vi.mocked(navigator.mediaDevices.getUserMedia).mockRejectedValue(
            new DOMException('Microphone blocked', 'NotAllowedError'),
        );
        render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await act(async () => undefined);
        expect(navigator.mediaDevices.getUserMedia).toHaveBeenCalledTimes(1);
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(
                    ([path]) => path === '/chat/api/conversations/17/calls',
                ),
        ).toBe(false);
        expect(state.rooms).toHaveLength(0);
    });

    it('keeps outgoing microphone failure beside the retry action without ringing anyone', async () => {
        vi.mocked(navigator.mediaDevices.getUserMedia).mockRejectedValueOnce(
            new DOMException('Blocked', 'NotAllowedError'),
        );
        render(<View />);
        const trigger = screen.getByRole('button', {
            name: 'Start voice call',
        });
        await waitFor(() => expect(trigger).toBeEnabled());
        fireEvent.click(trigger);
        fireEvent.click(
            await screen.findByRole('checkbox', { name: /Jo Example/ }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Check microphone' }),
        );
        await screen.findByText('Microphone blocked');
        expect(screen.getByRole('alert')).toHaveTextContent('browser settings');
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(
                    ([path, options]) =>
                        path.endsWith('/calls') && options?.method === 'POST',
                ),
        ).toBe(false);
        fireEvent.click(
            screen.getByRole('button', { name: 'Check microphone' }),
        );
        await screen.findByRole('button', { name: 'Check again' });
        fireEvent.click(screen.getByRole('button', { name: /^Call$/ }));
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        expect(
            screen.queryByText('Microphone blocked'),
        ).not.toBeInTheDocument();
    });

    it('shows a confirmed missed invitation and opens the shared conversation without redialling', async () => {
        currentCall = { ...caller('incoming', 22), my_status: 'invited' };
        calls = [currentCall];
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) =>
            path.includes('/outcome?')
                ? Promise.resolve({
                      call: { ...currentCall, outcome: 'missed' },
                      followup: {
                          conversation: {
                              id: 17,
                              title: 'Family visit',
                              can_send: true,
                              can_call: true,
                          },
                          family: true,
                          callbacks: false,
                      },
                  })
                : fallback(path, options),
        );
        render(<View />);
        await screen.findByRole('button', { name: /^Answer$/ });
        calls = [];
        await act(async () => {
            await state.poll?.();
        });
        await screen.findByRole('heading', { name: 'Missed call' });
        expect(
            screen.queryByRole('button', { name: 'Record communication' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Send a message' }));
        await screen.findByText('Family call conversation fixture');
        expect(state.rooms).toHaveLength(0);
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(
                    ([path, options]) =>
                        path.endsWith('/calls') && options?.method === 'POST',
                ),
        ).toBe(false);
    });

    it.each(['Call again', 'incoming answer'])(
        'discards an old result after %s and does not revive it during a pending leave',
        async (source) => {
            currentCall = { ...caller('incoming', 22), my_status: 'invited' };
            calls = [currentCall];
            render(<View />);
            await screen.findByRole('button', { name: /^Answer$/ });
            calls = [];
            await act(async () => {
                await state.poll?.();
            });
            await screen.findByRole('button', { name: 'Call again' });
            if (source === 'Call again') {
                fireEvent.click(
                    screen.getByRole('button', { name: 'Call again' }),
                );
                fireEvent.click(
                    await screen.findByRole('checkbox', { name: /Jo Example/ }),
                );
                currentCall = caller('second-call');
                fireEvent.click(
                    screen.getByRole('button', { name: 'Check microphone' }),
                );
                await screen.findByRole('button', { name: 'Check again' });
                fireEvent.click(screen.getByRole('button', { name: /^Call$/ }));
            } else {
                currentCall = {
                    ...caller('second-call', 22),
                    my_status: 'invited',
                };
                calls = [currentCall];
                await act(async () => {
                    await state.poll?.();
                });
                fireEvent.click(
                    await screen.findByRole('button', { name: 'Answer' }),
                );
            }
            await screen.findByRole('region', { name: 'Ongoing voice call' });
            await waitFor(() =>
                expect(
                    screen.getByRole('button', { name: 'Leave' }),
                ).toBeEnabled(),
            );
            const pending = deferred<object>();
            const fallback = vi.mocked(chatRequest).getMockImplementation()!;
            vi.mocked(chatRequest).mockImplementation((path, options) =>
                path.endsWith('/leave')
                    ? pending.promise
                    : fallback(path, options),
            );
            fireEvent.click(screen.getByRole('button', { name: 'Leave' }));
            expect(
                screen.queryByRole('region', { name: 'Call result' }),
            ).not.toBeInTheDocument();
            calls = [];
            await act(async () => pending.resolve({}));
            await screen.findByRole('heading', { name: 'Call ended' });
            expect(
                screen.getByRole('region', { name: 'Call result' }),
            ).toHaveTextContent('Conversation second-call');
            expect(state.rooms).toHaveLength(1);
        },
    );

    it('distinguishes an invitation answered elsewhere and does not offer a duplicate call', async () => {
        currentCall = { ...caller('incoming', 22), my_status: 'invited' };
        calls = [currentCall];
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) =>
            path.includes('/outcome?')
                ? Promise.resolve({
                      call: {
                          ...currentCall,
                          outcome: 'answered_on_another_device',
                      },
                      followup: {
                          conversation: {
                              id: 17,
                              title: 'Team',
                              can_send: true,
                              can_call: true,
                          },
                          family: false,
                          callbacks: false,
                      },
                  })
                : fallback(path, options),
        );
        render(<View />);
        await screen.findByRole('button', { name: /^Answer$/ });
        calls = [{ ...currentCall, my_status: 'accepted' }];
        await act(async () => {
            await state.poll?.();
        });
        await screen.findByRole('heading', {
            name: 'Answered on another device',
        });
        expect(
            screen.queryByRole('button', { name: 'Call again' }),
        ).not.toBeInTheDocument();
        expect(state.rooms).toHaveLength(0);
    });

    it('recovers an interrupted start by checking first and explicitly joining the original call', async () => {
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) => {
            if (path === '/chat/api/conversations/17/calls')
                return Promise.reject(new Error('Response lost'));
            if (path.includes('/call-attempts/'))
                return Promise.resolve({
                    attempt_state: 'started',
                    can_join: true,
                    call: currentCall,
                });
            return fallback(path, options);
        });
        render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await screen.findByText('Call confirmation interrupted');
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Check call' }),
            ).toBeEnabled(),
        );
        expect(state.rooms).toHaveLength(0);
        const releaseNote = claimChatMicrophone('voice_note');
        releaseNote();
        fireEvent.click(screen.getByRole('button', { name: 'Check call' }));
        await screen.findByText('Your call was created');
        expect(state.rooms).toHaveLength(0);
        fireEvent.click(
            screen.getByRole('button', { name: 'Join existing call' }),
        );
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.filter(
                    ([path]) => path === '/chat/api/conversations/17/calls',
                ),
        ).toHaveLength(1);
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.filter(([path]) =>
                    path.includes('/call-attempts/'),
                ),
        ).toHaveLength(2);
    });

    it('releases a stalled start into recovery after the deadline without automatically ringing again', async () => {
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) =>
            path === '/chat/api/conversations/17/calls'
                ? new Promise((_resolve, reject) =>
                      options?.signal?.addEventListener(
                          'abort',
                          () =>
                              reject(
                                  new DOMException('Timed out', 'AbortError'),
                              ),
                          { once: true },
                      ),
                  )
                : fallback(path, options),
        );
        vi.useFakeTimers();
        try {
            render(<View />);
            fireEvent.click(screen.getByText('Start fixture call'));
            await act(async () => undefined);
            expect(
                screen.getByRole('button', { name: 'Check call' }),
            ).toBeDisabled();
            await act(async () => vi.advanceTimersByTimeAsync(30000));
            expect(
                screen.getByRole('button', { name: 'Check call' }),
            ).toBeEnabled();
            expect(
                screen.getByText('Call confirmation interrupted'),
            ).toBeInTheDocument();
            expect(
                vi
                    .mocked(chatRequest)
                    .mock.calls.filter(
                        ([path]) => path === '/chat/api/conversations/17/calls',
                    ),
            ).toHaveLength(1);
            const event = new Event('beforeunload', { cancelable: true });
            window.dispatchEvent(event);
            expect(event.defaultPrevented).toBe(true);
            expect(state.rooms).toHaveLength(0);
            const release = claimChatMicrophone('voice_note');
            release();
        } finally {
            vi.useRealTimers();
        }
    });

    it.each(['start', 'join'])(
        'preserves selected audio after an interrupted %s response',
        async (stage) => {
            let interrupted = false;
            const fallback = vi.mocked(chatRequest).getMockImplementation()!;
            vi.mocked(chatRequest).mockImplementation((path, options) => {
                if (
                    !interrupted &&
                    (stage === 'start'
                        ? path === '/chat/api/conversations/17/calls'
                        : path.endsWith('/join'))
                ) {
                    interrupted = true;
                    return Promise.reject(new Error('Lost response'));
                }
                if (path.includes('/outcome?'))
                    return Promise.resolve({
                        call: { ...currentCall, outcome: 'ongoing' },
                    });
                return fallback(path, options);
            });
            render(<View />);
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Start device fixture call',
                }),
            );
            if (stage === 'join') {
                fireEvent.click(
                    await screen.findByRole('button', { name: 'Check call' }),
                );
                fireEvent.click(
                    await screen.findByRole('button', {
                        name: 'Try connecting',
                    }),
                );
            } else {
                fireEvent.click(
                    await screen.findByRole('button', {
                        name: 'Retry same call',
                    }),
                );
            }
            await waitFor(() => expect(state.rooms).toHaveLength(1));
            expect(state.roomOptions[0]).toMatchObject({
                audioCaptureDefaults: { deviceId: { exact: 'usb' } },
                audioOutput: { deviceId: 'headset' },
            });
            for (const [constraints] of vi.mocked(
                navigator.mediaDevices.getUserMedia,
            ).mock.calls)
                expect(constraints).toEqual({
                    audio: { deviceId: { exact: 'usb' } },
                    video: false,
                });
            const bodies = vi
                .mocked(chatRequest)
                .mock.calls.filter(
                    ([path]) => path === '/chat/api/conversations/17/calls',
                )
                .map(([, options]) => String(options?.body));
            expect(bodies[0]).not.toContain('headset');
            expect(bodies[0]).not.toContain('usb');
        },
    );

    it('retries exactly the original start body and prevents another outgoing call while uncertain', async () => {
        let attempts = 0;
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) =>
            path === '/chat/api/conversations/17/calls' && ++attempts === 1
                ? Promise.reject(new Error('Lost'))
                : fallback(path, options),
        );
        render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await screen.findByText('Call confirmation interrupted');
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Retry same call' }),
            ).toBeEnabled(),
        );
        fireEvent.click(screen.getByText('Start fixture call'));
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.filter(
                    ([path]) => path === '/chat/api/conversations/17/calls',
                ),
        ).toHaveLength(1);
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry same call' }),
        );
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        const starts = vi
            .mocked(chatRequest)
            .mock.calls.filter(
                ([path]) => path === '/chat/api/conversations/17/calls',
            );
        expect(starts).toHaveLength(2);
        expect(starts[1][1]?.body).toBe(starts[0][1]?.body);
    });

    it('retains cancellation intent after an uncertain stop and never offers to join it', async () => {
        let stops = 0;
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) => {
            if (path === '/chat/api/conversations/17/calls')
                return Promise.reject(new Error('Response lost'));
            if (path.endsWith('/cancel'))
                return ++stops === 1
                    ? Promise.reject(new Error('Stop response lost'))
                    : Promise.resolve({
                          attempt_state: 'cancelled',
                          can_join: false,
                          call: null,
                      });
            if (path.includes('/call-attempts/'))
                return Promise.resolve({
                    attempt_state: 'started',
                    can_join: true,
                    call: currentCall,
                });
            return fallback(path, options);
        });
        render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Stop my attempt' }),
            ).toBeEnabled(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Stop my attempt' }),
        );
        await screen.findByText(/The server has not confirmed/);
        fireEvent.click(screen.getByRole('button', { name: 'Check call' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Retry stopping' }),
            ).toBeEnabled(),
        );
        expect(
            screen.queryByRole('button', { name: 'Join existing call' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Retry stopping' }));
        await screen.findByText('Call attempt stopped');
        const commands = vi
            .mocked(chatRequest)
            .mock.calls.filter(([path]) => path.endsWith('/cancel'));
        expect(commands[0][1]?.body).toBe(commands[1][1]?.body);
        expect(state.rooms).toHaveLength(0);
        fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }));
        expect(
            screen.queryByRole('region', { name: 'Call recovery' }),
        ).not.toBeInTheDocument();
    });

    it('does not join or create another call when a recovered attempt has finished', async () => {
        vi.mocked(chatRequest).mockImplementation(async (path) =>
            path === '/chat/api/conversations/17/calls'
                ? {
                      attempt_state: 'finished',
                      can_join: false,
                      call: currentCall,
                  }
                : status([]),
        );
        render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await screen.findByText('This call attempt has finished');
        expect(state.rooms).toHaveLength(0);
        expect(
            screen.queryByRole('button', { name: 'Join existing call' }),
        ).not.toBeInTheDocument();
    });

    it('does not join a recovered call whose returned conversation differs from the original request', async () => {
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) =>
            path === '/chat/api/conversations/17/calls'
                ? Promise.resolve({
                      attempt_state: 'started',
                      can_join: true,
                      call: { ...currentCall, conversation_id: 999 },
                  })
                : fallback(path, options),
        );
        render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Check call' }),
            ).toBeEnabled(),
        );
        expect(state.rooms).toHaveLength(0);
        expect(
            screen.getByText('Call confirmation interrupted'),
        ).toBeInTheDocument();
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(([path]) => path.endsWith('/join')),
        ).toBe(false);
    });

    it('reviews a missing microphone from the compact call without unmuting or joining again', async () => {
        let available = true;
        const devices = new EventTarget();
        Object.assign(navigator.mediaDevices, {
            enumerateDevices: vi.fn(async () =>
                available
                    ? [
                          {
                              kind: 'audioinput',
                              deviceId: 'default',
                              label: 'Microphone',
                          },
                      ]
                    : [],
            ),
            addEventListener: devices.addEventListener.bind(devices),
            removeEventListener: devices.removeEventListener.bind(devices),
        });
        render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await waitFor(() =>
            expect(screen.getByRole('button', { name: 'Mute' })).toBeEnabled(),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Mute' }));
        await screen.findByRole('button', { name: 'Unmute' });
        const session = state.rooms[0];
        session.localParticipant.setMicrophoneEnabled.mockClear();
        available = false;
        await act(async () => devices.dispatchEvent(new Event('devicechange')));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Review audio' }),
        );
        await screen.findByRole('dialog', { name: 'Audio and connection' });
        await waitFor(() =>
            expect(screen.getByLabelText('Microphone')).toBeDisabled(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Close audio settings' }),
        );
        expect(
            await screen.findByRole('button', { name: 'Unmute microphone' }),
        ).toHaveAttribute('aria-pressed', 'true');
        expect(
            session.localParticipant.setMicrophoneEnabled,
        ).not.toHaveBeenCalled();
        expect(session.switchActiveDevice).not.toHaveBeenCalled();
        expect(state.rooms).toHaveLength(1);
    });

    it('keeps mute when switching headsets and stops a late device change after account teardown', async () => {
        Object.assign(navigator.mediaDevices, {
            enumerateDevices: vi.fn(async () => [
                {
                    kind: 'audioinput',
                    deviceId: 'default',
                    label: 'System microphone',
                },
                { kind: 'audioinput', deviceId: 'usb', label: 'USB headset' },
            ]),
        });
        const view = render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await waitFor(() =>
            expect(screen.getByRole('button', { name: 'Mute' })).toBeEnabled(),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Mute' }));
        await screen.findByRole('button', { name: 'Unmute' });
        fireEvent.click(
            screen.getByRole('button', { name: 'Expand current call' }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Audio devices' }),
        );
        await waitFor(() =>
            expect(screen.getByLabelText('Microphone')).toBeEnabled(),
        );
        const session = state.rooms[0];
        session.localParticipant.setMicrophoneEnabled.mockClear();
        fireEvent.keyDown(screen.getByLabelText('Microphone'), {
            key: 'Enter',
        });
        fireEvent.click(screen.getByRole('option', { name: 'USB headset' }));
        await screen.findByText(/Microphone changed/);
        expect(session.switchActiveDevice).toHaveBeenCalledWith(
            'audioinput',
            'usb',
        );
        expect(
            session.localParticipant.setMicrophoneEnabled,
        ).not.toHaveBeenCalled();
        const pending = deferred<boolean>();
        session.switchActiveDevice.mockImplementationOnce(
            () => pending.promise,
        );
        fireEvent.keyDown(screen.getByLabelText('Microphone'), {
            key: 'Enter',
        });
        fireEvent.click(
            screen.getByRole('option', { name: 'System microphone' }),
        );
        view.rerender(<View actor={33} />);
        const track =
            session.localParticipant.audioTrackPublications.get('mic')!.track;
        track.stop.mockClear();
        await act(async () => pending.resolve(true));
        expect(track.stop).toHaveBeenCalled();
        expect(
            screen.queryByRole('dialog', { name: 'Audio and connection' }),
        ).not.toBeInTheDocument();
        expect(
            session.localParticipant.setMicrophoneEnabled,
        ).not.toHaveBeenCalled();
    });

    it('keeps a microphone-off retry when the server cannot confirm leaving', async () => {
        let attempts = 0;
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) =>
            path.endsWith('/leave') && ++attempts === 1
                ? Promise.reject(new Error('Connection interrupted'))
                : fallback(path, options),
        );
        render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        await waitFor(() =>
            expect(screen.getByRole('button', { name: 'Leave' })).toBeEnabled(),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Leave' }));
        await screen.findByText(
            'Audio is off. Retry leaving to confirm the call ended.',
        );
        expect(screen.getByRole('button', { name: 'Mute' })).toBeDisabled();
        expect(screen.queryByText('Record for call-a')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Leave' }));
        await screen.findByRole('button', { name: 'Record communication' });
        expect(screen.queryByText('Record for call-a')).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Record communication' }),
        );
        await screen.findByText('Record for call-a');
        expect(
            screen.queryByRole('region', { name: 'Ongoing voice call' }),
        ).not.toBeInTheDocument();
    });
    it('restores a lost start after remount without ringing or capturing, and reviews current recipients before retry', async () => {
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) => {
            if (path.endsWith('/calls') && options?.method === 'POST')
                return Promise.reject(new Error('lost response'));
            if (path.includes('/call-attempts/'))
                return Promise.resolve({
                    attempt_state: 'unconfirmed',
                    can_join: false,
                    call: null,
                });
            return fallback(path, options);
        });
        const view = render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await screen.findByText('Call confirmation interrupted');
        const stored = JSON.parse(localStorage.getItem(callRecoveryKey(11))!);
        expect(stored.kind).toBe('start');
        expect(stored).not.toHaveProperty('title');
        view.unmount();
        await act(async () => undefined);
        vi.mocked(chatRequest).mockClear();
        vi.mocked(navigator.mediaDevices.getUserMedia).mockClear();
        render(<View />);
        await screen.findByText('Previous voice call');
        expect(
            screen.getByRole('button', { name: 'Retry same call' }),
        ).toBeDisabled();
        expect(navigator.mediaDevices.getUserMedia).not.toHaveBeenCalled();
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(([, options]) => options?.method === 'POST'),
        ).toBe(false);
        fireEvent.click(screen.getByRole('button', { name: 'Check call' }));
        await screen.findByText('Retry calls: Jo Example.');
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry same call' }),
        );
        await waitFor(() =>
            expect(
                vi
                    .mocked(chatRequest)
                    .mock.calls.some(
                        ([path, options]) =>
                            path.endsWith('/calls') &&
                            options?.method === 'POST',
                    ),
            ).toBe(true),
        );
        const retried = vi
            .mocked(chatRequest)
            .mock.calls.find(
                ([path, options]) =>
                    path.endsWith('/calls') && options?.method === 'POST',
            )!;
        expect(JSON.parse(String(retried[1]?.body))).toMatchObject({
            mutation_id: stored.mutation,
            device_id: stored.device,
            participant_ids: [22],
        });
    });
    it('restores connected calls with the same device but requires explicit checking and joining', async () => {
        const view = render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        await waitFor(() =>
            expect(screen.getByRole('button', { name: 'Leave' })).toBeEnabled(),
        );
        const stored = JSON.parse(localStorage.getItem(callRecoveryKey(11))!);
        expect(stored.kind).toBe('call');
        expect(stored).not.toHaveProperty('token');
        view.unmount();
        await act(async () => undefined);
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) =>
            path.includes('/outcome?')
                ? Promise.resolve({
                      call: { ...currentCall, outcome: 'ongoing' },
                  })
                : fallback(path, options),
        );
        vi.mocked(chatRequest).mockClear();
        vi.mocked(navigator.mediaDevices.getUserMedia).mockClear();
        render(<View />);
        await screen.findByText('Previous voice call');
        expect(navigator.mediaDevices.getUserMedia).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('button', { name: 'Try connecting' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Check call' }));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Try connecting' }),
        );
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        const joined = vi
            .mocked(chatRequest)
            .mock.calls.find(([path]) => path.endsWith('/join'))!;
        expect(JSON.parse(String(joined[1]?.body))).toMatchObject({
            device_id: stored.device,
            invitation_id: stored.invitation,
        });
    });
    it('preserves an unconfirmed end-for-everyone through reload and never offers reconnect', async () => {
        const key = callRecoveryKey(11);
        const device = crypto.randomUUID();
        localStorage.setItem(
            key,
            JSON.stringify({
                version: 1,
                actor: 11,
                device,
                conversation: 17,
                kind: 'call',
                call: currentCall.id,
                invitation: currentCall.my_invitation_id,
                stopping: 'end',
            }),
        );
        render(<View />);
        await screen.findByText('Previous voice call');
        expect(
            screen.queryByRole('button', { name: 'Try connecting' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry ending call' }),
        );
        await waitFor(() => expect(localStorage.getItem(key)).toBeNull());
        const left = vi
            .mocked(chatRequest)
            .mock.calls.find(([path]) => path.endsWith('/leave'))!;
        expect(JSON.parse(String(left[1]?.body))).toMatchObject({
            device_id: device,
            end_for_everyone: true,
        });
        expect(navigator.mediaDevices.getUserMedia).not.toHaveBeenCalled();
    });
    it('prevents a second provider from joining the owner tab and recovers after that tab closes', async () => {
        const first = render(<View />);
        fireEvent.click(screen.getByText('Start fixture call'));
        await screen.findByRole('region', { name: 'Ongoing voice call' });
        await waitFor(() =>
            expect(screen.getByRole('button', { name: 'Leave' })).toBeEnabled(),
        );
        const captures = vi.mocked(navigator.mediaDevices.getUserMedia).mock
            .calls.length;
        const second = render(<View />);
        await within(second.container).findByText('Call open in another tab');
        expect(navigator.mediaDevices.getUserMedia).toHaveBeenCalledTimes(
            captures,
        );
        first.unmount();
        await act(async () => undefined);
        fireEvent.click(
            within(second.container).getByRole('button', {
                name: 'Check again',
            }),
        );
        await screen.findByText('Previous voice call');
        expect(navigator.mediaDevices.getUserMedia).toHaveBeenCalledTimes(
            captures,
        );
    });
    it('keeps corrupt storage unchanged and blocks call dispatch', async () => {
        localStorage.setItem(callRecoveryKey(11), 'broken');
        render(<View />);
        await screen.findByText('Call recovery unavailable');
        fireEvent.click(screen.getByText('Start fixture call'));
        await act(async () => undefined);
        expect(navigator.mediaDevices.getUserMedia).not.toHaveBeenCalled();
        expect(localStorage.getItem(callRecoveryKey(11))).toBe('broken');
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(([, options]) => options?.method === 'POST'),
        ).toBe(false);
    });
    it('keeps another account recovery private and never reuses its device', async () => {
        const foreign = {
            version: 1,
            actor: 33,
            device: crypto.randomUUID(),
            conversation: 88,
            kind: 'call',
            call: 'private-call',
            invitation: 'private-invitation',
            stopping: 'none',
        };
        localStorage.setItem(callRecoveryKey(33), JSON.stringify(foreign));
        render(<View />);
        await act(async () => undefined);
        expect(
            screen.queryByText('Previous voice call'),
        ).not.toBeInTheDocument();
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(
                    ([path]) =>
                        path.includes('private-call') ||
                        path.includes(foreign.device),
                ),
        ).toBe(false);
        expect(localStorage.getItem(callRecoveryKey(33))).toBe(
            JSON.stringify(foreign),
        );
    });
    it('retires a server-confirmed replaced invitation without touching the replacement', async () => {
        const key = callRecoveryKey(11);
        localStorage.setItem(
            key,
            JSON.stringify({
                version: 1,
                actor: 11,
                device: crypto.randomUUID(),
                conversation: 17,
                kind: 'call',
                call: currentCall.id,
                invitation: 'old-invitation',
                stopping: 'none',
            }),
        );
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) =>
            path.includes('/outcome?')
                ? Promise.reject(
                      Object.assign(new Error('Invitation changed'), {
                          status: 409,
                      }),
                  )
                : fallback(path, options),
        );
        render(<View />);
        await screen.findByText('Previous voice call');
        fireEvent.click(screen.getByRole('button', { name: 'Check call' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Dismiss' }));
        expect(localStorage.getItem(key)).toBeNull();
        expect(navigator.mediaDevices.getUserMedia).not.toHaveBeenCalled();
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(
                    ([path]) =>
                        path.endsWith('/leave') || path.endsWith('/join'),
                ),
        ).toBe(false);
    });
    it('retains cancellation after remount and sends the original cancellation identity only', async () => {
        const key = callRecoveryKey(11);
        const device = crypto.randomUUID();
        const mutation = crypto.randomUUID();
        localStorage.setItem(
            key,
            JSON.stringify({
                version: 1,
                actor: 11,
                device,
                conversation: 17,
                kind: 'start',
                mutation,
                participants: [22],
                routing: 'invited',
                stopping: true,
            }),
        );
        const fallback = vi.mocked(chatRequest).getMockImplementation()!;
        vi.mocked(chatRequest).mockImplementation((path, options) =>
            path.endsWith('/cancel')
                ? Promise.resolve({
                      attempt_state: 'cancelled',
                      can_join: false,
                      call: null,
                  })
                : fallback(path, options),
        );
        render(<View />);
        await screen.findByText('Previous voice call');
        expect(
            screen.queryByRole('button', { name: 'Retry same call' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Retry stopping' }));
        await screen.findByText('Call attempt stopped');
        const cancel = vi
            .mocked(chatRequest)
            .mock.calls.find(([path]) => path.endsWith('/cancel'))!;
        expect(JSON.parse(String(cancel[1]?.body))).toMatchObject({
            device_id: device,
            mutation_id: mutation,
            participant_ids: [22],
        });
        expect(localStorage.getItem(key)).toBeNull();
        expect(navigator.mediaDevices.getUserMedia).not.toHaveBeenCalled();
    });
});
