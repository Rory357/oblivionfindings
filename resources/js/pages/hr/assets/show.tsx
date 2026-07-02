/* eslint-disable no-restricted-syntax -- The asset detail mirrors the hub chrome:
 * a compact brand header with lifecycle action buttons over sub-tabbed sections
 * (Details · History · Maintenance · Documents · Activity). Rows are custom
 * layouts, not shadcn <Card> cases; colours stay token-based. Zero confirm():
 * every lifecycle action is a reviewed wizard modal. */
import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    Boxes,
    CheckCircle2,
    ExternalLink,
    FileText,
    Pencil,
    QrCode,
    RotateCcw,
    Trash2,
    Truck,
    Upload,
    UserCheck,
    Wrench,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import {
    categoryIcon,
    categoryLabel,
    fdate,
    nzd,
    PersonAvatar,
    StatusPill,
    type AssetStatus,
    type CategoryOption,
    type StaffOption,
} from '@/components/hr/asset-parts';
import {
    AssetWizard,
    type AssetModal,
    type EditableAsset,
} from '@/components/hr/asset-wizards';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';

interface AssignmentHistory {
    id: number;
    assignee: string | null;
    assigned_at: string | null;
    returned_at: string | null;
    due_at: string | null;
    condition_on_assign: string | null;
    condition_on_return: string | null;
    assigned_by: string | null;
}

interface MaintenanceLog {
    id: number;
    type: string;
    vendor: string | null;
    cost: number | null;
    sent_at: string | null;
    expected_back_at: string | null;
    completed_at: string | null;
    outcome: string | null;
    notes: string | null;
}

interface DocumentRow {
    id: number;
    title: string;
    category: string;
    effective_at: string | null;
    expiry_at: string | null;
    uploaded_by: string | null;
    created_at: string | null;
}

interface AssetDetail {
    id: number;
    tag: string;
    name: string;
    category: string;
    status: AssetStatus;
    make: string | null;
    model: string | null;
    serial: string | null;
    cost: number | null;
    supplier: string | null;
    purchase_date: string | null;
    warranty: string | null;
    condition: string | null;
    depreciation_method: string | null;
    useful_life_years: number | null;
    qr_token: string | null;
    fleet: boolean;
    fleet_asset: { id: number; name: string; asset_tag: string | null; registration_number: string | null; status: string } | null;
    notes: string | null;
    disposal_reason: string | null;
    disposed_at: string | null;
    disposal_value: number | null;
    current_assignment: { assignment_id: number; assignee: string | null; role: string | null; since: string | null; due_by: string | null } | null;
    assignments: AssignmentHistory[];
    maintenance_logs: MaintenanceLog[];
    documents: DocumentRow[];
}

interface FleetIncidentRow {
    id: number;
    reference: string;
    title: string;
    summary: string | null;
    severity: string;
    status: string;
    occurred_at: string | null;
}

interface Props {
    asset: AssetDetail;
    staff: StaffOption[];
    categories: CategoryOption[];
    fleetIncidents: FleetIncidentRow[];
    can: { manage: boolean; view_fleet: boolean; view_fleet_incidents: boolean };
}

type DetailTab = 'details' | 'history' | 'maintenance' | 'documents' | 'activity';

const TABS: Array<{ id: DetailTab; label: string }> = [
    { id: 'details', label: 'Details' },
    { id: 'history', label: 'Assignment history' },
    { id: 'maintenance', label: 'Maintenance' },
    { id: 'documents', label: 'Documents' },
    { id: 'activity', label: 'Activity' },
];

const DOC_CATEGORIES = [
    { value: 'invoice', label: 'Invoice / receipt' },
    { value: 'certificate', label: 'Certificate / warranty' },
    { value: 'manual', label: 'Manual' },
    { value: 'handover', label: 'Handover form' },
    { value: 'photo', label: 'Photo' },
];

export default function AssetShow({ asset, staff, categories, fleetIncidents, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Asset Management', href: '/hr/assets' },
        { title: asset.tag, href: `/hr/assets/${asset.id}` },
    ];

    const [tab, setTab] = useState<DetailTab>('details');
    const [modal, setModal] = useState<AssetModal | null>(null);
    const Icon = categoryIcon(asset.category);

    const editable: EditableAsset = {
        id: asset.id,
        tag: asset.tag,
        name: asset.name,
        category: asset.category,
        make: asset.make,
        model: asset.model,
        serial: asset.serial,
        cost: asset.cost,
        supplier: asset.supplier,
        warranty: asset.warranty,
        purchase_date: asset.purchase_date,
        condition: asset.condition,
        depreciation_method: asset.depreciation_method,
        useful_life_years: asset.useful_life_years,
        fleet_asset_id: asset.fleet_asset?.id ?? null,
        qr_token: asset.qr_token,
    };

    const ref = { id: asset.id, name: asset.name, tag: asset.tag };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${asset.name} · Asset`} />

            <PageShell>
                {/* compact header */}
                <div
                    className="relative overflow-hidden rounded-[20px] p-6 text-primary-foreground"
                    style={{
                        background:
                            'linear-gradient(120deg, color-mix(in oklch, var(--primary) 72%, black 22%), var(--primary) 58%, color-mix(in oklch, var(--primary) 90%, white 8%))',
                    }}
                >
                    <button
                        type="button"
                        onClick={() => router.visit('/hr/assets')}
                        className="mb-3 inline-flex items-center gap-1.5 text-[12.5px] font-semibold text-primary-foreground/80 hover:text-primary-foreground"
                    >
                        <ArrowLeft className="h-4 w-4" /> Asset Management
                    </button>
                    <div className="flex flex-wrap items-start gap-4">
                        <span className="grid h-14 w-14 flex-none place-items-center rounded-2xl border border-primary-foreground/20 bg-primary-foreground/15">
                            <Icon className="h-7 w-7" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2.5">
                                <h1 className="text-[24px] leading-tight font-extrabold tracking-tight">{asset.name}</h1>
                                <StatusPill status={asset.status} />
                            </div>
                            <div className="mt-1 font-mono text-[12.5px] text-primary-foreground/75">
                                {asset.tag}{asset.serial ? ` · ${asset.serial}` : ''} · {categoryLabel(asset.category)}
                            </div>
                            {asset.fleet && asset.fleet_asset ? (
                                can.view_fleet ? (
                                    <a
                                        href={`/fleet-assets/assets/${asset.fleet_asset.id}`}
                                        className="mt-2 inline-flex items-center gap-1.5 rounded-[8px] border border-primary-foreground/25 bg-primary-foreground/[0.12] px-2.5 py-1 text-[12px] font-semibold text-primary-foreground hover:bg-primary-foreground/20"
                                    >
                                        <Truck className="h-3.5 w-3.5" /> Linked to Fleet register
                                        <ExternalLink className="h-3 w-3" />
                                    </a>
                                ) : (
                                    <span className="mt-2 inline-flex items-center gap-1.5 rounded-[8px] border border-primary-foreground/25 bg-primary-foreground/[0.12] px-2.5 py-1 text-[12px] font-semibold text-primary-foreground">
                                        <Truck className="h-3.5 w-3.5" /> Linked to Fleet register
                                    </span>
                                )
                            ) : null}
                        </div>

                        {/* actions */}
                        <div className="flex flex-wrap gap-2">
                            <ActionBtn icon={QrCode} label="Print QR" onClick={() => window.open(`/hr/assets/${asset.id}/qr.svg`, '_blank')} />
                            {can.manage && !asset.fleet ? (
                                <ActionBtn icon={Pencil} label="Edit" onClick={() => setModal({ type: 'new', asset: editable })} />
                            ) : null}
                            {can.manage && asset.status === 'available' ? (
                                <ActionBtn primary icon={UserCheck} label="Assign" onClick={() => setModal({ type: 'assign', asset: ref })} />
                            ) : null}
                            {can.manage && asset.status === 'assigned' && asset.current_assignment ? (
                                <ActionBtn primary icon={RotateCcw} label="Return" onClick={() => setModal({ type: 'return', assignmentId: asset.current_assignment!.assignment_id, asset: { ...ref, assignee: asset.current_assignment!.assignee } })} />
                            ) : null}
                            {can.manage && asset.status !== 'retired' && asset.status !== 'maintenance' && !asset.fleet ? (
                                <ActionBtn icon={Wrench} label="Log repair" onClick={() => setModal({ type: 'maintenance', asset: ref })} />
                            ) : null}
                            {can.manage && asset.status === 'maintenance' ? (
                                <ActionBtn primary icon={CheckCircle2} label="Return to service" onClick={() => setModal({ type: 'rfs', asset: ref })} />
                            ) : null}
                            {can.manage && (asset.status === 'available' || asset.status === 'maintenance') && !asset.fleet ? (
                                <ActionBtn icon={Trash2} label="Retire" onClick={() => setModal({ type: 'retire', asset: ref })} />
                            ) : null}
                        </div>
                    </div>
                </div>

                {/* sub-tabs */}
                <div className="mt-5 mb-4 flex flex-wrap gap-1.5 border-b border-border">
                    {TABS.map((t) => (
                        <button
                            key={t.id}
                            type="button"
                            onClick={() => setTab(t.id)}
                            className={cn('relative px-3 py-2 text-[13px] font-semibold transition-colors', tab === t.id ? 'text-primary' : 'text-muted-foreground hover:text-foreground')}
                        >
                            {t.label}
                            {tab === t.id ? <span className="absolute -bottom-px left-0 h-0.5 w-full rounded-full bg-primary" /> : null}
                        </button>
                    ))}
                </div>

                {tab === 'details' && (
                    <>
                        <DetailsTab asset={asset} />
                        {asset.fleet && can.view_fleet_incidents && fleetIncidents.length > 0 ? (
                            <FleetIncidentsPanel incidents={fleetIncidents} />
                        ) : null}
                    </>
                )}
                {tab === 'history' && <HistoryTab assignments={asset.assignments} />}
                {tab === 'maintenance' && <MaintenanceLogsTab logs={asset.maintenance_logs} />}
                {tab === 'documents' && <DocumentsTab asset={asset} canManage={can.manage} />}
                {tab === 'activity' && <ActivityTab asset={asset} />}
            </PageShell>

            <AssetWizard modal={modal} staff={staff} categories={categories} onClose={() => setModal(null)} />
        </AppLayout>
    );
}

function ActionBtn({ icon: Icon, label, onClick, primary }: { icon: typeof QrCode; label: string; onClick: () => void; primary?: boolean }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'inline-flex h-9 items-center gap-2 rounded-[9px] px-3 text-[12.5px] font-bold transition-colors',
                primary
                    ? 'bg-primary-foreground text-primary hover:scale-[1.02]'
                    : 'border border-primary-foreground/[0.28] bg-primary-foreground/[0.12] text-primary-foreground hover:bg-primary-foreground/20',
            )}
        >
            <Icon className="h-4 w-4" /> {label}
        </button>
    );
}

function Panel({ children, className }: { children: React.ReactNode; className?: string }) {
    return <div className={cn('rounded-2xl border border-border bg-card p-5 shadow-[0_1px_2px_rgba(0,0,0,0.04)]', className)}>{children}</div>;
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex justify-between gap-4 border-b border-border py-2 last:border-b-0">
            <span className="text-[12.5px] text-muted-foreground">{label}</span>
            <span className="text-right text-[12.5px] font-semibold">{value ?? '—'}</span>
        </div>
    );
}

function DetailsTab({ asset }: { asset: AssetDetail }) {
    return (
        <div className="grid gap-4 lg:grid-cols-[1.3fr_1fr]">
            <Panel>
                <div className="mb-3 text-[15px] font-bold">Specifications &amp; purchase</div>
                <Row label="Make / model" value={[asset.make, asset.model].filter(Boolean).join(' ') || '—'} />
                <Row label="Serial number" value={asset.serial ? <span className="font-mono">{asset.serial}</span> : '—'} />
                <Row label="Condition at intake" value={asset.condition ?? '—'} />
                <Row label="Purchase date" value={fdate(asset.purchase_date)} />
                <Row label="Purchase cost" value={nzd(asset.cost)} />
                <Row label="Supplier" value={asset.supplier ?? '—'} />
                <Row label="Warranty expiry" value={fdate(asset.warranty)} />
                <Row label="Depreciation" value={asset.depreciation_method === 'diminishing' ? 'Diminishing value' : asset.depreciation_method === 'straight' ? 'Straight-line' : '—'} />
                <Row label="Useful life" value={asset.useful_life_years ? `${asset.useful_life_years} years` : '—'} />
                {asset.status === 'retired' ? (
                    <>
                        <Row label="Disposal reason" value={asset.disposal_reason ?? '—'} />
                        <Row label="Disposed" value={fdate(asset.disposed_at)} />
                        <Row label="Disposal value" value={nzd(asset.disposal_value)} />
                    </>
                ) : null}
                {asset.notes ? (
                    <div className="mt-3 rounded-xl bg-muted/50 p-3 text-[13px] text-muted-foreground">{asset.notes}</div>
                ) : null}
            </Panel>

            <Panel>
                <div className="mb-3 text-[15px] font-bold">Current assignment</div>
                {asset.current_assignment ? (
                    <div className="flex items-center gap-3">
                        <PersonAvatar name={asset.current_assignment.assignee} size={42} />
                        <div className="min-w-0">
                            <div className="text-[14px] font-bold">{asset.current_assignment.assignee}</div>
                            <div className="text-[12px] text-muted-foreground">{asset.current_assignment.role ?? '—'}</div>
                            <div className="mt-1 text-[12px] text-muted-foreground">
                                Since {fdate(asset.current_assignment.since)}
                                {asset.current_assignment.due_by ? ` · due ${fdate(asset.current_assignment.due_by)}` : ''}
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className="py-6 text-center text-[13px] text-muted-foreground">Not currently assigned.</div>
                )}
            </Panel>
        </div>
    );
}

const INCIDENT_SEV_CLS: Record<string, string> = {
    minor: 'bg-status-success-bg text-status-success',
    moderate: 'bg-status-warning-bg text-status-warning',
    major: 'bg-status-critical-bg text-status-critical',
    critical: 'bg-status-critical-bg text-status-critical',
};

/** Recent Fleet incidents against the linked canonical asset (read-through). */
function FleetIncidentsPanel({ incidents }: { incidents: FleetIncidentRow[] }) {
    return (
        <Panel className="mt-4">
            <div className="mb-3 text-[15px] font-bold">Fleet incidents</div>
            <div className="flex flex-col">
                {incidents.map((i, idx) => (
                    <a
                        key={i.id}
                        href={`/fleet-assets/incidents/${i.id}`}
                        className={cn('flex items-center gap-3 py-2.5 transition-colors hover:bg-accent/50', idx ? 'border-t border-border' : '')}
                    >
                        <span className={cn('grid h-9 w-9 flex-none place-items-center rounded-[9px]', INCIDENT_SEV_CLS[i.severity] ?? 'bg-muted text-muted-foreground')}>
                            <AlertTriangle className="h-4 w-4" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-mono text-[12px] font-semibold text-muted-foreground">{i.reference}</span>
                                <span className="text-[13px] font-bold">{i.title}</span>
                                <span className={cn('rounded-full px-2 py-px text-[11px] font-bold capitalize', INCIDENT_SEV_CLS[i.severity] ?? 'bg-muted text-muted-foreground')}>
                                    {i.severity}
                                </span>
                                <span className="rounded-full bg-muted px-2 py-px text-[11px] font-bold capitalize text-muted-foreground">{i.status}</span>
                            </div>
                            <div className="mt-0.5 text-[12px] text-muted-foreground">
                                {fdate(i.occurred_at)}
                                {i.summary ? ` · ${i.summary}` : ''}
                            </div>
                        </div>
                        <ExternalLink className="h-3.5 w-3.5 flex-none text-muted-foreground" />
                    </a>
                ))}
            </div>
        </Panel>
    );
}

function HistoryTab({ assignments }: { assignments: AssignmentHistory[] }) {
    return (
        <Panel>
            <div className="mb-3 text-[15px] font-bold">Assignment history</div>
            {assignments.length === 0 ? (
                <div className="py-8 text-center text-[13px] text-muted-foreground">No assignments recorded yet.</div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[640px] border-collapse text-[13px]">
                        <thead>
                            <tr className="border-b border-border text-left text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                                <th className="py-2 pr-3">Employee</th>
                                <th className="py-2 pr-3">Assigned</th>
                                <th className="py-2 pr-3">Returned</th>
                                <th className="py-2 pr-3">Condition</th>
                                <th className="py-2">By</th>
                            </tr>
                        </thead>
                        <tbody>
                            {assignments.map((a) => (
                                <tr key={a.id} className="border-b border-border last:border-b-0">
                                    <td className="py-2.5 pr-3 font-semibold">{a.assignee ?? '—'}</td>
                                    <td className="py-2.5 pr-3 text-muted-foreground">{fdate(a.assigned_at)}</td>
                                    <td className="py-2.5 pr-3 text-muted-foreground">{a.returned_at ? fdate(a.returned_at) : <span className="font-semibold text-status-info">Active</span>}</td>
                                    <td className="py-2.5 pr-3 text-muted-foreground">
                                        {[a.condition_on_assign, a.condition_on_return].filter(Boolean).join(' → ') || '—'}
                                    </td>
                                    <td className="py-2.5 text-muted-foreground">{a.assigned_by ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Panel>
    );
}

function MaintenanceLogsTab({ logs }: { logs: MaintenanceLog[] }) {
    return (
        <Panel>
            <div className="mb-3 text-[15px] font-bold">Maintenance history</div>
            {logs.length === 0 ? (
                <div className="py-8 text-center text-[13px] text-muted-foreground">No repairs or services logged.</div>
            ) : (
                <div className="flex flex-col">
                    {logs.map((log, i) => (
                        <div key={log.id} className={cn('flex items-start gap-3 py-3', i ? 'border-t border-border' : '')}>
                            <span className="grid h-9 w-9 flex-none place-items-center rounded-[10px] bg-status-warning-bg text-status-warning">
                                <Wrench className="h-4 w-4" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-[13px] font-bold capitalize">{log.type}</span>
                                    {log.vendor ? <span className="text-[12.5px] text-muted-foreground">· {log.vendor}</span> : null}
                                    {log.cost != null ? <span className="text-[12.5px] font-semibold tabular-nums">· {nzd(log.cost)}</span> : null}
                                    {log.completed_at ? (
                                        <span className="rounded-full bg-status-success-bg px-2 py-px text-[11px] font-bold text-status-success">Closed</span>
                                    ) : (
                                        <span className="rounded-full bg-status-warning-bg px-2 py-px text-[11px] font-bold text-status-warning">Open</span>
                                    )}
                                </div>
                                <div className="mt-0.5 text-[12px] text-muted-foreground">
                                    Sent {fdate(log.sent_at)}
                                    {log.expected_back_at ? ` · expected ${fdate(log.expected_back_at)}` : ''}
                                    {log.completed_at ? ` · completed ${fdate(log.completed_at)}` : ''}
                                    {log.outcome ? ` · ${log.outcome}` : ''}
                                </div>
                                {log.notes ? <div className="mt-1 text-[12.5px]">{log.notes}</div> : null}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </Panel>
    );
}

function DocumentsTab({ asset, canManage }: { asset: AssetDetail; canManage: boolean }) {
    const fileRef = useRef<HTMLInputElement>(null);
    const form = useForm<{ title: string; category: string; file: File | null }>({
        title: '',
        category: 'invoice',
        file: null,
    });

    const submit = () => {
        if (!form.data.file) {
            toast.error('Choose a file to upload.');
            return;
        }
        form.post(`/hr/assets/${asset.id}/documents`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                if (fileRef.current) fileRef.current.value = '';
                toast.success('Document uploaded.');
            },
        });
    };

    const remove = (id: number) =>
        router.delete(`/hr/assets/documents/${id}`, { preserveScroll: true });

    return (
        <div className="flex flex-col gap-4">
            {canManage ? (
                <Panel>
                    <div className="mb-3 text-[15px] font-bold">Upload a document</div>
                    <div className="grid gap-3 sm:grid-cols-[1.4fr_1fr_auto]">
                        <Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="e.g. AppleCare certificate" />
                        <Select value={form.data.category} onValueChange={(v) => form.setData('category', v)}>
                            <SelectTrigger aria-label="Document category">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {DOC_CATEGORIES.map((c) => (
                                    <SelectItem key={c.value} value={c.value}>{c.label}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <input
                            ref={fileRef}
                            type="file"
                            onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)}
                            className="text-[12.5px] file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-1.5 file:text-[12.5px] file:font-semibold"
                        />
                    </div>
                    <div className="mt-3 flex items-center gap-3">
                        <Button onClick={submit} disabled={form.processing || !form.data.title || !form.data.file}>
                            <Upload className="h-4 w-4" /> {form.processing ? 'Uploading…' : 'Upload'}
                        </Button>
                        <span className="text-[11.5px] text-muted-foreground">PDF, image or Office file up to 20 MB. Stored privately.</span>
                    </div>
                    {form.errors.file ? <div className="mt-2 text-[12px] text-status-critical">{form.errors.file}</div> : null}
                </Panel>
            ) : null}

            <Panel>
                <div className="mb-3 text-[15px] font-bold">Document library</div>
                {asset.documents.length === 0 ? (
                    <div className="py-8 text-center text-[13px] text-muted-foreground">No documents attached yet.</div>
                ) : (
                    <div className="flex flex-col">
                        {asset.documents.map((d, i) => (
                            <div key={d.id} className={cn('flex items-center gap-3 py-2.5', i ? 'border-t border-border' : '')}>
                                <span className="grid h-9 w-9 flex-none place-items-center rounded-[9px] bg-muted text-muted-foreground">
                                    <FileText className="h-4 w-4" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="truncate text-[13px] font-semibold">{d.title}</div>
                                    <div className="text-[11.5px] text-muted-foreground capitalize">
                                        {d.category}{d.uploaded_by ? ` · ${d.uploaded_by}` : ''}{d.created_at ? ` · ${fdate(d.created_at)}` : ''}
                                    </div>
                                </div>
                                <a href={`/hr/assets/documents/${d.id}/download`} className="rounded-md border border-border px-2.5 py-1 text-[12px] font-semibold hover:bg-accent">Download</a>
                                {canManage ? (
                                    <button type="button" onClick={() => remove(d.id)} aria-label="Remove document" className="grid h-8 w-8 place-items-center rounded-md text-muted-foreground hover:bg-status-critical-bg hover:text-status-critical">
                                        <Trash2 className="h-4 w-4" />
                                    </button>
                                ) : null}
                            </div>
                        ))}
                    </div>
                )}
            </Panel>
        </div>
    );
}

function ActivityTab({ asset }: { asset: AssetDetail }) {
    const events = useMemo(() => {
        const out: Array<{ at: string | null; icon: typeof Boxes; tone: string; text: string }> = [];
        for (const a of asset.assignments) {
            if (a.assigned_at) out.push({ at: a.assigned_at, icon: UserCheck, tone: 'var(--primary)', text: `Assigned to ${a.assignee ?? 'staff'}` });
            if (a.returned_at) out.push({ at: a.returned_at, icon: RotateCcw, tone: 'var(--status-info)', text: `Returned by ${a.assignee ?? 'staff'}` });
        }
        for (const m of asset.maintenance_logs) {
            if (m.sent_at) out.push({ at: m.sent_at, icon: Wrench, tone: 'var(--status-warning)', text: `Sent to ${m.vendor ?? 'repair'} (${m.type})` });
            if (m.completed_at) out.push({ at: m.completed_at, icon: CheckCircle2, tone: 'var(--status-success)', text: `Returned to service${m.outcome ? ` · ${m.outcome}` : ''}` });
        }
        for (const d of asset.documents) {
            if (d.created_at) out.push({ at: d.created_at, icon: FileText, tone: 'var(--category-fleet)', text: `Document added · ${d.title}` });
        }
        return out.filter((e) => e.at).sort((a, b) => (b.at! > a.at! ? 1 : -1));
    }, [asset]);

    return (
        <Panel>
            <div className="mb-3 text-[15px] font-bold">Activity timeline</div>
            {events.length === 0 ? (
                <div className="py-8 text-center text-[13px] text-muted-foreground">No activity recorded yet.</div>
            ) : (
                <div className="flex flex-col gap-0.5">
                    {events.map((e, i) => {
                        const Icon = e.icon;
                        return (
                            <div key={i} className="flex items-center gap-3 py-2">
                                <span className="grid h-8 w-8 flex-none place-items-center rounded-[9px] bg-muted" style={{ color: e.tone }}>
                                    <Icon className="h-4 w-4" />
                                </span>
                                <span className="flex-1 text-[13px] font-medium">{e.text}</span>
                                <span className="flex-none text-[11.5px] text-muted-foreground">{fdate(e.at)}</span>
                            </div>
                        );
                    })}
                </div>
            )}
        </Panel>
    );
}
