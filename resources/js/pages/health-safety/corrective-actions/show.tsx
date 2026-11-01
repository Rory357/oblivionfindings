/* One corrective action — the approved drawing pg-hsn-43 (Stephan, 23 Oct 2026): the action's five stages with the
 * independent check as the next step, what we agreed told as a story, people and linked records beside it. The
 * complete / verify / return / owner panes and their submissions are unchanged. */
import { hsActionStatus, hsLabel } from '@/lib/health-safety/hs-labels';
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
    PageHeaderRail,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import {
    PageHeaderFacts,
    PageHeaderSteps,
    type PageHeaderFact,
    type PageHeaderStep,
} from '@/components/page/page-header-strip';
import {
    PaneLink,
    RecordProgress,
    type RecordProgressActivity,
    type RecordProgressNext,
    type RecordProgressPane,
} from '@/components/signature/record-progress';
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
    /** K1a: a step blocked for now, with the server's reason (only for viewers who could otherwise do it). */
    blocked?: { start?: { reason: string; unblock: string } } | [];
    /** K1a: the next step the server names for this action. */
    next_step?: { key: 'choose_owner'; can: boolean } | null;
};
type ActionStepKey = 'agreed' | 'owner' | 'done' | 'checked' | 'closed';

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
                { title: 'Health and safety', href: '/health-safety' },
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
    const [picked, setPicked] = useState<ActionStepKey | null>(null);
    useEffect(() => {
        registerDraft('lifecycle-command', {
            dirty: false,
            busy: command.processing,
        });
        return () => registerDraft('lifecycle-command', null);
    }, [registerDraft, command.processing]);
    // B1.1: the one status (a checked action says whether it worked), the same words as the board, list and /tasks.
    const status = hsActionStatus(ca).label;
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
                      label: 'Check it worked',
                      icon: ShieldCheck,
                      run: () => select('overview', 'verify'),
                  },
              ]
            : []),
        ...(can.complete
            ? [
                  {
                      key: 'complete',
                      label: 'Mark done',
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
    const replacementNeeded = ownership.state === 'replacement_needed';
    const blockedStart = Array.isArray(workspace.blocked)
        ? undefined
        : workspace.blocked?.start;
    const ownerAction: HeaderAction | null = ownership.can_assign
        ? {
              key: 'owner',
              label: replacementNeeded
                  ? 'Choose a new owner'
                  : ca.owner
                    ? 'Hand over ownership'
                    : 'Assign owner',
              icon: Plus,
              run: () => select('overview', 'owner'),
          }
        : null;
    // M13: while the owner needs replacing, choosing a new one is the main step.
    const primary: HeaderAction | null =
        lifecycle[0] ??
        ((!ca.owner || replacementNeeded) && ownerAction ? ownerAction : null);
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

    /* ---- decision 26: round status steps in the header, the Progress card under it ---- */
    // An owner who can no longer own it puts the action back on the Owner step (M13).
    const stage =
        replacementNeeded && ca.status !== 'closed'
            ? { stage: 2 }
            : correctiveActionStage(ca);
    const didntWork = ca.effectiveness_confirmed === false;
    const stepState = (index: number): PageHeaderStep['state'] =>
        index === 4 && didntWork && stage.stage >= 4 && ca.status !== 'completed'
            ? 'failed'
            : ca.status === 'closed' || index < stage.stage
              ? 'done'
              : index === stage.stage
                ? 'now'
                : 'todo';
    const steps: PageHeaderStep[] = [
        {
            key: 'agreed',
            label: 'Agreed',
            meta: `From ${review.reference_number}`,
            state: stepState(1),
        },
        {
            key: 'owner',
            label: 'Owner',
            meta: replacementNeeded ? 'Needs a new owner' : (owner ?? 'Needs an owner'),
            state: stepState(2),
        },
        {
            key: 'done',
            label: 'Done',
            meta: ca.completed_at
                ? [ca.completed_by_name, formatDate(ca.completed_at)]
                      .filter(Boolean)
                      .join(' · ')
                : ca.status === 'in_progress'
                  ? 'In progress'
                  : 'Not yet',
            state: stepState(3),
        },
        {
            key: 'checked',
            label: 'Checked',
            meta: didntWork
                ? 'Didn’t work'
                : ca.verified_at
                  ? [ca.verified_by_name, formatDate(ca.verified_at)]
                        .filter(Boolean)
                        .join(' · ')
                  : ca.status === 'completed'
                    ? 'Waiting'
                    : 'Not yet',
            state: stepState(4),
        },
        {
            key: 'closed',
            label: 'Closed',
            meta: ca.closed_at ? formatDate(ca.closed_at) : 'Not yet',
            state: stepState(5),
        },
    ];
    const currentStep: ActionStepKey =
        ca.status === 'closed'
            ? 'closed'
            : ((steps.find((s) => s.state === 'now')?.key as ActionStepKey | undefined) ?? 'agreed');
    const shownStep: ActionStepKey = picked ?? currentStep;
    const dueNote = overdue
        ? ' · overdue'
        : dueToday
          ? ' · due today'
          : ca.due_date
            ? ` · due ${formatDateOnly(ca.due_date)}`
            : '';
    // The record's one filled button lives in the next-step bar; everything else is quiet (DESIGN.md).
    const nextButton = primary ? (
        <Button onClick={primary.run} disabled={primary.disabled}>
            {primary.icon ? <primary.icon aria-hidden="true" /> : null}
            {primary.label}
        </Button>
    ) : undefined;
    const next: RecordProgressNext =
        ca.status === 'closed'
            ? {
                  kicker: 'Closed',
                  title: 'This action is closed',
                  text: ca.closed_at
                      ? `Closed ${formatDate(ca.closed_at)}. Closing it doesn’t close its safety review.`
                      : 'Closing it doesn’t close its safety review.',
                  tone: 'done',
              }
            : replacementNeeded
              ? {
                    kicker: 'Owner needs replacing',
                    title: 'Choose a new owner',
                    text: ownership.can_assign
                        ? 'Then they start the action and mark it done.'
                        : `Someone who manages health and safety at ${review.site_name ?? 'this site'} chooses the new owner.`,
                    action: nextButton,
                }
              : !ca.owner || ownership.state === 'unassigned'
                ? {
                      kicker: 'Needs an owner',
                      title: 'Choose who owns this action',
                      text: 'Assign an accountable owner before starting this action.',
                      action: nextButton,
                  }
                : ca.status === 'completed'
                  ? {
                        kicker: `Next step${dueNote}`,
                        title: 'Someone checks the work is done and working',
                        text: (
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
                        action: nextButton,
                    }
                  : ca.status === 'verified'
                    ? {
                          kicker: 'Next step',
                          title: 'Close the action',
                          text: didntWork
                              ? 'The check found it didn’t work. The safety review adds a revised action.'
                              : ca.verified_by_name
                                ? `Checked by ${ca.verified_by_name}`
                                : undefined,
                          action: nextButton,
                      }
                    : {
                          kicker: `Next step${dueNote}`,
                          title:
                              ca.status === 'open'
                                  ? `${owner} starts the action`
                                  : `${owner} finishes the work and adds evidence`,
                          text: 'Someone else checks it once it’s done',
                          action: nextButton,
                      };

    // Off the overview the Progress card isn't shown, so its step stays reachable as a quiet header button.
    const progressShown = !pane && section === 'overview' && !(rawAction && !canOpenPane);
    const headerItems = primary && !progressShown ? [primary, ...glass] : glass;

    /* ---- the picked step's pane: what was added there, then its activity ---- */
    const stepOf = (label: string): ActionStepKey => {
        const text = label.toLowerCase();
        if (/created/.test(text)) return 'agreed';
        if (/verified|returned/.test(text)) return 'checked';
        if (/closed/.test(text)) return 'closed';
        return 'done';
    };
    const actionActivity: Array<RecordProgressActivity & { step: ActionStepKey }> = [
        ...ca.history.map((entry, index) => ({
            key: `h${index}`,
            step: stepOf(entry.label),
            time: entry.occurred_at ? formatDateTime(entry.occurred_at) : '',
            who: entry.actor ?? undefined,
            what: entry.detail ? `${entry.label.toLowerCase()}: ${entry.detail}` : entry.label.toLowerCase(),
        })),
        ...ownership.history.map((change) => ({
            key: `o${change.id}`,
            step: 'owner' as const,
            time: formatDateTime(change.changed_at),
            who: change.changed_by,
            what: `${change.previous_owner ? `handed the action from ${change.previous_owner} to ${change.owner}` : `made ${change.owner} the owner`}${change.reason ? ` — ${change.reason}` : ''}`,
        })),
    ];
    const ownerLink = ownership.can_assign ? (
        <PaneLink onClick={() => select('overview', 'owner')}>
            {replacementNeeded ? 'Choose a new owner' : ca.owner ? 'Hand over' : 'Assign owner'}
        </PaneLink>
    ) : undefined;
    const paneByStep: Record<ActionStepKey, Omit<RecordProgressPane, 'title' | 'meta' | 'onBack' | 'activity'>> = {
        agreed: {
            items: [
                { key: 'action', label: 'The action', value: ca.description ?? ca.title },
                ...(ca.recommendation
                    ? [{ key: 'why', label: 'Why', value: ca.recommendation }]
                    : ca.source.type === 'new_responsibility'
                      ? [{ key: 'why', label: 'Why', value: `New responsibility: ${ca.source.reason ?? 'Reason not recorded'}` }]
                      : []),
                {
                    key: 'from',
                    label: 'From',
                    value: `Safety review ${review.reference_number} · ${reviewStage}`,
                    action: <PaneLink href={review.url}>Open</PaneLink>,
                },
                {
                    key: 'due',
                    label: 'Due',
                    value: ca.due_date ? `${formatDateOnly(ca.due_date)}${overdue ? ' · overdue' : ''}` : 'Not recorded',
                },
            ],
        },
        owner: {
            items: [
                {
                    key: 'owner',
                    label: 'Owner',
                    value: replacementNeeded
                        ? `${owner ?? 'The owner'} can no longer own this action`
                        : (owner ?? 'Needs an owner'),
                    action: ownerLink,
                },
            ],
            children: blockedStart ? (
                <div className="flex flex-wrap items-center gap-2 px-[18px] pb-3">
                    <Button variant="outline" disabled>
                        Start action
                    </Button>
                    <span className="text-caption text-muted-foreground">
                        {blockedStart.reason}
                    </span>
                </div>
            ) : undefined,
        },
        done: {
            items: ca.completed_at || ca.evidence.completion_notes
                ? [
                      {
                          key: 'what',
                          label: 'What was done',
                          value:
                              ca.evidence.completion_notes ??
                              (loaded ? 'No completion notes recorded.' : 'Completion details need separate access.'),
                          meta: [
                              ca.evidence.completed_by?.name ?? ca.completed_by_name,
                              ca.evidence.completed_at ? formatDateTime(ca.evidence.completed_at) : null,
                          ]
                              .filter(Boolean)
                              .join(' · ') || undefined,
                      },
                      {
                          key: 'files',
                          label: 'Files',
                          value: evidenceCount
                              ? `${evidenceCount} ${evidenceCount === 1 ? 'file' : 'files'}`
                              : 'None added',
                          action: <PaneLink onClick={() => select('evidence')}>{evidenceCount ? 'See files' : 'Files'}</PaneLink>,
                      },
                      ...(ca.rework.latest_reason
                          ? [{ key: 'rework', label: 'Changes asked for', value: ca.rework.latest_reason }]
                          : []),
                  ]
                : ca.rework.latest_reason
                  ? [{ key: 'rework', label: 'Changes asked for', value: ca.rework.latest_reason }]
                  : [],
            empty:
                ca.status === 'in_progress'
                    ? `${owner ?? 'The owner'} is doing the work. They mark it done and add evidence.`
                    : 'Nothing added yet. The owner starts the action, then marks it done.',
        },
        checked: {
            items: ca.verified_at
                ? [
                      {
                          key: 'by',
                          label: 'Checked by',
                          value: ca.verified_by_name ?? 'Not recorded',
                          meta: formatDateTime(ca.verified_at),
                      },
                      ...(ca.effectiveness_confirmed !== null
                          ? [{ key: 'worked', label: 'Did it work?', value: ca.effectiveness_confirmed ? 'Yes, it worked' : 'No, it didn’t work' }]
                          : []),
                  ]
                : [],
            empty:
                ca.status === 'completed'
                    ? 'Waiting for an independent check: not the owner or the person who did it.'
                    : 'Not checked yet. Someone else checks it once it’s done.',
        },
        closed: {
            items: ca.closed_at ? [{ key: 'closed', label: 'Closed', value: formatDateTime(ca.closed_at) }] : [],
            empty:
                ca.effectiveness_confirmed === false
                    ? 'Not closed. The check found it didn’t work, so the safety review adds a revised action.'
                    : ca.verified_at
                      ? 'Not closed yet. It can close now that it has been checked.'
                      : 'Not closed yet. It closes after the check.',
            note: 'Closing this action doesn’t close its safety review.',
        },
    };
    const shownMeta = steps.find((s) => s.key === shownStep)?.meta;
    const stepPane: RecordProgressPane = {
        title: steps.find((s) => s.key === shownStep)?.label ?? 'Step',
        meta: shownMeta,
        onBack: shownStep !== currentStep ? () => setPicked(null) : undefined,
        ...paneByStep[shownStep],
        activity: {
            items: actionActivity.filter((entry) => entry.step === shownStep),
            state: loaded || shownStep === 'owner' ? 'ready' : 'error',
            onAll: () => select('history'),
        },
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
                            siteName={review.site_name}
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
            <>
                <RecordProgress next={next} pane={stepPane} />
                <Story title="What we agreed" facts={storyFacts} paras={storyParas} />
            </>
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
                            pane || !headerItems.length ? undefined : (
                                <>
                                    {headerItems.map((item) => (
                                        <PageHeaderGlassButton
                                            key={item.key}
                                            disabled={item.disabled}
                                            onClick={item.run}
                                        >
                                            {item.label}
                                        </PageHeaderGlassButton>
                                    ))}
                                </>
                            )
                        }
                        facts={<PageHeaderFacts items={facts} />}
                        strip={
                            rawAction && !canOpenPane ? undefined : (
                                <PageHeaderSteps
                                    steps={steps}
                                    selected={!pane && section === 'overview' ? shownStep : null}
                                    onSelect={(key) => {
                                        setPicked(key === currentStep ? null : (key as ActionStepKey));
                                        if (pane || section !== 'overview') select('overview');
                                    }}
                                    ariaLabel="Corrective action steps"
                                />
                            )
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

