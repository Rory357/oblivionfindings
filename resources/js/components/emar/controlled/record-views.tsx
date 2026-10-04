import {
    FilePreviewDialog,
    type PreviewFile,
} from '@/components/files/file-preview-dialog';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import {
    BookOpen,
    ClipboardList,
    FileWarning,
    ShieldCheck,
    Undo2,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import type { ActionSpec } from './action-dialog';
import type {
    ControlledEntry,
    ControlledOverride,
    ControlledProductPayload,
} from './product-types';
import {
    dateTime,
    medicineFor,
    medicineLabel,
    Notice,
    quantity,
    RecordList,
    StateBadge,
} from './product-ui';

export type DetailSpec = {
    kind:
        | 'medicine'
        | 'entry'
        | 'discrepancy'
        | 'loss'
        | 'destruction'
        | 'request'
        | 'override';
    id: number;
};
export const entryCanBeVoided = (
    entry: ControlledEntry,
    payload: ControlledProductPayload,
) =>
    payload.can.manage &&
    !entry.voided_at &&
    !['count', 'balance_check', 'void'].includes(entry.entry_type) &&
    entry.can_void !== false;
export function EntryList({
    entries,
    payload,
    onDetail,
    onAction,
}: {
    entries: ControlledEntry[];
    payload: ControlledProductPayload;
    onDetail: (spec: DetailSpec) => void;
    onAction: (spec: ActionSpec) => void;
}) {
    const actions = (entry: ControlledEntry): MenuItem[] =>
        compactMenu([
            {
                label: 'View entry',
                icon: BookOpen,
                onClick: () => onDetail({ kind: 'entry', id: entry.id }),
            },
            entryCanBeVoided(entry, payload) && {
                label: 'Void and correct',
                icon: Undo2,
                danger: true,
                onClick: () =>
                    onAction({
                        action: 'void',
                        targetId: entry.id,
                        medicineId: entry.client_medication_id,
                    }),
            },
        ]);
    return (
        <RecordList
            rows={entries}
            rowKey={(entry) => entry.id}
            identity={(entry) => ({
                name: medicineLabel(payload, entry.client_medication_id),
                icon: BookOpen,
                subline: `Entry ${entry.id} · ${entry.entry_type.replace(/_/g, ' ')} · ${dateTime(entry.recorded_at)}`,
                extra: entry.voided_at ? (
                    <StatusBadge variant="neutral">Voided</StatusBadge>
                ) : entry.corrects_entry_id ? (
                    <StatusBadge variant="info">
                        Corrects entry {entry.corrects_entry_id}
                    </StatusBadge>
                ) : null,
            })}
            actionsFor={actions}
            onOpen={(entry) => onDetail({ kind: 'entry', id: entry.id })}
            columns={[
                {
                    key: 'amount',
                    label: 'Amount',
                    width: '0.7fr',
                    cell: (entry) => (
                        <span className={entry.voided_at ? 'line-through' : ''}>
                            {entry.quantity ?? 'Not recorded'}
                        </span>
                    ),
                },
                {
                    key: 'balance',
                    label: 'Balance',
                    width: '0.8fr',
                    cell: (entry) => (
                        <span className={entry.voided_at ? 'line-through' : ''}>
                            {entry.on_hand_before ?? 'Not recorded'} →{' '}
                            {entry.on_hand_after ?? 'Not recorded'}
                        </span>
                    ),
                },
                {
                    key: 'people',
                    label: 'Recorded / witnessed',
                    width: '1.5fr',
                    cell: (entry) => (
                        <div>
                            <p>{entry.recorded_by_name ?? '—'}</p>
                            <p className="text-caption">
                                {[
                                    entry.witnessed_by_name,
                                    entry.second_witness_name,
                                ]
                                    .filter(Boolean)
                                    .join(' · ') || 'No witness recorded'}
                            </p>
                        </div>
                    ),
                },
                {
                    key: 'notes',
                    label: 'Notes',
                    width: '1.3fr',
                    cell: (entry) => (
                        <div>
                            <p>{entry.notes || '—'}</p>
                            {entry.voided_at ? (
                                <p className="text-caption">
                                    Voided {dateTime(entry.voided_at)} ·{' '}
                                    {entry.void_reason ?? 'Reason unavailable'}
                                </p>
                            ) : null}
                        </div>
                    ),
                },
            ]}
            emptyTitle="No entries in this view"
            emptyDescription="Try another date, person or search. Recorded counts and witnessed changes appear here."
        />
    );
}
export function DetailDialog({
    spec,
    payload,
    onAction,
    onDetail,
    onFollowup,
    onClose,
}: {
    spec: DetailSpec;
    payload: ControlledProductPayload;
    onAction: (spec: ActionSpec) => void;
    onDetail: (spec: DetailSpec) => void;
    onFollowup: (override: ControlledOverride) => void;
    onClose: () => void;
}) {
    const [section, setSection] = useState(0);
    const [preview, setPreview] = useState<PreviewFile | null>(null);
    const record =
        spec.kind === 'medicine'
            ? medicineFor(payload, spec.id)
            : spec.kind === 'entry'
              ? payload.entries.find((item) => item.id === spec.id)
              : spec.kind === 'discrepancy'
                ? payload.discrepancies.find((item) => item.id === spec.id)
                : spec.kind === 'loss'
                  ? payload.losses.find((item) => item.id === spec.id)
                  : spec.kind === 'destruction'
                    ? payload.destructions.find((item) => item.id === spec.id)
                    : spec.kind === 'request'
                      ? payload.requests.find((item) => item.id === spec.id)
                      : payload.overrides.find((item) => item.id === spec.id);
    if (!record)
        return (
            <WizardShell
                frontline
                open
                onClose={onClose}
                title="We can’t show this record"
                description="The record may be outside your approved houses or no longer available."
                railIcon={ShieldCheck}
                railTitle="Record unavailable"
                railSub="Controlled medicines"
                steps={[
                    {
                        key: 'unavailable',
                        label: 'Unavailable',
                        blurb: 'Check your access',
                        icon: ShieldCheck,
                    },
                ]}
                stepIndex={0}
                onStepClick={() => undefined}
                sequential={false}
                headerLabel="Record unavailable"
                footerStart={
                    <Button className="min-h-11" onClick={onClose}>
                        Close
                    </Button>
                }
            >
                <WizardStepPane>
                    <Notice title="We can’t show this record">
                        It may be outside your approved houses or no longer
                        available.
                    </Notice>
                </WizardStepPane>
            </WizardShell>
        );
    const rows: [string, ReactNode][] = [];
    let title = 'Controlled record';
    let extra: ReactNode = null;
    let footer: ReactNode = null;
    let secondary: ReactNode = null;
    if (spec.kind === 'medicine') {
        const medicine = medicineFor(payload, spec.id)!;
        title = `${medicine.name} · ${medicine.client_name}`;
        rows.push(
            ['House', medicine.site_name],
            ['Balance', quantity(medicine.balance, medicine.unit)],
            [
                'NZ class',
                medicine.class_review_required
                    ? 'Class to review — not mapped from a legacy schedule'
                    : medicine.nz_class
                      ? `Class ${medicine.nz_class}`
                      : 'Not configured',
            ],
            ['Last count', dateTime(medicine.count.last_at)],
            ['Count', medicine.count.title],
        );
        secondary = (
            <EntryList
                entries={payload.entries.filter(
                    (entry) => entry.client_medication_id === medicine.id,
                )}
                payload={payload}
                onDetail={onDetail}
                onAction={onAction}
            />
        );
        if (payload.can.manage)
            footer = (
                <Button
                    variant="outline"
                    className="min-h-11"
                    onClick={() =>
                        onAction({
                            action: 'class_review',
                            medicineId: medicine.id,
                        })
                    }
                >
                    Review NZ class
                </Button>
            );
    } else if (spec.kind === 'entry') {
        const entry = payload.entries.find((item) => item.id === spec.id)!;
        title = `Register entry ${entry.id}`;
        rows.push(
            ['Medicine', medicineLabel(payload, entry.client_medication_id)],
            ['Type', entry.entry_type.replace(/_/g, ' ')],
            ['Amount', entry.quantity ?? 'Not recorded'],
            [
                'Balance',
                `${entry.on_hand_before ?? 'Not recorded'} → ${entry.on_hand_after ?? 'Not recorded'}`,
            ],
            [
                'Recorded',
                `${dateTime(entry.recorded_at)} · ${entry.recorded_by_name ?? '—'}`,
            ],
            [
                'Witness',
                [entry.witnessed_by_name, entry.second_witness_name]
                    .filter(Boolean)
                    .join(' · ') || 'No witness recorded',
            ],
            ['Notes', entry.notes ?? '—'],
        );
        if (entry.voided_at)
            rows.push(
                [
                    'Voided',
                    `${dateTime(entry.voided_at)} · ${entry.voided_by_name ?? '—'}`,
                ],
                ['Void witness', entry.void_witness_name ?? '—'],
                ['Void reason', entry.void_reason ?? '—'],
            );
        if (entry.corrects_entry_id)
            rows.push([
                'Correction',
                `Corrects entry ${entry.corrects_entry_id}`,
            ]);
        if (entryCanBeVoided(entry, payload))
            footer = (
                <Button
                    variant="destructive"
                    className="min-h-11"
                    onClick={() =>
                        onAction({
                            action: 'void',
                            targetId: entry.id,
                            medicineId: entry.client_medication_id,
                        })
                    }
                >
                    Void and correct
                </Button>
            );
    } else if (spec.kind === 'discrepancy') {
        const discrepancy = payload.discrepancies.find(
            (item) => item.id === spec.id,
        )!;
        title = `Discrepancy ${discrepancy.id}`;
        rows.push(
            [
                'Medicine',
                medicineLabel(payload, discrepancy.client_medication_id),
            ],
            ['Status', <StateBadge status={discrepancy.status} />],
            ['Expected', discrepancy.expected_balance ?? 'Not recorded'],
            ['First count', discrepancy.first_count ?? '—'],
            [
                'Second count',
                discrepancy.recount_balance ??
                    discrepancy.actual_balance ??
                    'Not recorded',
            ],
            ['Counted by', discrepancy.reported_by_name ?? '—'],
            ['Witness', discrepancy.witnessed_by_name ?? '—'],
            ['Owner', discrepancy.owner_name ?? 'Not configured'],
            ['What was found', discrepancy.notes ?? '—'],
            ['Immediate action', discrepancy.immediate_action_taken ?? '—'],
        );
        if (discrepancy.outcome)
            rows.push(
                ['Outcome', discrepancy.outcome.replace(/_/g, ' ')],
                ['Resolution', discrepancy.resolution_notes ?? '—'],
                [
                    'Resolved',
                    `${dateTime(discrepancy.resolved_at)} · ${discrepancy.resolved_by_name ?? '—'}`,
                ],
            );
        extra = (
            <Notice title="Independent follow-up">
                {discrepancy.can_resolve
                    ? 'A person who did not count or witness the count must resolve the discrepancy.'
                    : (discrepancy.resolve_reason ??
                      'You cannot resolve this discrepancy.')}{' '}
                The linked incident stays with Medication errors.
            </Notice>
        );
        if (discrepancy.can_resolve)
            footer = (
                <Button
                    className="min-h-11"
                    onClick={() =>
                        onAction({
                            action: 'resolve',
                            targetId: discrepancy.id,
                            medicineId: discrepancy.client_medication_id,
                        })
                    }
                >
                    Resolve discrepancy
                </Button>
            );
    } else if (spec.kind === 'loss') {
        const loss = payload.losses.find((item) => item.id === spec.id)!;
        title = `Loss ${loss.id}`;
        rows.push(
            ['Medicine', medicineLabel(payload, loss.client_medication_id)],
            ['Amount', loss.quantity],
            ['Status', <StateBadge status={loss.status} />],
            ['Discovered', dateTime(loss.discovered_at)],
            ['What happened', loss.circumstances],
            ['Immediate action', loss.immediate_action_taken ?? '—'],
            ['Reported by', loss.reported_by_name ?? '—'],
            ['Witness', loss.witnessed_by_name ?? '—'],
            [
                'Police',
                loss.reported_to_police
                    ? `${loss.police_reference ?? 'No reference recorded'} · ${dateTime(loss.police_notified_at)} · ${loss.police_notified_by_name ?? '—'}`
                    : 'Not recorded as told',
            ],
            [
                'Medicines Control',
                loss.reported_to_regulator
                    ? `${loss.regulator_reference ?? 'No reference recorded'} · ${dateTime(loss.regulator_notified_at)} · ${loss.regulator_notified_by_name ?? '—'}`
                    : 'Not recorded as told',
            ],
        );
        if (loss.suspected_theft)
            rows.push(['Concern recorded', 'Suspected theft']);
        if (loss.closed_at)
            rows.push(
                ['Finding', loss.resolution_outcome ?? '—'],
                ['Closure notes', loss.resolution_notes ?? '—'],
                ['Closed', dateTime(loss.closed_at)],
            );
        secondary = (
            <div className="space-y-3">
                <ListCaption
                    title="Investigation"
                    caption="Earlier notes remain unchanged"
                />
                {loss.notes.length ? (
                    [...loss.notes]
                        .sort(
                            (a, b) =>
                                a.created_at.localeCompare(b.created_at) ||
                                a.id - b.id,
                        )
                        .map((note) => (
                            <ReviewCard
                                key={note.id}
                                title={`${dateTime(note.created_at)} · ${note.created_by_name ?? '—'}`}
                                icon={FileWarning}
                            >
                                <p className="text-sm whitespace-pre-wrap">
                                    {note.notes}
                                </p>
                            </ReviewCard>
                        ))
                ) : (
                    <p className="text-caption">No investigation notes yet.</p>
                )}
            </div>
        );
        if (!loss.closed_at)
            footer = (
                <>
                    {payload.can.record ? (
                        <Button
                            variant="outline"
                            className="min-h-11"
                            onClick={() =>
                                onAction({
                                    action: 'loss_note',
                                    targetId: loss.id,
                                    medicineId: loss.client_medication_id,
                                })
                            }
                        >
                            Add investigation note
                        </Button>
                    ) : null}
                    {payload.can.record ? (
                        <Button
                            variant="outline"
                            className="min-h-11"
                            onClick={() =>
                                onAction({
                                    action: 'loss_notify',
                                    targetId: loss.id,
                                    medicineId: loss.client_medication_id,
                                })
                            }
                        >
                            Record a notification
                        </Button>
                    ) : null}
                    {payload.can.close_loss ? (
                        <Button
                            className="min-h-11"
                            onClick={() =>
                                onAction({
                                    action: 'loss_close',
                                    targetId: loss.id,
                                    medicineId: loss.client_medication_id,
                                })
                            }
                        >
                            Close loss
                        </Button>
                    ) : null}
                </>
            );
    } else if (spec.kind === 'destruction') {
        const destruction = payload.destructions.find(
            (item) => item.id === spec.id,
        )!;
        title = `Destruction record ${destruction.id}`;
        rows.push(
            [
                'Medicine',
                medicineLabel(payload, destruction.client_medication_id),
            ],
            ['Amount', destruction.quantity],
            ['Reason', destruction.reason],
            [
                'Method',
                destruction.method === 'pharmacy_return'
                    ? 'Return to the pharmacy'
                    : 'Denature on site',
            ],
            ['Recorded', dateTime(destruction.recorded_at)],
            ['Recorder', destruction.recorded_by_name ?? '—'],
            [
                'Witnesses',
                [destruction.witnessed_by_name, destruction.second_witness_name]
                    .filter(Boolean)
                    .join(' · ') || '—',
            ],
            [
                'Receipt',
                destruction.received_at
                    ? `${destruction.pharmacist_name ?? '—'} · ${destruction.pharmacist_registration ?? '—'} · ${dateTime(destruction.received_at)}`
                    : destruction.method === 'pharmacy_return'
                      ? 'Waiting for the pharmacist’s receipt'
                      : 'Not applicable',
            ],
            ['Notes', destruction.notes ?? '—'],
        );
        if (destruction.photo)
            rows.push([
                'Photo',
                <Button
                    variant="outline"
                    className="min-h-11"
                    onClick={() =>
                        setPreview({
                            id: destruction.id,
                            name: destruction.photo!.name,
                            mime: destruction.photo!.mime_type,
                            bytes: destruction.photo!.size,
                            source: 'Controlled destruction record',
                            previewUrl: destruction.photo!.url,
                            downloadUrl: destruction.photo!.download_url,
                        })
                    }
                >
                    View saved photo
                </Button>,
            ]);
        if (destruction.voided_at)
            rows.push(
                ['Voided', dateTime(destruction.voided_at)],
                ['Void reason', destruction.void_reason ?? '—'],
            );
        if (payload.can.manage && !destruction.voided_at)
            footer = (
                <>
                    {destruction.method === 'pharmacy_return' &&
                    !destruction.received_at ? (
                        <Button
                            className="min-h-11"
                            onClick={() =>
                                onAction({
                                    action: 'destruction_receipt',
                                    targetId: destruction.id,
                                    medicineId:
                                        destruction.client_medication_id,
                                })
                            }
                        >
                            Record pharmacy receipt
                        </Button>
                    ) : null}
                    <Button
                        variant="destructive"
                        className="min-h-11"
                        onClick={() =>
                            onAction({
                                action: 'destruction_void',
                                targetId: destruction.id,
                                medicineId: destruction.client_medication_id,
                            })
                        }
                    >
                        Void destruction
                    </Button>
                </>
            );
    } else if (spec.kind === 'request') {
        const request = payload.requests.find((item) => item.id === spec.id)!;
        title = 'Witness request';
        rows.push(
            ['Requested by', request.requested_by_name],
            ['Colleague', request.witness_name],
            [
                'Status',
                <StateBadge status={request.response ?? request.status} />,
            ],
            ['Reason', request.reason ?? '—'],
            ['Requested', dateTime(request.created_at)],
        );
        footer = (
            <>
                {request.can_answer ? (
                    <Button
                        className="min-h-11"
                        onClick={() =>
                            onAction({
                                action: 'witness_answer',
                                targetId: request.id,
                                siteId: request.site_id,
                            })
                        }
                    >
                        Answer request
                    </Button>
                ) : null}
                {request.can_cancel ? (
                    <Button
                        variant="outline"
                        className="min-h-11"
                        onClick={() =>
                            onAction({
                                action: 'witness_cancel',
                                targetId: request.id,
                                siteId: request.site_id,
                            })
                        }
                    >
                        Cancel request
                    </Button>
                ) : null}
            </>
        );
    } else {
        const override = payload.overrides.find((item) => item.id === spec.id)!;
        title = `Witness override ${override.id}`;
        rows.push(
            ['House', override.site_name],
            [
                'Requested',
                `${dateTime(override.requested_at)} · ${override.requested_by_name}`,
            ],
            ['Reason', override.reason],
            ['Status', <StateBadge status={override.status} />],
            [
                'Coverage',
                override.medicine_ids
                    .map((id) => medicineLabel(payload, id))
                    .join('; '),
            ],
            [
                'Window',
                `${dateTime(override.starts_at)} → ${dateTime(override.expires_at)}`,
            ],
            [
                'Decision',
                `${override.decided_by_name ?? '—'} · ${override.decision_notes ?? '—'}`,
            ],
            ['Follow-up due', dateTime(override.followup_due_at)],
        );
        secondary = override.doses.length ? (
            <div className="space-y-3">
                <ListCaption
                    title="Doses covered"
                    caption="A separate check and sign-off is kept for every dose"
                />
                {override.doses.map((dose) => (
                    <ReviewCard
                        key={dose.administration_id}
                        title={medicineLabel(
                            payload,
                            dose.client_medication_id,
                        )}
                        icon={ShieldCheck}
                    >
                        <ReviewRow
                            label="Given"
                            value={`${dateTime(dose.administered_at)} · ${dose.administered_by_name ?? '—'}`}
                        />
                        <ReviewRow
                            label="Sign-off"
                            value={
                                dose.signed_off_at
                                    ? dateTime(dose.signed_off_at)
                                    : 'Awaiting a witnessed count and check'
                            }
                        />
                    </ReviewCard>
                ))}
            </div>
        ) : null;
        footer = (
            <>
                {payload.can.override &&
                ['waiting', 'pending'].includes(override.status) ? (
                    <Button
                        className="min-h-11"
                        onClick={() =>
                            onAction({
                                action: 'override_decide',
                                targetId: override.id,
                                siteId: override.site_id,
                            })
                        }
                    >
                        Review request
                    </Button>
                ) : null}
                {override.can_signoff &&
                override.doses.some((dose) => !dose.signed_off_at) ? (
                    <Button
                        className="min-h-11"
                        onClick={() => onFollowup(override)}
                    >
                        Check and sign off
                    </Button>
                ) : null}
            </>
        );
    }
    const sections = [
        {
            key: 'details',
            label: 'Details',
            blurb: 'Record and next action',
            icon: ClipboardList,
        },
        ...(secondary
            ? [
                  {
                      key: 'history',
                      label:
                          spec.kind === 'medicine'
                              ? 'Entries'
                              : spec.kind === 'override'
                                ? 'Doses'
                                : 'Investigation',
                      blurb: 'History kept unchanged',
                      icon: BookOpen,
                  },
              ]
            : []),
    ];
    return (
        <>
            <WizardShell
                frontline
                open
                onClose={onClose}
                title={title}
                description="Controlled medicine record and its retained history."
                railIcon={ShieldCheck}
                railTitle={title}
                railSub="Controlled medicines"
                steps={sections}
                stepIndex={section}
                onStepClick={setSection}
                sequential={false}
                headerLabel={sections[section]?.label ?? 'Details'}
                pct={null}
                footerStart={
                    <Button
                        variant="outline"
                        className="min-h-11"
                        onClick={onClose}
                    >
                        Close
                    </Button>
                }
                footerEnd={
                    <div className="flex flex-wrap justify-end gap-2">
                        {footer}
                    </div>
                }
                maxWidth="min(92vw, 1100px)"
            >
                <WizardStepPane>
                    <div className="space-y-5">
                        {section === 0 ? (
                            <>
                                {extra}
                                <ReviewCard
                                    title="Record details"
                                    icon={ClipboardList}
                                >
                                    {rows.map(([label, value]) => (
                                        <ReviewRow
                                            key={label}
                                            label={label}
                                            value={value}
                                        />
                                    ))}
                                </ReviewCard>
                            </>
                        ) : (
                            secondary
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            <FilePreviewDialog
                file={preview}
                onClose={() => setPreview(null)}
            />
        </>
    );
}
