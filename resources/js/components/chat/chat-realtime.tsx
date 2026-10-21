import {
    createContext,
    useContext,
    useEffect,
    useState,
    type ReactNode,
} from 'react';
import {
    connectChatRealtime,
    type ChatLiveState,
} from './chat-realtime-connection';

const Context = createContext<ChatLiveState>('off');
export const useChatRealtime = () => useContext(Context);

type ProviderProps = {
    actorId: number | null;
    enabled: boolean;
    children: ReactNode;
};
export function ChatRealtimeProvider(props: ProviderProps) {
    return (
        <RealtimeSession key={`${props.actorId}:${props.enabled}`} {...props} />
    );
}
function RealtimeSession({ actorId, enabled, children }: ProviderProps) {
    const [state, setState] = useState<ChatLiveState>('off');
    useEffect(() => {
        if (!enabled || !actorId) return;
        return connectChatRealtime(actorId, setState, () => {
            window.dispatchEvent(new Event('oblivion:chat-refresh'));
            window.dispatchEvent(new Event('oblivion:chat-unread-changed'));
        });
    }, [actorId, enabled]);
    return <Context.Provider value={state}>{children}</Context.Provider>;
}
