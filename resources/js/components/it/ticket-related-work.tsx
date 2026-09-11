import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, InfoCard, StepHead } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    readRelatedWork,
    relationshipLabels,
    type RelatedLink,
    type RelatedTicket,
    type RelatedWork,
    type TicketRelationship,
} from '@/hooks/it-ticket-relationship-contract';
import { useItTicketRelationshipCommand } from '@/hooks/use-it-ticket-relationship-command';
import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import { ClipboardCheck, Link2, Search, Unlink } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';

interface Selection {
    ticket: RelatedTicket;
    relationship: TicketRelationship;
    action: 'add' | 'remove';
}
interface Props {
    actorId: number;
    ticketId: number;
    active: boolean;
    onChanged: () => void;
}
const steps = [
    {
        key: 'choose',
        label: 'Choose relationship',
        blurb: 'Keep both records intact',
        icon: Link2,
    },
    {
        key: 'review',
        label: 'Review relationship',
        blurb: 'Check both ticket references',
        icon: ClipboardCheck,
    },
] as const;

export function TicketRelatedWork(props: Props) {
    return (
        <RelatedWorkBody
            key={`${props.actorId}:${props.ticketId}`}
            {...props}
        />
    );
}
function RelatedWorkBody({ actorId, ticketId, active, onChanged }: Props) {
    const [work, setWork] = useState<RelatedWork | null>(null);
    const [loading, setLoading] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);
    const [access, setAccess] = useState<'session' | 'access' | null>(null);
    const [dialog, setDialog] = useState<{
        selection: Selection | null;
    } | null>(null);
    const epoch = useRef(0);
    const request = useRef<AbortController | null>(null);
    const load = useCallback(
        async (search = '', linksPage = 1, candidatesPage = 1) => {
            request.current?.abort();
            const controller = new AbortController();
            request.current = controller;
            const token = ++epoch.current;
            setLoading(true);
            setFailure(null);
            try {
                const response = await axios.get(
                    `/it/tickets/${ticketId}/related-work`,
                    {
                        params: {
                            actor_user_id: actorId,
                            search,
                            links_page: linksPage,
                            candidates_page: candidatesPage,
                        },
                        signal: controller.signal,
                        timeout: 15000,
                    },
                );
                if (epoch.current !== token) return;
                const next = readRelatedWork(response.data, actorId, ticketId);
                if (!next) throw new Error('Unconfirmed related work');
                setWork(next);
                setAccess(null);
            } catch (error) {
                if (epoch.current !== token) return;
                const status = axios.isAxiosError(error)
                    ? error.response?.status
                    : undefined;
                if ([401, 419, 403, 404].includes(status ?? 0)) {
                    setWork(null);
                    setAccess(
                        status === 401 || status === 419 ? 'session' : 'access',
                    );
                } else
                    setFailure(
                        'Related work could not be refreshed. Retry to read the current records.',
                    );
            } finally {
                if (epoch.current === token) setLoading(false);
            }
        },
        [actorId, ticketId],
    );
    useEffect(() => {
        if (active) void load();
    }, [active, load]);
    useEffect(
        () => () => {
            ++epoch.current;
            request.current?.abort();
        },
        [],
    );
    const close = (changed = false) => {
        setDialog(null);
        void load();
        if (changed) onChanged();
    };
    return (
        <section aria-label="Related tickets" className="space-y-3">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <h3 className="text-section-title">Related tickets</h3>
                    <p className="text-caption text-muted-foreground">
                        Internal relationships keep each ticket’s conversation,
                        ownership and history separate.
                    </p>
                </div>
                <Button
                    variant="outline"
                    onClick={() => setDialog({ selection: null })}
                    disabled={!work || !!access || !!failure || loading}
                >
                    <Link2 className="h-4 w-4" />
                    Manage related work
                </Button>
            </div>
            {access ? (
                <p role="alert">
                    {access === 'session'
                        ? 'Sign in again to view related work.'
                        : 'Related work is no longer available to this account.'}
                </p>
            ) : (
                <>
                    {loading && (
                        <p role="status" className="text-caption">
                            Reading related work…
                        </p>
                    )}
                    {failure && (
                        <div role="alert" className="space-y-2">
                            <p>{failure}</p>
                            <Button
                                variant="outline"
                                onClick={() => void load()}
                                disabled={loading}
                            >
                                Retry related work
                            </Button>
                        </div>
                    )}
                    {work && !failure && (
                        <>
                            {work.links.data.length === 0 ? (
                                <p className="text-subtle">
                                    No related tickets are available in this
                                    view.
                                </p>
                            ) : (
                                <ul className="divide-y divide-border rounded-xl border border-border">
                                    {work.links.data.map(
                                        (link: RelatedLink) => (
                                            <li
                                                key={link.id}
                                                className="flex items-center justify-between gap-4 p-4"
                                            >
                                                <div className="min-w-0">
                                                    <p className="text-caption text-muted-foreground">
                                                        {
                                                            relationshipLabels[
                                                                link
                                                                    .relationship
                                                            ]
                                                        }
                                                    </p>
                                                    <Link
                                                        className="font-medium break-words text-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                                                        href={link.ticket.href}
                                                    >
                                                        {link.ticket.reference}{' '}
                                                        · {link.ticket.title}
                                                    </Link>
                                                </div>
                                                {link.can_remove && (
                                                    <Button
                                                        variant="outline"
                                                        aria-label={`Remove ${link.ticket.reference} relationship`}
                                                        onClick={() =>
                                                            setDialog({
                                                                selection: {
                                                                    ticket: link.ticket,
                                                                    relationship:
                                                                        link.relationship,
                                                                    action: 'remove',
                                                                },
                                                            })
                                                        }
                                                    >
                                                        <Unlink className="h-4 w-4" />
                                                        Remove link
                                                    </Button>
                                                )}
                                            </li>
                                        ),
                                    )}
                                </ul>
                            )}
                            <div className="flex justify-end gap-2">
                                <Button
                                    variant="outline"
                                    disabled={loading || work.links.page === 1}
                                    onClick={() =>
                                        void load('', work.links.page - 1)
                                    }
                                >
                                    Previous related tickets
                                </Button>
                                <Button
                                    variant="outline"
                                    disabled={loading || !work.links.has_more}
                                    onClick={() =>
                                        void load('', work.links.page + 1)
                                    }
                                >
                                    Next related tickets
                                </Button>
                            </div>
                        </>
                    )}
                </>
            )}
            {dialog && (
                <RelationshipDialog
                    actorId={actorId}
                    ticketId={ticketId}
                    work={work}
                    loading={loading}
                    failure={failure}
                    access={access}
                    initialSelection={dialog.selection}
                    load={load}
                    onClose={close}
                />
            )}
        </section>
    );
}
function RelationshipDialog({
    actorId,
    ticketId,
    work,
    loading,
    failure,
    access,
    initialSelection,
    load,
    onClose,
}: {
    actorId: number;
    ticketId: number;
    work: RelatedWork | null;
    loading: boolean;
    failure: string | null;
    access: 'session' | 'access' | null;
    initialSelection: Selection | null;
    load: (
        search?: string,
        linksPage?: number,
        candidatesPage?: number,
    ) => Promise<void>;
    onClose: (changed?: boolean) => void;
}) {
    const command = useItTicketRelationshipCommand(actorId, ticketId);
    const [selection, setSelection] = useState<Selection | null>(
        initialSelection,
    );
    const [kind, setKind] = useState<TicketRelationship>(
        initialSelection?.relationship ?? 'related_ticket',
    );
    const [query, setQuery] = useState('');
    const [step, setStep] = useState(initialSelection ? 1 : 0);
    const [review, setReview] = useState<{
        source: RelatedTicket;
        selection: Selection;
    } | null>(
        initialSelection && work
            ? { source: work.source, selection: initialSelection }
            : null,
    );
    const [leave, setLeave] = useState<(() => void) | null>(null);
    const [formIssue, setFormIssue] = useState<string | null>(null);
    const approved = useRef(false);
    const body = useRef<HTMLDivElement>(null);
    const heading = useRef<HTMLHeadingElement>(null);
    const pending =
        command.identity !== null &&
        command.stage !== 'settled' &&
        command.stage !== 'rejected';
    const concealed =
        !!access || command.stage === 'access' || command.stage === 'session';
    const guarded = !command.result && (!!selection || pending);
    const changed = command.result?.status === 'committed';
    const finish = () => onClose(changed);
    const close = () => {
        if (guarded && !concealed) setLeave(() => finish);
        else finish();
    };
    useLayoutEffect(() => {
        const region = body.current?.closest('[data-wizard-region="body"]');
        if (region instanceof HTMLElement) region.scrollTop = 0;
        heading.current?.focus({ preventScroll: true });
    }, [step, command.stage]);
    useEffect(() => {
        if (!guarded || concealed) return;
        const unload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', unload);
        const remove = router.on('before', (event) => {
            if (approved.current || event.detail.visit.method !== 'get') return;
            event.preventDefault();
            const visit = event.detail.visit;
            setLeave(() => () => {
                approved.current = true;
                router.visit(visit.url, visit);
            });
        });
        return () => {
            window.removeEventListener('beforeunload', unload);
            remove();
        };
    }, [guarded, concealed]);
    useEffect(() => {
        if (concealed) {
            setSelection(null);
            setReview(null);
            setQuery('');
        }
    }, [concealed]);
    const editable =
        !pending &&
        !command.busy &&
        !concealed &&
        !loading &&
        !failure &&
        command.stage !== 'unavailable' &&
        command.stage !== 'settled';
    const refresh = () => {
        command.reset();
        setFormIssue(null);
        setSelection(null);
        setReview(null);
        setStep(0);
        void load(query);
    };
    const startReview = () => {
        if (!editable) return;
        if (!selection || !work?.can_change) {
            setFormIssue(
                'Choose an eligible ticket before reviewing the relationship.',
            );
            return;
        }
        setFormIssue(null);
        setReview({
            source: work.source,
            selection: { ...selection, relationship: kind },
        });
        setStep(1);
    };
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title="Manage related work"
                description="Link or unlink existing tickets without moving their conversations."
                railIcon={Link2}
                railTitle="Related tickets"
                railSub="IT & Support"
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (editable && index === 0) {
                        setStep(0);
                        setReview(null);
                        if (selection?.action === 'remove') setSelection(null);
                    }
                    if (index === 1) startReview();
                }}
                footerStart={
                    <Button variant="outline" onClick={close}>
                        Close
                    </Button>
                }
                footerEnd={
                    concealed ? null : command.result ? (
                        <Button onClick={finish}>
                            Return to related tickets
                        </Button>
                    ) : command.busy ? (
                        <Button variant="outline" onClick={command.stopWaiting}>
                            Stop waiting
                        </Button>
                    ) : pending ? (
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" onClick={command.check}>
                                Check result
                            </Button>
                            {command.canRetry && (
                                <Button
                                    variant="outline"
                                    onClick={command.retry}
                                >
                                    Retry same change
                                </Button>
                            )}
                            <Button variant="outline" onClick={command.cancel}>
                                Cancel pending change
                            </Button>
                        </div>
                    ) : step === 0 ? (
                        <Button
                            disabled={
                                !selection ||
                                !work?.can_change ||
                                loading ||
                                !editable
                            }
                            onClick={startReview}
                        >
                            Review relationship
                        </Button>
                    ) : (
                        <Button
                            disabled={
                                !review ||
                                !work?.can_change ||
                                !editable ||
                                command.stage === 'rejected'
                            }
                            onClick={() => {
                                if (review)
                                    command.send({
                                        targetId: review.selection.ticket.id,
                                        relationship:
                                            review.selection.relationship,
                                        action: review.selection.action,
                                        sourceVersion:
                                            review.source.lock_version,
                                        targetVersion:
                                            review.selection.ticket
                                                .lock_version,
                                    });
                            }}
                        >
                            {review?.selection.action === 'remove'
                                ? 'Remove relationship'
                                : 'Save relationship'}
                        </Button>
                    )
                }
                success={
                    command.result?.status === 'committed' ? (
                        <WizardSuccessPane
                            title={
                                command.result.changed
                                    ? 'Relationship saved'
                                    : 'Relationship already matched'
                            }
                            blurb="The recorded change is confirmed. Refresh the related tickets to see their current state."
                            actions={
                                <Button onClick={finish}>
                                    Return to related tickets
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <div ref={body} className="space-y-4">
                    <h2 ref={heading} tabIndex={-1} className="sr-only">
                        {concealed
                            ? 'Related work unavailable'
                            : step === 0
                              ? 'Choose a related ticket'
                              : 'Review relationship'}
                    </h2>
                    {concealed ? (
                        <div role="alert" className="space-y-3">
                            <p>
                                {access === 'session' ||
                                command.stage === 'session'
                                    ? 'Sign in again before checking this relationship.'
                                    : 'Access to these records is no longer available.'}
                            </p>
                            {(access === 'session' ||
                                command.stage === 'session') && (
                                <Button asChild variant="outline">
                                    <a href="/login">Sign in</a>
                                </Button>
                            )}
                        </div>
                    ) : (
                        <>
                            {formIssue && (
                                <p role="alert" className="text-subtle">
                                    {formIssue}
                                </p>
                            )}
                            {command.message && (
                                <p role="alert" className="text-subtle">
                                    {command.message}
                                </p>
                            )}
                            {command.stage === 'rejected' && (
                                <Button variant="outline" onClick={refresh}>
                                    Refresh records and review again
                                </Button>
                            )}
                            {command.result?.status === 'cancelled' ? (
                                <p role="status">
                                    This pending change was cancelled. No late
                                    request with its reference can apply it.
                                </p>
                            ) : pending ? (
                                <InfoCard icon={Link2}>
                                    Check the outcome before making another
                                    change. Only the command reference is
                                    retained after closing; stopping the wait
                                    does not cancel the server request.
                                </InfoCard>
                            ) : (
                                <WizardStepPane key={step}>
                                    {step === 0 ? (
                                        <div className="space-y-4">
                                            <StepHead
                                                icon={Link2}
                                                title="Choose a related ticket"
                                                blurb="Both records keep their existing ownership, audiences and history."
                                            />
                                            {!work?.can_change && (
                                                <p role="status">
                                                    Reopen an unmerged source
                                                    ticket before changing its
                                                    relationships.
                                                </p>
                                            )}
                                            <Field label="Relationship">
                                                <div className="flex flex-wrap gap-2">
                                                    {(
                                                        Object.keys(
                                                            relationshipLabels,
                                                        ) as TicketRelationship[]
                                                    ).map((value) => (
                                                        <Button
                                                            key={value}
                                                            variant={
                                                                kind === value
                                                                    ? 'default'
                                                                    : 'outline'
                                                            }
                                                            aria-pressed={
                                                                kind === value
                                                            }
                                                            disabled={!editable}
                                                            onClick={() => {
                                                                setKind(value);
                                                                if (selection)
                                                                    setSelection(
                                                                        {
                                                                            ...selection,
                                                                            relationship:
                                                                                value,
                                                                            action: 'add',
                                                                        },
                                                                    );
                                                            }}
                                                        >
                                                            {
                                                                relationshipLabels[
                                                                    value
                                                                ]
                                                            }
                                                        </Button>
                                                    ))}
                                                </div>
                                            </Field>
                                            <form
                                                className="flex gap-2"
                                                onSubmit={(event) => {
                                                    event.preventDefault();
                                                    void load(query);
                                                }}
                                            >
                                                <Input
                                                    aria-label="Search related tickets"
                                                    placeholder="Reference or title"
                                                    value={query}
                                                    maxLength={160}
                                                    onChange={(event) =>
                                                        setQuery(
                                                            event.target.value,
                                                        )
                                                    }
                                                    disabled={!editable}
                                                />
                                                <Button
                                                    variant="outline"
                                                    type="submit"
                                                    disabled={
                                                        loading || !editable
                                                    }
                                                >
                                                    <Search className="h-4 w-4" />
                                                    Search
                                                </Button>
                                            </form>
                                            {loading && (
                                                <p role="status">
                                                    Reading eligible tickets…
                                                </p>
                                            )}
                                            {failure && (
                                                <div role="alert">
                                                    <p>{failure}</p>
                                                    <Button
                                                        variant="outline"
                                                        onClick={() =>
                                                            void load(query)
                                                        }
                                                    >
                                                        Retry search
                                                    </Button>
                                                </div>
                                            )}
                                            {!loading && !failure && work && (
                                                <>
                                                    <div
                                                        aria-label="Related ticket candidates"
                                                        className="max-h-60 space-y-2 overflow-y-auto"
                                                    >
                                                        {work.candidates.data
                                                            .length === 0 ? (
                                                            <p className="text-subtle">
                                                                No eligible
                                                                tickets match
                                                                this search.
                                                            </p>
                                                        ) : (
                                                            work.candidates.data.map(
                                                                (candidate) => (
                                                                    <Button
                                                                        key={
                                                                            candidate.id
                                                                        }
                                                                        variant="outline"
                                                                        className="h-auto min-h-11 w-full justify-start text-left whitespace-normal"
                                                                        disabled={
                                                                            !editable ||
                                                                            !work.can_change
                                                                        }
                                                                        aria-pressed={
                                                                            selection
                                                                                ?.ticket
                                                                                .id ===
                                                                            candidate.id
                                                                        }
                                                                        onClick={() =>
                                                                            setSelection(
                                                                                {
                                                                                    ticket: candidate,
                                                                                    relationship:
                                                                                        kind,
                                                                                    action: 'add',
                                                                                },
                                                                            )
                                                                        }
                                                                    >
                                                                        <span className="min-w-0">
                                                                            <span className="text-caption block text-muted-foreground">
                                                                                {
                                                                                    candidate.reference
                                                                                }
                                                                            </span>
                                                                            <span className="block break-words">
                                                                                {
                                                                                    candidate.title
                                                                                }
                                                                            </span>
                                                                        </span>
                                                                    </Button>
                                                                ),
                                                            )
                                                        )}
                                                    </div>
                                                    <div className="flex justify-end gap-2">
                                                        <Button
                                                            variant="outline"
                                                            disabled={
                                                                work.candidates
                                                                    .page ===
                                                                    1 ||
                                                                !editable
                                                            }
                                                            onClick={() =>
                                                                void load(
                                                                    query,
                                                                    1,
                                                                    work
                                                                        .candidates
                                                                        .page -
                                                                        1,
                                                                )
                                                            }
                                                        >
                                                            Previous candidates
                                                        </Button>
                                                        <Button
                                                            variant="outline"
                                                            disabled={
                                                                !work.candidates
                                                                    .has_more ||
                                                                !editable
                                                            }
                                                            onClick={() =>
                                                                void load(
                                                                    query,
                                                                    1,
                                                                    work
                                                                        .candidates
                                                                        .page +
                                                                        1,
                                                                )
                                                            }
                                                        >
                                                            Next candidates
                                                        </Button>
                                                    </div>
                                                </>
                                            )}
                                            {selection && (
                                                <p className="text-subtle">
                                                    Selected:{' '}
                                                    {selection.ticket.reference}{' '}
                                                    · {selection.ticket.title}
                                                </p>
                                            )}
                                        </div>
                                    ) : (
                                        review && (
                                            <div className="space-y-4">
                                                <StepHead
                                                    icon={
                                                        review.selection
                                                            .action === 'remove'
                                                            ? Unlink
                                                            : ClipboardCheck
                                                    }
                                                    title="Review relationship"
                                                    blurb={
                                                        review.selection
                                                            .action === 'remove'
                                                            ? 'Remove this relationship from both records. Its audit history remains.'
                                                            : 'Add an internal reference on both records. This does not merge or settle either ticket.'
                                                    }
                                                />
                                                <div className="grid grid-cols-2 gap-3">
                                                    <ReviewCard
                                                        icon={Link2}
                                                        title="This ticket"
                                                    >
                                                        <p className="text-caption">
                                                            {
                                                                review.source
                                                                    .reference
                                                            }
                                                        </p>
                                                        <p className="break-words">
                                                            {
                                                                review.source
                                                                    .title
                                                            }
                                                        </p>
                                                    </ReviewCard>
                                                    <ReviewCard
                                                        icon={Link2}
                                                        title="Related ticket"
                                                    >
                                                        <p className="text-caption">
                                                            {
                                                                review.selection
                                                                    .ticket
                                                                    .reference
                                                            }
                                                        </p>
                                                        <p className="break-words">
                                                            {
                                                                review.selection
                                                                    .ticket
                                                                    .title
                                                            }
                                                        </p>
                                                    </ReviewCard>
                                                </div>
                                                <ReviewRow
                                                    label="Relationship"
                                                    value={
                                                        relationshipLabels[
                                                            review.selection
                                                                .relationship
                                                        ]
                                                    }
                                                />
                                                <InfoCard icon={Link2}>
                                                    This reference grants no
                                                    access and changes no
                                                    problem, change or
                                                    major-incident membership.
                                                    Both tickets are checked
                                                    again when saving.
                                                </InfoCard>
                                                <Button
                                                    variant="outline"
                                                    disabled={!editable}
                                                    onClick={() => {
                                                        setStep(0);
                                                        setReview(null);
                                                        if (
                                                            selection?.action ===
                                                            'remove'
                                                        )
                                                            setSelection(null);
                                                    }}
                                                >
                                                    Back to selection
                                                </Button>
                                            </div>
                                        )
                                    )}
                                </WizardStepPane>
                            )}
                        </>
                    )}
                </div>
            </WizardShell>
            <ConfirmDialog
                open={leave !== null}
                onClose={() => setLeave(null)}
                onConfirm={() => {
                    approved.current = true;
                    leave?.();
                }}
                title={
                    pending
                        ? 'Leave this pending change?'
                        : 'Discard this relationship proposal?'
                }
                description={
                    pending
                        ? 'The request may still finish. Its opaque reference is kept so you can check the outcome next time you open related work.'
                        : 'The unsubmitted selection will be discarded. Cancel to keep reviewing it.'
                }
                confirmText={
                    pending ? 'Leave and check later' : 'Discard proposal'
                }
                variant="default"
            />
        </>
    );
}
