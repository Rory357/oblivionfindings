import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    LockKeyhole,
    Pencil,
    Phone,
    PhoneIncoming,
    PhoneOff,
    ShieldCheck,
    Volume2,
    VolumeX,
} from 'lucide-react';
import { CallProblemNotice, type CallProblem } from './call-problem';

export type CallPresentation = {
    kind: 'staff' | 'family' | 'care_team' | 'group';
    caller_name: string | null;
    caller_detail: string | null;
    audience: string;
    viewer_is_family?: boolean;
};

export function IncomingCallCard({
    caller,
    detail,
    presentation,
    working,
    permissionPending,
    problem,
    silent,
    soundReady,
    soundEnabled,
    onSilence,
    onEnableSound,
    onAnswer,
    onDecline,
}: {
    caller: string;
    detail: string;
    presentation?: CallPresentation | null;
    working: boolean;
    permissionPending: boolean;
    problem?: CallProblem | null;
    silent: boolean;
    soundReady: boolean;
    soundEnabled: boolean;
    onSilence: () => void;
    onEnableSound: () => void;
    onAnswer: () => void;
    onDecline: () => void;
}) {
    const family =
        presentation?.kind === 'family' || presentation?.kind === 'care_team';
    const name = presentation?.caller_name || caller;
    const initials = name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0])
        .join('');
    const label = presentation
        ? {
              staff: 'Staff voice call',
              family: 'Family voice call',
              care_team: 'Care-team voice call',
              group: 'Group voice call',
          }[presentation.kind]
        : 'Incoming voice call';
    return (
        <section
            aria-label="Incoming voice call"
            role="region"
            // No coloured top line (DESIGN.md non-negotiable 8); the heading below says which call it is.
            className="rounded-[17px] border bg-card px-5 pt-2.5 pb-3.5 text-card-foreground"
        >
            <div
                className={cn(
                    'mb-2 flex items-center justify-between gap-2 text-xs font-semibold',
                    family ? 'text-status-success' : 'text-primary',
                )}
            >
                <span className="flex items-center gap-2">
                    <PhoneIncoming className="size-4" />
                    {label}
                </span>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-[44px] shrink-0"
                    aria-label={silent ? 'Unmute ringtone' : 'Silence ringtone'}
                    aria-pressed={silent}
                    onClick={onSilence}
                >
                    {silent ? (
                        <VolumeX className="size-4" />
                    ) : (
                        <Volume2 className="size-4" />
                    )}
                </Button>
            </div>
            <div className="mt-2 mb-4 flex items-center gap-4">
                <span className="shrink-0 rounded-full border p-[5px]">
                    <Avatar className="size-[50px]">
                        <AvatarFallback
                            className={cn(
                                'text-[17px] font-semibold',
                                family
                                    ? 'bg-status-success-bg text-status-success-foreground'
                                    : 'bg-primary-fill/10 text-primary',
                            )}
                        >
                            {initials}
                        </AvatarFallback>
                    </Avatar>
                </span>
                <div className="min-w-0">
                    <h2 className="text-[21px] leading-tight font-semibold tracking-[-0.45px] [overflow-wrap:anywhere]">
                        {name}
                    </h2>
                    <p className="mt-1 text-[13px] leading-relaxed [overflow-wrap:anywhere] text-muted-foreground">
                        {presentation?.caller_detail || detail}
                    </p>
                </div>
            </div>
            <p className="flex items-start gap-2 rounded-lg bg-muted px-3 py-2.5 text-xs leading-relaxed [overflow-wrap:anywhere] text-muted-foreground">
                {family ? (
                    <ShieldCheck className="mt-0.5 size-3.5 shrink-0" />
                ) : (
                    <LockKeyhole className="mt-0.5 size-3.5 shrink-0" />
                )}
                {presentation?.audience || 'Only approved call participants'}
            </p>
            <p
                role="status"
                className="my-4 flex items-center gap-2 text-[13px]"
            >
                <span className="size-[7px] shrink-0 rounded-full bg-status-success" />
                {permissionPending
                    ? 'Waiting for microphone permission…'
                    : silent
                      ? 'Ringtone silenced · Still calling'
                      : 'Incoming call…'}
            </p>
            {problem && (
                <div className="mb-3">
                    <CallProblemNotice problem={problem} />
                </div>
            )}
            <div className="grid grid-cols-2 gap-2.5">
                <Button
                    variant="destructive"
                    className="min-h-[46px]"
                    disabled={working && !permissionPending}
                    onClick={onDecline}
                >
                    <PhoneOff className="size-4" />
                    Decline
                </Button>
                <Button
                    className="min-h-[46px]"
                    disabled={working}
                    onClick={onAnswer}
                >
                    <Phone className="size-4" />
                    Answer
                </Button>
            </div>
            <p className="mt-3 flex items-center justify-center gap-1.5 text-[11px] text-muted-foreground">
                <Pencil className="size-3 shrink-0" />
                {permissionPending
                    ? 'You can still decline this call.'
                    : 'Your work stays open'}
            </p>
            {soundEnabled && !silent && !soundReady && (
                <Button
                    variant="ghost"
                    className="mt-2 min-h-[44px] w-full whitespace-normal"
                    onClick={onEnableSound}
                >
                    <Volume2 className="size-4" />
                    Enable ringtone in this browser
                </Button>
            )}
        </section>
    );
}
