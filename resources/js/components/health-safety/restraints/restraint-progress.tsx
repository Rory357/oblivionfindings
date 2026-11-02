/**
 * The restraint record's round status steps and Progress card (board G4-5, approved by Stephan 1 Nov 2026; DESIGN.md
 * "Records: status steps in the header, the Progress card under it").
 *
 * The header carries only the restraint's own track: Recorded › Restraint review owner › Monitoring › Debrief ›
 * Restraint review. "Restraint review" always means the restraint's own step; "safety review" means only the Health and
 * safety record, which is a card in the body. The next-step bar holds the record's only filled button, and only when the
 * server lets this viewer do that step (eventCommandAllowed); otherwise it says who does. Nothing here is invented: every
 * pane reads the restraint payload.
 */
import type { PageHeaderStep } from '@/components/page/page-header-strip';
import {
    PaneLink,
    type RecordProgressActivity,
    type RecordProgressItem,
    type RecordProgressNext,
    type RecordProgressPane,
} from '@/components/signature/record-progress';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime, formatTime } from '@/lib/datetime';
import type { RestraintEventCommand } from '@/lib/health-safety/restraint-navigation';
import { ClipboardCheck, MessageCircle, Pencil, UserCog } from 'lucide-react';
import type { ReactNode } from 'react';

import { EVENT_COMMAND_LABELS, eventCommandAllowed } from './event-command-form';
import type { RestraintEventDetail, RestraintEventRecord, RestraintFollowup, SourceFact, SourcePerson } from './types';

export type RestraintStepKey = 'recorded' | 'owner' | 'monitoring' | 'debrief' | 'review';
export const RESTRAINT_STEP_ORDER: RestraintStepKey[] = ['recorded', 'owner', 'monitoring', 'debrief', 'review'];

const STEP_LABELS: Record<RestraintStepKey, string> = {
    recorded: 'Recorded',
    owner: 'Restraint review owner',
    monitoring: 'Monitoring',
    debrief: 'Debrief',
    review: 'Restraint review',
};

type Run = (command: RestraintEventCommand, child?: number) => void;

const plural = (n: number, one: string, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;
const join = (...parts: (ReactNode | null | undefined | false)[]) =>
    parts.filter((part) => part !== null && part !== undefined && part !== false && part !== '').join(' · ');
const named = (person: SourcePerson | null | undefined) => person?.name ?? null;

/** The entries still in force: a correction replaces the entry it corrects. */
export function currentFacts(rows: SourceFact[]): SourceFact[] {
    return rows.filter((row) => !rows.some((other) => other.supersedes_id === row.id));
}

const factText = (fact: SourceFact | undefined, key: string): string | null => {
    const value = fact?.facts[key];
    if (typeof value === 'string' && value.trim()) return value;
    if (Array.isArray(value) && value.length) return value.filter((item) => typeof item === 'string').join(', ');
    return null;
};
const when = (fact: SourceFact) => fact.occurred_at ?? fact.created_at;
const ownershipFacts = (d: RestraintEventRecord) => d.reviews.filter((row) => row.facts.kind === 'ownership');
const reviewFacts = (d: RestraintEventRecord) => d.reviews.filter((row) => row.facts.kind !== 'ownership' && row.facts.kind !== 'correction');

/** The step the restraint is on: owner → debrief → restraint review (all done once the review is recorded). */
export function currentRestraintStep(d: RestraintEventRecord): RestraintStepKey {
    if (!d.needs_review) return 'review';
    if (!d.review_owner_id) return 'owner';
    if (!currentFacts(d.debriefs).length) return 'debrief';
    return 'review';
}

export function restraintRoundSteps(d: RestraintEventRecord): PageHeaderStep[] {
    const checks = currentFacts(d.observations);
    const debrief = currentFacts(d.debriefs).at(-1);
    const lastCheck = checks.at(-1);
    const owner = named(d.review_owner_id);
    const current = currentRestraintStep(d);
    const reviewed = !d.needs_review;
    const done: Record<RestraintStepKey, boolean> = {
        recorded: true,
        owner: Boolean(owner) || reviewed,
        monitoring: checks.length > 0,
        debrief: Boolean(debrief),
        review: reviewed,
    };
    const meta: Record<RestraintStepKey, ReactNode> = {
        recorded: join(named(d.created_by), formatDate(d.started_at, '')) || undefined,
        owner: owner ?? (reviewed ? 'Not recorded' : 'No owner yet'),
        monitoring: checks.length ? join(plural(checks.length, 'check'), lastCheck ? `last ${formatDate(when(lastCheck), '')}` : null) : 'No checks recorded',
        debrief: debrief ? formatDate(when(debrief), 'Recorded') : 'Not recorded yet',
        review: reviewed
            ? d.reviewed_at ? formatDate(d.reviewed_at) : 'Recorded'
            : d.review_due_at ? `Due ${formatDate(d.review_due_at)}` : 'Due time not set',
    };
    return RESTRAINT_STEP_ORDER.map((key) => ({
        key,
        label: STEP_LABELS[key],
        meta: meta[key],
        state: done[key] && !(key === current && !reviewed) ? 'done' : key === current && !reviewed ? 'now' : 'todo',
    }));
}

/* ------------------------------------------------------------------ */
/*  Next step bar                                                      */
/* ------------------------------------------------------------------ */

export type RestraintNextBar = RecordProgressNext & { owns: RestraintEventCommand | null };

const due = (d: RestraintEventRecord) =>
    d.review_due_at ? `restraint review due ${formatDate(d.review_due_at)}, ${formatTime(d.review_due_at)}` : 'no due time set for the restraint review';

export function restraintNextBar(detail: RestraintEventDetail, viewerId: number | null | undefined, run: Run): RestraintNextBar {
    const d = detail.event;
    const allowed = (command: RestraintEventCommand) => eventCommandAllowed(detail, command);
    const button = (command: RestraintEventCommand, icon: ReactNode) => (
        <Button type="button" onClick={() => run(command)}>
            {icon}
            {EVENT_COMMAND_LABELS[command]}
        </Button>
    );
    const site = d.site?.name;
    if (!d.needs_review) {
        return {
            owns: null,
            kicker: 'Finished',
            tone: 'done',
            title: `Restraint review recorded${d.reviewed_at ? ` ${formatDateTime(d.reviewed_at)}` : ''}`,
            text: detail.safety_review ? 'The restraint is finished. Its safety review closes separately.' : 'The restraint is finished.',
        };
    }
    const owner = d.review_owner_id;
    if (!owner) {
        return allowed('assign-review')
            ? {
                  owns: 'assign-review',
                  title: 'Choose who owns the restraint review',
                  text: `The owner records the debrief and the restraint review — ${due(d)}.`,
                  action: button('assign-review', <UserCog className="size-4" aria-hidden="true" />),
              }
            : {
                  owns: null,
                  title: 'Waiting for a restraint review owner',
                  text: `Someone who reviews restraints${site ? ` at ${site}` : ''} chooses who owns it.`,
              };
    }
    const you = viewerId != null && owner.id === viewerId;
    const who = you ? 'You' : (owner.name ?? 'The restraint review owner');
    if (!currentFacts(d.debriefs).length) {
        return {
            owns: allowed('record-debrief') ? 'record-debrief' : null,
            title: `${who} ${you ? 'record' : 'records'} the debrief, then the restraint review`,
            text: due(d),
            action: allowed('record-debrief') ? button('record-debrief', <MessageCircle className="size-4" aria-hidden="true" />) : undefined,
        };
    }
    return {
        owns: allowed('review') ? 'review' : null,
        title: `${who} ${you ? 'record' : 'records'} the restraint review`,
        text: d.reviewed_at ? `${due(d)} · it changed since the last restraint review` : due(d),
        action: allowed('review') ? button('review', <ClipboardCheck className="size-4" aria-hidden="true" />) : undefined,
    };
}

/* ------------------------------------------------------------------ */
/*  The picked step's pane                                             */
/* ------------------------------------------------------------------ */

export type RestraintPaneHandlers = {
    onBack?: () => void;
    onHistory: () => void;
    run: Run;
};

type Dated = RecordProgressActivity & { at: string };

function log(items: Dated[], onAll: () => void): RecordProgressPane['activity'] {
    return {
        items: items
            .sort((a, b) => new Date(b.at).getTime() - new Date(a.at).getTime())
            .map(({ at: _at, ...item }) => item),
        state: 'ready',
        onAll,
    };
}

function entry(at: string | null | undefined, item: Omit<RecordProgressActivity, 'time'>): Dated[] {
    return at ? [{ ...item, at, time: formatDateTime(at) }] : [];
}

function checkEntries(d: RestraintEventRecord): Dated[] {
    return d.observations.flatMap((row, index) =>
        entry(when(row), {
            key: `check-${row.id}`,
            who: named(row.recorder) ?? undefined,
            what: row.supersedes_id ? 'corrected a monitoring check' : `recorded monitoring check ${index + 1}`,
            edit: Boolean(row.supersedes_id),
        }),
    );
}

function recordedEntry(d: RestraintEventRecord): Dated[] {
    return entry(d.created_at ?? d.started_at, { key: 'recorded', who: named(d.created_by) ?? undefined, what: 'recorded the restraint' });
}

/** "Family/whānau told (Mere Tane, done) · talk to the practitioner (yours, due Fri 6 Nov)". */
export function followupSummary(rows: RestraintFollowup[], viewerId: number | null | undefined): string {
    const live = rows.filter((row) => !row.cancelled_at);
    if (!live.length) return 'None';
    return live
        .map((row) => {
            const owner = row.assignee?.id != null && row.assignee.id === viewerId ? 'yours' : (row.assignee?.name ?? 'no owner');
            const state = row.completed_at ? 'done' : row.due_at ? `due ${formatDate(row.due_at)}` : 'open';
            return `${row.notes} (${owner}, ${state})`;
        })
        .join(' · ');
}

function alsoYou(detail: RestraintEventDetail, viewerId: number | null | undefined): string | undefined {
    const d = detail.event;
    const owner = d.review_owner_id;
    if (!d.needs_review || !owner?.name || owner.id === viewerId || !eventCommandAllowed(detail, 'review')) return undefined;
    return `You review restraints${d.site ? ` at ${d.site.name}` : ''} too, so you can record the debrief or the restraint review as well as ${owner.name}.`;
}

export function restraintStepPane(
    detail: RestraintEventDetail,
    step: RestraintStepKey,
    viewerId: number | null | undefined,
    h: RestraintPaneHandlers,
): RecordProgressPane {
    const d = detail.event;
    const base = { onBack: h.onBack };
    const allowed = (command: RestraintEventCommand, child?: number) => eventCommandAllowed(detail, command, child);
    const files = d.attachments.filter((file) => !file.removed_at).length;
    const owner = d.review_owner_id;

    if (step === 'recorded') {
        const staff = d.staff_involved.map((item) => ('kind' in item ? item.text : item.name)).filter(Boolean).join(', ');
        return {
            ...base,
            title: 'Recorded',
            meta: join(named(d.created_by) ? `By ${named(d.created_by)}` : null, formatDateTime(d.created_at ?? d.started_at, '')) || undefined,
            items: [
                { key: 'when', label: 'When', value: `${formatDateTime(d.started_at)}${d.ended_at ? ` – ${formatTime(d.ended_at)}` : ''}` },
                { key: 'staff', label: 'Staff involved', value: staff || 'Not recorded' },
                // The record keeps only who authorised it, not how or when (G4-5).
                { key: 'authorised', label: 'Authorised by', value: named(d.authorised_by) ?? 'Not recorded' },
                { key: 'files', label: 'Files', value: files ? plural(files, 'file') : 'No files' },
            ],
            activity: log(recordedEntry(d), h.onHistory),
            note: allowed('correct') ? 'A correction keeps what was first recorded and needs a reason.' : undefined,
        };
    }

    if (step === 'owner') {
        const latest = ownershipFacts(d).at(-1);
        return {
            ...base,
            title: 'Restraint review owner',
            meta: owner?.name ? `${owner.name} owns the restraint review` : 'No owner yet',
            items: [
                {
                    key: 'owner',
                    label: 'Restraint review owner',
                    value: owner?.name ?? (owner ? 'Name hidden' : 'No owner yet'),
                    meta: join(
                        d.review_due_at ? `Restraint review due ${formatDateTime(d.review_due_at)}` : null,
                        latest ? `Chosen by ${named(latest.recorder) ?? 'someone'}` : null,
                        factText(latest, 'reason'),
                    ) || undefined,
                    action:
                        d.needs_review && allowed('assign-review') ? (
                            <PaneLink icon={UserCog} onClick={() => h.run('assign-review')}>
                                {owner ? 'Change owner' : 'Choose owner'}
                            </PaneLink>
                        ) : null,
                },
            ],
            activity: log(
                ownershipFacts(d).flatMap((row) => {
                    const chosen = row.facts.owner_id as SourcePerson | null | undefined;
                    return entry(row.created_at, {
                        key: `owner-${row.id}`,
                        who: named(row.recorder) ?? undefined,
                        what: chosen?.name ? `made ${chosen.name} the restraint review owner` : 'took the restraint review owner off',
                    });
                }),
                h.onHistory,
            ),
        };
    }

    if (step === 'monitoring') {
        const checks = currentFacts(d.observations);
        const items: RecordProgressItem[] = checks.map((row, index) => ({
            key: `check-${row.id}`,
            label: `Check ${index + 1}`,
            value: join(factText(row, 'condition'), factText(row, 'response')) || 'No details written',
            meta: join(named(row.recorder) ? `By ${named(row.recorder)}` : null, formatDateTime(when(row), '')) || undefined,
            action: allowed('correct-observation', row.id) ? (
                <PaneLink icon={Pencil} onClick={() => h.run('correct-observation', row.id)}>
                    Edit
                </PaneLink>
            ) : null,
        }));
        return {
            ...base,
            title: 'Monitoring',
            meta: checks.length ? join(plural(checks.length, 'check'), `last ${formatDate(when(checks.at(-1)!), '')}`) : 'No checks recorded',
            items,
            empty: 'No monitoring checks recorded.',
            activity: log(checkEntries(d), h.onHistory),
            note: checks.length ? 'A check is corrected with a reason; the first entry stays in History.' : undefined,
        };
    }

    if (step === 'debrief') {
        const debrief = currentFacts(d.debriefs).at(-1);
        const checks = currentFacts(d.observations);
        const lastCheck = checks.at(-1);
        const debriefEntries = d.debriefs.flatMap((row) =>
            entry(when(row), {
                key: `debrief-${row.id}`,
                who: named(row.recorder) ?? undefined,
                what: row.supersedes_id ? 'corrected the debrief' : 'recorded the debrief',
                edit: Boolean(row.supersedes_id),
            }),
        );
        const items: RecordProgressItem[] = debrief
            ? [
                  ...(
                      [
                          ['participants', 'Who took part', factText(debrief, 'actual_participants')],
                          ['voice', 'The person’s view', factText(debrief, 'person_voice')],
                          ['contact', 'Family/whānau contact', factText(debrief, 'contact_outcome')],
                          ['learning', 'What we learned', factText(debrief, 'learning')],
                      ] as const
                  ).map(([key, label, value]) => ({ key, label, value: value ?? 'Not recorded' })),
                  {
                      key: 'recorded',
                      label: 'Recorded',
                      value: join(named(debrief.recorder), formatDateTime(when(debrief), '')) || 'Not recorded',
                      action: allowed('correct-debrief', debrief.id) ? (
                          <PaneLink icon={Pencil} onClick={() => h.run('correct-debrief', debrief.id)}>
                              Edit
                          </PaneLink>
                      ) : null,
                  },
              ]
            : [
                  {
                      key: 'monitoring',
                      label: 'Monitoring before it',
                      value: checks.length
                          ? join(plural(checks.length, 'check'), lastCheck ? `last ${formatDateTime(when(lastCheck), '')}${named(lastCheck.recorder) ? `, by ${named(lastCheck.recorder)}` : ''}` : null)
                          : 'No checks recorded',
                  },
                  { key: 'followups', label: 'Follow-ups', value: followupSummary(d.followups, viewerId) },
                  { key: 'files', label: 'Files', value: files ? String(files) : 'No files' },
              ];
        return {
            ...base,
            title: 'Debrief',
            meta: debrief
                ? `Recorded ${formatDate(when(debrief), '')}`
                : join('Not recorded yet', owner?.name ? `${owner.id === viewerId ? 'You own' : `${owner.name} owns`} the restraint review` : null),
            items,
            activity: log(debrief ? debriefEntries : [...debriefEntries, ...checkEntries(d), ...recordedEntry(d)], h.onHistory),
            note: alsoYou(detail, viewerId),
        };
    }

    const reviews = reviewFacts(d);
    const reviewed = !d.needs_review || Boolean(d.reviewed_at);
    return {
        ...base,
        title: 'Restraint review',
        meta: !d.needs_review
            ? `Recorded ${d.reviewed_at ? formatDateTime(d.reviewed_at) : ''}`.trim()
            : d.reviewed_at
              ? 'Changed since the last restraint review'
              : d.review_due_at
                ? `Due ${formatDateTime(d.review_due_at)}`
                : 'Not recorded yet',
        items: reviewed
            ? [
                  { key: 'by', label: 'Recorded by', value: named(d.reviewed_by) ?? 'Not recorded', meta: d.reviewed_at ? formatDateTime(d.reviewed_at) : undefined },
                  { key: 'notes', label: 'Review notes', value: d.review_notes || 'No notes written' },
                  { key: 'lessons', label: 'Lessons learned', value: d.lessons_learned || 'Not recorded' },
                  {
                      key: 'version',
                      label: 'Version reviewed',
                      value:
                          d.reviewed_source_revision === null
                              ? 'Not reviewed yet'
                              : `Version ${d.reviewed_source_revision}${d.reviewed_source_revision !== d.source_revision ? ` (latest is ${d.source_revision})` : ''}`,
                  },
              ]
            : [],
        empty: 'The restraint review hasn’t been recorded yet.',
        activity: log(
            reviews.flatMap((row) =>
                entry(factText(row, 'reviewed_at') ?? row.created_at, {
                    key: `review-${row.id}`,
                    who: named(row.recorder) ?? undefined,
                    what: row.supersedes_id ? 'recorded the restraint review again' : 'recorded the restraint review',
                }),
            ),
            h.onHistory,
        ),
        note: alsoYou(detail, viewerId),
    };
}
