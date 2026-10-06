import { EntityTable } from '@/components/lists/entity-table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import {
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import {
    secondPersonDisplay,
    type SecondPersonEvidence,
} from '@/lib/medication-second-person';
import axios from 'axios';
import { ClipboardCheck, History, Info, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { ReadingState } from './reading';
import { isConcealed, type MaybeConcealed } from './types';
import { concealedIdentity, SectionCard } from './ui';
import { useDraftClose } from './use-draft-close';
import { useRecordJson } from './use-record-json';

type Dose = SecondPersonEvidence & {
    key: string;
    id: number;
    medicine: string;
    status: string;
    at: string | null;
    scheduled_for: string | null;
    by: string | null;
    dose: string | null;
    reason: string | null;
    notes: string | null;
    is_correction: boolean;
    correction_status: string | null;
    correction_reason: string | null;
    requested_by: number | null;
    witness?: string | null;
    reviewed_by?: string | null;
    reviewed_at?: string | null;
    rejection_reason?: string | null;
    glucose?: string | null;
    pulse?: number | null;
    systolic?: number | null;
    diastolic?: number | null;
};
type Change = {
    key: string;
    action: string;
    at: string;
    by: string | null;
    meta: unknown;
};
type HistoryData = {
    page: {
        data: MaybeConcealed<Dose | Change>[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    can_correct?: boolean;
    actor_id?: number;
};

export function HistorySection({
    clientId,
    view,
    page,
    onPage,
    initialDoseId,
    onDetailClose,
}: {
    clientId: number;
    view: string;
    page: number;
    onPage: (page: number) => void;
    initialDoseId?: number | null;
    onDetailClose?: () => void;
}) {
    const { data, load, reload } = useRecordJson<HistoryData>(
        `/emar/clients/${clientId}/record/history?view=${view}&page=${page}`,
    );
    const [detail, setDetail] = useState<number | null>(initialDoseId ?? null);
    const [command, setCommand] = useState<{
        row: Dose;
        action: 'request' | 'approve' | 'reject';
    } | null>(null);
    const [change, setChange] = useState<Change | null>(null);
    if (!data || load !== 'ready')
        return <ReadingState load={load} reload={reload} />;
    const caption = `${data.page.from ?? 0}–${data.page.to ?? 0} of ${data.page.total} records`;
    return (
        <SectionCard
            icon={History}
            title={
                view === 'corrections'
                    ? 'Correction chain'
                    : view === 'changes'
                      ? 'All recorded changes'
                      : 'Dose history'
            }
        >
            <p className="text-caption text-muted-foreground">
                {caption} · newest first · Pacific/Auckland
            </p>
            {data.page.data.length ? (
                <EntityTable
                    rows={data.page.data}
                    rowKey={(row) => row.key}
                    identityLabel={view === 'changes' ? 'Change' : 'Medicine'}
                    identity={(row) =>
                        isConcealed(row)
                            ? concealedIdentity
                            : 'action' in row
                              ? {
                                    icon: History,
                                    name: row.action.replaceAll('.', ' · '),
                                    subline: formatDateTime(row.at),
                                }
                              : {
                                    icon: History,
                                    name: row.medicine,
                                    subline: formatDateTime(row.at),
                                }
                    }
                    minWidth={850}
                    rowHeight="content"
                    onOpen={(row) => {
                        if (isConcealed(row)) return;
                        if ('action' in row) setChange(row);
                        else setDetail(row.id);
                    }}
                    columns={[
                        {
                            key: 'outcome',
                            label:
                                view === 'changes' ? 'Recorded by' : 'Outcome',
                            width: '1fr',
                            cell: (row) =>
                                isConcealed(row) ? (
                                    '—'
                                ) : 'action' in row ? (
                                    row.by
                                ) : (
                                    <StatusBadge
                                        variant={
                                            row.status === 'given'
                                                ? 'success'
                                                : row.status === 'refused'
                                                  ? 'warning'
                                                  : 'neutral'
                                        }
                                    >
                                        {row.status === 'missed'
                                            ? 'Missed (recorded)'
                                            : row.status
                                                  .charAt(0)
                                                  .toUpperCase() +
                                              row.status.slice(1)}
                                    </StatusBadge>
                                ),
                        },
                        {
                            key: 'dose',
                            label: 'Dose / change',
                            width: '1.5fr',
                            cell: (row) =>
                                isConcealed(row)
                                    ? '—'
                                    : 'action' in row
                                      ? 'Open for recorded details'
                                      : `${row.dose ?? 'Dose not recorded'}${row.is_correction ? ` · correction ${row.correction_status}` : ''}`,
                        },
                        {
                            key: 'reason',
                            label: 'Reason / note',
                            width: '2fr',
                            cell: (row) =>
                                isConcealed(row) || 'action' in row
                                    ? '—'
                                    : (row.correction_reason ??
                                      row.reason ??
                                      row.notes ??
                                      '—'),
                        },
                        {
                            key: 'by',
                            label: 'Recorded by',
                            width: '1fr',
                            cell: (row) =>
                                isConcealed(row) ? '—' : (row.by ?? '—'),
                        },
                    ]}
                    actionsFor={(row) => {
                        if (isConcealed(row)) return [];
                        if ('action' in row)
                            return [
                                {
                                    label: 'View recorded change',
                                    icon: Info,
                                    onClick: () => setChange(row),
                                },
                            ];
                        return [
                            {
                                label: 'View dose and correction chain',
                                icon: Info,
                                onClick: () => setDetail(row.id),
                            },
                            ...(data.can_correct
                                ? row.is_correction &&
                                  row.correction_status === 'pending'
                                    ? [
                                          {
                                              label:
                                                  row.requested_by ===
                                                  data.actor_id
                                                      ? 'Another person must approve your correction'
                                                      : 'Approve correction',
                                              icon: ClipboardCheck,
                                              disabled:
                                                  row.requested_by ===
                                                  data.actor_id
                                                      ? 'A different person must approve this correction.'
                                                      : undefined,
                                              onClick: () =>
                                                  setCommand({
                                                      row,
                                                      action: 'approve' as const,
                                                  }),
                                          },
                                          {
                                              label: 'Decline correction',
                                              icon: ClipboardCheck,
                                              onClick: () =>
                                                  setCommand({
                                                      row,
                                                      action: 'reject' as const,
                                                  }),
                                          },
                                      ]
                                    : [
                                          {
                                              label: 'Request a correction',
                                              icon: RefreshCw,
                                              onClick: () =>
                                                  setCommand({
                                                      row,
                                                      action: 'request' as const,
                                                  }),
                                          },
                                      ]
                                : []),
                        ];
                    }}
                />
            ) : (
                <p className="text-sm">No records in this view.</p>
            )}
            <div className="flex items-center justify-between gap-3">
                <Button
                    variant="outline"
                    disabled={data.page.current_page <= 1}
                    onClick={() => onPage(page - 1)}
                >
                    Previous
                </Button>
                <span className="text-caption">
                    Page {data.page.current_page} of {data.page.last_page}
                </span>
                <Button
                    variant="outline"
                    disabled={data.page.current_page >= data.page.last_page}
                    onClick={() => onPage(page + 1)}
                >
                    Next
                </Button>
            </div>
            {detail !== null && (
                <DoseDetail
                    clientId={clientId}
                    doseId={detail}
                    onClose={() => {
                        setDetail(null);
                        onDetailClose?.();
                    }}
                />
            )}
            {change && (
                <WizardShell
                    frontline
                    open
                    onClose={() => setChange(null)}
                    title="Recorded change"
                    description="Original recorded evidence"
                    railIcon={History}
                    railTitle="Recorded change"
                    railSub={change.action}
                    steps={[
                        {
                            key: 'evidence',
                            label: 'Evidence',
                            blurb: 'As recorded',
                            icon: History,
                        },
                    ]}
                    stepIndex={0}
                    onStepClick={() => {}}
                    sequential={false}
                >
                    <WizardStepPane>
                        <ReviewRow
                            label="When"
                            value={formatDateTime(change.at)}
                        />
                        <ReviewRow
                            label="By"
                            value={change.by ?? 'Not recorded'}
                        />
                        <pre className="mt-4 text-sm break-all whitespace-pre-wrap">
                            {JSON.stringify(change.meta, null, 2)}
                        </pre>
                    </WizardStepPane>
                </WizardShell>
            )}
            {command && (
                <CorrectionDialog
                    clientId={clientId}
                    row={command.row}
                    action={command.action}
                    onClose={() => setCommand(null)}
                    onSaved={() => {
                        setCommand(null);
                        reload();
                    }}
                />
            )}
        </SectionCard>
    );
}

function DoseDetail({
    clientId,
    doseId,
    onClose,
}: {
    clientId: number;
    doseId: number;
    onClose: () => void;
}) {
    const { data, load, reload } = useRecordJson<{ dose: Dose; chain: Dose[] }>(
        `/emar/clients/${clientId}/record/doses/${doseId}`,
    );
    const confirmation = data ? secondPersonDisplay(data.dose) : null;
    return (
        <WizardShell
            frontline
            open
            onClose={onClose}
            title="Dose record"
            description="The recorded dose and retained correction history."
            railIcon={History}
            railTitle="Dose record"
            railSub={data?.dose.medicine ?? 'Loading'}
            steps={[
                {
                    key: 'dose',
                    label: 'Dose and history',
                    blurb: 'Original evidence retained',
                    icon: History,
                },
            ]}
            stepIndex={0}
            onStepClick={() => {}}
            sequential={false}
        >
            <WizardStepPane>
                {!data || load !== 'ready' ? (
                    <ReadingState load={load} reload={reload} />
                ) : (
                    <div className="space-y-4">
                        <ReviewRow label="Outcome" value={data.dose.status} />
                        <ReviewRow
                            label="When"
                            value={formatDateTime(data.dose.at)}
                        />
                        <ReviewRow
                            label="Recorded by"
                            value={data.dose.by ?? 'Not recorded'}
                        />
                        <ReviewRow
                            label="Dose"
                            value={data.dose.dose ?? 'Not recorded'}
                        />
                        <ReviewRow
                            label="Second person"
                            value={
                                confirmation ? (
                                    <span className="space-y-1">
                                        <StatusBadge
                                            variant={confirmation.tone}
                                            label={confirmation.label}
                                        />
                                        <span className="text-subtle block">
                                            {confirmation.detail}
                                        </span>
                                    </span>
                                ) : (
                                    (data.dose.witness ?? 'Not recorded')
                                )
                            }
                        />
                        <ReviewRow
                            label="Dose readings"
                            value={
                                [
                                    data.dose.glucose != null
                                        ? `Glucose ${data.dose.glucose} mmol/L`
                                        : null,
                                    data.dose.pulse != null
                                        ? `Pulse ${data.dose.pulse} bpm`
                                        : null,
                                    data.dose.systolic != null ||
                                    data.dose.diastolic != null
                                        ? `Blood pressure ${data.dose.systolic ?? '—'}/${data.dose.diastolic ?? '—'} mmHg`
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ') || 'None recorded'
                            }
                        />
                        <ReviewRow
                            label="Reason / notes"
                            value={
                                [data.dose.reason, data.dose.notes]
                                    .filter(Boolean)
                                    .join(' · ') || 'None recorded'
                            }
                        />
                        <h3 className="font-semibold">
                            Retained records for this dose
                        </h3>
                        {data.chain.map((row) => (
                            <div
                                key={row.id}
                                className="rounded-lg border p-3 text-sm"
                            >
                                <p>
                                    {row.status} · {formatDateTime(row.at)} ·{' '}
                                    {row.by}
                                </p>
                                {secondPersonDisplay(row) && (
                                    <p className="mt-2">
                                        {secondPersonDisplay(row)!.label} ·{' '}
                                        {secondPersonDisplay(row)!.detail}
                                    </p>
                                )}
                                {row.is_correction && (
                                    <p>
                                        Correction {row.correction_status} ·{' '}
                                        {row.correction_reason}
                                        {row.reviewed_by
                                            ? ` · reviewed by ${row.reviewed_by}`
                                            : ''}
                                        {row.rejection_reason
                                            ? ` · ${row.rejection_reason}`
                                            : ''}
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </WizardStepPane>
        </WizardShell>
    );
}

function CorrectionDialog({
    clientId,
    row,
    action,
    onClose,
    onSaved,
}: {
    clientId: number;
    row: Dose;
    action: 'request' | 'approve' | 'reject';
    onClose: () => void;
    onSaved: () => void;
}) {
    const [status, setStatus] = useState(row.status);
    const [dose, setDose] = useState(row.dose ?? '');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [uuid] = useState(() => crypto.randomUUID());
    const { requestClose, confirmation } = useDraftClose(
        status !== row.status || dose !== (row.dose ?? '') || Boolean(reason),
        busy,
        onClose,
    );
    const title =
        action === 'request'
            ? 'Request a correction'
            : action === 'approve'
              ? 'Approve correction'
              : 'Decline correction';
    const save = async () => {
        setBusy(true);
        setError(null);
        try {
            await axios.post(
                `/emar/clients/${clientId}/record/doses/${row.id}/corrections/${action}`,
                {
                    status,
                    dose_given: dose,
                    correction_reason: reason,
                    reason,
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
                    'We couldn’t confirm the save. Retry with your retained entries.',
            );
        } finally {
            setBusy(false);
        }
    };
    return (
        <>
            <WizardShell
                frontline
                open
                onClose={requestClose}
                title={title}
                description="The original record is retained. Another person approves the correction."
                railIcon={RefreshCw}
                railTitle={title}
                railSub={row.medicine}
                steps={[
                    {
                        key: 'record',
                        label: 'Check',
                        blurb: 'Original evidence retained',
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
                        <Button
                            disabled={
                                busy || (action !== 'approve' && !reason.trim())
                            }
                            onClick={save}
                        >
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
                    <ReviewRow
                        label="Recorded"
                        value={`${row.status} · ${row.dose ?? ''} · ${formatDateTime(row.at)}`}
                    />
                    <div className="mt-4 space-y-4">
                        {action === 'request' && (
                            <>
                                <Field label="Correct outcome">
                                    <SelectInput
                                        value={status}
                                        onChange={setStatus}
                                        placeholder="Outcome"
                                        options={[
                                            'given',
                                            'refused',
                                            'withheld',
                                            'missed',
                                        ].map((value) => ({
                                            value,
                                            label: value,
                                        }))}
                                    />
                                </Field>
                                <Field label="Correct dose">
                                    <Input
                                        value={dose}
                                        onChange={(event) =>
                                            setDose(event.target.value)
                                        }
                                    />
                                </Field>
                            </>
                        )}
                        {action === 'approve' ? (
                            <p className="text-sm">
                                Confirm that this correction matches the
                                evidence. You cannot approve a correction you
                                requested.
                            </p>
                        ) : (
                            <Field label="Reason" required>
                                <Textarea
                                    value={reason}
                                    onChange={(event) =>
                                        setReason(event.target.value)
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
