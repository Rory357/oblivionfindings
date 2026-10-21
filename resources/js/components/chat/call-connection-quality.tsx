import { formatTime } from '@/lib/datetime';
import type { Participant, Room } from 'livekit-client';
import { Signal, SignalLow, WifiOff } from 'lucide-react';
import { useEffect, useState } from 'react';

type Quality = 'excellent' | 'good' | 'poor' | 'lost' | 'unknown';
export type CallConnectionReport = {
    state: string;
    quality: Quality;
    reportedAt: number | null;
};
function quality(value: unknown): Quality {
    return ['excellent', 'good', 'poor', 'lost'].includes(String(value))
        ? (value as Quality)
        : 'unknown';
}
function initial(room: Room): CallConnectionReport {
    return {
        state: room.state,
        quality:
            room.state === 'connected'
                ? quality(room.localParticipant.connectionQuality)
                : 'unknown',
        // Reading an SDK snapshot does not establish when that quality was measured.
        reportedAt: null,
    };
}

/** Observe this room's local participant. Never infer quality from an open UI or another caller. */
export function useCallConnectionQuality(
    room: Room | null | undefined,
): CallConnectionReport | null {
    const [snapshot, setSnapshot] = useState<{
        room: Room;
        report: CallConnectionReport;
    } | null>(null);
    useEffect(() => {
        if (!room) return;
        let live = true;
        let state: string = room.state;
        let unbind = () => {};
        setSnapshot({ room, report: initial(room) });
        const changed = (next: string) => {
            if (!live || next === state) return;
            state = next;
            // An earlier good report must not survive a connection interruption.
            setSnapshot({
                room,
                report: { state, quality: 'unknown', reportedAt: null },
            });
        };
        const reported = (value: unknown, participant: Participant) => {
            if (
                !live ||
                participant !== room.localParticipant ||
                state !== 'connected'
            )
                return;
            const next = quality(value);
            setSnapshot({
                room,
                report: {
                    state,
                    quality: next,
                    reportedAt: next === 'unknown' ? null : Date.now(),
                },
            });
        };
        void import('livekit-client').then(({ RoomEvent }) => {
            if (!live) return;
            if (RoomEvent.ConnectionQualityChanged)
                room.on(RoomEvent.ConnectionQualityChanged, reported);
            if (RoomEvent.ConnectionStateChanged)
                room.on(RoomEvent.ConnectionStateChanged, changed);
            if (state === room.state && state === 'connected') {
                // Catch a report received while the SDK import was resolving.
                setSnapshot({ room, report: initial(room) });
            } else changed(room.state);
            unbind = () => {
                if (RoomEvent.ConnectionQualityChanged)
                    room.off(RoomEvent.ConnectionQualityChanged, reported);
                if (RoomEvent.ConnectionStateChanged)
                    room.off(RoomEvent.ConnectionStateChanged, changed);
            };
        });
        return () => {
            live = false;
            unbind();
        };
    }, [room]);
    return room
        ? snapshot?.room === room
            ? snapshot.report
            : initial(room)
        : null;
}

export function CallConnectionQuality({
    report,
    compact = false,
}: {
    report: CallConnectionReport | null;
    compact?: boolean;
}) {
    if (!report) return null;
    const reconnecting =
        report.state === 'reconnecting' ||
        report.state === 'signalReconnecting';
    const disconnected = report.state === 'disconnected';
    const poor = report.state === 'connected' && report.quality === 'poor';
    const lost = report.state === 'connected' && report.quality === 'lost';
    // The dock already presents reconnecting/connecting state. Keep routine detail in the call view.
    if (compact && !poor && !lost) return null;
    const label = disconnected
        ? 'Disconnected'
        : reconnecting
          ? 'Reconnecting'
          : report.state !== 'connected'
            ? 'Connecting'
            : {
                  excellent: 'Excellent',
                  good: 'Good',
                  poor: 'Weak',
                  lost: 'Interrupted',
                  unknown: 'Waiting for a quality report',
              }[report.quality];
    const Icon =
        disconnected || lost
            ? WifiOff
            : poor || reconnecting
              ? SignalLow
              : Signal;
    const guidance =
        poor || lost
            ? 'Audio may break up. Check your network connection or continue by text.'
            : reconnecting
              ? 'Your microphone mute setting is kept while the call reconnects.'
              : null;
    return (
        <div
            role="status"
            className={
                compact
                    ? 'mt-3 space-y-1 rounded-xl border bg-muted p-3 text-sm'
                    : 'mt-3 space-y-1 rounded-xl border p-3 text-sm'
            }
        >
            <p className="flex items-start gap-2">
                <Icon aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                <span>
                    Your connection:{' '}
                    <span className="font-medium">{label}</span>
                </span>
            </p>
            {guidance && <p className="text-muted-foreground">{guidance}</p>}
            {!compact && (
                <p className="text-xs text-muted-foreground">
                    {report.reportedAt
                        ? `Last quality update ${formatTime(report.reportedAt)}. `
                        : ''}
                    This describes your connection to the call.
                </p>
            )}
        </div>
    );
}
