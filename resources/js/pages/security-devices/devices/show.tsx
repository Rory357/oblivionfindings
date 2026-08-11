import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    PageHero,
    type PageHeroBadge,
    type PageHeroBadgeTone,
    type PageHeroMetaItem,
    type PageHeroStat,
} from '@/components/page';
import PageShell from '@/components/page-shell';
import { useGroupedProfileSearchShortcut } from '@/components/page/grouped-profile-nav';
import { ReasonDialog } from '@/components/reason-dialog';
import {
    EditDeviceDialog,
    useEditDeviceDialogState,
} from '@/components/security-devices/add-device-dialog';
import {
    DeviceDocumentHistory,
    type DeviceDocumentHistoryItem,
} from '@/components/security-devices/device-document-history';
import {
    ControlRoomAlertAccessRequired,
    FleetTechnologyAccessRequired,
} from '@/components/security-devices/permission-destinations';
import { Badge } from '@/components/ui/badge';
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
import { formatDate, formatDateTime, formatRelative } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowRightLeft,
    Check,
    Clock,
    Cpu,
    Edit,
    FileText,
    GitBranch,
    Link2,
    MapPin,
    Minus,
    Network,
    Plus,
    Radio,
    Trash2,
    Wrench,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { DeviceProfile, DeviceProfileSectionKey } from './device-profile';
import {
    DeviceProfileGroupNavigation,
    DeviceProfileNavigation,
} from './device-profile-navigation';
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

type Props = {
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

function heroTone(state: string | null | undefined): PageHeroBadgeTone {
    switch (state) {
        case 'active':
        case 'available':
        case 'fresh':
        case 'healthy':
        case 'online':
            return 'success';
        case 'attention':
        case 'degraded':
        case 'maintenance':
        case 'stale':
        case 'warning':
            return 'warning';
        case 'critical':
        case 'failed':
        case 'lost':
        case 'offline':
            return 'critical';
        default:
            return 'default';
    }
}

function heroStatTone(
    state: string | null | undefined,
): 'neutral' | 'success' | 'warning' | 'critical' | 'info' {
    const tone = heroTone(state);

    return tone === 'default' ? 'neutral' : tone;
}

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

export default function DeviceShow({
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
    const totalRelationships =
        relationships.parents.length + relationships.children.length;
    const totalRelationshipHistory =
        relationshipHistory.parents.length +
        relationshipHistory.children.length;
    const [activeSection, setActiveSection] = useState<DeviceProfileSectionKey>(
        profile.sections[0]?.key ?? 'health',
    );
    const [profileSearchOpen, setProfileSearchOpen] = useState(false);
    useGroupedProfileSearchShortcut(() => setProfileSearchOpen(true));
    const editDeviceDialog = useEditDeviceDialogState();
    const [releaseOpen, setReleaseOpen] = useState(false);
    const [decommissionOpen, setDecommissionOpen] = useState(false);
    useEffect(() => {
        const syncSectionFromUrl = () => {
            const requested = new URLSearchParams(window.location.search).get(
                'section',
            ) as DeviceProfileSectionKey | null;
            if (
                requested &&
                profile.sections.some((section) => section.key === requested)
            ) {
                setActiveSection(requested);
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

    const formatBytes = (n: number): string => {
        if (n < 1024) return `${n} B`;
        if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
        return `${(n / 1024 / 1024).toFixed(1)} MB`;
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

    const identityDescription = [
        profile.header.identity.manufacturer,
        profile.header.identity.model,
    ]
        .filter(Boolean)
        .join(' ');
    const requiredActionIcon =
        profile.header.requiredAction.state === 'none' ? Check : AlertTriangle;
    const heroMeta: PageHeroMetaItem[] = [
        {
            icon: Cpu,
            label: profile.header.identity.type || 'Registered device',
        },
        { label: profile.header.identity.uid },
        {
            icon: MapPin,
            label: profile.header.location?.name ?? 'Unassigned',
            href: profile.header.location?.href ?? undefined,
        },
        {
            icon: Radio,
            label: profile.header.providerObservation.label,
        },
    ];
    const heroBadges: PageHeroBadge[] = [
        {
            icon: Activity,
            label: humanise(profile.header.health.deviceState),
            tone: heroTone(profile.header.health.deviceState),
            dot: true,
        },
        {
            icon: Radio,
            label: profile.header.health.label,
            tone: heroTone(profile.header.health.state),
        },
        {
            icon: Clock,
            label: humanise(profile.header.freshness.state),
            tone: heroTone(profile.header.freshness.state),
        },
        {
            icon: requiredActionIcon,
            label: profile.header.requiredAction.label,
            tone: heroTone(profile.header.requiredAction.state),
            onClick: () =>
                openProfileSection(profile.header.requiredAction.section),
            'aria-label': `${profile.header.requiredAction.label}: ${profile.header.requiredAction.description}`,
        },
    ];
    const heroStats: PageHeroStat[] = [
        {
            label: 'Monitors',
            value: profile.health.monitoring
                ? `${profile.health.monitoring.healthy}/${profile.health.monitoring.enabled}`
                : '—',
            sub: profile.health.monitoring
                ? 'healthy / enabled'
                : 'Unavailable',
            tone:
                profile.health.monitoring?.attention ||
                profile.health.monitoring?.uncertain
                    ? 'warning'
                    : 'success',
            hideOnMobile: false,
        },
        {
            label: 'Last observation',
            value: formatRelative(
                profile.header.freshness.observedAt,
                Date.now(),
                'Never',
            ),
            sub: profile.header.providerObservation.label,
            tone: heroStatTone(profile.header.freshness.state),
            hideOnMobile: false,
        },
        {
            label: 'Assignment',
            value: profile.header.assignment?.name ?? 'Unassigned',
            sub: profile.header.assignment
                ? humanise(profile.header.assignment.type)
                : 'No current owner',
            hideOnMobile: false,
        },
    ];

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
                <PageHero
                    title={device.name}
                    description={
                        identityDescription ||
                        profile.header.identity.type ||
                        'Registered device'
                    }
                    backHref="/security-devices/devices"
                    backLabel="Devices"
                    icon={Cpu}
                    meta={heroMeta}
                    badges={heroBadges}
                    stats={heroStats}
                    actions={
                        <div className="flex gap-2">
                            {can.update && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={editDeviceDialog.openDialog}
                                >
                                    <Edit className="mr-2 h-4 w-4" /> Edit
                                </Button>
                            )}
                            {can.delete &&
                                device.status !== 'decommissioned' && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setDecommissionOpen(true)
                                        }
                                    >
                                        <Trash2 className="mr-2 h-4 w-4" />{' '}
                                        Decommission
                                    </Button>
                                )}
                        </div>
                    }
                    footer={
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
                            <DeviceMonitorsSection profile={profile} />
                        </TabsContent>

                        <TabsContent value="interfaces-sensors">
                            <DeviceInterfacesSensorsSection profile={profile} />
                        </TabsContent>

                        <TabsContent value="configuration">
                            <DeviceConfigurationSection
                                profile={profile}
                                onEditRegistry={editDeviceDialog.openDialog}
                                onEditServiceDue={() => setServiceDueOpen(true)}
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
                        <TabsContent value="assignments" className="space-y-4">
                            <div className="grid gap-4 lg:grid-cols-2">
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

                                <Card>
                                    <CardHeader className="flex flex-row items-center justify-between gap-3 space-y-0">
                                        <div>
                                            <CardTitle className="flex items-center gap-2">
                                                <Link2 className="h-4 w-4" />{' '}
                                                Linked assets
                                            </CardTitle>
                                            <CardDescription>
                                                Physical asset relationships
                                                without duplicating the asset
                                                register.
                                            </CardDescription>
                                        </div>
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
                                                    <Plus className="mr-1 h-3.5 w-3.5" />{' '}
                                                    Link asset
                                                </Button>
                                            )}
                                    </CardHeader>
                                    <CardContent>
                                        {assetLinks.length > 0 ? (
                                            <div className="space-y-2">
                                                {assetLinks.map((link) => (
                                                    <div
                                                        key={link.id}
                                                        className="flex items-center justify-between gap-3 rounded-md border p-3 text-sm"
                                                    >
                                                        <div className="min-w-0 flex-1">
                                                            {link.href ? (
                                                                <Link
                                                                    href={
                                                                        link.href
                                                                    }
                                                                    className="frontline-focus inline-block max-w-full truncate rounded-sm font-medium text-primary hover:underline"
                                                                >
                                                                    {link.asset_name ??
                                                                        `Asset #${link.asset_id}`}
                                                                </Link>
                                                            ) : (
                                                                <>
                                                                    <p className="truncate font-medium">
                                                                        {link.asset_name ??
                                                                            `Asset #${link.asset_id}`}
                                                                    </p>
                                                                    {link.access
                                                                        .state ===
                                                                    'restricted' ? (
                                                                        <FleetTechnologyAccessRequired
                                                                            label={
                                                                                link
                                                                                    .access
                                                                                    .label
                                                                            }
                                                                            className="min-h-0 text-xs"
                                                                        />
                                                                    ) : null}
                                                                </>
                                                            )}
                                                            {link.asset_tag && (
                                                                <p className="font-mono text-xs text-muted-foreground">
                                                                    {
                                                                        link.asset_tag
                                                                    }
                                                                </p>
                                                            )}
                                                            {link.notes && (
                                                                <p className="mt-1 text-xs text-muted-foreground">
                                                                    {link.notes}
                                                                </p>
                                                            )}
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            <Badge
                                                                variant="outline"
                                                                className="text-[10px]"
                                                            >
                                                                {link.link_type.replace(
                                                                    /_/g,
                                                                    ' ',
                                                                )}
                                                            </Badge>
                                                            {can.update && (
                                                                <Button
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    className="min-h-11 min-w-11 px-2 text-status-critical hover:text-status-critical"
                                                                    aria-label={`Unlink ${link.asset_name ?? `Asset ${link.asset_id}`} from device`}
                                                                    onClick={() =>
                                                                        setPendingAssetUnlink(
                                                                            link,
                                                                        )
                                                                    }
                                                                    disabled={
                                                                        unlinkingId ===
                                                                        link.id
                                                                    }
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
                                            <p className="text-sm text-muted-foreground">
                                                No linked assets.
                                            </p>
                                        )}
                                    </CardContent>
                                </Card>
                            </div>

                            <Dialog open={linkOpen} onOpenChange={setLinkOpen}>
                                <DialogContent className="sm:max-w-md">
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
                                                onValueChange={setLinkAssetId}
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
                                        {linkError && (
                                            <p className="text-sm text-status-critical">
                                                {linkError}
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
                                                linkSubmitting || !linkAssetId
                                            }
                                        >
                                            {linkSubmitting
                                                ? 'Linking…'
                                                : 'Link asset'}
                                        </Button>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>

                            <div className="flex items-center justify-between">
                                <div>
                                    <h3 className="text-lg font-semibold">
                                        Assignment History
                                    </h3>
                                    <p className="text-sm text-muted-foreground">
                                        All assignments for this device, newest
                                        first.
                                    </p>
                                </div>
                                {can.assign && (
                                    <Button
                                        size="sm"
                                        onClick={openAssignDialog}
                                    >
                                        <Plus className="mr-1 h-3 w-3" /> Assign
                                    </Button>
                                )}
                            </div>

                            {assignmentHistory.length > 0 ? (
                                <div className="space-y-2">
                                    {assignmentHistory.map((a) => (
                                        <div
                                            key={a.id}
                                            className={`rounded-lg border p-4 text-sm ${a.is_active ? 'border-primary bg-primary/5' : ''}`}
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-medium">
                                                            {a.assignable_name}
                                                        </span>
                                                        <Badge
                                                            variant="outline"
                                                            className="text-[10px]"
                                                        >
                                                            {targetTypeLabel(
                                                                a.assignable_type,
                                                            )}
                                                        </Badge>
                                                        <Badge
                                                            variant="outline"
                                                            className="text-[10px]"
                                                        >
                                                            {a.assignment_type}
                                                        </Badge>
                                                        {a.is_active && (
                                                            <Badge
                                                                variant="default"
                                                                className="text-[10px]"
                                                            >
                                                                Active
                                                            </Badge>
                                                        )}
                                                        {a.is_overdue && (
                                                            <Badge
                                                                variant="destructive"
                                                                className="text-[10px]"
                                                            >
                                                                Overdue
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    <div className="mt-1 flex flex-wrap gap-x-4 text-xs text-muted-foreground">
                                                        <span>
                                                            Assigned:{' '}
                                                            {formatDateTime(
                                                                a.assigned_at,
                                                            )}
                                                        </span>
                                                        {a.assigned_by && (
                                                            <span>
                                                                by{' '}
                                                                {a.assigned_by}
                                                            </span>
                                                        )}
                                                        {a.released_at && (
                                                            <>
                                                                <span>
                                                                    Released:{' '}
                                                                    {formatDateTime(
                                                                        a.released_at,
                                                                    )}
                                                                </span>
                                                                {a.released_by && (
                                                                    <span>
                                                                        by{' '}
                                                                        {
                                                                            a.released_by
                                                                        }
                                                                    </span>
                                                                )}
                                                            </>
                                                        )}
                                                        {a.expected_return_at && (
                                                            <span>
                                                                Return by:{' '}
                                                                {formatDate(
                                                                    a.expected_return_at,
                                                                )}
                                                            </span>
                                                        )}
                                                    </div>
                                                    {a.notes && (
                                                        <p className="mt-1 text-xs text-muted-foreground italic">
                                                            {a.notes}
                                                        </p>
                                                    )}
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
                                    action={
                                        can.assign ? (
                                            <Button
                                                size="sm"
                                                onClick={openAssignDialog}
                                            >
                                                Assign Now
                                            </Button>
                                        ) : undefined
                                    }
                                />
                            )}
                        </TabsContent>

                        {/* ── Events tab ────────────────────────────── */}
                        <TabsContent value="events" className="space-y-4">
                            {profile.controlRoomAlerts.length > 0 && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle>
                                            Control Room alerts
                                        </CardTitle>
                                        <CardDescription>
                                            Active operational alerts linked
                                            through this device's canonical
                                            Control Room projection.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-2">
                                        {profile.controlRoomAlerts.map(
                                            (alert, index) =>
                                                alert.href &&
                                                alert.access.state ===
                                                    'available' ? (
                                                    <Link
                                                        key={
                                                            alert.id ??
                                                            `restricted-alert-${index}`
                                                        }
                                                        href={alert.href}
                                                        className="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm transition-colors hover:bg-muted/50"
                                                    >
                                                        <span className="min-w-0">
                                                            <span className="font-medium">
                                                                {
                                                                    alert.reference
                                                                }
                                                            </span>
                                                            <span className="ml-2 text-muted-foreground">
                                                                {alert.type?.replace(
                                                                    /[._-]+/g,
                                                                    ' ',
                                                                )}
                                                            </span>
                                                        </span>
                                                        <span className="flex shrink-0 items-center gap-2">
                                                            {alert.severity ? (
                                                                <Badge variant="outline">
                                                                    {
                                                                        alert.severity
                                                                    }
                                                                </Badge>
                                                            ) : null}
                                                            {alert.status ? (
                                                                <Badge variant="secondary">
                                                                    {
                                                                        alert.status
                                                                    }
                                                                </Badge>
                                                            ) : null}
                                                            <span className="text-xs text-muted-foreground">
                                                                {formatDateTime(
                                                                    alert.triggeredAt,
                                                                )}
                                                            </span>
                                                        </span>
                                                    </Link>
                                                ) : (
                                                    <div
                                                        key={
                                                            alert.id ??
                                                            `restricted-alert-${index}`
                                                        }
                                                        className="rounded-md border px-3"
                                                    >
                                                        <ControlRoomAlertAccessRequired
                                                            label={
                                                                alert.access
                                                                    .label
                                                            }
                                                        />
                                                    </div>
                                                ),
                                        )}
                                    </CardContent>
                                </Card>
                            )}

                            {can.viewEvents && (
                                <>
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <h3 className="text-lg font-semibold">
                                                Recent Events
                                            </h3>
                                            <p className="text-sm text-muted-foreground">
                                                Last 20 events for this device.
                                            </p>
                                        </div>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={`/security-devices/monitoring?device_id=${device.id}`}
                                            >
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
                                                        evt.severity ===
                                                        'critical'
                                                            ? 'border-status-critical/30 bg-status-critical-bg dark:border-status-critical/30'
                                                            : evt.severity ===
                                                                'warning'
                                                              ? 'border-status-warning/30 bg-status-warning-bg dark:border-status-warning/30'
                                                              : ''
                                                    }`}
                                                >
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <Badge
                                                                variant={
                                                                    evt.severity ===
                                                                    'critical'
                                                                        ? 'destructive'
                                                                        : evt.severity ===
                                                                            'warning'
                                                                          ? 'outline'
                                                                          : 'secondary'
                                                                }
                                                                className="text-[10px]"
                                                            >
                                                                {evt.severity}
                                                            </Badge>
                                                            <span className="font-medium">
                                                                {evt.event_type.replace(
                                                                    /_/g,
                                                                    ' ',
                                                                )}
                                                            </span>
                                                            {evt.source && (
                                                                <span className="text-xs text-muted-foreground">
                                                                    via{' '}
                                                                    {evt.source}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>
                                                    <span className="shrink-0 text-xs text-muted-foreground">
                                                        {formatDateTime(
                                                            evt.occurred_at,
                                                        )}
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <EmptyState
                                            icon={Clock}
                                            title="No events yet"
                                            description="Device events will appear here as they are recorded."
                                            variant="compact"
                                        />
                                    )}
                                </>
                            )}
                        </TabsContent>

                        {/* ── Maintenance tab ───────────────────────── */}
                        <TabsContent value="maintenance" className="space-y-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <h3 className="text-lg font-semibold">
                                        Maintenance Records
                                    </h3>
                                    <p className="text-sm text-muted-foreground">
                                        Scheduled, in-progress, and completed
                                        maintenance for this device.
                                    </p>
                                </div>
                                {can.manageMaintenance && (
                                    <Button
                                        size="sm"
                                        onClick={() => setMaintOpen(true)}
                                    >
                                        <Plus className="mr-1 h-3 w-3" />{' '}
                                        Schedule Maintenance
                                    </Button>
                                )}
                            </div>

                            {maintenanceRecords.length > 0 ? (
                                <div className="space-y-2">
                                    {maintenanceRecords.map((m) => {
                                        const isOverdue =
                                            m.status === 'scheduled' &&
                                            m.scheduled_for &&
                                            new Date(m.scheduled_for) <
                                                new Date();
                                        return (
                                            <div
                                                key={m.id}
                                                className={`rounded-lg border p-4 text-sm ${isOverdue ? 'border-status-warning/30 bg-status-warning-bg' : ''}`}
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-medium">
                                                                {m.type.replace(
                                                                    /_/g,
                                                                    ' ',
                                                                )}
                                                            </span>
                                                            <Badge
                                                                variant={
                                                                    m.status ===
                                                                    'completed'
                                                                        ? 'default'
                                                                        : m.status ===
                                                                            'scheduled'
                                                                          ? 'outline'
                                                                          : 'secondary'
                                                                }
                                                                className="text-[10px]"
                                                            >
                                                                {m.status}
                                                            </Badge>
                                                            {isOverdue && (
                                                                <Badge
                                                                    variant="destructive"
                                                                    className="text-[10px]"
                                                                >
                                                                    Overdue
                                                                </Badge>
                                                            )}
                                                        </div>
                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                            {m.description}
                                                        </p>
                                                        <div className="mt-1 flex flex-wrap gap-x-4 text-xs text-muted-foreground">
                                                            {m.scheduled_for && (
                                                                <span>
                                                                    Due:{' '}
                                                                    {formatDate(
                                                                        m.scheduled_for,
                                                                    )}
                                                                </span>
                                                            )}
                                                            {m.completed_at && (
                                                                <span>
                                                                    Completed:{' '}
                                                                    {formatDateTime(
                                                                        m.completed_at,
                                                                    )}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>
                                                    {can.manageMaintenance &&
                                                        m.status ===
                                                            'scheduled' && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() =>
                                                                    router.post(
                                                                        `/security-devices/maintenance/${m.id}/complete`,
                                                                        {},
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                <Check className="mr-1 h-3 w-3" />{' '}
                                                                Complete
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
                                    action={
                                        can.manageMaintenance ? (
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    setMaintOpen(true)
                                                }
                                            >
                                                Schedule Maintenance
                                            </Button>
                                        ) : undefined
                                    }
                                />
                            )}
                        </TabsContent>

                        {/* ── Topology tab ──────────────────────────── */}
                        <TabsContent value="topology">
                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between space-y-0">
                                    <div>
                                        <CardTitle>Device Topology</CardTitle>
                                        <CardDescription>
                                            Physical and logical relationships
                                            to other devices.
                                        </CardDescription>
                                    </div>
                                    {can.update && otherDevices.length > 0 && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => {
                                                setRelOpen(true);
                                                setRelError('');
                                            }}
                                        >
                                            <Plus className="mr-1 h-3.5 w-3.5" />{' '}
                                            Link device
                                        </Button>
                                    )}
                                </CardHeader>
                                <CardContent>
                                    {totalRelationships > 0 ? (
                                        <div className="space-y-4">
                                            {relationships.parents.length >
                                                0 && (
                                                <div>
                                                    <p className="mb-2 text-xs font-medium text-muted-foreground">
                                                        Upstream (this device's
                                                        parents)
                                                    </p>
                                                    {relationships.parents.map(
                                                        (r) => (
                                                            <div
                                                                key={r.id}
                                                                className="mb-1 flex items-center gap-3 rounded-md border p-3 text-sm"
                                                            >
                                                                <Network className="h-4 w-4 text-muted-foreground" />
                                                                <span className="text-muted-foreground">
                                                                    This device{' '}
                                                                    {r.type.replace(
                                                                        /_/g,
                                                                        ' ',
                                                                    )}
                                                                </span>
                                                                <Link
                                                                    href={`/security-devices/devices/${r.device_id}`}
                                                                    className="flex-1 truncate text-primary hover:underline"
                                                                >
                                                                    {r.device_name ??
                                                                        `Device #${r.device_id}`}
                                                                </Link>
                                                                {r.port && (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="text-[10px]"
                                                                    >
                                                                        {r.port}
                                                                    </Badge>
                                                                )}
                                                                {can.update && (
                                                                    <Button
                                                                        size="sm"
                                                                        variant="ghost"
                                                                        className="min-h-11 min-w-11 px-2 text-status-critical hover:text-status-critical"
                                                                        aria-label={`Remove relationship to ${r.device_name ?? `Device ${r.device_id}`}`}
                                                                        onClick={() =>
                                                                            setPendingRelationshipRemoval(
                                                                                r,
                                                                            )
                                                                        }
                                                                        disabled={
                                                                            unlinkingRelId ===
                                                                            r.id
                                                                        }
                                                                        title="Remove relationship"
                                                                    >
                                                                        <Minus className="h-3.5 w-3.5" />
                                                                    </Button>
                                                                )}
                                                            </div>
                                                        ),
                                                    )}
                                                </div>
                                            )}
                                            {relationships.children.length >
                                                0 && (
                                                <div>
                                                    <p className="mb-2 text-xs font-medium text-muted-foreground">
                                                        Downstream (this
                                                        device's children)
                                                    </p>
                                                    {relationships.children.map(
                                                        (r) => (
                                                            <div
                                                                key={r.id}
                                                                className="mb-1 flex items-center gap-3 rounded-md border p-3 text-sm"
                                                            >
                                                                <GitBranch className="h-4 w-4 text-muted-foreground" />
                                                                <Link
                                                                    href={`/security-devices/devices/${r.device_id}`}
                                                                    className="flex-1 truncate text-primary hover:underline"
                                                                >
                                                                    {r.device_name ??
                                                                        `Device #${r.device_id}`}
                                                                </Link>
                                                                <span className="text-muted-foreground">
                                                                    {r.type.replace(
                                                                        /_/g,
                                                                        ' ',
                                                                    )}{' '}
                                                                    this device
                                                                </span>
                                                                {r.port && (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="text-[10px]"
                                                                    >
                                                                        {r.port}
                                                                    </Badge>
                                                                )}
                                                                {can.update && (
                                                                    <Button
                                                                        size="sm"
                                                                        variant="ghost"
                                                                        className="min-h-11 min-w-11 px-2 text-status-critical hover:text-status-critical"
                                                                        aria-label={`Remove relationship to ${r.device_name ?? `Device ${r.device_id}`}`}
                                                                        onClick={() =>
                                                                            setPendingRelationshipRemoval(
                                                                                r,
                                                                            )
                                                                        }
                                                                        disabled={
                                                                            unlinkingRelId ===
                                                                            r.id
                                                                        }
                                                                        title="Remove relationship"
                                                                    >
                                                                        <Minus className="h-3.5 w-3.5" />
                                                                    </Button>
                                                                )}
                                                            </div>
                                                        ),
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    ) : (
                                        <EmptyState
                                            icon={Network}
                                            title="No relationships"
                                            description={
                                                can.update
                                                    ? 'Click "Link device" to record a physical or logical connection.'
                                                    : 'Device topology relationships will appear here when configured.'
                                            }
                                            variant="compact"
                                        />
                                    )}
                                    {totalRelationshipHistory > 0 && (
                                        <details className="mt-5 border-t pt-4">
                                            <summary className="flex min-h-11 cursor-pointer items-center font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none">
                                                Relationship history (
                                                {totalRelationshipHistory})
                                            </summary>
                                            <p className="mb-3 text-xs text-muted-foreground">
                                                Removed relationships are
                                                retained with their operator and
                                                reason evidence.
                                            </p>
                                            <div className="space-y-2">
                                                {relationshipHistory.parents.map(
                                                    (relationship) => (
                                                        <div
                                                            key={
                                                                relationship.id
                                                            }
                                                            className="rounded-md border bg-muted/20 p-3 text-sm"
                                                        >
                                                            <p>
                                                                This device{' '}
                                                                {relationship.type.replace(
                                                                    /_/g,
                                                                    ' ',
                                                                )}{' '}
                                                                <Link
                                                                    href={`/security-devices/devices/${relationship.device_id}`}
                                                                    className="font-medium text-primary hover:underline"
                                                                >
                                                                    {relationship.device_name ??
                                                                        `Device #${relationship.device_id}`}
                                                                </Link>
                                                            </p>
                                                            <p className="mt-1 text-xs text-muted-foreground">
                                                                Removed{' '}
                                                                {formatDateTime(
                                                                    relationship.unlinked_at,
                                                                )}
                                                                {relationship.unlinked_by
                                                                    ? ` by ${relationship.unlinked_by}`
                                                                    : ''}
                                                                . Reason:{' '}
                                                                <span className="break-words text-foreground">
                                                                    {
                                                                        relationship.unlink_reason
                                                                    }
                                                                </span>
                                                            </p>
                                                        </div>
                                                    ),
                                                )}
                                                {relationshipHistory.children.map(
                                                    (relationship) => (
                                                        <div
                                                            key={
                                                                relationship.id
                                                            }
                                                            className="rounded-md border bg-muted/20 p-3 text-sm"
                                                        >
                                                            <p>
                                                                <Link
                                                                    href={`/security-devices/devices/${relationship.device_id}`}
                                                                    className="font-medium text-primary hover:underline"
                                                                >
                                                                    {relationship.device_name ??
                                                                        `Device #${relationship.device_id}`}
                                                                </Link>{' '}
                                                                {relationship.type.replace(
                                                                    /_/g,
                                                                    ' ',
                                                                )}{' '}
                                                                this device
                                                            </p>
                                                            <p className="mt-1 text-xs text-muted-foreground">
                                                                Removed{' '}
                                                                {formatDateTime(
                                                                    relationship.unlinked_at,
                                                                )}
                                                                {relationship.unlinked_by
                                                                    ? ` by ${relationship.unlinked_by}`
                                                                    : ''}
                                                                . Reason:{' '}
                                                                <span className="break-words text-foreground">
                                                                    {
                                                                        relationship.unlink_reason
                                                                    }
                                                                </span>
                                                            </p>
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        </details>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Link-device dialog */}
                            <Dialog open={relOpen} onOpenChange={setRelOpen}>
                                <DialogContent className="sm:max-w-md">
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
                        <TabsContent value="documents">
                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between space-y-0">
                                    <div>
                                        <CardTitle>Documents</CardTitle>
                                        <CardDescription>
                                            Manuals, compliance certs, install
                                            photos, firmware notes, and other
                                            device-specific files.
                                        </CardDescription>
                                    </div>
                                    {can.update && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => {
                                                setDocOpen(true);
                                                setDocError('');
                                            }}
                                        >
                                            <Plus className="mr-1 h-3.5 w-3.5" />{' '}
                                            Upload document
                                        </Button>
                                    )}
                                </CardHeader>
                                <CardContent>
                                    {documents.length === 0 ? (
                                        <EmptyState
                                            icon={FileText}
                                            title="No documents"
                                            description={
                                                can.update
                                                    ? 'Click "Upload document" to attach a manual, photo, or compliance cert.'
                                                    : 'No documents have been uploaded for this device.'
                                            }
                                            variant="compact"
                                        />
                                    ) : (
                                        <div className="space-y-2">
                                            {documents.map((doc) => {
                                                const expired =
                                                    doc.expiry_date &&
                                                    new Date(doc.expiry_date) <
                                                        new Date();
                                                return (
                                                    <div
                                                        key={doc.id}
                                                        className="flex items-start gap-3 rounded-md border p-3 text-sm"
                                                    >
                                                        <FileText className="mt-0.5 h-4 w-4 text-muted-foreground" />
                                                        <div className="min-w-0 flex-1 space-y-1">
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <a
                                                                    href={
                                                                        doc.download_url
                                                                    }
                                                                    className="truncate font-medium text-primary hover:underline"
                                                                >
                                                                    {doc.title}
                                                                </a>
                                                                <Badge
                                                                    variant="outline"
                                                                    className="text-[10px]"
                                                                >
                                                                    {doc.category.replace(
                                                                        /_/g,
                                                                        ' ',
                                                                    )}
                                                                </Badge>
                                                                {doc.version && (
                                                                    <Badge
                                                                        variant="secondary"
                                                                        className="text-[10px]"
                                                                    >
                                                                        v
                                                                        {
                                                                            doc.version
                                                                        }
                                                                    </Badge>
                                                                )}
                                                                {expired && (
                                                                    <Badge
                                                                        variant="destructive"
                                                                        className="text-[10px]"
                                                                    >
                                                                        Expired
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                            <p className="truncate font-mono text-xs text-muted-foreground">
                                                                {
                                                                    doc.original_name
                                                                }{' '}
                                                                ·{' '}
                                                                {formatBytes(
                                                                    doc.size_bytes,
                                                                )}
                                                                {doc.expiry_date &&
                                                                    ` · expires ${doc.expiry_date}`}
                                                            </p>
                                                            {doc.notes && (
                                                                <p className="text-xs text-muted-foreground">
                                                                    {doc.notes}
                                                                </p>
                                                            )}
                                                        </div>
                                                        {can.update && (
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                className="min-h-11 min-w-11 px-2 text-status-critical hover:text-status-critical"
                                                                aria-label={`Remove document ${doc.title}`}
                                                                onClick={() =>
                                                                    setPendingDocumentDeletion(
                                                                        {
                                                                            id: doc.id,
                                                                            title: doc.title,
                                                                        },
                                                                    )
                                                                }
                                                                disabled={
                                                                    deletingDocId ===
                                                                    doc.id
                                                                }
                                                                title="Remove document"
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

                            <DeviceDocumentHistory items={documentHistory} />

                            {/* Upload dialog */}
                            <Dialog open={docOpen} onOpenChange={setDocOpen}>
                                <DialogContent className="sm:max-w-md">
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
                                        <div className="space-y-1">
                                            <label className="text-sm font-medium">
                                                File
                                            </label>
                                            <Input
                                                type="file"
                                                onChange={(e) =>
                                                    setDocFile(
                                                        e.target.files?.[0] ??
                                                            null,
                                                    )
                                                }
                                            />
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
                    <DialogContent className="sm:max-w-md">
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
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                {activeAssignment
                                    ? 'Transfer Device'
                                    : 'Assign Device'}
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
                                    onValueChange={setAssignId}
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
                                <div className="rounded-md border border-status-warning/30 bg-status-warning-bg p-3 text-xs text-status-warning dark:bg-status-warning-bg dark:text-status-warning">
                                    Client device assignments require a valid
                                    consent record (NZ privacy). Ensure consent
                                    has been recorded before assigning.
                                </div>
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

                            {assignError && (
                                <p className="text-sm text-destructive">
                                    {assignError}
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
                                disabled={submitting || !assignId}
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
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Schedule Maintenance</DialogTitle>
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
                                            Scheduled Service
                                        </SelectItem>
                                        <SelectItem value="repair">
                                            Repair
                                        </SelectItem>
                                        <SelectItem value="firmware_update">
                                            Firmware Update
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
                                            Connectivity Check
                                        </SelectItem>
                                        <SelectItem value="battery_replacement">
                                            Battery Replacement
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
