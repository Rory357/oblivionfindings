import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatRelative } from '@/lib/datetime';
import { Link, router, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    Bell,
    Check,
    Heart,
    Lightbulb,
    Plus,
    Search,
    UsersRound,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { hubRequest } from './_api';
import type { HubIdea, HubProps } from './_types';

const ideaCategories = [
    'Better ways of working',
    'Our people',
    'Community',
    'Sustainability',
    'Something else',
];
const ideaStatuses: Record<string, string> = {
    open: 'Open for ideas',
    reviewing: 'Under review',
    planned: 'Planned',
    delivered: 'Delivered',
    closed: 'Closed',
};

export function IdeaBoard({
    ideas,
    moderate,
}: {
    ideas: HubIdea[];
    moderate: boolean;
}) {
    const [create, setCreate] = useState(false);
    const [selected, setSelected] = useState<number | null>(null);
    const [status, setStatus] = useState('all');
    const [query, setQuery] = useState('');
    const [sort, setSort] = useState('recent');
    const idea = ideas.find((i) => i.id === selected);
    const list = ideas
        .filter(
            (i) =>
                (status === 'all' || i.status === status) &&
                `${i.title} ${i.body} ${i.category}`
                    .toLowerCase()
                    .includes(query.toLowerCase()),
        )
        .sort((a, b) =>
            sort === 'support'
                ? b.support_count - a.support_count
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
                <Button onClick={() => setCreate(true)}>
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
                <select
                    className="hub-native-select"
                    value={status}
                    onChange={(e) => setStatus(e.target.value)}
                    aria-label="Idea status"
                >
                    <option value="all">All stages</option>
                    {Object.entries(ideaStatuses).map(([key, value]) => (
                        <option key={key} value={key}>
                            {value}
                        </option>
                    ))}
                </select>
                <select
                    className="hub-native-select"
                    value={sort}
                    onChange={(e) => setSort(e.target.value)}
                    aria-label="Sort ideas"
                >
                    <option value="recent">Newest first</option>
                    <option value="support">Most supported</option>
                </select>
            </div>
            <div className="hub-library-results">
                {list.map((i) => (
                    <article className="hub-resource-card" key={i.id}>
                        <span className="text-xs text-primary">
                            {i.category}
                        </span>
                        <h3>
                            <Button
                                variant="link"
                                className="h-auto justify-start p-0 text-left text-base font-semibold whitespace-normal text-foreground hover:text-primary"
                                onClick={() => setSelected(i.id)}
                            >
                                {i.title}
                            </Button>
                        </h3>
                        <p className="line-clamp-3 text-sm text-muted-foreground">
                            {i.body}
                        </p>
                        <div className="flex items-center justify-between gap-2 text-xs">
                            <span>{ideaStatuses[i.status]}</span>
                            <span>{i.support_count} supporting</span>
                        </div>
                        <div className="mt-auto flex justify-between border-t pt-3 text-xs text-muted-foreground">
                            <span>{i.author}</span>
                            <Button
                                variant="link"
                                onClick={() => setSelected(i.id)}
                                className="h-auto p-0 text-xs text-primary"
                            >
                                Join the discussion{' '}
                                <ArrowRight className="inline size-3" />
                            </Button>
                        </div>
                    </article>
                ))}
            </div>
            {list.length === 0 && (
                <div className="hub-empty-note">
                    {ideas.length
                        ? 'No ideas match these filters.'
                        : 'Be the first to suggest something that could make work better.'}
                </div>
            )}
            <Dialog open={create} onOpenChange={setCreate}>
                <DialogContent>
                    {create && <NewIdea close={() => setCreate(false)} />}
                </DialogContent>
            </Dialog>
            <Dialog
                open={!!idea}
                onOpenChange={(open) => !open && setSelected(null)}
            >
                <DialogContent
                    className="max-h-[85vh] overflow-auto"
                    style={{ maxWidth: 720 }}
                >
                    {idea && (
                        <IdeaDetails
                            key={`${idea.id}:${idea.version}`}
                            idea={idea}
                            moderate={moderate}
                            close={() => setSelected(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </section>
    );
}

function NewIdea({ close }: { close: () => void }) {
    const form = useForm({ title: '', body: '', category: ideaCategories[0] });
    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/company-hub/ideas', {
                    preserveScroll: true,
                    onSuccess: close,
                });
            }}
        >
            <DialogHeader>
                <DialogTitle>Share an idea</DialogTitle>
                <DialogDescription>
                    Ideas are visible to staff across the company. Keep personal
                    or confidential matters in the appropriate HR channel.
                </DialogDescription>
            </DialogHeader>
            <div className="space-y-4 py-5">
                <Label className="grid gap-2">
                    Your idea
                    <Input
                        required
                        maxLength={160}
                        autoFocus
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                    />
                </Label>
                <Label className="grid gap-2">
                    What could we improve?
                    <Textarea
                        required
                        rows={5}
                        maxLength={4000}
                        value={form.data.body}
                        onChange={(e) => form.setData('body', e.target.value)}
                    />
                </Label>
                <Label className="grid gap-2">
                    Category
                    <select
                        className="hub-native-select"
                        value={form.data.category}
                        onChange={(e) =>
                            form.setData('category', e.target.value)
                        }
                    >
                        {ideaCategories.map((c) => (
                            <option key={c}>{c}</option>
                        ))}
                    </select>
                </Label>
                {Object.keys(form.errors).length > 0 && (
                    <p role="alert" className="text-sm text-destructive">
                        {Object.values(form.errors).join(' ')}
                    </p>
                )}
            </div>
            <DialogFooter>
                <Button type="button" variant="outline" onClick={close}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={
                        form.processing ||
                        !form.data.title.trim() ||
                        !form.data.body.trim()
                    }
                >
                    Share idea
                </Button>
            </DialogFooter>
        </form>
    );
}

function IdeaDetails({
    idea,
    moderate,
    close,
}: {
    idea: HubIdea;
    moderate: boolean;
    close: () => void;
}) {
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [confirmRemove, setConfirmRemove] = useState(false);
    const form = useForm({ action: 'comment', body: '' });
    const review = useForm({
        action: 'status',
        status: idea.status,
        status_note: idea.status_note ?? '',
        version: idea.version,
    });
    const act = (data: Record<string, string | number | boolean>) => {
        setBusy(true);
        setError('');
        router.post(`/company-hub/ideas/${idea.id}`, data, {
            preserveScroll: true,
            onError: (errors) => setError(Object.values(errors).join(' ')),
            onFinish: () => setBusy(false),
            onSuccess: () => {
                if (data.action === 'remove') close();
            },
        });
    };
    return (
        <>
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
                    disabled={busy}
                    aria-pressed={idea.supported}
                    onClick={() =>
                        act({ action: 'support', active: !idea.supported })
                    }
                >
                    <Heart className="size-4" />
                    {idea.supported ? 'Supporting' : 'Support this idea'} ·{' '}
                    {idea.support_count}
                </Button>
                <span className="text-xs text-primary">
                    {ideaStatuses[idea.status]}
                </span>
            </div>
            {idea.status_note && (
                <div className="my-4 rounded-lg bg-muted p-4 text-sm">
                    <strong>Company response</strong>
                    <p className="mt-2 whitespace-pre-wrap">
                        {idea.status_note}
                    </p>
                </div>
            )}
            {moderate && (
                <details className="my-4 rounded-lg border p-4">
                    <summary className="cursor-pointer text-sm font-medium">
                        Update the status
                    </summary>
                    <form
                        className="mt-3 space-y-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            review.post(`/company-hub/ideas/${idea.id}`, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <select
                            className="hub-native-select"
                            aria-label="New idea status"
                            value={review.data.status}
                            onChange={(e) =>
                                review.setData('status', e.target.value)
                            }
                        >
                            {Object.entries(ideaStatuses).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                        <Textarea
                            required
                            maxLength={2000}
                            aria-label="Company response"
                            placeholder="Explain what happens next…"
                            value={review.data.status_note}
                            onChange={(e) =>
                                review.setData('status_note', e.target.value)
                            }
                        />
                        {Object.keys(review.errors).length > 0 && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {Object.values(review.errors).join(' ')}
                            </p>
                        )}
                        <Button disabled={review.processing} type="submit">
                            Save response
                        </Button>
                    </form>
                </details>
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
                                    disabled={busy}
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
                        form.post(`/company-hub/ideas/${idea.id}`, {
                            preserveScroll: true,
                            onSuccess: () => form.reset('body'),
                        });
                    }}
                >
                    <Label className="grid gap-2">
                        Add to the idea
                        <Textarea
                            required
                            maxLength={2000}
                            value={form.data.body}
                            onChange={(e) =>
                                form.setData('body', e.target.value)
                            }
                        />
                    </Label>
                    {form.errors.body && (
                        <p role="alert" className="text-sm text-destructive">
                            {form.errors.body}
                        </p>
                    )}
                    <Button
                        type="submit"
                        disabled={form.processing || !form.data.body.trim()}
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
                    {confirmRemove ? (
                        <div className="flex items-center gap-2 text-sm">
                            <span>Remove this idea from the board?</span>
                            <Button
                                disabled={busy}
                                variant="destructive"
                                onClick={() =>
                                    act({
                                        action: 'remove',
                                        version: idea.version,
                                    })
                                }
                            >
                                Remove idea
                            </Button>
                            <Button
                                variant="ghost"
                                onClick={() => setConfirmRemove(false)}
                            >
                                Keep it
                            </Button>
                        </div>
                    ) : (
                        <Button
                            variant="ghost"
                            onClick={() => setConfirmRemove(true)}
                        >
                            Remove idea…
                        </Button>
                    )}
                </div>
            )}
        </>
    );
}

export function CompanySpaces({
    data,
    mode,
}: {
    data: HubProps;
    mode: 'teams' | 'campaigns' | 'following';
}) {
    const followed = data.followedPages ?? [];
    const pages = data.pages.filter((p) =>
        mode === 'following'
            ? followed.includes(p.id)
            : p.kind === (mode === 'teams' ? 'department' : 'campaign'),
    );
    return (
        <div className="hub-library-results">
            {pages.map((page) => (
                <article key={page.id} className="hub-space-card">
                    {page.cover_url ? (
                        <img src={page.cover_url} alt="" />
                    ) : (
                        <div className="hub-space-cover">
                            <UsersRound className="size-10" />
                        </div>
                    )}
                    <div>
                        <span className="text-xs text-primary">
                            {page.category}
                        </span>
                        <Link
                            className="mt-2 block text-lg font-semibold"
                            href={`/company-hub/read/${page.slug}`}
                        >
                            {page.title}
                        </Link>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {page.summary}
                        </p>
                        <div className="mt-5 flex items-center justify-between">
                            <span className="text-xs text-muted-foreground">
                                {page.owner}
                            </span>
                            <Button
                                variant={
                                    followed.includes(page.id)
                                        ? 'secondary'
                                        : 'outline'
                                }
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        `/company-hub/pages/${page.id}/follow`,
                                        {
                                            following: !followed.includes(
                                                page.id,
                                            ),
                                        },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Bell className="size-3" />
                                {followed.includes(page.id)
                                    ? 'Following'
                                    : 'Follow'}
                            </Button>
                        </div>
                    </div>
                </article>
            ))}
            {pages.length === 0 && (
                <p className="hub-empty-note col-span-full">
                    {mode === 'following'
                        ? 'Follow a page or space to keep its latest updates together here.'
                        : `No ${mode === 'teams' ? 'department spaces' : 'campaigns'} have been published for you yet.`}
                </p>
            )}
        </div>
    );
}

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
                <h2 className="text-lg font-semibold">Get involved</h2>
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
