import { Button } from '@/components/ui/button';
import { Bookmark, Check, Loader2, Undo2, X } from 'lucide-react';
import {
    createContext,
    useContext,
    useEffect,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import { hubRequest } from './_api';
import type { HubProps } from './_types';

export type BookmarkSubject = {
    subject_type: 'page' | 'event' | 'media';
    subject_id: number;
};
type State = BookmarkSubject & {
    actor_id: number;
    bookmarked: boolean;
    version: string | null;
};
type Receipt = State & {
    saved: true;
    previous_saved: boolean;
    changed: boolean;
};
type Undo = { state: State; previous: boolean; title: string };
type Context = {
    bookmarks: HubProps['bookmarks'];
    busy: boolean;
    blocked: boolean;
    change: (subject: BookmarkSubject, saved: boolean, title: string) => void;
};
const BookmarkContext = createContext<Context | null>(null);
export const useHubBookmarks = () => useContext(BookmarkContext);
const keyFor = (subject: { subject_type: string; subject_id: number }) =>
    `${subject.subject_type}:${subject.subject_id}`;

function confirmedState(
    value: State,
    actorId: number,
    subject: BookmarkSubject,
): value is State {
    return (
        !!value &&
        value.actor_id === actorId &&
        value.subject_type === subject.subject_type &&
        value.subject_id === subject.subject_id &&
        typeof value.bookmarked === 'boolean' &&
        (value.bookmarked
            ? typeof value.version === 'string' &&
              /^[a-f0-9]{64}$/i.test(value.version)
            : value.version === null)
    );
}

/** One actor-scoped save session keeps readers, lists and Undo on the same receipt. */
export function HubBookmarksProvider({
    actorId,
    initial,
    children,
}: {
    actorId: number;
    initial: HubProps['bookmarks'];
    children: ReactNode;
}) {
    const [bookmarks, setBookmarks] = useState(initial);
    const [busy, setBusy] = useState(false);
    const [undo, setUndo] = useState<Undo | null>(null);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [recovery, setRecovery] = useState<{
        subject: BookmarkSubject;
        title: string;
    } | null>(null);
    const alive = useRef(false);
    const pending = useRef(false);
    const confirmed = useRef(new Map<string, State>());
    const current = useRef(bookmarks);
    current.current = bookmarks;
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    useEffect(() => {
        // A page visit may have started before a confirmed write/recovery. Its
        // unversioned list must not replace a newer exact-subject receipt.
        setBookmarks([
            ...initial.filter((item) => !confirmed.current.has(keyFor(item))),
            ...Array.from(confirmed.current.values())
                .filter((item) => item.bookmarked)
                .map((item) => ({
                    subject_type: item.subject_type,
                    subject_id: item.subject_id,
                    version: item.version,
                })),
        ]);
    }, [initial]);
    const accept = (state: State) => {
        confirmed.current.set(keyFor(state), state);
        setBookmarks((items) => [
            ...items.filter((item) => keyFor(item) !== keyFor(state)),
            ...(state.bookmarked
                ? [
                      {
                          subject_type: state.subject_type,
                          subject_id: state.subject_id,
                          version: state.version,
                      },
                  ]
                : []),
        ]);
    };
    const read = async (subject: BookmarkSubject) => {
        const result = await hubRequest<State>(
            `/company-hub/bookmarks/state?actor_id=${actorId}&subject_type=${subject.subject_type}&subject_id=${subject.subject_id}`,
            'GET',
        );
        if (!confirmedState(result, actorId, subject))
            throw new Error('The current saved state could not be confirmed.');
        return result;
    };
    const write = async (
        subject: BookmarkSubject,
        saved: boolean,
        title: string,
        inverse?: Undo,
    ) => {
        if (pending.current || recovery) return;
        pending.current = true;
        setBusy(true);
        setError('');
        setMessage('');
        setUndo(null);
        let sent = false;
        try {
            const known = current.current.find(
                (item) => keyFor(item) === keyFor(subject),
            );
            let before: State = inverse?.state ?? {
                ...subject,
                actor_id: actorId,
                bookmarked: !!known,
                version: known?.version ?? null,
            };
            if (!inverse && before.bookmarked && !before.version) {
                before = await read(subject);
                if (!alive.current) return;
                accept(before);
                if (before.bookmarked !== !!known)
                    throw new Error(
                        'This saved item changed elsewhere. The current choice is now shown; choose again to change it.',
                    );
            }
            if (!alive.current) return;
            sent = true;
            const result = await hubRequest<Receipt>(
                '/company-hub/bookmarks',
                'POST',
                {
                    hub_receipt: true,
                    actor_id: actorId,
                    subject_type: subject.subject_type,
                    subject_id: subject.subject_id,
                    saved,
                    expected_saved: before.bookmarked,
                    expected_version: before.version,
                },
            );
            if (!alive.current) return;
            if (
                !confirmedState(result, actorId, subject) ||
                result.saved !== true ||
                result.bookmarked !== saved ||
                result.previous_saved !== before.bookmarked ||
                result.changed !== (saved !== before.bookmarked)
            )
                throw new Error('The saved change could not be confirmed.');
            accept(result);
            setMessage(
                inverse
                    ? `Saved-item change undone for “${title}”.`
                    : saved
                      ? `“${title}” saved for later.`
                      : `“${title}” removed from saved.`,
            );
            if (result.changed && !inverse)
                setUndo({
                    state: result,
                    previous: result.previous_saved,
                    title,
                });
        } catch (err) {
            if (!alive.current) return;
            setError((err as Error).message);
            // Re-read after any attempted write, including a conflict; never reverse an uncertain outcome.
            if (sent) setRecovery({ subject, title });
        } finally {
            if (alive.current) {
                pending.current = false;
                setBusy(false);
            }
        }
    };
    const recover = async () => {
        if (!recovery || pending.current) return;
        pending.current = true;
        setBusy(true);
        setError('');
        try {
            const result = await read(recovery.subject);
            if (!alive.current) return;
            accept(result);
            setUndo(null);
            setRecovery(null);
            setMessage(
                `“${recovery.title}” is currently ${result.bookmarked ? 'saved for later' : 'not saved'}.`,
            );
        } catch (err) {
            if (alive.current) setError((err as Error).message);
        } finally {
            if (alive.current) {
                pending.current = false;
                setBusy(false);
            }
        }
    };
    return (
        <BookmarkContext.Provider
            value={{
                bookmarks,
                busy,
                blocked: !!recovery,
                change: (subject, saved, title) => {
                    void write(subject, saved, title);
                },
            }}
        >
            {children}
            {(message || error || busy || recovery) && (
                <aside
                    aria-label="Saved-item changes"
                    className="fixed right-6 bottom-6 z-50 w-[min(440px,calc(100vw-3rem))] rounded-2xl border border-border bg-card p-4 shadow-xl"
                >
                    <div className="flex items-start gap-3">
                        {busy ? (
                            <Loader2 className="mt-0.5 size-5 shrink-0 animate-spin text-primary" />
                        ) : (
                            <Check className="mt-0.5 size-5 shrink-0 text-primary" />
                        )}
                        <div className="min-w-0 flex-1 space-y-2">
                            {busy && (
                                <p role="status" className="text-sm">
                                    Updating your saved items…
                                </p>
                            )}
                            {message && (
                                <p role="status" className="text-sm">
                                    {message}
                                </p>
                            )}
                            {error && (
                                <p
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    {error}
                                </p>
                            )}
                            {recovery && (
                                <>
                                    <p className="text-xs text-muted-foreground">
                                        Check the current saved state before
                                        making another change.
                                    </p>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={busy}
                                        onClick={() => void recover()}
                                    >
                                        Check saved state
                                    </Button>
                                </>
                            )}
                            {undo && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={busy}
                                    onClick={() =>
                                        void write(
                                            undo.state,
                                            undo.previous,
                                            undo.title,
                                            undo,
                                        )
                                    }
                                >
                                    <Undo2 className="size-4" />
                                    Undo saved change
                                </Button>
                            )}
                        </div>
                        {!busy && !recovery && (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-7 shrink-0"
                                aria-label="Dismiss saved-item message"
                                onClick={() => {
                                    setMessage('');
                                    setError('');
                                    setUndo(null);
                                }}
                            >
                                <X className="size-4" />
                            </Button>
                        )}
                    </div>
                </aside>
            )}
        </BookmarkContext.Provider>
    );
}

export function HubBookmarkButton({
    subject,
    title,
    saved,
    compact = false,
    disabled = false,
    savedLabel = 'Remove from saved',
    saveLabel = 'Save for later',
}: {
    subject: BookmarkSubject;
    title: string;
    saved: boolean;
    compact?: boolean;
    disabled?: boolean;
    savedLabel?: string;
    saveLabel?: string;
}) {
    const context = useHubBookmarks();
    const isSaved = context
        ? context.bookmarks.some((item) => keyFor(item) === keyFor(subject))
        : saved;
    return (
        <Button
            variant={compact ? 'ghost' : 'outline'}
            size={compact ? 'icon' : 'default'}
            disabled={disabled || !context || context.busy || context.blocked}
            aria-label={
                compact ? `${isSaved ? 'Unsave' : 'Save'} ${title}` : undefined
            }
            aria-pressed={isSaved}
            onClick={() => context?.change(subject, !isSaved, title)}
        >
            <Bookmark
                className={`size-4 ${isSaved ? 'fill-current text-primary' : ''}`}
            />
            {!compact && (isSaved ? savedLabel : saveLabel)}
        </Button>
    );
}
