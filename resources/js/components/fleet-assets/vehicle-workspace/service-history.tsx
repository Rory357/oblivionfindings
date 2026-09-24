import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly } from '@/lib/datetime';
import {
    Clock3,
    FileText,
    Paperclip,
    Upload,
    Wrench,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { AddEvidenceDialog } from './add-evidence-dialog';
import {
    useVehicleCollectionView,
    VehicleCollectionToggle,
    VehicleRecordCollection,
} from './record-collection';
import {
    openWorkOrder,
    SectionHeading,
    SourceRecordDialog,
} from './studio-kit';
import type { ServiceHistoryRow, VehicleWorkspace } from './types';
import { StudioNotice } from './wizard-kit';
import { WorkEvidenceDialog } from './work-evidence-dialog';
import { formatKm, type WorkspaceLocation } from './workspace-model';

type Filter = 'all' | 'completed' | 'cancelled';

function outcome(row: ServiceHistoryRow): string {
    if (row.status === 'cancelled') return 'Cancelled';
    return row.awaiting_release ? 'Completed · awaiting release' : 'Completed';
}

/**
 * Service & work history, shared by Service & compliance › Service history
 * and Maintenance › Historical work as in the approved design.
 */
export function HistoryPanel({
    workspace,
    back,
    onChanged,
}: {
    workspace: VehicleWorkspace;
    /** Where the work order's back link returns to. */
    back: WorkspaceLocation;
    onChanged: () => void;
}) {
    const { view, setView } = useVehicleCollectionView('service-history');
    const [filter, setFilter] = useState<Filter>('all');
    const [query, setQuery] = useState('');
    const [record, setRecord] = useState<ServiceHistoryRow | null>(null);
    const [upload, setUpload] = useState<ServiceHistoryRow | null>(null);
    const { vehicle, can, work } = workspace;
    const rows = useMemo(() => {
        const needle = query.trim().toLowerCase();
        return workspace.service_history.filter((row) => {
            if (filter === 'completed' && row.status === 'cancelled')
                return false;
            if (filter === 'cancelled' && row.status !== 'cancelled')
                return false;
            if (!needle) return true;
            return [row.title, row.reference, row.provider, row.notes]
                .filter(Boolean)
                .some((text) => String(text).toLowerCase().includes(needle));
        });
    }, [workspace.service_history, filter, query]);
    // Evidence for work goes to its work order; a recorded service keeps its own files.
    const canUpload = (row: ServiceHistoryRow) =>
        row.kind === 'work' ? can.schedule_service : can.manage_documents;
    const open = (row: ServiceHistoryRow) => {
        if (row.work_order_id && work.can_view)
            openWorkOrder(row.work_order_id, vehicle.id, back);
        else setRecord(row);
    };

    return (
        <section className="studio-card history-studio">
            <SectionHeading
                eyebrow="VEHICLE RECORD"
                title="Service & work history"
            >
                <div className="studio-inline">
                    <Input
                        aria-label="Find service history"
                        placeholder="Find a service or reference…"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                    <Select
                        value={filter}
                        onValueChange={(value) => setFilter(value as Filter)}
                    >
                        <SelectTrigger
                            className="w-40"
                            aria-label="Service history filter"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All outcomes</SelectItem>
                            <SelectItem value="completed">Completed</SelectItem>
                            <SelectItem value="cancelled">Cancelled</SelectItem>
                        </SelectContent>
                    </Select>
                    <VehicleCollectionToggle
                        label="Service history"
                        view={view}
                        onChange={setView}
                    />
                </div>
            </SectionHeading>
            {!can.view_maintenance && (
                <StudioNotice title="Maintenance work stays with the vehicle’s Site">
                    Recorded services are listed here. Completed and cancelled
                    Maintenance work is shown to people with Maintenance access
                    at{' '}
                    {vehicle.home_site?.name ??
                        vehicle.site?.name ??
                        'its Site'}
                    .
                </StudioNotice>
            )}
            <div className="history-summary">
                <div>
                    <Wrench className="size-[18px]" aria-hidden />
                    <strong>
                        {
                            rows.filter((row) => row.status !== 'cancelled')
                                .length
                        }
                    </strong>
                    <span>Completed records</span>
                </div>
                <div>
                    <Paperclip className="size-[18px]" aria-hidden />
                    <strong>
                        {
                            rows.filter(
                                (row) =>
                                    row.files > 0 || !!row.evidence_reference,
                            ).length
                        }
                    </strong>
                    <span>Source evidence</span>
                </div>
                <div>
                    <Clock3 className="size-[18px]" aria-hidden />
                    <strong>
                        {
                            rows.filter((row) => row.status === 'cancelled')
                                .length
                        }
                    </strong>
                    <span>Cancelled visits</span>
                </div>
            </div>
            <VehicleRecordCollection
                label="Service & work records"
                view={view}
                columns={[
                    { label: 'Date / mileage' },
                    { label: 'Outcome', width: '1.1fr' },
                    { label: 'Evidence & notes', width: '1.6fr' },
                ]}
                empty={{
                    title: 'No matching history',
                    description:
                        'Completed services and cancelled visits will appear here.',
                }}
                records={rows.map((row) => ({
                    id: row.key,
                    name: row.title,
                    subline: `${row.reference ?? (row.kind === 'service' ? 'Recorded service' : `Work #${row.id}`)} · ${row.provider || 'Provider not recorded'}`,
                    icon: row.status === 'cancelled' ? XCircle : Wrench,
                    tone: row.awaiting_release ? 'warning' : undefined,
                    fields: [
                        <>
                            <strong>
                                {row.date
                                    ? formatDateOnly(row.date)
                                    : 'Undated'}
                            </strong>
                            <small>
                                {row.odometer_km !== null
                                    ? formatKm(row.odometer_km)
                                    : 'No mileage recorded'}
                            </small>
                        </>,
                        <StatusBadge
                            key="outcome"
                            variant={
                                row.status === 'cancelled'
                                    ? 'neutral'
                                    : row.awaiting_release
                                      ? 'warning'
                                      : 'success'
                            }
                        >
                            {outcome(row)}
                        </StatusBadge>,
                        <>
                            <span>
                                {row.notes ||
                                    'Completion evidence and original source retained.'}
                            </span>
                            <small>
                                {row.evidence_reference ||
                                    (row.files
                                        ? `${row.files} ${row.files === 1 ? 'file' : 'files'} kept`
                                        : row.status === 'cancelled'
                                          ? 'Cancellation reason retained'
                                          : 'No evidence reference recorded')}
                            </small>
                            {canUpload(row) && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setUpload(row)}
                                >
                                    <Upload className="size-[14px]" />
                                    Upload evidence
                                </Button>
                            )}
                        </>,
                    ],
                    onOpen: () => open(row),
                    footer: {
                        primary: row.provider || 'Provider not recorded',
                        secondary: 'Open the original service record',
                    },
                    actions: [
                        {
                            label: 'Open service record',
                            icon: row.work_order_id ? Wrench : FileText,
                            onClick: () => open(row),
                        },
                        ...(canUpload(row)
                            ? [
                                  {
                                      label: 'Upload evidence',
                                      icon: Upload,
                                      onClick: () => setUpload(row),
                                  },
                              ]
                            : []),
                    ],
                }))}
            />
            {record && (
                <SourceRecordDialog
                    title={record.title}
                    description={`${[vehicle.asset_tag, vehicle.name].filter(Boolean).join(' · ')} · Service record`}
                    rows={[
                        ['Outcome', outcome(record)],
                        [
                            'Date',
                            record.date
                                ? formatDateOnly(record.date)
                                : 'Undated',
                        ],
                        [
                            'Mileage',
                            record.odometer_km !== null
                                ? formatKm(record.odometer_km)
                                : 'No mileage recorded',
                        ],
                        [
                            'Provider',
                            record.provider || 'Provider not recorded',
                        ],
                        [
                            'Evidence',
                            record.evidence_reference ||
                                (record.files
                                    ? `${record.files} ${record.files === 1 ? 'file' : 'files'} kept`
                                    : 'No evidence reference recorded'),
                        ],
                        ['Notes', record.notes || 'None recorded'],
                        ['Recorded by', record.recorded_by || 'Not recorded'],
                    ]}
                    onClose={() => setRecord(null)}
                />
            )}
            {upload?.kind === 'work' && upload.work_order_id && (
                <WorkEvidenceDialog
                    workOrderId={upload.work_order_id}
                    workLabel={upload.reference ?? upload.title}
                    onClose={() => setUpload(null)}
                    onSaved={onChanged}
                />
            )}
            {upload?.kind === 'service' && (
                <AddEvidenceDialog
                    vehicle={vehicle}
                    title={`Upload evidence · ${upload.title}`}
                    category="Service evidence"
                    sourceType="service_completion"
                    sourceId={upload.id}
                    onClose={() => setUpload(null)}
                    onSaved={onChanged}
                />
            )}
        </section>
    );
}
