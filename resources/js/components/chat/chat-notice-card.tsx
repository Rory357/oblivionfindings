import { Button } from '@/components/ui/button';
import { formatRelative } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import { MessageSquare } from 'lucide-react';
import {
    createContext,
    useContext,
    useEffect,
    useState,
    type ReactNode,
} from 'react';
import { chatRequest } from './api';
import { useChatPreferences } from './chat-personal-preferences';
import { watchChatUpdates } from './chat-update-watch';

type PreviewMap = Record<string, string | null>;
const Context = createContext<PreviewMap>({});
const uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;
export function ChatNoticePreviews(props: {
    actorId: number | null;
    notificationIds: string[];
    children: ReactNode;
}) {
    return <PreviewSession key={props.actorId} {...props} />;
}
function PreviewSession({
    actorId,
    notificationIds,
    children,
}: {
    actorId: number | null;
    notificationIds: string[];
    children: ReactNode;
}) {
    const personal = useChatPreferences(actorId ?? undefined);
    const permitted = Boolean(
        actorId && personal.loaded && personal.values.show_previews,
    );
    const idsKey = JSON.stringify(
        [...new Set(notificationIds.filter((id) => uuid.test(id)))].sort(),
    );
    const [snapshot, setSnapshot] = useState<{
        key: string;
        previews: PreviewMap;
    } | null>(null);
    const [visible, setVisible] = useState(
        () =>
            typeof document !== 'undefined' &&
            document.visibilityState === 'visible',
    );
    useEffect(() => {
        const change = () => {
            setSnapshot(null);
            setVisible(document.visibilityState === 'visible');
        };
        document.addEventListener('visibilitychange', change);
        return () => document.removeEventListener('visibilitychange', change);
    }, []);
    useEffect(() => {
        const ids = JSON.parse(idsKey) as string[];
        if (!permitted || !visible || !actorId || !ids.length) {
            setSnapshot(null);
            return;
        }
        const controller = new AbortController();
        const stop = watchChatUpdates(
            async () => {
                try {
                    const result: PreviewMap = {};
                    // One request per page-sized batch, never a polling request per card.
                    for (let i = 0; i < ids.length; i += 30) {
                        const batch = ids.slice(i, i + 30);
                        const query = new URLSearchParams();
                        batch.forEach((id) => query.append('ids[]', id));
                        const page = await chatRequest<{
                            actor_id: number;
                            previews: PreviewMap;
                        }>(`/chat/api/notification-previews?${query}`, {
                            headers: { 'X-Chat-Actor': String(actorId) },
                            signal: AbortSignal.any([
                                controller.signal,
                                AbortSignal.timeout(15000),
                            ]),
                        });
                        if (controller.signal.aborted) return false;
                        if (
                            page.actor_id !== actorId ||
                            !page.previews ||
                            Object.keys(page.previews).length !==
                                batch.length ||
                            batch.some(
                                (id) =>
                                    !(id in page.previews) ||
                                    (page.previews[id] !== null &&
                                        (typeof page.previews[id] !==
                                            'string' ||
                                            page.previews[id]!.length > 2000)),
                            )
                        )
                            throw new Error('Invalid Chat preview');
                        Object.assign(result, page.previews);
                    }
                    if (!controller.signal.aborted)
                        setSnapshot({ key: idsKey, previews: result });
                    return true;
                } catch {
                    if (!controller.signal.aborted) setSnapshot(null);
                    return false;
                }
            },
            { interval: () => 30000 },
        );
        return () => {
            controller.abort();
            stop();
        };
    }, [actorId, idsKey, permitted, visible]);
    return (
        <Context.Provider
            value={
                permitted && visible && snapshot?.key === idsKey
                    ? snapshot.previews
                    : {}
            }
        >
            {children}
        </Context.Provider>
    );
}
export function ChatNoticeCard({
    id,
    eventKey,
    createdAt,
    unread,
    onMarkRead,
}: {
    id: string;
    eventKey?: string;
    createdAt: string;
    unread: boolean;
    onMarkRead: () => void;
}) {
    const previews = useContext(Context);
    const title =
        eventKey === 'chat.mentioned'
            ? 'You were mentioned in Chat'
            : eventKey === 'chat.family.reply'
              ? 'New reply from the care team'
              : eventKey === 'chat.family.assigned'
                ? 'Family work assigned to you'
                : eventKey === 'chat.call.missed'
                  ? 'Missed Chat call'
                  : 'New Chat message';
    return (
        <article
            aria-label={title}
            className="flex min-w-0 gap-3 rounded-xl border bg-background p-4"
        >
            <MessageSquare className="mt-1 size-5 shrink-0 text-primary" />
            <div className="min-w-0 flex-1 space-y-2">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 className="text-sm font-semibold">{title}</h3>
                    <span className="text-xs text-muted-foreground">
                        {formatRelative(createdAt)}
                    </span>
                </div>
                {unread && (
                    <p className="text-xs font-medium text-primary">Unread</p>
                )}
                <p className="text-sm [overflow-wrap:anywhere] text-muted-foreground">
                    {previews[id] ||
                        (eventKey === 'chat.family.assigned'
                            ? 'Open Chat to review the handover.'
                            : eventKey === 'chat.call.missed'
                              ? 'Open Chat to review your missed calls.'
                              : 'Open Chat to read the message.')}
                </p>
                <div className="flex flex-wrap gap-2">
                    {uuid.test(id) && (
                        <Button
                            asChild
                            variant="outline"
                            className="frontline-tap"
                        >
                            <Link href={`/chat/notifications/${id}`}>
                                Open Chat
                            </Link>
                        </Button>
                    )}
                    {unread && (
                        <Button
                            variant="ghost"
                            className="frontline-tap"
                            onClick={onMarkRead}
                        >
                            Mark as read
                        </Button>
                    )}
                </div>
            </div>
        </article>
    );
}
