import { ConfirmDialog } from '@/components/confirm-dialog';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import { RecordPicker } from '@/components/people-locations/record-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { TilePicker } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import { formatDateTime, toDatetimeLocal } from '@/lib/datetime';
import { useForm } from '@inertiajs/react';
import axios from 'axios';
import {
    ArrowLeftRight,
    Check,
    ClipboardCheck,
    FileText,
    HelpCircle,
    Pill,
    Plus,
    XCircle,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { Errors, Field, Note, useDraftClose } from './_parts';
import type {
    ClientChoice,
    Order,
    Reconciliation,
    ReconciliationItem,
    RespiteStayChoice,
} from './_types';

export function StartReconciliation({
    clients,
    stays,
    canControlled,
    onClose,
}: {
    clients: ClientChoice[];
    stays: RespiteStayChoice[];
    canControlled: boolean;
    onClose: () => void;
}) {
    const [step, setStep] = useState(0);
    const form = useForm({
        client_id: '',
        reason: 'hospital_discharge',
        sources: '',
        respite_stay_id: '',
        source_medicines: [] as {
            name: string;
            controlled: boolean;
            notes: string;
        }[],
    });
    const close = useDraftClose(form.isDirty, form.processing, onClose);
    const steps = [
        {
            key: 'who',
            label: 'Who and why',
            blurb: 'Person and transition',
            icon: ArrowLeftRight,
        },
        {
            key: 'sources',
            label: 'The sources',
            blurb: 'Include every medicine',
            icon: FileText,
        },
        {
            key: 'review',
            label: 'Review & start',
            blurb: 'Match medicines next',
            icon: ClipboardCheck,
        },
    ];
    return (
        <>
            <WizardShell
                frontline
                open
                onClose={close.close}
                title="Reconcile medicines"
                description="Compare the chart with the prescribing and transfer sources. Do not make a clinical decision from missing information."
                railIcon={ArrowLeftRight}
                railTitle="Reconcile medicines"
                railSub="Transition of care"
                steps={steps}
                stepIndex={step}
                onStepClick={setStep}
                pct={Math.round(
                    ([form.data.client_id, form.data.sources].filter(Boolean)
                        .length /
                        2) *
                        100,
                )}
                footerStart={
                    <Button
                        variant="outline"
                        onClick={step ? () => setStep(step - 1) : close.close}
                    >
                        {step ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <Button
                        disabled={form.processing}
                        onClick={
                            step < 2
                                ? () => setStep(step + 1)
                                : () =>
                                      form.post('/emar/reconciliations', {
                                          preserveScroll: true,
                                          onSuccess: onClose,
                                      })
                        }
                    >
                        {step < 2
                            ? 'Continue'
                            : form.processing
                              ? 'Saving…'
                              : 'Start matching'}
                    </Button>
                }
            >
                <div className="grid gap-5">
                    <Errors errors={form.errors} />
                    {step === 0 && (
                        <>
                            <Field id="rec-person" label="Person">
                                <RecordPicker
                                    label="Person"
                                    value={form.data.client_id}
                                    options={clients
                                        .filter((client) => client.can_enter)
                                        .map((client) => ({
                                            value: String(client.id),
                                            label: client.name,
                                        }))}
                                    onChange={(value) =>
                                        form.setData('client_id', value)
                                    }
                                />
                            </Field>
                            <TilePicker
                                value={form.data.reason}
                                onChange={(value) =>
                                    form.setData('reason', value)
                                }
                                options={[
                                    {
                                        key: 'moving_in',
                                        label: 'Moving in',
                                        icon: ArrowLeftRight,
                                    },
                                    {
                                        key: 'hospital_discharge',
                                        label: 'Back from hospital',
                                        icon: ArrowLeftRight,
                                    },
                                    {
                                        key: 'respite_arriving',
                                        label: 'Respite arriving',
                                        icon: ArrowLeftRight,
                                    },
                                    {
                                        key: 'respite_leaving',
                                        label: 'Respite leaving',
                                        icon: ArrowLeftRight,
                                    },
                                    {
                                        key: 'house_move',
                                        label: 'House move',
                                        icon: ArrowLeftRight,
                                    },
                                ]}
                            />
                            {form.data.reason.startsWith('respite') && (
                                <Field id="rec-stay" label="Respite stay">
                                    <RecordPicker
                                        label="Respite stay"
                                        value={form.data.respite_stay_id}
                                        options={stays
                                            .filter(
                                                (stay) =>
                                                    String(stay.client_id) ===
                                                    form.data.client_id,
                                            )
                                            .map((stay) => ({
                                                value: String(stay.id),
                                                label: `${stay.status.replaceAll('_', ' ')} · ${stay.actual_start ? formatDateTime(stay.actual_start) : 'Arrival not recorded'}`,
                                            }))}
                                        onChange={(value) =>
                                            form.setData(
                                                'respite_stay_id',
                                                value,
                                            )
                                        }
                                    />
                                </Field>
                            )}
                        </>
                    )}
                    {step === 1 && (
                        <>
                            <Field
                                id="rec-sources"
                                label="Sources checked — who, when and which records"
                            >
                                <Textarea
                                    id="rec-sources"
                                    rows={5}
                                    value={form.data.sources}
                                    onChange={(event) =>
                                        form.setData(
                                            'sources',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="GP prescription, hospital discharge list, pharmacy pack, sending house record… Include dates and where the originals can be opened."
                                />
                            </Field>
                            <Note>
                                Every current chart medicine is included
                                automatically. Add every medicine on the source
                                that is missing from the chart.
                            </Note>
                            {form.data.source_medicines.map(
                                (medicine, index) => (
                                    <div
                                        key={index}
                                        className="grid gap-2 rounded-xl border p-3"
                                    >
                                        <Field
                                            id={`rec-source-${index}`}
                                            label="Medicine on the source"
                                        >
                                            <Input
                                                id={`rec-source-${index}`}
                                                value={medicine.name}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'source_medicines',
                                                        form.data.source_medicines.map(
                                                            (entry, at) =>
                                                                at === index
                                                                    ? {
                                                                          ...entry,
                                                                          name: event
                                                                              .target
                                                                              .value,
                                                                      }
                                                                    : entry,
                                                        ),
                                                    )
                                                }
                                            />
                                        </Field>
                                        {canControlled && (
                                            <label className="frontline-tap flex items-center gap-2">
                                                <Checkbox
                                                    checked={
                                                        medicine.controlled
                                                    }
                                                    onCheckedChange={(value) =>
                                                        form.setData(
                                                            'source_medicines',
                                                            form.data.source_medicines.map(
                                                                (entry, at) =>
                                                                    at === index
                                                                        ? {
                                                                              ...entry,
                                                                              controlled:
                                                                                  value ===
                                                                                  true,
                                                                          }
                                                                        : entry,
                                                            ),
                                                        )
                                                    }
                                                />
                                                Controlled medicine
                                            </label>
                                        )}
                                        <Button
                                            variant="ghost"
                                            onClick={() =>
                                                form.setData(
                                                    'source_medicines',
                                                    form.data.source_medicines.filter(
                                                        (_, at) => at !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            Remove unsaved entry
                                        </Button>
                                    </div>
                                ),
                            )}
                            <Button
                                variant="outline"
                                onClick={() =>
                                    form.setData('source_medicines', [
                                        ...form.data.source_medicines,
                                        {
                                            name: '',
                                            controlled: false,
                                            notes: '',
                                        },
                                    ])
                                }
                            >
                                <Plus className="size-4" />
                                Add a medicine from the source
                            </Button>
                        </>
                    )}
                    {step === 2 && (
                        <>
                            <ReviewCard
                                icon={ArrowLeftRight}
                                title="Who and why"
                                onEdit={() => setStep(0)}
                            >
                                <ReviewRow
                                    label="Person"
                                    value={
                                        clients.find(
                                            (client) =>
                                                String(client.id) ===
                                                form.data.client_id,
                                        )?.name
                                    }
                                />
                                <ReviewRow
                                    label="Transition"
                                    value={form.data.reason.replaceAll(
                                        '_',
                                        ' ',
                                    )}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={FileText}
                                title="Sources"
                                onEdit={() => setStep(1)}
                            >
                                <p className="text-sm whitespace-pre-wrap">
                                    {form.data.sources}
                                </p>
                                <ReviewRow
                                    label="Additional medicines"
                                    value={
                                        form.data.source_medicines
                                            .map((medicine) => medicine.name)
                                            .join(', ') || 'None entered'
                                    }
                                />
                            </ReviewCard>
                            <Note>
                                This starts a reconciliation. It does not change
                                an order or authorise a dose.
                            </Note>
                        </>
                    )}
                </div>
            </WizardShell>
            {close.confirm}
        </>
    );
}

export function ReconcileMedicines({
    record,
    orders,
    onClose,
    onEnter,
}: {
    record: Reconciliation;
    orders: Order[];
    onClose: () => void;
    onEnter: (orderId: number | null, clientId: number) => void;
}) {
    const [step, setStep] = useState(0);
    const [matched, setMatched] = useState(false);
    const [candidates, setCandidates] = useState(orders);
    const [candidateError, setCandidateError] = useState(false);
    const [queryItem, setQueryItem] = useState<ReconciliationItem | null>(null);
    const [confirmApply, setConfirmApply] = useState(false);
    const form = useForm({
        items: record.items
            .filter((item) => !item.applied_at)
            .map((item) => ({
                id: item.id,
                decision: item.decision ?? '',
                notes: item.notes ?? '',
                next_dose_at: item.next_dose_at
                    ? toDatetimeLocal(item.next_dose_at)
                    : '',
                external_last_dose: null as {
                    given_at: string;
                    source: string;
                    dose_given: string;
                } | null,
            })),
        revision_ids: {} as Record<number, string>,
    });
    const close = useDraftClose(form.isDirty, form.processing, onClose);
    const steps = [
        {
            key: 'sources',
            label: 'The sources',
            blurb: 'Who and what was compared',
            icon: FileText,
        },
        {
            key: 'match',
            label: 'Match each medicine',
            blurb: 'Decide from the prescriber’s source',
            icon: Pill,
        },
        {
            key: 'changes',
            label: 'What changes',
            blurb: 'Order versions go to be checked',
            icon: ArrowLeftRight,
        },
        {
            key: 'sign-off',
            label: 'Sign off',
            blurb: 'Every medicine matched',
            icon: ClipboardCheck,
        },
    ];
    const saveMatching = (after?: () => void) =>
        form.put(`/emar/reconciliations/${record.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setMatched(true);
                after?.();
            },
        });
    const row = (id: number) => form.data.items.find((item) => item.id === id);
    const setItem = (
        id: number,
        change: Partial<(typeof form.data.items)[number]>,
    ) =>
        form.setData(
            'items',
            form.data.items.map((item) =>
                item.id === id ? { ...item, ...change } : item,
            ),
        );
    const apply = () =>
        record.items.some(
            (item) =>
                (row(item.id)?.decision ?? item.decision) === 'stop' &&
                !item.applied_at,
        )
            ? setConfirmApply(true)
            : form.post(`/emar/reconciliations/${record.id}/apply`, {
                  preserveScroll: true,
                  onSuccess: onClose,
              });
    const signOff = () =>
        form.post(`/emar/reconciliations/${record.id}/sign-off`, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    useEffect(() => {
        const controller = new AbortController();
        axios
            .get<{ orders: Order[] }>(
                `/emar/orders/candidates/${record.client_id}`,
                { signal: controller.signal },
            )
            .then(({ data }) => setCandidates(data.orders))
            .catch(() => {
                if (!controller.signal.aborted) setCandidateError(true);
            });
        return () => controller.abort();
    }, [record.client_id]);
    if (queryItem)
        return (
            <PrescriberResponse
                record={record}
                item={queryItem}
                onClose={() => setQueryItem(null)}
                onSaved={onClose}
            />
        );
    return (
        <>
            <WizardShell
                frontline
                open
                onClose={close.close}
                title="Reconcile medicines"
                description="Compare every medicine and record the source for the last dose. Changes need their own independent check."
                railIcon={ArrowLeftRight}
                railTitle="Reconcile medicines"
                railSub={`${record.client.first_name} ${record.client.last_name}`}
                steps={steps}
                stepIndex={step}
                onStepClick={setStep}
                pct={Math.round(
                    (record.items.filter(
                        (item) => item.applied_at || row(item.id)?.decision,
                    ).length /
                        Math.max(record.items.length, 1)) *
                        100,
                )}
                footerStart={
                    <Button
                        variant="outline"
                        onClick={step ? () => setStep(step - 1) : close.close}
                    >
                        {step ? 'Back' : 'Close'}
                    </Button>
                }
                footerEnd={
                    record.signed_off_at ? (
                        <StatusBadge variant="success">
                            Signed off {formatDateTime(record.signed_off_at)}
                        </StatusBadge>
                    ) : (
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                disabled={
                                    form.processing ||
                                    !record.can_manage ||
                                    record.status === 'changes_applied'
                                }
                                onClick={() => saveMatching()}
                            >
                                Save and finish later
                            </Button>
                            <Button
                                disabled={
                                    form.processing ||
                                    !record.can_manage ||
                                    (step >= 2 && !record.can_apply) ||
                                    (step === 3 &&
                                        (!record.can_sign_off ||
                                            record.status !==
                                                'changes_applied'))
                                }
                                onClick={
                                    step === 0
                                        ? () => setStep(1)
                                        : step === 1
                                          ? () => saveMatching(() => setStep(2))
                                          : step === 2
                                            ? apply
                                            : signOff
                                }
                            >
                                {form.processing
                                    ? 'Saving…'
                                    : step < 2
                                      ? 'Continue'
                                      : step === 2
                                        ? 'Apply matched decisions'
                                        : 'Sign off reconciliation'}
                            </Button>
                        </div>
                    )
                }
            >
                <div className="grid gap-5">
                    <Errors errors={form.errors} />
                    {record.restricted_medicines && (
                        <Note>
                            Controlled medicines are left out. A colleague with
                            controlled-medicine access must match and sign off
                            the full reconciliation.
                        </Note>
                    )}
                    {!record.restricted_medicines &&
                        record.can_manage &&
                        !record.can_apply && (
                            <Note>
                                An authorised colleague must apply and sign off
                                the controlled-medicine decisions in this
                                reconciliation.
                            </Note>
                        )}
                    {step === 0 && (
                        <ReviewCard
                            icon={FileText}
                            title="Sources and transition"
                        >
                            <ReviewRow
                                label="Reason"
                                value={record.reason.replaceAll('_', ' ')}
                            />
                            <p className="text-sm whitespace-pre-wrap">
                                {record.sources}
                            </p>
                        </ReviewCard>
                    )}
                    {step === 1 && (
                        <>
                            {record.items.map((item) => (
                                <ReviewCard
                                    key={item.id}
                                    icon={Pill}
                                    title={item.medicine_name}
                                >
                                    <ReviewRow
                                        label="Chart"
                                        value={
                                            item.client_medication_id
                                                ? 'On our chart'
                                                : 'Not on our chart — from the source'
                                        }
                                    />
                                    <ReviewRow
                                        label="Last dose actually given"
                                        value={
                                            item.last_dose_evidence.source ===
                                            'emar'
                                                ? `${item.last_dose_evidence.dose_given} · ${formatDateTime(item.last_dose_evidence.given_at)} · eMAR record ${item.last_dose_evidence.administration_id}`
                                                : 'Not recorded in eMAR — confirm with the source'
                                        }
                                    />
                                    {item.last_dose_evidence.external && (
                                        <ReviewRow
                                            label="External last-dose source"
                                            value={`${item.last_dose_evidence.external.source} · ${formatDateTime(item.last_dose_evidence.external.given_at)} · ${item.last_dose_evidence.external.dose_given}`}
                                        />
                                    )}
                                    {item.applied_at || record.signed_off_at ? (
                                        <div>
                                            <ReviewRow
                                                label="Decision"
                                                value={item.decision}
                                            />
                                            {item.decision === 'ask' &&
                                                !item.prescriber_query_resolved_at &&
                                                record.can_sign_off && (
                                                    <Button
                                                        variant="outline"
                                                        onClick={() =>
                                                            setQueryItem(item)
                                                        }
                                                    >
                                                        Record the prescriber
                                                        response
                                                    </Button>
                                                )}
                                            {item.prescriber_query_resolved_at && (
                                                <p className="text-caption">
                                                    Prescriber response recorded{' '}
                                                    {formatDateTime(
                                                        item.prescriber_query_resolved_at,
                                                    )}
                                                </p>
                                            )}
                                        </div>
                                    ) : (
                                        <>
                                            <TilePicker
                                                value={
                                                    row(item.id)?.decision ?? ''
                                                }
                                                onChange={(value) =>
                                                    setItem(item.id, {
                                                        decision: value,
                                                    })
                                                }
                                                options={[
                                                    ...(item.client_medication_id
                                                        ? [
                                                              {
                                                                  key: 'continue',
                                                                  label: 'Continue',
                                                                  icon: Check,
                                                              },
                                                              {
                                                                  key: 'change',
                                                                  label: 'Change',
                                                                  icon: ArrowLeftRight,
                                                              },
                                                              {
                                                                  key: 'stop',
                                                                  label: 'Stop',
                                                                  icon: XCircle,
                                                              },
                                                          ]
                                                        : [
                                                              {
                                                                  key: 'start',
                                                                  label: 'Start a new order',
                                                                  icon: Plus,
                                                              },
                                                          ]),
                                                    {
                                                        key: 'ask',
                                                        label: 'Ask the prescriber first',
                                                        icon: HelpCircle,
                                                    },
                                                ]}
                                            />
                                            <Field
                                                id={`rec-note-${item.id}`}
                                                label="Source, decision and what needs checking"
                                            >
                                                <Textarea
                                                    id={`rec-note-${item.id}`}
                                                    value={
                                                        row(item.id)?.notes ??
                                                        ''
                                                    }
                                                    onChange={(event) =>
                                                        setItem(item.id, {
                                                            notes: event.target
                                                                .value,
                                                        })
                                                    }
                                                />
                                            </Field>
                                            {row(item.id)?.decision ===
                                                'ask' && (
                                                <DateTimeField
                                                    compact
                                                    id={`rec-next-${item.id}`}
                                                    label="Next affected dose due"
                                                    value={
                                                        row(item.id)
                                                            ?.next_dose_at ?? ''
                                                    }
                                                    clearable={false}
                                                    onChange={(value) =>
                                                        setItem(item.id, {
                                                            next_dose_at: value,
                                                        })
                                                    }
                                                />
                                            )}
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    setItem(item.id, {
                                                        external_last_dose: row(
                                                            item.id,
                                                        )?.external_last_dose
                                                            ? null
                                                            : {
                                                                  given_at: '',
                                                                  source: '',
                                                                  dose_given:
                                                                      '',
                                                              },
                                                    })
                                                }
                                            >
                                                {row(item.id)
                                                    ?.external_last_dose
                                                    ? 'Cancel external last-dose entry'
                                                    : 'Record last dose from an external source'}
                                            </Button>
                                            {row(item.id)
                                                ?.external_last_dose && (
                                                <div className="mt-3 grid gap-3">
                                                    <Field
                                                        id={`last-source-${item.id}`}
                                                        label="Who confirmed the last dose, and from which record?"
                                                    >
                                                        <Input
                                                            id={`last-source-${item.id}`}
                                                            value={
                                                                row(item.id)!
                                                                    .external_last_dose!
                                                                    .source
                                                            }
                                                            onChange={(event) =>
                                                                setItem(
                                                                    item.id,
                                                                    {
                                                                        external_last_dose:
                                                                            {
                                                                                ...row(
                                                                                    item.id,
                                                                                )!
                                                                                    .external_last_dose!,
                                                                                source: event
                                                                                    .target
                                                                                    .value,
                                                                            },
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    </Field>
                                                    <DateTimeField
                                                        compact
                                                        id={`last-dose-at-${item.id}`}
                                                        label="Last dose actually given"
                                                        value={
                                                            row(item.id)!
                                                                .external_last_dose!
                                                                .given_at
                                                        }
                                                        onChange={(value) =>
                                                            setItem(item.id, {
                                                                external_last_dose:
                                                                    {
                                                                        ...row(
                                                                            item.id,
                                                                        )!
                                                                            .external_last_dose!,
                                                                        given_at:
                                                                            value,
                                                                    },
                                                            })
                                                        }
                                                    />
                                                    <Field
                                                        id={`last-dose-${item.id}`}
                                                        label="Amount given"
                                                    >
                                                        <Input
                                                            id={`last-dose-${item.id}`}
                                                            value={
                                                                row(item.id)!
                                                                    .external_last_dose!
                                                                    .dose_given
                                                            }
                                                            onChange={(event) =>
                                                                setItem(
                                                                    item.id,
                                                                    {
                                                                        external_last_dose:
                                                                            {
                                                                                ...row(
                                                                                    item.id,
                                                                                )!
                                                                                    .external_last_dose!,
                                                                                dose_given:
                                                                                    event
                                                                                        .target
                                                                                        .value,
                                                                            },
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    </Field>
                                                </div>
                                            )}
                                        </>
                                    )}
                                </ReviewCard>
                            ))}
                        </>
                    )}
                    {step === 2 && (
                        <>
                            {candidateError && (
                                <Note>
                                    Waiting versions could not be refreshed.
                                    Reopen this reconciliation before linking a
                                    version.
                                </Note>
                            )}
                            <Note>
                                New orders and changes are entered from their
                                source. Their waiting versions are linked here;
                                someone else checks them. Stops take effect when
                                these decisions are applied. Support is flagged
                                for reassessment.
                            </Note>
                            {!matched && record.status === 'draft' && (
                                <Note>
                                    Save the matching decisions before applying
                                    them.
                                </Note>
                            )}
                            {record.items
                                .filter((item) =>
                                    ['change', 'start'].includes(
                                        row(item.id)?.decision ??
                                            item.decision ??
                                            '',
                                    ),
                                )
                                .map((item) => (
                                    <ReviewCard
                                        key={item.id}
                                        icon={ArrowLeftRight}
                                        title={item.medicine_name}
                                    >
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                saveMatching(() =>
                                                    onEnter(
                                                        item.client_medication_id,
                                                        record.client_id,
                                                    ),
                                                )
                                            }
                                        >
                                            Enter{' '}
                                            {item.client_medication_id
                                                ? 'the change'
                                                : 'a new order'}{' '}
                                            from its source
                                        </Button>
                                        <div className="mt-3">
                                            <RecordPicker
                                                label="Waiting order version"
                                                value={
                                                    form.data.revision_ids[
                                                        item.id
                                                    ] ?? ''
                                                }
                                                options={candidates
                                                    .filter(
                                                        (order) =>
                                                            order.client_id ===
                                                                record.client_id &&
                                                            order.pending
                                                                ?.status ===
                                                                'pending' &&
                                                            (!item.client_medication_id ||
                                                                order.id ===
                                                                    item.client_medication_id),
                                                    )
                                                    .map((order) => ({
                                                        value: String(
                                                            order.pending!.id,
                                                        ),
                                                        label: `${order.name} · version ${order.pending!.version.version_number}`,
                                                    }))}
                                                onChange={(value) =>
                                                    form.setData(
                                                        'revision_ids',
                                                        {
                                                            ...form.data
                                                                .revision_ids,
                                                            [item.id]: value,
                                                        },
                                                    )
                                                }
                                            />
                                        </div>
                                    </ReviewCard>
                                ))}
                        </>
                    )}
                    {step === 3 && (
                        <>
                            <ReviewCard icon={ClipboardCheck} title="Sign off">
                                <ReviewRow
                                    label="Medicines"
                                    value={`${record.items.length} visible medicines matched`}
                                />
                                <ReviewRow
                                    label="Order changes"
                                    value="Remain waiting until their independent check"
                                />
                                <ReviewRow
                                    label="Support"
                                    value={
                                        record.support_reassessment_required
                                            ? 'Reassessment required'
                                            : 'No reassessment flag recorded'
                                    }
                                />
                            </ReviewCard>
                            <Note>
                                Signing off confirms that every medicine has
                                been matched to the sources. Unresolved
                                prescriber queries and unchecked changes keep
                                respite arrival reconciliation incomplete.
                            </Note>
                            {record.status !== 'changes_applied' &&
                                !record.signed_off_at && (
                                    <Note>
                                        Apply the matched decisions first.
                                    </Note>
                                )}
                        </>
                    )}
                </div>
            </WizardShell>
            {close.confirm}
            <ConfirmDialog
                frontline
                open={confirmApply}
                onClose={() => setConfirmApply(false)}
                title="Apply these stop decisions?"
                description="The selected stopped orders end immediately. Their sources and dose history are kept."
                confirmText="Apply matched decisions"
                processing={form.processing}
                onConfirm={() =>
                    form.post(`/emar/reconciliations/${record.id}/apply`, {
                        preserveScroll: true,
                        onSuccess: onClose,
                    })
                }
            />
        </>
    );
}

function PrescriberResponse({
    record,
    item,
    onClose,
    onSaved,
}: {
    record: Reconciliation;
    item: ReconciliationItem;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [step, setStep] = useState(0);
    const form = useForm({
        prescriber: '',
        method: 'phone',
        confirmed_at: toDatetimeLocal(new Date()),
        instruction: '',
    });
    const close = useDraftClose(form.isDirty, form.processing, onClose);
    return (
        <>
            <WizardShell
                frontline
                open
                onClose={close.close}
                title="Record the prescriber’s response"
                description="Keep the prescriber’s instruction with this reconciliation query. Order changes still require their own source and check."
                railIcon={HelpCircle}
                railTitle="Prescriber’s response"
                railSub={item.medicine_name}
                steps={[
                    {
                        key: 'response',
                        label: 'The response',
                        blurb: 'Prescriber and instruction',
                        icon: FileText,
                    },
                    {
                        key: 'review',
                        label: 'Review & save',
                        blurb: 'Keep the response with this query',
                        icon: ClipboardCheck,
                    },
                ]}
                stepIndex={step}
                onStepClick={setStep}
                footerStart={
                    <Button
                        variant="outline"
                        onClick={step ? () => setStep(0) : close.close}
                    >
                        {step ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <Button
                        disabled={form.processing}
                        onClick={
                            step === 0
                                ? () => setStep(1)
                                : () =>
                                      form.post(
                                          `/emar/reconciliations/${record.id}/items/${item.id}/query`,
                                          {
                                              preserveScroll: true,
                                              onSuccess: onSaved,
                                          },
                                      )
                        }
                    >
                        {step === 0 ? 'Continue' : 'Save the response'}
                    </Button>
                }
            >
                <div className="grid gap-5">
                    <Errors errors={form.errors} />
                    <Note>{item.notes}</Note>
                    {step === 0 ? (
                        <>
                            <Field id="query-prescriber" label="Prescriber">
                                <Input
                                    id="query-prescriber"
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
                                        label: 'Phone',
                                        icon: FileText,
                                    },
                                    {
                                        key: 'written',
                                        label: 'Written',
                                        icon: FileText,
                                    },
                                    {
                                        key: 'in_person',
                                        label: 'In person',
                                        icon: FileText,
                                    },
                                ]}
                            />
                            <DateTimeField
                                compact
                                id="query-time"
                                label="When they confirmed"
                                clearable={false}
                                value={form.data.confirmed_at}
                                onChange={(value) =>
                                    form.setData('confirmed_at', value)
                                }
                            />
                            <Field
                                id="query-instruction"
                                label="Their instruction and source"
                            >
                                <Textarea
                                    id="query-instruction"
                                    value={form.data.instruction}
                                    onChange={(event) =>
                                        form.setData(
                                            'instruction',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </>
                    ) : (
                        <>
                            <ReviewCard
                                icon={FileText}
                                title="Prescriber’s response"
                            >
                                <ReviewRow
                                    label="Prescriber"
                                    value={form.data.prescriber}
                                />
                                <ReviewRow
                                    label="Instruction"
                                    value={form.data.instruction}
                                />
                                <ReviewRow
                                    label="When"
                                    value={`${form.data.confirmed_at.replace('T', ' · ')} · Pacific/Auckland`}
                                />
                            </ReviewCard>
                            <Note>
                                This closes the query. Enter any new order or
                                change from its source and arrange its
                                independent check before the affected dose.
                            </Note>
                        </>
                    )}
                </div>
            </WizardShell>
            {close.confirm}
        </>
    );
}
