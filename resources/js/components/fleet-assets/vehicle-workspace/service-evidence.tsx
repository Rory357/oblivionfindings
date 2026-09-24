import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import {
    ArrowRight,
    Bell,
    CalendarDays,
    FileText,
    History,
    Paperclip,
    ShieldCheck,
    ShieldOff,
    Upload,
    Wrench,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ComplianceDialog } from './compliance-dialog';
import {
    isNotRequired,
    notRequiredByline,
    NotRequiredDialog,
    NotRequiredToggle,
} from './compliance-not-required';
import {
    useVehicleCollectionView,
    VehicleCollectionToggle,
    VehicleRecordCollection,
} from './record-collection';
import {
    PlanAppointmentDialog,
    SectionHeading,
    SourceRecordDialog,
    StudioFooterAction,
} from './studio-kit';
import {
    applicabilityNames,
    outcomeNames,
    type Applicability,
    type ComplianceKind,
    type ComplianceRecord,
    type ComplianceVersion,
    type VehicleWorkspace,
} from './types';
import {
    complianceIssue,
    complianceTone,
    formatKm,
    todayInAuckland,
    type WorkspaceLocation,
} from './workspace-model';

const OUTCOME_VARIANT: Record<string, StatusVariant> = {
    passed: 'success',
    recorded: 'success',
    failed: 'critical',
    needs_assessment: 'warning',
};

/** A reason without its requirement prefix still reads as a sentence. */
function sentenceCase(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

export function statusFor(version: ComplianceVersion | null): {
    label: string;
    variant: StatusVariant;
} {
    if (!version) return { label: 'Not recorded', variant: 'warning' };
    if (version.applicability === 'not_applicable')
        return { label: 'Not applicable', variant: 'neutral' };
    if (version.applicability === 'unknown')
        return { label: 'Needs assessment', variant: 'warning' };
    return {
        label: outcomeNames[version.outcome],
        variant: OUTCOME_VARIANT[version.outcome] ?? 'neutral',
    };
}

export function EvidencePanel({
    workspace,
    focusKind,
    onNavigate,
    onChanged,
}: {
    workspace: VehicleWorkspace;
    /** A readiness link names the requirement it's about; its row is brought into view. */
    focusKind?: ComplianceKind;
    onNavigate: (location: WorkspaceLocation) => void;
    onChanged: () => void;
}) {
    const { view, setView } = useVehicleCollectionView('compliance');
    const [editing, setEditing] = useState<{
        record: ComplianceRecord;
        step: number;
        applicability?: Applicability;
    } | null>(null);
    const [history, setHistory] = useState<ComplianceRecord | null>(null);
    const [source, setSource] = useState<ComplianceRecord | null>(null);
    const [planning, setPlanning] = useState<ComplianceRecord | null>(null);
    const [notRequired, setNotRequired] = useState<ComplianceRecord | null>(
        null,
    );
    const panel = useRef<HTMLElement>(null);
    const { can, vehicle } = workspace;
    const owner = vehicle.responsible?.name ?? 'No responsible person';
    const odometerKm = workspace.odometer.current_km;

    // Land on the linked requirement: scroll to its row, outline it briefly
    // and put focus on its tick box (or the row) so the next step is visible.
    useEffect(() => {
        if (!focusKind || !panel.current) return;
        const marker = panel.current.querySelector<HTMLElement>(
            `[data-compliance-kind="${focusKind}"]`,
        );
        const row = marker?.closest<HTMLElement>(
            '[role="row"], .collection-card',
        );
        if (!row) return;
        const reduced = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;
        row.scrollIntoView({
            block: 'center',
            behavior: reduced ? 'auto' : 'smooth',
        });
        row.classList.add('compliance-focus');
        (
            row.querySelector<HTMLElement>('[data-compliance-toggle]') ?? row
        ).focus({ preventScroll: true });
        const timer = window.setTimeout(
            () => row.classList.remove('compliance-focus'),
            2600,
        );
        return () => {
            window.clearTimeout(timer);
            row.classList.remove('compliance-focus');
        };
    }, [focusKind, view]);

    return (
        <section className="studio-card" ref={panel}>
            <SectionHeading
                eyebrow="EVIDENCE & OBLIGATIONS"
                title="Service & compliance"
            >
                <VehicleCollectionToggle
                    label="Compliance requirements"
                    view={view}
                    onChange={setView}
                />
                <Button
                    variant="outline"
                    onClick={() =>
                        onNavigate({ tab: 'service', view: 'schedules' })
                    }
                >
                    <Wrench className="size-4" />
                    Service schedules
                </Button>
            </SectionHeading>
            <VehicleRecordCollection
                label="Compliance requirements"
                view={view}
                columns={[
                    { label: 'Recorded status' },
                    { label: 'Next due / coverage' },
                    { label: 'Owner & evidence', width: '1.4fr' },
                ]}
                empty={{
                    title: 'No requirements',
                    description: 'Registration, WoF, CoF and RUC appear here.',
                }}
                records={workspace.compliance.map((record) => {
                    const current = record.current;
                    const status = statusFor(current);
                    const issue = complianceIssue(
                        record,
                        workspace.readiness.reasons,
                    );
                    const fileCount = current?.files.length ?? 0;
                    const notRequired = isNotRequired(record);
                    const byline = notRequiredByline(record);
                    const ruc =
                        record.kind === 'ruc' &&
                        current?.ruc_start_km !== null &&
                        current?.ruc_end_km !== null &&
                        !!current;
                    const due = notRequired
                        ? 'Not required · basis recorded'
                        : current?.expires_on
                          ? formatDateOnly(current.expires_on)
                          : ruc
                            ? `${formatKm(current.ruc_start_km)} – ${formatKm(current.ruc_end_km)}`
                            : 'Not recorded';
                    const remaining =
                        ruc &&
                        odometerKm !== null &&
                        current.ruc_end_km !== null &&
                        current.applicability === 'applicable'
                            ? current.ruc_end_km - odometerKm
                            : null;
                    const openSource = () => setSource(record);
                    return {
                        id: record.kind,
                        name: record.label,
                        subline:
                            current?.evidence_reference ??
                            (current?.legacy
                                ? 'Earlier date, not yet assessed'
                                : 'No reference recorded'),
                        icon: ShieldCheck,
                        tone: complianceTone(
                            record,
                            workspace.readiness.reasons,
                        ),
                        onOpen: openSource,
                        fields: [
                            <>
                                <StatusBadge variant={status.variant}>
                                    {status.label}
                                </StatusBadge>
                                <small data-compliance-kind={record.kind}>
                                    {current
                                        ? applicabilityNames[
                                              current.applicability
                                          ]
                                        : 'Not assessed'}
                                </small>
                                {can.manage && (
                                    <NotRequiredToggle
                                        record={record}
                                        onToggle={() => setNotRequired(record)}
                                    />
                                )}
                            </>,
                            <>
                                <strong>{due}</strong>
                                {notRequired &&
                                    current?.applicability_basis && (
                                        <small className="compliance-not-required-reason">
                                            {current.applicability_basis}
                                            {byline ? ` · ${byline}` : ''}
                                        </small>
                                    )}
                                {remaining !== null && (
                                    <small>
                                        {remaining < 0
                                            ? 'Recorded odometer exceeds licence end'
                                            : `${formatKm(remaining)} remaining · recorded odometer`}
                                    </small>
                                )}
                                {issue && (
                                    <small>
                                        {sentenceCase(
                                            issue.message.replace(
                                                `${record.label}: `,
                                                '',
                                            ),
                                        )}
                                    </small>
                                )}
                                {can.manage && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            setEditing({ record, step: 1 })
                                        }
                                    >
                                        Update dates &amp; evidence
                                    </Button>
                                )}
                            </>,
                            <>
                                <span>{owner}</span>
                                {/* eslint-disable-next-line no-restricted-syntax -- The design's inline evidence link inside the owner cell. */}
                                <button
                                    type="button"
                                    className="inline-evidence"
                                    disabled={!can.manage}
                                    onClick={() =>
                                        setEditing({ record, step: 1 })
                                    }
                                >
                                    <Paperclip className="size-[14px]" />
                                    {fileCount}{' '}
                                    {fileCount === 1 ? 'file' : 'files'}
                                    {can.manage ? ' · add evidence' : ''}
                                </button>
                            </>,
                        ],
                        actions: [
                            {
                                label: 'View source record',
                                icon: FileText,
                                onClick: openSource,
                            },
                            {
                                label: 'View history',
                                icon: History,
                                onClick: () => setHistory(record),
                            },
                            ...(can.manage
                                ? [
                                      {
                                          label: 'Update evidence',
                                          icon: ShieldCheck,
                                          onClick: () =>
                                              setEditing({ record, step: 0 }),
                                      },
                                      {
                                          label: 'Upload document',
                                          icon: Upload,
                                          onClick: () =>
                                              setEditing({ record, step: 1 }),
                                      },
                                      {
                                          label: notRequired
                                              ? 'Mark as required'
                                              : 'Mark not required',
                                          icon: notRequired
                                              ? ShieldCheck
                                              : ShieldOff,
                                          onClick: () => setNotRequired(record),
                                      },
                                  ]
                                : []),
                            ...(can.schedule_service
                                ? [
                                      {
                                          label: 'Plan appointment',
                                          icon: CalendarDays,
                                          onClick: () => setPlanning(record),
                                      },
                                  ]
                                : []),
                        ],
                        footer: {
                            personName: owner,
                            primary: owner,
                            secondary: 'Responsible owner',
                        },
                    };
                })}
            />
            <StudioFooterAction
                icon={<Bell className="size-4" aria-hidden />}
                action={
                    <Button
                        variant="ghost"
                        onClick={() =>
                            onNavigate({ tab: 'service', view: 'reminders' })
                        }
                    >
                        Review reminders <ArrowRight className="size-[14px]" />
                    </Button>
                }
            >
                Reminders have their own owner and delivery history.
            </StudioFooterAction>
            {editing && (
                <ComplianceDialog
                    vehicle={vehicle}
                    record={editing.record}
                    initialStep={editing.step}
                    presetApplicability={editing.applicability}
                    onClose={() => setEditing(null)}
                    onSaved={onChanged}
                />
            )}
            {notRequired && (
                <NotRequiredDialog
                    vehicle={vehicle}
                    record={notRequired}
                    onClose={() => setNotRequired(null)}
                    onSaved={onChanged}
                    onRecordEvidence={() => {
                        setEditing({
                            record: notRequired,
                            step: 1,
                            applicability: 'applicable',
                        });
                        setNotRequired(null);
                    }}
                />
            )}
            {source && (
                <SourceRecordDialog
                    title={`${source.label} source`}
                    description={`${[vehicle.asset_tag, vehicle.name].filter(Boolean).join(' · ')} · Compliance record`}
                    rows={[
                        [
                            'Reference',
                            source.current?.evidence_reference ??
                                'No reference recorded',
                        ],
                        [
                            'Applicability',
                            source.current
                                ? applicabilityNames[
                                      source.current.applicability
                                  ]
                                : 'Not assessed',
                        ],
                        [
                            'Basis',
                            source.current?.applicability_basis ??
                                'Not recorded',
                        ],
                        [
                            'Evidence',
                            source.current?.files
                                .map((file) => file.name)
                                .join(', ') || 'Not recorded',
                        ],
                    ]}
                    onClose={() => setSource(null)}
                />
            )}
            {planning && (
                <PlanAppointmentDialog
                    vehicle={vehicle}
                    presetType={`${planning.label} appointment`}
                    startLocal={
                        planning.current?.expires_on &&
                        planning.current.expires_on > todayInAuckland()
                            ? `${planning.current.expires_on.slice(0, 10)}T09:00`
                            : undefined
                    }
                    onClose={() => setPlanning(null)}
                    onSaved={onChanged}
                />
            )}
            <ComplianceHistoryDialog
                record={history}
                onClose={() => setHistory(null)}
            />
        </section>
    );
}

function ComplianceHistoryDialog({
    record,
    onClose,
}: {
    record: ComplianceRecord | null;
    onClose: () => void;
}) {
    return (
        <Dialog
            open={record !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{record?.label} history</DialogTitle>
                    <DialogDescription>
                        Every saved version is kept. The newest is the one
                        readiness uses.
                        {record && record.history_count > record.history.length
                            ? ` Showing the latest ${record.history.length} of ${record.history_count}.`
                            : ''}
                    </DialogDescription>
                </DialogHeader>
                <ol className="scrollbar-pretty grid max-h-[60vh] gap-3 overflow-y-auto">
                    {record?.history.length ? (
                        record.history.map((version) => {
                            const status = statusFor(version);
                            return (
                                <li
                                    key={version.id}
                                    className="rounded-lg border p-3 text-sm"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <strong>
                                            Version {version.version}
                                        </strong>
                                        <StatusBadge
                                            variant={status.variant}
                                            label={status.label}
                                        />
                                    </div>
                                    <dl className="mt-2 grid gap-1 sm:grid-cols-2">
                                        <div>
                                            <dt className="text-caption">
                                                Reference
                                            </dt>
                                            <dd>
                                                {version.evidence_reference ??
                                                    'None'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-caption">
                                                Next due / coverage
                                            </dt>
                                            <dd>
                                                {version.expires_on
                                                    ? formatDateOnly(
                                                          version.expires_on,
                                                      )
                                                    : version.ruc_end_km !==
                                                        null
                                                      ? `${formatKm(version.ruc_start_km)} – ${formatKm(version.ruc_end_km)}`
                                                      : 'Not recorded'}
                                            </dd>
                                        </div>
                                        {version.applicability_basis && (
                                            <div className="sm:col-span-2">
                                                <dt className="text-caption">
                                                    Basis / source
                                                </dt>
                                                <dd>
                                                    {
                                                        version.applicability_basis
                                                    }
                                                </dd>
                                            </div>
                                        )}
                                        {version.files.length > 0 && (
                                            <div className="sm:col-span-2">
                                                <dt className="text-caption">
                                                    Files
                                                </dt>
                                                <dd className="flex flex-wrap gap-2">
                                                    {version.files.map(
                                                        (file) =>
                                                            file.url ? (
                                                                <a
                                                                    key={
                                                                        file.id
                                                                    }
                                                                    href={
                                                                        file.url
                                                                    }
                                                                    className="text-primary underline"
                                                                    target="_blank"
                                                                    rel="noreferrer"
                                                                >
                                                                    {file.name}
                                                                </a>
                                                            ) : (
                                                                <span
                                                                    key={
                                                                        file.id
                                                                    }
                                                                    className="text-muted-foreground"
                                                                >
                                                                    {file.name}{' '}
                                                                    (waiting for
                                                                    virus check)
                                                                </span>
                                                            ),
                                                    )}
                                                </dd>
                                            </div>
                                        )}
                                    </dl>
                                    <p className="text-caption mt-2">
                                        {version.legacy
                                            ? 'Carried over from the earlier vehicle record; not assessed.'
                                            : `${version.recorded_by ?? 'Someone'} · ${version.created_at ? formatDateTime(version.created_at) : ''}`}
                                    </p>
                                </li>
                            );
                        })
                    ) : (
                        <li className="text-subtle">
                            No evidence recorded yet.
                        </li>
                    )}
                </ol>
            </DialogContent>
        </Dialog>
    );
}
