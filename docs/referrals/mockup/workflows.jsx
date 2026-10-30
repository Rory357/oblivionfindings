import { CalendarDays } from 'lucide-react';
import { useState } from 'react';
import { usePrototype } from './context';
import { TODAY, date } from './data';
import {
    businessDate,
    clone,
    eligibleOwners,
    followupsFor,
    stamp,
    uid,
} from './model';
import {
    Badge,
    Button,
    Empty,
    Modal,
    Notice,
    ReviewCard,
    ReviewRow,
    Section,
    Wizard,
} from './ui';
export function WorkPanel({ r }) {
    const { can, request } = usePrototype();
    const work = followupsFor(r, true).filter(
        (w) => !w.financial || can('fundingRead'),
    );
    return (
        <>
            <Section
                title="Tasks & next actions"
                icon={CalendarDays}
                caption="One work item, with the same owner and date in the queue and calendar."
                actions={
                    can('tasks') && (
                        <Button onClick={() => request('workEdit', r)}>
                            Add follow-up
                        </Button>
                    )
                }
            >
                {work.map((w) => (
                    <div key={w.id} className="work-row">
                        <div className="grow">
                            <strong>{w.label}</strong>
                            <p className="text-subtle">
                                {w.owner} · {date(w.due)}
                                {w.primary
                                    ? ' · Primary next action'
                                    : w.financial
                                      ? ' · Funding case'
                                      : ''}
                            </p>
                            {w.outcome && (
                                <p className="text-subtle">{w.outcome}</p>
                            )}
                        </div>
                        <Badge>
                            {w.status === 'Cancelled'
                                ? 'Cancelled'
                                : w.done
                                  ? 'Completed'
                                  : w.status || 'Open'}
                        </Badge>
                        {can(w.financial ? 'funding' : 'tasks') && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    request('workEdit', r, { work: w })
                                }
                            >
                                Work on follow-up
                            </Button>
                        )}
                    </div>
                ))}
                {!work.length && <Empty title="No follow-up work" />}
            </Section>
            <Appointments r={r} />
        </>
    );
}
export function WorkAction({ m, close }) {
    const { can, data, update, actor, failure, setFailure, request } =
            usePrototype(),
        r = m.r,
        w = m.work;
    const [undo, setUndo] = useState(null);
    const permission = w?.financial ? 'funding' : 'tasks';
    if (!can(permission))
        return <Empty title="Follow-up permission required" />;
    if (undo)
        return (
            <Modal title="Follow-up saved" onClose={close}>
                <Notice tone="success">
                    Follow-up saved.
                    <div className="actions">
                        <Button onClick={close}>Done</Button>
                        <Button
                            variant="outline"
                            onClick={() => {
                                update(r.id, undo, 'Follow-up change undone');
                                close();
                            }}
                        >
                            Undo follow-up change
                        </Button>
                    </div>
                </Notice>
            </Modal>
        );
    const initial = {
        label: w?.label || '',
        owner: w?.owner || r.owner,
        due: m.due || w?.due || businessDate(1),
        time: w?.time || '',
        action: m.complete ? 'Complete' : w?.done ? 'Reopen' : 'Edit',
        outcome: '',
        replacement: '',
        replacementDue: businessDate(
            data.config.reviewDays,
            undefined,
            data.config,
        ),
    };
    return (
        <Wizard
            title={w ? 'Work on follow-up' : 'Add follow-up'}
            initial={initial}
            onClose={close}
            failure={failure}
            clearFailure={() => setFailure('Normal')}
            steps={[
                {
                    label: 'Work, owner and date',
                    fields: [
                        {
                            key: 'action',
                            label: 'Action',
                            type: 'select',
                            options: w
                                ? ['Edit', 'Complete', 'Cancel', 'Reopen']
                                : ['Edit'],
                        },
                        {
                            key: 'label',
                            label: 'What needs to happen?',
                            required: true,
                        },
                        {
                            key: 'owner',
                            label: 'Owner',
                            type: 'search',
                            options: eligibleOwners(data, r.site),
                            disabled: !!w?.primary && !can('assign'),
                            hint:
                                w?.primary && !can('assign')
                                    ? 'Changing the referral owner requires Assign referrals permission.'
                                    : undefined,
                            required: true,
                        },
                        {
                            key: 'due',
                            label: 'Due date',
                            type: 'date',
                            required: true,
                        },
                        { key: 'time', label: 'Time (optional)', type: 'time' },
                    ],
                },
                {
                    label: 'Outcome and continuity',
                    fields: [
                        {
                            key: 'outcome',
                            label: 'Outcome / reason',
                            type: 'textarea',
                            required: true,
                        },
                        {
                            key: 'replacement',
                            label: 'Next action after this one',
                            required: true,
                            when: (v) =>
                                w?.primary &&
                                ['Complete', 'Cancel'].includes(v.action) &&
                                !['Closed', 'Converted'].includes(r.stage),
                        },
                        {
                            key: 'replacementDue',
                            label: 'Next action / hold review date',
                            type: 'date',
                            required: true,
                            when: (v) =>
                                w?.primary &&
                                ['Complete', 'Cancel'].includes(v.action) &&
                                !['Closed', 'Converted'].includes(r.stage),
                        },
                    ],
                },
            ]}
            onSave={(v) => {
                if (w?.primary && v.owner !== r.owner && !can('assign'))
                    return {
                        error: 'Assign referrals permission is required to change the referral owner.',
                    };
                if (!eligibleOwners(data, r.site).includes(v.owner))
                    return {
                        error: 'Choose an available owner with access to this site.',
                    };
                let patch, previous;
                const done = v.action === 'Complete' || v.action === 'Cancel',
                    changed = {
                        label: v.label,
                        owner: v.owner,
                        due: v.due,
                        time: v.time,
                        done,
                        status:
                            v.action === 'Cancel'
                                ? 'Cancelled'
                                : done
                                  ? 'Completed'
                                  : 'Open',
                        outcome: v.outcome,
                        completedAt: done ? stamp() : null,
                        by: actor,
                    };
                if (w?.primary) {
                    previous = {
                        next: r.next,
                        due: r.due,
                        nextTime: r.nextTime || '',
                        owner: r.owner,
                        nextDone: r.nextDone,
                        tasks: r.tasks,
                        stage: r.stage,
                    };
                    patch = done
                        ? {
                              next: v.replacement || r.next,
                              due: v.replacement ? v.replacementDue : v.due,
                              nextTime: v.replacement ? '' : v.time,
                              owner: v.owner,
                              nextDone: !v.replacement,
                              tasks: [
                                  ...r.tasks,
                                  {
                                      ...w,
                                      ...changed,
                                      primary: false,
                                      id: uid('completed'),
                                  },
                              ],
                          }
                        : {
                              next: v.label,
                              due: v.due,
                              nextTime: v.time,
                              owner: v.owner,
                              nextDone: false,
                          };
                } else if (w?.fundingId) {
                    previous = { funding: r.funding };
                    patch = {
                        funding: r.funding.map((f) =>
                            f.id === w.fundingId
                                ? {
                                      ...f,
                                      next: v.label,
                                      due: v.due,
                                      nextTime: v.time,
                                      navigator: v.owner,
                                      nextDone: done,
                                      nextStatus: changed.status,
                                      completedAt: changed.completedAt,
                                      workOutcome: v.outcome,
                                  }
                                : f,
                        ),
                    };
                } else {
                    previous = { tasks: r.tasks };
                    patch = {
                        tasks: w
                            ? r.tasks.map((t) =>
                                  t.id === w.id ? { ...t, ...changed } : t,
                              )
                            : [...r.tasks, { ...changed, id: uid('task') }],
                    };
                }
                update(
                    r.id,
                    patch,
                    `${v.action === 'Edit' ? 'Updated' : v.action} follow-up: ${v.label}`,
                );
                // Undo is a local, immediate inverse of this fictional edit, offered before leaving this dialog.
                setUndo(previous);
                return {
                    message:
                        'Follow-up saved. You can undo this local change before leaving.',
                };
            }}
        />
    );
}
export function Appointments({ r }) {
    const { can, request } = usePrototype();
    return (
        <Section
            title="Assessments & appointments"
            icon={CalendarDays}
            actions={
                can('tasks') && (
                    <Button
                        variant="outline"
                        onClick={() => request('appointment', r)}
                    >
                        Schedule assessment
                    </Button>
                )
            }
        >
            {(r.appointments || []).map((a) => (
                <ReviewCard icon={CalendarDays} key={a.id} title={a.title}>
                    <ReviewRow
                        label="When"
                        value={`${date(a.date)} · ${a.time || 'All day'} · ${a.duration || 60} minutes`}
                    />
                    <ReviewRow
                        label="Where / who"
                        value={`${a.location} · ${a.attendees}`}
                    />
                    <ReviewRow
                        label="State"
                        value={<Badge>{a.status}</Badge>}
                    />
                    {a.outcome && (
                        <ReviewRow label="Outcome" value={a.outcome} />
                    )}
                    <Button
                        variant="link"
                        disabled={!can('tasks')}
                        onClick={() =>
                            request('appointment', r, { appointment: a })
                        }
                    >
                        Review appointment ↗
                    </Button>
                </ReviewCard>
            ))}
            {!r.appointments?.length && (
                <p className="text-subtle">
                    No assessments scheduled. Funding and provider meetings can
                    be recorded here.
                </p>
            )}
        </Section>
    );
}
export function AppointmentAction({ m, close }) {
    const { data, can, update, actor, failure, setFailure } = usePrototype(),
        r = m.r,
        a = m.appointment;
    if (!can('tasks'))
        return <Empty title="Manage follow-ups permission required" />;
    return (
        <Wizard
            title={a ? 'Review assessment appointment' : 'Schedule assessment'}
            onClose={close}
            initial={
                a
                    ? { ...a, date: m.date || a.date }
                    : {
                          title: 'Assessment / planning conversation',
                          date: m.date || TODAY,
                          time: '10:00',
                          duration: 60,
                          location: '',
                          attendees: '',
                          owner: r.owner,
                          status: 'Scheduled',
                          fundingId: '',
                          outcome: '',
                      }
            }
            failure={failure}
            clearFailure={() => setFailure('Normal')}
            steps={[
                {
                    label: 'Meeting and participants',
                    fields: [
                        {
                            key: 'title',
                            label: 'Appointment purpose',
                            required: true,
                        },
                        {
                            key: 'location',
                            label: 'Location or remote meeting details',
                            required: true,
                        },
                        {
                            key: 'attendees',
                            label: 'Participants and contact organisation',
                            required: true,
                        },
                        {
                            key: 'owner',
                            label: 'Responsible staff member',
                            type: 'search',
                            options: eligibleOwners(data, r.site),
                            required: true,
                        },
                        {
                            key: 'fundingId',
                            label: 'Related funding case',
                            type: 'select',
                            options: [
                                {
                                    value: '',
                                    label: 'Provider assessment / no funding case',
                                },
                                ...(can('fundingRead')
                                    ? r.funding.map((f) => ({
                                          value: f.id,
                                          label: f.path,
                                      }))
                                    : []),
                            ],
                        },
                    ],
                },
                {
                    label: 'Date and outcome',
                    fields: [
                        {
                            key: 'date',
                            label: 'Appointment date',
                            type: 'date',
                            required: true,
                        },
                        {
                            key: 'time',
                            label: 'Appointment time',
                            type: 'time',
                            required: true,
                        },
                        {
                            key: 'duration',
                            label: 'Duration (minutes)',
                            type: 'number',
                            required: true,
                        },
                        {
                            key: 'status',
                            label: 'Appointment state',
                            type: 'select',
                            options: [
                                'Scheduled',
                                'Completed',
                                'Cancelled',
                                'Did not attend',
                            ],
                        },
                        {
                            key: 'outcome',
                            label: 'Outcome or reason for this update',
                            type: 'textarea',
                            required: true,
                        },
                    ],
                    validate: (v) =>
                        Number(v.duration) < 5
                            ? 'Use a duration of at least five minutes.'
                            : '',
                },
            ]}
            onSave={(v) => {
                if (!eligibleOwners(data, r.site).includes(v.owner))
                    return {
                        error: 'Choose an eligible appointment owner for this site.',
                    };
                const changed = {
                    ...v,
                    id: a?.id || uid('appointment'),
                    updatedAt: stamp(),
                    by: actor,
                    history: [
                        ...(a?.history || []),
                        ...(a ? [{ ...clone(a), history: undefined }] : []),
                    ],
                };
                update(
                    r.id,
                    {
                        appointments: a
                            ? r.appointments.map((x) =>
                                  x.id === a.id ? changed : x,
                              )
                            : [...(r.appointments || []), changed],
                    },
                    'Assessment appointment ' + v.status.toLowerCase(),
                );
                return {
                    message:
                        'Appointment updated in the calendar and referral. Its history is preserved.',
                };
            }}
        />
    );
}
