/* One corrective action — the approved drawing pg-hsn-43 (Stephan, 23 Oct 2026): the action's five stages with the
 * independent check as the next step, what we agreed told as a story, people and linked records beside it. The
 * complete / verify / return / owner panes and their submissions are unchanged. */
import { HS_LABELS, hsLabel } from '@/lib/health-safety/hs-labels';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    ActionOwnerPane,
    ActionOwnershipHistory,
    type ActionOwnership,
} from '@/components/health-safety/action-owner-pane';
import {
    ActionEvidencePanel,
    CompleteActionPane,
    ReturnActionPane,
    VerifyActionPane,
    correctiveActionStage,
    type EventCorrectiveAction,
} from '@/components/health-safety/event-detail-dialog';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import {
    PageHeaderFacts,
    PageHeaderStages,
    type PageHeaderFact,
    type PageHeaderNextStep,
    type PageHeaderStage,
} from '@/components/page/page-header-strip';
import {
    FactRows,
    Log,
    Note,
    PeopleCards,
    QuietLine,
    RowList,
    SideColumn,
    Story,
    Surface,
    type PersonCardItem,
    type SgTone,
    type StoryFact,
} from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import {
    SafetyFormDraftContext,
    useSafetyForm,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import {
    formatDate,
    formatDateOnly,
    formatDateTime,
    toDateInput,
} from '@/lib/datetime';
import {
    correctiveActionHref,
    safetyReviewReturn,
    type CorrectiveActionPane,
    type CorrectiveActionSection,
} from '@/lib/health-safety/navigation';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    CheckCircle2,
    Clock,
    Info,
    ListChecks,
    MapPin,
    Plus,
    RadioTower,
    ShieldAlert,
    ShieldCheck,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

export type CorrectiveActionWorkspace = {
    action: EventCorrectiveAction & {
        description: string | null;
        closed_at: string | null;
    };
    review: {
        id: number;
        reference_number: string;
        status: string;
        site_name: string | null;
        url: string;
    };
    source_task_url: string | null;
    ownership: ActionOwnership;
    can: Record<'start' | 'complete' | 'verify' | 'return' | 'close', boolean>;
};
// Words from the shared H&S labels (docs/health-safety-build/vocabulary.md).
const statuses = HS_LABELS.action_status as Record<string, string>;
const PRIORITY_TONE: Record<string, PageHeaderFact['tone']> = {
    low: 'success',
    medium: 'warning',
    high: 'critical',
    critical: 'critical',
};

/** Tone of an action-history row, read from its words (returned = attention, added/verified/closed = done). */
function historyTone(label: string): SgTone {
    const text = label.toLowerCase();
    if (/return|rework|reopen/.test(text)) return 'warning';
    if (/added|created|verified|checked|closed/.test(text)) return 'success';
    return 'info';
}

export default function CorrectiveActionShow({
    workspace,
}: {
    workspace: CorrectiveActionWorkspace;
}) {
    const { action, review } = workspace;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health & safety', href: '/health-safety' },
                {
                    title: 'Corrective actions',
                    href: '/health-safety/corrective-actions',
                },
                {
                    title: action.title,
                    href: correctiveActionHref(action.id),
                },
            ]}
        >
            <Head title={`${action.title} · ${action.reference_number}`} />
            <ActionRecord
                key={action.id}
                workspace={workspace}
                reviewId={review.id}
            />
        </AppLayout>
    );
}

function ActionRecord({
    workspace,
    reviewId,
}: {
    workspace: CorrectiveActionWorkspace;
    reviewId: number;
}) {
    const { action: ca, review, can, ownership } = workspace;
    const pageUrl = usePage().url;
    const params = new URLSearchParams(
        pageUrl.split('?')[1]?.split('#')[0] ?? '',
    );
    const section: CorrectiveActionSection =
        params.get('section') === 'evidence'
            ? 'evidence'
            : params.get('section') === 'history'
              ? 'history'
              : 'overview';
    const rawAction = params.get('action');
    const pane: CorrectiveActionPane | null = [
        'complete',
        'verify',
        'return',
        'owner',
    ].includes(rawAction ?? '')
        ? (rawAction as CorrectiveActionPane)
        : null;
    const returnTo = params.has('return_to')
        ? safetyReviewReturn(params.get('return_to'))
        : '/health-safety/corrective-actions';
    const draft = useSafetyWorkspaceDrafts();
    const registerDraft = draft.register;
    const [confirmation, setConfirmation] = useState<'start' | 'close' | null>(
        null,
    );
    const command = useSafetyForm({});
    useEffect(() => {
        registerDraft('lifecycle-command', {
            dirty: false,
            busy: command.processing,
        });
        return () => registerDraft('lifecycle-command', null);
    }, [registerDraft, command.processing]);
    const status = statuses[ca.status] ?? 'Needs review';
    const loaded = ca.evidence.load_state === 'loaded';
    const changeView = (
        nextSection: CorrectiveActionSection,
        nextPane: CorrectiveActionPane | null = null,
    ) =>
        router.replace({
            url: correctiveActionHref(ca.id, {
                section: nextSection,
                action: nextPane,
                returnTo,
            }),
            preserveState: true,
            preserveScroll: true,
        });
    const select = (
        nextSection: CorrectiveActionSection,
        nextPane: CorrectiveActionPane | null = null,
    ) => draft.request(() => changeView(nextSection, nextPane));
    const context = {
        id: reviewId,
        can: { verify_corrective_actions: can.verify },
    };
    const done = () => changeView('overview');
    const cancel = () => draft.request(done);
    const canOpenPane =
        pane === 'owner' ? ownership.can_assign : pane && can[pane];

    const owner = ca.owner?.name ?? null;
    const reviewStage = hsLabel('review_status', review.status);
    const today = toDateInput(new Date());
    const dueToday = !!ca.due_date && ca.due_date === today;
    const overdue =
        ca.is_overdue && ca.status !== 'verified' && ca.status !== 'closed';
    const evidenceCount = loaded ? ca.evidence.attachments.length : 0;
    const historyCount =
        (loaded ? ca.history.length : 0) + ownership.history.length;

    /* ---- header actions: one primary, the rest glass (each only when it can run) ---- */
    type HeaderAction = {
        key: string;
        label: string;
        icon?: LucideIcon;
        run: () => void;
        disabled?: boolean;
    };
    const lifecycle: HeaderAction[] = [
        ...(can.verify
            ? [
                  {
                      key: 'verify',
                      label: 'Verify action',
                      icon: ShieldCheck,
                      run: () => select('overview', 'verify'),
                  },
              ]
            : []),
        ...(can.complete
            ? [
                  {
                      key: 'complete',
                      label: 'Mark complete',
                      icon: CheckCircle2,
                      run: () => select('overview', 'complete'),
                  },
              ]
            : []),
        ...(can.start
            ? [
                  {
                      key: 'start',
                      label: 'Start action',
                      icon: Plus,
                      disabled: command.processing,
                      run: () => draft.request(() => setConfirmation('start')),
                  },
              ]
            : []),
        ...(can.close
            ? [
                  {
                      key: 'close',
                      label: 'Close action',
                      icon: CheckCircle2,
                      disabled: command.processing,
                      run: () => draft.request(() => setConfirmation('close')),
                  },
              ]
            : []),
    ];
    const ownerAction: HeaderAction | null = ownership.can_assign
        ? {
              key: 'owner',
              label: ca.owner ? 'Hand over ownership' : 'Assign owner',
              icon: Plus,
              run: () => select('overview', 'owner'),
          }
        : null;
    const primary: HeaderAction | null =
        lifecycle[0] ?? (!ca.owner && ownerAction ? ownerAction : null);
    const glass: HeaderAction[] = [
        ...(can.return
            ? [
                  {
                      key: 'return',
                      label: 'Return for rework',
                      run: () => select('overview', 'return'),
                  },
              ]
            : []),
        ...(ownerAction && ownerAction !== primary ? [ownerAction] : []),
        ...lifecycle.slice(1),
    ];

    /* ---- the stage line and who does what next ---- */
    const stage = correctiveActionStage(ca);
    const stageState = (index: number): PageHeaderStage['state'] =>
        ca.status === 'closed' || index < stage.stage
            ? 'done'
            : index === stage.stage
              ? 'now'
              : 'todo';
    const steps: PageHeaderStage[] = [
        {
            label: 'Agreed',
            meta: `From ${review.reference_number}`,
            state: stageState(1),
        },
        {
            label: 'Owner',
            meta: owner ?? 'Needs an owner',
            state: stageState(2),
        },
        {
            label: 'Done',
            meta: ca.completed_at
                ? [
                      ca.completed_by_name,
                      formatDate(ca.completed_at),
                  ]
                      .filter(Boolean)
                      .join(' · ')
                : ca.status === 'in_progress'
                  ? 'In progress'
                  : undefined,
            state: stageState(3),
        },
        {
            label: 'Checked',
            meta: ca.verified_at
                ? [ca.verified_by_name, formatDate(ca.verified_at)]
                      .filter(Boolean)
                      .join(' · ')
                : ca.status === 'completed'
                  ? 'Waiting'
                  : undefined,
            state: stageState(4),
        },
        {
            label: 'Closed',
            meta: ca.closed_at ? formatDate(ca.closed_at) : undefined,
            state: stageState(5),
        },
    ].map((step) => ({
        ...step,
        tone:
            step.state === 'now' && stage.tone !== 'success'
                ? stage.tone
                : undefined,
    }));
    const dueNote = overdue
        ? ' · overdue'
        : dueToday
          ? ' · due today'
          : ca.due_date
            ? ` · due ${formatDateOnly(ca.due_date)}`
            : '';
    const next: PageHeaderNextStep | null =
        ca.status === 'closed'
            ? null
            : ownership.state === 'replacement_needed'
              ? {
                    label: 'Owner needs replacing',
                    text: 'Choose a new owner',
                    meta: 'The recorded owner needs replacement because their current role, employment or site access no longer supports this responsibility.',
                    tone: 'warning',
                }
              : !ca.owner || ownership.state === 'unassigned'
                ? {
                      label: 'Needs an owner',
                      text: 'Choose who owns this action',
                      meta: 'Assign an accountable owner before starting this action.',
                      tone: 'critical',
                  }
                : ca.status === 'completed'
                  ? {
                        label: `Next step${dueNote}`,
                        text: 'Someone checks the work is done and working',
                        meta: (
                            <>
                                Not <b>{owner}</b> (owner)
                                {ca.completed_by_name &&
                                ca.completed_by_name !== owner ? (
                                    <>
                                        {' '}
                                        or <b>{ca.completed_by_name}</b> (did
                                        it)
                                    </>
                                ) : null}{' '}
                                — an independent check
                            </>
                        ),
                        tone: 'warning',
                    }
                  : ca.status === 'verified'
                    ? {
                          label: 'Next step',
                          text: 'Close the action',
                          meta: ca.verified_by_name
                              ? `Checked by ${ca.verified_by_name}`
                              : undefined,
                      }
                    : {
                          label: `Next step${dueNote}`,
                          text:
                              ca.status === 'open'
                                  ? `${owner} starts the action`
                                  : `${owner} finishes the work and adds evidence`,
                          meta: 'Someone else checks it once it’s done',
                          tone: overdue ? 'critical' : undefined,
                      };

    const facts: PageHeaderFact[] = [
        {
            value: hsLabel('action_priority', ca.priority),
            label: 'Priority',
            tone: PRIORITY_TONE[ca.priority] ?? 'neutral',
        },
        ...(!ca.owner
            ? [
                  {
                      value: 'Needs an owner',
                      label: 'Owner',
                      tone: 'warning' as const,
                  },
              ]
            : []),
        {
            value: ca.due_date ? formatDateOnly(ca.due_date) : 'Not recorded',
            label: overdue ? 'Due · overdue' : 'Due',
            tone: overdue ? 'critical' : dueToday ? 'warning' : undefined,
        },
    ];

    /* ---- body ---- */
    const sourceTask: ReactNode =
        ca.source.type === 'unavailable' ? (
            'The source task link needs review. Ask the H&S team to check the linked records.'
        ) : ca.source.type === 'restricted' ? (
            'The source operational task requires separate access.'
        ) : workspace.source_task_url ? (
            <Link
                className="font-medium text-primary underline-offset-2 hover:underline"
                href={workspace.source_task_url}
            >
                Open source operational task
            </Link>
        ) : null;

    const storyFacts: StoryFact[] = [
        {
            icon: ShieldAlert,
            text: `From ${review.reference_number} · ${reviewStage}`,
        },
        ...(review.site_name
            ? [{ icon: MapPin, text: review.site_name }]
            : []),
        ...(ca.due_date
            ? [
                  {
                      icon: CalendarDays,
                      text: `Due ${formatDateOnly(ca.due_date)}`,
                  },
              ]
            : []),
        ...(ca.source.type === 'control_room_task'
            ? [
                  {
                      icon: RadioTower,
                      text: `Transferred from Control Room task: ${ca.source.reference} · ${ca.source.title}`,
                  },
              ]
            : []),
    ];
    const storyParas: { h: string; t: ReactNode }[] = [
        { h: 'The action', t: ca.description ?? ca.title },
        ...(ca.recommendation ? [{ h: 'Why', t: ca.recommendation }] : []),
        ...(ca.source.type === 'new_responsibility'
            ? [
                  {
                      h: 'Why',
                      t: `New responsibility: ${ca.source.reason ?? 'Reason not recorded'}`,
                  },
              ]
            : []),
        ...(sourceTask ? [{ h: 'Source task', t: sourceTask }] : []),
        ...(ca.evidence.completion_notes
            ? [
                  {
                      h: 'What was done',
                      t: `${[
                          ca.evidence.completed_by?.name ??
                              ca.completed_by_name,
                          ca.evidence.completed_at
                              ? formatDateTime(ca.evidence.completed_at)
                              : null,
                      ]
                          .filter(Boolean)
                          .join(' · ')}${ca.evidence.completed_by || ca.evidence.completed_at ? ' – ' : ''}${ca.evidence.completion_notes}`,
                  },
              ]
            : []),
        ...(ca.rework.latest_reason
            ? [{ h: 'Changes requested', t: ca.rework.latest_reason }]
            : []),
        ...(ca.effectiveness_confirmed !== null
            ? [
                  {
                      h: 'Did it work?',
                      t: `Effectiveness assessment: ${ca.effectiveness_confirmed ? 'Effective' : 'Not effective'}`,
                  },
              ]
            : []),
    ];
    const evidenceShows =
        ca.evidence.load_state === 'unavailable'
            ? ca.status === 'completed'
            : ca.evidence.attachments.length > 0 || ca.evidence.can_upload;

    const main =
        rawAction && !canOpenPane ? (
            <Surface title="This action is unavailable">
                <div className="space-y-3 px-[18px] pt-1 pb-4">
                    <p className="text-sm text-muted-foreground">
                        The record or your access has changed. Return to the
                        action to see its current status.
                    </p>
                    <Button onClick={() => select('overview')}>
                        Return to action
                    </Button>
                </div>
            </Surface>
        ) : pane ? (
            <Surface aria-label="Current corrective action step">
                <div className="p-5">
                    {pane === 'owner' ? (
                        <ActionOwnerPane
                            actionId={ca.id}
                            owner={ca.owner}
                            ownership={ownership}
                            onDone={done}
                            onCancel={cancel}
                        />
                    ) : pane === 'complete' ? (
                        <CompleteActionPane
                            d={context}
                            ca={ca}
                            onDone={done}
                            onCancel={cancel}
                        />
                    ) : pane === 'verify' ? (
                        <VerifyActionPane
                            d={context}
                            ca={ca}
                            onDone={done}
                            onCancel={cancel}
                        />
                    ) : (
                        <ReturnActionPane
                            d={context}
                            ca={ca}
                            onDone={done}
                            onCancel={cancel}
                        />
                    )}
                </div>
            </Surface>
        ) : section === 'evidence' ? (
            <>
                <Story
                    title="What was done"
                    sub={
                        ca.evidence.completed_by
                            ? `Submitted by ${ca.evidence.completed_by.name}${ca.evidence.completed_at ? ` · ${formatDateTime(ca.evidence.completed_at)}` : ''}`
                            : undefined
                    }
                    paras={[
                        {
                            h: 'Evidence it’s done',
                            t:
                                ca.evidence.completion_notes ??
                                (loaded
                                    ? 'No completion notes have been recorded.'
                                    : 'Completion details are unavailable with your current access.'),
                        },
                    ]}
                />
                {evidenceShows ? (
                    <Surface title="Files">
                        <div className="px-[18px] pt-3 pb-4">
                            <ActionEvidencePanel
                                d={context}
                                ca={ca}
                                allowUpload={ca.evidence.can_upload}
                            />
                        </div>
                    </Surface>
                ) : (
                    <QuietLine
                        title="Files"
                        text="No completion evidence has been uploaded."
                    />
                )}
            </>
        ) : section === 'history' ? (
            <>
                <ActionOwnershipHistory ownership={ownership} />
                {ca.history.length ? (
                    <Log
                        title="Action history"
                        limit={ca.history.length}
                        items={[...ca.history].reverse().map((entry) => ({
                            time: entry.occurred_at
                                ? formatDateTime(entry.occurred_at)
                                : '',
                            tone: historyTone(entry.label),
                            title: entry.label,
                            sub:
                                entry.actor || entry.detail ? (
                                    <>
                                        {entry.actor}
                                        {entry.actor && entry.detail
                                            ? ' · '
                                            : ''}
                                        {entry.detail ? (
                                            <span className="whitespace-pre-wrap">
                                                {entry.detail}
                                            </span>
                                        ) : null}
                                    </>
                                ) : undefined,
                        }))}
                    />
                ) : ownership.history.length === 0 ? (
                    <QuietLine
                        title="Action history"
                        text={
                            loaded
                                ? 'No action history is available.'
                                : 'Action history is unavailable with your current access.'
                        }
                    />
                ) : null}
            </>
        ) : (
            <Story title="What we agreed" facts={storyFacts} paras={storyParas} />
        );

    const people: PersonCardItem[] = [
        ...(owner ? [{ name: owner, sub: 'Accountable owner' }] : []),
        ...(ca.completed_by_name
            ? [
                  {
                      name: ca.completed_by_name,
                      sub: `Did the work${ca.completed_at ? ` · ${formatDate(ca.completed_at)}` : ''}`,
                  },
              ]
            : []),
        ...(ca.verified_by_name
            ? [
                  {
                      name: ca.verified_by_name,
                      sub: `Checked it${ca.verified_at ? ` · ${formatDate(ca.verified_at)}` : ''}`,
                  },
              ]
            : []),
    ];
    const noLifecycle = !Object.values(can).some(Boolean);
    const side = pane ? (
        <FactRows
            title="Next step"
            sub="Finish or cancel the current form before choosing another step."
            action={{ label: 'Open safety review', href: review.url }}
            rows={[
                ['Safety review', `${review.reference_number} · ${reviewStage}`],
            ]}
        />
    ) : (
        <>
            <Note icon={Info}>
                Completing this action does not close its incident or safety
                review. Verification remains a separate decision.
            </Note>
            {review.status === 'closed' && ca.status !== 'closed' ? (
                <Note icon={ListChecks}>
                    The safety review is closed. This action remains open until
                    its own work and independent verification are finished.
                </Note>
            ) : null}
            {noLifecycle ? (
                <Note icon={Clock}>
                    {ca.status === 'closed'
                        ? 'This corrective action is closed.'
                        : ca.status === 'completed'
                          ? 'An authorised person independent of the action must review the completion evidence.'
                          : 'The responsible H&S team manages the next step.'}
                </Note>
            ) : null}
            {people.length ? (
                <PeopleCards title="People" cols={1} items={people} />
            ) : null}
            <RowList
                title="Linked records"
                action={{ label: 'Open safety review', href: review.url }}
                items={[
                    {
                        key: 'review',
                        title: `Safety review ${review.reference_number}`,
                        sub: reviewStage,
                        href: review.url,
                        leading: <ShieldAlert className="size-4" />,
                    },
                ]}
            />
        </>
    );

    return (
        <SafetyFormDraftContext.Provider value={draft.register}>
            {draft.confirmation}
            <ConfirmDialog
                open={confirmation !== null}
                onClose={() => {
                    if (!command.processing) setConfirmation(null);
                }}
                frontline
                processing={command.processing}
                variant="default"
                title={
                    confirmation === 'start'
                        ? 'Start this action?'
                        : 'Close this verified action?'
                }
                description={
                    confirmation === 'start'
                        ? 'Record that work on this corrective action is underway.'
                        : 'This closes the corrective action. Incident and safety review closure remain separate decisions.'
                }
                confirmText={
                    confirmation === 'start' ? 'Start action' : 'Close action'
                }
                onConfirm={() => {
                    if (
                        !confirmation ||
                        !can[confirmation] ||
                        command.processing
                    )
                        return;
                    command.post(
                        `/health-safety/events/${reviewId}/corrective-actions/${ca.id}/${confirmation}`,
                        {
                            preserveScroll: true,
                            preserveState: true,
                            onSuccess: () => setConfirmation(null),
                        },
                    );
                }}
            />
            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        wrapTitle
                        icon={ListChecks}
                        title={ca.title}
                        backHref={returnTo}
                        titleChip={
                            <PageHeaderStatusChip
                                variant={
                                    ca.status === 'closed' ||
                                    ca.status === 'verified'
                                        ? 'success'
                                        : 'info'
                                }
                                icon={
                                    ca.status === 'closed' ||
                                    ca.status === 'verified'
                                        ? CheckCircle2
                                        : Clock
                                }
                            >
                                {status}
                            </PageHeaderStatusChip>
                        }
                        subline={[
                            ca.reference_number,
                            review.site_name,
                            ca.due_date
                                ? `Due ${formatDateOnly(ca.due_date)}`
                                : null,
                            owner ? `Owner ${owner}` : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                        actions={
                            pane || !(primary || glass.length) ? undefined : (
                                <>
                                    {glass.map((item) => (
                                        <PageHeaderGlassButton
                                            key={item.key}
                                            disabled={item.disabled}
                                            onClick={item.run}
                                        >
                                            {item.label}
                                        </PageHeaderGlassButton>
                                    ))}
                                    {primary ? (
                                        <PageHeaderPrimaryButton
                                            icon={primary.icon}
                                            disabled={primary.disabled}
                                            onClick={primary.run}
                                        >
                                            {primary.label}
                                        </PageHeaderPrimaryButton>
                                    ) : null}
                                </>
                            )
                        }
                        facts={<PageHeaderFacts items={facts} />}
                        strip={
                            !pane && !(rawAction && !canOpenPane) && next ? (
                                <PageHeaderStages
                                    steps={steps}
                                    next={next}
                                    ariaLabel="Corrective action stage"
                                />
                            ) : !pane && ca.status === 'closed' ? (
                                <PageHeaderStages
                                    steps={steps}
                                    ariaLabel="Corrective action stage"
                                />
                            ) : undefined
                        }
                        rail={
                            <PageHeaderRail<CorrectiveActionSection>
                                items={[
                                    { key: 'overview', label: 'Overview' },
                                    {
                                        key: 'evidence',
                                        label: 'Evidence',
                                        count: evidenceCount || undefined,
                                    },
                                    {
                                        key: 'history',
                                        label: 'History',
                                        count: historyCount || undefined,
                                    },
                                ]}
                                value={section}
                                onSelect={(key) => select(key)}
                                ariaLabel="Corrective action sections"
                            />
                        }
                    />
                }
            >
                <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]">
                    <div className="grid min-w-0 gap-5">{main}</div>
                    <aside
                        className="min-w-0"
                        aria-label="Corrective action people and links"
                    >
                        <SideColumn>{side}</SideColumn>
                    </aside>
                </div>
            </PageLayout>
        </SafetyFormDraftContext.Provider>
    );
}

