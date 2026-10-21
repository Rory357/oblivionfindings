import { useEffect, useRef } from 'react';
import { useChatCalling } from './chat-calling';
import { startChatMessageSounds } from './chat-message-sound-session';
import { useChatPreferences } from './chat-personal-preferences';

export function ChatMessageSounds({
    actorId,
    enabled,
}: {
    actorId: number | null;
    enabled: boolean;
}) {
    return enabled && actorId ? (
        <SoundSession key={actorId} actorId={actorId} />
    ) : null;
}
function SoundSession({ actorId }: { actorId: number }) {
    const preferences = useChatPreferences(actorId);
    const calling = useChatCalling();
    const state = useRef({ enabled: false, activeCall: false });
    const session = useRef<ReturnType<typeof startChatMessageSounds> | null>(
        null,
    );
    useEffect(() => {
        state.current = {
            enabled:
                preferences.loaded &&
                !preferences.error &&
                preferences.values.sound_enabled &&
                !preferences.values.quiet_enabled,
            activeCall: calling?.active === true,
        };
        session.current?.updated();
    }, [
        preferences.loaded,
        preferences.error,
        preferences.values.sound_enabled,
        preferences.values.quiet_enabled,
        calling?.active,
    ]);
    useEffect(() => {
        const current = startChatMessageSounds(actorId, () => state.current);
        session.current = current;
        return () => {
            current.dispose();
            session.current = null;
        };
    }, [actorId]);
    return null;
}
