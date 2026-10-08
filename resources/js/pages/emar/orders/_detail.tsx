import { ConfirmDialog } from '@/components/confirm-dialog';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { TilePicker } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import {
    formatDateOnly,
    formatDateTime,
    toDatetimeLocal,
} from '@/lib/datetime';
import type { SharedData } from '@/types';
import { useForm, usePage } from '@inertiajs/react';
import {
    CheckCheck,
    ClipboardCheck,
    FileText,
    History,
    Package,
    Phone,
    Pill,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import {
    Errors,
    Field,
    Note,
    OrderStatus,
    SourceFiles,
    useDraftClose,
} from './_parts';
import type { Detail, Order, ReviewHandoff, Revision } from './_types';

type Action =
    | 'view'
    | 'check'
    | 'allergy'
    | 'written'
    | 'stop'
    | 'hold'
    | 'resume';
const sections = [
    {
        key: 'order',
        label: 'This order',
        blurb: 'Prescription and source',
        icon: Pill,
    },
    {
        key: 'versions',
        label: 'Versions',
        blurb: 'Every version kept',
        icon: History,
    },
    {
        key: 'checks',
        label: 'Checks and confirmations',
        blurb: 'Who checked and what was confirmed',
        icon: ShieldCheck,
    },
    { key: 'supply', label: 'Supply', blurb: 'Read only', icon: Package },
];

export function OrderDetail({
    order,
    detail,
    me,
    canManage,
    onClose,
    onEnter,
    initialAction = 'view',
    review,
    initialCheckMode = 'independent',
}: {
    order: Order;
    detail: Detail;
    me: number;
    canManage: boolean;
    onClose: () => void;
    onEnter: () => void;
    initialAction?: Action;
    review?: ReviewHandoff;
    initialCheckMode?: 'independent' | 'second' | 'send_back';
}) {
    const { auth } = usePage<SharedData>().props;
    const canOpenStock = Boolean(auth.can?.medications?.stockUpdate);
    const [section, setSection] = useState(0);
    const [action, setAction] = useState<Action>(initialAction);
    const pending = detail.revisions.find(
        (revision) => revision.status === 'pending',
    );
    const current = detail.revisions.find(
        (revision) =>
            revision.version.version_number === order.version &&
            ['checked', 'checked_alone'].includes(revision.status),
    );
    const [selected, setSelected] = useState<Revision | undefined>(
        pending ?? current ?? detail.revisions[0],
    );
    const [confirmStop, setConfirmStop] = useState(false);
    const form = useForm({
        mode: String(initialCheckMode),
        source_matches: false,
        dose_route_times_checked: false,
        allergies_interactions_checked: false,
        lone_reason: '',
        reason: '',
        prescriber: selected?.version.source_evidence.prescriber ?? '',
        method: 'phone',
        confirmed_at: toDatetimeLocal(new Date()),
        instruction: '',
        received_at: toDatetimeLocal(new Date()),
        matches: false,
        file: null as File | null,
        client_id: order.client_id,
        request_key: crypto.randomUUID(),
        review_item: review?.id ?? null,
    });
    const close = useDraftClose(form.isDirty, form.processing, onClose);
    const involved =
        selected &&
        [selected.entered_by, selected.read_back_witness_id].includes(me);
    const allergyBlocked =
        detail.allergies.matches.length > 0 && !selected?.allergy_confirmation;
    const choose = (next: Action, revision = selected) => {
        setSelected(revision);
        setAction(next);
        form.clearErrors();
    };
    const save = () => {
        if (action === 'view') return;
        const url = ['stop', 'hold', 'resume'].includes(action)
            ? `/emar/orders/${order.id}/${action}`
            : `/emar/order-revisions/${selected?.id}/${action === 'allergy' ? 'allergy-confirmation' : action === 'written' ? 'written-confirmation' : form.data.mode === 'send_back' ? 'send-back' : 'check'}`;
        form.post(url, { preserveScroll: true, onSuccess: onClose });
    };
    const actionTitle = {
        view: 'This order',
        check: 'Check this version',
        allergy: 'The prescriber confirmed it’s safe',
        written: 'Attach the written confirmation',
        stop: 'Stop this order',
        hold: 'Hold this order',
        resume: 'Resume this order',
    };
    return (
        <>
            <WizardShell
                frontline
                open
                onClose={close.close}
                title={`${order.name} — ${actionTitle[action]}`}
                description={`Medication order for ${order.person}. Sources, checked versions and changes are kept.`}
                railIcon={Pill}
                railTitle={order.name}
                railSub={order.person}
                steps={
                    action === 'view'
                        ? sections
                        : [
                              {
                                  key: action,
                                  label: actionTitle[action],
                                  blurb: `Version ${selected?.version.version_number ?? order.version}`,
                                  icon: ClipboardCheck,
                              },
                          ]
                }
                sequential={false}
                stepIndex={action === 'view' ? section : 0}
                onStepClick={setSection}
                headerLabel={
                    action === 'view'
                        ? sections[section].label
                        : actionTitle[action]
                }
                pct={null}
                footerStart={
                    <Button
                        variant="outline"
                        disabled={form.processing}
                        onClick={
                            action === 'view'
                                ? close.close
                                : () => setAction('view')
                        }
                    >
                        {action === 'view' ? 'Close' : 'Back to order'}
                    </Button>
                }
                footerEnd={
                    action === 'view' ? (
                        <div className="flex flex-wrap gap-2">
                            {pending && order.can_verify && (
                                <Button
                                    onClick={() => choose('check', pending)}
                                >
                                    Check version{' '}
                                    {pending.version.version_number}
                                </Button>
                            )}
                            {order.can_manage && order.state !== 'ceased' && (
                                <Button variant="outline" onClick={onEnter}>
                                    Enter a change
                                </Button>
                            )}
                        </div>
                    ) : (
                        <Button
                            variant={
                                action === 'stop' ? 'destructive' : 'default'
                            }
                            disabled={
                                form.processing ||
                                (action === 'check' && allergyBlocked)
                            }
                            onClick={
                                action === 'stop'
                                    ? () => setConfirmStop(true)
                                    : save
                            }
                        >
                            {form.processing
                                ? 'Saving…'
                                : action === 'check'
                                  ? form.data.mode === 'send_back'
                                      ? 'Send it back'
                                      : 'Checked — it can be given'
                                  : action === 'written'
                                    ? 'Save written confirmation'
                                    : action === 'allergy'
                                      ? 'Record the prescriber’s confirmation'
                                      : `${action === 'hold' ? 'Hold' : action === 'resume' ? 'Resume' : 'Stop'} order`}
                        </Button>
                    )
                }
            >
                <div className="grid gap-5">
                    <Errors errors={form.errors} />
                    {action === 'view' && section === 0 && (
                        <>
                            <OrderStatus order={order} />
                            <ReviewCard
                                icon={Pill}
                                title={
                                    order.approval_status === 'verified'
                                        ? `In use · version ${order.version}`
                                        : 'New order — waiting for its check'
                                }
                            >
                                <ReviewRow
                                    label="Medicine"
                                    value={detail.order.name}
                                />
                                <ReviewRow
                                    label="Dose and route"
                                    value={`${detail.order.dosage} · ${detail.order.route}`}
                                />
                                <ReviewRow
                                    label="When"
                                    value={`${detail.order.frequency} · ${(detail.order.dose_times ?? []).join(', ')}`}
                                />
                                <ReviewRow
                                    label="What it is for"
                                    value={detail.order.indication}
                                />
                                <ReviewRow
                                    label="Instructions and monitoring"
                                    value={detail.order.instructions}
                                />
                                <ReviewRow
                                    label="Last day"
                                    value={formatDateOnly(
                                        detail.order.end_date?.slice(0, 10),
                                    )}
                                />
                            </ReviewCard>
                            <Note>
                                Last dose actually given:{' '}
                                {detail.last_dose
                                    ? `${detail.last_dose.dose_given} · ${formatDateTime(detail.last_dose.given_at)} · ${detail.last_dose.by ?? 'Recorder not known'} · eMAR record ${detail.last_dose.id}`
                                    : 'Not recorded in eMAR. Confirm the last dose from the transfer or prescribing source before giving.'}
                            </Note>
                            {current?.allergy_confirmation && (
                                <ReviewCard
                                    icon={ShieldCheck}
                                    title="The prescriber confirmed this version is safe"
                                >
                                    <ReviewRow
                                        label="Prescriber"
                                        value={
                                            current.allergy_confirmation
                                                .prescriber
                                        }
                                    />
                                    <ReviewRow
                                        label="When and how"
                                        value={`${formatDateTime(current.allergy_confirmation.confirmed_at)} · ${current.allergy_confirmation.method}`}
                                    />
                                    <ReviewRow
                                        label="What they said"
                                        value={
                                            current.allergy_confirmation
                                                .instruction
                                        }
                                    />
                                </ReviewCard>
                            )}
                            {order.blocked_reason && (
                                <Note>{order.blocked_reason}</Note>
                            )}
                            {order.can_manage && order.state !== 'ceased' && (
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            choose(
                                                order.state === 'paused'
                                                    ? 'resume'
                                                    : 'hold',
                                            )
                                        }
                                    >
                                        {order.state === 'paused'
                                            ? 'Resume on prescriber instruction'
                                            : 'Hold on prescriber instruction'}
                                    </Button>
                                    <Button
                                        variant="destructive"
                                        onClick={() => choose('stop')}
                                    >
                                        Stop order
                                    </Button>
                                </div>
                            )}
                        </>
                    )}
                    {action === 'view' && section === 1 && (
                        <>
                            {detail.revisions.length ? (
                                detail.revisions.map((revision) => (
                                    <ReviewCard
                                        key={revision.id}
                                        icon={History}
                                        title={`Version ${revision.version.version_number} · ${revision.status.replaceAll('_', ' ')}`}
                                    >
                                        <ReviewRow
                                            label="Prescription"
                                            value={`${revision.version.prescription_payload.dosage} · ${revision.version.prescription_payload.frequency}`}
                                        />
                                        <ReviewRow
                                            label="Entered"
                                            value={`${revision.enterer?.name ?? 'Not known'} · ${formatDateTime(revision.created_at)}`}
                                        />
                                        <ReviewRow
                                            label="Source"
                                            value={`${revision.version.source_evidence.type} · ${revision.version.source_evidence.prescriber ?? 'Not attached'}`}
                                        />
                                        <ReviewRow
                                            label="Change"
                                            value={
                                                revision.version.change_reason
                                            }
                                        />
                                        <SourceFiles
                                            files={revision.files ?? []}
                                        />
                                        {revision.status === 'pending' &&
                                            order.can_verify && (
                                                <Button
                                                    className="mt-3"
                                                    onClick={() =>
                                                        choose(
                                                            'check',
                                                            revision,
                                                        )
                                                    }
                                                >
                                                    Check this version
                                                </Button>
                                            )}
                                    </ReviewCard>
                                ))
                            ) : (
                                <Note>
                                    Existing chart entry. Source and original
                                    checks remain in the medication history.
                                    Enter its source through the order wizard
                                    when making a change.
                                </Note>
                            )}
                            {detail.actions.map((event) => (
                                <div
                                    key={event.id}
                                    className="rounded-lg border p-3"
                                >
                                    <p className="font-semibold">
                                        {event.action.replaceAll('_', ' ')}
                                    </p>
                                    <p className="text-caption">
                                        {formatDateTime(event.occurred_at)} ·
                                        staff record {event.actor_id}
                                    </p>
                                    {typeof event.evidence
                                        .prescriber_instruction ===
                                        'string' && (
                                        <p>
                                            {
                                                event.evidence
                                                    .prescriber_instruction
                                            }
                                        </p>
                                    )}
                                </div>
                            ))}
                        </>
                    )}
                    {action === 'view' && section === 2 && (
                        <>
                            {detail.revisions.map((revision) => (
                                <ReviewCard
                                    key={revision.id}
                                    icon={ShieldCheck}
                                    title={`Version ${revision.version.version_number}`}
                                >
                                    <ReviewRow
                                        label="Checked by"
                                        value={
                                            revision.checker?.name ?? 'Waiting'
                                        }
                                    />
                                    <ReviewRow
                                        label="Checked at"
                                        value={formatDateTime(
                                            revision.checked_at,
                                        )}
                                    />
                                    <ReviewRow
                                        label="Read-back"
                                        value={
                                            revision.witness
                                                ? `${revision.witness.name} · witness PIN · ${formatDateTime(revision.version.source_evidence.read_back_at)}`
                                                : 'Written source or not recorded'
                                        }
                                    />
                                    {revision.rejection_reason && (
                                        <ReviewRow
                                            label="Sent back"
                                            value={revision.rejection_reason}
                                        />
                                    )}
                                    {revision.lone_reason && (
                                        <ReviewRow
                                            label="Checked alone because"
                                            value={revision.lone_reason}
                                        />
                                    )}
                                    {revision.second_due_at &&
                                        !revision.second_checked_at && (
                                            <>
                                                <ReviewRow
                                                    label="Second check due"
                                                    value={formatDateTime(
                                                        revision.second_due_at,
                                                    )}
                                                />
                                                {order.can_verify &&
                                                    ![
                                                        revision.entered_by,
                                                        revision.read_back_witness_id,
                                                        revision.checked_by,
                                                    ].includes(me) && (
                                                        <Button
                                                            onClick={() => {
                                                                form.setData(
                                                                    'mode',
                                                                    'second',
                                                                );
                                                                choose(
                                                                    'check',
                                                                    revision,
                                                                );
                                                            }}
                                                        >
                                                            Do the second check
                                                        </Button>
                                                    )}
                                            </>
                                        )}
                                    {revision.written_due_at && (
                                        <>
                                            <ReviewRow
                                                label="Written confirmation"
                                                value={
                                                    revision.written_confirmation
                                                        ? `${revision.written_confirmation.method} · ${formatDateTime(revision.written_confirmation.received_at)}`
                                                        : `Due ${formatDateTime(revision.written_due_at)}`
                                                }
                                            />
                                            {order.can_manage &&
                                                !revision.written_confirmation && (
                                                    <Button
                                                        variant="outline"
                                                        onClick={() => {
                                                            form.setData(
                                                                'method',
                                                                'signed_prescription',
                                                            );
                                                            choose(
                                                                'written',
                                                                revision,
                                                            );
                                                        }}
                                                    >
                                                        Attach written
                                                        confirmation
                                                    </Button>
                                                )}
                                        </>
                                    )}
                                    {revision.allergy_confirmation && (
                                        <ReviewRow
                                            label="Prescriber’s allergy confirmation"
                                            value={
                                                revision.allergy_confirmation
                                                    .instruction
                                            }
                                        />
                                    )}
                                </ReviewCard>
                            ))}
                        </>
                    )}
                    {action === 'view' && section === 3 && (
                        <>
                            {detail.stock.length ? (
                                detail.stock.map((stock) => (
                                    <ReviewCard
                                        key={stock.id}
                                        icon={Package}
                                        title="Current supply"
                                    >
                                        <ReviewRow
                                            label="On hand"
                                            value={`${stock.on_hand} ${stock.unit}`}
                                        />
                                        <ReviewRow
                                            label="Batch"
                                            value={stock.batch_number}
                                        />
                                        <ReviewRow
                                            label="Expiry"
                                            value={formatDateOnly(
                                                stock.expiry_date?.slice(0, 10),
                                            )}
                                        />
                                    </ReviewCard>
                                ))
                            ) : (
                                <Note>
                                    No supply recorded for this medicine.
                                </Note>
                            )}
                            {canOpenStock && (
                                <Button asChild variant="outline">
                                    <a
                                        href={`/emar/stock/packs?client_id=${order.client_id}&medication_id=${order.id}`}
                                    >
                                        Open Stock & pharmacy
                                    </a>
                                </Button>
                            )}
                        </>
                    )}
                    {action === 'check' && selected && (
                        <>
                            <div className="grid gap-5 md:grid-cols-2">
                                <ReviewCard icon={Pill} title="Now">
                                    <ReviewRow
                                        label="Dose"
                                        value={
                                            order.approval_status === 'verified'
                                                ? detail.order.dosage
                                                : 'New order — no checked dose'
                                        }
                                    />
                                    <ReviewRow
                                        label="When"
                                        value={detail.order.frequency}
                                    />
                                    <ReviewRow
                                        label="Route"
                                        value={detail.order.route}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={ClipboardCheck}
                                    title={`To check · version ${selected.version.version_number}`}
                                >
                                    <ReviewRow
                                        label="Dose"
                                        value={
                                            selected.version
                                                .prescription_payload.dosage
                                        }
                                    />
                                    <ReviewRow
                                        label="When"
                                        value={
                                            selected.version
                                                .prescription_payload.frequency
                                        }
                                    />
                                    <ReviewRow
                                        label="Route"
                                        value={
                                            selected.version
                                                .prescription_payload.route
                                        }
                                    />
                                    <ReviewRow
                                        label="Times"
                                        value={selected.version.prescription_payload.dose_times?.join(
                                            ', ',
                                        )}
                                    />
                                    <ReviewRow
                                        label="Instructions"
                                        value={
                                            selected.version
                                                .prescription_payload
                                                .instructions
                                        }
                                    />
                                </ReviewCard>
                            </div>
                            <ReviewCard
                                icon={FileText}
                                title="Source and read-back"
                            >
                                <ReviewRow
                                    label="Source"
                                    value={`${selected.version.source_evidence.type} · ${selected.version.source_evidence.prescriber ?? 'Unknown'}`}
                                />
                                <ReviewRow
                                    label="Read-back witness"
                                    value={selected.witness?.name}
                                />
                                <SourceFiles files={selected.files ?? []} />
                            </ReviewCard>
                            <Note>
                                Recorded allergies:{' '}
                                {detail.allergies.recorded
                                    .map((entry) => entry.allergen)
                                    .join(', ') ||
                                    'Nothing recorded — not a confirmed allergy review.'}
                                {detail.allergies.class_matching ===
                                    'not_configured' && (
                                    <p>
                                        Drug-class matching: Not configured.
                                        Check against the source and all
                                        recorded allergies.
                                    </p>
                                )}
                            </Note>
                            {allergyBlocked && (
                                <Note>
                                    <StatusBadge variant="critical">
                                        Prescriber must confirm
                                    </StatusBadge>
                                    <p>
                                        This version cannot be checked until the
                                        prescriber confirms it is safe for the
                                        recorded allergy match.
                                    </p>
                                    {order.can_manage && (
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                choose('allergy', selected)
                                            }
                                        >
                                            Record the prescriber’s confirmation
                                        </Button>
                                    )}
                                </Note>
                            )}
                            {involved && form.data.mode !== 'second' ? (
                                <>
                                    <Note>
                                        {selected.entered_by === me
                                            ? 'You entered this version. Someone else normally checks it.'
                                            : 'You witnessed the read-back. Someone else must check it.'}
                                    </Note>
                                    {selected.entered_by === me &&
                                        canManage && (
                                            <>
                                                <TilePicker
                                                    value={form.data.mode}
                                                    onChange={(value) =>
                                                        form.setData(
                                                            'mode',
                                                            value,
                                                        )
                                                    }
                                                    options={[
                                                        {
                                                            key: 'independent',
                                                            label: 'Someone else checks',
                                                            description:
                                                                'Close and ask an authorised colleague',
                                                            icon: ShieldCheck,
                                                        },
                                                        {
                                                            key: 'alone',
                                                            label: 'Nobody else can check today',
                                                            description:
                                                                'A second check is due tomorrow',
                                                            icon: CheckCheck,
                                                        },
                                                    ]}
                                                />
                                                {form.data.mode === 'alone' && (
                                                    <Field
                                                        id="lone-reason"
                                                        label="Why nobody else can check today"
                                                    >
                                                        <Textarea
                                                            id="lone-reason"
                                                            value={
                                                                form.data
                                                                    .lone_reason
                                                            }
                                                            onChange={(event) =>
                                                                form.setData(
                                                                    'lone_reason',
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                    </Field>
                                                )}
                                            </>
                                        )}
                                </>
                            ) : (
                                form.data.mode !== 'second' && (
                                    <TilePicker
                                        value={form.data.mode}
                                        onChange={(value) =>
                                            form.setData('mode', value)
                                        }
                                        options={[
                                            {
                                                key: 'independent',
                                                label: 'Checked',
                                                description:
                                                    'It matches the source',
                                                icon: ShieldCheck,
                                            },
                                            {
                                                key: 'send_back',
                                                label: 'Send it back',
                                                description:
                                                    'Say what needs fixing',
                                                icon: XCircle,
                                            },
                                        ]}
                                    />
                                )
                            )}
                            {form.data.mode === 'send_back' ? (
                                <Field
                                    id="send-back-reason"
                                    label="What needs fixing"
                                >
                                    <Textarea
                                        id="send-back-reason"
                                        value={form.data.reason}
                                        onChange={(event) =>
                                            form.setData(
                                                'reason',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            ) : (
                                <>
                                    {(
                                        [
                                            [
                                                'source_matches',
                                                'It matches the prescriber’s source',
                                            ],
                                            [
                                                'dose_route_times_checked',
                                                'Dose, route and times are right',
                                            ],
                                            [
                                                'allergies_interactions_checked',
                                                'Allergies and other medicines checked',
                                            ],
                                        ] as const
                                    ).map(([key, label]) => (
                                        <label
                                            key={key}
                                            className="frontline-tap flex items-center gap-2"
                                        >
                                            <Checkbox
                                                checked={form.data[key]}
                                                onCheckedChange={(value) =>
                                                    form.setData(
                                                        key,
                                                        value === true,
                                                    )
                                                }
                                            />
                                            {label}
                                        </label>
                                    ))}
                                </>
                            )}
                        </>
                    )}
                    {action === 'allergy' && (
                        <>
                            <Field id="confirm-prescriber" label="Prescriber">
                                <Input
                                    id="confirm-prescriber"
                                    value={form.data.prescriber}
                                    onChange={(event) =>
                                        form.setData(
                                            'prescriber',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <TilePicker
                                value={form.data.method}
                                onChange={(value) =>
                                    form.setData('method', value)
                                }
                                options={[
                                    {
                                        key: 'phone',
                                        label: 'By phone',
                                        icon: Phone,
                                    },
                                    {
                                        key: 'written',
                                        label: 'In writing',
                                        icon: FileText,
                                    },
                                    {
                                        key: 'in_person',
                                        label: 'In person',
                                        icon: Pill,
                                    },
                                ]}
                            />
                            <DateTimeField
                                compact
                                id="allergy-confirmed-at"
                                label="Confirmed"
                                value={form.data.confirmed_at}
                                clearable={false}
                                onChange={(value) =>
                                    form.setData('confirmed_at', value)
                                }
                            />
                            <Field
                                id="allergy-instruction"
                                label="What the prescriber said"
                            >
                                <Textarea
                                    id="allergy-instruction"
                                    value={form.data.instruction}
                                    onChange={(event) =>
                                        form.setData(
                                            'instruction',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Note>
                                This confirmation stays with this version. It
                                does not change the person’s allergy list.
                            </Note>
                        </>
                    )}
                    {action === 'written' && (
                        <>
                            <TilePicker
                                value={form.data.method}
                                onChange={(value) =>
                                    form.setData('method', value)
                                }
                                options={[
                                    {
                                        key: 'signed_prescription',
                                        label: 'Signed prescription',
                                        icon: FileText,
                                    },
                                    {
                                        key: 'email',
                                        label: 'Prescriber’s email',
                                        icon: FileText,
                                    },
                                    {
                                        key: 'e_prescription',
                                        label: 'E-prescription',
                                        icon: FileText,
                                    },
                                ]}
                            />
                            <DateTimeField
                                compact
                                id="written-received"
                                label="Received"
                                value={form.data.received_at}
                                clearable={false}
                                onChange={(value) =>
                                    form.setData('received_at', value)
                                }
                            />
                            <FileDropzone
                                multiple={false}
                                accept=".pdf,.jpg,.jpeg,.png"
                                title="Attach the prescriber’s written confirmation"
                                hint="PDF, JPG or PNG · up to 10 MB"
                                onFiles={(files) =>
                                    form.setData('file', files[0] ?? null)
                                }
                            />
                            {form.data.file && (
                                <StagedFileCard
                                    file={form.data.file}
                                    onRemove={() => form.setData('file', null)}
                                />
                            )}
                            <label className="frontline-tap flex items-center gap-2">
                                <Checkbox
                                    checked={form.data.matches}
                                    onCheckedChange={(value) =>
                                        form.setData('matches', value === true)
                                    }
                                />
                                It matches the phone or verbal order.
                            </label>
                            <Note>
                                If it differs, enter a change from this source
                                and have it checked.
                            </Note>
                        </>
                    )}
                    {['stop', 'hold', 'resume'].includes(action) && (
                        <>
                            <Field
                                id="lifecycle-reason"
                                label={
                                    action === 'stop'
                                        ? 'Who instructed the stop, and why?'
                                        : 'Prescriber’s instruction and reason'
                                }
                            >
                                <Textarea
                                    id="lifecycle-reason"
                                    value={form.data.reason}
                                    onChange={(event) =>
                                        form.setData(
                                            'reason',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Note>
                                {action === 'stop'
                                    ? 'The order stops straight away. Its reason, actor, time and earlier versions are kept. Restarting a stopped medicine needs a new checked order.'
                                    : action === 'hold'
                                      ? 'No doses are owed while held. The checked prescription is kept.'
                                      : 'This returns to the checked prescription. A changed dose needs a new version and a check.'}
                            </Note>
                        </>
                    )}
                </div>
            </WizardShell>
            {close.confirm}
            <ConfirmDialog
                frontline
                open={confirmStop}
                onClose={() => setConfirmStop(false)}
                title={`Stop ${order.name}?`}
                description="It stops straight away. The order and every version stay in the history."
                confirmText="Stop order"
                processing={form.processing}
                onConfirm={save}
            />
        </>
    );
}
