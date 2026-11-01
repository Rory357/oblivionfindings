/**
 * The safety review's round status steps and Progress card (board M5, approved by Stephan 1 Nov 2026, decision 26).
 * Steps: Assigned › Investigated › Implemented › Check it worked › Closed. The step the review is on is picked when the
 * page opens; picking another shows what was added there. The next-step bar comes from the server's `next_step` (the
 * same order as closing needs) and holds the page's only filled button; a viewer who can't do the step is told who does.
 */
import { CheckCircle2, ChevronDown, ClipboardCheck, FileDown, ListChecks, Paperclip, PencilLine, Plus, ShieldCheck, UserRoundCheck, XCircle } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import type {
    EventCorrectiveAction,
    EventDetail,
    EventInvestigation,
    EventSectionKey,
    SafetyReviewPane,
} from '@/components/health-safety/event-detail-dialog';
import { PendingCorrection } from '@/components/health-safety/review-correction';
import { filesSummary } from '@/components/health-safety/review-files';
import { REVIEW_STEP_LABELS, stepActivity, type ReviewActivity, type ReviewStepKey } from '@/components/health-safety/review-history';
import type { PageHeaderStep } from '@/components/page/page-header-strip';
import { PaneLink, type RecordProgressNext, type RecordProgressPane } from '@/components/signature/record-progress';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDate, formatDateOnly } from '@/lib/datetime';

const DONE = ['completed', 'verified', 'closed'];
const CHECKED = ['verified', 'closed'];

function day(iso: string | null | undefined): string | undefined {
    if (!iso) return undefined;
    // "Tue 27 Oct", as the board writes step dates.
    return formatDate(iso, '') || undefined;
}

function latest(values: (string | null | undefined)[]): string | null {
    return values.filter((value): value is string => !!value).sort().at(-1) ?? null;
}

export function agreedInvestigation(d: EventDetail): EventInvestigation | null {
    return d.investigations.find((inv) => inv.status === 'completed') ?? null;
}

type StepState = { key: ReviewStepKey; done: boolean; failed?: boolean; meta?: ReactNode };

function stepStates(d: EventDetail): StepState[] {
    const closed = d.status === 'closed';
    const actions = d.corrective_actions;
    const agreed = agreedInvestigation(d);
    const decided = d.worksafe.decision_signed;
    const invNeeded = d.investigation_required;
    const doneCount = actions.filter((a) => DONE.includes(a.status)).length;
    const implemented = actions.length > 0 && doneCount === actions.length;
    const failedAction = actions.find((a) => a.needs_revision);
    const revisedOk = d.close_readiness.requirements.find((r) => r.key === 'revised_actions')?.complete ?? true;
    const checked = implemented && actions.every((a) => CHECKED.includes(a.status)) && !failedAction && revisedOk;
    const anyRevision = actions.some((a) => a.replaces);

    // G4-6: "Assigned" means passed to Health and safety (automatically for a restraint); a step with nothing to do
    // (no investigation needed, no actions) is drawn as passed, so the review sits on the step that's really left.
    const handedOver = ['accepted', 'not_required'].includes(d.handover.status);
    const investigatedDone = closed || !invNeeded || !!agreed;
    const nothingToDo = actions.length === 0 && investigatedDone && !(agreed?.recommendation_count ?? 0);

    return [
        {
            key: 'assigned',
            done: !!d.handover.owner || handedOver || closed,
            meta: d.handover.owner
                ? day(d.handover.accepted_at ?? d.reported_at)
                : d.handover.status === 'not_required'
                  ? `Automatically${day(d.reported_at) ? ` · ${day(d.reported_at)}` : ''}`
                  : handedOver
                    ? 'Taken on'
                    : 'Waiting to be taken on',
        },
        {
            key: 'investigated',
            done: investigatedDone,
            meta: agreed?.completed_at
                ? day(agreed.completed_at)
                : !invNeeded
                  ? `Not needed${d.severity === 'low' ? ' at Low severity' : ''}`
                  : 'Not yet',
        },
        {
            key: 'implemented',
            done: closed || implemented || nothingToDo,
            meta: implemented
                ? day(latest(actions.map((a) => a.completed_at)))
                : actions.length
                  ? `${doneCount} of ${actions.length} done`
                  : nothingToDo
                    ? 'None'
                    : 'No actions yet',
        },
        {
            key: 'check',
            done: closed || checked || nothingToDo,
            failed: !!failedAction && !closed,
            meta: failedAction ? (
                <>
                    <span className="eh-rstep-bad">Failed</span>
                    {failedAction.verified_at ? ` · ${day(failedAction.verified_at)}` : ''}
                </>
            ) : checked ? (
                `${day(latest(actions.map((a) => a.verified_at))) ?? ''}${anyRevision ? ' · revised fix worked' : ''}`
            ) : nothingToDo ? (
                'Nothing to check'
            ) : (
                'Not yet'
            ),
        },
        {
            key: 'closed',
            done: closed,
            meta: closed ? day(d.closed_at) : !decided ? 'After the WorkSafe decision' : 'Not yet',
        },
    ];
}

/** The step the review is on: a check that didn't work, else the first step not done. */
export function currentReviewStep(d: EventDetail): ReviewStepKey {
    const states = stepStates(d);
    return (states.find((s) => s.failed) ?? states.find((s) => !s.done) ?? states[states.length - 1]).key;
}

export function reviewRoundSteps(d: EventDetail): PageHeaderStep[] {
    const current = currentReviewStep(d);
    return stepStates(d).map((s) => ({
        key: s.key,
        label: REVIEW_STEP_LABELS[s.key],
        meta: s.meta,
        state: s.done && !s.failed ? 'done' : s.key === current ? (s.failed ? 'failed' : 'now') : 'todo',
    }));
}

/** The header chip: what most needs doing, in words ("Revised action needed"), else the review stage. */
export function reviewStatusChip(d: EventDetail, stageLabel: string): { label: string; tone: 'success' | 'info' | 'warning' | 'critical' } {
    if (d.status === 'closed') return { label: 'Closed', tone: 'success' };
    if (d.corrective_actions.some((a) => a.needs_revision)) return { label: 'Revised action needed', tone: 'warning' };
    if (!d.handover.owner && d.next_step?.key === 'choose_owner') return { label: 'Needs an owner', tone: 'warning' };
    return { label: stageLabel, tone: 'info' };
}

export type ReviewStepHandlers = {
    onPane: (pane: SafetyReviewPane) => void;
    onSection: (section: EventSectionKey) => void;
    onOwner: () => void;
    onCorrect: (investigation: EventInvestigation) => void;
};

/** The fixed next-step bar. */
export function reviewNextBar(d: EventDetail, h: ReviewStepHandlers): RecordProgressNext | null {
    if (d.status === 'closed') {
        return {
            kicker: 'Closed',
            tone: 'done',
            title: 'Nothing left to do',
            text: `Closed${d.closed_at ? ` ${formatDate(d.closed_at)}` : ''}. The final record can be downloaded as a PDF from the header.`,
        };
    }
    const step = d.next_step;
    if (!step) return null;
    const failed = step.action_id ? d.corrective_actions.find((a) => a.id === step.action_id) : undefined;
    const blockers = d.close_readiness.requirements.filter((r) => !r.complete).length;
    const button = (label: string, run: () => void, icon: ReactNode = null) => (
        <Button type="button" onClick={run}>
            {icon}
            {label}
        </Button>
    );

    const plan: Record<string, { text: ReactNode; action: ReactNode | null }> = {
        take_on: {
            text: 'Accept the handover from the incident, then it has an owner.',
            action: d.handover.can_accept ? button('Take it on', () => h.onPane({ kind: 'accept_handover' })) : null,
        },
        worksafe_decision: {
            text: 'Decide whether WorkSafe must be told. The other WorkSafe steps depend on it.',
            action: d.worksafe.can_decide ? button('Record WorkSafe decision', () => h.onPane({ kind: 'worksafe_decision' })) : null,
        },
        worksafe_notify: {
            text: 'It’s notifiable: WorkSafe must be told as soon as possible.',
            action: d.worksafe.can_notify ? button('Record that WorkSafe was told', () => h.onPane({ kind: 'worksafe_notify' })) : null,
        },
        worksafe_acknowledge: {
            text: 'Record when WorkSafe acknowledged it.',
            action: d.worksafe.can_acknowledge ? button('Record acknowledgement', () => h.onPane({ kind: 'worksafe_acknowledge' })) : null,
        },
        choose_owner: {
            text: 'This safety review can’t close without an owner.',
            action: d.can.change_owner
                ? button('Choose owner', h.onOwner, <UserRoundCheck className="size-4" aria-hidden="true" />)
                : null,
        },
        investigation: {
            text: 'Agree the findings, then turn the recommendations into actions.',
            action: step.can ? button('Open the investigation', () => h.onSection('investigation')) : null,
        },
        revised_action: {
            text: 'Its check said it didn’t work. This review can’t close until a revised action is added, done and checked.',
            action:
                step.can && failed
                    ? button(
                          'Add revised action',
                          () => h.onPane({ kind: 'ca_add', replacesActionId: failed.id }),
                          <Plus className="size-4" aria-hidden="true" />,
                      )
                    : null,
        },
        actions: {
            text: 'Each action’s owner does their part, then someone else checks it worked.',
            action: step.can ? button('Open actions', () => h.onSection('actions')) : null,
        },
        close: {
            text: blockers
                ? `${blockers} ${blockers === 1 ? 'thing' : 'things'} left before it can close.`
                : 'Everything needed to close is done.',
            action: d.can.close ? button('Close safety review', () => h.onPane({ kind: 'close' }), <CheckCircle2 className="size-4" aria-hidden="true" />) : null,
        },
    };
    const entry = plan[step.key] ?? { text: null, action: null };
    return {
        title: step.label,
        // A viewer who can't do the step is told who does (IR3b: no disabled buttons).
        text: entry.action ? entry.text : step.who,
        action: entry.action,
    };
}

function invMeta(inv: EventInvestigation): string {
    const parts = [
        inv.submitted_by_name ? `Submitted by ${inv.submitted_by_name}` : null,
        inv.reviewed_by_name ? `reviewed by ${inv.reviewed_by_name}` : null,
        inv.approved_by_name ? `approved by ${inv.approved_by_name}` : null,
    ].filter(Boolean);
    const when = inv.completed_at ? formatDate(inv.completed_at) : null;
    return [parts.join(' · '), when].filter(Boolean).join(', ');
}

function actionLine(action: EventCorrectiveAction): string {
    const owner = action.owner?.name ?? action.assigned_to_name;
    const status = CHECKED.includes(action.status)
        ? action.effectiveness_confirmed === false
            ? 'checked — it didn’t work'
            : 'checked — it worked'
        : action.status === 'completed'
          ? 'done, waiting for a check'
          : action.status === 'in_progress'
            ? 'in progress'
            : 'not started';
    return [owner, status, action.due_date && !DONE.includes(action.status) ? `due ${formatDateOnly(action.due_date)}` : null]
        .filter(Boolean)
        .join(' · ');
}

/** The picked step's pane, with the step's own files (board M5d) as its last row. */
export function reviewStepPane(
    d: EventDetail,
    step: ReviewStepKey,
    activity: ReviewActivity | null,
    h: ReviewStepHandlers & { onBack?: () => void; onHistory: () => void; onAddFiles?: (step: ReviewStepKey) => void; onPdf?: () => void },
): RecordProgressPane {
    const pane = stepPaneBase(d, step, activity, h);
    const files = (d.files ?? []).filter((file) => file.step === step);
    const canAdd = !!d.can.add_files && !!h.onAddFiles && d.status !== 'closed';
    if (!files.length && !canAdd) return pane;
    return {
        ...pane,
        items: [
            ...(pane.items ?? []),
            {
                key: 'files',
                label: 'Files',
                value: files.length ? (
                    // eslint-disable-next-line no-restricted-syntax -- a quiet link from the step to its files on the Files tab.
                    <button type="button" className="sg-pane-link" onClick={() => h.onSection('evidence')}>
                        {filesSummary(files)}
                    </button>
                ) : (
                    'None yet'
                ),
                meta: files.length ? `Added by ${[...new Set(files.map((file) => file.uploaded_by))].join(', ')}` : undefined,
                action: canAdd ? (
                    <PaneLink icon={Paperclip} onClick={() => h.onAddFiles?.(step)}>
                        Add files
                    </PaneLink>
                ) : null,
            },
        ],
    };
}

function stepPaneBase(
    d: EventDetail,
    step: ReviewStepKey,
    activity: ReviewActivity | null,
    h: ReviewStepHandlers & { onBack?: () => void; onHistory: () => void; onPdf?: () => void },
): RecordProgressPane {
    const base = {
        onBack: h.onBack,
        activity: activity
            ? { items: stepActivity(activity, step), state: activity.state, onAll: h.onHistory }
            : undefined,
    };
    const open = d.status !== 'closed';

    if (step === 'assigned') {
        const owner = d.handover.owner;
        return {
            ...base,
            title: 'Assigned',
            meta: owner
                ? d.handover.accepted_by
                    ? `Taken on by ${d.handover.accepted_by.name}${d.handover.accepted_at ? `, ${formatDate(d.handover.accepted_at)}` : ''}`
                    : `Owner ${owner.name}`
                : 'No owner yet',
            items: [
                {
                    key: 'owner',
                    label: 'Owner',
                    value: owner?.name ?? 'No owner yet',
                    meta: owner ? 'Sees this safety review through to closing.' : 'It can’t close without one.',
                    action:
                        open && d.can.change_owner ? (
                            <PaneLink icon={UserRoundCheck} onClick={h.onOwner}>
                                {owner ? 'Change owner' : 'Choose owner'}
                            </PaneLink>
                        ) : null,
                },
                ...(d.handover_summary.source_label
                    ? [{ key: 'from', label: 'From', value: d.handover_summary.source_label }]
                    : []),
                ...(d.handover.notes ? [{ key: 'notes', label: 'Handover notes', value: d.handover.notes }] : []),
            ],
            note: 'Changing the owner needs a reason. The old and new owners are told, and the change is kept in History.',
        };
    }

    if (step === 'investigated') {
        const inv = agreedInvestigation(d) ?? d.investigations[0] ?? null;
        const recs = inv?.recommendations ?? [];
        const items: RecordProgressPane['items'] = [];
        if (inv) {
            items.push({
                key: 'findings',
                label: 'Findings',
                value: inv.findings_summary || 'No findings written yet.',
                action:
                    inv.can_propose_correction ? (
                        <PaneLink icon={PencilLine} onClick={() => h.onCorrect(inv)}>
                            Edit
                        </PaneLink>
                    ) : null,
            });
            if (recs.length)
                items.push({
                    key: 'recommendations',
                    label: 'Recommendations',
                    value: (
                        <ol className="grid gap-1">
                            {recs.map((rec, index) => (
                                <li key={index}>
                                    {index + 1}. {rec.description}
                                    {rec.disposition?.corrective_action
                                        ? ` → action ${rec.disposition.corrective_action.reference_number}`
                                        : ''}
                                </li>
                            ))}
                        </ol>
                    ),
                    action: (
                        <PaneLink icon={ListChecks} onClick={() => h.onSection('actions')}>
                            Actions
                        </PaneLink>
                    ),
                });
        }
        items.push({
            key: 'worksafe',
            label: 'WorkSafe decision',
            value: d.worksafe.decision_signed
                ? d.worksafe.notifiable
                    ? `Notifiable.${d.worksafe.decision_reason ? ` ${d.worksafe.decision_reason}` : ''}`
                    : `Not a notifiable event.${d.worksafe.decision_reason ? ` ${d.worksafe.decision_reason}` : ''}`
                : 'Not recorded yet.',
            meta: d.worksafe.decided_by
                ? `Recorded by ${d.worksafe.decided_by.name}${d.worksafe.decided_at ? `, ${formatDate(d.worksafe.decided_at)}` : ''}`
                : undefined,
            action:
                open && d.worksafe.can_decide && d.worksafe.decision_signed ? (
                    <PaneLink icon={ShieldCheck} onClick={() => h.onPane({ kind: 'worksafe_decision' })}>
                        Edit
                    </PaneLink>
                ) : null,
        });
        return {
            ...base,
            title: 'Investigated',
            meta: inv ? invMeta(inv) : d.investigation_required ? 'No investigation started yet' : 'No investigation needed',
            items,
            children: inv?.correction ? (
                <div className="px-[18px] pb-3">
                    <PendingCorrection eventId={d.id} correction={inv.correction} />
                </div>
            ) : null,
            note: 'An edit to a done step is a correction with a reason. Findings go back through independent review and approval; the original stands until then and stays in History. The owner is told.',
        };
    }

    if (step === 'implemented') {
        const actions = d.corrective_actions;
        const done = actions.filter((a) => DONE.includes(a.status)).length;
        return {
            ...base,
            title: 'Implemented',
            meta: actions.length ? `${done} of ${actions.length} actions done` : 'No actions yet',
            items: actions.map((action) => ({
                key: `ca-${action.id}`,
                label: action.reference_number,
                value: action.title,
                meta: actionLine(action),
            })),
            empty: 'No actions have been added yet.',
        };
    }

    if (step === 'check') {
        const checked = d.corrective_actions.filter((a) => CHECKED.includes(a.status) || a.status === 'completed');
        return {
            ...base,
            title: 'Check it worked',
            meta: 'Each action is checked by someone who didn’t add it, own it, do it, or start this review.',
            items: checked.map((action) => ({
                key: `check-${action.id}`,
                label: action.reference_number,
                value: (
                    <span className="inline-flex flex-wrap items-center gap-2">
                        {action.title}
                        {CHECKED.includes(action.status) ? (
                            action.effectiveness_confirmed === false ? (
                                <StatusBadge variant="critical" size="sm">
                                    <XCircle className="size-3" aria-hidden="true" /> Checked — it didn’t work
                                </StatusBadge>
                            ) : (
                                <StatusBadge variant="success" size="sm">
                                    <ClipboardCheck className="size-3" aria-hidden="true" /> Checked — it worked
                                </StatusBadge>
                            )
                        ) : (
                            <StatusBadge variant="warning" size="sm">
                                Waiting for check
                            </StatusBadge>
                        )}
                    </span>
                ),
                meta: CHECKED.includes(action.status)
                    ? [
                          action.check_notes ? `Check result: ${action.check_notes}` : null,
                          action.verified_by_name
                              ? `Checked by ${action.verified_by_name}${action.verified_at ? ` · ${formatDate(action.verified_at)}` : ''}`
                              : null,
                      ]
                          .filter(Boolean)
                          .join(' · ')
                    : 'Done, waiting for someone else to check it.',
                action:
                    action.needs_revision && open && d.can.manage ? (
                        <PaneLink icon={Plus} onClick={() => h.onPane({ kind: 'ca_add', replacesActionId: action.id })}>
                            Add revised action
                        </PaneLink>
                    ) : null,
            })),
            empty: 'Nothing has been done yet, so nothing is waiting for a check.',
        };
    }

    return {
        ...base,
        title: 'Closed',
        meta: d.status === 'closed' ? `Closed${d.closed_at ? ` ${formatDate(d.closed_at)}` : ''}` : 'Not closed yet',
        items:
            d.status === 'closed'
                ? [
                      ...(d.closure_summary ? [{ key: 'learned', label: 'Closing notes', value: d.closure_summary }] : []),
                      {
                          key: 'before',
                          label: 'Before it could close',
                          value: `All ${d.close_readiness.requirements.length} done.`,
                      },
                      // Board M5c: the final record, downloadable from here and the header.
                      {
                          key: 'final',
                          label: 'Final record',
                          value: 'PDF · the record as it stood when it closed',
                          action: h.onPdf ? (
                              <PaneLink icon={FileDown} onClick={h.onPdf}>
                                  Download PDF
                              </PaneLink>
                          ) : null,
                      },
                  ]
                : d.close_readiness.requirements
                      .filter((r) => !r.complete)
                      .map((r) => ({ key: r.key, label: 'Still to do', value: r.label })),
        empty: 'Nothing blocks closing.',
    };
}

/** "1 thing left · 7 done" — one line that opens the closure list. */
export function ClosureReadinessLine({ d, children }: { d: EventDetail; children: ReactNode }) {
    const [openList, setOpenList] = useState(false);
    const requirements = d.close_gate.requirements;
    const done = requirements.filter((r) => r.complete).length;
    const left = requirements.length - done;
    const closedBy = d.status !== 'closed' && !d.can.close && d.close_authority ? ` · ${d.close_authority.text}` : '';
    return (
        <section className="sg-surface" aria-label="Closure readiness">
            {/* eslint-disable-next-line no-restricted-syntax -- the whole one-line readiness row opens its list (.sg-ready). */}
            <button type="button" className="sg-ready" aria-expanded={openList} onClick={() => setOpenList((v) => !v)}>
                <CheckCircle2 aria-hidden="true" />
                <div className="min-w-0">
                    <b>Closure readiness</b>
                    <span>
                        {d.status === 'closed'
                            ? `Nothing left · all ${requirements.length} done`
                            : left
                              ? `${left} ${left === 1 ? 'thing' : 'things'} left · ${done} done`
                              : `Nothing left · all ${done} done`}
                        {closedBy}
                    </span>
                </div>
                <ChevronDown aria-hidden="true" />
            </button>
            {openList ? <div className="border-t border-border">{children}</div> : null}
        </section>
    );
}
