import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { formatDate, formatRelative } from '@/lib/datetime';
import { router, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    Heart,
    Lightbulb,
    Plus,
    Search,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hubRequest } from './_api';
import { HubBrowseSelect } from './_browse-select';
import { IdeaComposer } from './_idea-composer';
import {
    IdeaProgress,
    IdeaProgressEditor,
    ideaStatuses,
    type IdeaProgressContext,
} from './_idea-progress';
import type { HubBrowse, HubIdea, HubProps } from './_types';
import { useHubBrowse } from './_use-hub-browse';

export function IdeaBoard({
    ideas,
    moderate,
    actorId = null,
    progressReady = false,
    teams = [],
    owners = [],
    initialIdeaId = null,
    initialUnavailable = false,
    readOnly = false,
    browse: initialBrowse,
}: {
    ideas: HubIdea[];
    moderate: boolean;
    initialIdeaId?: number | null;
    initialUnavailable?: boolean;
    readOnly?: boolean;
    browse?: HubBrowse<HubIdea> | null;
} & Partial<IdeaProgressContext>) {
    const [create, setCreate] = useState(false);
    const [selected, setSelected] = useState<number | null>(initialIdeaId);
    const [unavailable, setUnavailable] = useState(initialUnavailable);
    const [status, setStatus] = useState('all');
    const [query, setQuery] = useState(
        String(initialBrowse?.filters.search ?? ''),
    );
    const [sort, setSort] = useState(
        String(initialBrowse?.filters.sort ?? 'newest'),
    );
    const browse = useHubBrowse({
        endpoint: '/company-hub/discovery/ideas',
        initial: initialBrowse,
        fallback: ideas,
        enabled: !readOnly,
        filters: {
            search: query,
            status: status === 'all' ? null : status,
            sort,
        },
    });
    const idea =
        browse.rows.find((i) => i.id === selected) ??
        ideas.find((i) => i.id === selected);
    const openIdea = (id: number) => {
        setSelected(id);
        if (browse.server)
            router.visit(`/company-hub?view=ideas&idea=${id}`, {
                preserveScroll: true,
                preserveState: true,
            });
    };
    const list = browse.server
        ? browse.rows
        : ideas
              .filter(
                  (i) =>
                      (status === 'all' || i.status === status) &&
                      `${i.title} ${i.body} ${i.category} ${i.team?.name ?? ''} ${i.owner?.name ?? ''} ${(i.updates ?? []).map((update) => update.note).join(' ')}`
                          .toLowerCase()
                          .includes(query.toLowerCase()),
              )
              .sort((a, b) =>
                  sort === 'most_supported'
                      ? b.support_count - a.support_count
                      : sort === 'oldest'
                        ? a.id - b.id
                        : b.id - a.id,
              );
    return (
        <section className="space-y-5">
            <div className="hub-community-intro">
                <Lightbulb className="size-8" />
                <div>
                    <h2>Small ideas. Meaningful change.</h2>
                    <p>
                        Share a suggestion, build on someone’s thinking and see
                        what happens next.
                    </p>
                </div>
                <Button
                    disabled={!actorId && !readOnly}
                    onClick={() => setCreate(true)}
                >
                    <Plus className="size-4" />
                    Share an idea
                </Button>
            </div>
            <div className="hub-library-tools">
                <label className="hub-library-search">
                    <Search className="size-4" />
                    <Input
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        aria-label="Search ideas"
                        placeholder="Find an idea…"
                    />
                </label>
                <HubBrowseSelect
                    label="Idea status"
                    value={status}
                    onChange={setStatus}
                    options={[
                        { value: 'all', label: 'All stages' },
                        ...Object.entries(ideaStatuses).map(
                            ([value, label]) => ({ value, label }),
                        ),
                    ]}
                />
                <HubBrowseSelect
                    label="Sort ideas"
                    value={sort}
                    onChange={setSort}
                    options={[
                        { value: 'updated', label: 'Recently updated' },
                        { value: 'newest', label: 'Newest first' },
                        { value: 'oldest', label: 'Oldest first' },
                        { value: 'most_supported', label: 'Most supported' },
                    ]}
                />
            </div>
            <div className="hub-library-results" aria-busy={browse.loading}>
                {list.map((i) => (
                    <article className="hub-resource-card" key={i.id}>
                        <span className="text-xs text-primary">
                            {i.category}
                        </span>
                        <h3>
                            <Button
                                variant="link"
                                className="h-auto justify-start p-0 text-left text-base font-semibold whitespace-normal text-foreground hover:text-primary"
                                onClick={() => openIdea(i.id)}
                            >
                                {i.title}
                            </Button>
                        </h3>
                        <p className="line-clamp-3 text-sm text-muted-foreground">
                            {i.body}
                        </p>
                        {(i.team || i.owner) && (
                            <p className="text-xs text-muted-foreground">
                                {[
                                    i.team?.name,
                                    i.owner ? `Owner: ${i.owner.name}` : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        )}
                        <div className="flex items-center justify-between gap-2 text-xs">
                            <span>{ideaStatuses[i.status]}</span>
                            <span>{i.support_count} supporting</span>
                        </div>
                        <div className="mt-auto flex justify-between border-t pt-3 text-xs text-muted-foreground">
                            <span>{i.author}</span>
                            <Button
                                variant="link"
                                onClick={() => openIdea(i.id)}
                                className="h-auto p-0 text-xs text-primary"
                            >
                                Join the discussion{' '}
                                <ArrowRight className="inline size-3" />
                            </Button>
                        </div>
                    </article>
                ))}
            </div>
            {browse.loading && (
                <p role="status" className="text-sm text-muted-foreground">
                    Loading ideas…
                </p>
            )}
            {browse.error && (
                <div className="hub-empty-note">
                    <p role="alert">{browse.error}</p>
                    <Button
                        variant="outline"
                        className="min-h-11"
                        onClick={browse.retry}
                    >
                        Try loading ideas again
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
                    Show more ideas
                </Button>
            )}
            {(unavailable || (selected !== null && !idea)) && (
                <div
                    role="status"
                    className="hub-empty-note flex items-center justify-between gap-4"
                >
                    <p>
                        This idea is no longer available. You can still explore
                        the other ideas on this board.
                    </p>
                    <Button
                        variant="outline"
                        onClick={() => {
                            setUnavailable(false);
                            setSelected(null);
                        }}
                    >
                        Dismiss
                    </Button>
                </div>
            )}
            {list.length === 0 && !browse.loading && !browse.error && (
                <div className="hub-empty-note">
                    {ideas.length
                        ? 'No ideas match these filters.'
                        : 'Be the first to suggest something that could make work better.'}
                </div>
            )}
            {create && (
                <IdeaComposer
                    readOnly={readOnly}
                    key={actorId}
                    actorId={actorId}
                    ideas={ideas}
                    teams={teams}
                    progressReady={progressReady}
                    close={() => setCreate(false)}
                    onView={(id) => {
                        setCreate(false);
                        openIdea(id);
                    }}
                />
            )}
            {idea && (
                <IdeaDetails
                    key={`${idea.id}:${actorId}`}
                    idea={idea}
                    moderate={moderate && !readOnly}
                    actorId={readOnly ? null : actorId}
                    progressReady={progressReady}
                    teams={teams}
                    owners={owners}
                    close={() => setSelected(null)}
                />
            )}
        </section>
    );
}

function IdeaDetails({
    idea,
    moderate,
    close,
    actorId,
    progressReady,
    teams,
    owners,
}: {
    idea: HubIdea;
    moderate: boolean;
    close: () => void;
} & IdeaProgressContext) {
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [confirmRemove, setConfirmRemove] = useState<number | null>(null);
    useEffect(() => {
        if (confirmRemove !== null && confirmRemove !== idea.version)
            setConfirmRemove(null);
    }, [confirmRemove, idea.version]);
    const [progressState, setProgressState] = useState({
        dirty: false,
        pending: false,
    });
    const [commentPending, setCommentPending] = useState(false);
    const pending = useRef(false);
    const alive = useRef(false);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    const form = useForm({ action: 'comment', body: '', actor_id: actorId });
    const locked =
        busy || commentPending || form.processing || progressState.pending;
    const navigation = useSettingsLeaveConfirmation(
        !!form.data.body || progressState.dirty || locked,
        'Leave this idea?',
        'Your unsent comment and progress changes will be discarded. A save already sent may still finish; check the discussion and history before trying again.',
        close,
        locked,
    );
    const leave = () => {
        if (!pending.current && !locked) {
            if (form.data.body || progressState.dirty)
                navigation.request(() => undefined);
            else close();
        }
    };
    const act = (data: Record<string, string | number | boolean>) => {
        if (pending.current || locked || !actorId) return;
        pending.current = true;
        setBusy(true);
        setError('');
        router.post(
            `/company-hub/ideas/${idea.id}`,
            { ...data, actor_id: actorId },
            {
                preserveScroll: true,
                onError: (errors) => {
                    if (alive.current)
                        setError(Object.values(errors).join(' '));
                },
                onFinish: () => {
                    if (alive.current) {
                        pending.current = false;
                        setBusy(false);
                    }
                },
                onSuccess: () => {
                    if (alive.current && data.action === 'remove')
                        navigation.preserveAndRun(close);
                },
            },
        );
    };
    return (
        <>
            <Dialog
                open
                onOpenChange={(open) => {
                    if (!open) leave();
                }}
            >
                <DialogContent
                    className="max-h-[85vh] overflow-auto"
                    style={{ maxWidth: 720 }}
                >
                    <DialogHeader>
                        <DialogTitle>{idea.title}</DialogTitle>
                        <DialogDescription>
                            {idea.author} · {formatRelative(idea.created_at)} ·{' '}
                            {idea.category}
                        </DialogDescription>
                    </DialogHeader>
                    <p className="my-4 text-sm leading-relaxed whitespace-pre-wrap">
                        {idea.body}
                    </p>
                    <div className="flex items-center gap-3">
                        <Button
                            variant={idea.supported ? 'secondary' : 'outline'}
                            disabled={locked || !actorId}
                            aria-pressed={idea.supported}
                            onClick={() =>
                                act({
                                    action: 'support',
                                    active: !idea.supported,
                                })
                            }
                        >
                            <Heart className="size-4" />
                            {idea.supported
                                ? 'Supporting'
                                : 'Support this idea'}{' '}
                            · {idea.support_count}
                        </Button>
                        <span className="text-xs text-primary">
                            {ideaStatuses[idea.status]}
                        </span>
                    </div>
                    <IdeaProgress idea={idea} />
                    {moderate && (
                        <IdeaProgressEditor
                            idea={idea}
                            actorId={actorId}
                            progressReady={progressReady}
                            teams={teams}
                            owners={owners}
                            disabled={busy || commentPending || form.processing}
                            onDraftStateChange={setProgressState}
                            runRefresh={navigation.preserveAndRun}
                        />
                    )}
                    <div className="my-5 space-y-4 border-t pt-5">
                        <h3 className="font-semibold">Discussion</h3>
                        {idea.comments.map((c) => (
                            <article
                                key={c.id}
                                className="rounded-lg bg-muted/50 p-3 text-sm"
                            >
                                <div className="flex items-center justify-between">
                                    <strong>{c.author}</strong>
                                    {c.can_remove && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            disabled={locked}
                                            onClick={() =>
                                                act({
                                                    action: 'remove_comment',
                                                    comment_id: c.id,
                                                })
                                            }
                                        >
                                            Remove
                                        </Button>
                                    )}
                                </div>
                                <p className="whitespace-pre-wrap">{c.body}</p>
                            </article>
                        ))}
                        <form
                            className="space-y-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (
                                    !actorId ||
                                    pending.current ||
                                    locked ||
                                    !form.data.body.trim()
                                )
                                    return;
                                pending.current = true;
                                setCommentPending(true);
                                form.post(`/company-hub/ideas/${idea.id}`, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        if (alive.current) form.reset('body');
                                    },
                                    onFinish: () => {
                                        if (alive.current) {
                                            pending.current = false;
                                            setCommentPending(false);
                                        }
                                    },
                                });
                            }}
                        >
                            <Label className="grid gap-2">
                                Add to the idea
                                <Textarea
                                    disabled={locked || !actorId}
                                    required
                                    maxLength={2000}
                                    value={form.data.body}
                                    onChange={(e) =>
                                        form.setData('body', e.target.value)
                                    }
                                />
                            </Label>
                            {form.errors.body && (
                                <p
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    {form.errors.body}
                                </p>
                            )}
                            <Button
                                type="submit"
                                disabled={
                                    locked || !actorId || !form.data.body.trim()
                                }
                            >
                                Post comment
                            </Button>
                        </form>
                    </div>
                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}
                    {idea.can_remove && (
                        <div className="border-t pt-3">
                            {confirmRemove !== null ? (
                                <div className="flex items-center gap-2 text-sm">
                                    <span>
                                        Remove this idea from the board?
                                    </span>
                                    <Button
                                        disabled={locked}
                                        variant="destructive"
                                        onClick={() =>
                                            act({
                                                action: 'remove',
                                                version: confirmRemove,
                                            })
                                        }
                                    >
                                        Remove idea
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        disabled={locked}
                                        onClick={() => setConfirmRemove(null)}
                                    >
                                        Keep it
                                    </Button>
                                </div>
                            ) : (
                                <Button
                                    variant="ghost"
                                    disabled={locked}
                                    onClick={() =>
                                        setConfirmRemove(idea.version)
                                    }
                                >
                                    Remove idea…
                                </Button>
                            )}
                        </div>
                    )}
                    <div className="mt-5 flex justify-end border-t pt-4">
                        <Button
                            variant="outline"
                            disabled={locked}
                            onClick={leave}
                        >
                            Done
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
            {navigation.confirmation}
        </>
    );
}

export { CompanySpaces } from './_spaces';

export function CampaignProgress({ data }: { data: HubProps }) {
    const steps = data.participation?.campaign ?? [];
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    if (!steps.length || !data.selectedPage) return null;
    const count = steps.filter((s) => s.done).length;
    const ends = data.selectedPage.content.campaign?.ends_on;
    return (
        <section className="hub-campaign-progress">
            <div className="flex items-center justify-between">
                <h2 className="text-lg font-semibold">Your activities</h2>
                <span className="text-sm">
                    {count} of {steps.length} completed
                </span>
            </div>
            {ends && (
                <p className="text-sm text-muted-foreground">
                    Runs until {formatDate(ends)}
                </p>
            )}
            <progress
                aria-label="Your campaign progress"
                max={steps.length}
                value={count}
                className="w-full accent-primary"
            />
            {steps.map((step) => (
                <div
                    key={step.id}
                    className="flex items-center gap-3 border-t py-3"
                >
                    <Button
                        variant={step.done ? 'secondary' : 'outline'}
                        aria-pressed={step.done}
                        disabled={busy || data.demo}
                        onClick={() => {
                            if (data.demo) return;
                            setBusy(true);
                            setError('');
                            router.post(
                                `/company-hub/pages/${data.selectedPage!.id}/interactions`,
                                {
                                    kind: 'campaign',
                                    step_id: step.id,
                                    step_key: step.key,
                                    active: !step.done,
                                },
                                {
                                    preserveScroll: true,
                                    onError: (err) =>
                                        setError(Object.values(err).join(' ')),
                                    onFinish: () => setBusy(false),
                                },
                            );
                        }}
                    >
                        {step.done && <Check className="size-4" />}
                        {step.done ? 'Completed' : 'Mark complete'}
                    </Button>
                    <div className="flex-1">
                        <p className="text-sm font-medium">{step.title}</p>
                        <small className="text-muted-foreground">
                            {step.count}{' '}
                            {step.count === 1
                                ? 'colleague has'
                                : 'colleagues have'}{' '}
                            completed this
                        </small>
                    </div>
                    {step.url && (
                        <a
                            className="text-sm text-primary"
                            href={step.url}
                            rel="noopener noreferrer"
                        >
                            Open activity{' '}
                            <ArrowRight className="inline size-4" />
                        </a>
                    )}
                </div>
            ))}
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </section>
    );
}

export function ReadReceipt({
    id,
    revisionId,
}: {
    id: number;
    revisionId?: number;
}) {
    useEffect(() => {
        void hubRequest(`/company-hub/pages/${id}/read`, 'POST', {
            revision_id: revisionId,
        }).catch(() => {
            /* Reading remains available if aggregate analytics cannot be recorded. */
        });
    }, [id, revisionId]);
    return null;
}
