import type { RoomOptions } from 'livekit-client';

export const CHAT_VOICE_ROOM_OPTIONS: Partial<RoomOptions> = {
    adaptiveStream: false,
    dynacast: false,
    // The pinned SDK/server pair fails during single-PC configuration in our
    // browser acceptance check. Use its supported dual-PC transport explicitly.
    singlePeerConnection: false,
};
