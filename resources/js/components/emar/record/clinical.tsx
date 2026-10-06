import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { EntityTable } from '@/components/lists/entity-table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import {
    SyringeDriverDialog,
    type ChartMedicationOption,
} from '@/pages/emar/components/mar-governance-dialogs';
import type { WitnessOption } from '@/pages/meds/today/types';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import {
    Activity,
    ClipboardCheck,
    HeartPulse,
    Link2,
    Plus,
    Stethoscope,
    Syringe,
} from 'lucide-react';
import { useState } from 'react';
import { ReadingState } from './reading';
import { isConcealed, type MaybeConcealed } from './types';
import { concealedIdentity, FactStrip, recordDate, SectionCard } from './ui';
import { useDraftClose } from './use-draft-close';
import { useRecordJson } from './use-record-json';

type Inr = {
    key: string;
    id: number;
    medicine: string | null;
    medicine_id: number | null;
    value: number;
    tested: string;
    target_low: number | null;
    target_high: number | null;
    dose: string | null;
    next: string | null;
    notes: string | null;
    by: string | null;
    disabled: boolean;
    unlinked_reason: string | null;
    instruction: string | null;
    instruction_source: string | null;
    disabled_reason: string | null;
};
type Check = {
    id: number;
    at: string;
    by: string | null;
    running: boolean;
    site_condition: string | null;
    volume_remaining: string | null;
    notes: string | null;
};
type Driver = {
    key: string;
    id: number;
    status: string;
    commenced_at: string;
    by: string | null;
    rate: string | null;
    rate_unit: string | null;
    contents: {
        client_medication_id: number;
        name: string;
        dose: string;
        unit: string;
    }[];
    site_of_insertion: string | null;
    notes: string | null;
    checks: Check[];
};
type Observation = {
    key: string;
    medicine: string;
    at: string;
    by: string | null;
    glucose: number | null;
    pulse: number | null;
    systolic: number | null;
    diastolic: number | null;
};
type ClinicalData = {
    inr: { rows: MaybeConcealed<Inr>[]; hidden: number };
    drivers: MaybeConcealed<Driver>[];
    observations: { rows: MaybeConcealed<Observation>[]; hidden: number };
    medicines: ChartMedicationOption[];
    witnesses: WitnessOption[];
    can_manage: boolean;
    can_check: boolean;
};
type Command =
    | 'inr'
    | 'link-inr'
    | 'disable-inr'
    | 'check-driver'
    | 'finish-driver';

export function ClinicalSection({
    clientId,
    view,
}: {
    clientId: number;
    view: string;
}) {
    const { data, load, reload } = useRecordJson<ClinicalData>(
        `/emar/clients/${clientId}/record/clinical`,
    );
    const [command, setCommand] = useState<{
        key: Command;
        recordId?: number;
    } | null>(null);
    const [start, setStart] = useState(false);
    if (!data || load !== 'ready')
        return <ReadingState load={load} reload={reload} />;
    const done = () => {
        setCommand(null);
        setStart(false);
        reload();
    };
    const medicines = data.medicines.filter((medicine) =>
        /\bwarfarin\b/i.test(medicine.name),
    );
    const inrStatus = (result: Inr) =>
        result.disabled
            ? { label: 'Entered in error', tone: 'neutral' as const }
            : result.target_low === null || result.target_high === null
              ? { label: 'No target range recorded', tone: 'neutral' as const }
              : result.value < result.target_low
                ? { label: 'Below the target range', tone: 'warning' as const }
                : result.value > result.target_high
                  ? {
                        label: 'Above the target range',
                        tone: 'critical' as const,
                    }
                  : { label: 'In the target range', tone: 'success' as const };
    const latest = data.inr.rows.find(
        (result): result is Inr => !isConcealed(result) && !result.disabled,
    );
    return (
        <div className="space-y-5">
            {view === 'driver' ? (
                <>
                    <SectionCard
                        title="Syringe drivers"
                        icon={Syringe}
                        right={
                            data.can_manage && (
                                <Button
                                    variant="outline"
                                    onClick={() => setStart(true)}
                                >
                                    <Plus className="size-4" /> Start a syringe
                                    driver
                                </Button>
                            )
                        }
                    >
                        {!data.drivers.length && (
                            <p className="text-sm">
                                No syringe driver recorded.
                            </p>
                        )}
                        {data.drivers.map((driver) =>
                            isConcealed(driver) ? (
                                <p
                                    key={driver.key}
                                    className="rounded-lg border p-3 text-sm"
                                >
                                    A syringe-driver record contains controlled
                                    or unavailable medication details. The house
                                    lead can tell you more.
                                </p>
                            ) : (
                                <div
                                    key={driver.key}
                                    className="space-y-3 rounded-lg border p-4"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <h3 className="font-semibold">
                                            {driver.status === 'running'
                                                ? 'Running'
                                                : driver.status}{' '}
                                            · started{' '}
                                            {formatDateTime(
                                                driver.commenced_at,
                                            )}
                                        </h3>
                                        {driver.status === 'running' && (
                                            <div className="flex gap-2">
                                                {data.can_manage && (
                                                    <Button
                                                        variant="outline"
                                                        onClick={() =>
                                                            setCommand({
                                                                key: 'finish-driver',
                                                                recordId:
                                                                    driver.id,
                                                            })
                                                        }
                                                    >
                                                        Finish the driver
                                                    </Button>
                                                )}
                                                {data.can_check && (
                                                    <Button
                                                        onClick={() =>
                                                            setCommand({
                                                                key: 'check-driver',
                                                                recordId:
                                                                    driver.id,
                                                            })
                                                        }
                                                    >
                                                        <ClipboardCheck className="size-4" />{' '}
                                                        Record a check
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                    <FactStrip
                                        items={[
                                            {
                                                label: 'Rate',
                                                value: `${driver.rate ?? 'Not recorded'} ${driver.rate_unit ?? ''}`,
                                            },
                                            {
                                                label: 'Insertion site',
                                                value:
                                                    driver.site_of_insertion ??
                                                    'Not recorded',
                                            },
                                            {
                                                label: 'Last check',
                                                value: driver.checks[0]
                                                    ? formatDateTime(
                                                          driver.checks[0].at,
                                                      )
                                                    : 'No check recorded',
                                            },
                                        ]}
                                    />
                                    {driver.contents.map((item) => (
                                        <p
                                            key={item.client_medication_id}
                                            className="text-sm"
                                        >
                                            {item.name} · {item.dose}{' '}
                                            {item.unit}
                                        </p>
                                    ))}
                                    {driver.notes && (
                                        <p className="text-caption whitespace-pre-wrap">
                                            {driver.notes}
                                        </p>
                                    )}
                                    {driver.checks.length > 0 && (
                                        <EntityTable
                                            rows={driver.checks}
                                            rowKey={(check) => check.id}
                                            identityLabel="Check"
                                            identity={(check) => ({
                                                icon: ClipboardCheck,
                                                name: formatDateTime(check.at),
                                                subline: check.by,
                                            })}
                                            minWidth={750}
                                            actionsFor={() => []}
                                            columns={[
                                                {
                                                    key: 'running',
                                                    label: 'Running',
                                                    width: '1fr',
                                                    cell: (check) => (
                                                        <StatusBadge
                                                            variant={
                                                                check.running
                                                                    ? 'success'
                                                                    : 'critical'
                                                            }
                                                        >
                                                            {check.running
                                                                ? 'Running'
                                                                : 'Not running'}
                                                        </StatusBadge>
                                                    ),
                                                },
                                                {
                                                    key: 'site',
                                                    label: 'Site condition',
                                                    width: '1fr',
                                                    cell: (check) =>
                                                        check.site_condition ??
                                                        '—',
                                                },
                                                {
                                                    key: 'left',
                                                    label: 'Left in syringe',
                                                    width: '1fr',
                                                    cell: (check) =>
                                                        check.volume_remaining ??
                                                        '—',
                                                },
                                                {
                                                    key: 'notes',
                                                    label: 'Notes',
                                                    width: '1.5fr',
                                                    cell: (check) =>
                                                        check.notes ?? '—',
                                                },
                                            ]}
                                        />
                                    )}
                                </div>
                            ),
                        )}
                    </SectionCard>
                </>
            ) : view === 'observations' ? (
                <ObservationList
                    clientId={clientId}
                    rows={data.observations.rows}
                />
            ) : (
                <>
                    <SectionCard
                        icon={Activity}
                        title={
                            latest
                                ? `Latest INR · ${latest.value.toFixed(1)}`
                                : 'INR results'
                        }
                        right={
                            data.can_manage && (
                                <Button
                                    onClick={() => setCommand({ key: 'inr' })}
                                >
                                    <Plus className="size-4" /> Record INR
                                    result
                                </Button>
                            )
                        }
                    >
                        {latest ? (
                            <>
                                <StatusBadge variant={inrStatus(latest).tone}>
                                    {inrStatus(latest).label}
                                </StatusBadge>
                                <p className="text-sm">
                                    {latest.instruction ??
                                        'No instruction recorded'}
                                    {latest.instruction_source
                                        ? ` · ${latest.instruction_source}`
                                        : ''}
                                </p>
                                <FactStrip
                                    items={[
                                        {
                                            label: 'Tested',
                                            value: recordDate(latest.tested),
                                        },
                                        {
                                            label: 'Target from prescriber',
                                            value:
                                                latest.target_low !== null &&
                                                latest.target_high !== null
                                                    ? `${latest.target_low}–${latest.target_high}`
                                                    : 'Not recorded',
                                        },
                                        {
                                            label: 'Next test',
                                            value: recordDate(latest.next),
                                        },
                                    ]}
                                />
                                <p className="text-caption">
                                    {latest.medicine ??
                                        `No medicine linked — ${latest.unlinked_reason ?? 'no reason recorded'}`}{' '}
                                    · entered by {latest.by ?? 'Not recorded'}
                                </p>
                            </>
                        ) : (
                            <p className="text-sm">
                                No visible INR result recorded. Results without
                                a linked medicine are still shown.
                            </p>
                        )}
                    </SectionCard>
                    {data.inr.rows.length > 0 && (
                        <EntityTable
                            rows={data.inr.rows}
                            rowKey={(result) => result.key}
                            identityLabel="Result"
                            identity={(result) =>
                                isConcealed(result)
                                    ? concealedIdentity
                                    : {
                                          icon: HeartPulse,
                                          name: `INR ${result.value.toFixed(1)}`,
                                          subline: `Tested ${recordDate(result.tested)}`,
                                      }
                            }
                            minWidth={1000}
                            rowHeight="content"
                            columns={[
                                {
                                    key: 'target',
                                    label: 'Against target',
                                    width: '1.3fr',
                                    cell: (result) =>
                                        isConcealed(result) ? (
                                            '—'
                                        ) : (
                                            <StatusBadge
                                                variant={inrStatus(result).tone}
                                            >
                                                {inrStatus(result).label}
                                            </StatusBadge>
                                        ),
                                },
                                {
                                    key: 'instruction',
                                    label: 'Instruction',
                                    width: '1.5fr',
                                    cell: (result) =>
                                        isConcealed(result)
                                            ? '—'
                                            : (result.instruction ??
                                              'Not recorded'),
                                },
                                {
                                    key: 'next',
                                    label: 'Next test',
                                    width: '1fr',
                                    cell: (result) =>
                                        isConcealed(result)
                                            ? '—'
                                            : recordDate(result.next),
                                },
                                {
                                    key: 'medicine',
                                    label: 'Medicine',
                                    width: '1.5fr',
                                    cell: (result) =>
                                        isConcealed(result)
                                            ? '—'
                                            : (result.medicine ??
                                              `No medicine linked · ${result.unlinked_reason ?? 'no reason recorded'}`),
                                },
                                {
                                    key: 'by',
                                    label: 'Entered by',
                                    width: '1fr',
                                    cell: (result) =>
                                        isConcealed(result)
                                            ? '—'
                                            : (result.by ?? '—'),
                                },
                            ]}
                            actionsFor={(result) =>
                                isConcealed(result) ||
                                !data.can_manage ||
                                result.disabled
                                    ? []
                                    : [
                                          ...(!result.medicine_id &&
                                          medicines.length
                                              ? [
                                                    {
                                                        label: 'Link to an anticoagulant',
                                                        icon: Link2,
                                                        onClick: () =>
                                                            setCommand({
                                                                key: 'link-inr',
                                                                recordId:
                                                                    result.id,
                                                            }),
                                                    },
                                                ]
                                              : []),
                                          {
                                              label: 'Mark as entered in error',
                                              icon: ClipboardCheck,
                                              danger: true,
                                              onClick: () =>
                                                  setCommand({
                                                      key: 'disable-inr',
                                                      recordId: result.id,
                                                  }),
                                          },
                                      ]
                            }
                        />
                    )}
                </>
            )}
            {command && (
                <ClinicalCommandDialog
                    key={`${command.key}:${command.recordId}`}
                    clientId={clientId}
                    command={command.key}
                    recordId={command.recordId}
                    medicines={medicines}
                    onClose={() => setCommand(null)}
                    onSaved={done}
                />
            )}
            {start && (
                <SyringeDriverDialog
                    clientId={clientId}
                    medications={data.medicines}
                    witnesses={data.witnesses}
                    commandUrl={`/emar/clients/${clientId}/record/clinical/start-driver`}
                    onClose={done}
                />
            )}
        </div>
    );
}

export function DoseObservations({ clientId }: { clientId: number }) {
    const { data, load, reload } = useRecordJson<ClinicalData>(
        `/emar/clients/${clientId}/record/clinical`,
    );
    if (!data || load !== 'ready')
        return <ReadingState load={load} reload={reload} />;
    return (
        <ObservationList clientId={clientId} rows={data.observations.rows} />
    );
}

function ObservationList({
    clientId,
    rows,
}: {
    clientId: number;
    rows: MaybeConcealed<Observation>[];
}) {
    return (
        <SectionCard
            title="Readings taken with doses"
            icon={Stethoscope}
            right={
                <Button variant="outline" asChild>
                    <Link href={`/emar/mar?client_id=${clientId}&tab=history`}>
                        Dose history
                    </Link>
                </Button>
            }
        >
            <p className="text-sm">
                These readings are saved with the dose. Follow the person’s plan
                when acting on a reading.
            </p>
            {rows.length ? (
                <EntityTable
                    rows={rows}
                    rowKey={(row) => row.key}
                    identityLabel="Dose reading"
                    identity={(row) =>
                        isConcealed(row)
                            ? concealedIdentity
                            : {
                                  icon: Stethoscope,
                                  name: row.medicine,
                                  subline: formatDateTime(row.at),
                              }
                    }
                    minWidth={850}
                    columns={[
                        {
                            key: 'glucose',
                            label: 'Glucose',
                            width: '1fr',
                            cell: (row) =>
                                isConcealed(row)
                                    ? '—'
                                    : row.glucose !== null
                                      ? `${row.glucose} mmol/L`
                                      : '—',
                        },
                        {
                            key: 'pulse',
                            label: 'Pulse',
                            width: '1fr',
                            cell: (row) =>
                                isConcealed(row)
                                    ? '—'
                                    : row.pulse !== null
                                      ? `${row.pulse} bpm`
                                      : '—',
                        },
                        {
                            key: 'bp',
                            label: 'Blood pressure',
                            width: '1fr',
                            cell: (row) =>
                                isConcealed(row)
                                    ? '—'
                                    : row.systolic !== null &&
                                        row.diastolic !== null
                                      ? `${row.systolic}/${row.diastolic} mmHg`
                                      : '—',
                        },
                        {
                            key: 'by',
                            label: 'Taken by',
                            width: '1fr',
                            cell: (row) =>
                                isConcealed(row) ? '—' : (row.by ?? '—'),
                        },
                    ]}
                    actionsFor={() => []}
                />
            ) : (
                <p className="text-caption text-muted-foreground">
                    No readings taken with doses in the displayed record.
                </p>
            )}
            <p className="text-caption text-muted-foreground">
                Most recent 100 dose records with readings are shown. Historical
                clinical notes remain in the clinical record.
            </p>
        </SectionCard>
    );
}

function ClinicalCommandDialog({
    clientId,
    command,
    recordId,
    medicines,
    onClose,
    onSaved,
}: {
    clientId: number;
    command: Command;
    recordId?: number;
    medicines: ChartMedicationOption[];
    onClose: () => void;
    onSaved: () => void;
}) {
    const [fields, setFields] = useState<Record<string, string>>({
        client_medication_id:
            medicines.length === 1 ? String(medicines[0].id) : '',
        infusion_running: 'true',
    });
    const [uuid] = useState(() => crypto.randomUUID());
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const { requestClose, confirmation } = useDraftClose(
        Object.entries(fields).some(
            ([key, value]) =>
                key !== 'infusion_running' &&
                key !== 'client_medication_id' &&
                Boolean(value),
        ) || fields.infusion_running !== 'true',
        busy,
        onClose,
    );
    const set = (key: string, value: string) =>
        setFields((old) => ({ ...old, [key]: value }));
    const title = {
        inr: 'Record INR result',
        'link-inr': 'Link INR result',
        'disable-inr': 'Mark INR as entered in error',
        'check-driver': 'Record a driver check',
        'finish-driver': 'Finish the driver',
    }[command];
    const save = async () => {
        setBusy(true);
        setError(null);
        try {
            await axios.post(
                `/emar/clients/${clientId}/record/clinical/${command}`,
                {
                    ...Object.fromEntries(
                        Object.entries(fields).map(([key, value]) => [
                            key,
                            value === '' ? null : value,
                        ]),
                    ),
                    ...(command === 'check-driver'
                        ? {
                              infusion_running:
                                  fields.infusion_running === 'true',
                          }
                        : {}),
                    record_id: recordId,
                    request_uuid: uuid,
                },
            );
            onSaved();
        } catch (cause) {
            const response = axios.isAxiosError(cause) ? cause.response : null;
            setError(
                Object.values(response?.data?.errors ?? {})
                    .flat()
                    .join(' ') ||
                    response?.data?.message ||
                    'We couldn’t confirm the save. Your entries are retained; retry the same save.',
            );
        } finally {
            setBusy(false);
        }
    };
    const input = (
        key: string,
        label: string,
        type = 'text',
        required = false,
    ) => (
        <Field key={key} label={label} required={required}>
            <Input
                type={type}
                step={type === 'number' ? 'any' : undefined}
                value={fields[key] ?? ''}
                onChange={(event) => set(key, event.target.value)}
            />
        </Field>
    );
    return (
        <>
            <WizardShell
                frontline
                open
                onClose={requestClose}
                title={title}
                description="Record the clinical evidence. No dose instruction is calculated."
                railIcon={command.includes('driver') ? Syringe : HeartPulse}
                railTitle={title}
                railSub="Medication record"
                steps={[
                    {
                        key: 'record',
                        label: 'Record',
                        blurb: 'Save clinical evidence',
                        icon: ClipboardCheck,
                    },
                ]}
                stepIndex={0}
                onStepClick={() => {}}
                footerEnd={
                    <>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={requestClose}
                        >
                            Cancel
                        </Button>
                        <Button disabled={busy} onClick={save}>
                            {busy ? 'Saving…' : title}
                        </Button>
                    </>
                }
            >
                <WizardStepPane>
                    {error && (
                        <p
                            role="alert"
                            className="mb-4 text-sm text-status-critical"
                        >
                            {error}
                        </p>
                    )}
                    <div className="grid gap-4 sm:grid-cols-2">
                        {['inr', 'link-inr'].includes(command) && (
                            <Field
                                label="Anticoagulant"
                                required={command === 'link-inr'}
                            >
                                <SelectInput
                                    value={
                                        fields.client_medication_id ||
                                        'unlinked'
                                    }
                                    onChange={(value) =>
                                        set(
                                            'client_medication_id',
                                            value === 'unlinked' ? '' : value,
                                        )
                                    }
                                    placeholder="Choose the medicine"
                                    options={[
                                        ...(command === 'inr'
                                            ? [
                                                  {
                                                      value: 'unlinked',
                                                      label: 'No medicine linked',
                                                  },
                                              ]
                                            : []),
                                        ...medicines.map((medicine) => ({
                                            value: String(medicine.id),
                                            label: `${medicine.name} ${medicine.dosage}`,
                                        })),
                                    ]}
                                />
                            </Field>
                        )}
                        {command === 'inr' && (
                            <>
                                {!fields.client_medication_id && (
                                    <Field
                                        label="Why is no medicine linked?"
                                        required
                                    >
                                        <Textarea
                                            value={fields.unlinked_reason ?? ''}
                                            onChange={(event) =>
                                                set(
                                                    'unlinked_reason',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                )}
                                {input(
                                    'inr_value',
                                    'INR value',
                                    'number',
                                    true,
                                )}
                                <Field label="Tested on" required>
                                    <DatePicker
                                        compact
                                        id="inr-tested"
                                        label="Tested on"
                                        value={fields.tested_on ?? ''}
                                        onChange={(value) =>
                                            set('tested_on', value)
                                        }
                                    />
                                </Field>
                                {input(
                                    'target_range_low',
                                    'Target from prescriber — low',
                                    'number',
                                )}
                                {input(
                                    'target_range_high',
                                    'Target from prescriber — high',
                                    'number',
                                )}
                                {input(
                                    'dose_mg',
                                    'Dose instruction (mg)',
                                    'number',
                                )}
                                <Field label="Next test date">
                                    <DatePicker
                                        compact
                                        id="inr-next"
                                        label="Next test date"
                                        value={fields.next_test_date ?? ''}
                                        onChange={(value) =>
                                            set('next_test_date', value)
                                        }
                                        allowClear
                                    />
                                </Field>
                                <Field
                                    label="Prescriber or clinic instruction"
                                    required
                                >
                                    <Textarea
                                        value={fields.instruction ?? ''}
                                        onChange={(event) =>
                                            set(
                                                'instruction',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                {input(
                                    'instruction_source',
                                    'Source of instruction',
                                    'text',
                                    true,
                                )}
                            </>
                        )}
                        {command === 'check-driver' && (
                            <>
                                <Field
                                    label="Is the infusion running?"
                                    required
                                >
                                    <SelectInput
                                        value={fields.infusion_running}
                                        onChange={(value) =>
                                            set('infusion_running', value)
                                        }
                                        placeholder="Running"
                                        options={[
                                            { value: 'true', label: 'Running' },
                                            {
                                                value: 'false',
                                                label: 'Not running',
                                            },
                                        ]}
                                    />
                                </Field>
                                {input(
                                    'site_condition',
                                    'Insertion site condition',
                                )}
                                {input('volume_remaining', 'Volume remaining')}
                            </>
                        )}
                        {command === 'disable-inr' && (
                            <Field
                                label="Why was this entered in error?"
                                required
                            >
                                <Textarea
                                    value={fields.reason ?? ''}
                                    onChange={(event) =>
                                        set('reason', event.target.value)
                                    }
                                />
                            </Field>
                        )}
                        {command === 'finish-driver' && (
                            <p className="text-sm">
                                The record and all checks are retained. A driver
                                requires at least one check before it can
                                finish.
                            </p>
                        )}
                        {['inr', 'check-driver', 'finish-driver'].includes(
                            command,
                        ) && (
                            <Field label="Notes">
                                <Textarea
                                    value={fields.notes ?? ''}
                                    onChange={(event) =>
                                        set('notes', event.target.value)
                                    }
                                />
                            </Field>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            {confirmation}
        </>
    );
}
