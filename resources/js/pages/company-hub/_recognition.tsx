import { AppreciationIcon } from '@/components/recognition/appreciation-icon';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Link } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowUpRight,
    Heart,
    Search,
    Star,
    UsersRound,
    X,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import { hubRequest } from './_api';
import { HubBrowseSelect } from './_browse-select';
import { useHubReading } from './_reading-navigation';
import { RecognitionCard } from './_recognition-card';
import type { HubProps, HubRecognition } from './_types';
import { useHubBrowse } from './_use-hub-browse';

export function RecognitionBoard({
    data,
    onRecognise,
    actions,
}: {
    data: HubProps;
    onRecognise?: (userId?: number) => void;
    actions?: ReactNode;
}) {
    const reading = useHubReading();
    const [category, setCategory] = useState('all');
    const [query, setQuery] = useState(
        String(data.recognitionBrowse?.filters.search ?? ''),
    );
    const [team, setTeam] = useState('all');
    const [teamsOnly, setTeamsOnly] = useState(false);
    const [order, setOrder] = useState('recent');
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [readingSpotlight, setReadingSpotlight] = useState(false);
    const [commentDrafts, setCommentDrafts] = useState<Set<number>>(
        () => new Set(),
    );
    const commentDraftChanged = useCallback((id: number, dirty: boolean) => {
        setCommentDrafts((current) => {
            if (current.has(id) === dirty) return current;
            const next = new Set(current);
            if (dirty) next.add(id);
            else next.delete(id);
            return next;
        });
    }, []);
    const filtersLocked = commentDrafts.size > 0;
    const [pending, setPending] = useState<number | 'clear' | 'refresh' | null>(
        null,
    );
    const [error, setError] = useState('');
    const [removal, setRemoval] = useState<HubRecognition | null>(null);
    const [undo, setUndo] = useState<{
        source: HubProps['recognition'];
        version: number;
        kudosId: number;
    } | null>(null);
    const undoButton = useRef<HTMLButtonElement>(null);
    const readSpotlightButton = useRef<HTMLButtonElement>(null);
    const removalCompleted = useRef(false);
    const locked = useRef(false);
    const [saved, setSaved] = useState<{
        source: HubProps['recognition'];
        value: HubRecognition;
    } | null>(null);
    const context = useRef(data.recognition);
    const mounted = useRef(true);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);
    useEffect(() => {
        context.current = data.recognition;
        setPending(null);
        setError('');
        setRemoval(null);
        setUndo(null);
        locked.current = false;
    }, [data.recognition]);
    const recognition =
        saved && saved.source === data.recognition
            ? saved.value
            : data.recognition;
    const spotlight = recognition?.spotlight;
    const editable = !!recognition?.can_feature && !data.demo;
    const canUndo =
        editable &&
        undo?.source === data.recognition &&
        undo?.version === recognition?.version &&
        !spotlight &&
        !recognition?.selection_unavailable;
    const removing = editable && removal === recognition;
    const baseUrl = data.demo ? '/company-hub/showcase' : '/company-hub';
    const updateSpotlight = async (
        mode: 'save' | 'refresh' | 'remove' | 'undo',
        kudosId: number | null = null,
    ) => {
        if (!recognition || !editable || locked.current || filtersLocked)
            return;
        if (mode === 'remove' && !removing) return;
        if (mode === 'undo' && (!canUndo || kudosId !== undo?.kudosId)) return;
        locked.current = true;
        const source = data.recognition;
        setPending(mode === 'refresh' ? 'refresh' : (kudosId ?? 'clear'));
        setError('');
        try {
            const result = await hubRequest<HubRecognition>(
                '/company-hub/recognition/spotlight',
                mode === 'refresh' ? 'GET' : 'PUT',
                mode === 'refresh'
                    ? undefined
                    : {
                          actor_id: recognition.actor_id,
                          version: recognition.version,
                          kudos_id: kudosId,
                      },
            );
            if (!mounted.current || context.current !== source) return;
            if (
                result.actor_id !== recognition.actor_id ||
                !Number.isSafeInteger(result.version) ||
                (mode !== 'refresh'
                    ? result.version !== recognition.version + 1
                    : result.version < recognition.version)
            ) {
                throw new Error(
                    'The hub changed while you were saving. Reload the page before choosing a spotlight.',
                );
            }
            setSaved({ source, value: result });
            setUndo(
                mode === 'remove' &&
                    spotlight &&
                    !result.spotlight &&
                    !result.selection_unavailable
                    ? { source, version: result.version, kudosId: spotlight.id }
                    : null,
            );
            if (mode === 'remove') {
                removalCompleted.current = true;
                setRemoval(null);
            }
            if (mode === 'undo')
                requestAnimationFrame(() =>
                    readSpotlightButton.current?.focus(),
                );
        } catch (cause) {
            if (mounted.current && context.current === source)
                setError(
                    cause instanceof Error
                        ? cause.message
                        : 'The spotlight could not be saved. Please try again.',
                );
        } finally {
            if (mounted.current && context.current === source) {
                locked.current = false;
                setPending(null);
            }
        }
    };
    const selected =
        !data.recognitionUnavailable &&
        data.selectedRecognition?.id === data.recognitionId
            ? data.selectedRecognition
            : null;
    const selectedId = selected?.id;
    useEffect(() => {
        if (!selectedId) return;
        setCategory('all');
        setQuery('');
        setTeam('all');
        setTeamsOnly(false);
        const frame = requestAnimationFrame(() =>
            document.getElementById(`kudos-${selectedId}`)?.focus(),
        );
        return () => cancelAnimationFrame(frame);
    }, [selectedId, data.recognition?.actor_id]);
    const browse = useHubBrowse({
        endpoint: '/company-hub/discovery/recognition',
        initial: data.recognitionBrowse,
        fallback: data.kudos,
        enabled: !data.demo,
        filters: {
            search: query,
            category: category === 'all' ? null : category,
            team_id: team === 'all' ? null : Number(team),
            recipient_type: teamsOnly ? 'team' : null,
            sort:
                order === 'celebrated'
                    ? 'most_appreciated'
                    : order === 'oldest'
                      ? 'oldest'
                      : 'newest',
        },
    });
    const baseKudos = selected
        ? [selected, ...browse.rows.filter((item) => item.id !== selected.id)]
        : browse.rows;
    const sourceKudos =
        readingSpotlight &&
        spotlight &&
        !baseKudos.some((item) => item.id === spotlight.id)
            ? [spotlight, ...baseKudos]
            : baseKudos;
    const kudos = browse.server
        ? sourceKudos
        : sourceKudos
              .filter(
                  (item) =>
                      (category === 'all' || item.category === category) &&
                      (!teamsOnly || item.recipient_type === 'team') &&
                      (team === 'all' ||
                          item.team_ids?.includes(Number(team))) &&
                      `${item.to} ${item.from} ${item.message} ${item.team_name ?? ''}`
                          .toLowerCase()
                          .includes(query.toLowerCase()),
              )
              .sort((a, b) =>
                  selected && (a.id === selected.id || b.id === selected.id)
                      ? a.id === selected.id
                          ? -1
                          : 1
                      : order === 'celebrated'
                        ? Object.values(b.reactions).reduce(
                              (sum, value) => sum + value.count,
                              0,
                          ) -
                              Object.values(a.reactions).reduce(
                                  (sum, value) => sum + value.count,
                                  0,
                              ) || b.created_at.localeCompare(a.created_at)
                        : order === 'oldest'
                          ? a.created_at.localeCompare(b.created_at)
                          : b.created_at.localeCompare(a.created_at),
              );
    const filtered =
        category !== 'all' || query !== '' || team !== 'all' || teamsOnly;
    const clearFilters = () => {
        if (filtersLocked) return;
        setCategory('all');
        setQuery('');
        setTeam('all');
        setTeamsOnly(false);
    };

    return (
        <section
            className="hub-recognition-board"
            aria-label="Company recognition"
        >
            <header className="hub-recognition-intro">
                <div>
                    <h2>Shout-outs</h2>
                    <p>Celebrate good work. Keep the conversation going.</p>
                </div>
                <div className="hub-recognition-intro-actions">{actions}</div>
            </header>
            <div className="hub-recognition-layout">
                <div className="hub-recognition-feed">
                    {onRecognise && (
                        <div className="hub-recognition-composer">
                            <span className="hub-recognition-composer-icon">
                                <AppreciationIcon className="size-6" />
                            </span>
                            <Button
                                variant="ghost"
                                onClick={() => onRecognise()}
                                className="hub-recognition-composer-prompt h-auto flex-col items-start gap-0 whitespace-normal"
                            >
                                <strong>Who made your day?</strong>
                                <span>
                                    Give a colleague or team a shout-out…
                                </span>
                            </Button>
                            <Button
                                onClick={() => onRecognise()}
                                aria-label="Write a shout-out"
                            >
                                Write a post
                            </Button>
                        </div>
                    )}
                    {(data.recognitionId || data.recognitionUnavailable) && (
                        <Card className="flex flex-row flex-wrap items-center justify-between gap-3 p-4">
                            <p role="status" className="text-sm">
                                {selected
                                    ? `Reading a shout-out for ${selected.to}`
                                    : 'This shout-out is no longer available to you.'}
                            </p>
                            <Button
                                variant="ghost"
                                onClick={() =>
                                    reading?.returnLabel
                                        ? reading.back()
                                        : reading
                                          ? reading.primary(
                                                `${baseUrl}?view=shoutouts`,
                                            )
                                          : window.location.assign(
                                                `${baseUrl}?view=shoutouts`,
                                            )
                                }
                            >
                                <ArrowLeft className="size-4" />
                                {reading?.returnLabel ?? 'All shout-outs'}
                            </Button>
                        </Card>
                    )}
                    <div className="hub-recognition-tools">
                        <div className="hub-recognition-feed-heading">
                            <div>
                                <span role="status">
                                    {kudos.length}{' '}
                                    {kudos.length === 1
                                        ? 'shout-out'
                                        : 'shout-outs'}
                                    {filtered
                                        ? ' in this view'
                                        : ' to celebrate'}
                                </span>
                            </div>
                            <Button
                                variant={filtered ? 'secondary' : 'ghost'}
                                aria-expanded={filtersOpen}
                                aria-controls="recognition-feed-filters"
                                onClick={() => setFiltersOpen(!filtersOpen)}
                            >
                                <UsersRound className="size-4" />
                                Filters{filtered ? ' · Active' : ''}
                            </Button>
                        </div>
                        <div className="hub-recognition-search">
                            <Search className="size-4" aria-hidden="true" />
                            <Input
                                aria-label="Search shout-outs"
                                placeholder="Find a person, team or moment…"
                                value={query}
                                disabled={filtersLocked}
                                aria-describedby={
                                    filtersLocked
                                        ? 'recognition-filter-draft-note'
                                        : undefined
                                }
                                onChange={(e) => {
                                    if (!filtersLocked)
                                        setQuery(e.target.value);
                                }}
                            />
                        </div>
                        {filtersLocked && (
                            <p
                                id="recognition-filter-draft-note"
                                role="status"
                                className="text-sm text-muted-foreground"
                            >
                                Finish or cancel your comment to change filters.
                            </p>
                        )}
                        {filtersOpen && (
                            <div
                                className="hub-recognition-filters"
                                id="recognition-feed-filters"
                            >
                                <Button
                                    variant={teamsOnly ? 'secondary' : 'ghost'}
                                    aria-pressed={teamsOnly}
                                    disabled={filtersLocked}
                                    onClick={() => {
                                        if (!filtersLocked)
                                            setTeamsOnly(!teamsOnly);
                                    }}
                                >
                                    <UsersRound className="size-4" />
                                    Team recognition
                                </Button>
                                <HubBrowseSelect
                                    label="Company value"
                                    disabled={filtersLocked}
                                    value={category}
                                    onChange={(value) => {
                                        if (!filtersLocked) setCategory(value);
                                    }}
                                    options={[
                                        { value: 'all', label: 'All values' },
                                        ...(browse.server
                                            ? Object.entries(
                                                  data.kudosCategories,
                                              ).map(([value, label]) => ({
                                                  value,
                                                  label,
                                              }))
                                            : [
                                                  ...new Set(
                                                      data.kudos
                                                          .map(
                                                              (item) =>
                                                                  item.category,
                                                          )
                                                          .filter(
                                                              (
                                                                  value,
                                                              ): value is string =>
                                                                  !!value,
                                                          ),
                                                  ),
                                              ].map((value) => ({
                                                  value,
                                                  label: value,
                                              }))),
                                    ]}
                                />
                                <HubBrowseSelect
                                    label="Recognition team"
                                    disabled={filtersLocked}
                                    value={team}
                                    onChange={(value) => {
                                        if (!filtersLocked) setTeam(value);
                                    }}
                                    options={[
                                        { value: 'all', label: 'All teams' },
                                        ...(
                                            data.interests?.available_teams ??
                                            []
                                        ).map((item) => ({
                                            value: String(item.id),
                                            label: item.name,
                                        })),
                                    ]}
                                />
                                <HubBrowseSelect
                                    label="Recognition order"
                                    disabled={filtersLocked}
                                    value={order}
                                    onChange={(value) => {
                                        if (!filtersLocked) setOrder(value);
                                    }}
                                    options={[
                                        {
                                            value: 'recent',
                                            label: 'Most recent',
                                        },
                                        {
                                            value: 'celebrated',
                                            label: 'Most celebrated',
                                        },
                                        {
                                            value: 'oldest',
                                            label: 'Oldest first',
                                        },
                                    ]}
                                />
                                {filtered && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={clearFilters}
                                        disabled={filtersLocked}
                                    >
                                        <X className="size-3.5" />
                                        Clear filters
                                    </Button>
                                )}
                            </div>
                        )}
                    </div>
                    {error && !removing && (
                        <div className="hub-recognition-error">
                            <p role="alert">{error}</p>
                            {editable && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={pending !== null || filtersLocked}
                                    onClick={() => updateSpotlight('refresh')}
                                >
                                    Refresh spotlight
                                </Button>
                            )}
                        </div>
                    )}
                    {pending !== null && (
                        <p
                            role="status"
                            className="text-xs text-muted-foreground"
                        >
                            {pending === 'refresh'
                                ? 'Refreshing spotlight…'
                                : 'Saving shared spotlight…'}
                        </p>
                    )}
                    <section
                        className="hub-recognition-grid"
                        aria-label="Shout-outs"
                        aria-busy={browse.loading}
                    >
                        {kudos.map((item) => (
                            <RecognitionCard
                                key={item.id}
                                kudos={item}
                                data={data}
                                onDraftChange={commentDraftChanged}
                                action={
                                    editable ? (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            aria-label={`Feature ${item.to}`}
                                            aria-pressed={
                                                spotlight?.id === item.id
                                            }
                                            disabled={
                                                pending !== null ||
                                                filtersLocked ||
                                                spotlight?.id === item.id
                                            }
                                            onClick={() =>
                                                updateSpotlight('save', item.id)
                                            }
                                        >
                                            <Star
                                                className="size-4"
                                                fill={
                                                    spotlight?.id === item.id
                                                        ? 'currentColor'
                                                        : 'none'
                                                }
                                            />
                                            {pending === item.id
                                                ? 'Saving…'
                                                : spotlight?.id === item.id
                                                  ? 'Featured'
                                                  : 'Feature'}
                                        </Button>
                                    ) : undefined
                                }
                            />
                        ))}
                    </section>
                    {browse.loading && (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Loading shout-outs…
                        </p>
                    )}
                    {browse.error && (
                        <div className="hub-recognition-error">
                            <p role="alert">{browse.error}</p>
                            <Button
                                variant="outline"
                                className="min-h-11"
                                onClick={browse.retry}
                            >
                                Try loading shout-outs again
                            </Button>
                        </div>
                    )}
                    {browse.hasMore && !browse.error && (
                        <Button
                            variant="outline"
                            className="min-h-11"
                            disabled={browse.loading}
                            onClick={browse.loadMore}
                        >
                            Show more shout-outs
                        </Button>
                    )}
                    {!kudos.length && !browse.loading && !browse.error && (
                        <div className="hub-recognition-empty">
                            <span className="hub-recognition-empty-icon">
                                <AppreciationIcon className="size-7" />
                            </span>
                            <h3>
                                {filtered
                                    ? 'No shout-outs in this view'
                                    : 'Start with a little thank-you.'}
                            </h3>
                            <p>
                                {filtered
                                    ? 'Try another name, value or team to find the moment you’re looking for.'
                                    : 'A thoughtful action. A helping hand. A moment that made the day better. Share the first shout-out.'}
                            </p>
                            {filtered ? (
                                <Button
                                    variant="outline"
                                    onClick={clearFilters}
                                    disabled={filtersLocked}
                                >
                                    Clear filters
                                </Button>
                            ) : (
                                onRecognise && (
                                    <Button onClick={() => onRecognise()}>
                                        Recognise a colleague
                                    </Button>
                                )
                            )}
                        </div>
                    )}
                    {kudos.length > 0 && (
                        <p className="hub-recognition-feed-note">
                            <Heart className="size-3.5" />
                            Small moments. Lasting appreciation.
                        </p>
                    )}
                </div>
                <aside
                    className="hub-recognition-sidebar"
                    aria-label="Recognition and connections"
                >
                    {spotlight ? (
                        <section
                            className="hub-recognition-spotlight"
                            aria-label="Recognition spotlight"
                        >
                            <div className="hub-recognition-photo">
                                <img
                                    src="/images/company-hub/colleagues-together.png"
                                    alt=""
                                />
                                <span>
                                    <Star className="size-4" />
                                    {spotlight.recipient_type === 'team'
                                        ? 'Team spotlight'
                                        : 'Colleague spotlight'}
                                </span>
                            </div>
                            <div className="hub-recognition-quote">
                                {spotlight.category &&
                                    spotlight.category.toLowerCase() !==
                                        'other' && (
                                        <span className="hub-recognition-value">
                                            {spotlight.category}
                                        </span>
                                    )}
                                <h3>{spotlight.to}</h3>
                                <blockquote>{spotlight.message}</blockquote>
                                <p className="text-xs text-muted-foreground">
                                    Recognised by {spotlight.from}
                                </p>
                                <Button
                                    ref={readSpotlightButton}
                                    variant="outline"
                                    disabled={filtersLocked}
                                    className="mt-5 w-full"
                                    onClick={() => {
                                        clearFilters();
                                        setReadingSpotlight(true);
                                        requestAnimationFrame(() =>
                                            document
                                                .getElementById(
                                                    `kudos-${spotlight.id}`,
                                                )
                                                ?.focus(),
                                        );
                                    }}
                                >
                                    Read shout-out
                                    <ArrowUpRight className="size-4" />
                                </Button>
                                <Link
                                    href={
                                        spotlight.recipient_url ||
                                        `${baseUrl}?view=${spotlight.recipient_type === 'team' ? 'teams' : 'people'}`
                                    }
                                    className="hub-recognition-text-link"
                                >
                                    {spotlight.recipient_type === 'team'
                                        ? 'Explore our teams'
                                        : 'Meet our people'}
                                    <ArrowUpRight className="size-4" />
                                </Link>
                                {editable && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="mt-3"
                                        disabled={
                                            pending !== null || filtersLocked
                                        }
                                        onClick={() => {
                                            removalCompleted.current = false;
                                            setRemoval(recognition ?? null);
                                        }}
                                    >
                                        {pending === 'clear'
                                            ? 'Removing…'
                                            : 'Remove spotlight'}
                                    </Button>
                                )}
                            </div>
                        </section>
                    ) : (
                        <section className="hub-recognition-invitation">
                            <div className="hub-recognition-invitation-image">
                                <img
                                    src="/images/company-hub/colleagues-together.png"
                                    alt=""
                                />
                                <span>
                                    <AppreciationIcon className="size-5" />
                                    Better, together.
                                </span>
                            </div>
                            <div className="hub-recognition-invitation-copy">
                                <h3>Make someone’s day.</h3>
                                <p>
                                    A helping hand, a thoughtful moment, a job
                                    done with care. Let them know you noticed.
                                </p>
                                {
                                    <Link
                                        className="hub-recognition-text-link"
                                        href={`${baseUrl}?view=people`}
                                    >
                                        Meet our people
                                        <ArrowUpRight className="size-4" />
                                    </Link>
                                }
                            </div>
                        </section>
                    )}
                    {editable && !spotlight && (
                        <section className="hub-recognition-editor-note">
                            <div>
                                <Star className="size-4" />
                                <h3>Shared spotlight</h3>
                                <span>Editors</span>
                            </div>
                            <p>
                                {recognition?.selection_unavailable
                                    ? 'The selected shout-out is no longer available. Choose another below or clear the selection.'
                                    : 'Use Feature on a shout-out to give that moment a place in the spotlight.'}
                            </p>
                            {recognition?.selection_unavailable && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={pending !== null || filtersLocked}
                                    onClick={() => {
                                        removalCompleted.current = false;
                                        setRemoval(recognition ?? null);
                                    }}
                                >
                                    Clear selection
                                </Button>
                            )}
                        </section>
                    )}
                    {canUndo && undo && (
                        <div
                            role="status"
                            className="rounded-xl border border-primary/20 bg-primary/5 p-4 text-sm"
                        >
                            <p>
                                Spotlight removed. The shout-out stays in the
                                feed.
                            </p>
                            <Button
                                ref={undoButton}
                                className="mt-2"
                                variant="outline"
                                size="sm"
                                disabled={pending !== null || filtersLocked}
                                onClick={() =>
                                    updateSpotlight('undo', undo.kudosId)
                                }
                            >
                                Undo removal
                            </Button>
                        </div>
                    )}
                    <nav
                        className="hub-recognition-connections"
                        aria-label="Connect with colleagues"
                    >
                        <Link href={`${baseUrl}?view=people`}>
                            <span>
                                <UsersRound className="size-4" />
                                Meet our people
                            </span>
                            <ArrowUpRight className="size-4" />
                        </Link>
                        <Link href={`${baseUrl}?view=teams`}>
                            <span>
                                <Heart className="size-4" />
                                Explore our teams
                            </span>
                            <ArrowUpRight className="size-4" />
                        </Link>
                    </nav>
                </aside>
            </div>
            <Dialog
                open={removing}
                onOpenChange={(open) => {
                    if (!open && !locked.current) setRemoval(null);
                }}
            >
                <DialogContent
                    onCloseAutoFocus={(event) => {
                        if (removalCompleted.current && undoButton.current) {
                            event.preventDefault();
                            undoButton.current.focus();
                        }
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Remove this spotlight?</DialogTitle>
                        <DialogDescription>
                            The shout-out stays in the recognition feed. Only
                            its featured placement is removed.
                        </DialogDescription>
                    </DialogHeader>
                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={pending !== null || filtersLocked}
                            onClick={() => setRemoval(null)}
                        >
                            Keep spotlight
                        </Button>
                        <Button
                            disabled={pending !== null || filtersLocked}
                            onClick={() => updateSpotlight('remove')}
                        >
                            {pending === 'clear'
                                ? 'Removing…'
                                : 'Remove spotlight'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
