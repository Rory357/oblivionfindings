import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Room } from 'livekit-client';
import {
    Headphones,
    Maximize2,
    MessageCircle,
    Mic,
    MicOff,
    Minimize2,
    Phone,
    PhoneOff,
    ShieldCheck,
    UserPlus,
    Volume2,
    X,
} from 'lucide-react';
import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import { CallAudioDevices, type CallAudioKind } from './call-audio-devices';
import {
    CallConnectionQuality,
    useCallConnectionQuality,
} from './call-connection-quality';
import { CallParticipants, type CallParticipant } from './call-participants';
import type { ChatThreadControls } from './chat-thread';
import type { CallPresentation } from './incoming-call-card';
import type { ChatPolicy } from './types';
import { useCallAudioHealth } from './use-call-audio-health';
import { useCallPeople } from './use-call-people';

const CallThread = lazy(() =>
    import('./chat-thread').then((module) => ({ default: module.ChatThread })),
);
// The authorised conversation response supplies the actual policy before a composer appears.
const waitingPolicy: ChatPolicy = {
    enabled: false,
    family_enabled: false,
    files_enabled: false,
    voice_notes_enabled: false,
    max_file_mb: 0,
    max_files: 0,
    response_expectation: '',
};

export function ActiveCallCard({
    title,
    status,
    presentation,
    actorId,
    conversationId,
    participants = [],
    hostId = 0,
    callId,
    deviceId,
    invitationId,
    maxParticipants,
    speakers = [],
    audioRoom,
    onSwitchDevice,
    onRefresh,
    muted,
    microphoneDisabled,
    working,
    audioBlocked,
    isHost,
    expanded,
    conversationOpen,
    onExpandedChange,
    onConversationChange,
    onMute,
    onLeave,
    onEndEveryone,
    onEnableAudio,
}: {
    title: string;
    status: string;
    presentation?: CallPresentation | null;
    actorId: number;
    conversationId: number;
    participants?: CallParticipant[];
    hostId?: number;
    callId?: string;
    deviceId?: string;
    invitationId?: string;
    maxParticipants?: number;
    speakers?: string[];
    audioRoom?: Room | null;
    onSwitchDevice?: (
        room: Room,
        kind: CallAudioKind,
        id: string,
    ) => Promise<void>;
    onRefresh?: () => Promise<void>;
    muted: boolean;
    microphoneDisabled: boolean;
    working: boolean;
    audioBlocked: boolean;
    isHost: boolean;
    expanded: boolean;
    conversationOpen: boolean;
    onExpandedChange: (open: boolean) => void;
    onConversationChange: (open: boolean) => void;
    onMute: () => void;
    onLeave: () => void;
    onEndEveryone: () => void;
    onEnableAudio: () => void;
}) {
    const audioHealth = useCallAudioHealth(audioRoom);
    const connection = useCallConnectionQuality(audioRoom);
    const [audioOpen, setAudioOpen] = useState(false);
    useEffect(() => {
        if (!expanded || !audioRoom) setAudioOpen(false);
    }, [expanded, audioRoom]);
    const people = useCallPeople({
        callId,
        actorId,
        deviceId,
        invitationId,
        isHost,
        onRefresh,
    });
    const expandTrigger = useRef<HTMLButtonElement>(null);
    const threadControls = useRef<ChatThreadControls>(null);
    const messageTrigger = useRef<HTMLButtonElement>(null);
    const returnTrigger = useRef<HTMLButtonElement | null>(null);
    const open = (message: boolean) => {
        returnTrigger.current = message
            ? messageTrigger.current
            : expandTrigger.current;
        if (message) onConversationChange(true);
        onExpandedChange(true);
    };
    const mute = (full = false) => (
        <Button
            variant="outline"
            className="min-h-[44px]"
            aria-pressed={muted}
            disabled={microphoneDisabled}
            onClick={onMute}
        >
            {muted ? <MicOff className="size-4" /> : <Mic className="size-4" />}
            {muted ? 'Unmute' : 'Mute'}
            {full ? ' microphone' : ''}
        </Button>
    );
    const enableAudio = audioBlocked && (
        <Button
            variant="outline"
            className="min-h-[44px]"
            onClick={onEnableAudio}
        >
            <Volume2 className="size-4" />
            Enable call audio
        </Button>
    );
    const audioWarning = (compact: boolean) =>
        audioHealth.warning && (
            <div className="mt-3 space-y-2 rounded-xl border bg-muted p-3 text-sm">
                <p role="alert" className="flex items-start gap-2">
                    <Headphones className="mt-0.5 size-4 shrink-0" />
                    <span>{audioHealth.warning.message}</span>
                </p>
                <div className="flex flex-wrap gap-2">
                    {compact && (
                        <Button
                            variant="outline"
                            className="min-h-[44px]"
                            onClick={() => {
                                open(false);
                                setAudioOpen(true);
                                audioHealth.refresh();
                            }}
                        >
                            Review audio
                        </Button>
                    )}
                    {audioHealth.warning.canDismiss && (
                        <Button
                            variant="outline"
                            className="min-h-[44px]"
                            onClick={audioHealth.dismiss}
                        >
                            I’ve checked
                        </Button>
                    )}
                </div>
            </div>
        );
    return (
        <>
            <section
                aria-label="Ongoing voice call"
                className="rounded-2xl border bg-card p-4 text-card-foreground"
            >
                <div className="flex items-center gap-3">
                    <div className="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                        <Phone className="size-5" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold break-words">
                            {title}
                        </p>
                        <p
                            role="status"
                            className="mt-1 text-xs break-words text-muted-foreground"
                        >
                            {status}
                            {muted ? ' · Microphone muted' : ''}
                        </p>
                    </div>
                    <Button
                        ref={expandTrigger}
                        variant="ghost"
                        size="icon"
                        className="size-[44px] shrink-0"
                        aria-label="Expand current call"
                        onClick={() => open(false)}
                    >
                        <Maximize2 className="size-4" />
                    </Button>
                </div>
                <div className="mt-3 grid grid-cols-3 gap-2 [&>button]:min-w-0 [&>button]:px-2">
                    {mute()}
                    <Button
                        ref={messageTrigger}
                        variant="outline"
                        className="min-h-[44px]"
                        onClick={() => open(true)}
                    >
                        <MessageCircle className="size-4" />
                        Message
                    </Button>
                    <Button
                        variant="outline"
                        className="min-h-[44px] border-destructive/20 bg-destructive/10 text-destructive hover:bg-destructive/20 hover:text-destructive"
                        disabled={working}
                        onClick={onLeave}
                    >
                        <PhoneOff className="size-4" />
                        Leave
                    </Button>
                </div>
                {audioBlocked && <div className="mt-2">{enableAudio}</div>}
                {audioWarning(true)}
                <CallConnectionQuality report={connection} compact />
            </section>
            <Dialog open={expanded} onOpenChange={onExpandedChange}>
                <DialogContent
                    style={{
                        maxWidth: 'min(92vw, 720px)',
                        width: 'min(92vw, 720px)',
                    }}
                    showCloseButton={false}
                    className="flex max-h-[calc(100dvh-2rem)] flex-col overflow-hidden rounded-2xl bg-card p-4 text-card-foreground sm:p-6"
                    onEscapeKeyDown={(event) => {
                        if (threadControls.current?.closeDetails())
                            event.preventDefault();
                    }}
                    onCloseAutoFocus={(event) => {
                        if (returnTrigger.current?.isConnected) {
                            event.preventDefault();
                            returnTrigger.current.focus({
                                preventScroll: true,
                            });
                        }
                    }}
                >
                    <Button
                        variant="ghost"
                        size="icon"
                        className="absolute top-2 right-2 size-[44px]"
                        aria-label="Close call view"
                        onClick={() => onExpandedChange(false)}
                    >
                        <X className="size-4" />
                    </Button>
                    <DialogHeader className="shrink-0 pr-10 text-left">
                        <DialogTitle className="break-words">
                            {title}
                        </DialogTitle>
                        <DialogDescription>{status}</DialogDescription>
                    </DialogHeader>
                    <div className="min-h-0 overflow-x-hidden overflow-y-auto">
                        <div className="flex flex-col items-center gap-3 text-center">
                            <div className="flex size-14 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                                <Phone className="size-6" />
                            </div>
                            <p className="max-w-lg text-sm break-words">
                                {presentation?.audience ??
                                    'Approved call participants'}
                            </p>
                            <div className="flex flex-wrap justify-center gap-2">
                                {mute(true)}
                                <Button
                                    variant="outline"
                                    className="min-h-[44px]"
                                    aria-pressed={conversationOpen}
                                    aria-controls="active-call-conversation"
                                    onClick={() =>
                                        onConversationChange(!conversationOpen)
                                    }
                                >
                                    <MessageCircle className="size-4" />
                                    Conversation
                                </Button>
                                {enableAudio}
                                {audioRoom && onSwitchDevice && expanded && (
                                    <CallAudioDevices
                                        room={audioRoom}
                                        disabled={microphoneDisabled}
                                        onSwitch={onSwitchDevice}
                                        controlledOpen={audioOpen}
                                        onOpenChange={(value) => {
                                            setAudioOpen(value);
                                            if (value) audioHealth.refresh();
                                        }}
                                        onRefresh={audioHealth.refresh}
                                        connection={connection}
                                    />
                                )}
                                {people.available && (
                                    <Button
                                        variant="outline"
                                        className="min-h-[44px] whitespace-normal"
                                        disabled={working || people.working}
                                        onClick={people.invite}
                                    >
                                        <UserPlus className="size-4" />
                                        {people.uncertain
                                            ? 'Review pending call action'
                                            : 'Invite'}
                                    </Button>
                                )}
                            </div>
                        </div>
                        {audioWarning(false)}
                        <CallConnectionQuality report={connection} />
                        <CallParticipants
                            people={participants}
                            actorId={actorId}
                            hostId={hostId}
                            muted={muted}
                            maxParticipants={maxParticipants}
                            speakers={speakers}
                            working={
                                working || people.working || people.uncertain
                            }
                            onRemove={
                                isHost && people.available
                                    ? people.remove
                                    : undefined
                            }
                        />
                        {expanded && conversationOpen && (
                            <section
                                id="active-call-conversation"
                                aria-label="Call conversation"
                                className="mt-4 flex h-[min(30rem,55dvh)] min-h-64 min-w-0 overflow-hidden rounded-xl border"
                            >
                                {typeof presentation?.viewer_is_family ===
                                'boolean' ? (
                                    <Suspense
                                        fallback={
                                            <p
                                                role="status"
                                                className="p-4 text-sm"
                                            >
                                                Opening conversation…
                                            </p>
                                        }
                                    >
                                        <CallThread
                                            key={`${actorId}:${conversationId}`}
                                            actorId={actorId}
                                            conversationId={conversationId}
                                            family={
                                                presentation.viewer_is_family
                                            }
                                            policy={waitingPolicy}
                                            controlsRef={threadControls}
                                            inCall
                                        />
                                    </Suspense>
                                ) : (
                                    <p role="status" className="p-4 text-sm">
                                        Conversation details are refreshing.
                                        Your call can continue.
                                    </p>
                                )}
                            </section>
                        )}
                        <p className="mt-4 flex items-center justify-center gap-2 text-xs text-muted-foreground">
                            <ShieldCheck className="size-4 shrink-0" />
                            Voice only. This call is not being recorded.
                        </p>
                    </div>
                    <DialogFooter className="shrink-0 flex-wrap gap-2 border-t pt-4 sm:justify-between">
                        <Button
                            variant="outline"
                            className="min-h-[44px]"
                            onClick={() => onExpandedChange(false)}
                        >
                            <Minimize2 className="size-4" />
                            Keep working
                        </Button>
                        <div className="flex flex-wrap gap-2">
                            {isHost && (
                                <Button
                                    variant="outline"
                                    className="min-h-[44px]"
                                    disabled={working}
                                    onClick={onEndEveryone}
                                >
                                    End for everyone
                                </Button>
                            )}
                            <Button
                                variant="destructive"
                                className="min-h-[44px]"
                                disabled={working}
                                onClick={onLeave}
                            >
                                <PhoneOff className="size-4" />
                                Leave call
                            </Button>
                        </div>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {people.dialog}
        </>
    );
}
