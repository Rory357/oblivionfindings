import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Room } from 'livekit-client';
import { Clock, Phone, Users, X } from 'lucide-react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useImperativeHandle,
    useRef,
    useState,
    type ReactNode,
    type Ref,
} from 'react';
import { toast } from 'sonner';
import { ActiveCallCard } from './active-call-card';
import { chatRequest, setChatDraftActor, type ChatRequestOptions } from './api';
import type { CallAudioKind } from './call-audio-devices';
import {
    CallAudioPreflight,
    DEFAULT_CALL_AUDIO,
    microphoneConstraints,
    type CallAudioPreflightControls,
    type CallAudioSelection,
} from './call-audio-preflight';
import { CallConversationContext } from './call-conversation-context';
import { CallJoinRecovery } from './call-join-recovery';
import { useCallOutcome, type CallTarget } from './call-outcome';
import {
    CallProblemNotice,
    describeCallProblem,
    type CallProblem,
} from './call-problem';
import { CallResultCard } from './call-result-card';
import { CallStartRecovery } from './call-start-recovery';
import { CallSummary, type CallSummaryTarget } from './call-summary';
import { CHAT_VOICE_ROOM_OPTIONS } from './call-transport-options';
import { ChatCallDock } from './chat-call-dock';
import { ChatCallbacks } from './chat-callbacks';
import { claimChatMicrophone } from './chat-microphone';
import { useChatPreferences } from './chat-personal-preferences';
import { useChatRealtime } from './chat-realtime';
import { watchChatUpdates } from './chat-update-watch';
import { IncomingCallCard, type CallPresentation } from './incoming-call-card';
import { useCallRecovery } from './use-call-recovery';
import { useCallRingtone } from './use-call-ringtone';
import { useCallSilence } from './use-call-silence';

type Call = CallSummaryTarget & {
    id: string;
    conversation_id: number;
    client_id: number | null;
    title: string;
    presentation?: CallPresentation | null;
    status: string;
    host_id: number;
    started_by: number;
    connected_at: string | null;
    ended_at: string | null;
    ring_until: string;
    my_status: string;
    my_invitation_id?: string;
    max_participants?: number;
    participants: {
        id: number;
        name: string;
        status: string;
        invitation_id?: string;
    }[];
};
export type Availability =
    | 'available'
    | 'busy'
    | 'dnd'
    | 'off_duty'
    | 'unavailable';
type CallRouting = 'invited' | 'care_team';
type StartResult = {
    attempt_state: 'unconfirmed' | 'started' | 'finished' | 'cancelled';
    can_join: boolean;
    call: Call | null;
};
type PendingStart = {
    restored?: boolean;
    reviewed?: string;
    audio?: CallAudioSelection;
    actor: number;
    epoch: number;
    device: string;
    conversationId: number;
    title: string;
    mutation: string;
    body: string;
    cancelling: boolean;
    result: StartResult | null;
    error: string;
};
type CallOperation = {
    audio?: CallAudioSelection;
    actor: number;
    epoch: number;
    device: string;
    stage: 'permission' | 'request';
};
type PendingJoin = {
    restored?: boolean;
    retired?: boolean;
    audio?: CallAudioSelection;
    actor: number;
    epoch: number;
    device: string;
    call: Pick<Call, 'id' | 'conversation_id' | 'my_invitation_id' | 'title'>;
    endEveryone?: boolean;
    stopping: boolean;
    checked: boolean;
    closed: boolean;
    error: string;
};
type CallStatus = {
    unread_count?: number;
    ready: boolean;
    availability: Availability;
    can_set_off_duty?: boolean;
    sound_enabled: boolean;
    calls: Call[];
};
type CallingContext = {
    unreadCount: number | null;
    enabled: boolean;
    ready: boolean;
    availability: Availability;
    canSetOffDuty: boolean;
    active: boolean;
    permissionPending: boolean;
    cancelPreflight: () => boolean;
    soundEnabled: boolean;
    setSoundEnabled: (value: boolean) => Promise<void>;
    setAvailability: (availability: Availability) => Promise<void>;
    start: (
        conversation: CallTarget,
        participants: number[],
        routing?: CallRouting,
        audio?: CallAudioSelection,
    ) => Promise<void>;
};
const Context = createContext<CallingContext | null>(null);
export const useChatCalling = () => useContext(Context);

function releaseRoom(session: Room) {
    for (const publication of session.localParticipant.audioTrackPublications.values())
        publication.track?.stop();
    void session.disconnect().catch(() => undefined);
}

function microphoneError(error: unknown) {
    const failure = error as Error;
    if (['NotAllowedError', 'SecurityError'].includes(failure?.name))
        return 'Allow microphone access in your browser settings, then try again.';
    if (failure?.name === 'NotFoundError')
        return 'No microphone was found. Connect a microphone and try again.';
    if (failure?.name === 'NotReadableError')
        return 'Your microphone is in use or unavailable. Check other apps and try again.';
    return (
        failure?.message ||
        'Could not connect the microphone. Please try again.'
    );
}

async function checkMicrophone(selection?: CallAudioSelection) {
    if (!navigator.mediaDevices?.getUserMedia)
        throw new Error(
            'Voice calls need a secure browser with microphone support.',
        );
    const permission = await navigator.mediaDevices.getUserMedia(
        microphoneConstraints(selection),
    );
    permission.getTracks().forEach((track) => track.stop());
}

function lostCallAccess(error: unknown) {
    return [401, 403, 419].includes(
        (error as { status?: number })?.status ?? 0,
    );
}

export function ChatCallingProvider({
    actorId,
    enabled,
    children,
}: {
    actorId: number | null;
    enabled: boolean;
    children: ReactNode;
}) {
    // Capture the rendered actor, not a mutable global that can change while work is pending.
    const callRequest = useCallback(
        <T,>(path: string, options: ChatRequestOptions = {}) => {
            const headers = new Headers(options.headers);
            headers.set('X-Chat-Actor', String(actorId ?? ''));
            return chatRequest<T>(path, { ...options, headers });
        },
        [actorId],
    );
    const realtime = useChatRealtime();
    const realtimeRef = useRef(realtime);
    useEffect(() => {
        realtimeRef.current = realtime;
    }, [realtime]);
    const [status, setStatus] = useState<CallStatus>({
        ready: false,
        availability: 'unavailable',
        sound_enabled: true,
        calls: [],
    });
    const statusRef = useRef(status);
    useEffect(() => {
        statusRef.current = status;
    }, [status]);
    const [active, setActive] = useState<Call | null>(null);
    const [phase, setPhase] = useState('');
    const [muted, setMuted] = useState(false);
    const [speakers, setSpeakers] = useState<string[]>([]);
    const [audioRoom, setAudioRoom] = useState<Room | null>(null);
    const [pendingStart, setPendingStart] = useState<PendingStart | null>(null);
    const pendingStartRef = useRef<PendingStart | null>(null);
    const startController = useRef<AbortController | null>(null);
    const [pendingJoin, setPendingJoin] = useState<PendingJoin | null>(null);
    const pendingJoinRef = useRef<PendingJoin | null>(null);
    const joinController = useRef<AbortController | null>(null);
    const [microphoneWorking, setMicrophoneWorking] = useState(false);
    const [working, setWorking] = useState(false);
    const [permissionPending, setPermissionPending] = useState(false);
    const [incomingProblem, setIncomingProblem] = useState<{
        actor: number;
        callId: string;
        invitation?: string;
        problem: CallProblem;
    } | null>(null);
    const [audioBlocked, setAudioBlocked] = useState(false);
    const [endReview, setEndReview] = useState(false);
    const [expanded, setExpanded] = useState(false);
    const [conversationOpen, setConversationOpen] = useState(false);
    const [summaryTarget, setSummaryTarget] =
        useState<CallSummaryTarget | null>(null);
    const [elapsed, setElapsed] = useState(0);
    const recovery = useCallRecovery(actorId, enabled);
    const outcome = useCallOutcome(actorId, enabled);
    const showOutcome = outcome.show;
    const clearOutcome = outcome.clear;
    const observedCalls = useRef<Call[]>([]);
    useEffect(() => {
        setChatDraftActor(actorId);
    }, [actorId]);
    const room = useRef<Room | null>(null);
    const releaseMicrophone = useRef<(() => void) | null>(null);
    const audioRoot = useRef<HTMLDivElement>(null);
    const activeRef = useRef<Call | null>(null);
    const device = useRef<string>(crypto.randomUUID());
    const stopped = useRef(false);
    const sessionGeneration = useRef(0);
    const callGeneration = useRef(0);
    const refreshSequence = useRef(0);
    const operation = useRef<CallOperation | null>(null);
    const microphoneRequest = useRef(0);
    const microphoneBusy = useRef(false);
    const statusActor = useRef<number | null>(null);
    const activeActor = useRef<number | null>(null);
    const summaryActor = useRef<number | null>(null);
    const currentActor = useRef(actorId);
    const currentEnabled = useRef(enabled);
    currentActor.current = actorId;
    currentEnabled.current = enabled;
    activeRef.current = active;
    const incoming =
        enabled && actorId && statusActor.current === actorId
            ? status.calls.find((call) => call.my_status === 'invited')
            : undefined;
    const invitationKey = incoming
        ? `${incoming.id}:${incoming.my_invitation_id ?? 'initial'}`
        : null;
    const callSilence = useCallSilence(actorId, invitationKey);
    const personalPreferences = useChatPreferences(actorId ?? undefined);
    const ringtone = useCallRingtone(
        actorId,
        !active && !pendingStart && !pendingJoin && recovery.status === 'ready'
            ? invitationKey
            : null,
        status.sound_enabled &&
            (personalPreferences.actorId !== actorId ||
                personalPreferences.loaded) &&
            (!personalPreferences.loaded ||
                personalPreferences.values.sound_enabled) &&
            status.availability === 'available' &&
            !personalPreferences.values.quiet_enabled &&
            !callSilence.silent,
    );

    function updatePendingStart(next: PendingStart | null) {
        pendingStartRef.current = next;
        setPendingStart(next);
    }
    function updatePendingJoin(next: PendingJoin | null) {
        pendingJoinRef.current = next;
        setPendingJoin(next);
    }

    async function joinRequest<T>(
        path: string,
        options: ChatRequestOptions = {},
    ): Promise<T> {
        const controller = new AbortController();
        joinController.current = controller;
        const deadline = window.setTimeout(() => controller.abort(), 30000);
        try {
            return await callRequest<T>(path, {
                ...options,
                signal: controller.signal,
            });
        } finally {
            window.clearTimeout(deadline);
            if (joinController.current === controller)
                joinController.current = null;
        }
    }

    const stopAudio = useCallback(() => {
        const current = room.current;
        room.current = null;
        setAudioRoom(null);
        callGeneration.current++;
        activeActor.current = null;
        // Stop capture synchronously. A delayed SDK disconnect must never clear
        // a subsequent account's room, controls or audio elements.
        if (current) releaseRoom(current);
        releaseMicrophone.current?.();
        releaseMicrophone.current = null;
        microphoneRequest.current++;
        microphoneBusy.current = false;
        setMicrophoneWorking(false);
        audioRoot.current?.replaceChildren();
        setActive(null);
        activeRef.current = null;
        setPhase('');
        setMuted(false);
        setSpeakers([]);
        setAudioBlocked(false);
        setExpanded(false);
        setConversationOpen(false);
        setEndReview(false);
    }, []);
    const refresh = useCallback(async () => {
        if (!enabled || !actorId) return;
        const epoch = sessionGeneration.current;
        const callEpoch = callGeneration.current;
        const sequence = ++refreshSequence.current;
        const isCurrent = () =>
            !stopped.current &&
            currentEnabled.current &&
            currentActor.current === actorId &&
            sessionGeneration.current === epoch &&
            callGeneration.current === callEpoch &&
            refreshSequence.current === sequence;
        let next: CallStatus;
        try {
            next = await callRequest<CallStatus>(
                `/chat/api/calls?device_id=${device.current}`,
            );
        } catch (error) {
            if (isCurrent() && lostCallAccess(error)) {
                stopAudio();
                operation.current = null;
                pendingStartRef.current = null;
                setPendingStart(null);
                startController.current?.abort();
                joinController.current?.abort();
                pendingJoinRef.current = null;
                setPendingJoin(null);
                statusActor.current = null;
                summaryActor.current = null;
                setWorking(false);
                setPermissionPending(false);
                setSummaryTarget(null);
                clearOutcome();
                observedCalls.current = [];
                setStatus({
                    ready: false,
                    availability: 'unavailable',
                    sound_enabled: true,
                    calls: [],
                });
            }
            throw error;
        }
        if (!isCurrent()) return;
        statusActor.current = actorId;
        const previousCalls = observedCalls.current;
        observedCalls.current = next.calls;
        setStatus(next);
        if (activeRef.current) {
            const previousActive = activeRef.current;
            const ongoing = next.calls.find(
                (call) => call.id === activeRef.current?.id,
            );
            if (
                !ongoing ||
                !['accepted', 'connected'].includes(ongoing.my_status) ||
                ongoing.my_invitation_id !== activeRef.current.my_invitation_id
            ) {
                await stopAudio();
                if (!operation.current)
                    void showOutcome({
                        ...previousActive,
                        device: device.current,
                    });
            } else {
                setActive(ongoing);
                if (room.current?.state === 'connected')
                    setPhase((current) =>
                        current.startsWith('Connection interrupted')
                            ? 'Connected'
                            : current,
                    );
            }
        } else if (
            !operation.current &&
            !pendingStartRef.current &&
            !pendingJoinRef.current
        ) {
            const closed = previousCalls.find(
                (previous) =>
                    previous.my_status === 'invited' &&
                    !next.calls.some(
                        (current) =>
                            current.id === previous.id &&
                            current.my_status === 'invited' &&
                            current.my_invitation_id ===
                                previous.my_invitation_id,
                    ),
            );
            if (closed) void showOutcome({ ...closed, device: device.current });
        }
    }, [enabled, actorId, stopAudio, callRequest, showOutcome, clearOutcome]);
    useEffect(() => {
        stopped.current = false;
        const generation = ++sessionGeneration.current;
        operation.current = null;
        pendingStartRef.current = null;
        setPendingStart(null);
        startController.current?.abort();
        joinController.current?.abort();
        pendingJoinRef.current = null;
        setPendingJoin(null);
        statusActor.current = null;
        observedCalls.current = [];
        summaryActor.current = null;
        device.current = crypto.randomUUID();
        setWorking(false);
        setPermissionPending(false);
        setIncomingProblem(null);
        setStatus({
            ready: false,
            availability: 'unavailable',
            sound_enabled: true,
            calls: [],
        });
        setSummaryTarget(null);
        setEndReview(false);
        if (!enabled || !actorId) return;
        const poll = async () => {
            try {
                await refresh();
                return true;
            } catch {
                if (
                    activeRef.current &&
                    currentActor.current === actorId &&
                    sessionGeneration.current === generation
                )
                    setPhase('Connection interrupted. Checking the call…');
                return false;
            }
        };
        const stopUpdates = watchChatUpdates(poll, {
            // Every open tab runs this provider and shares the Chat request limit:
            // poll quickly only while a call needs it, otherwise back off.
            interval: () => {
                if (activeRef.current || statusRef.current.calls.length > 0)
                    return 3000;
                // A call rings for 40 s and recently hidden tabs can still be called.
                if (document.visibilityState !== 'visible') return 10000;
                if (!statusRef.current.ready) return 30000;
                return realtimeRef.current === 'live' ? 15000 : 5000;
            },
            whenHidden: true,
            events: ['oblivion:chat-refresh', 'oblivion:chat-unread-changed'],
        });
        const heartbeat = window.setInterval(() => {
            if (document.visibilityState === 'visible')
                void callRequest('/chat/api/availability', {
                    method: 'PATCH',
                    body: '{}',
                }).catch(() => undefined);
        }, 15000);
        return () => {
            stopped.current = true;
            sessionGeneration.current = generation + 1;
            operation.current = null;
            pendingStartRef.current = null;
            startController.current?.abort();
            joinController.current?.abort();
            pendingJoinRef.current = null;
            stopUpdates();
            clearInterval(heartbeat);
            void stopAudio();
        };
    }, [enabled, actorId, refresh, stopAudio, callRequest]);
    const restoredRecord = recovery.restored;
    useEffect(() => {
        if (
            !enabled ||
            !actorId ||
            !restoredRecord ||
            restoredRecord.actor !== actorId
        )
            return;
        if (
            operation.current ||
            activeRef.current ||
            pendingStartRef.current ||
            pendingJoinRef.current
        )
            return;
        device.current = restoredRecord.device;
        const base = {
            actor: actorId,
            epoch: sessionGeneration.current,
            device: restoredRecord.device,
            error: '',
        };
        if (restoredRecord.kind === 'start') {
            updatePendingStart({
                ...base,
                restored: true,
                conversationId: restoredRecord.conversation,
                title: 'Previous voice call',
                mutation: restoredRecord.mutation,
                body: JSON.stringify({
                    mutation_id: restoredRecord.mutation,
                    device_id: restoredRecord.device,
                    participant_ids: restoredRecord.participants,
                    routing_mode: restoredRecord.routing,
                }),
                cancelling: restoredRecord.stopping,
                result: null,
            });
        } else {
            updatePendingJoin({
                ...base,
                call: {
                    id: restoredRecord.call,
                    conversation_id: restoredRecord.conversation,
                    my_invitation_id: restoredRecord.invitation,
                    title: 'Previous voice call',
                },
                stopping: restoredRecord.stopping !== 'none',
                endEveryone: restoredRecord.stopping === 'end',
                restored: true,
                checked: false,
                closed: false,
            });
        }
    }, [actorId, enabled, restoredRecord, working]);
    const clearRecovery = recovery.clear;
    const releaseIdleRecovery = recovery.releaseIdle;
    useEffect(() => {
        const result = outcome.state;
        if (
            !result?.data ||
            result.data.call.outcome === 'ongoing' ||
            !result.data.call.outcome
        )
            return;
        clearRecovery(
            (record) =>
                record.kind === 'call' &&
                record.call === result.target.id &&
                record.conversation === result.target.conversation_id &&
                record.device === result.target.device &&
                record.invitation === result.target.my_invitation_id,
        );
        releaseIdleRecovery();
    }, [outcome.state, clearRecovery, releaseIdleRecovery]);
    const activeId = active?.id;
    const unresolvedStart =
        !!pendingStart &&
        !['finished', 'cancelled'].includes(
            pendingStart.result?.attempt_state ?? '',
        );
    useEffect(() => {
        if (
            !unresolvedStart &&
            (!pendingJoin || pendingJoin.closed) &&
            (!working || permissionPending)
        )
            return;
        const warn = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [unresolvedStart, pendingJoin, working, permissionPending]);
    const connectedAt = active?.connected_at;
    useEffect(() => {
        if (!activeId) return;
        const update = () =>
            setElapsed(
                connectedAt
                    ? Math.max(
                          0,
                          Math.floor(
                              (Date.now() - Date.parse(connectedAt)) / 1000,
                          ),
                      )
                    : 0,
            );
        update();
        const timer = window.setInterval(update, 1000);
        const warn = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', warn);
        return () => {
            clearInterval(timer);
            window.removeEventListener('beforeunload', warn);
        };
    }, [activeId, connectedAt]);

    function ownsWork(work: CallOperation) {
        return (
            !stopped.current &&
            currentEnabled.current &&
            currentActor.current === work.actor &&
            sessionGeneration.current === work.epoch &&
            operation.current === work
        );
    }
    function beginWork(
        stage: CallOperation['stage'] = 'request',
    ): CallOperation | null {
        if (
            !enabled ||
            !actorId ||
            stopped.current ||
            operation.current ||
            currentActor.current !== actorId
        )
            return null;
        const work = {
            actor: actorId,
            epoch: sessionGeneration.current,
            device: device.current,
            stage,
        };
        operation.current = work;
        setWorking(true);
        setPermissionPending(stage === 'permission');
        return work;
    }
    async function acquireWork(work: CallOperation) {
        const acquired = await recovery.acquire();
        if (!ownsWork(work)) {
            recovery.releaseIdle();
            return false;
        }
        return acquired;
    }
    function finishWork(work: CallOperation) {
        if (!ownsWork(work)) return;
        if (!activeRef.current) {
            releaseMicrophone.current?.();
            releaseMicrophone.current = null;
        }
        operation.current = null;
        recovery.releaseIdle();
        setWorking(false);
        setPermissionPending(false);
    }
    function cancelPreflight() {
        const work = operation.current;
        if (!work || !ownsWork(work) || work.stage !== 'permission')
            return false;
        joinController.current?.abort();
        operation.current = null;
        callGeneration.current++;
        releaseMicrophone.current?.();
        releaseMicrophone.current = null;
        setWorking(false);
        setPermissionPending(false);
        setPhase('');
        recovery.releaseIdle();
        return true;
    }
    async function connect(
        call: Call,
        pendingWork?: CallOperation,
        microphoneChecked = false,
    ) {
        const work = pendingWork ?? beginWork('permission');
        if (!work || !ownsWork(work)) return;
        setIncomingProblem(null);
        const callEpoch = ++callGeneration.current;
        const isCurrent = () =>
            ownsWork(work) && callGeneration.current === callEpoch;
        let session: Room | null = null;
        let joined = false;
        let dispatched = false;
        let joinedCall = call;
        setPhase('Connecting audio…');
        try {
            if (!(await acquireWork(work))) return;
            if (!microphoneChecked) {
                releaseMicrophone.current = claimChatMicrophone('call');
                await checkMicrophone(work.audio);
            }
            if (!isCurrent()) return;
            work.stage = 'request';
            setPermissionPending(false);
            clearOutcome();
            saveCallRecovery(call, work);
            dispatched = true;
            const response = await joinRequest<{
                call: Call;
                connection: { url: string; token: string };
            }>(`/chat/api/calls/${call.id}/join`, {
                method: 'POST',
                body: JSON.stringify({
                    device_id: work.device,
                    invitation_id: call.my_invitation_id,
                }),
            });
            if (!isCurrent()) return;
            if (
                !response?.call ||
                response.call.id !== call.id ||
                response.call.conversation_id !== call.conversation_id ||
                (call.my_invitation_id &&
                    response.call.my_invitation_id !== call.my_invitation_id) ||
                !response.connection?.url ||
                !response.connection?.token
            )
                throw new Error('The connection result is not confirmed.');
            joined = true;
            joinedCall = response.call;
            updatePendingJoin(null);
            const { Room, RoomEvent, Track } = await import('livekit-client');
            if (!isCurrent()) return;
            session = new Room({
                ...CHAT_VOICE_ROOM_OPTIONS,
                ...(work.audio
                    ? {
                          audioCaptureDefaults: {
                              deviceId:
                                  work.audio.microphone === 'default'
                                      ? undefined
                                      : { exact: work.audio.microphone },
                          },
                          ...(work.audio.speaker !== 'default'
                              ? {
                                    audioOutput: {
                                        deviceId: work.audio.speaker,
                                    },
                                }
                              : {}),
                      }
                    : {}),
            });
            const connectedRoom = session;
            const ownsRoom = () =>
                !stopped.current &&
                currentEnabled.current &&
                currentActor.current === work.actor &&
                sessionGeneration.current === work.epoch &&
                callGeneration.current === callEpoch &&
                room.current === connectedRoom;
            room.current = session;
            session.on(RoomEvent.TrackSubscribed, (track) => {
                if (ownsRoom() && track.kind === Track.Kind.Audio)
                    audioRoot.current?.append(track.attach());
            });
            session.on(RoomEvent.TrackUnsubscribed, (track) =>
                track.detach().forEach((element) => element.remove()),
            );
            session.on(RoomEvent.AudioPlaybackStatusChanged, () => {
                if (ownsRoom())
                    setAudioBlocked(!connectedRoom.canPlaybackAudio);
            });
            session.on(RoomEvent.Reconnecting, () => {
                if (ownsRoom()) {
                    setSpeakers([]);
                    setPhase('Reconnecting audio…');
                }
            });
            session.on(RoomEvent.ActiveSpeakersChanged, (people) => {
                if (ownsRoom())
                    setSpeakers(people.map((person) => person.identity));
            });
            session.on(RoomEvent.Reconnected, () => {
                if (ownsRoom()) setPhase('Connected');
            });
            session.on(RoomEvent.Disconnected, () => {
                if (ownsRoom()) {
                    room.current = null;
                    setAudioRoom(null);
                    callGeneration.current++;
                    releaseRoom(connectedRoom);
                    setSpeakers([]);
                    audioRoot.current?.replaceChildren();
                    setPhase(
                        'Audio disconnected. Leave the call and try again.',
                    );
                }
            });
            activeActor.current = work.actor;
            setActive(response.call);
            activeRef.current = response.call;
            await session.connect(
                response.connection.url,
                response.connection.token,
            );
            if (!isCurrent()) {
                releaseRoom(session);
                return;
            }
            await session.localParticipant.setMicrophoneEnabled(true);
            if (!isCurrent()) {
                releaseRoom(session);
                return;
            }
            try {
                await session.startAudio();
            } catch {
                if (isCurrent()) setAudioBlocked(true);
            }
            if (!isCurrent()) {
                releaseRoom(session);
                return;
            }
            await callRequest(`/chat/api/calls/${call.id}/connected`, {
                method: 'POST',
                body: JSON.stringify({
                    device_id: work.device,
                    invitation_id: response.call.my_invitation_id,
                }),
            });
            if (!isCurrent()) return;
            setAudioRoom(session);
            setPhase(
                call.started_by === actorId && call.status === 'ringing'
                    ? 'Calling…'
                    : 'Connected',
            );
            try {
                await refresh();
            } catch {
                if (isCurrent())
                    setPhase('Connection interrupted. Checking the call…');
            }
        } catch (error) {
            if (!isCurrent()) {
                if (session) releaseRoom(session);
                return;
            }
            stopAudio();
            if (lostCallAccess(error)) {
                updatePendingJoin(null);
                statusActor.current = null;
                summaryActor.current = null;
                setSummaryTarget(null);
                setStatus({
                    ready: false,
                    availability: 'unavailable',
                    sound_enabled: true,
                    calls: [],
                });
                toast.error('Call access has changed. Your microphone is off.');
                return;
            }
            if (
                dispatched &&
                !joined &&
                ![400, 404, 409, 422].includes(
                    (error as { status?: number })?.status ?? 0,
                )
            ) {
                updatePendingJoin({
                    audio: work.audio,
                    actor: work.actor,
                    epoch: work.epoch,
                    device: work.device,
                    call,
                    stopping: pendingJoinRef.current?.stopping ?? false,
                    checked: false,
                    closed: false,
                    error: '',
                });
                return;
            }
            if (!joined && pendingJoinRef.current) {
                updatePendingJoin({
                    ...pendingJoinRef.current,
                    checked: false,
                    error: microphoneError(error),
                });
                return;
            }
            if (joined || call.started_by === work.actor) {
                // A failed audio connection can still own a server-side call.
                // Keep its cleanup visible until the server confirms leaving.
                activeActor.current = work.actor;
                activeRef.current = joinedCall;
                setActive(joinedCall);
                setPhase('Audio is off. Confirming the call has ended…');
                try {
                    saveCallRecovery(joinedCall, work, 'leave');
                    await callRequest(`/chat/api/calls/${call.id}/leave`, {
                        method: 'POST',
                        body: JSON.stringify({
                            device_id: work.device,
                            invitation_id: joinedCall.my_invitation_id,
                        }),
                    });
                    if (!ownsWork(work)) return;
                    clearCallRecovery(joinedCall, work.device);
                    stopAudio();
                    setStatus((previous) => ({
                        ...previous,
                        calls: previous.calls.filter(
                            (item) => item.id !== call.id,
                        ),
                    }));
                    void showOutcome({ ...joinedCall, device: work.device });
                } catch (cleanupError) {
                    if (!ownsWork(work)) return;
                    if (lostCallAccess(cleanupError)) {
                        stopAudio();
                        statusActor.current = null;
                        clearOutcome();
                    } else {
                        setPhase(
                            'Audio is off. Retry leaving to confirm the call ended.',
                        );
                    }
                    return;
                }
            }
            if (!ownsWork(work)) return;
            if (
                !joined &&
                dispatched &&
                [400, 404, 409, 422].includes(
                    (error as { status?: number }).status ?? 0,
                )
            )
                clearCallRecovery(call, work.device);
            if (!joined && call.started_by !== work.actor) {
                setIncomingProblem({
                    actor: work.actor,
                    callId: call.id,
                    invitation: call.my_invitation_id,
                    problem: describeCallProblem(error),
                });
            } else toast.error(microphoneError(error));
        } finally {
            finishWork(work);
        }
    }
    async function switchAudioDevice(
        session: Room,
        kind: CallAudioKind,
        id: string,
    ) {
        if (
            session !== room.current ||
            microphoneBusy.current ||
            working ||
            session.state !== 'connected'
        )
            throw new Error('Wait until the call is connected.');
        const epoch = sessionGeneration.current;
        const request = ++microphoneRequest.current;
        const isCurrent = () =>
            currentActor.current === actorId &&
            currentEnabled.current &&
            sessionGeneration.current === epoch &&
            room.current === session;
        microphoneBusy.current = true;
        setMicrophoneWorking(true);
        try {
            const changed = await session.switchActiveDevice(kind, id);
            if (!isCurrent()) {
                releaseRoom(session);
                throw new Error('This call has ended.');
            }
            if (!changed) throw new Error('The device could not be changed.');
        } catch (error) {
            if (!isCurrent()) releaseRoom(session);
            throw error;
        } finally {
            if (microphoneRequest.current === request) {
                microphoneBusy.current = false;
                setMicrophoneWorking(false);
            }
        }
    }
    async function toggleMicrophone() {
        const session = room.current;
        if (!session || microphoneBusy.current || working) return;
        const epoch = sessionGeneration.current;
        const request = ++microphoneRequest.current;
        const isCurrent = () =>
            currentActor.current === actorId &&
            currentEnabled.current &&
            sessionGeneration.current === epoch &&
            room.current === session;
        microphoneBusy.current = true;
        setMicrophoneWorking(true);
        try {
            await session.localParticipant.setMicrophoneEnabled(muted);
            if (isCurrent()) setMuted(!muted);
            else releaseRoom(session);
        } catch {
            if (isCurrent()) toast.error('Could not change the microphone.');
            else releaseRoom(session);
        } finally {
            if (microphoneRequest.current === request) {
                microphoneBusy.current = false;
                setMicrophoneWorking(false);
            }
        }
    }
    async function enableAudio() {
        const session = room.current;
        if (!session) return;
        try {
            await session.startAudio();
        } catch {
            if (room.current === session)
                toast.error(
                    'Audio could not start. Check your browser sound settings and try again.',
                );
        }
    }
    function saveStartRecovery(command: PendingStart) {
        const body = JSON.parse(command.body) as {
            participant_ids: number[];
            routing_mode: CallRouting;
        };
        recovery.write({
            version: 1,
            actor: command.actor,
            device: command.device,
            conversation: command.conversationId,
            kind: 'start',
            mutation: command.mutation,
            participants: body.participant_ids,
            routing: body.routing_mode,
            stopping: command.cancelling,
        });
    }
    function clearStartRecovery(command: PendingStart) {
        recovery.clear(
            (record) =>
                record.kind === 'start' &&
                record.mutation === command.mutation &&
                record.device === command.device,
        );
    }
    function saveCallRecovery(
        call: Pick<Call, 'id' | 'conversation_id' | 'my_invitation_id'>,
        work: CallOperation,
        stopping: 'none' | 'leave' | 'end' = 'none',
    ) {
        if (!call.my_invitation_id)
            throw new Error(
                'This call invitation could not be confirmed. Check the call again.',
            );
        const previous = recovery.record();
        if (
            stopping === 'none' &&
            previous?.kind === 'call' &&
            previous.stopping !== 'none'
        )
            throw new Error(
                'This call is being stopped and will not reconnect.',
            );
        recovery.write({
            version: 1,
            actor: work.actor,
            device: work.device,
            conversation: call.conversation_id,
            kind: 'call',
            call: call.id,
            invitation: call.my_invitation_id,
            stopping:
                previous?.kind === 'call' && previous.stopping !== 'none'
                    ? previous.stopping
                    : stopping,
        });
    }
    function clearCallRecovery(
        call: Pick<Call, 'id' | 'my_invitation_id'>,
        deviceId: string,
    ) {
        recovery.clear(
            (record) =>
                record.kind === 'call' &&
                record.call === call.id &&
                record.invitation === call.my_invitation_id &&
                record.device === deviceId,
        );
    }
    async function leave(call: Call, everyone = false) {
        const work = beginWork();
        if (!work) return;
        if (activeRef.current?.id === call.id) stopAudio();
        else callGeneration.current++;
        try {
            if (!(await acquireWork(work))) return;
            saveCallRecovery(call, work, everyone ? 'end' : 'leave');
            const saved = recovery.record();
            everyone = saved?.kind === 'call' && saved.stopping === 'end';
            await callRequest(`/chat/api/calls/${call.id}/leave`, {
                method: 'POST',
                body: JSON.stringify({
                    device_id: work.device,
                    invitation_id: call.my_invitation_id,
                    end_for_everyone: everyone,
                }),
            });
            if (!ownsWork(work)) return;
            clearCallRecovery(call, work.device);
            setEndReview(false);
            setStatus((previous) => ({
                ...previous,
                calls: previous.calls.filter((item) => item.id !== call.id),
            }));
            void showOutcome({ ...call, device: work.device });
            try {
                await refresh();
            } catch {
                if (ownsWork(work))
                    toast('Call ended. Chat could not refresh yet.');
            }
        } catch (error) {
            if (!ownsWork(work)) return;
            if (lostCallAccess(error)) {
                statusActor.current = null;
                summaryActor.current = null;
                setSummaryTarget(null);
                toast.error('Call access has changed. Your microphone is off.');
                return;
            }
            if (call.my_status !== 'invited') {
                activeActor.current = work.actor;
                activeRef.current = call;
                setActive(call);
                setPhase(
                    'Audio is off. Retry leaving to confirm the call ended.',
                );
            } else {
                toast.error(
                    `The server could not confirm the call was declined: ${(error as Error).message}`,
                );
            }
        } finally {
            finishWork(work);
        }
    }
    async function availability(value: Availability) {
        const epoch = sessionGeneration.current;
        const isCurrent = () =>
            !stopped.current &&
            currentEnabled.current &&
            currentActor.current === actorId &&
            sessionGeneration.current === epoch;
        if (!isCurrent()) return;
        if (value === 'available' && status.sound_enabled)
            await ringtone.enable();
        if (!isCurrent()) return;
        await callRequest('/chat/api/availability', {
            method: 'PATCH',
            body: JSON.stringify({ availability: value }),
        });
        if (isCurrent()) await refresh();
    }
    async function setSoundEnabled(value: boolean) {
        const epoch = sessionGeneration.current;
        const isCurrent = () =>
            !stopped.current &&
            currentEnabled.current &&
            currentActor.current === actorId &&
            sessionGeneration.current === epoch;
        if (!isCurrent()) return;
        if (value) await ringtone.enable();
        if (!isCurrent()) return;
        await callRequest('/chat/api/availability', {
            method: 'PATCH',
            body: JSON.stringify({ sound_enabled: value }),
        });
        if (isCurrent()) {
            await refresh();
            window.dispatchEvent(new Event('oblivion:chat-refresh'));
        }
    }
    async function start(
        conversation: CallTarget,
        participants: number[],
        routing: CallRouting = 'invited',
        audio?: CallAudioSelection,
    ) {
        if (
            activeRef.current ||
            operation.current ||
            pendingStartRef.current ||
            pendingJoinRef.current
        )
            throw new Error('Finish your current call first.');
        const work = beginWork('permission');
        if (!work)
            throw new Error(
                'Voice calling is not available. Refresh this page.',
            );
        try {
            if (!(await acquireWork(work))) return;
            work.audio = audio ? { ...audio } : undefined;
            releaseMicrophone.current = claimChatMicrophone('call');
            await checkMicrophone(work.audio);
            if (!ownsWork(work)) return;
            work.stage = 'request';
            setPermissionPending(false);
            const mutation = crypto.randomUUID();
            clearOutcome();
            const command: PendingStart = {
                audio: work.audio,
                actor: work.actor,
                epoch: work.epoch,
                device: work.device,
                conversationId: conversation.id,
                title: conversation.title || 'Voice call',
                mutation,
                body: JSON.stringify({
                    mutation_id: mutation,
                    device_id: work.device,
                    participant_ids: [...participants].sort((a, b) => a - b),
                    routing_mode: routing,
                }),
                cancelling: false,
                result: null,
                error: '',
            };
            saveStartRecovery(command);
            updatePendingStart(command);
            try {
                const response = await requestStart(command, 'start');
                if (ownsWork(work))
                    await acceptStartResult(command, response, work);
            } catch (error) {
                if (!ownsWork(work)) return;
                if (
                    [400, 401, 403, 404, 409, 419, 422].includes(
                        (error as { status?: number }).status ?? 0,
                    )
                ) {
                    clearStartRecovery(command);
                    updatePendingStart(null);
                    throw error;
                }
                updatePendingStart({
                    ...command,
                    error: '',
                });
            }
        } catch (error) {
            if (ownsWork(work)) {
                const failure = new Error(microphoneError(error));
                failure.name = (error as Error)?.name || 'Error';
                throw failure;
            }
        } finally {
            finishWork(work);
        }
    }

    async function requestStart(
        command: PendingStart,
        action: 'start' | 'check' | 'cancel',
    ): Promise<StartResult> {
        const controller = new AbortController();
        startController.current = controller;
        const deadline = window.setTimeout(() => controller.abort(), 30000);
        const base = `/chat/api/conversations/${command.conversationId}`;
        try {
            const result = await callRequest<StartResult>(
                action === 'start'
                    ? `${base}/calls`
                    : `${base}/call-attempts/${command.mutation}${action === 'cancel' ? '/cancel' : `?device_id=${command.device}`}`,
                {
                    method: action === 'check' ? 'GET' : 'POST',
                    body: action === 'check' ? undefined : command.body,
                    signal: controller.signal,
                },
            );
            if (
                !result ||
                !['unconfirmed', 'started', 'finished', 'cancelled'].includes(
                    result.attempt_state,
                ) ||
                typeof result.can_join !== 'boolean' ||
                (result.can_join &&
                    (!result.call || result.attempt_state !== 'started')) ||
                (result.call &&
                    (result.call.conversation_id !== command.conversationId ||
                        result.call.started_by !== command.actor))
            )
                throw new Error('The call result is not confirmed.');
            return result;
        } finally {
            window.clearTimeout(deadline);
            if (startController.current === controller)
                startController.current = null;
        }
    }

    async function acceptStartResult(
        command: PendingStart,
        result: StartResult,
        work: CallOperation,
    ) {
        if (['finished', 'cancelled'].includes(result.attempt_state))
            clearStartRecovery(command);
        if (result.can_join && result.call && !command.cancelling) {
            saveCallRecovery(result.call, work);
            updatePendingStart(null);
            await connect(result.call, work, true);
        } else updatePendingStart({ ...command, result, error: '' });
    }

    async function recoverStart(action: 'check' | 'start' | 'join' | 'cancel') {
        let command = pendingStartRef.current;
        if (
            !command ||
            command.actor !== actorId ||
            command.epoch !== sessionGeneration.current
        )
            return;
        if (action === 'cancel' && permissionPending) cancelPreflight();
        if (
            operation.current ||
            (command.cancelling && ['start', 'join'].includes(action)) ||
            (action === 'start' && command.restored && !command.reviewed)
        )
            return;
        const joining = action === 'start' || action === 'join';
        const work = beginWork(joining ? 'permission' : 'request');
        if (!work) return;
        work.audio = command.audio;
        if (action === 'cancel') command = { ...command, cancelling: true };
        updatePendingStart({ ...command, error: '' });
        try {
            if (!(await acquireWork(work))) return;
            saveStartRecovery(command);
            if (joining) {
                releaseMicrophone.current = claimChatMicrophone('call');
                await checkMicrophone(work.audio);
                if (!ownsWork(work)) return;
                work.stage = 'request';
                setPermissionPending(false);
            }
            // Join always re-reads the receipt before joining; no automatic join follows a background read.
            const result = await requestStart(
                command,
                action === 'join' ? 'check' : action,
            );
            if (!ownsWork(work)) return;
            if (['finished', 'cancelled'].includes(result.attempt_state))
                clearStartRecovery(command);
            if (result.call) command = { ...command, title: result.call.title };
            if (
                command.restored &&
                result.attempt_state === 'unconfirmed' &&
                !command.cancelling
            ) {
                const options = await joinRequest<{
                    people: { id: number; name: string; available: boolean }[];
                    care_team?: { allowed: boolean; available: boolean };
                }>(
                    `/chat/api/conversations/${command.conversationId}/call-options`,
                );
                if (!ownsWork(work)) return;
                const body = JSON.parse(command.body) as {
                    participant_ids: number[];
                    routing_mode: CallRouting;
                };
                const people = body.participant_ids.map((id) =>
                    options.people.find(
                        (person) => person.id === id && person.available,
                    ),
                );
                const reviewed =
                    body.routing_mode === 'care_team'
                        ? options.care_team?.allowed &&
                          options.care_team.available
                            ? 'Available care team'
                            : ''
                        : people.every(
                                (person) =>
                                    person && typeof person.name === 'string',
                            )
                          ? people.map((person) => person!.name).join(', ')
                          : '';
                command = { ...command, reviewed };
            }
            if (joining) await acceptStartResult(command, result, work);
            else updatePendingStart({ ...command, result, error: '' });
        } catch (error) {
            if (!ownsWork(work)) return;
            if (lostCallAccess(error)) {
                updatePendingStart(null);
                toast.error('Call access has changed. Your microphone is off.');
            } else
                updatePendingStart({
                    ...command,
                    error:
                        work.stage === 'permission'
                            ? microphoneError(error)
                            : 'The server has not confirmed this attempt yet. Check again or retry the same action.',
                });
        } finally {
            finishWork(work);
        }
    }
    const duration = `${Math.floor(elapsed / 60)}:${String(elapsed % 60).padStart(2, '0')}`;
    async function recoverJoin(action: 'check' | 'join' | 'stop') {
        let pending = pendingJoinRef.current;
        if (
            !pending ||
            pending.actor !== actorId ||
            pending.epoch !== sessionGeneration.current
        )
            return;
        if (action === 'stop' && permissionPending) cancelPreflight();
        if (operation.current || (action === 'join' && pending.stopping))
            return;
        if (action === 'stop') {
            pending = { ...pending, stopping: true, checked: false, error: '' };
            updatePendingJoin(pending);
        }
        const work = beginWork('request');
        if (!work) return;
        const finish = () => {
            clearCallRecovery(pending.call, pending.device);
            updatePendingJoin(null);
            setStatus((previous) => ({
                ...previous,
                calls: previous.calls.filter(
                    (item) => item.id !== pending.call.id,
                ),
            }));
            void showOutcome({ ...pending.call, device: pending.device });
        };
        try {
            if (!(await acquireWork(work))) return;
            if (action === 'stop') {
                saveCallRecovery(
                    pending.call,
                    work,
                    pending.endEveryone ? 'end' : 'leave',
                );
                await joinRequest(`/chat/api/calls/${pending.call.id}/leave`, {
                    method: 'POST',
                    body: JSON.stringify({
                        device_id: pending.device,
                        invitation_id: pending.call.my_invitation_id,
                        end_for_everyone: !!pending.endEveryone,
                    }),
                });
                if (ownsWork(work)) finish();
                return;
            }
            const params = new URLSearchParams({ device_id: pending.device });
            if (pending.call.my_invitation_id)
                params.set('invitation_id', pending.call.my_invitation_id);
            const result = await joinRequest<{
                call: Call & { outcome: string };
            }>(`/chat/api/calls/${pending.call.id}/outcome?${params}`);
            if (!ownsWork(work)) return;
            const fresh = result?.call;
            if (
                !fresh ||
                fresh.id !== pending.call.id ||
                fresh.conversation_id !== pending.call.conversation_id ||
                (pending.call.my_invitation_id &&
                    fresh.my_invitation_id !== pending.call.my_invitation_id) ||
                typeof fresh.outcome !== 'string' ||
                typeof fresh.my_status !== 'string'
            )
                throw new Error(
                    'The call result is not confirmed. Check again.',
                );
            if (
                fresh.ended_at ||
                fresh.outcome !== 'ongoing' ||
                !['invited', 'accepted', 'connected'].includes(fresh.my_status)
            ) {
                finish();
                return;
            }
            updatePendingJoin({
                ...pending,
                call: fresh,
                closed: false,
                checked: true,
                error: '',
            });
            if (action === 'join' && !pending.stopping) {
                work.audio = pending.audio;
                work.stage = 'permission';
                setPermissionPending(true);
                await connect(fresh, work);
            }
        } catch (error) {
            if (!ownsWork(work)) return;
            const closed = [401, 403, 404, 409, 419].includes(
                (error as { status?: number })?.status ?? 0,
            );
            if (lostCallAccess(error)) statusActor.current = null;
            const retired = (error as { status?: number }).status === 409;
            if (retired) clearCallRecovery(pending.call, pending.device);
            updatePendingJoin({
                ...pending,
                closed,
                retired,
                checked: false,
                error: closed
                    ? ''
                    : 'Could not confirm this invitation. Check your connection and try again.',
            });
        } finally {
            finishWork(work);
        }
    }

    const recoveryBlocked = enabled && !!actorId && recovery.status !== 'ready';
    const visibleJoin =
        enabled && actorId !== null && pendingJoin?.actor === actorId
            ? pendingJoin
            : null;
    const visibleActive =
        enabled && actorId !== null && activeActor.current === actorId
            ? active
            : null;
    const visiblePending =
        enabled && actorId !== null && pendingStart?.actor === actorId
            ? pendingStart
            : null;
    const ownsStatus =
        enabled && actorId !== null && statusActor.current === actorId;
    const visibleOutcome =
        !visibleActive && !visiblePending && !visibleJoin && !incoming
            ? outcome.state
            : null;

    return (
        <Context.Provider
            value={{
                unreadCount: ownsStatus ? (status.unread_count ?? null) : null,
                enabled,
                ready: ownsStatus && status.ready && !recoveryBlocked,
                availability: ownsStatus ? status.availability : 'unavailable',
                canSetOffDuty: ownsStatus && status.can_set_off_duty === true,
                active:
                    recoveryBlocked ||
                    !!visibleActive ||
                    !!visiblePending ||
                    !!visibleJoin ||
                    working,
                permissionPending: permissionPending && working,
                cancelPreflight,
                soundEnabled: status.sound_enabled,
                setSoundEnabled,
                setAvailability: availability,
                start,
            }}
        >
            <CallConversationContext.Provider
                value={
                    visibleActive &&
                    actorId !== null &&
                    expanded &&
                    conversationOpen &&
                    typeof visibleActive.presentation?.viewer_is_family ===
                        'boolean'
                        ? {
                              actorId,
                              conversationId: visibleActive.conversation_id,
                              returnToCall: () => setExpanded(true),
                          }
                        : visibleOutcome?.data &&
                            outcome.action &&
                            actorId !== null
                          ? {
                                actorId,
                                conversationId:
                                    visibleOutcome.data.call.conversation_id,
                                mode: 'followup' as const,
                                returnToCall: () =>
                                    outcome.setAction(outcome.action),
                            }
                          : null
                }
            >
                {children}
                <CallSummary
                    key={`${actorId}:${summaryTarget?.id ?? 'none'}`}
                    actorId={actorId}
                    call={
                        enabled &&
                        actorId !== null &&
                        summaryActor.current === actorId
                            ? summaryTarget
                            : null
                    }
                    onClose={() => setSummaryTarget(null)}
                />
                <div ref={audioRoot} className="hidden" aria-hidden="true" />
                <p
                    role="status"
                    aria-live="polite"
                    aria-atomic="true"
                    className="sr-only"
                >
                    {incoming &&
                    !active &&
                    !visibleJoin &&
                    !visiblePending &&
                    !recoveryBlocked
                        ? `Incoming voice call from ${incoming.presentation?.caller_name || incoming.participants.find((person) => person.id === incoming.started_by)?.name || 'a call participant'}. Press Alt Shift C to reach call controls.`
                        : ''}
                </p>
                {((incoming && !active) ||
                    visibleActive ||
                    visiblePending ||
                    visibleJoin ||
                    visibleOutcome ||
                    recoveryBlocked) && (
                    <ChatCallDock
                        covered={
                            expanded ||
                            endReview ||
                            !!(visibleOutcome && outcome.action)
                        }
                    >
                        {recoveryBlocked && (
                            <section
                                aria-label="Call browser recovery"
                                className="space-y-3 rounded-2xl border bg-card p-4 text-card-foreground"
                            >
                                <h2 className="text-sm font-semibold">
                                    {recovery.status === 'other'
                                        ? 'Call open in another tab'
                                        : recovery.status === 'checking'
                                          ? 'Checking previous call…'
                                          : 'Call recovery unavailable'}
                                </h2>
                                <p role="status" className="text-sm">
                                    {recovery.status === 'other'
                                        ? 'Use the call controls in that tab. If it has closed, check again here. Your microphone will stay off in this tab.'
                                        : recovery.status === 'checking'
                                          ? 'Checking this browser before making another call.'
                                          : 'This browser could not safely save or read your call attempt. Check browser storage and use a supported browser before retrying. Your previous attempt has not been discarded.'}
                                </p>
                                <Button
                                    variant="outline"
                                    className="min-h-[44px]"
                                    disabled={recovery.status === 'checking'}
                                    onClick={() => void recovery.sync()}
                                >
                                    Check again
                                </Button>
                            </section>
                        )}
                        {!recoveryBlocked &&
                            visibleOutcome &&
                            actorId !== null && (
                                <CallResultCard
                                    data={visibleOutcome.data}
                                    loading={visibleOutcome.loading}
                                    denied={visibleOutcome.denied}
                                    error={visibleOutcome.error}
                                    actorId={actorId}
                                    action={outcome.action}
                                    onAction={outcome.setAction}
                                    onRetry={() =>
                                        void showOutcome(visibleOutcome.target)
                                    }
                                    onDismiss={clearOutcome}
                                    onRecord={() => {
                                        if (
                                            visibleOutcome.data?.call
                                                .can_record_note
                                        ) {
                                            summaryActor.current = actorId;
                                            setSummaryTarget(
                                                visibleOutcome.data.call,
                                            );
                                        }
                                    }}
                                    callAgain={
                                        visibleOutcome.data ? (
                                            <ChatCallButton
                                                actorId={actorId}
                                                conversation={{
                                                    ...visibleOutcome.data
                                                        .followup.conversation,
                                                    type: visibleOutcome.data
                                                        .followup.callbacks
                                                        ? 'family'
                                                        : undefined,
                                                }}
                                                label="Call again"
                                            />
                                        ) : null
                                    }
                                />
                            )}
                        {!recoveryBlocked && visiblePending && (
                            <CallStartRecovery
                                title={visiblePending.title}
                                reviewRequired={!!visiblePending.restored}
                                reviewedRecipients={visiblePending.reviewed}
                                state={
                                    visiblePending.result?.attempt_state ??
                                    'unconfirmed'
                                }
                                cancelling={visiblePending.cancelling}
                                working={working}
                                permissionPending={permissionPending}
                                error={visiblePending.error}
                                onCheck={() => void recoverStart('check')}
                                onRetry={() => void recoverStart('start')}
                                onJoin={() => void recoverStart('join')}
                                onStop={() => void recoverStart('cancel')}
                                onDismiss={() => {
                                    if (
                                        !operation.current &&
                                        ['finished', 'cancelled'].includes(
                                            pendingStartRef.current?.result
                                                ?.attempt_state ?? '',
                                        )
                                    )
                                        updatePendingStart(null);
                                }}
                            />
                        )}
                        {!recoveryBlocked && visibleJoin && (
                            <CallJoinRecovery
                                title={visibleJoin.call.title}
                                restored={visibleJoin.restored}
                                endingEveryone={visibleJoin.endEveryone}
                                working={working}
                                permissionPending={permissionPending}
                                stopping={visibleJoin.stopping}
                                checked={visibleJoin.checked}
                                closed={visibleJoin.closed}
                                error={visibleJoin.error}
                                onCheck={() => void recoverJoin('check')}
                                onJoin={() => void recoverJoin('join')}
                                onStop={() => void recoverJoin('stop')}
                                onDismiss={
                                    visibleJoin.retired
                                        ? () => updatePendingJoin(null)
                                        : undefined
                                }
                            />
                        )}
                        {!recoveryBlocked &&
                            incoming &&
                            !active &&
                            !visibleJoin &&
                            !visiblePending && (
                                <IncomingCallCard
                                    caller={
                                        incoming.participants.find(
                                            (person) =>
                                                person.id ===
                                                incoming.started_by,
                                        )?.name ?? 'Call participant'
                                    }
                                    detail={incoming.title}
                                    presentation={incoming.presentation}
                                    working={working || !!visiblePending}
                                    permissionPending={permissionPending}
                                    silent={callSilence.silent}
                                    problem={
                                        incomingProblem?.actor === actorId &&
                                        incomingProblem.callId ===
                                            incoming.id &&
                                        incomingProblem.invitation ===
                                            incoming.my_invitation_id
                                            ? incomingProblem.problem
                                            : null
                                    }
                                    soundReady={ringtone.ready}
                                    soundEnabled={status.sound_enabled}
                                    onSilence={callSilence.toggle}
                                    onEnableSound={() =>
                                        void ringtone.enable().then((ready) => {
                                            if (!ready)
                                                toast(
                                                    'This browser cannot play the ringtone. The incoming-call card will stay visible.',
                                                );
                                        })
                                    }
                                    onAnswer={() => void connect(incoming)}
                                    onDecline={() => {
                                        if (permissionPending)
                                            cancelPreflight();
                                        void leave(incoming);
                                    }}
                                />
                            )}
                        {visibleActive && actorId !== null && (
                            <ActiveCallCard
                                title={visibleActive.title}
                                status={`${visibleActive.connected_at ? duration : phase || 'Connecting…'}${visibleActive.connected_at && phase && !['Connected', 'Calling…'].includes(phase) ? ` · ${phase}` : ''}`}
                                presentation={visibleActive.presentation}
                                actorId={actorId}
                                conversationId={visibleActive.conversation_id}
                                participants={visibleActive.participants}
                                hostId={visibleActive.host_id}
                                callId={visibleActive.id}
                                deviceId={device.current}
                                invitationId={visibleActive.my_invitation_id}
                                maxParticipants={visibleActive.max_participants}
                                speakers={speakers}
                                onRefresh={refresh}
                                audioRoom={audioRoom}
                                onSwitchDevice={switchAudioDevice}
                                muted={muted}
                                microphoneDisabled={
                                    working ||
                                    microphoneWorking ||
                                    phase.startsWith('Reconnecting') ||
                                    !room.current
                                }
                                working={working}
                                audioBlocked={audioBlocked}
                                isHost={visibleActive.host_id === actorId}
                                expanded={expanded}
                                conversationOpen={conversationOpen}
                                onExpandedChange={setExpanded}
                                onConversationChange={setConversationOpen}
                                onMute={() => void toggleMicrophone()}
                                onLeave={() => void leave(visibleActive)}
                                onEndEveryone={() => {
                                    setExpanded(false);
                                    setEndReview(true);
                                }}
                                onEnableAudio={() => void enableAudio()}
                            />
                        )}
                    </ChatCallDock>
                )}
                <Dialog
                    open={endReview && !!visibleActive}
                    onOpenChange={setEndReview}
                >
                    <DialogContent
                        style={{
                            maxWidth: 'min(92vw, 480px)',
                            width: 'min(92vw, 480px)',
                        }}
                        onCloseAutoFocus={(event) => {
                            const control =
                                document.querySelector<HTMLButtonElement>(
                                    '.chat-call-dock button[aria-label="Expand current call"]',
                                );
                            if (control && !endReview) {
                                event.preventDefault();
                                control.focus({ preventScroll: true });
                            }
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle>
                                End the call for everyone?
                            </DialogTitle>
                            <DialogDescription>
                                Everyone will be disconnected. Use Leave if the
                                others should continue.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                className="min-h-[44px]"
                                onClick={() => setEndReview(false)}
                            >
                                Keep call open
                            </Button>
                            <Button
                                variant="destructive"
                                className="min-h-[44px]"
                                disabled={working}
                                onClick={() =>
                                    active && void leave(active, true)
                                }
                            >
                                End call
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </CallConversationContext.Provider>
        </Context.Provider>
    );
}

export function ChatCallButton({
    conversation,
    actorId,
    label,
    ref,
    hideTrigger = false,
    family = false,
}: {
    conversation: CallTarget;
    actorId: number;
    label?: string;
    ref?: Ref<{ open: () => boolean }>;
    hideTrigger?: boolean;
    /** The viewer is a family member, who can request a callback instead. */
    family?: boolean;
}) {
    const calling = useChatCalling();
    const [open, setOpen] = useState(false);
    const [callbackOpen, setCallbackOpen] = useState(false);
    const [selected, setSelected] = useState<number[]>([]);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<CallProblem | null>(null);
    const [audio, setAudio] = useState<CallAudioSelection>(DEFAULT_CALL_AUDIO);
    const [audioReady, setAudioReady] = useState(false);
    const audioCheck = useRef<CallAudioPreflightControls>(null);
    const attempt = useRef(0);
    const startInFlight = useRef(false);
    const cancelPending = useRef(calling?.cancelPreflight);
    cancelPending.current = calling?.cancelPreflight;
    const invalidateAttempt = useCallback(() => {
        attempt.current++;
        if (startInFlight.current) cancelPending.current?.();
        startInFlight.current = false;
    }, []);
    useEffect(() => {
        invalidateAttempt();
        setOpen(false);
        setBusy(false);
        setProblem(null);
        setAudio(DEFAULT_CALL_AUDIO);
        setAudioReady(false);
        return invalidateAttempt;
    }, [actorId, conversation.id, invalidateAttempt]);
    const [loadingPeople, setLoadingPeople] = useState(false);
    const [peopleError, setPeopleError] = useState('');
    const [people, setPeople] = useState<
        { id: number; name: string; available: boolean; cover: boolean }[]
    >([]);
    const [routing, setRouting] = useState<CallRouting>('invited');
    const [careTeam, setCareTeam] = useState({
        allowed: false,
        available: false,
    });
    const [maxParticipants, setMaxParticipants] = useState(8);
    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        setLoadingPeople(true);
        setPeople([]);
        setSelected([]);
        setRouting('invited');
        setCareTeam({ allowed: false, available: false });
        setPeopleError('');
        void chatRequest<{
            people: typeof people;
            care_team?: { allowed: boolean; available: boolean };
            max_participants?: number;
        }>(`/chat/api/conversations/${conversation.id}/call-options`, {
            signal: controller.signal,
            headers: { 'X-Chat-Actor': String(actorId) },
        })
            .then((result) => {
                if (!controller.signal.aborted) {
                    setPeople(
                        result.people.filter((person) => person.id !== actorId),
                    );
                    setCareTeam(
                        result.care_team ?? {
                            allowed: false,
                            available: false,
                        },
                    );
                    setRouting(
                        result.care_team?.allowed ? 'care_team' : 'invited',
                    );
                    setMaxParticipants(result.max_participants ?? 8);
                }
            })
            .catch((error) => {
                if (!controller.signal.aborted)
                    setPeopleError((error as Error).message);
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoadingPeople(false);
            });
        return () => controller.abort();
    }, [open, conversation.id, actorId]);
    useImperativeHandle(ref, () => ({
        open: () => {
            if (
                !calling?.ready ||
                calling.active ||
                !conversation.can_send ||
                conversation.can_call === false
            )
                return false;
            setProblem(null);
            setAudioReady(false);
            setOpen(true);
            return true;
        },
    }));
    if (!calling) return null;
    function changeOpen(next: boolean) {
        if (!next && busy) {
            if (!calling?.cancelPreflight()) return;
            attempt.current++;
            startInFlight.current = false;
            setBusy(false);
        }
        if (!next) {
            audioCheck.current?.stop();
            setAudioReady(false);
        }
        setOpen(next);
    }
    return (
        <>
            {!hideTrigger && (
                <Button
                    variant={label ? 'outline' : 'ghost'}
                    size={label ? 'default' : 'icon'}
                    className={label ? 'min-h-[44px]' : 'size-[44px]'}
                    aria-label={label ?? 'Start voice call'}
                    disabled={
                        !calling.ready ||
                        calling.active ||
                        !conversation.can_send ||
                        conversation.can_call === false
                    }
                    title={
                        conversation.can_call === false
                            ? 'Voice calls are disabled for this conversation'
                            : calling.ready
                              ? 'Start a voice call'
                              : 'Voice calling is not available yet'
                    }
                    onClick={() => {
                        setProblem(null);
                        setAudioReady(false);
                        setOpen(true);
                    }}
                >
                    <Phone className="size-5" />
                    {label}
                </Button>
            )}
            <Dialog open={open} onOpenChange={changeOpen}>
                <DialogContent
                    style={{
                        maxWidth: 'min(92vw, 480px)',
                        width: 'min(92vw, 480px)',
                    }}
                    showCloseButton={false}
                    className="flex max-h-[calc(100dvh-2rem)] flex-col overflow-y-auto rounded-2xl bg-card text-card-foreground"
                >
                    <Button
                        variant="ghost"
                        size="icon"
                        className="absolute top-2 right-2 size-[44px]"
                        aria-label="Close call setup"
                        disabled={busy && !calling.permissionPending}
                        onClick={() => changeOpen(false)}
                    >
                        <X className="size-4" />
                    </Button>
                    <DialogHeader className="shrink-0 pr-10 text-left">
                        <DialogTitle>Start a voice call</DialogTitle>
                        <DialogDescription>
                            {routing === 'care_team'
                                ? 'Call the available care team. The first person to answer takes the call.'
                                : 'Choose approved participants. Only people who are currently available can be called.'}
                        </DialogDescription>
                    </DialogHeader>
                    {loadingPeople && (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Checking availability…
                        </p>
                    )}
                    {peopleError && (
                        <p
                            role="alert"
                            className="text-sm text-status-critical"
                        >
                            {peopleError}
                        </p>
                    )}
                    {problem && <CallProblemNotice problem={problem} />}
                    {!loadingPeople &&
                        !peopleError &&
                        (routing === 'care_team'
                            ? !careTeam.available
                            : !people.some((person) => person.available)) && (
                            <div className="space-y-3 rounded-lg bg-muted p-3 text-sm">
                                <p>
                                    Nobody is available to call right now. Send
                                    a message
                                    {family && conversation.type === 'family'
                                        ? ' or request a callback'
                                        : ''}
                                    .
                                </p>
                                {family && conversation.type === 'family' && (
                                    <Button
                                        variant="outline"
                                        className="frontline-tap"
                                        disabled={busy}
                                        onClick={() => {
                                            changeOpen(false);
                                            setCallbackOpen(true);
                                        }}
                                    >
                                        <Clock className="size-4" />
                                        Request callback
                                    </Button>
                                )}
                            </div>
                        )}
                    {!loadingPeople && !peopleError && careTeam.allowed && (
                        <div
                            role="radiogroup"
                            aria-label="Call destination"
                            className="grid gap-2"
                        >
                            <label className="flex min-h-[44px] cursor-pointer items-start gap-3 rounded-xl border p-3">
                                <input
                                    type="radio"
                                    name="call-destination"
                                    className="mt-1"
                                    checked={routing === 'care_team'}
                                    disabled={busy}
                                    onChange={() => {
                                        setRouting('care_team');
                                        setSelected([]);
                                    }}
                                />
                                <Users className="mt-0.5 size-5 shrink-0 text-primary" />
                                <span>
                                    <span className="block text-sm font-medium">
                                        Available care team
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        One responder answers. Other invitations
                                        stop.
                                    </span>
                                </span>
                            </label>
                            <label className="flex min-h-[44px] cursor-pointer items-center gap-3 rounded-xl border p-3">
                                <input
                                    type="radio"
                                    name="call-destination"
                                    checked={routing === 'invited'}
                                    disabled={busy}
                                    onChange={() => setRouting('invited')}
                                />
                                <span className="text-sm font-medium">
                                    Choose people for a call
                                </span>
                            </label>
                        </div>
                    )}
                    {routing === 'invited' && (
                        <>
                            <p className="text-xs text-muted-foreground">
                                {selected.length + 1} / {maxParticipants}{' '}
                                participants, including you
                            </p>
                            <div className="max-h-64 overflow-y-auto">
                                {people.map((person) => (
                                    <label
                                        key={person.id}
                                        className="flex min-h-[44px] items-center gap-3 rounded-lg p-3 hover:bg-muted"
                                    >
                                        <Checkbox
                                            disabled={
                                                !person.available ||
                                                busy ||
                                                (!selected.includes(
                                                    person.id,
                                                ) &&
                                                    selected.length + 1 >=
                                                        maxParticipants)
                                            }
                                            checked={selected.includes(
                                                person.id,
                                            )}
                                            onCheckedChange={(checked) =>
                                                setSelected(
                                                    checked === true
                                                        ? [
                                                              ...selected,
                                                              person.id,
                                                          ]
                                                        : selected.filter(
                                                              (id) =>
                                                                  id !==
                                                                  person.id,
                                                          ),
                                                )
                                            }
                                        />
                                        <span className="text-sm">
                                            {person.name}
                                            <span className="block text-xs text-muted-foreground">
                                                {person.cover
                                                    ? 'Authorised care-team cover · '
                                                    : ''}
                                                {person.available
                                                    ? 'Available'
                                                    : 'Unavailable'}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                        </>
                    )}
                    {open && (
                        <CallAudioPreflight
                            key={`${actorId}:${conversation.id}`}
                            selection={audio}
                            onChange={setAudio}
                            onReady={setAudioReady}
                            disabled={busy || calling.active}
                            controls={audioCheck}
                        />
                    )}
                    {busy && calling.permissionPending && (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Allow microphone access to continue, or cancel.
                            Nobody has been called yet.
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            className="min-h-[44px]"
                            disabled={busy && !calling.permissionPending}
                            onClick={() => changeOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            className="min-h-[44px]"
                            disabled={
                                busy ||
                                !audioReady ||
                                loadingPeople ||
                                !!peopleError ||
                                (routing === 'care_team'
                                    ? !careTeam.available
                                    : !selected.length)
                            }
                            onClick={async () => {
                                audioCheck.current?.stop();
                                const currentAttempt = ++attempt.current;
                                startInFlight.current = true;
                                setBusy(true);
                                setProblem(null);
                                try {
                                    await calling.start(
                                        conversation,
                                        routing === 'care_team' ? [] : selected,
                                        routing,
                                        audio,
                                    );
                                    if (attempt.current === currentAttempt)
                                        setOpen(false);
                                } catch (error) {
                                    if (attempt.current === currentAttempt)
                                        setProblem(describeCallProblem(error));
                                } finally {
                                    if (attempt.current === currentAttempt) {
                                        startInFlight.current = false;
                                        setBusy(false);
                                    }
                                }
                            }}
                        >
                            <Phone className="size-4" />
                            {busy
                                ? 'Connecting…'
                                : problem
                                  ? 'Try again'
                                  : routing === 'care_team'
                                    ? 'Call care team'
                                    : 'Call'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {callbackOpen && (
                <ChatCallbacks
                    conversationId={conversation.id}
                    actorId={actorId}
                    family={family}
                    hideTrigger
                    controlledOpen
                    onOpenChange={(next) => {
                        if (!next) setCallbackOpen(false);
                    }}
                />
            )}
        </>
    );
}
