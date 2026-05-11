import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    ArrowRightLeft,
    Battery,
    Calendar,
    Check,
    Clock,
    Edit,
    FileText,
    GitBranch,
    Link2,
    MapPin,
    Minus,
    Network,
    Plus,
    Settings,
    Shield,
    Trash2,
    Wrench,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';

// ── Types ─────────────────────────────────────────────────────────

type DeviceDetail = {
    id: number;
    device_uid: string;
    name: string;
    domain: string;
    category: string;
    subcategory: string | null;
    manufacturer: string | null;
    model: string | null;
    serial_number: string | null;
    mac_address: string | null;
    imei: string | null;
    asset_tag: string | null;
    firmware_version: string | null;
    ip_address: string | null;
    status: string;
    health_status: string;
    last_seen_at: string | null;
    last_signal_at: string | null;
    battery_level: number | null;
    battery_updated_at: string | null;
    commissioned_at: string | null;
    warranty_expires_at: string | null;
    next_service_due: string | null;
    expected_lifespan_months: number | null;
    purchase_price: string | null;
    provider: string | null;
    external_ref: Record<string, unknown> | null;
    config: Record<string, unknown> | null;
    meta: Record<string, unknown> | null;
    latitude: string | null;
    longitude: string | null;
    location_description: string | null;
    notes: string | null;
    created_at: string | null;
    created_by: { id: number; name: string } | null;
};

type Assignment = {
    id: number;
    assignable_type: string;
    assignable_id: number;
    assignment_type: string;
    assigned_at: string;
    expected_return_at: string | null;
    assignable_name: string;
};

type AssignmentHistoryItem = {
    id: number;
    assignable_type: string;
    assignable_name: string;
    assignment_type: string;
    assigned_at: string;
    expected_return_at: string | null;
    released_at: string | null;
    assigned_by: string | null;
    released_by: string | null;
    is_active: boolean;
    is_overdue: boolean;
    notes: string | null;
};

type AssetLink = { id: number; asset_id: number; asset_name: string | null; asset_tag: string | null; link_type: string; linked_at: string; notes: string | null };
type AvailableAsset = { id: number; name: string; asset_tag: string | null };
type LinkTypeOption = { value: string; label: string };
type EventItem = { id: number; event_type: string; severity: string; occurred_at: string; source: string | null };
type MaintenanceItem = { id: number; type: string; status: string; description: string; scheduled_for: string | null; completed_at: string | null };
type Relationship = { id: number; device_id: number; device_name: string | null; type: string; port: string | null };

type TargetEntity = { id: number; name: string; [key: string]: unknown };

type Props = {
    device: DeviceDetail;
    activeAssignment: Assignment | null;
    assignmentHistory: AssignmentHistoryItem[];
    assignmentTargets: {
        sites: TargetEntity[];
        rooms: Array<{ id: number; site_id: number; name: string }>;
        staff: TargetEntity[];
        clients: Array<{ id: number; first_name: string; last_name: string }>;
        vehicles: Array<{ id: number; name: string; registration_number: string | null }>;
    };
    assetLinks: AssetLink[];
    availableAssets: AvailableAsset[];
    linkTypes: LinkTypeOption[];
    relationshipTypes: LinkTypeOption[];
    otherDevices: Array<{ id: number; name: string; device_uid: string; category: string }>;
    documents: Array<{
        id: number;
        title: string;
        category: string;
        version: string | null;
        effective_date: string | null;
        expiry_date: string | null;
        original_name: string;
        mime_type: string;
        size_bytes: number;
        notes: string | null;
        uploaded_at: string | null;
        download_url: string;
    }>;
    documentCategories: LinkTypeOption[];
    recentEvents: EventItem[];
    maintenanceRecords: MaintenanceItem[];
    relationships: { parents: Relationship[]; children: Relationship[] };
    groups: Array<{ id: number; name: string }>;
    can: { update: boolean; delete: boolean; assign: boolean };
};

// ── Helpers ───────────────────────────────────────────────────────

function statusVariant(s: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (s) { case 'active': return 'default'; case 'offline': case 'decommissioned': case 'lost': return 'secondary'; default: return 'outline'; }
}
function healthVariant(h: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (h) { case 'healthy': return 'default'; case 'warning': return 'outline'; case 'critical': return 'destructive'; default: return 'secondary'; }
}
function domainLabel(d: string): string {
    return { security: 'Security', tracking: 'Tracking', iot_healthcare: 'IoT / Healthcare', it_infrastructure: 'IT Infrastructure', facilities: 'Facilities' }[d] ?? d;
}
function formatDate(iso: string | null): string {
    if (!iso) return '-';
    return new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}
function formatDateTime(iso: string | null): string {
    if (!iso) return '-';
    return new Date(iso).toLocaleString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
function targetTypeLabel(t: string): string {
    return { site: 'Site', room: 'Room', vehicle: 'Vehicle', staff: 'Staff', client: 'Client' }[t] ?? t;
}

// ── Component ─────────────────────────────────────────────────────

export default function DeviceShow({ device, activeAssignment, assignmentHistory, assignmentTargets, assetLinks, availableAssets, linkTypes, relationshipTypes, otherDevices, documents, documentCategories, recentEvents, maintenanceRecords, relationships, groups, can }: Props) {
    const totalRelationships = relationships.parents.length + relationships.children.length;

    // ── Asset-link dialog state ──────────────────────────────────
    const [linkOpen, setLinkOpen] = useState(false);
    const [linkAssetId, setLinkAssetId] = useState<string>('');
    const [linkType, setLinkType] = useState<string>(linkTypes[0]?.value ?? 'primary');
    const [linkNotes, setLinkNotes] = useState('');
    const [linkSubmitting, setLinkSubmitting] = useState(false);
    const [linkError, setLinkError] = useState('');
    const [unlinkingId, setUnlinkingId] = useState<number | null>(null);

    // Assets already linked to this device (active) — hide from picker.
    const linkedAssetIds = useMemo(() => new Set(assetLinks.map((l) => l.asset_id)), [assetLinks]);
    const pickableAssets = useMemo(
        () => availableAssets.filter((a) => !linkedAssetIds.has(a.id)),
        [availableAssets, linkedAssetIds],
    );

    const submitLink = () => {
        if (!linkAssetId) {
            setLinkError('Pick an asset to link.');
            return;
        }
        setLinkSubmitting(true);
        setLinkError('');
        router.post(
            `/security-devices/devices/${device.id}/asset-links`,
            {
                asset_id: Number(linkAssetId),
                link_type: linkType,
                notes: linkNotes || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setLinkOpen(false);
                    setLinkAssetId('');
                    setLinkType(linkTypes[0]?.value ?? 'primary');
                    setLinkNotes('');
                },
                onError: (errs) => {
                    const firstErr = Object.values(errs)[0];
                    setLinkError(Array.isArray(firstErr) ? firstErr[0] : String(firstErr ?? 'Failed to link asset.'));
                },
                onFinish: () => setLinkSubmitting(false),
            },
        );
    };

    const submitUnlink = (linkId: number) => {
        if (!confirm('Unlink this asset? The link history is preserved.')) return;
        setUnlinkingId(linkId);
        router.delete(
            `/security-devices/devices/${device.id}/asset-links/${linkId}`,
            {
                preserveScroll: true,
                onFinish: () => setUnlinkingId(null),
            },
        );
    };

    // ── Relationship dialog state ────────────────────────────────
    const [relOpen, setRelOpen] = useState(false);
    const [relOtherDeviceId, setRelOtherDeviceId] = useState<string>('');
    const [relType, setRelType] = useState<string>(relationshipTypes[0]?.value ?? 'connected_to');
    const [relDirection, setRelDirection] = useState<'downstream' | 'upstream'>('downstream');
    const [relPort, setRelPort] = useState('');
    const [relNotes, setRelNotes] = useState('');
    const [relSubmitting, setRelSubmitting] = useState(false);
    const [relError, setRelError] = useState('');
    const [unlinkingRelId, setUnlinkingRelId] = useState<number | null>(null);

    const submitRelationship = () => {
        if (!relOtherDeviceId) {
            setRelError('Pick a device to link.');
            return;
        }
        setRelSubmitting(true);
        setRelError('');
        router.post(
            `/security-devices/devices/${device.id}/relationships`,
            {
                other_device_id: Number(relOtherDeviceId),
                relationship_type: relType,
                direction: relDirection,
                port: relPort || null,
                notes: relNotes || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setRelOpen(false);
                    setRelOtherDeviceId('');
                    setRelType(relationshipTypes[0]?.value ?? 'connected_to');
                    setRelDirection('downstream');
                    setRelPort('');
                    setRelNotes('');
                },
                onError: (errs) => {
                    const firstErr = Object.values(errs)[0];
                    setRelError(Array.isArray(firstErr) ? firstErr[0] : String(firstErr ?? 'Failed to add relationship.'));
                },
                onFinish: () => setRelSubmitting(false),
            },
        );
    };

    const submitUnlinkRelationship = (relId: number) => {
        if (!confirm('Remove this relationship?')) return;
        setUnlinkingRelId(relId);
        router.delete(
            `/security-devices/devices/${device.id}/relationships/${relId}`,
            {
                preserveScroll: true,
                onFinish: () => setUnlinkingRelId(null),
            },
        );
    };

    // ── Document upload state ────────────────────────────────────
    const [docOpen, setDocOpen] = useState(false);
    const [docTitle, setDocTitle] = useState('');
    const [docCategory, setDocCategory] = useState(documentCategories[0]?.value ?? 'other');
    const [docVersion, setDocVersion] = useState('');
    const [docEffective, setDocEffective] = useState('');
    const [docExpiry, setDocExpiry] = useState('');
    const [docNotes, setDocNotes] = useState('');
    const [docFile, setDocFile] = useState<File | null>(null);
    const [docSubmitting, setDocSubmitting] = useState(false);
    const [docError, setDocError] = useState('');
    const [deletingDocId, setDeletingDocId] = useState<number | null>(null);

    const submitDocument = () => {
        if (!docFile) {
            setDocError('Choose a file.');
            return;
        }
        if (!docTitle.trim()) {
            setDocError('Title is required.');
            return;
        }
        setDocSubmitting(true);
        setDocError('');
        const formData = new FormData();
        formData.append('file', docFile);
        formData.append('title', docTitle);
        formData.append('category', docCategory);
        if (docVersion) formData.append('version', docVersion);
        if (docEffective) formData.append('effective_date', docEffective);
        if (docExpiry) formData.append('expiry_date', docExpiry);
        if (docNotes) formData.append('notes', docNotes);

        router.post(`/security-devices/devices/${device.id}/documents`, formData, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                setDocOpen(false);
                setDocTitle('');
                setDocCategory(documentCategories[0]?.value ?? 'other');
                setDocVersion('');
                setDocEffective('');
                setDocExpiry('');
                setDocNotes('');
                setDocFile(null);
            },
            onError: (errs) => {
                const firstErr = Object.values(errs)[0];
                setDocError(Array.isArray(firstErr) ? firstErr[0] : String(firstErr ?? 'Upload failed.'));
            },
            onFinish: () => setDocSubmitting(false),
        });
    };

    const submitDeleteDocument = (docId: number) => {
        if (!confirm('Delete this document? This cannot be undone.')) return;
        setDeletingDocId(docId);
        router.delete(
            `/security-devices/devices/${device.id}/documents/${docId}`,
            {
                preserveScroll: true,
                onFinish: () => setDeletingDocId(null),
            },
        );
    };

    const formatBytes = (n: number): string => {
        if (n < 1024) return `${n} B`;
        if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
        return `${(n / 1024 / 1024).toFixed(1)} MB`;
    };

    // ── Overview inline-edit state (notes + asset_tag + next_service_due) ──
    const [editingAssetTag, setEditingAssetTag] = useState(false);
    const [assetTagDraft, setAssetTagDraft] = useState(device.asset_tag ?? '');
    const [editingNotes, setEditingNotes] = useState(false);
    const [notesDraft, setNotesDraft] = useState(device.notes ?? '');
    const [editingServiceDue, setEditingServiceDue] = useState(false);
    const [serviceDueDraft, setServiceDueDraft] = useState(device.next_service_due ?? '');
    const [fieldSaving, setFieldSaving] = useState(false);

    const saveFields = (payload: Record<string, string | null>, onSuccess: () => void) => {
        setFieldSaving(true);
        router.patch(
            `/security-devices/devices/${device.id}/fields`,
            payload,
            {
                preserveScroll: true,
                onSuccess,
                onFinish: () => setFieldSaving(false),
            },
        );
    };

    // ── Maintenance dialog state ─────────────────────────────────
    const [maintOpen, setMaintOpen] = useState(false);
    const [maintType, setMaintType] = useState('scheduled_service');
    const [maintDesc, setMaintDesc] = useState('');
    const [maintDate, setMaintDate] = useState('');
    const [maintNotes, setMaintNotes] = useState('');
    const [maintSubmitting, setMaintSubmitting] = useState(false);
    const [maintError, setMaintError] = useState('');

    const submitMaintenance = () => {
        if (!maintDesc) { setMaintError('Description is required.'); return; }
        setMaintSubmitting(true);
        setMaintError('');
        router.post(`/security-devices/devices/${device.id}/maintenance`, {
            type: maintType,
            description: maintDesc,
            scheduled_for: maintDate || null,
            notes: maintNotes || null,
        }, {
            preserveScroll: true,
            onSuccess: () => { setMaintOpen(false); setMaintDesc(''); setMaintDate(''); setMaintNotes(''); },
            onError: (errors) => { setMaintError(Object.values(errors).flat().join(' ')); },
            onFinish: () => setMaintSubmitting(false),
        });
    };

    // ── Assign dialog state ───────────────────────────────────────
    const [assignOpen, setAssignOpen] = useState(false);
    const [assignType, setAssignType] = useState<string>('site');
    const [assignId, setAssignId] = useState<string>('');
    const [assignmentKind, setAssignmentKind] = useState<string>('permanent');
    const [returnDate, setReturnDate] = useState<string>('');
    const [assignNotes, setAssignNotes] = useState<string>('');
    const [submitting, setSubmitting] = useState(false);
    const [assignError, setAssignError] = useState<string>('');

    const targetOptions = useMemo(() => {
        switch (assignType) {
            case 'site': return (assignmentTargets.sites ?? []).map(s => ({ value: String(s.id), label: s.name }));
            case 'room': return (assignmentTargets.rooms ?? []).map(r => ({ value: String(r.id), label: r.name }));
            case 'staff': return (assignmentTargets.staff ?? []).map(s => ({ value: String(s.id), label: s.name }));
            case 'client': return (assignmentTargets.clients ?? []).map(c => ({ value: String(c.id), label: `${c.first_name} ${c.last_name}`.trim() }));
            case 'vehicle': return (assignmentTargets.vehicles ?? []).map(v => ({ value: String(v.id), label: v.registration_number ? `${v.name} (${v.registration_number})` : v.name }));
            default: return [];
        }
    }, [assignType, assignmentTargets]);

    const openAssignDialog = () => {
        setAssignType('site');
        setAssignId('');
        setAssignmentKind('permanent');
        setReturnDate('');
        setAssignNotes('');
        setAssignError('');
        setAssignOpen(true);
    };

    const submitAssign = () => {
        if (!assignId) { setAssignError('Please select a target.'); return; }
        setSubmitting(true);
        setAssignError('');
        router.post(`/security-devices/devices/${device.id}/assign`, {
            assignable_type: assignType,
            assignable_id: assignId,
            assignment_type: assignmentKind,
            expected_return_at: assignmentKind === 'loan' && returnDate ? returnDate : null,
            notes: assignNotes || null,
        }, {
            preserveScroll: true,
            onSuccess: () => { setAssignOpen(false); },
            onError: (errors) => { setAssignError(Object.values(errors).flat().join(' ')); },
            onFinish: () => setSubmitting(false),
        });
    };

    const submitRelease = () => {
        if (!confirm('Release this device from its current assignment? It will return to the pool.')) return;
        router.post(`/security-devices/devices/${device.id}/release`, {}, { preserveScroll: true });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'Devices', href: '/security-devices/devices' },
                { title: device.name },
            ]}
        >
            <Head title={`${device.name} - Security & Devices`} />

            <PageShell>
                <PageHeader
                    title={
                        <div className="flex flex-wrap items-center gap-3">
                            <span>{device.name}</span>
                            <Badge variant="outline" className="font-mono text-xs">{device.device_uid}</Badge>
                            <Badge variant={statusVariant(device.status)}>{device.status.replace(/_/g, ' ')}</Badge>
                            <Badge variant={healthVariant(device.health_status)}>{device.health_status}</Badge>
                        </div>
                    }
                    backHref="/security-devices/devices"
                    backLabel="Devices"
                    actions={
                        <div className="flex gap-2">
                            {can.update && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={`/security-devices/devices/${device.id}/edit`}>
                                        <Edit className="mr-2 h-4 w-4" /> Edit
                                    </Link>
                                </Button>
                            )}
                            {can.delete && device.status !== 'decommissioned' && (
                                <Button variant="outline" size="sm" onClick={() => { if (confirm(`Decommission "${device.name}"?`)) router.delete(`/security-devices/devices/${device.id}`); }}>
                                    <Trash2 className="mr-2 h-4 w-4" /> Decommission
                                </Button>
                            )}
                        </div>
                    }
                />

                <Tabs defaultValue="overview">
                    <TabsList>
                        <TabsTrigger value="overview">Overview</TabsTrigger>
                        <TabsTrigger value="assignments">Assignments ({assignmentHistory.length})</TabsTrigger>
                        <TabsTrigger value="events">Events ({recentEvents.length})</TabsTrigger>
                        <TabsTrigger value="maintenance">Maintenance ({maintenanceRecords.length})</TabsTrigger>
                        <TabsTrigger value="relationships">Topology ({totalRelationships})</TabsTrigger>
                        <TabsTrigger value="documents">Documents</TabsTrigger>
                    </TabsList>

                    {/* ── Overview tab ──────────────────────────── */}
                    <TabsContent value="overview" className="space-y-6">
                        <div className="grid gap-6 lg:grid-cols-2">
                            {/* Identity */}
                            <Card>
                                <CardHeader><CardTitle className="flex items-center gap-2"><Shield className="h-4 w-4" /> Identity</CardTitle></CardHeader>
                                <CardContent>
                                    <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                                        <Field label="Domain" value={domainLabel(device.domain)} />
                                        <Field label="Category" value={device.category.replace(/_/g, ' ')} />
                                        <Field label="Subcategory" value={device.subcategory?.replace(/_/g, ' ')} />
                                        <Field label="Manufacturer" value={device.manufacturer} />
                                        <Field label="Model" value={device.model} />
                                        <Field label="Serial" value={device.serial_number} />
                                        <Field label="MAC" value={device.mac_address} />
                                        <Field label="IMEI" value={device.imei} />
                                        <Field label="IP Address" value={device.ip_address} />
                                        <Field label="Asset Tag">
                                            {editingAssetTag ? (
                                                <div className="flex items-center gap-1">
                                                    <Input
                                                        value={assetTagDraft}
                                                        onChange={(e) => setAssetTagDraft(e.target.value)}
                                                        className="h-7 text-xs"
                                                        autoFocus
                                                    />
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        className="h-7 w-7 p-0"
                                                        onClick={() =>
                                                            saveFields(
                                                                { asset_tag: assetTagDraft || null },
                                                                () => setEditingAssetTag(false),
                                                            )
                                                        }
                                                        disabled={fieldSaving}
                                                        title="Save"
                                                    >
                                                        <Check className="h-3.5 w-3.5" />
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        className="h-7 w-7 p-0"
                                                        onClick={() => {
                                                            setEditingAssetTag(false);
                                                            setAssetTagDraft(device.asset_tag ?? '');
                                                        }}
                                                        title="Cancel"
                                                    >
                                                        <X className="h-3.5 w-3.5" />
                                                    </Button>
                                                </div>
                                            ) : (
                                                <span className="flex items-center gap-2">
                                                    <span>{device.asset_tag ?? '—'}</span>
                                                    {can.update && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            className="h-6 w-6 p-0 opacity-50 hover:opacity-100"
                                                            onClick={() => {
                                                                setAssetTagDraft(device.asset_tag ?? '');
                                                                setEditingAssetTag(true);
                                                            }}
                                                            title="Edit asset tag"
                                                        >
                                                            <Edit className="h-3 w-3" />
                                                        </Button>
                                                    )}
                                                </span>
                                            )}
                                        </Field>
                                        <Field label="Firmware" value={device.firmware_version} />
                                    </dl>
                                </CardContent>
                            </Card>

                            {/* Status & Health */}
                            <Card>
                                <CardHeader><CardTitle className="flex items-center gap-2"><Activity className="h-4 w-4" /> Status & Health</CardTitle></CardHeader>
                                <CardContent>
                                    <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                                        <Field label="Status"><Badge variant={statusVariant(device.status)}>{device.status.replace(/_/g, ' ')}</Badge></Field>
                                        <Field label="Health"><Badge variant={healthVariant(device.health_status)}>{device.health_status}</Badge></Field>
                                        <Field label="Last Seen" value={formatDateTime(device.last_seen_at)} />
                                        <Field label="Last Signal" value={formatDateTime(device.last_signal_at)} />
                                        {device.battery_level !== null && (
                                            <Field label="Battery">
                                                <span className="flex items-center gap-1"><Battery className="h-4 w-4" />{device.battery_level}%</span>
                                            </Field>
                                        )}
                                        <Field label="Commissioned" value={formatDate(device.commissioned_at)} />
                                        <Field label="Warranty Expires" value={formatDate(device.warranty_expires_at)} />
                                        <Field label="Next Service Due">
                                            {editingServiceDue ? (
                                                <div className="flex items-center gap-1">
                                                    <Input
                                                        type="date"
                                                        value={serviceDueDraft}
                                                        onChange={(e) => setServiceDueDraft(e.target.value)}
                                                        className="h-7 text-xs"
                                                    />
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        className="h-7 w-7 p-0"
                                                        onClick={() =>
                                                            saveFields(
                                                                { next_service_due: serviceDueDraft || null },
                                                                () => setEditingServiceDue(false),
                                                            )
                                                        }
                                                        disabled={fieldSaving}
                                                        title="Save"
                                                    >
                                                        <Check className="h-3.5 w-3.5" />
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        className="h-7 w-7 p-0"
                                                        onClick={() => {
                                                            setEditingServiceDue(false);
                                                            setServiceDueDraft(device.next_service_due ?? '');
                                                        }}
                                                        title="Cancel"
                                                    >
                                                        <X className="h-3.5 w-3.5" />
                                                    </Button>
                                                </div>
                                            ) : (
                                                <span className="flex items-center gap-2">
                                                    {device.next_service_due ? (
                                                        <>
                                                            <span>{formatDate(device.next_service_due)}</span>
                                                            {new Date(device.next_service_due) < new Date() && (
                                                                <Badge variant="destructive" className="text-[10px]">Overdue</Badge>
                                                            )}
                                                        </>
                                                    ) : (
                                                        <span className="text-muted-foreground">—</span>
                                                    )}
                                                    {can.update && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            className="h-6 w-6 p-0 opacity-50 hover:opacity-100"
                                                            onClick={() => {
                                                                setServiceDueDraft(device.next_service_due ?? '');
                                                                setEditingServiceDue(true);
                                                            }}
                                                            title="Set next service date"
                                                        >
                                                            <Edit className="h-3 w-3" />
                                                        </Button>
                                                    )}
                                                </span>
                                            )}
                                        </Field>
                                    </dl>
                                </CardContent>
                            </Card>

                            {/* Current Assignment */}
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="flex items-center gap-2"><MapPin className="h-4 w-4" /> Current Assignment</CardTitle>
                                        {can.assign && (
                                            <div className="flex gap-1">
                                                {activeAssignment ? (
                                                    <>
                                                        <Button variant="outline" size="sm" onClick={openAssignDialog}>
                                                            <ArrowRightLeft className="mr-1 h-3 w-3" /> Transfer
                                                        </Button>
                                                        <Button variant="outline" size="sm" onClick={submitRelease}>
                                                            <Minus className="mr-1 h-3 w-3" /> Release
                                                        </Button>
                                                    </>
                                                ) : (
                                                    <Button size="sm" onClick={openAssignDialog}>
                                                        <Plus className="mr-1 h-3 w-3" /> Assign
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    {activeAssignment ? (
                                        <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                                            <Field label="Assigned to" value={activeAssignment.assignable_name} />
                                            <Field label="Target type" value={targetTypeLabel(activeAssignment.assignable_type)} />
                                            <Field label="Assignment type" value={activeAssignment.assignment_type} />
                                            <Field label="Since" value={formatDateTime(activeAssignment.assigned_at)} />
                                            {activeAssignment.expected_return_at && (
                                                <Field label="Expected return" value={formatDate(activeAssignment.expected_return_at)} />
                                            )}
                                        </dl>
                                    ) : (
                                        <p className="text-sm text-muted-foreground italic">Unassigned (pooled stock)</p>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Linked Assets */}
                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between space-y-0">
                                    <CardTitle className="flex items-center gap-2"><Link2 className="h-4 w-4" /> Linked Assets</CardTitle>
                                    {can.update && pickableAssets.length > 0 && (
                                        <Button size="sm" variant="outline" onClick={() => { setLinkOpen(true); setLinkError(''); }}>
                                            <Plus className="mr-1 h-3.5 w-3.5" /> Link asset
                                        </Button>
                                    )}
                                </CardHeader>
                                <CardContent>
                                    {assetLinks.length > 0 ? (
                                        <div className="space-y-2">
                                            {assetLinks.map((link) => (
                                                <div key={link.id} className="flex items-center justify-between gap-3 rounded-md border p-3 text-sm">
                                                    <div className="min-w-0 flex-1">
                                                        <p className="truncate font-medium">{link.asset_name ?? `Asset #${link.asset_id}`}</p>
                                                        {link.asset_tag && <p className="font-mono text-xs text-muted-foreground">{link.asset_tag}</p>}
                                                        {link.notes && <p className="mt-1 text-xs text-muted-foreground">{link.notes}</p>}
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <Badge variant="outline" className="text-[10px]">{link.link_type.replace(/_/g, ' ')}</Badge>
                                                        {can.update && (
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                className="h-7 px-2 text-status-critical hover:text-status-critical"
                                                                onClick={() => submitUnlink(link.id)}
                                                                disabled={unlinkingId === link.id}
                                                                title="Unlink (history preserved)"
                                                            >
                                                                <Minus className="h-3.5 w-3.5" />
                                                            </Button>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-sm italic text-muted-foreground">No linked assets</p>
                                    )}
                                    {can.update && pickableAssets.length === 0 && availableAssets.length > 0 && assetLinks.length > 0 && (
                                        <p className="mt-3 text-xs text-muted-foreground">All known assets are already linked to this device.</p>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Link-asset dialog */}
                            <Dialog open={linkOpen} onOpenChange={setLinkOpen}>
                                <DialogContent className="sm:max-w-md">
                                    <DialogHeader>
                                        <DialogTitle>Link asset</DialogTitle>
                                        <DialogDescription>
                                            Connect this device to a tracked asset. Choose the link type based on the physical relationship.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <div className="space-y-4">
                                        <div className="space-y-1">
                                            <label className="text-sm font-medium">Asset</label>
                                            <Select value={linkAssetId} onValueChange={setLinkAssetId}>
                                                <SelectTrigger><SelectValue placeholder="Select an asset" /></SelectTrigger>
                                                <SelectContent>
                                                    {pickableAssets.map((a) => (
                                                        <SelectItem key={a.id} value={String(a.id)}>
                                                            {a.name}{a.asset_tag ? ` — ${a.asset_tag}` : ''}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-1">
                                            <label className="text-sm font-medium">Link type</label>
                                            <Select value={linkType} onValueChange={setLinkType}>
                                                <SelectTrigger><SelectValue /></SelectTrigger>
                                                <SelectContent>
                                                    {linkTypes.map((t) => (
                                                        <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-1">
                                            <label className="text-sm font-medium">Notes (optional)</label>
                                            <Input value={linkNotes} onChange={(e) => setLinkNotes(e.target.value)} placeholder="e.g. Installed in dashboard console" />
                                        </div>
                                        {linkError && <p className="text-sm text-status-critical">{linkError}</p>}
                                    </div>
                                    <DialogFooter>
                                        <Button variant="ghost" onClick={() => setLinkOpen(false)} disabled={linkSubmitting}>Cancel</Button>
                                        <Button onClick={submitLink} disabled={linkSubmitting || !linkAssetId}>
                                            {linkSubmitting ? 'Linking…' : 'Link asset'}
                                        </Button>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>
                        </div>

                        {device.provider && (
                            <Card>
                                <CardHeader><CardTitle className="flex items-center gap-2"><Settings className="h-4 w-4" /> Integration</CardTitle></CardHeader>
                                <CardContent>
                                    <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-4">
                                        <Field label="Provider" value={device.provider} />
                                        {device.location_description && <Field label="Location" value={device.location_description} />}
                                    </dl>
                                    {device.external_ref && Object.keys(device.external_ref).length > 0 && (
                                        <div className="mt-4">
                                            <p className="text-xs font-medium text-muted-foreground mb-1">External Reference</p>
                                            <pre className="rounded-md bg-muted p-3 text-xs overflow-auto max-h-32">{JSON.stringify(device.external_ref, null, 2)}</pre>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0">
                                <CardTitle>Notes</CardTitle>
                                {can.update && !editingNotes && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => {
                                            setNotesDraft(device.notes ?? '');
                                            setEditingNotes(true);
                                        }}
                                    >
                                        <Edit className="mr-1 h-3.5 w-3.5" /> Edit
                                    </Button>
                                )}
                            </CardHeader>
                            <CardContent>
                                {editingNotes ? (
                                    <div className="space-y-3">
                                        <textarea
                                            value={notesDraft}
                                            onChange={(e) => setNotesDraft(e.target.value)}
                                            className="min-h-[120px] w-full rounded-md border bg-background px-3 py-2 text-sm"
                                            placeholder="Free-form notes for this device…"
                                            maxLength={5000}
                                            autoFocus
                                        />
                                        <div className="flex gap-2">
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    saveFields({ notes: notesDraft || null }, () =>
                                                        setEditingNotes(false),
                                                    )
                                                }
                                                disabled={fieldSaving}
                                            >
                                                {fieldSaving ? 'Saving…' : 'Save notes'}
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => {
                                                    setEditingNotes(false);
                                                    setNotesDraft(device.notes ?? '');
                                                }}
                                                disabled={fieldSaving}
                                            >
                                                Cancel
                                            </Button>
                                        </div>
                                    </div>
                                ) : device.notes ? (
                                    <p className="whitespace-pre-wrap text-sm">{device.notes}</p>
                                ) : (
                                    <p className="text-sm italic text-muted-foreground">No notes yet.</p>
                                )}
                            </CardContent>
                        </Card>

                        <div className="text-xs text-muted-foreground">
                            Created {formatDateTime(device.created_at)}
                            {device.created_by && <> by {device.created_by.name}</>}
                            {groups.length > 0 && <> | Groups: {groups.map(g => g.name).join(', ')}</>}
                        </div>
                    </TabsContent>

                    {/* ── Assignments tab ───────────────────────── */}
                    <TabsContent value="assignments" className="space-y-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <h3 className="text-lg font-semibold">Assignment History</h3>
                                <p className="text-sm text-muted-foreground">All assignments for this device, newest first.</p>
                            </div>
                            {can.assign && (
                                <Button size="sm" onClick={openAssignDialog}>
                                    <Plus className="mr-1 h-3 w-3" /> Assign
                                </Button>
                            )}
                        </div>

                        {assignmentHistory.length > 0 ? (
                            <div className="space-y-2">
                                {assignmentHistory.map((a) => (
                                    <div key={a.id} className={`rounded-lg border p-4 text-sm ${a.is_active ? 'border-primary bg-primary/5' : ''}`}>
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-medium">{a.assignable_name}</span>
                                                    <Badge variant="outline" className="text-[10px]">{targetTypeLabel(a.assignable_type)}</Badge>
                                                    <Badge variant="outline" className="text-[10px]">{a.assignment_type}</Badge>
                                                    {a.is_active && <Badge variant="default" className="text-[10px]">Active</Badge>}
                                                    {a.is_overdue && <Badge variant="destructive" className="text-[10px]">Overdue</Badge>}
                                                </div>
                                                <div className="mt-1 flex flex-wrap gap-x-4 text-xs text-muted-foreground">
                                                    <span>Assigned: {formatDateTime(a.assigned_at)}</span>
                                                    {a.assigned_by && <span>by {a.assigned_by}</span>}
                                                    {a.released_at && (
                                                        <>
                                                            <span>Released: {formatDateTime(a.released_at)}</span>
                                                            {a.released_by && <span>by {a.released_by}</span>}
                                                        </>
                                                    )}
                                                    {a.expected_return_at && <span>Return by: {formatDate(a.expected_return_at)}</span>}
                                                </div>
                                                {a.notes && <p className="mt-1 text-xs text-muted-foreground italic">{a.notes}</p>}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <EmptyState
                                icon={MapPin}
                                title="No assignment history"
                                description="This device has never been assigned."
                                variant="compact"
                                action={can.assign ? (
                                    <Button size="sm" onClick={openAssignDialog}>Assign Now</Button>
                                ) : undefined}
                            />
                        )}
                    </TabsContent>

                    {/* ── Events tab ────────────────────────────── */}
                    <TabsContent value="events" className="space-y-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <h3 className="text-lg font-semibold">Recent Events</h3>
                                <p className="text-sm text-muted-foreground">Last 20 events for this device.</p>
                            </div>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/security-devices/alerts-events?device_id=${device.id}`}>
                                    View all events
                                </Link>
                            </Button>
                        </div>

                        {recentEvents.length > 0 ? (
                            <div className="space-y-1">
                                {recentEvents.map((evt) => (
                                    <div
                                        key={evt.id}
                                        className={`flex items-start gap-3 rounded-md border p-3 text-sm ${
                                            evt.severity === 'critical' ? 'border-status-critical/30 bg-status-critical-bg dark:border-status-critical/30' :
                                            evt.severity === 'warning' ? 'border-status-warning/30 bg-status-warning-bg dark:border-status-warning/30' :
                                            ''
                                        }`}
                                    >
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge variant={evt.severity === 'critical' ? 'destructive' : evt.severity === 'warning' ? 'outline' : 'secondary'} className="text-[10px]">
                                                    {evt.severity}
                                                </Badge>
                                                <span className="font-medium">{evt.event_type.replace(/_/g, ' ')}</span>
                                                {evt.source && <span className="text-xs text-muted-foreground">via {evt.source}</span>}
                                            </div>
                                        </div>
                                        <span className="text-xs text-muted-foreground shrink-0">{formatDateTime(evt.occurred_at)}</span>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <EmptyState icon={Clock} title="No events yet" description="Device events will appear here as they are recorded." variant="compact" />
                        )}
                    </TabsContent>

                    {/* ── Maintenance tab ───────────────────────── */}
                    <TabsContent value="maintenance" className="space-y-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <h3 className="text-lg font-semibold">Maintenance Records</h3>
                                <p className="text-sm text-muted-foreground">Scheduled, in-progress, and completed maintenance for this device.</p>
                            </div>
                            {can.update && (
                                <Button size="sm" onClick={() => setMaintOpen(true)}>
                                    <Plus className="mr-1 h-3 w-3" /> Schedule Maintenance
                                </Button>
                            )}
                        </div>

                        {maintenanceRecords.length > 0 ? (
                            <div className="space-y-2">
                                {maintenanceRecords.map((m) => {
                                    const isOverdue = m.status === 'scheduled' && m.scheduled_for && new Date(m.scheduled_for) < new Date();
                                    return (
                                        <div key={m.id} className={`rounded-lg border p-4 text-sm ${isOverdue ? 'border-status-warning/30 bg-status-warning-bg' : ''}`}>
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="font-medium">{m.type.replace(/_/g, ' ')}</span>
                                                        <Badge variant={m.status === 'completed' ? 'default' : m.status === 'scheduled' ? 'outline' : 'secondary'} className="text-[10px]">{m.status}</Badge>
                                                        {isOverdue && <Badge variant="destructive" className="text-[10px]">Overdue</Badge>}
                                                    </div>
                                                    <p className="mt-1 text-xs text-muted-foreground">{m.description}</p>
                                                    <div className="mt-1 flex flex-wrap gap-x-4 text-xs text-muted-foreground">
                                                        {m.scheduled_for && <span>Due: {formatDate(m.scheduled_for)}</span>}
                                                        {m.completed_at && <span>Completed: {formatDateTime(m.completed_at)}</span>}
                                                    </div>
                                                </div>
                                                {can.update && m.status === 'scheduled' && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => router.post(`/security-devices/maintenance/${m.id}/complete`, {}, { preserveScroll: true })}
                                                    >
                                                        <Check className="mr-1 h-3 w-3" /> Complete
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        ) : (
                            <EmptyState
                                icon={Wrench}
                                title="No maintenance records"
                                description="Schedule maintenance to keep this device in good operating condition."
                                variant="compact"
                                action={can.update ? (
                                    <Button size="sm" onClick={() => setMaintOpen(true)}>Schedule Maintenance</Button>
                                ) : undefined}
                            />
                        )}
                    </TabsContent>

                    {/* ── Topology tab ──────────────────────────── */}
                    <TabsContent value="relationships">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0">
                                <div>
                                    <CardTitle>Device Topology</CardTitle>
                                    <CardDescription>Physical and logical relationships to other devices.</CardDescription>
                                </div>
                                {can.update && otherDevices.length > 0 && (
                                    <Button size="sm" variant="outline" onClick={() => { setRelOpen(true); setRelError(''); }}>
                                        <Plus className="mr-1 h-3.5 w-3.5" /> Link device
                                    </Button>
                                )}
                            </CardHeader>
                            <CardContent>
                                {totalRelationships > 0 ? (
                                    <div className="space-y-4">
                                        {relationships.parents.length > 0 && (
                                            <div>
                                                <p className="mb-2 text-xs font-medium text-muted-foreground">Upstream (this device's parents)</p>
                                                {relationships.parents.map((r) => (
                                                    <div key={r.id} className="mb-1 flex items-center gap-3 rounded-md border p-3 text-sm">
                                                        <Network className="h-4 w-4 text-muted-foreground" />
                                                        <span className="text-muted-foreground">{r.type.replace(/_/g, ' ')}</span>
                                                        <Link href={`/security-devices/devices/${r.device_id}`} className="flex-1 truncate text-primary hover:underline">{r.device_name ?? `Device #${r.device_id}`}</Link>
                                                        {r.port && <Badge variant="outline" className="text-[10px]">{r.port}</Badge>}
                                                        {can.update && (
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                className="h-7 px-2 text-status-critical hover:text-status-critical"
                                                                onClick={() => submitUnlinkRelationship(r.id)}
                                                                disabled={unlinkingRelId === r.id}
                                                                title="Remove relationship"
                                                            >
                                                                <Minus className="h-3.5 w-3.5" />
                                                            </Button>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                        {relationships.children.length > 0 && (
                                            <div>
                                                <p className="mb-2 text-xs font-medium text-muted-foreground">Downstream (this device's children)</p>
                                                {relationships.children.map((r) => (
                                                    <div key={r.id} className="mb-1 flex items-center gap-3 rounded-md border p-3 text-sm">
                                                        <GitBranch className="h-4 w-4 text-muted-foreground" />
                                                        <span className="text-muted-foreground">{r.type.replace(/_/g, ' ')}</span>
                                                        <Link href={`/security-devices/devices/${r.device_id}`} className="flex-1 truncate text-primary hover:underline">{r.device_name ?? `Device #${r.device_id}`}</Link>
                                                        {r.port && <Badge variant="outline" className="text-[10px]">{r.port}</Badge>}
                                                        {can.update && (
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                className="h-7 px-2 text-status-critical hover:text-status-critical"
                                                                onClick={() => submitUnlinkRelationship(r.id)}
                                                                disabled={unlinkingRelId === r.id}
                                                                title="Remove relationship"
                                                            >
                                                                <Minus className="h-3.5 w-3.5" />
                                                            </Button>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                ) : (
                                    <EmptyState icon={Network} title="No relationships" description={can.update ? 'Click "Link device" to record a physical or logical connection.' : 'Device topology relationships will appear here when configured.'} variant="compact" />
                                )}
                            </CardContent>
                        </Card>

                        {/* Link-device dialog */}
                        <Dialog open={relOpen} onOpenChange={setRelOpen}>
                            <DialogContent className="sm:max-w-md">
                                <DialogHeader>
                                    <DialogTitle>Link to another device</DialogTitle>
                                    <DialogDescription>
                                        Record a physical or logical relationship. Pick a direction, a relationship type, and the other device.
                                    </DialogDescription>
                                </DialogHeader>
                                <div className="space-y-4">
                                    <div className="space-y-1">
                                        <label className="text-sm font-medium">Direction</label>
                                        <Select value={relDirection} onValueChange={(v) => setRelDirection(v as 'downstream' | 'upstream')}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="downstream">This device → other (downstream)</SelectItem>
                                                <SelectItem value="upstream">Other → this device (upstream)</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-1">
                                        <label className="text-sm font-medium">Relationship type</label>
                                        <Select value={relType} onValueChange={setRelType}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                {relationshipTypes.map((t) => (
                                                    <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-1">
                                        <label className="text-sm font-medium">Other device</label>
                                        <Select value={relOtherDeviceId} onValueChange={setRelOtherDeviceId}>
                                            <SelectTrigger><SelectValue placeholder="Select a device" /></SelectTrigger>
                                            <SelectContent>
                                                {otherDevices.map((d) => (
                                                    <SelectItem key={d.id} value={String(d.id)}>
                                                        {d.name}{d.device_uid ? ` — ${d.device_uid}` : ''}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="space-y-1">
                                            <label className="text-sm font-medium">Port (optional)</label>
                                            <Input value={relPort} onChange={(e) => setRelPort(e.target.value)} placeholder="e.g. Port 3" />
                                        </div>
                                        <div className="space-y-1">
                                            <label className="text-sm font-medium">Notes (optional)</label>
                                            <Input value={relNotes} onChange={(e) => setRelNotes(e.target.value)} placeholder="short note" />
                                        </div>
                                    </div>
                                    {relError && <p className="text-sm text-status-critical">{relError}</p>}
                                </div>
                                <DialogFooter>
                                    <Button variant="ghost" onClick={() => setRelOpen(false)} disabled={relSubmitting}>Cancel</Button>
                                    <Button onClick={submitRelationship} disabled={relSubmitting || !relOtherDeviceId}>
                                        {relSubmitting ? 'Linking…' : 'Add relationship'}
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </TabsContent>

                    {/* ── Documents tab ─────────────────────────── */}
                    <TabsContent value="documents">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0">
                                <div>
                                    <CardTitle>Documents</CardTitle>
                                    <CardDescription>Manuals, compliance certs, install photos, firmware notes, and other device-specific files.</CardDescription>
                                </div>
                                {can.update && (
                                    <Button size="sm" variant="outline" onClick={() => { setDocOpen(true); setDocError(''); }}>
                                        <Plus className="mr-1 h-3.5 w-3.5" /> Upload document
                                    </Button>
                                )}
                            </CardHeader>
                            <CardContent>
                                {documents.length === 0 ? (
                                    <EmptyState
                                        icon={FileText}
                                        title="No documents"
                                        description={can.update ? 'Click "Upload document" to attach a manual, photo, or compliance cert.' : 'No documents have been uploaded for this device.'}
                                        variant="compact"
                                    />
                                ) : (
                                    <div className="space-y-2">
                                        {documents.map((doc) => {
                                            const expired = doc.expiry_date && new Date(doc.expiry_date) < new Date();
                                            return (
                                                <div key={doc.id} className="flex items-start gap-3 rounded-md border p-3 text-sm">
                                                    <FileText className="mt-0.5 h-4 w-4 text-muted-foreground" />
                                                    <div className="min-w-0 flex-1 space-y-1">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <a href={doc.download_url} className="truncate font-medium text-primary hover:underline">{doc.title}</a>
                                                            <Badge variant="outline" className="text-[10px]">{doc.category.replace(/_/g, ' ')}</Badge>
                                                            {doc.version && <Badge variant="secondary" className="text-[10px]">v{doc.version}</Badge>}
                                                            {expired && <Badge variant="destructive" className="text-[10px]">Expired</Badge>}
                                                        </div>
                                                        <p className="truncate font-mono text-xs text-muted-foreground">
                                                            {doc.original_name} · {formatBytes(doc.size_bytes)}
                                                            {doc.expiry_date && ` · expires ${doc.expiry_date}`}
                                                        </p>
                                                        {doc.notes && <p className="text-xs text-muted-foreground">{doc.notes}</p>}
                                                    </div>
                                                    {can.update && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            className="h-7 px-2 text-status-critical hover:text-status-critical"
                                                            onClick={() => submitDeleteDocument(doc.id)}
                                                            disabled={deletingDocId === doc.id}
                                                            title="Delete document"
                                                        >
                                                            <Trash2 className="h-3.5 w-3.5" />
                                                        </Button>
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Upload dialog */}
                        <Dialog open={docOpen} onOpenChange={setDocOpen}>
                            <DialogContent className="sm:max-w-md">
                                <DialogHeader>
                                    <DialogTitle>Upload document</DialogTitle>
                                    <DialogDescription>Max 20 MB. Stored encrypted on the local disk. Delete removes both the row and the file.</DialogDescription>
                                </DialogHeader>
                                <div className="space-y-4">
                                    <div className="space-y-1">
                                        <label className="text-sm font-medium">File</label>
                                        <Input type="file" onChange={(e) => setDocFile(e.target.files?.[0] ?? null)} />
                                    </div>
                                    <div className="space-y-1">
                                        <label className="text-sm font-medium">Title</label>
                                        <Input value={docTitle} onChange={(e) => setDocTitle(e.target.value)} placeholder="e.g. UVC-G4 Install Manual" />
                                    </div>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="space-y-1">
                                            <label className="text-sm font-medium">Category</label>
                                            <Select value={docCategory} onValueChange={setDocCategory}>
                                                <SelectTrigger><SelectValue /></SelectTrigger>
                                                <SelectContent>
                                                    {documentCategories.map((c) => (
                                                        <SelectItem key={c.value} value={c.value}>{c.label}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-1">
                                            <label className="text-sm font-medium">Version (optional)</label>
                                            <Input value={docVersion} onChange={(e) => setDocVersion(e.target.value)} placeholder="e.g. 1.2.0" />
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="space-y-1">
                                            <label className="text-sm font-medium">Effective (optional)</label>
                                            <Input type="date" value={docEffective} onChange={(e) => setDocEffective(e.target.value)} />
                                        </div>
                                        <div className="space-y-1">
                                            <label className="text-sm font-medium">Expires (optional)</label>
                                            <Input type="date" value={docExpiry} onChange={(e) => setDocExpiry(e.target.value)} />
                                        </div>
                                    </div>
                                    <div className="space-y-1">
                                        <label className="text-sm font-medium">Notes (optional)</label>
                                        <Input value={docNotes} onChange={(e) => setDocNotes(e.target.value)} placeholder="short note" />
                                    </div>
                                    {docError && <p className="text-sm text-status-critical">{docError}</p>}
                                </div>
                                <DialogFooter>
                                    <Button variant="ghost" onClick={() => setDocOpen(false)} disabled={docSubmitting}>Cancel</Button>
                                    <Button onClick={submitDocument} disabled={docSubmitting || !docFile || !docTitle.trim()}>
                                        {docSubmitting ? 'Uploading…' : 'Upload'}
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </TabsContent>
                </Tabs>

                {/* ── Assign dialog ─────────────────────────────── */}
                <Dialog open={assignOpen} onOpenChange={setAssignOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{activeAssignment ? 'Transfer Device' : 'Assign Device'}</DialogTitle>
                            <DialogDescription>
                                {activeAssignment
                                    ? `Currently assigned to ${activeAssignment.assignable_name}. The current assignment will be released automatically.`
                                    : 'Assign this device to a site, room, staff member, client, or vehicle.'}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4">
                            <div>
                                <label className="text-sm font-medium mb-1.5 block">Target type <span className="text-destructive">*</span></label>
                                <Select value={assignType} onValueChange={(v) => { setAssignType(v); setAssignId(''); }}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="site">Site</SelectItem>
                                        <SelectItem value="room">Room</SelectItem>
                                        <SelectItem value="staff">Staff</SelectItem>
                                        <SelectItem value="client">Client</SelectItem>
                                        <SelectItem value="vehicle">Vehicle</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <label className="text-sm font-medium mb-1.5 block">
                                    {targetTypeLabel(assignType)} <span className="text-destructive">*</span>
                                </label>
                                <Select value={assignId} onValueChange={setAssignId}>
                                    <SelectTrigger><SelectValue placeholder={`Select ${targetTypeLabel(assignType).toLowerCase()}`} /></SelectTrigger>
                                    <SelectContent>
                                        {targetOptions.map((opt) => (
                                            <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <label className="text-sm font-medium mb-1.5 block">Assignment type</label>
                                <Select value={assignmentKind} onValueChange={setAssignmentKind}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="permanent">Permanent</SelectItem>
                                        <SelectItem value="temporary">Temporary</SelectItem>
                                        <SelectItem value="loan">Loan</SelectItem>
                                        <SelectItem value="shared">Shared</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            {assignmentKind === 'loan' && (
                                <div>
                                    <label className="text-sm font-medium mb-1.5 block">Expected return date</label>
                                    <Input type="date" value={returnDate} onChange={(e) => setReturnDate(e.target.value)} />
                                </div>
                            )}

                            {assignType === 'client' && (
                                <div className="rounded-md border border-status-warning/30 bg-status-warning-bg dark:bg-status-warning-bg p-3 text-xs text-status-warning dark:text-status-warning">
                                    Client device assignments require a valid consent record (NZ privacy). Ensure consent has been recorded before assigning.
                                </div>
                            )}

                            <div>
                                <label className="text-sm font-medium mb-1.5 block">Notes (optional)</label>
                                <textarea
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    rows={2}
                                    value={assignNotes}
                                    onChange={(e) => setAssignNotes(e.target.value)}
                                    placeholder="Optional assignment notes..."
                                />
                            </div>

                            {assignError && <p className="text-sm text-destructive">{assignError}</p>}
                        </div>

                        <DialogFooter>
                            <Button variant="outline" onClick={() => setAssignOpen(false)}>Cancel</Button>
                            <Button onClick={submitAssign} disabled={submitting || !assignId}>
                                {submitting ? 'Saving...' : activeAssignment ? 'Transfer' : 'Assign'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* ── Maintenance dialog ────────────────────────── */}
                <Dialog open={maintOpen} onOpenChange={setMaintOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Schedule Maintenance</DialogTitle>
                            <DialogDescription>Create a maintenance record for {device.name}.</DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4">
                            <div>
                                <label className="text-sm font-medium mb-1.5 block">Type <span className="text-destructive">*</span></label>
                                <Select value={maintType} onValueChange={setMaintType}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="scheduled_service">Scheduled Service</SelectItem>
                                        <SelectItem value="repair">Repair</SelectItem>
                                        <SelectItem value="firmware_update">Firmware Update</SelectItem>
                                        <SelectItem value="inspection">Inspection</SelectItem>
                                        <SelectItem value="replacement">Replacement</SelectItem>
                                        <SelectItem value="calibration">Calibration</SelectItem>
                                        <SelectItem value="connectivity_check">Connectivity Check</SelectItem>
                                        <SelectItem value="battery_replacement">Battery Replacement</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <label className="text-sm font-medium mb-1.5 block">Description <span className="text-destructive">*</span></label>
                                <textarea
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    rows={3}
                                    value={maintDesc}
                                    onChange={(e) => setMaintDesc(e.target.value)}
                                    placeholder="Describe the maintenance work..."
                                />
                            </div>

                            <div>
                                <label className="text-sm font-medium mb-1.5 block">Scheduled date</label>
                                <Input type="date" value={maintDate} onChange={(e) => setMaintDate(e.target.value)} />
                            </div>

                            <div>
                                <label className="text-sm font-medium mb-1.5 block">Notes (optional)</label>
                                <textarea
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    rows={2}
                                    value={maintNotes}
                                    onChange={(e) => setMaintNotes(e.target.value)}
                                    placeholder="Optional notes..."
                                />
                            </div>

                            {maintError && <p className="text-sm text-destructive">{maintError}</p>}
                        </div>

                        <DialogFooter>
                            <Button variant="outline" onClick={() => setMaintOpen(false)}>Cancel</Button>
                            <Button onClick={submitMaintenance} disabled={maintSubmitting || !maintDesc}>
                                {maintSubmitting ? 'Saving...' : 'Schedule'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </PageShell>
        </AppLayout>
    );
}

// ── Shared sub-component ──────────────────────────────────────────

function Field({ label, value, children }: { label: string; value?: string | null; children?: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm font-medium mt-0.5">{children ?? (value || <span className="text-muted-foreground/50">-</span>)}</dd>
        </div>
    );
}
