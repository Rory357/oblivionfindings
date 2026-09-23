import { Button } from '@/components/ui/button';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import {
    ArrowRight,
    CalendarDays,
    History,
    Paperclip,
    ShieldCheck,
    Wrench,
} from 'lucide-react';
import {
    VehicleCollectionToggle,
    VehicleRecordCollection,
    useVehicleCollectionView,
    type VehicleCollectionRecord,
} from './record-collection';
import {
    applicabilityNames,
    outcomeNames,
    requirementNames,
    type ComplianceVersion,
    type VehicleCompliance,
    type VehicleReadiness,
} from './types';

function requirementStatus(
    version: ComplianceVersion | null,
    blocked: boolean,
): { label: string; variant: StatusVariant } {
    if (!version) return { label: 'Not assessed', variant: 'warning' };
    if (version.applicability === 'not_applicable')
        return {
            label: blocked ? 'Review basis' : 'Not applicable',
            variant: blocked ? 'warning' : 'neutral',
        };
    if (version.outcome === 'failed')
        return { label: 'Failed', variant: 'critical' };
    if (
        version.applicability === 'unknown' ||
        version.outcome === 'needs_assessment'
    )
        return { label: 'Needs assessment', variant: 'warning' };
    return {
        label: blocked ? 'Review coverage' : outcomeNames[version.outcome],
        variant: blocked ? 'critical' : 'success',
    };
}

export function VehicleCompliancePanel({
    records,
    readiness,
    canManage,
    canViewDocuments,
    onEdit,
    onHistory,
    onDocuments,
    onReminders,
    onSchedules,
}: {
    records: VehicleCompliance[];
    readiness: VehicleReadiness;
    canManage: boolean;
    canViewDocuments: boolean;
    onEdit: (record: VehicleCompliance, initialStep: 0 | 1) => void;
    onHistory: (record: VehicleCompliance) => void;
    onDocuments: () => void;
    onReminders: () => void;
    onSchedules: () => void;
}) {
    const { view, setView } = useVehicleCollectionView();
    const rows: VehicleCollectionRecord[] = records.map((record) => {
        const version = record.current;
        const reasons = readiness.reasons.filter(
            (reason) => reason.kind === record.kind && reason.blocks_decision,
        );
        const status = requirementStatus(version, reasons.length > 0);
        const coverage =
            version?.applicability === 'not_applicable'
                ? 'Not required under recorded basis'
                : record.kind === 'ruc'
                  ? version?.ruc_start_km != null && version?.ruc_end_km != null
                      ? `${version.ruc_start_km.toLocaleString('en-NZ')}–${version.ruc_end_km.toLocaleString('en-NZ')} km`
                      : 'Licence range not recorded'
                  : formatDateOnly(version?.expires_on, 'Date not recorded');
        return {
            id: record.kind,
            name: requirementNames[record.kind],
            icon: ShieldCheck,
            subline: version
                ? `Version ${version.version} · ${record.history_count} ${record.history_count === 1 ? 'record' : 'records'}`
                : 'No source assessment',
            tone:
                status.variant === 'critical'
                    ? 'critical'
                    : reasons.length
                      ? 'warning'
                      : 'success',
            onOpen: () => onHistory(record),
            fields: [
                <div key="state" className="space-y-1.5">
                    <StatusBadge variant={status.variant}>
                        {status.label}
                    </StatusBadge>
                    <p className="text-xs text-muted-foreground">
                        {version
                            ? applicabilityNames[version.applicability]
                            : 'Review applicability'}
                    </p>
                </div>,
                <div key="coverage" className="space-y-1.5">
                    <strong className="text-sm">{coverage}</strong>
                    {reasons.slice(0, 1).map((reason) => (
                        <p
                            key={reason.code}
                            className="text-xs text-status-warning"
                        >
                            {reason.message}
                        </p>
                    ))}
                    {canManage && (
                        <Button
                            variant="link"
                            size="sm"
                            className="h-auto p-0 text-xs"
                            onClick={(event) => {
                                event.stopPropagation();
                                onEdit(record, 1);
                            }}
                        >
                            <CalendarDays className="size-3.5" />
                            Update dates & evidence
                        </Button>
                    )}
                </div>,
                <div key="source" className="space-y-1 text-xs">
                    <p>
                        {version?.evidence_reference ||
                            version?.applicability_basis ||
                            'Evidence not recorded'}
                    </p>
                    <p className="text-muted-foreground">
                        {version?.recorded_by_name ?? 'Author not recorded'}
                    </p>
                    {version && (
                        <p className="text-muted-foreground">
                            {formatDateTime(version.created_at)}
                        </p>
                    )}
                </div>,
            ],
            actions: [
                ...(canManage
                    ? [
                          {
                              label: 'Update dates & evidence',
                              icon: CalendarDays,
                              onClick: () => onEdit(record, 1),
                          },
                          {
                              label: 'Review applicability & outcome',
                              icon: ShieldCheck,
                              onClick: () => onEdit(record, 0),
                          },
                      ]
                    : []),
                {
                    label: 'View source history',
                    icon: History,
                    onClick: () => onHistory(record),
                },
                ...(canViewDocuments
                    ? [
                          {
                              label: 'Vehicle documents',
                              icon: Paperclip,
                              onClick: onDocuments,
                          },
                      ]
                    : []),
            ],
        };
    });
    return (
        <section
            className="rounded-xl border bg-card p-5 text-card-foreground"
            aria-labelledby="vehicle-compliance-heading"
        >
            <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p className="mb-2 text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                        Evidence & obligations
                    </p>
                    <h2
                        id="vehicle-compliance-heading"
                        className="text-lg font-semibold"
                    >
                        Service & compliance
                    </h2>
                </div>
                <div className="flex flex-wrap items-center gap-3">
                    <VehicleCollectionToggle
                        label="Compliance"
                        view={view}
                        onChange={setView}
                    />
                    <Button variant="outline" onClick={onSchedules}>
                        <Wrench className="size-4" />
                        Service schedules
                    </Button>
                </div>
            </div>
            <VehicleRecordCollection
                label="Vehicle requirements"
                view={view}
                records={rows}
                columns={[
                    { label: 'Recorded status', width: '0.95fr' },
                    { label: 'Next due / coverage', width: '1.4fr' },
                    { label: 'Source & author', width: '1.3fr' },
                ]}
                empty="No requirements are available for this vehicle. Reload the record to review its assessment."
            />
            <div className="mt-5 flex flex-wrap items-center justify-between gap-3 border-t pt-4 text-xs text-muted-foreground">
                <span>
                    Reminders have their own owner and delivery history.
                </span>
                <Button variant="ghost" size="sm" onClick={onReminders}>
                    Review reminders
                    <ArrowRight className="size-4" />
                </Button>
            </div>
        </section>
    );
}
