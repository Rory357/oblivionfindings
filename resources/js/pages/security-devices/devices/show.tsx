import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderViewToggle,
} from '@/components/page';
import PageShell from '@/components/page-shell';
import { useGroupedProfileSearchShortcut } from '@/components/page/grouped-profile-nav';
import { ReasonDialog } from '@/components/reason-dialog';
import {
    EditDeviceDialog,
    useEditDeviceDialogState,
} from '@/components/security-devices/add-device-dialog';
import { CctvNavigationActions } from '@/components/security-devices/cctv-navigation-actions';
import type { DeviceDocumentHistoryItem } from '@/components/security-devices/device-document-history';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { TabsRoot as Tabs, TabsContent } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateTime } from '@/lib/datetime';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowRightLeft,
    Cpu,
    Edit,
    MapPin,
    Minus,
    Plus,
    RefreshCw,
    Trash2,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { InstallationProfileDialog } from './_installation-dialog';
import { DeviceEndpointSection } from './device-endpoint-section';
import type { DeviceProfile, DeviceProfileSectionKey } from './device-profile';
import { DeviceProfileHeader } from './device-profile-header';
import {
    DeviceProfileGroupNavigation,
    DeviceProfileNavigation,
} from './device-profile-navigation';
import {
    DeviceAssetRecords,
    DeviceAssignmentRecords,
    DeviceControlRoomRecords,
    DeviceDocumentHistoryRecords,
    DeviceDocumentRecords,
    DeviceEventRecords,
    DeviceMaintenanceRecords,
    DeviceRelationshipHistoryRecords,
    DeviceRelationshipRecords,
    type DeviceRecordsView,
} from './device-profile-records';
import {
    DeviceAuditSection,
    DeviceConfigurationSection,
    DeviceHealthSection,
    DeviceInterfacesSensorsSection,
    DeviceManagementSection,
    DeviceMonitorsSection,
    DeviceTicketsSection,
} from './device-profile-sections';

// ── Types ─────────────────────────────────────────────────────────

type DeviceDetail = {
    id: number;
    name: string;
    status: string;
    domain?: string | null;
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

type AssetLink = {
    id: number;
    asset_id: number;
    asset_name: string | null;
    asset_tag: string | null;
    href: string | null;
    access: {
        state: 'available' | 'restricted';
        label: string;
    };
    link_type: string;
    linked_at: string;
    notes: string | null;
};
type AvailableAsset = { id: number; name: string; asset_tag: string | null };
type LinkTypeOption = { value: string; label: string };
type EventItem = {
    id: number;
    event_type: string;
    severity: string;
    occurred_at: string;
    source: string | null;
};
type MaintenanceItem = {
    id: number;
    type: string;
    status: string;
    description: string;
    scheduled_for: string | null;
    completed_at: string | null;
};
type Relationship = {
    id: number;
    device_id: number;
    device_name: string | null;
    type: string;
    port: string | null;
};
type HistoricalRelationship = Relationship & {
    created_at: string | null;
    created_by: string | null;
    unlinked_at: string;
    unlinked_by: string | null;
    unlink_reason: string;
};

type TargetEntity = { id: number; name: string; [key: string]: unknown };

export type Props = {
    actor_id: number;
    as_of: string;
    endpoint_setup?: { actor_id: number } | null;
    device: DeviceDetail;
    profile: DeviceProfile;
    activeAssignment: Assignment | null;
    assignmentHistory: AssignmentHistoryItem[];
    assignmentTargets: {
        sites: TargetEntity[];
        rooms: Array<{ id: number; site_id: number; name: string }>;
        staff: TargetEntity[];
        clients: Array<{ id: number; first_name: string; last_name: string }>;
        vehicles: Array<{
            id: number;
            name: string;
            registration_number: string | null;
        }>;
    };
    assetLinks: AssetLink[];
    availableAssets: AvailableAsset[];
    linkTypes: LinkTypeOption[];
    relationshipTypes: LinkTypeOption[];
    otherDevices: Array<{
        id: number;
        name: string;
        device_uid: string;
        category: string;
    }>;
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
    documentHistory: DeviceDocumentHistoryItem[];
    documentCategories: LinkTypeOption[];
    recentEvents: EventItem[];
    maintenanceRecords: MaintenanceItem[];
    relationships: { parents: Relationship[]; children: Relationship[] };
    relationshipHistory: {
        parents: HistoricalRelationship[];
        children: HistoricalRelationship[];
    };
    can: {
        update: boolean;
        delete: boolean;
        assign: boolean;
        viewEvents: boolean;
        manageMaintenance: boolean;
    };
};

// ── Helpers ───────────────────────────────────────────────────────

function humanise(value: string | null | undefined): string {
    if (!value) return 'Not recorded';

    return value
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}
function targetTypeLabel(t: string): string {
    return (
        {
            site: 'Site',
            room: 'Room',
            vehicle: 'Vehicle',
            staff: 'Staff',
            client: 'Client',
        }[t] ?? t
    );
}

// ── Component ─────────────────────────────────────────────────────

export default function DeviceShow(data: Props) {
    const { auth } = usePage<{
        auth?: {
            user?: { id: number } | null;
            can?: { securityDevices?: Record<string, unknown> };
        };
    }>().props;
    const permission = auth?.can?.securityDevices;
    const [sourceDevice] = useState(data.device.id);
    const [sourceAuthority] = useState(() =>
        JSON.stringify([data.actor_id, auth?.can]),
    );
    const authorityMatches =
        sourceAuthority === JSON.stringify([data.actor_id, auth?.can]);
    if (
        !authorityMatches ||
        sourceDevice !== data.device.id ||
        !auth?.user?.id ||
        data.actor_id !== auth.user.id ||
        permission?.viewAny !== true ||
        permission.devicesView !== true
    )
        return (
            <AppLayout
                breadcrumbs={[
                    { title: 'Home', href: '/dashboard' },
                    { title: 'Security & Devices', href: '/security-devices' },
                    { title: 'All devices', href: '/security-devices/devices' },
                ]}
            >
                <Head title="Device access required" />
                <div className="space-y-5">
                    <PageHeader
                        actions={
                            <PageHeaderGlassButton
                                icon={RefreshCw}
                                onClick={() =>
                                    router.visit(
                                        `/security-devices/devices/${data.device.id}`,
                                        {
                                            preserveState: false,
                                            preserveScroll: true,
                                        },
                                    )
                                }
                            >
                                Refresh current record
                            </PageHeaderGlassButton>
                        }
                        icon={Cpu}
                        title="Device access required"
                        subline="Device records are restricted."
                    />
                    <div role="status">
                        <EmptyState
                            icon={Cpu}
                            title="This device isn’t available to you"
                            description="This device is not available in your current access. Return to All devices and reopen a permitted record."
                            action={
                                <Button asChild variant="outline">
                                    <Link href="/security-devices/devices">
                                        Back to all devices
                                    </Link>
                                </Button>
                            }
                        />
                    </div>
                </div>
            </AppLayout>
        );
    const can = {
        ...data.can,
        update: data.can.update && permission.devicesUpdate === true,
        delete: data.can.delete && permission.devicesDelete === true,
        assign: data.can.assign && permission.devicesAssign === true,
        viewEvents: data.can.viewEvents && permission.eventsView === true,
        manageMaintenance:
            data.can.manageMaintenance && permission.maintenanceManage === true,
    };
    return (
        <DeviceShowBody
            key={JSON.stringify([data.actor_id, data.device.id, permission])}
            {...data}
            can={can}
            endpoint_setup={
                permission.devicesUpdate === true &&
                permission.commandsAdmin === true
                    ? data.endpoint_setup
                    : null
            }
        />
    );
}

function DeviceShowBody({
    as_of,
    endpoint_setup = null,
    device,
    profile,
    activeAssignment,
    assignmentHistory,
    assignmentTargets,
    assetLinks,
    availableAssets,
    linkTypes,
    relationshipTypes,
    otherDevices,
    documents,
    documentHistory,
    documentCategories,
    recentEvents,
    maintenanceRecords,
    relationships,
    relationshipHistory,
    can,
}: Props) {
    const totalRelationshipHistory =
        relationshipHistory.parents.length +
        relationshipHistory.children.length;
    const [activeSection, setActiveSection] = useState<DeviceProfileSectionKey>(
        profile.sections[0]?.key ?? 'health',
    );
    const [recordsView, setRecordsView] = useState<DeviceRecordsView>('cards');
    const [profileSearchOpen, setProfileSearchOpen] = useState(false);
    useGroupedProfileSearchShortcut(() => setProfileSearchOpen(true));
    const editDeviceDialog = useEditDeviceDialogState();
    const [releaseOpen, setReleaseOpen] = useState(false);
    const [decommissionOpen, setDecommissionOpen] = useState(false);
    const [installationOpen, setInstallationOpen] = useState(false);
    useEffect(() => {
        const syncSectionFromUrl = () => {
            const params = new URLSearchParams(window.location.search);
            const requested = params.get(
                'section',
            ) as DeviceProfileSectionKey | null;
            if (
                requested &&
                profile.sections.some((section) => section.key === requested)
            ) {
                setActiveSection(requested);
                return;
            }
            if (
                params.get('action') &&
                profile.sections.some((section) => section.key === 'management')
            ) {
                setActiveSection('management');
            }
        };

        syncSectionFromUrl();
        window.addEventListener('popstate', syncSectionFromUrl);
        return () => window.removeEventListener('popstate', syncSectionFromUrl);
    }, [profile.sections]);
    const openProfileSection = (section: DeviceProfileSectionKey) => {
        setActiveSection(section);
        if (typeof window === 'undefined') return;

        const url = new URL(window.location.href);
        if (url.searchParams.get('section') === section) return;

        url.searchParams.set('section', section);
        window.history.pushState(
            window.history.state,
            '',
            `${url.pathname}${url.search}${url.hash}`,
        );
    };

    // ── Asset-link dialog state ──────────────────────────────────
    const [linkOpen, setLinkOpen] = useState(false);
    const [linkAssetId, setLinkAssetId] = useState<string>('');
    const [linkType, setLinkType] = useState<string>(
        linkTypes[0]?.value ?? 'primary',
    );
    const [linkNotes, setLinkNotes] = useState('');
    const [linkSubmitting, setLinkSubmitting] = useState(false);
    const [linkError, setLinkError] = useState('');
    const [unlinkingId, setUnlinkingId] = useState<number | null>(null);
    const [pendingAssetUnlink, setPendingAssetUnlink] =
        useState<AssetLink | null>(null);

    // Assets already linked to this device (active) — hide from picker.
    const linkedAssetIds = useMemo(
        () => new Set(assetLinks.map((l) => l.asset_id)),
        [assetLinks],
    );
    const pickableAssets = useMemo(
        () => availableAssets.filter((a) => !linkedAssetIds.has(a.id)),
        [availableAssets, linkedAssetIds],
    );

    const linkAssetIsEligible = pickableAssets.some(
        (asset) => String(asset.id) === linkAssetId,
    );
    const assetChoiceError =
        linkAssetId && !linkAssetIsEligible
            ? 'This asset is no longer available to link. Choose a current asset.'
            : '';

    const submitLink = () => {
        if (!linkAssetId) {
            setLinkError('Pick an asset to link.');
            return;
        }
        if (!linkAssetIsEligible) {
            setLinkError(
                'This asset is no longer available to link. Choose a current asset.',
            );
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
                onSuccess: (page) => {
                    const flash = page.props.flash;
                    if (
                        flash !== null &&
                        typeof flash === 'object' &&
                        'error' in flash &&
                        Boolean(flash.error)
                    ) {
                        setLinkError(
                            'The asset was not linked. Review the current asset choices and try again.',
                        );
                        return;
                    }
                    setLinkOpen(false);
                    setLinkAssetId('');
                    setLinkType(linkTypes[0]?.value ?? 'primary');
                    setLinkNotes('');
                },
                onError: (errs) => {
                    const firstErr = Object.values(errs)[0];
                    setLinkError(
                        Array.isArray(firstErr)
                            ? firstErr[0]
                            : String(firstErr ?? 'Failed to link asset.'),
                    );
                },
                onFinish: () => setLinkSubmitting(false),
            },
        );
    };

    const submitUnlink = (linkId: number) => {
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
    const [relType, setRelType] = useState<string>(
        relationshipTypes[0]?.value ?? 'connected_to',
    );
    const [relDirection, setRelDirection] = useState<'downstream' | 'upstream'>(
        'downstream',
    );
    const [relPort, setRelPort] = useState('');
    const [relNotes, setRelNotes] = useState('');
    const [relSubmitting, setRelSubmitting] = useState(false);
    const [relError, setRelError] = useState('');
    const [unlinkingRelId, setUnlinkingRelId] = useState<number | null>(null);
    const [pendingRelationshipRemoval, setPendingRelationshipRemoval] =
        useState<Relationship | null>(null);

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
                    setRelError(
                        Array.isArray(firstErr)
                            ? firstErr[0]
                            : String(firstErr ?? 'Failed to add relationship.'),
                    );
                },
                onFinish: () => setRelSubmitting(false),
            },
        );
    };

    const submitUnlinkRelationship = (
        relId: number,
        reason: string,
        done: () => void,
    ) => {
        setUnlinkingRelId(relId);
        router.delete(
            `/security-devices/devices/${device.id}/relationships/${relId}`,
            {
                data: { reason },
                preserveScroll: true,
                onSuccess: () => setPendingRelationshipRemoval(null),
                onFinish: () => {
                    setUnlinkingRelId(null);
                    done();
                },
            },
        );
    };

    // ── Document upload state ────────────────────────────────────
    const [docOpen, setDocOpen] = useState(false);
    const [docTitle, setDocTitle] = useState('');
    const [docCategory, setDocCategory] = useState(
        documentCategories[0]?.value ?? 'other',
    );
    const [docVersion, setDocVersion] = useState('');
    const [docEffective, setDocEffective] = useState('');
    const [docExpiry, setDocExpiry] = useState('');
    const [docNotes, setDocNotes] = useState('');
    const [docFile, setDocFile] = useState<File | null>(null);
    const [docSubmitting, setDocSubmitting] = useState(false);
    const [docError, setDocError] = useState('');
    const [deletingDocId, setDeletingDocId] = useState<number | null>(null);
    const [pendingDocumentDeletion, setPendingDocumentDeletion] = useState<{
        id: number;
        title: string;
    } | null>(null);

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

        router.post(
            `/security-devices/devices/${device.id}/documents`,
            formData,
            {
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
                    setDocError(
                        Array.isArray(firstErr)
                            ? firstErr[0]
                            : String(firstErr ?? 'Upload failed.'),
                    );
                },
                onFinish: () => setDocSubmitting(false),
            },
        );
    };

    const submitDeleteDocument = (
        docId: number,
        reason: string,
        done: () => void,
    ) => {
        setDeletingDocId(docId);
        router.delete(
            `/security-devices/devices/${device.id}/documents/${docId}`,
            {
                data: { reason },
                preserveScroll: true,
                onSuccess: () => setPendingDocumentDeletion(null),
                onFinish: () => {
                    setDeletingDocId(null);
                    done();
                },
            },
        );
    };

    // ── Lifecycle service-date dialog state ─────────────────────
    const [serviceDueOpen, setServiceDueOpen] = useState(false);
    const [serviceDue, setServiceDue] = useState(
        profile.configuration.registry.nextServiceDue ?? '',
    );
    const [serviceDueSubmitting, setServiceDueSubmitting] = useState(false);

    const submitServiceDue = () => {
        setServiceDueSubmitting(true);
        router.patch(
            `/security-devices/devices/${device.id}/fields`,
            { next_service_due: serviceDue || null },
            {
                preserveScroll: true,
                onSuccess: () => setServiceDueOpen(false),
                onFinish: () => setServiceDueSubmitting(false),
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
    const [pendingMaintenanceCompletion, setPendingMaintenanceCompletion] =
        useState<MaintenanceItem | null>(null);
    const [completingMaintenance, setCompletingMaintenance] = useState(false);

    const completeMaintenance = () => {
        if (!pendingMaintenanceCompletion || completingMaintenance) return;
        setCompletingMaintenance(true);
        router.post(
            `/security-devices/maintenance/${pendingMaintenanceCompletion.id}/complete`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => setPendingMaintenanceCompletion(null),
                onFinish: () => setCompletingMaintenance(false),
            },
        );
    };

    const submitMaintenance = () => {
        if (!maintDesc) {
            setMaintError('Description is required.');
            return;
        }
        setMaintSubmitting(true);
        setMaintError('');
        router.post(
            `/security-devices/devices/${device.id}/maintenance`,
            {
                type: maintType,
                description: maintDesc,
                scheduled_for: maintDate || null,
                notes: maintNotes || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setMaintOpen(false);
                    setMaintDesc('');
                    setMaintDate('');
                    setMaintNotes('');
                },
                onError: (errors) => {
                    setMaintError(Object.values(errors).flat().join(' '));
                },
                onFinish: () => setMaintSubmitting(false),
            },
        );
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
            case 'site':
                return (assignmentTargets.sites ?? []).map((s) => ({
                    value: String(s.id),
                    label: s.name,
                }));
            case 'room':
                return (assignmentTargets.rooms ?? []).map((r) => ({
                    value: String(r.id),
                    label: r.name,
                }));
            case 'staff':
                return (assignmentTargets.staff ?? []).map((s) => ({
                    value: String(s.id),
                    label: s.name,
                }));
            case 'client':
                return (assignmentTargets.clients ?? []).map((c) => ({
                    value: String(c.id),
                    label: `${c.first_name} ${c.last_name}`.trim(),
                }));
            case 'vehicle':
                return (assignmentTargets.vehicles ?? []).map((v) => ({
                    value: String(v.id),
                    label: v.registration_number
                        ? `${v.name} (${v.registration_number})`
                        : v.name,
                }));
            default:
                return [];
        }
    }, [assignType, assignmentTargets]);

    const assignmentTargetIsEligible = targetOptions.some(
        (target) => target.value === assignId,
    );
    const assignmentChoiceError =
        assignId && !assignmentTargetIsEligible
            ? 'This assignment target is no longer available. Choose a current target.'
            : '';

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
        if (!assignId) {
            setAssignError('Please select a target.');
            return;
        }
        if (!assignmentTargetIsEligible) {
            setAssignError(
                'This assignment target is no longer available. Choose a current target.',
            );
            return;
        }
        setSubmitting(true);
        setAssignError('');
        router.post(
            `/security-devices/devices/${device.id}/assign`,
            {
                assignable_type: assignType,
                assignable_id: assignId,
                assignment_type: assignmentKind,
                expected_return_at:
                    assignmentKind === 'loan' && returnDate ? returnDate : null,
                notes: assignNotes || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setAssignOpen(false);
                },
                onError: (errors) => {
                    setAssignError(Object.values(errors).flat().join(' '));
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const submitRelease = () => {
        router.post(
            `/security-devices/devices/${device.id}/release`,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'All devices', href: '/security-devices/devices' },
                { title: device.name },
            ]}
        >
            <Head title={`${device.name} - Security & Devices`} />

            <PageShell>
                <DeviceProfileHeader
                    profile={profile}
                    asOf={as_of}
                    onSection={openProfileSection}
                    onSearch={() => setProfileSearchOpen(true)}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <CctvNavigationActions
                                cameraId={device.id}
                                navigation={profile.cctv}
                                onDark
                            />
                            {endpoint_setup && (
                                <PageHeaderGlassButton
                                    onClick={() => setInstallationOpen(true)}
                                >
                                    Set up Obli
                                </PageHeaderGlassButton>
                            )}
                            {can.update && (
                                <PageHeaderGlassButton
                                    icon={Edit}
                                    onClick={editDeviceDialog.openDialog}
                                >
                                    Edit
                                </PageHeaderGlassButton>
                            )}
                            {can.delete &&
                                device.status !== 'decommissioned' && (
                                    <PageHeaderGlassButton
                                        icon={Trash2}
                                        onClick={() =>
                                            setDecommissionOpen(true)
                                        }
                                    >
                                        Decommission
                                    </PageHeaderGlassButton>
                                )}
                        </div>
                    }
                    filters={
                        [
                            'assignments',
                            'events',
                            'maintenance',
                            'topology',
                            'documents',
                        ].includes(activeSection) ? (
                            <PageHeaderViewToggle
                                value={recordsView}
                                onChange={setRecordsView}
                                ariaLabel="Device record view"
                                options={[
                                    { value: 'cards', label: 'Cards' },
                                    { value: 'table', label: 'Table' },
                                ]}
                            />
                        ) : undefined
                    }
                    rail={
                        <DeviceProfileGroupNavigation
                            sections={profile.sections}
                            activeSection={activeSection}
                            onSectionChange={openProfileSection}
                            onSearch={() => setProfileSearchOpen(true)}
                        />
                    }
                />

                <DeviceProfileNavigation
                    sections={profile.sections}
                    activeSection={activeSection}
                    onSectionChange={openProfileSection}
                    searchOpen={profileSearchOpen}
                    onSearchClose={() => setProfileSearchOpen(false)}
                />

                <div
                    id="device-profile-panel"
                    role="tabpanel"
                    aria-labelledby={`device-profile-tab-${activeSection}`}
                >
                    <Tabs
                        value={activeSection}
                        onValueChange={(value) =>
                            openProfileSection(value as DeviceProfileSectionKey)
                        }
                    >
                        {/* ── Overview tab ──────────────────────────── */}
                        <TabsContent value="health" className="space-y-6">
                            <DeviceHealthSection profile={profile} />
                        </TabsContent>

                        <TabsContent value="monitors">
                            <DeviceMonitorsSection
                                profile={profile}
                                deviceName={device.name}
                            />
                        </TabsContent>

                        <TabsContent value="interfaces-sensors">
                            <DeviceInterfacesSensorsSection
                                profile={profile}
                                deviceName={device.name}
                            />
                        </TabsContent>

                        <TabsContent value="configuration">
                            <DeviceConfigurationSection
                                profile={profile}
                                deviceId={device.id}
                                deviceName={device.name}
                                onEditRegistry={editDeviceDialog.openDialog}
                                onEditServiceDue={() => setServiceDueOpen(true)}
                            />
                        </TabsContent>

                        <TabsContent value="endpoint">
                            <DeviceEndpointSection
                                posture={profile.endpointPosture}
                                deviceId={device.id}
                                deviceName={device.name}
                                canSetupAgent={endpoint_setup !== null}
                                onOpenInstallation={() =>
                                    setInstallationOpen(true)
                                }
                            />
                        </TabsContent>

                        <TabsContent value="management">
                            <DeviceManagementSection
                                profile={profile}
                                deviceId={device.id}
                            />
                        </TabsContent>

                        <TabsContent value="tickets">
                            <DeviceTicketsSection profile={profile} />
                        </TabsContent>

                        {/* ── Assignments tab ───────────────────────── */}
                        <TabsContent value="assignments" className="space-y-5">
                            <div className="space-y-5">
                                <Card>
                                    <CardHeader className="flex flex-row items-center justify-between gap-3 space-y-0">
                                        <div>
                                            <CardTitle className="flex items-center gap-2">
                                                <MapPin className="h-4 w-4" />{' '}
                                                Current assignment
                                            </CardTitle>
                                            <CardDescription>
                                                The canonical owner or location
                                                used across Site, Client, Fleet,
                                                HR, and IT context.
                                            </CardDescription>
                                        </div>
                                        {can.assign && (
                                            <div className="flex shrink-0 flex-wrap gap-1">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={openAssignDialog}
                                                >
                                                    {activeAssignment ? (
                                                        <ArrowRightLeft className="mr-1 h-3.5 w-3.5" />
                                                    ) : (
                                                        <Plus className="mr-1 h-3.5 w-3.5" />
                                                    )}
                                                    {activeAssignment
                                                        ? 'Transfer'
                                                        : 'Assign'}
                                                </Button>
                                                {activeAssignment && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            setReleaseOpen(true)
                                                        }
                                                    >
                                                        <Minus className="mr-1 h-3.5 w-3.5" />{' '}
                                                        Release
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                    </CardHeader>
                                    <CardContent>
                                        {activeAssignment ? (
                                            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                                                <Field
                                                    label="Assigned to"
                                                    value={
                                                        activeAssignment.assignable_name
                                                    }
                                                />
                                                <Field
                                                    label="Target type"
                                                    value={targetTypeLabel(
                                                        activeAssignment.assignable_type,
                                                    )}
                                                />
                                                <Field
                                                    label="Assignment type"
                                                    value={
                                                        activeAssignment.assignment_type
                                                    }
                                                />
                                                <Field
                                                    label="Since"
                                                    value={formatDateTime(
                                                        activeAssignment.assigned_at,
                                                    )}
                                                />
                                                {activeAssignment.expected_return_at && (
                                                    <Field
                                                        label="Expected return"
                                                        value={formatDate(
                                                            activeAssignment.expected_return_at,
                                                        )}
                                                    />
                                                )}
                                            </dl>
                                        ) : (
                                            <p className="text-sm text-muted-foreground">
                                                Unassigned pooled stock. Assign
                                                it so other modules can resolve
                                                the correct operational context.
                                            </p>
                                        )}
                                    </CardContent>
                                </Card>

                                <div className="space-y-5">
                                    {can.update &&
                                        pickableAssets.length > 0 && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => {
                                                    setLinkOpen(true);
                                                    setLinkError('');
                                                }}
                                            >
                                                <Plus className="mr-1 h-3.5 w-3.5" />
                                                Link asset
                                            </Button>
                                        )}
                                    <DeviceAssetRecords
                                        rows={assetLinks}
                                        view={recordsView}
                                        canUpdate={can.update}
                                        unlinkingId={unlinkingId}
                                        onUnlink={setPendingAssetUnlink}
                                    />
                                </div>
                            </div>

                            <Dialog open={linkOpen} onOpenChange={setLinkOpen}>
                                <DialogContent
                                    style={{
                                        maxWidth: 'min(92vw, 480px)',
                                        width: 'min(92vw, 480px)',
                                    }}
                                >
                                    <DialogHeader>
                                        <DialogTitle>Link asset</DialogTitle>
                                        <DialogDescription>
                                            Connect this device to the canonical
                                            asset register and record the
                                            physical relationship.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <div className="space-y-4">
                                        <div className="space-y-1">
                                            <label className="text-sm font-medium">
                                                Asset
                                            </label>
                                            <Select
                                                value={linkAssetId}
                                                onValueChange={(value) => {
                                                    setLinkAssetId(value);
                                                    setLinkError('');
                                                }}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select an asset" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {pickableAssets.map(
                                                        (asset) => (
                                                            <SelectItem
                                                                key={asset.id}
                                                                value={String(
                                                                    asset.id,
                                                                )}
                                                            >
                                                                {asset.name}
                                                                {asset.asset_tag
                                                                    ? ` — ${asset.asset_tag}`
                                                                    : ''}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-1">
                                            <label className="text-sm font-medium">
                                                Link type
                                            </label>
                                            <Select
                                                value={linkType}
                                                onValueChange={setLinkType}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {linkTypes.map((option) => (
                                                        <SelectItem
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-1">
                                            <label className="text-sm font-medium">
                                                Notes (optional)
                                            </label>
                                            <Input
                                                value={linkNotes}
                                                onChange={(event) =>
                                                    setLinkNotes(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="e.g. Installed in dashboard console"
                                            />
                                        </div>
                                        {(linkError || assetChoiceError) && (
                                            <p
                                                role="alert"
                                                className="text-sm text-status-critical"
                                            >
                                                {linkError || assetChoiceError}
                                            </p>
                                        )}
                                    </div>
                                    <DialogFooter>
                                        <Button
                                            variant="ghost"
                                            onClick={() => setLinkOpen(false)}
                                            disabled={linkSubmitting}
                                        >
                                            Cancel
                                        </Button>
                                        <Button
                                            onClick={submitLink}
                                            disabled={
                                                linkSubmitting ||
                                                !linkAssetIsEligible
                                            }
                                        >
                                            {linkSubmitting
                                                ? 'Linking…'
                                                : 'Link asset'}
                                        </Button>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>

                            <DeviceAssignmentRecords
                                rows={assignmentHistory}
                                view={recordsView}
                            />
                        </TabsContent>

                        {/* ── Events tab ────────────────────────────── */}
                        <TabsContent value="events" className="space-y-5">
                            {profile.controlRoomAlerts.length > 0 && (
                                <DeviceControlRoomRecords
                                    rows={profile.controlRoomAlerts}
                                    view={recordsView}
                                />
                            )}
                            {can.viewEvents ? (
                                <div className="space-y-5">
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={`/security-devices/monitoring?device_id=${device.id}`}
                                        >
                                            View all events
                                        </Link>
                                    </Button>
                                    <DeviceEventRecords
                                        rows={recentEvents}
                                        view={recordsView}
                                    />
                                </div>
                            ) : (
                                <EmptyState
                                    variant="compact"
                                    icon={Cpu}
                                    title="Device event access required"
                                    description="Your current access doesn’t include device events. Ask an administrator if you need to review them."
                                />
                            )}
                        </TabsContent>

                        {/* ── Maintenance tab ───────────────────────── */}
                        <TabsContent value="maintenance" className="space-y-5">
                            {can.manageMaintenance && (
                                <Button
                                    size="sm"
                                    onClick={() => setMaintOpen(true)}
                                >
                                    <Plus className="mr-1 h-3 w-3" />
                                    Schedule maintenance
                                </Button>
                            )}
                            <DeviceMaintenanceRecords
                                rows={maintenanceRecords}
                                view={recordsView}
                                asOf={as_of}
                                canManage={can.manageMaintenance}
                                onComplete={setPendingMaintenanceCompletion}
                            />
                        </TabsContent>

                        {/* ── Topology tab ──────────────────────────── */}
                        <TabsContent value="topology" className="space-y-5">
                            {can.update && otherDevices.length > 0 && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => {
                                        setRelOpen(true);
                                        setRelError('');
                                    }}
                                >
                                    <Plus className="mr-1 h-3.5 w-3.5" />
                                    Link device
                                </Button>
                            )}
                            <DeviceRelationshipRecords
                                rows={relationships}
                                view={recordsView}
                                canUpdate={can.update}
                                unlinkingId={unlinkingRelId}
                                onUnlink={setPendingRelationshipRemoval}
                            />
                            {totalRelationshipHistory > 0 && (
                                <DeviceRelationshipHistoryRecords
                                    rows={relationshipHistory}
                                    view={recordsView}
                                />
                            )}

                            <Dialog open={relOpen} onOpenChange={setRelOpen}>
                                <DialogContent
                                    style={{
                                        maxWidth: 'min(92vw, 720px)',
                                        width: 'min(92vw, 720px)',
                                    }}
                                >
                                    <DialogHeader>
                                        <DialogTitle>
                                            Link to another device
                                        </DialogTitle>
                                        <DialogDescription>
                                            Record a physical or logical
                                            relationship. Pick a direction, a
                                            relationship type, and the other
                                            device.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <div className="space-y-4">
                                        <div className="space-y-1">
                                            <label className="text-sm font-medium">
                                                Direction
                                            </label>
                                            <Select
                                                value={relDirection}
                                                onValueChange={(v) =>
                                                    setRelDirection(
                                                        v as
                                                            | 'downstream'
                                                            | 'upstream',
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="downstream">
                                                        This device → other
                                                        (downstream)
                                                    </SelectItem>
                                                    <SelectItem value="upstream">
                                                        Other → this device
                                                        (upstream)
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-1">
                                            <label className="text-sm font-medium">
                                                Relationship type
                                            </label>
                                            <Select
                                                value={relType}
                                                onValueChange={setRelType}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {relationshipTypes.map(
                                                        (t) => (
                                                            <SelectItem
                                                                key={t.value}
                                                                value={t.value}
                                                            >
                                                                {t.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-1">
                                            <label className="text-sm font-medium">
                                                Other device
                                            </label>
                                            <Select
                                                value={relOtherDeviceId}
                                                onValueChange={
                                                    setRelOtherDeviceId
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select a device" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {otherDevices.map((d) => (
                                                        <SelectItem
                                                            key={d.id}
                                                            value={String(d.id)}
                                                        >
                                                            {d.name}
                                                            {d.device_uid
                                                                ? ` — ${d.device_uid}`
                                                                : ''}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="grid grid-cols-2 gap-3">
                                            <div className="space-y-1">
                                                <label className="text-sm font-medium">
                                                    Port (optional)
                                                </label>
                                                <Input
                                                    value={relPort}
                                                    onChange={(e) =>
                                                        setRelPort(
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="e.g. Port 3"
                                                />
                                            </div>
                                            <div className="space-y-1">
                                                <label className="text-sm font-medium">
                                                    Notes (optional)
                                                </label>
                                                <Input
                                                    value={relNotes}
                                                    onChange={(e) =>
                                                        setRelNotes(
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="short note"
                                                />
                                            </div>
                                        </div>
                                        {relError && (
                                            <p className="text-sm text-status-critical">
                                                {relError}
                                            </p>
                                        )}
                                    </div>
                                    <DialogFooter>
                                        <Button
                                            variant="ghost"
                                            onClick={() => setRelOpen(false)}
                                            disabled={relSubmitting}
                                        >
                                            Cancel
                                        </Button>
                                        <Button
                                            onClick={submitRelationship}
                                            disabled={
                                                relSubmitting ||
                                                !relOtherDeviceId
                                            }
                                        >
                                            {relSubmitting
                                                ? 'Linking…'
                                                : 'Add relationship'}
                                        </Button>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>
                        </TabsContent>

                        {/* ── Documents tab ─────────────────────────── */}
                        <TabsContent value="documents" className="space-y-5">
                            {can.update && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => {
                                        setDocOpen(true);
                                        setDocError('');
                                    }}
                                >
                                    <Plus className="mr-1 h-3.5 w-3.5" />
                                    Upload document
                                </Button>
                            )}
                            <DeviceDocumentRecords
                                rows={documents}
                                view={recordsView}
                                asOf={as_of}
                                canUpdate={can.update}
                                deletingId={deletingDocId}
                                onRemove={(doc) =>
                                    setPendingDocumentDeletion({
                                        id: doc.id,
                                        title: doc.title,
                                    })
                                }
                            />
                            <DeviceDocumentHistoryRecords
                                rows={documentHistory}
                                view={recordsView}
                            />

                            {/* Upload dialog */}
                            <Dialog open={docOpen} onOpenChange={setDocOpen}>
                                <DialogContent
                                    className="max-h-[90vh] overflow-y-auto"
                                    style={{
                                        maxWidth: 'min(92vw, 720px)',
                                        width: 'min(92vw, 720px)',
                                    }}
                                >
                                    <DialogHeader>
                                        <DialogTitle>
                                            Upload document
                                        </DialogTitle>
                                        <DialogDescription>
                                            Max 20 MB. Uploads are staged and
                                            verified in private storage before
                                            they become available. Removal is
                                            recorded first, then recovered
                                            automatically if quarantine cleanup
                                            is interrupted.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <div className="space-y-4">
                                        <div className="space-y-2">
                                            <label
                                                id="device-doc-file-label"
                                                className="text-sm font-medium"
                                            >
                                                File
                                            </label>
                                            <FileDropzone
                                                id="device-doc-file"
                                                aria-labelledby="device-doc-file-label"
                                                multiple={false}
                                                title="Drag and drop a document here"
                                                hint="PDF, Word, or image up to 20 MB"
                                                disabled={docSubmitting}
                                                onFiles={(files) => {
                                                    setDocFile(
                                                        files[0] ?? null,
                                                    );
                                                    setDocError('');
                                                }}
                                            />
                                            {docFile && (
                                                <StagedFileCard
                                                    file={docFile}
                                                    onRemove={() =>
                                                        setDocFile(null)
                                                    }
                                                />
                                            )}
                                        </div>
                                        <div className="space-y-1">
                                            <label className="text-sm font-medium">
                                                Title
                                            </label>
                                            <Input
                                                value={docTitle}
                                                onChange={(e) =>
                                                    setDocTitle(e.target.value)
                                                }
                                                placeholder="e.g. UVC-G4 Install Manual"
                                            />
                                        </div>
                                        <div className="grid grid-cols-2 gap-3">
                                            <div className="space-y-1">
                                                <label className="text-sm font-medium">
                                                    Category
                                                </label>
                                                <Select
                                                    value={docCategory}
                                                    onValueChange={
                                                        setDocCategory
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {documentCategories.map(
                                                            (c) => (
                                                                <SelectItem
                                                                    key={
                                                                        c.value
                                                                    }
                                                                    value={
                                                                        c.value
                                                                    }
                                                                >
                                                                    {c.label}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="space-y-1">
                                                <label className="text-sm font-medium">
                                                    Version (optional)
                                                </label>
                                                <Input
                                                    value={docVersion}
                                                    onChange={(e) =>
                                                        setDocVersion(
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="e.g. 1.2.0"
                                                />
                                            </div>
                                        </div>
                                        <div className="grid grid-cols-2 gap-3">
                                            <div className="space-y-1">
                                                <label className="text-sm font-medium">
                                                    Effective (optional)
                                                </label>
                                                <Input
                                                    type="date"
                                                    value={docEffective}
                                                    onChange={(e) =>
                                                        setDocEffective(
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-1">
                                                <label className="text-sm font-medium">
                                                    Expires (optional)
                                                </label>
                                                <Input
                                                    type="date"
                                                    value={docExpiry}
                                                    onChange={(e) =>
                                                        setDocExpiry(
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                        </div>
                                        <div className="space-y-1">
                                            <label className="text-sm font-medium">
                                                Notes (optional)
                                            </label>
                                            <Input
                                                value={docNotes}
                                                onChange={(e) =>
                                                    setDocNotes(e.target.value)
                                                }
                                                placeholder="short note"
                                            />
                                        </div>
                                        {docError && (
                                            <p className="text-sm text-status-critical">
                                                {docError}
                                            </p>
                                        )}
                                    </div>
                                    <DialogFooter>
                                        <Button
                                            variant="ghost"
                                            onClick={() => setDocOpen(false)}
                                            disabled={docSubmitting}
                                        >
                                            Cancel
                                        </Button>
                                        <Button
                                            onClick={submitDocument}
                                            disabled={
                                                docSubmitting ||
                                                !docFile ||
                                                !docTitle.trim()
                                            }
                                        >
                                            {docSubmitting
                                                ? 'Uploading…'
                                                : 'Upload'}
                                        </Button>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>
                        </TabsContent>

                        <TabsContent value="audit">
                            <DeviceAuditSection profile={profile} />
                        </TabsContent>
                    </Tabs>
                </div>

                {can.update && (
                    <EditDeviceDialog
                        open={editDeviceDialog.open}
                        onClose={editDeviceDialog.closeDialog}
                        deviceId={device.id}
                    />
                )}

                <Dialog open={serviceDueOpen} onOpenChange={setServiceDueOpen}>
                    <DialogContent
                        style={{
                            maxWidth: 'min(92vw, 480px)',
                            width: 'min(92vw, 480px)',
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle>Update next service date</DialogTitle>
                            <DialogDescription>
                                Keep the lifecycle date current without leaving
                                the device workspace.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-2">
                            <label
                                className="text-sm font-medium"
                                htmlFor="next-service-due"
                            >
                                Next service due
                            </label>
                            <Input
                                id="next-service-due"
                                type="date"
                                value={serviceDue}
                                onChange={(event) =>
                                    setServiceDue(event.target.value)
                                }
                            />
                        </div>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setServiceDueOpen(false)}
                                disabled={serviceDueSubmitting}
                            >
                                Cancel
                            </Button>
                            <Button
                                onClick={submitServiceDue}
                                disabled={serviceDueSubmitting}
                            >
                                {serviceDueSubmitting ? 'Saving…' : 'Save date'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* ── Assign dialog ─────────────────────────────── */}
                <Dialog open={assignOpen} onOpenChange={setAssignOpen}>
                    <DialogContent
                        style={{
                            maxWidth: 'min(92vw, 720px)',
                            width: 'min(92vw, 720px)',
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle>
                                {activeAssignment
                                    ? 'Transfer device'
                                    : 'Assign device'}
                            </DialogTitle>
                            <DialogDescription>
                                {activeAssignment
                                    ? `Currently assigned to ${activeAssignment.assignable_name}. The current assignment will be released automatically.`
                                    : 'Assign this device to a site, room, staff member, client, or vehicle.'}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4">
                            <div>
                                <label className="mb-1.5 block text-sm font-medium">
                                    Target type{' '}
                                    <span className="text-destructive">*</span>
                                </label>
                                <Select
                                    value={assignType}
                                    onValueChange={(v) => {
                                        setAssignType(v);
                                        setAssignId('');
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="site">
                                            Site
                                        </SelectItem>
                                        <SelectItem value="room">
                                            Room
                                        </SelectItem>
                                        <SelectItem value="staff">
                                            Staff
                                        </SelectItem>
                                        <SelectItem value="client">
                                            Client
                                        </SelectItem>
                                        <SelectItem value="vehicle">
                                            Vehicle
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <label className="mb-1.5 block text-sm font-medium">
                                    {targetTypeLabel(assignType)}{' '}
                                    <span className="text-destructive">*</span>
                                </label>
                                <Select
                                    value={assignId}
                                    onValueChange={(value) => {
                                        setAssignId(value);
                                        setAssignError('');
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue
                                            placeholder={`Select ${targetTypeLabel(assignType).toLowerCase()}`}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {targetOptions.map((opt) => (
                                            <SelectItem
                                                key={opt.value}
                                                value={opt.value}
                                            >
                                                {opt.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <label className="mb-1.5 block text-sm font-medium">
                                    Assignment type
                                </label>
                                <Select
                                    value={assignmentKind}
                                    onValueChange={setAssignmentKind}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="permanent">
                                            Permanent
                                        </SelectItem>
                                        <SelectItem value="temporary">
                                            Temporary
                                        </SelectItem>
                                        <SelectItem value="loan">
                                            Loan
                                        </SelectItem>
                                        <SelectItem value="shared">
                                            Shared
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            {assignmentKind === 'loan' && (
                                <div>
                                    <label className="mb-1.5 block text-sm font-medium">
                                        Expected return date
                                    </label>
                                    <Input
                                        type="date"
                                        value={returnDate}
                                        onChange={(e) =>
                                            setReturnDate(e.target.value)
                                        }
                                    />
                                </div>
                            )}

                            {assignType === 'client' && (
                                <Alert variant="warning" role="note">
                                    <AlertDescription>
                                        {device.domain === 'tracking'
                                            ? "Tracking a client needs their active location-tracking consent (NZ privacy). The client's most recent active consent is recorded with this assignment; without one, the assignment is refused."
                                            : "Record the client's consent before assigning this device (NZ privacy). Consent is checked automatically only for tracking devices."}
                                    </AlertDescription>
                                </Alert>
                            )}

                            <div>
                                <label className="mb-1.5 block text-sm font-medium">
                                    Notes (optional)
                                </label>
                                <textarea
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    rows={2}
                                    value={assignNotes}
                                    onChange={(e) =>
                                        setAssignNotes(e.target.value)
                                    }
                                    placeholder="Optional assignment notes..."
                                />
                            </div>

                            {(assignError || assignmentChoiceError) && (
                                <p
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    {assignError || assignmentChoiceError}
                                </p>
                            )}
                        </div>

                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setAssignOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                onClick={submitAssign}
                                disabled={
                                    submitting || !assignmentTargetIsEligible
                                }
                            >
                                {submitting
                                    ? 'Saving...'
                                    : activeAssignment
                                      ? 'Transfer'
                                      : 'Assign'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* ── Maintenance dialog ────────────────────────── */}
                <Dialog open={maintOpen} onOpenChange={setMaintOpen}>
                    <DialogContent
                        style={{
                            maxWidth: 'min(92vw, 720px)',
                            width: 'min(92vw, 720px)',
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle>Schedule maintenance</DialogTitle>
                            <DialogDescription>
                                Create a maintenance record for {device.name}.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4">
                            <div>
                                <label className="mb-1.5 block text-sm font-medium">
                                    Type{' '}
                                    <span className="text-destructive">*</span>
                                </label>
                                <Select
                                    value={maintType}
                                    onValueChange={setMaintType}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="scheduled_service">
                                            Scheduled service
                                        </SelectItem>
                                        <SelectItem value="repair">
                                            Repair
                                        </SelectItem>
                                        <SelectItem value="firmware_update">
                                            Firmware update
                                        </SelectItem>
                                        <SelectItem value="inspection">
                                            Inspection
                                        </SelectItem>
                                        <SelectItem value="replacement">
                                            Replacement
                                        </SelectItem>
                                        <SelectItem value="calibration">
                                            Calibration
                                        </SelectItem>
                                        <SelectItem value="connectivity_check">
                                            Connectivity check
                                        </SelectItem>
                                        <SelectItem value="battery_replacement">
                                            Battery replacement
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <label className="mb-1.5 block text-sm font-medium">
                                    Description{' '}
                                    <span className="text-destructive">*</span>
                                </label>
                                <textarea
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    rows={3}
                                    value={maintDesc}
                                    onChange={(e) =>
                                        setMaintDesc(e.target.value)
                                    }
                                    placeholder="Describe the maintenance work..."
                                />
                            </div>

                            <div>
                                <label className="mb-1.5 block text-sm font-medium">
                                    Scheduled date
                                </label>
                                <Input
                                    type="date"
                                    value={maintDate}
                                    onChange={(e) =>
                                        setMaintDate(e.target.value)
                                    }
                                />
                            </div>

                            <div>
                                <label className="mb-1.5 block text-sm font-medium">
                                    Notes (optional)
                                </label>
                                <textarea
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    rows={2}
                                    value={maintNotes}
                                    onChange={(e) =>
                                        setMaintNotes(e.target.value)
                                    }
                                    placeholder="Optional notes..."
                                />
                            </div>

                            {maintError && (
                                <p className="text-sm text-destructive">
                                    {maintError}
                                </p>
                            )}
                        </div>

                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setMaintOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                onClick={submitMaintenance}
                                disabled={maintSubmitting || !maintDesc}
                            >
                                {maintSubmitting ? 'Saving...' : 'Schedule'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <ConfirmDialog
                    open={pendingAssetUnlink !== null}
                    onClose={() => setPendingAssetUnlink(null)}
                    onConfirm={() => {
                        if (pendingAssetUnlink) {
                            submitUnlink(pendingAssetUnlink.id);
                        }
                    }}
                    title="Unlink asset from device?"
                    description={
                        pendingAssetUnlink
                            ? `Unlink “${pendingAssetUnlink.asset_name ?? `Asset ${pendingAssetUnlink.asset_id}`}” from this device? The asset and the historical link record are preserved.`
                            : ''
                    }
                    confirmText="Unlink asset"
                />

                <ReasonDialog
                    open={pendingRelationshipRemoval !== null}
                    onClose={() => setPendingRelationshipRemoval(null)}
                    onConfirm={(reason, done) => {
                        if (pendingRelationshipRemoval) {
                            submitUnlinkRelationship(
                                pendingRelationshipRemoval.id,
                                reason,
                                done,
                            );
                        }
                    }}
                    title="Remove device relationship?"
                    description={
                        pendingRelationshipRemoval
                            ? `Remove the active relationship with “${pendingRelationshipRemoval.device_name ?? `Device ${pendingRelationshipRemoval.device_id}`}”? Neither Device is deleted, and the relationship is retained as history.`
                            : ''
                    }
                    label="Reason for removing this relationship"
                    placeholder="For example: network path replaced during the approved change."
                    confirmLabel="Remove relationship"
                />

                <ReasonDialog
                    open={pendingDocumentDeletion !== null}
                    onClose={() => setPendingDocumentDeletion(null)}
                    onConfirm={(reason, done) => {
                        if (pendingDocumentDeletion) {
                            submitDeleteDocument(
                                pendingDocumentDeletion.id,
                                reason,
                                done,
                            );
                        }
                    }}
                    title="Remove device document?"
                    description={
                        pendingDocumentDeletion
                            ? `Remove “${pendingDocumentDeletion.title}” from active records? The private file will move through recoverable quarantine before deletion, while its metadata, integrity fingerprint, actor, and reason remain in Document lifecycle history.`
                            : ''
                    }
                    label="Reason for removing this document"
                    placeholder="For example: superseded by a verified current certificate."
                    confirmLabel="Remove document"
                />

                <ConfirmDialog
                    open={pendingMaintenanceCompletion !== null}
                    onClose={() => {
                        if (!completingMaintenance)
                            setPendingMaintenanceCompletion(null);
                    }}
                    onConfirm={completeMaintenance}
                    processing={completingMaintenance}
                    title="Mark maintenance as complete?"
                    description={
                        pendingMaintenanceCompletion
                            ? `Mark “${humanise(pendingMaintenanceCompletion.type)}” for ${device.name} as completed now? It moves out of scheduled maintenance and the completion time is recorded.`
                            : ''
                    }
                    confirmText={
                        completingMaintenance
                            ? 'Completing…'
                            : 'Complete maintenance'
                    }
                    variant="default"
                />

                <ConfirmDialog
                    open={releaseOpen}
                    onClose={() => setReleaseOpen(false)}
                    onConfirm={submitRelease}
                    title="Release device assignment?"
                    description={`Release “${device.name}” from its current assignment? It will return to the available device pool and the assignment history will be preserved.`}
                    confirmText="Release device"
                    variant="default"
                />

                <ConfirmDialog
                    open={decommissionOpen}
                    onClose={() => setDecommissionOpen(false)}
                    onConfirm={() =>
                        router.delete(`/security-devices/devices/${device.id}`)
                    }
                    title="Decommission device?"
                    description={`Decommission “${device.name}”? It will leave the active estate but its record and operational history remain available.`}
                    confirmText="Decommission device"
                />
            </PageShell>
            {endpoint_setup && (
                <InstallationProfileDialog
                    open={installationOpen}
                    onClose={() => setInstallationOpen(false)}
                    deviceId={device.id}
                    actorId={endpoint_setup.actor_id}
                />
            )}
        </AppLayout>
    );
}

// ── Shared sub-component ──────────────────────────────────────────

function Field({
    label,
    value,
    children,
}: {
    label: string;
    value?: string | null;
    children?: React.ReactNode;
}) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 text-sm font-medium">
                {children ??
                    (value || (
                        <span className="text-muted-foreground/50">-</span>
                    ))}
            </dd>
        </div>
    );
}
