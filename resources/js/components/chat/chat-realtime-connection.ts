import { connectLive, type LiveConnectionState } from './live/connection';
import { chatSync } from './live/sync';

export type ChatLiveState = LiveConnectionState;

/**
 * Start Oblivion Live for the rendered account. Its ticket request answers 404 while live
 * updates are switched off and 403 when this account may not use Chat; either way the
 * connection stops and the one sync loop keeps Chat current by polling.
 */
export function connectChatRealtime(
    actorId: number,
    onState: (state: ChatLiveState) => void,
    onRefresh: () => void,
) {
    let previous: ChatLiveState | null = null;
    const live = connectLive({
        actorId,
        onState: (state) => {
            // Losing access after being connected: let every view re-check what it shows.
            if (state === 'off' && previous !== null && previous !== 'off')
                onRefresh();
            previous = state;
            onState(state);
        },
        onEvent: (event) => chatSync.receive(event),
        // A new connection catches up once through the app.
        onReady: (resume) => {
            if (resume) chatSync.resync();
        },
    });
    return () => live.stop();
}
