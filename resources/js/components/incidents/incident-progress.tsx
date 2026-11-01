/**
 * The incident's round status steps and Progress card (board M14, approved by Stephan 1 Nov 2026, decision 26;
 * DESIGN.md "Records: status steps in the header, the Progress card under it").
 *
 * Steps: Reported › Assigned › Manager review › Closed. The step the incident is on is picked when the page opens;
 * picking another shows what was added there. The next-step bar holds the page's only filled button and opens the
 * same guarded dialogs the page already had; a viewer who can't do the step is told who does (no disabled buttons).
 * Everything here is read from the incident payload: nothing is invented for a step the page has no record of.
 */
import { Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, ChevronDown, ExternalLink, Plus, Send, UserRoundCheck } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import type { IncidentDetail, LifecycleAction } from '@/components/incidents/incident-detail-dialog';
import { incidentAssignLabel } from '@/components/incidents/incident-ownership-panel';
import type { PageHeaderStep } from '@/components/page/page-header-strip';
import { Checks, Surface, type CheckItem } from '@/components/signature/blocks';
import {
    PaneLink,
    type RecordProgressActivity,
    type RecordProgressItem,
    type RecordProgressNext,
    type RecordProgressPane,
} from '@/components/signature/record-progress';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDate, formatDateTime } from '@/lib/datetime';
import { hsLabel, hsVariant } from '@/lib/health-safety/hs-labels';

import { ownerNeeded, plural } from './incident-stages';

export type IncidentNoteField = 'what_happened' | 'done_straight_away';

/** A dated note beside the reporter's words (answer 20). Kept as written. */
export type IncidentReportNote = {
    id: number;
    field: IncidentNoteField | string;
    note: string;
    author_name: string;
    created_at: string | null;
};

export type IncidentProgressDetail = IncidentDetail & {
    notes?: IncidentReportNote[];
    can: IncidentDetail['can'] & { add_note?: boolean };
};

export type IncidentStepKey = 'reported' | 'assigned' | 'review' | 'closed';

export const INCIDENT_NOTE_FIELD_LABELS: Record<IncidentNoteField, string> = {
    what_happened: 'What happened',
    done_straight_away: 'What we did straight away',
};

const STEP_LABELS: Record<IncidentStepKey, string> = {
    reported: 'Reported',
    assigned: 'Assigned',
    review: 'Manager review',
    closed: 'Closed',
};

const GATE_LABELS: Record<string, string> = {
    incident_review: 'Finish the manager review',
    incident_followups: 'Finish all follow-ups',
    incident_investigation: 'Finish the investigation',
};

const CHECKED = ['verified', 'closed'];

function stepDone(d: IncidentProgressDetail): Record<IncidentStepKey, boolean> {
    const closed = d.status === 'closed';
    return {
        reported: d.status !== 'draft',
        assigned: d.status !== 'draft' && (closed || !ownerNeeded(d.ownership)),
        review: ['reviewed', 'closed'].includes(d.status),
        closed,
    };
}

const ORDER: IncidentStepKey[] = ['reported', 'assigned', 'review', 'closed'];

/** The step the incident is on: the first one not done (Closed once closed). */
export function currentIncidentStep(d: IncidentProgressDetail): IncidentStepKey {
    const done = stepDone(d);
    return ORDER.find((key) => !done[key]) ?? 'closed';
}

function hsOpen(d: IncidentProgressDetail): boolean {
    return Boolean(d.hs_event && d.hs_event.status !== 'closed');
}

function hsRestricted(d: IncidentProgressDetail): boolean {
    return !d.hs_event && d.linked_access?.health_safety === 'restricted';
}

function siteManager(d: IncidentProgressDetail): string {
    return d.client?.site ? `a manager at ${d.client.site}` : 'a manager';
}

export function incidentRoundSteps(d: IncidentProgressDetail): PageHeaderStep[] {
    const done = stepDone(d);
    const current = currentIncidentStep(d);
    const ownership = d.ownership;
    const needed = d.status !== 'draft' && d.status !== 'closed' ? ownerNeeded(ownership) : null;
    const owner = ownership?.owner?.name ?? (d.status === 'closed' ? ownership?.last_owner_name : null) ?? null;
    const latestOwnerChange = ownership?.history?.[0];
    const meta: Record<IncidentStepKey, string | undefined> = {
        reported: d.status === 'draft' ? 'Not submitted yet' : d.submitted_at ? formatDateTime(d.submitted_at) : 'Submitted',
        assigned: owner
            ? [owner, latestOwnerChange ? formatDate(latestOwnerChange.changed_at) : null].filter(Boolean).join(' · ')
            : needed === 'replacement_needed'
              ? 'Owner needs replacing'
              : needed
                ? 'Needs an owner'
                : done.assigned
                  ? undefined
                  : 'Not yet',
        review: done.review ? (d.reviewed_at ? formatDateTime(d.reviewed_at) : 'Done') : 'Not started',
        closed: done.closed
            ? d.closed_at
                ? formatDateTime(d.closed_at)
                : 'Closed'
            : hsOpen(d)
              ? 'Waiting for the safety review'
              : d.status === 'reviewed' && d.close_gate.allowed
                ? 'Ready to close'
                : 'Not yet',
    };
    return ORDER.map((key): PageHeaderStep => ({
        key,
        label: STEP_LABELS[key],
        meta: meta[key],
        state: done[key] ? 'done' : key === current ? 'now' : 'todo',
    }));
}

/* ------------------------------------------------------------------ */
/*  Next step bar                                                      */
/* ------------------------------------------------------------------ */

export type IncidentNextHandlers = {
    onRecord: (action: LifecycleAction) => void;
    onAssign: () => void;
    responseActive: boolean;
    responseHref: string | null | undefined;
};

export type IncidentNextBar = RecordProgressNext & {
    /** What the bar's button does, so the header doesn't offer it a second time. */
    owns: LifecycleAction | 'assign' | 'response' | null;
};

function afterReview(d: IncidentProgressDetail): string {
    if (hsOpen(d)) return 'The incident closes after that, once its safety review has closed.';
    if (hsRestricted(d)) return 'The incident closes after that, once its linked safety work is finished.';
    return 'The incident can close after that.';
}

export function incidentNextBar(d: IncidentProgressDetail, h: IncidentNextHandlers): IncidentNextBar {
    const button = (label: string, icon: ReactNode, run: () => void) => (
        <Button type="button" onClick={run}>
            {icon}
            {label}
        </Button>
    );
    if (d.journey_state === 'Journey repair required') {
        return {
            owns: null,
            title: 'The linked incident journey needs repair',
            text: 'Repair the linked incident journey before closing this incident.',
        };
    }
    if (h.responseActive && d.status !== 'closed') {
        return {
            owns: h.responseHref ? 'response' : null,
            title: 'The Control Room response is still active',
            text: `The operational response is still active. Response and incident review are separate decisions.${h.responseHref ? '' : ' A permitted Control Room responder must continue the response.'}`,
            action: h.responseHref ? (
                <Button asChild>
                    <Link href={h.responseHref}>Open response</Link>
                </Button>
            ) : undefined,
        };
    }
    if (d.status === 'closed') {
        return {
            owns: null,
            kicker: 'Closed',
            tone: 'done',
            title: `Closed${d.closed_at ? ` ${formatDateTime(d.closed_at)}` : ''}${d.closed_outcome ? ` · ${d.closed_outcome}` : ''}`,
            text:
                d.journey_state === 'Journey closed'
                    ? 'The connected journey is closed.'
                    : 'The incident report is closed. Linked response or safety work still has its own completion requirements.',
        };
    }
    if (d.status === 'draft') {
        return d.can.submit
            ? {
                  owns: 'submit',
                  title: 'Submit the report for review',
                  text: 'Once it’s submitted the report is kept as written, and a manager reviews it.',
                  action: button('Submit for review', <Send className="size-4" aria-hidden="true" />, () => h.onRecord('submit')),
              }
            : {
                  owns: null,
                  title: 'The report is still a draft',
                  text: `${d.reporter?.name ?? 'The person who reported it'} finishes it and submits it for review.`,
              };
    }
    const needed = ownerNeeded(d.ownership);
    if (needed) {
        const canAssign = Boolean(d.ownership?.can_assign);
        return {
            owns: canAssign ? 'assign' : null,
            title: needed === 'unassigned' ? 'Choose who follows this incident through' : 'Choose a new owner',
            text: canAssign
                ? needed === 'unassigned'
                    ? 'The incident needs an owner who sees it through to closing.'
                    : `Previous owner: ${d.ownership?.owner?.name ?? d.ownership?.last_owner_name ?? 'not recorded'}. Their access no longer covers this incident.`
                : `Waiting for ${siteManager(d)} to choose an owner.`,
            action: canAssign
                ? button(incidentAssignLabel(d.ownership!), <UserRoundCheck className="size-4" aria-hidden="true" />, h.onAssign)
                : undefined,
        };
    }
    if (d.status === 'submitted') {
        return {
            owns: d.can.review ? 'review' : null,
            title: 'Complete the manager review',
            text: d.can.review ? afterReview(d) : `${capitalise(siteManager(d))} completes it. ${afterReview(d)}`,
            action: d.can.review
                ? button('Complete manager review', <CheckCircle2 className="size-4" aria-hidden="true" />, () => h.onRecord('review'))
                : undefined,
        };
    }
    // Reviewed: the incident closes once everything it needs is done.
    const left = d.close_gate.requirements.filter((item) => !item.complete).length;
    if (!d.close_gate.allowed) {
        return {
            owns: null,
            title: hsOpen(d) ? 'Waiting for the safety review to close' : 'Finish what’s left before it closes',
            text: `${plural(left, 'thing')} left before the incident can close. Closure readiness lists them.`,
        };
    }
    return {
        owns: d.can.close ? 'close' : null,
        title: 'Close the incident',
        text: d.can.close ? 'Everything needed to close is done.' : `Everything needed to close is done. ${capitalise(siteManager(d))} closes it.`,
        action: d.can.close
            ? button('Close incident', <CheckCircle2 className="size-4" aria-hidden="true" />, () => h.onRecord('close'))
            : undefined,
    };
}

function capitalise(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

/* ------------------------------------------------------------------ */
/*  The picked step's pane                                             */
/* ------------------------------------------------------------------ */

export type IncidentPaneHandlers = {
    onBack?: () => void;
    onHistory: () => void;
    onSection: (section: 'care' | 'evidence' | 'followups') => void;
    onAssign: () => void;
    onAddNote: (field: IncidentNoteField) => void;
};

/** The reporter's words with the dated notes added beside them ("<author> · <date>: <note>"). */
function WordsWithNotes({ words, notes, label }: { words: string; notes: IncidentReportNote[]; label: string }) {
    return (
        <>
            <span className="whitespace-pre-line">{words}</span>
            {notes.length ? (
                <ul aria-label={`Notes beside ${label}`} className="mt-2 grid gap-1.5">
                    {notes.map((note) => (
                        <li key={note.id} className="text-caption">
                            <span className="font-medium text-foreground">
                                {note.author_name}
                                {note.created_at ? ` · ${formatDateTime(note.created_at)}` : ''}:
                            </span>{' '}
                            <span className="whitespace-pre-line">{note.note}</span>
                        </li>
                    ))}
                </ul>
            ) : null}
        </>
    );
}

function byNewest(items: (RecordProgressActivity & { at: string })[]): RecordProgressActivity[] {
    return items
        .sort((a, b) => new Date(b.at).getTime() - new Date(a.at).getTime())
        .map(({ at: _at, ...item }) => item);
}

function activity(at: string | null | undefined, item: Omit<RecordProgressActivity, 'time'>) {
    return at ? [{ ...item, at, time: formatDateTime(at) }] : [];
}

export function incidentStepPane(d: IncidentProgressDetail, step: IncidentStepKey, h: IncidentPaneHandlers): RecordProgressPane {
    const reporter = d.reporter?.name ?? null;
    const notes = d.notes ?? [];
    const base = { onBack: h.onBack };
    const log = (items: (RecordProgressActivity & { at: string })[]) => ({
        items: byNewest(items),
        state: 'ready' as const,
        onAll: h.onHistory,
    });

    if (step === 'reported') {
        const draft = d.status === 'draft';
        const canNote = Boolean(d.can.add_note) && !draft;
        const noteRow = (field: IncidentNoteField, words: string | null): RecordProgressItem => {
            const label = INCIDENT_NOTE_FIELD_LABELS[field];
            return {
                key: field,
                label,
                value: (
                    <WordsWithNotes
                        words={words || 'Not recorded'}
                        notes={notes.filter((note) => note.field === field)}
                        label={label}
                    />
                ),
                action: canNote ? (
                    <PaneLink icon={Plus} onClick={() => h.onAddNote(field)}>
                        Add a note
                    </PaneLink>
                ) : null,
            };
        };
        const firstAid = d.first_aid_records ?? [];
        const restraints = d.restraint_events ?? [];
        const hidden = d.hidden_health_records ?? [];
        const injuries = firstAid.map((record) => record.injury).filter(Boolean);
        const healthLinked = firstAid.length + restraints.length + hidden.length;
        const items: RecordProgressItem[] = [
            noteRow('what_happened', d.description),
            noteRow('done_straight_away', d.immediate_action_taken),
            ...(d.witnesses ? [{ key: 'witnesses', label: 'Witnesses', value: d.witnesses }] : []),
            ...(d.type === 'near_miss' && d.potential_consequence
                ? [{ key: 'could', label: 'Could have caused', value: d.potential_consequence }]
                : []),
            ...(healthLinked
                ? [
                      {
                          key: 'injury',
                          label: 'Injury',
                          value: injuries.length
                              ? injuries.join('; ')
                              : plural(healthLinked, 'linked health record'),
                          action: (
                              <PaneLink onClick={() => h.onSection('care')}>
                                  Injury and first aid
                                  <ArrowRight aria-hidden="true" />
                              </PaneLink>
                          ),
                      },
                  ]
                : []),
            {
                key: 'files',
                label: 'Files',
                value: d.attachments.length ? plural(d.attachments.length, 'file') : 'No files',
            },
        ];
        const files = d.attachments.length;
        return {
            ...base,
            title: 'Reported',
            meta: draft
                ? 'Not submitted yet'
                : [reporter ? `By ${reporter}` : null, d.submitted_at ? formatDateTime(d.submitted_at) : null]
                      .filter(Boolean)
                      .join(' · ') || undefined,
            items,
            activity: log([
                ...activity(d.submitted_at, {
                    key: 'submitted',
                    who: reporter ?? undefined,
                    what: `reported the incident${files ? ` with ${plural(files, 'file')}` : ''}`,
                }),
                ...firstAid.flatMap((record) =>
                    activity(record.treatment_date, {
                        key: `fa-${record.id}`,
                        what: `First aid recorded${record.injury ? `: ${record.injury}` : ''}`,
                    }),
                ),
                ...notes.flatMap((note) =>
                    activity(note.created_at, {
                        key: `note-${note.id}`,
                        who: note.author_name,
                        what: `added a note beside “${INCIDENT_NOTE_FIELD_LABELS[note.field as IncidentNoteField] ?? 'the report'}”`,
                    }),
                ),
            ]),
            note: draft
                ? undefined
                : `A submitted report can’t be changed. A dated note is added beside the reporter’s words, and ${reporter ?? 'the reporter'} is told.`,
        };
    }

    if (step === 'assigned') {
        const ownership = d.ownership;
        const needed = d.status !== 'draft' && d.status !== 'closed' ? ownerNeeded(ownership) : null;
        const owner = ownership?.owner?.name ?? ownership?.last_owner_name ?? null;
        const latest = ownership?.history?.[0];
        const open = d.status !== 'draft' && d.status !== 'closed';
        return {
            ...base,
            title: 'Assigned',
            meta: needed === 'replacement_needed' ? 'Owner needs replacing' : needed ? 'Needs an owner' : owner ? `Incident owner ${owner}` : undefined,
            items: ownership
                ? [
                      {
                          key: 'owner',
                          label: 'Incident owner',
                          value: needed === 'unassigned' ? 'No owner yet' : (owner ?? 'Not recorded'),
                          meta: latest
                              ? `Chosen by ${latest.changed_by} · ${formatDateTime(latest.changed_at)}${latest.reason ? ` · ${latest.reason}` : ''}`
                              : undefined,
                          action:
                              open && ownership.can_assign ? (
                                  <PaneLink icon={UserRoundCheck} onClick={h.onAssign}>
                                      {incidentAssignLabel(ownership)}
                                  </PaneLink>
                              ) : null,
                      },
                  ]
                : [],
            empty: 'No owner has been chosen yet.',
            activity: log(
                (ownership?.history ?? []).flatMap((change) =>
                    activity(change.changed_at, {
                        key: `owner-${change.id}`,
                        who: change.changed_by,
                        what: change.previous_owner
                            ? `changed the owner from ${change.previous_owner} to ${change.owner}`
                            : `made ${change.owner} the owner`,
                    }),
                ),
            ),
        };
    }

    if (step === 'review') {
        const reviewed = ['reviewed', 'closed'].includes(d.status) || Boolean(d.reviewed_at);
        return {
            ...base,
            title: 'Manager review',
            meta: reviewed ? (d.reviewed_at ? `Reviewed ${formatDateTime(d.reviewed_at)}` : 'Reviewed') : 'Not started',
            items: reviewed
                ? [{ key: 'review_notes', label: 'Review notes', value: d.review_notes || 'No notes written' }]
                : [],
            empty: 'The manager review hasn’t been done yet.',
            activity: log(activity(d.reviewed_at, { key: 'reviewed', what: 'Manager review completed' })),
        };
    }

    const closed = d.status === 'closed';
    const left = d.close_gate.requirements.filter((item) => !item.complete);
    return {
        ...base,
        title: 'Closed',
        meta: closed ? `Closed${d.closed_at ? ` ${formatDateTime(d.closed_at)}` : ''}` : 'Not closed yet',
        items: closed
            ? [
                  ...(d.closed_outcome ? [{ key: 'outcome', label: 'Outcome', value: d.closed_outcome }] : []),
                  ...(d.closed_notes ? [{ key: 'closed_notes', label: 'Closing notes', value: d.closed_notes }] : []),
              ]
            : [
                  ...(d.reopened_at
                      ? [
                            {
                                key: 'reopened',
                                label: 'Reopened',
                                value: d.reopened_reason || 'No reason recorded',
                                meta: formatDateTime(d.reopened_at),
                            },
                        ]
                      : []),
                  ...left.map((item) => ({ key: item.key, label: 'Still to do', value: requirementLabel(d, item) })),
              ],
        empty: closed ? 'Closed with no outcome or notes written.' : 'Nothing blocks closing.',
        activity: log([
            ...activity(d.reopened_at, { key: 'reopened', what: `Reopened${d.reopened_reason ? `: ${d.reopened_reason}` : ''}` }),
            ...activity(d.closed_at, { key: 'closed', what: `Closed${d.closed_outcome ? `: ${d.closed_outcome}` : ''}` }),
        ]),
    };
}

/* ------------------------------------------------------------------ */
/*  Side column: closure readiness and the linked safety review        */
/* ------------------------------------------------------------------ */

type Requirement = IncidentDetail['close_gate']['requirements'][number];

function requirementLabel(d: IncidentDetail, item: Requirement): ReactNode {
    const label = item.complete
        ? item.label
        : item.key === 'health_safety_governance' && item.href && d.hs_event
          ? `Close safety review ${d.hs_event.reference_number}`
          : (GATE_LABELS[item.key] ?? item.label);
    return !item.complete && item.href ? (
        <Link
            href={item.href}
            aria-label={label}
            className="rounded-sm font-medium text-foreground underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            {label}
        </Link>
    ) : (
        label
    );
}

/** "2 things left · 1 done · then you or another manager at Kauri House close it" — one line that opens the list. */
export function IncidentClosureReadiness({ d }: { d: IncidentProgressDetail }) {
    const [open, setOpen] = useState(false);
    const requirements = d.close_gate.requirements;
    const done = requirements.filter((item) => item.complete).length;
    const left = requirements.length - done;
    const site = d.client?.site;
    const closer = d.can.close
        ? `you or another manager${site ? ` at ${site}` : ''} close it`
        : `${siteManager(d)} closes it`;
    const summary = left
        ? `${plural(left, 'thing')} left · ${done} done · then ${closer}`
        : `Nothing left · all ${done} done · ${d.can.close ? `you or another manager${site ? ` at ${site}` : ''} can close it` : `${siteManager(d)} closes it`}`;
    const checks: CheckItem[] = requirements.map((item): CheckItem => ({
        state: item.complete ? 'success' : 'warning',
        label: requirementLabel(d, item),
    }));
    return (
        <section aria-label="Closure readiness" className="sg-surface min-w-0">
            {/* eslint-disable-next-line no-restricted-syntax -- the whole one-line readiness row opens its list (.sg-ready, shared with the safety review). */}
            <button type="button" className="sg-ready" aria-expanded={open} onClick={() => setOpen((value) => !value)}>
                <CheckCircle2 aria-hidden="true" />
                <div className="min-w-0">
                    <b>Closure readiness</b>
                    <span>{summary}</span>
                </div>
                <ChevronDown aria-hidden="true" />
            </button>
            {open ? (
                <div className="border-t border-border pt-2">
                    <Checks items={checks} single />
                </div>
            ) : null}
        </section>
    );
}

/** Shown only to a viewer who can open the safety review (the payload carries it only then). */
export function LinkedSafetyReviewCard({ d }: { d: IncidentProgressDetail }) {
    const ev = d.hs_event;
    if (!ev || !ev.url || d.linked_access?.health_safety === 'restricted') return null;
    const total = ev.corrective_actions.length;
    const checked = ev.corrective_actions.filter((item) => CHECKED.includes(item.status)).length;
    const owner = ev.handover.owner?.name;
    return (
        <Surface title="Linked safety review" aria-label="Linked safety review">
            <div className="grid gap-3 px-[18px] pt-2 pb-4">
                <p className="text-caption">
                    {[ev.reference_number, d.client?.site, owner ? `Owner ${owner}` : 'No owner yet'].filter(Boolean).join(' · ')}
                </p>
                <div className="flex flex-wrap items-center gap-3">
                    <StatusBadge variant={hsVariant('review_status', ev.status)}>{hsLabel('review_status', ev.status)}</StatusBadge>
                    <span className="text-caption">
                        {total ? `${checked} of ${plural(total, 'action')} checked` : 'No actions yet'}
                    </span>
                </div>
                <div>
                    <Button asChild variant="outline">
                        <Link href={ev.url}>
                            <ExternalLink className="size-4" aria-hidden="true" />
                            Open safety review
                        </Link>
                    </Button>
                </div>
            </div>
        </Surface>
    );
}
