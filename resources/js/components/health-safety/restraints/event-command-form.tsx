import {
    ActualEventTimeField,
    actualEventInstant,
} from '@/components/clinical/actual-event-time-field';
import { DrillTimeField } from '@/components/health-safety/drills/drill-time-field';
import { HazardStaffPicker } from '@/components/health-safety/hazard-staff-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { toDatetimeLocal, workerTimeOffset } from '@/lib/datetime';
import { hsLabel } from '@/lib/health-safety/hs-labels';
import {
    restraintHref,
    type RestraintEventCommand,
    type RestraintWorkspace,
} from '@/lib/health-safety/restraint-navigation';
import { useMemo } from 'react';
import { SourceCommandStatus } from './command-status';
import {
    EMPTY_RESTRAINT_PICKERS,
    type RestraintEventDetail,
    type RestraintPickers,
    type SourceFact,
} from './types';
import { useCaptureChoices } from './use-capture-choices';
import { useSourceCommand, type CommandContext } from './use-source-command';

export const EVENT_COMMAND_LABELS: Record<
    RestraintEventCommand | 'create',
    string
> = {
    create: 'Record a restraint',
    correct: 'Correct what was recorded',
    review: 'Record review',
    'assign-review': 'Choose who reviews this',
    'link-incident': 'Link an incident',
    'unlink-incident': 'Unlink the incident',
    'add-observation': 'Record a check',
    'correct-observation': 'Correct a check',
    'record-debrief': 'Record debrief',
    'correct-debrief': 'Correct debrief',
    'add-followup': 'Add follow-up',
    'reassign-followup': 'Change follow-up owner',
    'complete-followup': 'Record follow-up outcome',
    'correct-followup': 'Correct follow-up outcome',
    'cancel-followup': 'Cancel follow-up',
    'add-evidence': 'Add evidence',
    'remove-evidence': 'Remove evidence',
};
/** The save button names what it does (never a bare "Save and confirm"). */
const SAVE_LABELS: Record<RestraintEventCommand | 'create', string> = {
    create: 'Save restraint',
    correct: 'Save correction',
    review: 'Save review',
    'assign-review': 'Save review owner',
    'link-incident': 'Link incident',
    'unlink-incident': 'Unlink incident',
    'add-observation': 'Save check',
    'correct-observation': 'Save correction',
    'record-debrief': 'Save debrief',
    'correct-debrief': 'Save correction',
    'add-followup': 'Add follow-up',
    'reassign-followup': 'Change owner',
    'complete-followup': 'Save outcome',
    'correct-followup': 'Save correction',
    'cancel-followup': 'Cancel follow-up',
    'add-evidence': 'Add file',
    'remove-evidence': 'Remove file',
};
const DEBRIEF_STATUS_LABELS: Record<string, string> = {
    completed: 'Done',
    not_yet_done: 'Not done yet',
    declined: 'Declined',
    not_required: 'Not needed',
};
const FOLLOWUP_OUTCOME_LABELS: Record<string, string> = {
    recorded: 'Done',
    contacted: 'Contact made',
    attempted: 'Tried to make contact',
    unable_to_contact: 'Couldn’t make contact',
    declined: 'Declined',
    not_required: 'Not needed',
    other: 'Other',
};
const EVIDENCE_CATEGORY_LABELS: Record<string, string> = {
    body_map: 'Body map',
    injury_photo: 'Injury photo',
    authorisation: 'Authorisation form',
    debrief: 'Debrief notes',
    other: 'Other',
};
export function eventCommandAllowed(
    detail: RestraintEventDetail,
    command: RestraintEventCommand,
    child?: number | null,
) {
    if (command === 'add-evidence') return detail.can.capture;
    if (command === 'remove-evidence')
        return (
            detail.can.manage &&
            detail.event.attachments.some(
                (item) => item.id === child && !item.removed_at,
            )
        );
    const childFlag = {
        'reassign-followup': 'reassign',
        'complete-followup': 'complete',
        'correct-followup': 'correct',
        'cancel-followup': 'cancel',
    } as const;
    if (command in childFlag)
        return detail.event.followups.some(
            (item) =>
                item.id === child &&
                item.can[childFlag[command as keyof typeof childFlag]],
        );
    return detail.can.review;
}
const textFact = (row: SourceFact | undefined, key: string) =>
    typeof row?.facts[key] === 'string'
        ? (row.facts[key] as string)
        : key === 'actual_participants' && Array.isArray(row?.facts[key])
          ? (row.facts[key] as string[]).join('\n')
          : '';

export function RestraintEventCommandForm({
    detail,
    command,
    childId,
    pickers: provided,
    workspace = 'view',
    returnTo,
    onDone,
    onCancel,
    prescope,
    captureAllowed = true,
    captureCheckHref = '/health-safety/restraints/report',
}: {
    detail: RestraintEventDetail | null;
    command: RestraintEventCommand | 'create';
    childId?: number | null;
    pickers?: Partial<RestraintPickers>;
    workspace?: RestraintWorkspace;
    returnTo: string;
    onDone: (href?: string | null) => void;
    onCancel: () => void;
    prescope?: {
        clientId?: number;
        siteId?: number;
        endpoint?: string;
        locked?: boolean;
    };
    captureAllowed?: boolean;
    captureCheckHref?: string;
}) {
    const d = detail?.event;
    const choices = useCaptureChoices(provided, captureCheckHref, 'event');
    const pickers =
        command === 'create'
            ? choices.pickers
            : { ...EMPTY_RESTRAINT_PICKERS, ...provided };
    const followup = d?.followups.find((item) => item.id === childId);
    const prior =
        command === 'correct-observation'
            ? d?.observations.find((item) => item.id === childId)
            : command === 'correct-debrief'
              ? d?.debriefs.find((item) => item.id === childId)
              : undefined;
    const factual = command === 'create' || command === 'correct';
    const observing =
        command === 'add-observation' || command === 'correct-observation';
    const debrief =
        command === 'record-debrief' || command === 'correct-debrief';
    const assigning =
        command === 'assign-review' ||
        command === 'add-followup' ||
        command === 'reassign-followup';
    const completing =
        command === 'complete-followup' || command === 'correct-followup';
    const context = useMemo<CommandContext | null>(
        () =>
            d && detail
                ? {
                      id: d.id,
                      version: d.lock_version,
                      allowed:
                          command !== 'create' &&
                          eventCommandAllowed(detail, command, childId),
                  }
                : null,
        [d, detail, command, childId],
    );
    const source = useSourceCommand({
        kind: 'event',
        command: command.replaceAll('-', '_'),
        context,
        captureAllowed: captureAllowed && choices.ready,
        receiptKey: 'restraint_confirmation',
        checkHref: d
            ? restraintHref('events', d.id, { workspace })
            : captureCheckHref,
        childKey: childId
            ? command === 'correct-observation' || command === 'correct-debrief'
                ? 'supersedes_id'
                : command.includes('observation')
                  ? 'observation_id'
                  : command.includes('debrief')
                    ? 'debrief_id'
                    : command.includes('evidence')
                      ? 'attachment_id'
                      : 'followup_id'
            : undefined,
        childId: childId ?? undefined,
        readContext: (value) => {
            const response = value as {
                detail?: RestraintEventDetail;
                can?: { capture: boolean };
            };
            const current = response?.detail;
            return command === 'create'
                ? { id: 0, version: 0, allowed: response.can?.capture === true }
                : current
                  ? {
                        id: current.event.id,
                        version: current.event.lock_version,
                        allowed: eventCommandAllowed(current, command, childId),
                    }
                  : null;
        },
        initial: {
            client_id: String(prescope?.clientId ?? ''),
            site_id: String(prescope?.siteId ?? ''),
            restraint_type: d?.restraint_type ?? '',
            severity: d?.severity ?? '',
            started_at: toDatetimeLocal(d?.started_at),
            started_offset: d?.started_at ? workerTimeOffset(d.started_at) : '',
            ended_at: toDatetimeLocal(d?.ended_at),
            ended_offset: d?.ended_at ? workerTimeOffset(d.ended_at) : '',
            duration_minutes:
                d?.duration_minutes == null ? '' : String(d.duration_minutes),
            trigger_description: d?.trigger_description ?? '',
            de_escalation_attempted: d?.de_escalation_attempted ?? '',
            restraint_description: d?.restraint_description ?? '',
            person_response: d?.person_response ?? '',
            post_incident_support: d?.post_incident_support ?? '',
            injury_occurred: d?.injury_occurred ?? false,
            injury_details: d?.injury_details ?? '',
            within_support_plan: d?.within_support_plan ?? false,
            deviation_reason: d?.deviation_reason ?? '',
            behaviour_support_plan_id: '',
            authorised_by: '',
            staff_involved: [] as number[],
            correct_authoriser: false,
            correct_staff: false,
            related_incident_id: '',
            reviewed_by: '',
            reviewed_at: '',
            reviewed_offset: '',
            review_notes: d?.review_notes ?? '',
            lessons_learned: d?.lessons_learned ?? '',
            review_owner_id: String(d?.review_owner_id?.id ?? ''),
            review_due_at: toDatetimeLocal(d?.review_due_at),
            review_due_offset: d?.review_due_at
                ? workerTimeOffset(d.review_due_at)
                : '',
            observed_by: '',
            replace_observer: false,
            observed_at: toDatetimeLocal(prior?.occurred_at),
            observed_offset: prior?.occurred_at
                ? workerTimeOffset(prior.occurred_at)
                : '',
            condition: textFact(prior, 'condition'),
            response: textFact(prior, 'response'),
            next_check_at: toDatetimeLocal(textFact(prior, 'next_check_at')),
            next_check_offset: textFact(prior, 'next_check_at')
                ? workerTimeOffset(textFact(prior, 'next_check_at'))
                : '',
            status: textFact(prior, 'status'),
            occurred_at: toDatetimeLocal(prior?.occurred_at),
            occurred_offset: prior?.occurred_at
                ? workerTimeOffset(prior.occurred_at)
                : '',
            actual_participants: textFact(prior, 'actual_participants'),
            person_voice: textFact(prior, 'person_voice'),
            communication_support: textFact(prior, 'communication_support'),
            advocacy_support: textFact(prior, 'advocacy_support'),
            cultural_preferences: textFact(prior, 'cultural_preferences'),
            contact_outcome: textFact(prior, 'contact_outcome'),
            learning: textFact(prior, 'learning'),
            notes:
                command === 'correct-followup'
                    ? (followup?.completion_outcome?.notes ?? '')
                    : command === 'add-followup'
                      ? ''
                      : (followup?.notes ?? ''),
            assigned_to_user_id: String(followup?.assignee?.id ?? ''),
            due_at: toDatetimeLocal(followup?.due_at),
            due_offset: followup?.due_at
                ? workerTimeOffset(followup.due_at)
                : '',
            completed_at: toDatetimeLocal(
                command === 'correct-followup' ? followup?.completed_at : null,
            ),
            completed_offset: followup?.completed_at
                ? workerTimeOffset(followup.completed_at)
                : '',
            outcome:
                command === 'correct-followup'
                    ? (followup?.completion_outcome?.outcome ?? '')
                    : '',
            reason: '',
            evidence_ids:
                prior?.evidence_ids ??
                (command === 'correct-followup'
                    ? followup?.completion_evidence_ids
                    : undefined) ??
                ([] as number[]),
            file: null as File | null,
            category: '',
        },
    });
    const { form, change } = source;
    const checkedEvent = (
        source.checkedResponse as { detail?: RestraintEventDetail } | null
    )?.detail?.event;
    const currentFacts = checkedEvent
        ? [
              {
                  label: 'What happened',
                  value: checkedEvent.restraint_description,
              },
              { label: 'What led to it', value: checkedEvent.trigger_description },
              {
                  label: 'Alternatives attempted',
                  value: checkedEvent.de_escalation_attempted,
              },
              {
                  label: 'Person’s response',
                  value: checkedEvent.person_response,
              },
              {
                  label: 'Support afterwards',
                  value: checkedEvent.post_incident_support,
              },
              {
                  label: 'Injury recorded',
                  value: checkedEvent.injury_occurred ? 'Yes' : 'No',
              },
              { label: 'Injury details', value: checkedEvent.injury_details },
              {
                  label: 'Was it in the plan?',
                  value: checkedEvent.plan_authorization.explanation,
              },
              {
                  label: 'Review owner',
                  value: checkedEvent.review_owner_id?.name ?? null,
              },
              {
                  label: 'Follow-up',
                  value:
                      checkedEvent.followups.find((item) => item.id === childId)
                          ?.notes ?? null,
              },
          ]
        : undefined;
    const chooseClient = (value: string) =>
        void choices.select(value, (id, site) => {
            change('client_id', id);
            change('site_id', site);
            change('related_incident_id', '');
            change('behaviour_support_plan_id', '');
            change('authorised_by', '');
            change('staff_involved', []);
        });
    type Data = typeof form.data;
    const text = (key: keyof Data, label: string, required = false) => (
        <Field
            key={key}
            label={label}
            required={required}
            error={form.errors[key]}
        >
            <Textarea
                value={String(form.data[key] ?? '')}
                required={required}
                onChange={(event) => change(key, event.target.value as never)}
            />
        </Field>
    );
    const select = (
        key: keyof Data,
        label: string,
        options: { value: string; label: string }[],
        required = false,
    ) => (
        <Field
            key={key}
            label={label}
            required={required}
            error={form.errors[key]}
        >
            <SelectInput
                frontline
                value={String(form.data[key])}
                onChange={(value) => change(key, value as never)}
                options={options}
                placeholder="Choose an option"
            />
        </Field>
    );
    const person = (
        key: keyof Data,
        label: string,
        options: { id: number; name: string }[],
    ) => (
        <Field key={key} label={label} error={form.errors[key]}>
            {key === 'client_id' && prescope?.locked ? (
                <p className="text-subtle">
                    {pickers.clients.find(
                        (item) => String(item.id) === form.data.client_id,
                    )?.name ?? 'Person on this respite stay'}
                </p>
            ) : (
                <>
                    <HazardStaffPicker
                        value={String(form.data[key])}
                        onChange={(value) =>
                            key === 'client_id' && command === 'create'
                                ? chooseClient(value)
                                : change(key, value as never)
                        }
                        options={options.map((item) => ({
                            value: String(item.id),
                            label: item.name,
                        }))}
                        placeholder={label}
                        listLabel={label}
                        searchLabel={
                            key === 'client_id' ? 'Search people' : undefined
                        }
                        emptyLabel={
                            key === 'client_id'
                                ? 'No one matches this search.'
                                : undefined
                        }
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        className="frontline-hit mt-3"
                        onClick={() =>
                            key === 'client_id' && command === 'create'
                                ? chooseClient('')
                                : change(key, '' as never)
                        }
                    >
                        Clear selection
                    </Button>
                </>
            )}
        </Field>
    );
    const time = (
        key: keyof Data,
        offset: keyof Data,
        label: string,
        required = true,
        actual = true,
    ) =>
        actual ? (
            <ActualEventTimeField
                key={key}
                id={'restraint-' + String(key)}
                label={label}
                value={String(form.data[key])}
                offset={String(form.data[offset])}
                onChange={(value, zone) => {
                    change(key, value as never);
                    change(offset, zone as never);
                }}
                error={form.errors[key]}
            />
        ) : (
            <DrillTimeField
                key={key}
                label={label}
                value={String(form.data[key])}
                offset={String(form.data[offset])}
                onChange={(value, zone) => {
                    change(key, value as never);
                    change(offset, zone as never);
                }}
                required={required}
            />
        );
    const instant = (key: keyof Data, offset: keyof Data) => {
        const original = (
            {
                started_at: d?.started_at,
                ended_at: d?.ended_at,
                review_due_at: d?.review_due_at,
                observed_at: prior?.occurred_at,
                occurred_at: prior?.occurred_at,
                next_check_at: textFact(prior, 'next_check_at'),
                due_at: followup?.due_at,
                completed_at:
                    command === 'correct-followup'
                        ? followup?.completed_at
                        : null,
            } as Partial<Record<keyof Data, string | null | undefined>>
        )[key];
        if (
            original &&
            form.data[key] === toDatetimeLocal(original) &&
            form.data[offset] === workerTimeOffset(original)
        )
            return original;
        return form.data[key]
            ? actualEventInstant(
                  String(form.data[key]),
                  String(form.data[offset]),
              )
            : null;
    };
    const base = `${'/health-safety/restraints/events'}/${d?.id ?? ''}`;
    const routes: Record<
        RestraintEventCommand | 'create',
        { method: 'post' | 'put' | 'delete'; href: string }
    > = {
        create: {
            method: 'post',
            href: prescope?.endpoint ?? '/health-safety/restraints/events',
        },
        correct: { method: 'post', href: base + '/corrections' },
        review: { method: 'put', href: base },
        'assign-review': { method: 'post', href: base + '/review-assignments' },
        'link-incident': { method: 'post', href: base + '/link-incident' },
        'unlink-incident': { method: 'post', href: base + '/unlink-incident' },
        'add-observation': { method: 'post', href: base + '/observations' },
        'correct-observation': {
            method: 'post',
            href: base + `/observations/${childId}/corrections`,
        },
        'record-debrief': { method: 'post', href: base + '/debriefs' },
        'correct-debrief': {
            method: 'post',
            href: base + `/debriefs/${childId}/corrections`,
        },
        'add-followup': { method: 'post', href: base + '/followups' },
        'reassign-followup': {
            method: 'post',
            href: base + `/followups/${childId}/assignment`,
        },
        'complete-followup': {
            method: 'post',
            href: base + `/followups/${childId}/complete`,
        },
        'correct-followup': {
            method: 'post',
            href: base + `/followups/${childId}/corrections`,
        },
        'cancel-followup': {
            method: 'post',
            href: base + `/followups/${childId}/cancel`,
        },
        'add-evidence': { method: 'post', href: base + '/attachments' },
        'remove-evidence': {
            method: 'delete',
            href: base + `/attachments/${childId}`,
        },
    };
    const payload = (): Record<string, unknown> => {
        const v = form.data;
        if (factual) {
            const facts = {
                ...(command === 'create'
                    ? {
                          client_id: Number(v.client_id),
                          ...(v.site_id ? { site_id: Number(v.site_id) } : {}),
                          staff_involved: v.staff_involved,
                          authorised_by: v.authorised_by
                              ? Number(v.authorised_by)
                              : null,
                          ...(v.related_incident_id
                              ? {
                                    related_incident_id: Number(
                                        v.related_incident_id,
                                    ),
                                }
                              : {}),
                      }
                    : { reason: v.reason }),
                restraint_type: v.restraint_type,
                severity: v.severity,
                started_at: instant('started_at', 'started_offset'),
                ended_at: instant('ended_at', 'ended_offset'),
                duration_minutes: v.duration_minutes
                    ? Number(v.duration_minutes)
                    : null,
                trigger_description: v.trigger_description || null,
                de_escalation_attempted: v.de_escalation_attempted || null,
                restraint_description: v.restraint_description,
                person_response: v.person_response || null,
                post_incident_support: v.post_incident_support || null,
                injury_occurred: v.injury_occurred,
                injury_details: v.injury_details || null,
                within_support_plan: v.within_support_plan,
                deviation_reason: v.deviation_reason || null,
                ...(v.behaviour_support_plan_id
                    ? {
                          behaviour_support_plan_id: Number(
                              v.behaviour_support_plan_id,
                          ),
                      }
                    : {}),
            };
            if (command === 'create' || !d) return facts;
            const changed: Record<string, unknown> = { reason: v.reason };
            if (v.correct_staff) changed.staff_involved = v.staff_involved;
            if (v.correct_authoriser)
                changed.authorised_by = v.authorised_by
                    ? Number(v.authorised_by)
                    : null;
            for (const key of [
                'restraint_type',
                'severity',
                'duration_minutes',
                'trigger_description',
                'de_escalation_attempted',
                'restraint_description',
                'person_response',
                'post_incident_support',
                'injury_occurred',
                'injury_details',
                'within_support_plan',
                'deviation_reason',
            ] as const) {
                if (facts[key] !== d[key]) changed[key] = facts[key];
            }
            if (
                v.started_at !== toDatetimeLocal(d.started_at) ||
                v.started_offset !== workerTimeOffset(d.started_at)
            )
                changed.started_at = facts.started_at;
            if (
                v.ended_at !== toDatetimeLocal(d.ended_at) ||
                v.ended_offset !==
                    (d.ended_at ? workerTimeOffset(d.ended_at) : '')
            )
                changed.ended_at = facts.ended_at;
            if (v.behaviour_support_plan_id)
                changed.behaviour_support_plan_id = Number(
                    v.behaviour_support_plan_id,
                );
            return changed;
        }
        if (command === 'review')
            return {
                reviewed_by: v.reviewed_by ? Number(v.reviewed_by) : null,
                reviewed_at: instant('reviewed_at', 'reviewed_offset'),
                review_notes: v.review_notes,
                lessons_learned: v.lessons_learned,
                severity: v.severity,
                post_incident_support: v.post_incident_support,
                reason: v.reason,
                evidence_ids: v.evidence_ids,
            };
        if (command === 'assign-review')
            return {
                review_owner_id: v.review_owner_id
                    ? Number(v.review_owner_id)
                    : null,
                review_due_at: instant('review_due_at', 'review_due_offset'),
                reason: v.reason,
            };
        if (command === 'link-incident')
            return { related_incident_id: Number(v.related_incident_id) };
        if (
            command === 'unlink-incident' ||
            command === 'cancel-followup' ||
            command === 'remove-evidence'
        )
            return { reason: v.reason };
        if (observing)
            return {
                ...(command === 'add-observation' || v.replace_observer
                    ? {
                          observed_by: v.observed_by
                              ? Number(v.observed_by)
                              : null,
                      }
                    : {}),
                observed_at: instant('observed_at', 'observed_offset'),
                condition: v.condition,
                response: v.response,
                next_check_at: instant('next_check_at', 'next_check_offset'),
                evidence_ids: v.evidence_ids,
                ...(command === 'correct-observation'
                    ? { reason: v.reason }
                    : {}),
            };
        if (debrief)
            return {
                status: v.status,
                occurred_at: instant('occurred_at', 'occurred_offset'),
                actual_participants: v.actual_participants
                    .split('\n')
                    .map((item) => item.trim())
                    .filter(Boolean),
                person_voice: v.person_voice,
                communication_support: v.communication_support,
                advocacy_support: v.advocacy_support,
                cultural_preferences: v.cultural_preferences,
                contact_outcome: v.contact_outcome,
                learning: v.learning,
                reason: v.reason,
                evidence_ids: v.evidence_ids,
            };
        if (command === 'add-followup' || command === 'reassign-followup')
            return {
                assigned_to_user_id: v.assigned_to_user_id
                    ? Number(v.assigned_to_user_id)
                    : null,
                due_at: instant('due_at', 'due_offset'),
                ...(command === 'add-followup'
                    ? { notes: v.notes }
                    : { reason: v.reason }),
            };
        if (completing)
            return {
                completed_at: instant('completed_at', 'completed_offset'),
                outcome: v.outcome,
                notes: v.notes,
                evidence_ids: v.evidence_ids,
                ...(command === 'correct-followup' ? { reason: v.reason } : {}),
            };
        return {
            file: v.file,
            category: v.category || null,
            notes: v.notes || null,
        };
    };
    return (
        <form
            className="space-y-5"
            onSubmit={(event) => {
                event.preventDefault();
                if (
                    command === 'correct-observation' &&
                    form.data.replace_observer &&
                    !form.data.observed_by
                )
                    return;
                const target = routes[command];
                source.save(target.method, target.href, {
                    ...payload(),
                    return_to: returnTo,
                    action: command === 'create' ? null : command,
                    section: factual
                        ? 'overview'
                        : observing
                          ? 'monitoring'
                          : debrief
                            ? 'debrief'
                            : completing || assigning
                              ? 'followups'
                              : 'review',
                });
            }}
        >
            <h2 className="text-section-title">
                {EVENT_COMMAND_LABELS[command]}
            </h2>
            <SourceCommandStatus
                {...source}
                checking={source.checking || choices.checking}
                currentFacts={currentFacts}
                // A new record has no saved version to load; only offer it once something changed underneath.
                canCheck={command !== 'create' || source.stale}
                onCheck={() => void source.checkCurrent()}
                onReview={source.useCurrent}
                onDone={() => onDone(source.confirmedHref)}
            />
            {command === 'create' && choices.checking && (
                <p role="status" className="text-caption">
                    Loading options for this person…
                </p>
            )}
            {command === 'create' && choices.failure && (
                <div className="space-y-3">
                    <p
                        role="alert"
                        className="text-caption text-status-critical"
                    >
                        {choices.failure}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        className="frontline-tap"
                        onClick={() => chooseClient(form.data.client_id)}
                    >
                        Try again
                    </Button>
                </div>
            )}
            {!source.receipt && (
                <fieldset
                    disabled={
                        form.processing || source.checking || !source.allowed
                    }
                    className="space-y-5"
                >
                    {factual && (
                        <>
                            {command === 'create' &&
                                person('client_id', 'Person', pickers.clients)}
                            {select(
                                'restraint_type',
                                'Restraint type',
                                [
                                    'physical',
                                    'chemical',
                                    'mechanical',
                                    'seclusion',
                                    'environmental',
                                ].map((value) => ({
                                    value,
                                    label: hsLabel('restraint_type', value),
                                })),
                                true,
                            )}
                            {select(
                                'severity',
                                'Severity',
                                ['low', 'medium', 'high', 'critical'].map(
                                    (value) => ({
                                        value,
                                        label: hsLabel('severity', value),
                                    }),
                                ),
                                true,
                            )}
                            {time(
                                'started_at',
                                'started_offset',
                                'Start time',
                            )}
                            {time(
                                'ended_at',
                                'ended_offset',
                                'End time',
                                false,
                            )}
                            <Field
                                label="Duration in minutes"
                                error={form.errors.duration_minutes}
                            >
                                <Input
                                    type="number"
                                    min={0}
                                    value={form.data.duration_minutes}
                                    onChange={(event) =>
                                        change(
                                            'duration_minutes',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            {text(
                                'trigger_description',
                                'What led to the restraint?',
                                true,
                            )}
                            {text(
                                'de_escalation_attempted',
                                'Alternatives and de-escalation attempted',
                                true,
                            )}
                            {text(
                                'restraint_description',
                                'What happened?',
                                true,
                            )}
                            {text('person_response', 'The person’s response')}
                            {text(
                                'post_incident_support',
                                'Support afterwards',
                            )}
                            <Field label="Did an injury occur?">
                                <Checkbox
                                    checked={form.data.injury_occurred}
                                    onCheckedChange={(value) =>
                                        change(
                                            'injury_occurred',
                                            value === true,
                                        )
                                    }
                                />
                            </Field>
                            {form.data.injury_occurred &&
                                text('injury_details', 'Injury details', true)}
                            <Field label="Was this in the person’s approved behaviour support plan?">
                                <Checkbox
                                    checked={form.data.within_support_plan}
                                    onCheckedChange={(value) =>
                                        change(
                                            'within_support_plan',
                                            value === true,
                                        )
                                    }
                                />
                            </Field>
                            {person(
                                'behaviour_support_plan_id',
                                'Behaviour support plan',
                                pickers.plansForPicker
                                    .filter(
                                        (item) =>
                                            !form.data.client_id ||
                                            item.client_id ===
                                                Number(form.data.client_id),
                                    )
                                    .map((item) => ({
                                        id: item.id,
                                        name:
                                            item.reference + ' · ' + item.title,
                                    })),
                            )}
                            {!form.data.within_support_plan &&
                                text(
                                    'deviation_reason',
                                    'Why it was outside the plan',
                                    true,
                                )}
                            {command === 'correct' && (
                                <div className="space-y-3 rounded-xl border border-border p-4">
                                    <p className="text-subtle">
                                        Staff names stay as they are unless you
                                        tick a box below. If you change them, the
                                        old names stay in the history.
                                    </p>
                                    <label className="frontline-tap text-subtle flex items-center gap-3">
                                        <Checkbox
                                            checked={
                                                form.data.correct_authoriser
                                            }
                                            onCheckedChange={(checked) =>
                                                change(
                                                    'correct_authoriser',
                                                    checked === true,
                                                )
                                            }
                                        />
                                        Change who authorised it
                                    </label>
                                    {form.data.correct_authoriser
                                        ? person(
                                              'authorised_by',
                                              'Who authorised it (leave blank to remove the name)',
                                              pickers.staff,
                                          )
                                        : null}
                                    <label className="frontline-tap text-subtle flex items-center gap-3">
                                        <Checkbox
                                            checked={form.data.correct_staff}
                                            onCheckedChange={(checked) =>
                                                change(
                                                    'correct_staff',
                                                    checked === true,
                                                )
                                            }
                                        />
                                        Change the staff involved
                                    </label>
                                    {form.data.correct_staff ? (
                                        <Field label="Staff involved (untick everyone to clear the list)">
                                            <div className="space-y-3">
                                                {pickers.staff.map((staff) => (
                                                    <label
                                                        key={staff.id}
                                                        className="frontline-tap text-subtle flex items-center gap-3"
                                                    >
                                                        <Checkbox
                                                            checked={form.data.staff_involved.includes(
                                                                staff.id,
                                                            )}
                                                            onCheckedChange={(
                                                                checked,
                                                            ) =>
                                                                change(
                                                                    'staff_involved',
                                                                    checked ===
                                                                        true
                                                                        ? [
                                                                              ...form
                                                                                  .data
                                                                                  .staff_involved,
                                                                              staff.id,
                                                                          ]
                                                                        : form.data.staff_involved.filter(
                                                                              (
                                                                                  id,
                                                                              ) =>
                                                                                  id !==
                                                                                  staff.id,
                                                                          ),
                                                                )
                                                            }
                                                        />
                                                        {staff.name}
                                                    </label>
                                                ))}
                                            </div>
                                        </Field>
                                    ) : null}
                                </div>
                            )}
                            {command === 'create' && (
                                <>
                                    {person(
                                        'authorised_by',
                                        'Person who authorised the restraint',
                                        pickers.staff,
                                    )}
                                    <Field label="Staff who were involved">
                                        <div className="space-y-3">
                                            {pickers.staff.map((staff) => (
                                                <label
                                                    key={staff.id}
                                                    className="text-subtle flex min-h-11 items-center gap-3"
                                                >
                                                    <Checkbox
                                                        checked={form.data.staff_involved.includes(
                                                            staff.id,
                                                        )}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            change(
                                                                'staff_involved',
                                                                checked === true
                                                                    ? [
                                                                          ...form
                                                                              .data
                                                                              .staff_involved,
                                                                          staff.id,
                                                                      ]
                                                                    : form.data.staff_involved.filter(
                                                                          (
                                                                              id,
                                                                          ) =>
                                                                              id !==
                                                                              staff.id,
                                                                      ),
                                                            )
                                                        }
                                                    />
                                                    {staff.name}
                                                </label>
                                            ))}
                                        </div>
                                    </Field>
                                    {person(
                                        'related_incident_id',
                                        'Related incident',
                                        pickers.incidents
                                            .filter(
                                                (item) =>
                                                    item.client_id ===
                                                    Number(form.data.client_id),
                                            )
                                            .map((item) => ({
                                                id: item.id,
                                                name: item.label,
                                            })),
                                    )}
                                </>
                            )}
                        </>
                    )}
                    {command === 'review' && (
                        <>
                            {person(
                                'reviewed_by',
                                'Who did the review (leave blank if it was you)',
                                pickers.reviewers,
                            )}
                            {time(
                                'reviewed_at',
                                'reviewed_offset',
                                'When the review happened',
                            )}
                            {text('review_notes', 'Review findings')}
                            {text(
                                'lessons_learned',
                                'Learning and improvements',
                            )}
                            {select(
                                'severity',
                                'Severity after review',
                                ['low', 'medium', 'high', 'critical'].map(
                                    (value) => ({
                                        value,
                                        label: hsLabel('severity', value),
                                    }),
                                ),
                                true,
                            )}
                            {text(
                                'post_incident_support',
                                'Support afterwards',
                            )}
                        </>
                    )}
                    {command === 'assign-review' && (
                        <>
                            {person(
                                'review_owner_id',
                                'Who will review it',
                                pickers.reviewers,
                            )}
                            {time(
                                'review_due_at',
                                'review_due_offset',
                                'Review due',
                                false,
                                false,
                            )}
                        </>
                    )}
                    {command === 'link-incident' &&
                        person(
                            'related_incident_id',
                            'Incident for this person and site',
                            pickers.incidents.map((item) => ({
                                id: item.id,
                                name: item.label,
                            })),
                        )}
                    {observing && (
                        <>
                            {command === 'correct-observation' ? (
                                <div className="space-y-3 rounded-xl border border-border p-4">
                                    <p className="text-caption text-muted-foreground">
                                        The person who made the check stays on
                                        it unless you change them. Your name is
                                        saved as the person who corrected it.
                                    </p>
                                    <label className="frontline-tap text-subtle flex items-center gap-3">
                                        <Checkbox
                                            checked={form.data.replace_observer}
                                            onCheckedChange={(checked) =>
                                                change(
                                                    'replace_observer',
                                                    checked === true,
                                                )
                                            }
                                        />
                                        Change who made the check
                                    </label>
                                    {form.data.replace_observer
                                        ? person(
                                              'observed_by',
                                              'Who made the check',
                                              pickers.staff,
                                          )
                                        : null}
                                    {form.data.replace_observer &&
                                    !form.data.observed_by ? (
                                        <p
                                            role="alert"
                                            className="text-caption text-status-critical"
                                        >
                                            Choose who made the check before
                                            saving.
                                        </p>
                                    ) : null}
                                </div>
                            ) : (
                                person(
                                    'observed_by',
                                    'Who made the check (leave blank if it was you)',
                                    pickers.staff,
                                )
                            )}
                            {time(
                                'observed_at',
                                'observed_offset',
                                'When the check happened',
                            )}
                            {text('condition', 'The person’s condition', true)}
                            {text('response', 'Response and support provided')}
                            {time(
                                'next_check_at',
                                'next_check_offset',
                                'Next check due',
                                false,
                                false,
                            )}
                        </>
                    )}
                    {debrief && (
                        <>
                            {select(
                                'status',
                                'Debrief outcome',
                                [
                                    'completed',
                                    'not_yet_done',
                                    'declined',
                                    'not_required',
                                ].map((value) => ({
                                    value,
                                    label: DEBRIEF_STATUS_LABELS[value],
                                })),
                                true,
                            )}
                            {time(
                                'occurred_at',
                                'occurred_offset',
                                'When the debrief happened',
                                form.data.status === 'completed',
                            )}
                            {text(
                                'actual_participants',
                                'Who took part (one name per line)',
                            )}
                            {text(
                                'person_voice',
                                'The person’s voice and preferences',
                            )}
                            {text(
                                'communication_support',
                                'Communication support',
                            )}
                            {text('advocacy_support', 'Advocacy support')}
                            {text(
                                'cultural_preferences',
                                'Cultural preferences',
                            )}
                            {text(
                                'contact_outcome',
                                'Contact outcome',
                                form.data.status === 'completed',
                            )}
                            {text(
                                'learning',
                                'Learning and agreed improvements',
                            )}
                        </>
                    )}
                    {(command === 'add-followup' ||
                        command === 'reassign-followup') && (
                        <>
                            {command === 'add-followup' &&
                                text('notes', 'What needs to happen?', true)}
                            {person(
                                'assigned_to_user_id',
                                'Who will do this',
                                pickers.followupOwners,
                            )}
                            {time(
                                'due_at',
                                'due_offset',
                                'Follow-up due',
                                false,
                                false,
                            )}
                        </>
                    )}
                    {completing && (
                        <>
                            {time(
                                'completed_at',
                                'completed_offset',
                                'When it was done',
                            )}
                            {select(
                                'outcome',
                                'Outcome',
                                [
                                    'recorded',
                                    'contacted',
                                    'attempted',
                                    'unable_to_contact',
                                    'declined',
                                    'not_required',
                                    'other',
                                ].map((value) => ({
                                    value,
                                    label: FOLLOWUP_OUTCOME_LABELS[value],
                                })),
                                true,
                            )}
                            {text(
                                'notes',
                                'What happened and what remains?',
                                true,
                            )}
                        </>
                    )}
                    {(command === 'correct' ||
                        command === 'assign-review' ||
                        command.startsWith('correct-') ||
                        command === 'reassign-followup' ||
                        command === 'cancel-followup' ||
                        command === 'unlink-incident' ||
                        command === 'remove-evidence' ||
                        debrief ||
                        command === 'review') &&
                        text(
                            'reason',
                            'Reason',
                            command !== 'review' &&
                                command !== 'record-debrief',
                        )}
                    {command === 'add-evidence' && (
                        <>
                            <FileDropzone
                                onFiles={(files) =>
                                    change('file', files[0] ?? null)
                                }
                                multiple={false}
                                hint="Maximum 10 MB"
                            />
                            {form.data.file && (
                                <StagedFileCard
                                    file={form.data.file}
                                    onRemove={() => change('file', null)}
                                />
                            )}
                            <p className="text-caption text-muted-foreground">
                                Files stay private. Maximum size: 10 MB.
                            </p>
                            {select(
                                'category',
                                'Evidence category',
                                [
                                    'body_map',
                                    'injury_photo',
                                    'authorisation',
                                    'debrief',
                                    'other',
                                ].map((value) => ({
                                    value,
                                    label: EVIDENCE_CATEGORY_LABELS[value],
                                })),
                            )}
                            {text('notes', 'Evidence notes')}
                        </>
                    )}
                    {(observing ||
                        debrief ||
                        completing ||
                        command === 'review') && (
                        <Field label="Evidence used">
                            <div className="space-y-3">
                                {d?.attachments
                                    .filter((item) => !item.removed_at)
                                    .map((item) => (
                                        <label
                                            className="text-subtle flex min-h-11 items-center gap-3"
                                            key={item.id}
                                        >
                                            <Checkbox
                                                checked={form.data.evidence_ids.includes(
                                                    item.id,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    change(
                                                        'evidence_ids',
                                                        checked === true
                                                            ? [
                                                                  ...form.data
                                                                      .evidence_ids,
                                                                  item.id,
                                                              ]
                                                            : form.data.evidence_ids.filter(
                                                                  (id) =>
                                                                      id !==
                                                                      item.id,
                                                              ),
                                                    )
                                                }
                                            />
                                            {item.original_name}
                                        </label>
                                    ))}
                            </div>
                        </Field>
                    )}
                    {Object.entries(form.errors).length > 0 && (
                        <ul
                            role="alert"
                            className="text-caption list-disc space-y-1 pl-5 text-status-critical"
                        >
                            {Object.entries(form.errors).map(([key, error]) => (
                                <li key={key}>{String(error)}</li>
                            ))}
                        </ul>
                    )}
                    <div className="flex flex-wrap gap-3">
                        <Button
                            className="frontline-tap"
                            type="submit"
                            disabled={
                                source.stale ||
                                source.checkFailed ||
                                (command === 'correct-observation' &&
                                    form.data.replace_observer &&
                                    !form.data.observed_by) ||
                                !source.allowed ||
                                form.processing ||
                                source.checking
                            }
                        >
                            {form.processing ? 'Saving…' : SAVE_LABELS[command]}
                        </Button>
                        <Button
                            className="frontline-tap"
                            type="button"
                            variant="outline"
                            onClick={onCancel}
                        >
                            Cancel
                        </Button>
                    </div>
                </fieldset>
            )}
        </form>
    );
}
