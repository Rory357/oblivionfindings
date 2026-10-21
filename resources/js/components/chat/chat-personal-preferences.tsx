import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import { chatRequest, isChatAccessDenied } from './api';
import { watchChatUpdates } from './chat-update-watch';

export type ChatPreferences = {
    text_size: 'normal' | 'large' | 'extra_large';
    spacing: 'comfortable' | 'compact';
    sending: 'enter_sends' | 'enter_newline';
    theme: 'light' | 'dark' | 'system';
    quiet_enabled: boolean;
    show_previews: boolean;
    sound_enabled: boolean;
};
export const defaultChatPreferences: ChatPreferences = {
    text_size: 'normal',
    spacing: 'comfortable',
    sending: 'enter_sends',
    theme: 'system',
    quiet_enabled: false,
    show_previews: false,
    sound_enabled: true,
};
export type ChatPreferencesSnapshot = {
    actor_id: number;
    version: string;
    values: ChatPreferences;
};
type PreferencesContext = {
    actorId: number | null;
    values: ChatPreferences;
    loaded: boolean;
    saving: boolean;
    error: string;
    save: (changes: Partial<ChatPreferences>) => Promise<void>;
    refresh: () => Promise<void>;
};
const Context = createContext<PreferencesContext>({
    actorId: null,
    values: defaultChatPreferences,
    loaded: false,
    saving: false,
    error: '',
    save: async () => {},
    refresh: async () => {},
});
export function useChatPreferences(actorId?: number) {
    const context = useContext(Context);
    return actorId !== undefined && context.actorId !== actorId
        ? { ...context, values: defaultChatPreferences, loaded: false }
        : context;
}
export function chatReadingClass(size: ChatPreferences['text_size']) {
    return size === 'extra_large'
        ? 'text-xl'
        : size === 'large'
          ? 'text-lg'
          : 'text-sm';
}
export function chatSendingHint(sending: ChatPreferences['sending']) {
    return sending === 'enter_newline'
        ? 'Enter adds a line · Use Send to send'
        : 'Enter to send · Shift + Enter for a new line';
}
function validate(snapshot: ChatPreferencesSnapshot, actorId: number) {
    if (
        !snapshot ||
        snapshot.actor_id !== actorId ||
        !/^[a-f0-9]{64}$/.test(snapshot.version) ||
        !['normal', 'large', 'extra_large'].includes(
            snapshot.values?.text_size,
        ) ||
        !['comfortable', 'compact'].includes(snapshot.values?.spacing) ||
        !['enter_sends', 'enter_newline'].includes(snapshot.values?.sending) ||
        !['light', 'dark', 'system'].includes(snapshot.values?.theme) ||
        ['quiet_enabled', 'show_previews', 'sound_enabled'].some(
            (key) =>
                typeof snapshot.values[key as keyof ChatPreferences] !==
                'boolean',
        )
    )
        throw new Error(
            'Your Chat preferences could not be verified. Review the latest preferences.',
        );
    return snapshot;
}
export function ChatPreferencesProvider(props: {
    actorId: number | null;
    enabled: boolean;
    children: ReactNode;
}) {
    return (
        <PreferencesSession
            key={`${props.actorId}:${props.enabled}`}
            {...props}
        />
    );
}
function PreferencesSession({
    actorId,
    enabled,
    children,
}: {
    actorId: number | null;
    enabled: boolean;
    children: ReactNode;
}) {
    const [snapshot, setSnapshot] = useState<ChatPreferencesSnapshot | null>(
        null,
    );
    const current = useRef(snapshot);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const busy = useRef(false);
    const uncertain = useRef(false);
    const generation = useRef(0);
    const live = useRef(true);
    const refresh = useCallback(async () => {
        if (!live.current || !enabled || !actorId || busy.current) return;
        const sequence = ++generation.current;
        try {
            const next = validate(
                await chatRequest<ChatPreferencesSnapshot>(
                    '/chat/api/personal-preferences',
                    {
                        headers: { 'X-Chat-Actor': String(actorId) },
                        signal: AbortSignal.timeout(15000),
                    },
                ),
                actorId,
            );
            if (!live.current || generation.current !== sequence) return;
            current.current = next;
            setSnapshot(next);
            uncertain.current = false;
            setError('');
        } catch (failure) {
            if (!live.current || generation.current !== sequence) return;
            if (isChatAccessDenied(failure)) {
                current.current = null;
                setSnapshot(null);
            }
            setError(
                (failure as Error).message ||
                    'Your preferences could not be loaded.',
            );
        }
    }, [actorId, enabled]);
    useEffect(() => {
        live.current = true;
        if (!enabled || !actorId) return;
        const stop = watchChatUpdates(
            async () => {
                if (uncertain.current) return false;
                await refresh();
                return Boolean(current.current);
            },
            { interval: () => 30000 },
        );
        return () => {
            live.current = false;
            stop();
        };
    }, [actorId, enabled, refresh]);
    async function save(changes: Partial<ChatPreferences>) {
        if (
            !live.current ||
            !enabled ||
            !actorId ||
            !current.current ||
            busy.current ||
            uncertain.current
        )
            return;
        busy.current = true;
        const sequence = ++generation.current;
        setSaving(true);
        setError('');
        try {
            const next = validate(
                await chatRequest<ChatPreferencesSnapshot>(
                    '/chat/api/personal-preferences',
                    {
                        method: 'PATCH',
                        headers: { 'X-Chat-Actor': String(actorId) },
                        body: JSON.stringify({
                            version: current.current.version,
                            values: changes,
                        }),
                        signal: AbortSignal.timeout(15000),
                    },
                ),
                actorId,
            );
            if (!live.current || generation.current !== sequence) return;
            current.current = next;
            setSnapshot(next);
            window.dispatchEvent(new Event('oblivion:chat-refresh'));
        } catch (failure) {
            if (!live.current || generation.current !== sequence) return;
            uncertain.current = true;
            if (isChatAccessDenied(failure)) {
                current.current = null;
                setSnapshot(null);
            }
            const message =
                (failure as Error).message ||
                'Your change could not be confirmed.';
            setError(
                /review.*preferences/i.test(message)
                    ? message
                    : `${message} Review the latest preferences before changing them again.`,
            );
        } finally {
            busy.current = false;
            if (live.current) setSaving(false);
        }
    }
    return (
        <Context.Provider
            value={{
                actorId,
                values: snapshot?.values ?? {
                    ...defaultChatPreferences,
                    sending: 'enter_newline',
                },
                loaded: Boolean(snapshot),
                saving,
                error,
                save,
                refresh,
            }}
        >
            {children}
        </Context.Provider>
    );
}
