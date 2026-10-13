import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate, formatRelative } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    Bell,
    BookOpen,
    Bookmark,
    CalendarDays,
    Check,
    CheckCheck,
    HeartHandshake,
    Megaphone,
    Newspaper,
    Search,
    Settings2,
    Sparkles,
    UsersRound,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { hubRequest } from './_api';
import { InterestsDialog } from './_interests';
import type { HubEvent, HubInterests, HubProps, HubUpdate } from './_types';

const labels = {
    news: 'Company stories',
    resources: 'Guides & resources',
    teams: 'Team spaces',
    campaigns: 'Campaigns',
    events: 'Events',
    shoutouts: 'Shout-outs',
    announcements: 'Announcements',
};
const icons = {
    news: Newspaper,
    resources: BookOpen,
    teams: UsersRound,
    campaigns: Sparkles,
    events: CalendarDays,
    shoutouts: HeartHandshake,
    announcements: Megaphone,
};

export function UpdatesFeed({
    data,
    following = false,
    onEvent,
}: {
    data: HubProps;
    following?: boolean;
    onEvent: (event: HubEvent) => void;
}) {
    const [interests, setInterests] = useState(data.interests);
    const [choosing, setChoosing] = useState(false);
    const [filter, setFilter] = useState('all');
    const [type, setType] = useState('all');
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(1);
    const [seen, setSeen] = useState<string[]>([]);
    const [bookmarks, setBookmarks] = useState(data.bookmarks);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const [undo, setUndo] = useState<{
        marked: string[];
        batch: string;
    } | null>(null);
    useEffect(() => {
        setPage(1);
    }, [filter, type, query]);
    useEffect(() => {
        setSeen([]);
        setUndo(null);
    }, [data.updates]);
    useEffect(() => {
        setInterests((current) =>
            current &&
            data.interests &&
            current.version > data.interests.version
                ? current
                : data.interests,
        );
    }, [data.interests]);
    useEffect(() => {
        setBookmarks(data.bookmarks);
    }, [data.bookmarks]);
    const isSaved = (item: HubUpdate) =>
        item.bookmark &&
        bookmarks.some(
            (b) =>
                b.subject_type === item.bookmark!.subject_type &&
                b.subject_id === item.bookmark!.subject_id,
        );
    const items = (data.updates ?? [])
        .map((item) => ({
            ...item,
            unread: item.unread && !seen.includes(item.key),
        }))
        .filter(
            (item) =>
                !following ||
                (item.page_id && data.followedPages?.includes(item.page_id)) ||
                item.topics.some((topic) =>
                    interests?.topics.includes(topic),
                ) ||
                item.teams.some((id) => interests?.teams.includes(id)),
        );
    const counts = {
        all: items.length,
        unread: items.filter((i) => i.unread).length,
        attention: items.filter((i) => i.required).length,
        saved: items.filter(isSaved).length,
    };
    const matches = items.filter(
        (item) =>
            (filter === 'all' ||
                (filter === 'unread' && item.unread) ||
                (filter === 'attention' && item.required) ||
                (filter === 'saved' && isSaved(item))) &&
            (type === 'all' || item.type === type) &&
            `${item.title} ${item.summary} ${item.owner} ${item.topics.join(' ')} ${item.teams.map((id) => interests?.available_teams.find((t) => t.id === id)?.name ?? '').join(' ')}`
                .toLowerCase()
                .includes(query.trim().toLowerCase()),
    );
    const pageCount = Math.max(1, Math.ceil(matches.length / 60));
    const currentPage = Math.min(page, pageCount);
    const shown = matches.slice((currentPage - 1) * 60, currentPage * 60);
    const markSeen = async (keys: string[]) => {
        if (!keys.length || busy || data.demo) return;
        setBusy(true);
        setError('');
        try {
            const result = await hubRequest<{
                marked: string[];
                batch: string;
            }>('/company-hub/seen', 'POST', { keys });
            setSeen((current) => [...new Set([...current, ...result.marked])]);
            setUndo(result.marked.length ? result : null);
            setMessage(
                `${result.marked.length} ${result.marked.length === 1 ? 'update' : 'updates'} marked as seen.`,
            );
        } catch (err) {
            setError((err as Error).message);
        } finally {
            setBusy(false);
        }
    };
    const undoSeen = async () => {
        if (!undo || busy) return;
        setBusy(true);
        setError('');
        try {
            await hubRequest('/company-hub/seen', 'POST', {
                keys: undo.marked,
                undo_batch: undo.batch,
            });
            setSeen((current) =>
                current.filter((key) => !undo.marked.includes(key)),
            );
            setUndo(null);
            setMessage('Seen change undone.');
        } catch (err) {
            setError((err as Error).message);
        } finally {
            setBusy(false);
        }
    };
    const save = (item: HubUpdate) => {
        if (!item.bookmark || busy || data.demo) return;
        const saved = !!isSaved(item);
        setBusy(true);
        setError('');
        router.post(
            '/company-hub/bookmarks',
            { ...item.bookmark, saved: !saved },
            {
                preserveScroll: true,
                onSuccess: () =>
                    setBookmarks((current) =>
                        saved
                            ? current.filter(
                                  (b) =>
                                      b.subject_type !==
                                          item.bookmark!.subject_type ||
                                      b.subject_id !==
                                          item.bookmark!.subject_id,
                              )
                            : [
                                  ...current.filter(
                                      (b) =>
                                          b.subject_type !==
                                              item.bookmark!.subject_type ||
                                          b.subject_id !==
                                              item.bookmark!.subject_id,
                                  ),
                                  item.bookmark!,
                              ],
                    ),
                onError: (errors) => setError(Object.values(errors).join(' ')),
                onFinish: () => setBusy(false),
            },
        );
    };
    const saveInterests = (result: HubInterests) => {
        setInterests(result);
        setMessage('Your interests have been saved.');
    };
    return (
        <section
            className="hub-updates-workspace space-y-5"
            aria-label={following ? 'Your interests' : 'Your company updates'}
        >
            {following ? (
                <div className="hub-interests-header">
                    <div>
                        <span className="text-xs font-semibold tracking-wider text-primary uppercase">
                            Your interests
                        </span>
                        <h2 className="mt-1 text-2xl font-semibold">
                            More of what matters to you
                        </h2>
                        <p className="mt-2 max-w-xl text-sm text-muted-foreground">
                            Stories, resources and updates from the topics,
                            teams and pages you follow.
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        disabled={!interests || data.demo}
                        onClick={() => setChoosing(true)}
                    >
                        <Settings2 className="size-4" />
                        Manage interests
                    </Button>
                    <div
                        className="flex w-full flex-wrap gap-2"
                        aria-label="Followed interests"
                    >
                        {interests?.topics.map((topic) => (
                            <span className="hub-interest-chip" key={topic}>
                                {topic}
                            </span>
                        ))}
                        {interests?.available_teams
                            .filter((team) => interests.teams.includes(team.id))
                            .map((team) => (
                                <span
                                    className="hub-interest-chip"
                                    key={team.id}
                                >
                                    <UsersRound className="size-3" />
                                    {team.name}
                                </span>
                            ))}
                        {!!data.followedPages?.length && (
                            <span className="hub-interest-chip">
                                {data.followedPages.length} followed{' '}
                                {data.followedPages.length === 1
                                    ? 'page'
                                    : 'pages'}
                            </span>
                        )}
                    </div>
                </div>
            ) : (
                <div className="hub-update-summary">
                    <span className="hub-update-summary-icon">
                        <Bell className="size-6" />
                    </span>
                    <div>
                        <h2 className="text-lg font-semibold">
                            {counts.unread
                                ? `${counts.unread} new ${counts.unread === 1 ? 'update' : 'updates'} to explore`
                                : 'You’re caught up'}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            The latest from across your company, in one place.
                        </p>
                    </div>
                    <Button
                        variant="ghost"
                        onClick={() => {
                            setFilter('attention');
                            setType('all');
                            setQuery('');
                        }}
                    >
                        {counts.attention
                            ? `${counts.attention} to confirm`
                            : 'No confirmations pending'}
                        <ArrowUpRight className="size-4" />
                    </Button>
                </div>
            )}
            <div
                className="flex flex-wrap items-center gap-2"
                aria-label="Update filters"
            >
                {(
                    [
                        ['all', 'All updates'],
                        ['unread', 'Unread'],
                        ['attention', 'Needs attention'],
                        ['saved', 'Saved'],
                    ] as const
                ).map(([key, label]) => (
                    <Button
                        key={key}
                        size="sm"
                        variant={filter === key ? 'secondary' : 'ghost'}
                        aria-pressed={filter === key}
                        onClick={() => setFilter(key)}
                    >
                        {label}
                        <span className="hub-filter-count">{counts[key]}</span>
                    </Button>
                ))}
            </div>
            <div className="hub-library-tools">
                <div className="relative min-w-56 flex-1">
                    <Search className="absolute top-2.5 left-3 size-4 text-muted-foreground" />
                    <Input
                        className="pl-9"
                        aria-label="Search your updates"
                        placeholder="Search a title, topic or team…"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                    />
                </div>
                <select
                    className="hub-native-select"
                    aria-label="Update type"
                    value={type}
                    onChange={(e) => setType(e.target.value)}
                >
                    <option value="all">All types</option>
                    {Object.entries(labels).map(([key, label]) => (
                        <option key={key} value={key}>
                            {label}
                        </option>
                    ))}
                </select>
                <Button
                    variant="outline"
                    disabled={busy || data.demo || !shown.some((i) => i.unread)}
                    onClick={() =>
                        void markSeen(
                            shown.filter((i) => i.unread).map((i) => i.key),
                        )
                    }
                >
                    <CheckCheck className="size-4" />
                    Mark shown as seen
                </Button>
            </div>
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
            {message && (
                <div className="flex items-center gap-3 text-sm" role="status">
                    <Check className="size-4 text-primary" />
                    {message}
                    {undo && (
                        <Button
                            variant="link"
                            size="sm"
                            disabled={busy}
                            onClick={undoSeen}
                        >
                            Undo
                        </Button>
                    )}
                </div>
            )}
            <p className="text-xs text-muted-foreground" role="status">
                {matches.length} {matches.length === 1 ? 'update' : 'updates'}
                {matches.length > shown.length
                    ? ` · Page ${currentPage} of ${pageCount}`
                    : ''}
            </p>
            <div className="hub-update-list">
                {shown.map((item) => {
                    const Icon = icons[item.type];
                    const event = item.event_id
                        ? data.events.find((e) => e.id === item.event_id)
                        : null;
                    const content = (
                        <>
                            <span className="hub-update-art">
                                {item.cover_url ? (
                                    <img
                                        src={item.cover_url}
                                        alt=""
                                        loading="lazy"
                                    />
                                ) : (
                                    <Icon className="size-6" aria-hidden />
                                )}
                            </span>
                            <span className="hub-update-copy">
                                <span className="hub-update-overline">
                                    {item.owner}
                                    {item.unread && (
                                        <span className="hub-update-new">
                                            New
                                        </span>
                                    )}
                                </span>
                                <strong>{item.title}</strong>
                                <span className="hub-update-description">
                                    {item.summary}
                                </span>
                                <small>
                                    {item.required ? (
                                        <span className="text-primary">
                                            Read confirmation needed
                                        </span>
                                    ) : (
                                        labels[item.type]
                                    )}{' '}
                                    ·{' '}
                                    {item.starts_at
                                        ? `Next date ${formatDate(item.starts_at)}`
                                        : formatRelative(item.at)}
                                </small>
                            </span>
                        </>
                    );
                    return (
                        <article
                            className="hub-discovery-update"
                            key={item.key}
                        >
                            {event ? (
                                // Custom full-row event opener shares the article link's card layout.
                                // eslint-disable-next-line no-restricted-syntax
                                <button
                                    className="hub-update-open"
                                    onClick={() => {
                                        if (item.unread)
                                            void markSeen([item.key]);
                                        onEvent(event);
                                    }}
                                >
                                    {content}
                                </button>
                            ) : (
                                <Link
                                    className="hub-update-open"
                                    href={item.url}
                                    onClick={() => {
                                        if (item.unread)
                                            void markSeen([item.key]);
                                    }}
                                >
                                    {content}
                                </Link>
                            )}
                            <div className="hub-update-actions">
                                {item.bookmark && (
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        disabled={busy || data.demo}
                                        aria-label={`${isSaved(item) ? 'Unsave' : 'Save'} ${item.title}`}
                                        aria-pressed={!!isSaved(item)}
                                        onClick={() => save(item)}
                                    >
                                        <Bookmark
                                            className={`size-4 ${isSaved(item) ? 'fill-current text-primary' : ''}`}
                                        />
                                    </Button>
                                )}
                                {item.unread ? (
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        disabled={busy || data.demo}
                                        aria-label={`Mark ${item.title} as seen`}
                                        onClick={() =>
                                            void markSeen([item.key])
                                        }
                                    >
                                        <Check className="size-4" />
                                    </Button>
                                ) : (
                                    <span
                                        className="p-2 text-muted-foreground"
                                        aria-label="Seen"
                                    >
                                        <CheckCheck className="size-4" />
                                    </span>
                                )}
                            </div>
                        </article>
                    );
                })}
            </div>
            {!shown.length && (
                <div className="hub-discovery-empty">
                    <Sparkles className="mx-auto size-8 text-primary" />
                    <h3>
                        {following && !items.length
                            ? 'Make this space yours'
                            : filter === 'attention'
                              ? 'Nothing needs your confirmation'
                              : 'No updates in this view'}
                    </h3>
                    <p>
                        {following && !items.length
                            ? 'Choose your interests or follow a page. Matching published updates will appear here.'
                            : 'Try another type, clear your search or explore all updates.'}
                    </p>
                    {following && (
                        <Button
                            variant="outline"
                            onClick={() => setChoosing(true)}
                            disabled={!interests}
                        >
                            Choose interests
                        </Button>
                    )}
                </div>
            )}
            {pageCount > 1 && (
                <div
                    className="flex items-center justify-end gap-3"
                    aria-label="Update pages"
                >
                    <Button
                        variant="outline"
                        disabled={currentPage === 1}
                        onClick={() => setPage(currentPage - 1)}
                    >
                        Previous updates
                    </Button>
                    <span className="text-sm">
                        {currentPage} / {pageCount}
                    </span>
                    <Button
                        variant="outline"
                        disabled={currentPage === pageCount}
                        onClick={() => setPage(currentPage + 1)}
                    >
                        Next updates
                    </Button>
                </div>
            )}
            <p className="text-xs text-muted-foreground">
                Seen indicators do not confirm required reading. Events cover
                the next 90 days; shout-outs and announcements show recent
                available items.
            </p>
            {choosing && interests && (
                <InterestsDialog
                    initial={interests}
                    onClose={() => setChoosing(false)}
                    onSaved={saveInterests}
                />
            )}
        </section>
    );
}
