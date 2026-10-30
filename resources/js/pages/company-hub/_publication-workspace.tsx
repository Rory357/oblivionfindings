import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { shiftInputInstant } from '@/lib/workforce-time-input';
import { router } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarClock,
    Check,
    MessageSquare,
    Send,
    UsersRound,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { HubDateTimeField } from './_date-fields';
import {
    PublicationReadiness,
    type HubReadiness,
} from './_publication-readiness';
import { RevisionSnapshot, type RevisionSummary } from './_revision-history';
import { HubSelect } from './_select';
import type { HubContent } from './_types';

type Choice = { id: number; name: string };
type PublicationMode = 'publish' | 'review' | 'schedule';
type Issue = HubReadiness['issues'][number];

export function PublicationWorkspace({
    pageId,
    kind,
    version,
    content,
    readiness,
    revisions,
    canPublish,
    dirty,
    saving,
    busy,
    sites,
    departments,
    owners,
    owner,
    currentOwner,
    onOwner,
    onAssign,
    onContent,
    onSave,
    onBack,
    onFix,
    onReview,
    onPublished,
    demo = false,
}: {
    pageId: number;
    kind: string;
    version: number;
    content: HubContent;
    readiness?: HubReadiness;
    revisions: RevisionSummary[];
    canPublish: boolean;
    dirty: boolean;
    saving: boolean;
    busy: boolean;
    sites: Choice[];
    departments: Choice[];
    owners: Choice[];
    owner: string;
    currentOwner: number;
    onOwner: (id: string) => void;
    onAssign: () => void;
    onContent: <K extends keyof HubContent>(
        key: K,
        value: HubContent[K],
    ) => void;
    onSave: () => Promise<boolean>;
    onBack: () => void;
    onFix: (issue: Issue) => void;
    onReview: () => Promise<boolean>;
    onPublished: () => void;
    demo?: boolean;
}) {
    const direct = canPublish && !content.requires_approval;
    const [mode, setMode] = useState<PublicationMode>(
        direct ? 'publish' : 'review',
    );
    const [publishAt, setPublishAt] = useState('');
    const [expiresAt, setExpiresAt] = useState('');
    const [confirm, setConfirm] = useState(false);
    const [discard, setDiscard] = useState(false);
    const [sending, setSending] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const refreshLock = useRef(false);
    const [error, setError] = useState('');
    const pending = useRef(false);
    const alive = useRef(false);
    const returnTo = useRef(onBack);
    const heading = useRef<HTMLHeadingElement>(null);
    const timingDirty = !!publishAt || !!expiresAt;
    const navigation = useSettingsLeaveConfirmation(
        timingDirty || sending || uncertain || refreshing,
        'Leave these publication choices?',
        'Your unsent timing choices will be discarded. A publication already sent may still finish; check the saved versions before trying again.',
    );
    useEffect(() => {
        alive.current = true;
        heading.current?.focus({ preventScroll: true });
        heading.current?.scrollIntoView({
            block: 'center',
            behavior: 'instant',
        });
        return () => {
            alive.current = false;
        };
    }, []);
    const blocked =
        dirty ||
        saving ||
        busy ||
        sending ||
        uncertain ||
        refreshing ||
        owner !== String(currentOwner) ||
        readiness?.editable === false ||
        readiness?.content_ready === false;
    const locked =
        busy ||
        saving ||
        sending ||
        uncertain ||
        refreshing ||
        confirm ||
        discard ||
        readiness?.editable === false;
    const activeMode = direct ? mode : 'review';
    const actionLabel =
        activeMode === 'review'
            ? 'Submit for review'
            : activeMode === 'schedule'
              ? 'Schedule publication'
              : 'Publish now';
    const live = revisions.find((revision) => revision.is_live);
    const audienceChoices =
        content.audience.type === 'sites' ? sites : departments;
    const audience =
        content.audience.type === 'all'
            ? 'All staff'
            : audienceChoices
                  .filter((item) => content.audience.ids.includes(item.id))
                  .map((item) => item.name)
                  .join(', ') || 'Choose an audience';
    const reviewSaved = () => {
        if (refreshLock.current || pending.current) return;
        refreshLock.current = true;
        setRefreshing(true);
        navigation.discardAndRun(() => {
            void onReview()
                .then((loaded) => {
                    if (alive.current && !loaded)
                        setError(
                            'Saved versions could not be refreshed. Try checking again before publishing.',
                        );
                })
                .catch(() => {
                    if (alive.current)
                        setError(
                            'Saved versions could not be refreshed. Try checking again before publishing.',
                        );
                })
                .finally(() => {
                    if (alive.current) {
                        refreshLock.current = false;
                        setRefreshing(false);
                    }
                });
        });
    };
    const leave = (action: () => void) => {
        if (pending.current || busy || saving || refreshLock.current) return;
        if (uncertain) {
            reviewSaved();
            return;
        }
        returnTo.current = action;
        if (timingDirty) setDiscard(true);
        else action();
    };
    const timing = () => {
        if (activeMode === 'review') return {};
        const publish =
            activeMode === 'schedule' ? shiftInputInstant(publishAt) : null;
        const expiry = expiresAt ? shiftInputInstant(expiresAt) : null;
        if (
            activeMode === 'schedule' &&
            (!publish || new Date(publish).getTime() <= Date.now())
        )
            throw new Error('Choose a future publication time.');
        if (
            expiry &&
            new Date(expiry).getTime() <=
                (publish ? new Date(publish).getTime() : Date.now())
        )
            throw new Error('Choose an expiry after publication.');
        return { publish_at: publish, expires_at: expiry };
    };
    const reviewAction = () => {
        if (blocked) return;
        setError('');
        try {
            timing();
            setConfirm(true);
        } catch (reason) {
            setError((reason as Error).message);
        }
    };
    const publish = () => {
        if (!confirm || pending.current || blocked) return;
        try {
            const extra = timing();
            if (demo) {
                onPublished();
                return;
            }
            pending.current = true;
            setSending(true);
            setError('');
            let acknowledged = false;
            router.post(
                `/company-hub/manage/${pageId}/${activeMode === 'review' ? 'submit' : 'publish'}`,
                { version, ...extra },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        acknowledged = true;
                        if (alive.current)
                            navigation.discardAndRun(onPublished);
                    },
                    onError: (errors) => {
                        acknowledged = true;
                        if (alive.current) {
                            setError(Object.values(errors).join(' '));
                            setConfirm(false);
                        }
                    },
                    onFinish: () => {
                        if (alive.current) {
                            pending.current = false;
                            setSending(false);
                            if (!acknowledged) {
                                setUncertain(true);
                                setConfirm(false);
                                setError(
                                    'We couldn’t confirm the publication outcome. Check saved versions before trying again.',
                                );
                            }
                        }
                    },
                },
            );
        } catch (reason) {
            if (pending.current) {
                pending.current = false;
                setSending(false);
                setUncertain(true);
            }
            setError((reason as Error).message);
            setConfirm(false);
        }
    };
    return (
        <>
            <section
                aria-label="Publication workspace"
                className="overflow-hidden rounded-2xl border bg-card"
            >
                <header className="space-y-3 border-b px-7 py-6">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="-ml-2"
                        disabled={sending || busy || saving || refreshing}
                        onClick={() => leave(onBack)}
                    >
                        <ArrowLeft className="size-4" /> Back to draft
                    </Button>
                    <p className="text-xs font-semibold tracking-widest text-primary uppercase">
                        Review & publish · Version {version}
                    </p>
                    <h2
                        ref={heading}
                        tabIndex={-1}
                        className="text-2xl font-semibold tracking-tight outline-none"
                    >
                        Ready for your people?
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        A clear review before anything reaches the shared Hub.
                    </p>
                </header>
                <div className="grid grid-cols-[minmax(0,1fr)_360px] items-start gap-8 p-7">
                    <div className="min-w-0 space-y-5">
                        {readiness && (
                            <PublicationReadiness
                                readiness={readiness}
                                dirty={dirty}
                                saving={saving}
                                onFix={(issue) => leave(() => onFix(issue))}
                                onReview={() => leave(reviewSaved)}
                            />
                        )}
                        <div className="flex items-center gap-3 rounded-xl border p-4">
                            <UsersRound className="size-5 shrink-0 text-primary" />
                            <div className="min-w-0 text-sm">
                                <strong className="block">{audience}</strong>
                                <p className="mt-1 text-muted-foreground">
                                    {live
                                        ? `Published version ${live.draft_version} stays visible while you prepare this draft.`
                                        : 'This page has no current published version.'}
                                </p>
                            </div>
                        </div>
                        <div
                            className="overflow-hidden rounded-xl border p-5"
                            aria-label="Publication content preview"
                            inert
                            onClickCapture={(event) => event.preventDefault()}
                        >
                            <RevisionSnapshot content={content} />
                        </div>
                    </div>
                    <aside
                        className="space-y-5 rounded-xl border bg-muted/30 p-5"
                        aria-label="Publication choices"
                    >
                        <fieldset className="space-y-5" disabled={locked}>
                            <div className="space-y-2">
                                <Label htmlFor="publication-audience">
                                    Audience
                                </Label>
                                <HubSelect
                                    id="publication-audience"
                                    label="Audience"
                                    disabled={locked}
                                    value={content.audience.type}
                                    onChange={(value) =>
                                        onContent('audience', {
                                            type: value as HubContent['audience']['type'],
                                            ids: [],
                                        })
                                    }
                                    options={[
                                        { value: 'all', label: 'All staff' },
                                        {
                                            value: 'sites',
                                            label: 'Selected sites',
                                        },
                                        {
                                            value: 'departments',
                                            label: 'Selected departments',
                                        },
                                    ]}
                                />
                                {content.audience.type !== 'all' && (
                                    <div className="space-y-2">
                                        {audienceChoices.map((item) => (
                                            <label
                                                className="flex items-center gap-2 text-sm"
                                                key={item.id}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={content.audience.ids.includes(
                                                        item.id,
                                                    )}
                                                    onChange={(event) =>
                                                        onContent('audience', {
                                                            ...content.audience,
                                                            ids: event.target
                                                                .checked
                                                                ? [
                                                                      ...content
                                                                          .audience
                                                                          .ids,
                                                                      item.id,
                                                                  ]
                                                                : content.audience.ids.filter(
                                                                      (id) =>
                                                                          id !==
                                                                          item.id,
                                                                  ),
                                                        })
                                                    }
                                                />
                                                {item.name}
                                            </label>
                                        ))}
                                        {!audienceChoices.length && (
                                            <p className="text-xs text-muted-foreground">
                                                No available{' '}
                                                {content.audience.type}.
                                            </p>
                                        )}
                                    </div>
                                )}
                            </div>
                            {owners.length > 0 && (
                                <div className="space-y-2">
                                    <Label htmlFor="publication-owner">
                                        Content owner
                                    </Label>
                                    <HubSelect
                                        id="publication-owner"
                                        label="Content owner"
                                        disabled={locked}
                                        value={owner}
                                        onChange={onOwner}
                                        options={[
                                            ...(!owners.some(
                                                (person) =>
                                                    String(person.id) === owner,
                                            )
                                                ? [
                                                      {
                                                          value: owner,
                                                          label: 'Current owner',
                                                      },
                                                  ]
                                                : []),
                                            ...owners.map((person) => ({
                                                value: String(person.id),
                                                label: person.name,
                                            })),
                                        ]}
                                    />
                                    {owner !== String(currentOwner) && (
                                        <Button
                                            variant="outline"
                                            disabled={dirty}
                                            onClick={() => leave(onAssign)}
                                        >
                                            Update owner
                                        </Button>
                                    )}
                                </div>
                            )}
                            {kind === 'news' && (
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={content.show_in_news !== false}
                                        onChange={(event) =>
                                            onContent(
                                                'show_in_news',
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    Show in company news
                                </label>
                            )}
                            <fieldset className="space-y-2">
                                <legend className="mb-2 text-sm font-medium">
                                    Next step
                                </legend>
                                {(
                                    [
                                        [
                                            'publish',
                                            'Publish now',
                                            'Share this saved version with its audience.',
                                            Send,
                                        ],
                                        [
                                            'review',
                                            'Submit for review',
                                            'Ask a publisher to check this saved version.',
                                            MessageSquare,
                                        ],
                                        [
                                            'schedule',
                                            'Schedule publication',
                                            'Choose when colleagues can see this version.',
                                            CalendarClock,
                                        ],
                                    ] as const
                                ).map(([value, label, help, Icon]) => (
                                    <label
                                        key={value}
                                        className={`flex items-start gap-3 rounded-xl border p-3 ${activeMode === value ? 'border-primary/50 bg-primary/5' : 'bg-card'} ${value !== 'review' && !direct ? 'opacity-50' : 'cursor-pointer'}`}
                                    >
                                        <input
                                            type="radio"
                                            name="publication-mode"
                                            className="mt-1"
                                            value={value}
                                            checked={activeMode === value}
                                            disabled={
                                                value !== 'review' && !direct
                                            }
                                            onChange={() => {
                                                setMode(value);
                                                setError('');
                                            }}
                                        />
                                        <Icon className="mt-0.5 size-4 shrink-0 text-primary" />
                                        <span>
                                            <strong className="block text-sm">
                                                {label}
                                            </strong>
                                            <span className="mt-1 block text-xs leading-relaxed text-muted-foreground">
                                                {help}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                            </fieldset>
                            {!direct && (
                                <p className="text-xs text-muted-foreground">
                                    {content.requires_approval
                                        ? 'This page requires another publisher’s approval.'
                                        : 'Your permissions allow submission for a publisher’s review.'}
                                </p>
                            )}
                            {activeMode === 'schedule' && (
                                <div className="space-y-2">
                                    <Label htmlFor="publication-time">
                                        Publish at (New Zealand time)
                                    </Label>
                                    <HubDateTimeField
                                        id="publication-time"
                                        label="Publish at"
                                        value={publishAt}
                                        onChange={(value) =>
                                            setPublishAt(value)
                                        }
                                    />
                                </div>
                            )}
                            {activeMode !== 'review' && (
                                <div className="space-y-2">
                                    <Label htmlFor="publication-expiry">
                                        Optional expiry (New Zealand time)
                                    </Label>
                                    <HubDateTimeField
                                        id="publication-expiry"
                                        label="Expires at"
                                        value={expiresAt}
                                        onChange={(value) =>
                                            setExpiresAt(value)
                                        }
                                    />
                                </div>
                            )}
                            {activeMode === 'review' && (
                                <p className="text-xs text-muted-foreground">
                                    The reviewer chooses publication and expiry
                                    times when approving.
                                </p>
                            )}
                        </fieldset>
                        {dirty && (
                            <Button
                                variant="outline"
                                className="w-full"
                                disabled={saving || busy || sending}
                                onClick={() => void onSave()}
                            >
                                {saving
                                    ? 'Saving…'
                                    : 'Save changes & update checks'}
                            </Button>
                        )}
                    </aside>
                </div>
                <footer className="space-y-4 border-t px-7 py-5">
                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}
                    {uncertain && (
                        <Button
                            variant="outline"
                            disabled={refreshing}
                            onClick={reviewSaved}
                        >
                            {refreshing
                                ? 'Checking saved versions…'
                                : 'Check saved versions'}
                        </Button>
                    )}
                    {discard && (
                        <div className="flex items-center gap-3 rounded-xl border p-4">
                            <p className="mr-auto text-sm">
                                Discard your unsent publication timing choices?
                            </p>
                            <Button
                                variant="outline"
                                onClick={() => setDiscard(false)}
                            >
                                Keep reviewing
                            </Button>
                            <Button
                                variant="destructive"
                                onClick={() => {
                                    setDiscard(false);
                                    returnTo.current();
                                }}
                            >
                                Discard choices
                            </Button>
                        </div>
                    )}
                    {confirm ? (
                        <div className="flex items-center gap-4 rounded-xl border border-primary/30 bg-primary/5 p-4">
                            <div className="mr-auto text-sm">
                                <strong className="block">
                                    {actionLabel} for version {version}?
                                </strong>
                                <p className="mt-1 text-muted-foreground">
                                    {activeMode === 'publish'
                                        ? `This version will become visible to ${audience}.`
                                        : activeMode === 'schedule'
                                          ? 'This saved version will be published at the chosen time.'
                                          : 'This saved version will be sent to the review queue.'}
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                disabled={sending}
                                onClick={() => setConfirm(false)}
                            >
                                Go back
                            </Button>
                            <Button disabled={blocked} onClick={publish}>
                                <Check className="size-4" />
                                {sending ? 'Working…' : 'Confirm'}
                            </Button>
                        </div>
                    ) : (
                        <div className="flex items-center justify-between gap-4">
                            <p className="text-xs text-muted-foreground">
                                {dirty
                                    ? 'Save the draft to update its checks before continuing.'
                                    : 'Your choice applies to this saved version.'}
                            </p>
                            <Button
                                disabled={blocked || discard}
                                onClick={reviewAction}
                            >
                                <Send className="size-4" />
                                {actionLabel}
                            </Button>
                        </div>
                    )}
                </footer>
            </section>
            {navigation.confirmation}
        </>
    );
}
