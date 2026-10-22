import { ConfirmDialog } from '@/components/confirm-dialog';
import { type EntityTableColumn, type MenuItem } from '@/components/lists';
import { EvidenceRecords } from '@/components/lists/evidence-records';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
    PageLayout,
} from '@/components/page';
import { OperationalStateBadge } from '@/components/security-devices/estate-operations';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    rememberOverlayTrigger,
    restoreOverlayFocus,
} from '@/components/ui/overlay-focus-return';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle,
    CalendarClock,
    Cpu,
    Edit,
    Eye,
    GitBranch,
    Info,
    LayoutGrid,
    List,
    Minus,
    Plus,
    RefreshCw,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    DeviceCard,
    domainLabel,
    type DeviceListItem,
    type Paginated,
} from '../devices/shared';
import { GroupWizard, groupTypeLabel } from './_dialogs';
import type { AutoRuleCondition, AutoRules } from './auto-rule-builder';
import MaintenancePolicyPreview, {
    maintenanceMemberIdentity,
    strictPreviewUtc,
} from './maintenance-policy-preview';

export type GroupDetail = {
    id: number;
    name: string;
    type: string;
    description: string | null;
    created_at: string | null;
    auto_rules: AutoRules | null;
    auto_rule_condition_count: number;
};
export type AvailableDevice = {
    id: number;
    name: string;
    device_uid: string;
    domain: string;
    category: string;
};
export type GroupShowProps = {
    actor_id: number;
    as_of: string;
    /** Server rule for the maintenance preview read (DeviceGroupMaintenancePreview::actorMayPreview). */
    can_preview_maintenance: boolean;
    group: GroupDetail;
    members: Paginated<DeviceListItem>;
    availableDevices: AvailableDevice[];
};
export type AutoRulePreview = {
    count: number;
    changes: { added: number; removed: number; kept: number; total: number };
    sample: Array<{
        id: number;
        name: string;
        device_uid: string;
        category: string;
    }>;
};
type ProfileView = 'members' | 'rules' | 'details';
const label = (value: string | null) =>
    value ? value.replaceAll('_', ' ') : 'Not recorded';
const ruleFieldLabel = (field: AutoRuleCondition['field']) =>
    ({
        domain: 'Area',
        category: 'Device type',
        subcategory: 'Device subtype',
        provider: 'Provider',
        status: 'Operational status',
        health_status: 'Health status',
    })[field];
const ruleOperatorLabel = (op: AutoRuleCondition['op']) =>
    ({ equals: 'is', not_equals: 'is not', in: 'is one of' })[op];
const previewSections = [
    {
        key: 'changes',
        label: 'Proposed changes',
        blurb: 'Additions, removals and final count',
        icon: RefreshCw,
    },
    {
        key: 'matches',
        label: 'Matching devices',
        blurb: 'A sample of approved matches',
        icon: Cpu,
    },
];
function MemberCard({
    row,
    actions,
}: {
    row: DeviceListItem;
    actions: MenuItem[];
}) {
    return <DeviceCard device={row} actions={actions} />;
}
export default function DeviceGroupShow(props: GroupShowProps) {
    const [layout, setLayout] = useState<'cards' | 'table'>('cards');
    const { auth } = usePage<{
        auth?: {
            user?: { id: number } | null;
            can?: {
                securityDevices?: {
                    groupsManage?: boolean;
                    monitoringManage?: boolean;
                };
            };
        };
    }>().props;
    const [sourceActor] = useState(props.actor_id);
    const visible =
        Number.isSafeInteger(sourceActor) &&
        sourceActor > 0 &&
        sourceActor === props.actor_id &&
        auth?.user?.id === sourceActor &&
        auth.can?.securityDevices?.groupsManage === true &&
        typeof props.as_of === 'string' &&
        strictPreviewUtc(props.as_of);
    if (!visible)
        return (
            <AppLayout
                breadcrumbs={[
                    { title: 'Home', href: '/dashboard' },
                    { title: 'Security & Devices', href: '/security-devices' },
                    {
                        title: 'Device groups',
                        href: '/security-devices/device-groups',
                    },
                    { title: 'Group access unavailable' },
                ]}
            >
                <Head title="Group access unavailable" />
                <PageLayout
                    hero={
                        <PageHeader
                            title="Group access unavailable"
                            icon={Cpu}
                            backHref="/security-devices/device-groups"
                            subline="Refresh the group to review current access."
                            actions={
                                <PageHeaderGlassButton
                                    icon={RefreshCw}
                                    onClick={() =>
                                        router.get(
                                            '/security-devices/device-groups/' +
                                                props.group.id,
                                            {},
                                            {
                                                preserveState: false,
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                >
                                    Refresh group
                                </PageHeaderGlassButton>
                            }
                        />
                    }
                >
                    <div role="status">
                        <EmptyState
                            icon={Cpu}
                            title="This group isn’t available to you right now"
                            description="Your access or the group may have changed since the page opened. Refresh the group to see what you can open now."
                        />
                    </div>
                </PageLayout>
            </AppLayout>
        );
    return (
        <GroupProfile
            // Stable identity: remount only for another actor or group, so an
            // open dialog survives ordinary responses (validation errors,
            // reloads). Authority changes are handled by `visible` above.
            key={`${sourceActor}:${props.group.id}`}
            {...props}
            sourceActor={sourceActor}
            canPreview={
                props.can_preview_maintenance === true &&
                auth.can?.securityDevices?.monitoringManage === true
            }
            layout={layout}
            setLayout={setLayout}
        />
    );
}
function GroupProfile({
    actor_id,
    as_of,
    sourceActor,
    canPreview,
    group,
    members,
    availableDevices,
    layout,
    setLayout,
}: GroupShowProps & {
    sourceActor: number;
    canPreview: boolean;
    layout: 'cards' | 'table';
    setLayout: (value: 'cards' | 'table') => void;
}) {
    const maintenanceFocus = useRef<{
        target: HTMLElement | null;
        owner: HTMLElement | null;
    }>({ target: null, owner: null });
    const [maintenanceMember, setMaintenanceMember] = useState<{
        id: number;
        identity: string;
    } | null>(null);
    const currentPreviewMember = maintenanceMember
        ? members.data.find(
              (member) =>
                  member.id === maintenanceMember.id &&
                  maintenanceMemberIdentity(member) ===
                      maintenanceMember.identity,
          )
        : undefined;
    const [editOpen, setEditOpen] = useState(false);
    const [edited, setEdited] = useState(false);
    const [view, setView] = useState<ProfileView>('members');
    const [search, setSearch] = useState('');
    const [attentionOnly, setAttentionOnly] = useState(false);
    const [addOpen, setAddOpen] = useState(false);
    const [selectedDevice, setSelectedDevice] = useState('');
    const [discardOpen, setDiscardOpen] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [addError, setAddError] = useState<string | null>(null);
    const [syncingAuto, setSyncingAuto] = useState(false);
    const [autoPreviewOpen, setAutoPreviewOpen] = useState(false);
    const [autoPreview, setAutoPreview] = useState<AutoRulePreview | null>(
        null,
    );
    const [autoPreviewError, setAutoPreviewError] = useState<string | null>(
        null,
    );
    const [loadingAutoPreview, setLoadingAutoPreview] = useState(false);
    const [previewSection, setPreviewSection] = useState(0);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [pendingMemberRemoval, setPendingMemberRemoval] = useState<{
        id: number;
        name: string;
    } | null>(null);
    const [removing, setRemoving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [mutationError, setMutationError] = useState<string | null>(null);
    const previewRequest = useRef<AbortController | null>(null);
    const mounted = useRef(true);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
            previewRequest.current?.abort();
        };
    }, []);
    const hasAutoRules =
        group.auto_rule_condition_count > 0 && Boolean(group.auto_rules);
    const path = `/security-devices/device-groups/${group.id}`;
    const attention = members.data.filter((device) =>
        ['critical', 'warning'].includes(device.health_status),
    );
    const rows = members.data.filter(
        (device) =>
            (!attentionOnly ||
                ['critical', 'warning'].includes(device.health_status)) &&
            [
                device.name,
                device.device_uid,
                device.category,
                device.assigned_to,
            ]
                .filter(Boolean)
                .join(' ')
                .toLowerCase()
                .includes(search.trim().toLowerCase()),
    );
    const selectMembers = (attention = false) => {
        setView('members');
        setSearch('');
        setAttentionOnly(attention);
    };
    const openAdd = () => {
        setSelectedDevice('');
        setAddError(null);
        setAddOpen(true);
    };
    const closeAdd = () => {
        if (submitting) return;
        if (selectedDevice) setDiscardOpen(true);
        else setAddOpen(false);
    };
    const closePreview = () => {
        if (syncingAuto) return;
        previewRequest.current?.abort();
        setAutoPreviewOpen(false);
        setAutoPreview(null);
        setLoadingAutoPreview(false);
    };
    const reviewAutoRules = async () => {
        previewRequest.current?.abort();
        const request = new AbortController();
        previewRequest.current = request;
        setAutoPreviewOpen(true);
        setLoadingAutoPreview(true);
        setAutoPreview(null);
        setAutoPreviewError(null);
        setPreviewSection(0);
        try {
            const response = await axios.get<AutoRulePreview>(
                `${path}/auto-rules/preview`,
                { signal: request.signal },
            );
            if (mounted.current && !request.signal.aborted)
                setAutoPreview(response.data);
        } catch {
            if (mounted.current && !request.signal.aborted)
                setAutoPreviewError(
                    'The proposed membership could not be loaded. No devices were changed.',
                );
        } finally {
            if (mounted.current && !request.signal.aborted)
                setLoadingAutoPreview(false);
        }
    };
    const syncAutoRules = () => {
        if (!autoPreview || syncingAuto || loadingAutoPreview) return;
        setSyncingAuto(true);
        setAutoPreviewError(null);
        router.post(
            `${path}/auto-rules/sync`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (mounted.current) setAutoPreviewOpen(false);
                },
                onError: () => {
                    if (mounted.current) {
                        setAutoPreview(null);
                        setAutoPreviewError(
                            'Changes could not be confirmed. Refresh the group and review a new preview before trying again.',
                        );
                    }
                },
                onFinish: () => {
                    if (mounted.current) setSyncingAuto(false);
                },
            },
        );
    };
    const submitAdd = () => {
        if (!selectedDevice || submitting) return;
        setSubmitting(true);
        setAddError(null);
        router.post(
            `${path}/members`,
            { device_id: selectedDevice },
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (mounted.current) {
                        setAddOpen(false);
                        setSelectedDevice('');
                    }
                },
                onError: (errors) => {
                    if (mounted.current)
                        setAddError(
                            errors.device_id ||
                                'The device could not be added. Check the refreshed group before trying again.',
                        );
                },
                onFinish: () => {
                    if (mounted.current) setSubmitting(false);
                },
            },
        );
    };
    const removeMember = () => {
        if (!pendingMemberRemoval || removing) return;
        setRemoving(true);
        setMutationError(null);
        router.delete(`${path}/members/${pendingMemberRemoval.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                if (mounted.current) setPendingMemberRemoval(null);
            },
            onError: () => {
                if (mounted.current)
                    setMutationError(
                        'Removal could not be confirmed. Refresh the group before trying again.',
                    );
            },
            onFinish: () => {
                if (mounted.current) setRemoving(false);
            },
        });
    };
    const deleteGroup = () => {
        if (deleting) return;
        setDeleting(true);
        setMutationError(null);
        router.delete(path, {
            onError: () => {
                if (mounted.current)
                    setMutationError(
                        'Deletion could not be confirmed. Refresh the group before trying again.',
                    );
            },
            onFinish: () => {
                if (mounted.current) setDeleting(false);
            },
        });
    };
    const actionsFor = (device: DeviceListItem): MenuItem[] => [
        ...(canPreview
            ? [
                  {
                      label: 'Preview maintenance policy',
                      icon: CalendarClock,
                      onClick: () => {
                          const row = document
                              .querySelector<HTMLAnchorElement>(
                                  'a[href="/security-devices/devices/' +
                                      device.id +
                                      '"]',
                              )
                              ?.closest('article, [role="row"]');
                          rememberOverlayTrigger(
                              maintenanceFocus,
                              row?.querySelector<HTMLElement>(
                                  'button[aria-haspopup="menu"]',
                              ) ?? null,
                          );
                          setMaintenanceMember({
                              id: device.id,
                              identity: maintenanceMemberIdentity(device),
                          });
                      },
                  },
              ]
            : []),
        {
            label: 'Open device',
            icon: Eye,
            onClick: () =>
                router.visit(`/security-devices/devices/${device.id}`),
        },
        {
            label: 'Remove from group',
            icon: Minus,
            danger: true,
            onClick: () => {
                setMutationError(null);
                setPendingMemberRemoval({ id: device.id, name: device.name });
            },
        },
    ];
    const fields: EntityTableColumn<DeviceListItem>[] = [
        {
            key: 'status',
            label: 'Operational status',
            width: '130px',
            cell: (device) => <OperationalStateBadge state={device.status} />,
        },
        {
            key: 'health',
            label: 'Health',
            width: '120px',
            cell: (device) => (
                <OperationalStateBadge state={device.health_status} />
            ),
        },
        {
            key: 'type',
            label: 'Device type',
            width: '180px',
            cell: (device) =>
                `${domainLabel(device.domain)} · ${label(device.category)}`,
        },
        {
            key: 'assignment',
            label: 'Assignment',
            width: '160px',
            cell: (device) =>
                device.assigned_to
                    ? `${label(device.assignment_type)} · ${device.assigned_to}`
                    : 'Unassigned',
        },
        {
            key: 'hardware',
            label: 'Hardware',
            width: '160px',
            cell: (device) =>
                [device.manufacturer, device.model]
                    .filter(Boolean)
                    .join(' · ') || 'Not recorded',
        },
        {
            key: 'seen',
            label: 'Last contact',
            width: '170px',
            cell: (device) =>
                device.last_seen_at
                    ? formatDateTime(device.last_seen_at)
                    : 'No contact recorded',
        },
        {
            key: 'uid',
            label: 'Device identifier',
            width: '150px',
            cell: (device) => device.device_uid,
        },
        {
            key: 'subtype',
            label: 'Device subtype',
            width: '150px',
            cell: (device) => label(device.subcategory),
        },
        {
            key: 'provider',
            label: 'Provider',
            width: '150px',
            cell: (device) => device.provider || 'Not recorded',
        },
        {
            key: 'battery',
            label: 'Battery',
            width: '100px',
            cell: (device) =>
                device.battery_level === null
                    ? 'Not reported'
                    : device.battery_level + '%',
        },
        {
            key: 'monitoring',
            label: 'Monitoring',
            width: '150px',
            cell: (device) =>
                device.monitoring_state
                    ? label(device.monitoring_state)
                    : 'Not supplied',
        },
    ];
    const columns: EntityTableColumn<DeviceListItem>[] = [
        {
            key: 'details',
            label: 'Device details',
            width: '1fr',
            cell: (record) => (
                <div className="space-y-1">
                    {[
                        fields[6],
                        fields[2],
                        fields[7],
                        fields[3],
                        fields[0],
                        fields[1],
                        fields[10],
                        fields[8],
                        fields[4],
                        fields[9],
                        fields[5],
                    ].map((field) => (
                        <ReviewRow
                            key={field.key}
                            label={field.label}
                            value={field.cell(record)}
                        />
                    ))}
                </div>
            ),
        },
    ];
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                {
                    title: 'Device groups',
                    href: '/security-devices/device-groups',
                },
                { title: group.name },
            ]}
        >
            <Head title={`${group.name} - Device groups`} />
            <PageLayout
                width="wide"
                hero={
                    <PageHeader
                        variant="profile"
                        backHref="/security-devices/device-groups"
                        icon={GitBranch}
                        title={group.name}
                        wrapTitle
                        titleChip={
                            <PageHeaderStatusChip variant="neutral">
                                {groupTypeLabel(group.type)}
                            </PageHeaderStatusChip>
                        }
                        subline={group.description || 'No description recorded'}
                        mobileSummary={`${members.meta.total} members · ${group.auto_rule_condition_count} saved rule conditions`}
                        actions={
                            <>
                                <PageHeaderSearch
                                    value={search}
                                    onChange={(value) => {
                                        setSearch(value);
                                        setView('members');
                                    }}
                                    ariaLabel="Search group members on this page"
                                    placeholder="Search current page members…"
                                />
                                <PageHeaderGlassButton
                                    icon={Edit}
                                    onClick={() => {
                                        setEdited(false);
                                        setEditOpen(true);
                                    }}
                                >
                                    Edit group
                                </PageHeaderGlassButton>
                                <PageHeaderGlassButton
                                    icon={Trash2}
                                    onClick={() => {
                                        setMutationError(null);
                                        setDeleteOpen(true);
                                    }}
                                >
                                    Delete group
                                </PageHeaderGlassButton>
                                <PageHeaderPrimaryButton
                                    icon={Plus}
                                    onClick={openAdd}
                                >
                                    Add device
                                </PageHeaderPrimaryButton>
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Members"
                                    ariaLabel="View all group members"
                                    onClick={() => selectMembers()}
                                >
                                    <PageHeaderMeterBig>
                                        {members.meta.total}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Approved members across all pages
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Need attention"
                                    ariaLabel="View members needing attention on this page"
                                    onClick={() => selectMembers(true)}
                                >
                                    <PageHeaderMeterBig>
                                        {attention.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Critical or warning on this page
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Rule conditions"
                                    ariaLabel="View saved automatic membership rule"
                                    onClick={() => setView('rules')}
                                >
                                    <PageHeaderMeterBig>
                                        {group.auto_rule_condition_count}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Review before applying membership
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Available to add"
                                    ariaLabel="Choose an available device"
                                    onClick={openAdd}
                                >
                                    <PageHeaderMeterBig>
                                        {availableDevices.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {availableDevices.length === 200
                                            ? 'First 200 approved candidates'
                                            : 'Approved operational candidates'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={
                            <>
                                {search || attentionOnly ? (
                                    <PageHeaderFilterButton
                                        onClick={() => selectMembers()}
                                    >
                                        Clear member filters
                                    </PageHeaderFilterButton>
                                ) : null}
                                <PageHeaderViewToggle
                                    value={layout}
                                    onChange={setLayout}
                                    options={[
                                        {
                                            value: 'cards',
                                            label: 'Cards',
                                            icon: LayoutGrid,
                                        },
                                        {
                                            value: 'table',
                                            label: 'Table',
                                            icon: List,
                                        },
                                    ]}
                                />
                            </>
                        }
                        rail={
                            <PageHeaderRail<ProfileView>
                                items={[
                                    {
                                        key: 'members',
                                        label: 'Members',
                                        icon: Cpu,
                                        count: members.meta.total,
                                    },
                                    {
                                        key: 'rules',
                                        label: 'Automatic membership',
                                        icon: RefreshCw,
                                        count: group.auto_rule_condition_count,
                                    },
                                    {
                                        key: 'details',
                                        label: 'Group details',
                                        icon: Info,
                                    },
                                ]}
                                value={view}
                                onSelect={setView}
                                ariaLabel="Device group sections"
                            />
                        }
                    />
                }
            >
                {view === 'members' && (
                    <section aria-label="Group members" className="space-y-3">
                        <EvidenceRecords<DeviceListItem>
                            key={JSON.stringify([search, attentionOnly])}
                            title={
                                attentionOnly
                                    ? 'Members needing attention'
                                    : 'Group members'
                            }
                            description={`${rows.length} shown from ${members.data.length} members on this page · ${members.meta.total} approved members across all pages.`}
                            rows={rows}
                            total={members.meta.total}
                            view={layout}
                            rowKey={(device) => device.id}
                            identity={(device) => ({
                                icon: Cpu,
                                name: device.name,
                                subline: device.device_uid,
                            })}
                            hrefFor={(device) =>
                                `/security-devices/devices/${device.id}`
                            }
                            actionsFor={actionsFor}
                            cardFor={(device) => ({
                                meridian: 'neutral',
                                icon: Cpu,
                                name: device.name,
                            })}
                            cardComponent={MemberCard}
                            columns={columns}
                            compactColumns={[fields[0], fields[1], fields[3]]}
                            empty={
                                search || attentionOnly
                                    ? 'No members match the current-page filters.'
                                    : 'No approved members are on this page. Add a device to organise this group.'
                            }
                            icon={Cpu}
                            minWidth={900}
                        />
                        <LaravelPagination
                            links={members.links}
                            lastPage={members.meta.last_page}
                        />
                        <p className="text-caption">
                            Member search and attention filters apply to this
                            page. Automatic rules only change membership after
                            review and apply.
                        </p>
                    </section>
                )}
                {view === 'rules' && (
                    <ReviewCard title="Automatic membership" icon={RefreshCw}>
                        <p className="mb-3 text-sm text-muted-foreground">
                            Saved rules are not applied in the background.
                            Review the proposed membership before applying a
                            change.
                        </p>
                        {hasAutoRules && group.auto_rules ? (
                            <>
                                <p className="mb-3 text-sm font-medium">
                                    Match{' '}
                                    {group.auto_rules.match === 'all'
                                        ? 'all'
                                        : 'any'}{' '}
                                    of these conditions:
                                </p>
                                <ol className="space-y-2">
                                    {group.auto_rules.conditions.map(
                                        (condition, index) => (
                                            <li
                                                key={`${condition.field}-${index}`}
                                                className="rounded-md border p-3 text-sm"
                                            >
                                                <span className="font-medium">
                                                    {ruleFieldLabel(
                                                        condition.field,
                                                    )}
                                                </span>{' '}
                                                {ruleOperatorLabel(
                                                    condition.op,
                                                )}{' '}
                                                <span className="font-medium">
                                                    {Array.isArray(
                                                        condition.value,
                                                    )
                                                        ? condition.value.join(
                                                              ', ',
                                                          )
                                                        : condition.value}
                                                </span>
                                            </li>
                                        ),
                                    )}
                                </ol>
                                <Button
                                    className="mt-4"
                                    onClick={reviewAutoRules}
                                    disabled={loadingAutoPreview || syncingAuto}
                                >
                                    <RefreshCw className="size-4" />
                                    Review proposed membership
                                </Button>
                            </>
                        ) : (
                            <p className="text-sm">
                                No saved automatic rule. Edit the group to
                                configure a rule, then review its proposed
                                membership.
                            </p>
                        )}
                    </ReviewCard>
                )}
                {view === 'details' && (
                    <ReviewCard title="Group details" icon={GitBranch}>
                        <ReviewRow label="Name" value={group.name} />
                        <ReviewRow
                            label="Group type"
                            value={groupTypeLabel(group.type)}
                        />
                        <ReviewRow
                            label="Description"
                            value={
                                group.description || 'No description recorded'
                            }
                        />
                        <ReviewRow
                            label="Created"
                            value={
                                group.created_at
                                    ? formatDateTime(group.created_at)
                                    : 'No time recorded'
                            }
                        />
                        <ReviewRow
                            label="Membership"
                            value={`${members.meta.total} approved members`}
                        />
                    </ReviewCard>
                )}
                <Dialog
                    open={addOpen}
                    onOpenChange={(open) => {
                        if (!open) closeAdd();
                    }}
                >
                    <DialogContent
                        style={{
                            width: 'min(92vw, 480px)',
                            maxWidth: 'min(92vw, 480px)',
                        }}
                        className="max-h-[90vh] overflow-x-hidden overflow-y-auto"
                        onEscapeKeyDown={(event) => {
                            if (submitting) event.preventDefault();
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle>Add device to group</DialogTitle>
                            <DialogDescription>
                                Select an approved operational device for “
                                {group.name}”.
                            </DialogDescription>
                        </DialogHeader>
                        <label
                            htmlFor="group-member-device"
                            className="text-sm font-medium"
                        >
                            Device
                        </label>
                        <Select
                            value={selectedDevice}
                            onValueChange={setSelectedDevice}
                            disabled={submitting}
                        >
                            <SelectTrigger id="group-member-device">
                                <SelectValue placeholder="Select a device…" />
                            </SelectTrigger>
                            <SelectContent>
                                {availableDevices.map((device) => (
                                    <SelectItem
                                        key={device.id}
                                        value={String(device.id)}
                                    >
                                        {device.name} ({device.device_uid}) ·{' '}
                                        {domainLabel(device.domain)} /{' '}
                                        {label(device.category)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {availableDevices.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No approved operational devices are currently
                                available to add.
                            </p>
                        ) : availableDevices.length === 200 ? (
                            <p className="text-caption">
                                Showing the first 200 available devices by name.
                            </p>
                        ) : null}
                        {addError && (
                            <Alert variant="destructive">
                                <AlertTriangle />
                                <AlertDescription>{addError}</AlertDescription>
                            </Alert>
                        )}
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={closeAdd}
                                disabled={submitting}
                            >
                                Cancel
                            </Button>
                            <Button
                                onClick={submitAdd}
                                disabled={submitting || !selectedDevice}
                            >
                                {submitting ? 'Adding…' : 'Add to group'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
                <WizardShell
                    open={autoPreviewOpen}
                    onClose={closePreview}
                    title="Review proposed membership"
                    description="Read-only membership preview. Apply after reviewing additions and removals."
                    railIcon={GitBranch}
                    railTitle="Membership review"
                    railSub={group.name}
                    steps={previewSections}
                    stepIndex={previewSection}
                    onStepClick={setPreviewSection}
                    sequential={false}
                    headerLabel={previewSections[previewSection].label}
                    maxWidth="min(94vw, 1100px)"
                    footerStart={
                        <Button
                            variant="outline"
                            disabled={syncingAuto}
                            onClick={closePreview}
                        >
                            Cancel
                        </Button>
                    }
                    footerEnd={
                        <Button
                            onClick={syncAutoRules}
                            disabled={
                                !autoPreview ||
                                syncingAuto ||
                                loadingAutoPreview
                            }
                        >
                            {syncingAuto
                                ? 'Applying…'
                                : 'Apply membership changes'}
                        </Button>
                    }
                >
                    <WizardStepPane>
                        <p className="mb-4 text-sm text-muted-foreground">
                            The server rechecks approved devices when you apply.
                            Membership may have changed since this preview.
                        </p>
                        {loadingAutoPreview && (
                            <p role="status" className="text-sm">
                                Checking approved devices…
                            </p>
                        )}
                        {autoPreviewError && (
                            <div className="space-y-3">
                                <Alert variant="destructive">
                                    <AlertTriangle />
                                    <AlertDescription>
                                        {autoPreviewError}
                                    </AlertDescription>
                                </Alert>
                                <Button
                                    variant="outline"
                                    onClick={reviewAutoRules}
                                    disabled={syncingAuto}
                                >
                                    Review again
                                </Button>
                            </div>
                        )}
                        {autoPreview &&
                            (previewSection === 0 ? (
                                <ReviewCard
                                    title="Proposed changes"
                                    icon={RefreshCw}
                                >
                                    {[
                                        ['Add', autoPreview.changes.added],
                                        ['Remove', autoPreview.changes.removed],
                                        ['Keep', autoPreview.changes.kept],
                                        [
                                            'Final total',
                                            autoPreview.changes.total,
                                        ],
                                    ].map(([label, count]) => (
                                        <ReviewRow
                                            key={label}
                                            label={String(label)}
                                            value={count}
                                        />
                                    ))}
                                    {autoPreview.changes.removed > 0 && (
                                        <p className="mt-3 text-sm text-status-warning">
                                            Applying will remove{' '}
                                            {autoPreview.changes.removed}{' '}
                                            approved{' '}
                                            {autoPreview.changes.removed === 1
                                                ? 'device'
                                                : 'devices'}{' '}
                                            from this group.
                                        </p>
                                    )}
                                </ReviewCard>
                            ) : (
                                <ReviewCard
                                    title={`Matching devices (${autoPreview.count})`}
                                    icon={Cpu}
                                >
                                    {autoPreview.sample.length ? (
                                        <ul className="space-y-3">
                                            {autoPreview.sample.map(
                                                (device) => (
                                                    <li
                                                        key={device.id}
                                                        className="space-y-1 text-sm"
                                                    >
                                                        <Link
                                                            href={`/security-devices/devices/${device.id}`}
                                                            className="frontline-focus rounded-sm font-medium text-primary hover:underline"
                                                        >
                                                            {device.name}
                                                        </Link>
                                                        <p className="break-words text-muted-foreground">
                                                            {device.device_uid}{' '}
                                                            ·{' '}
                                                            {label(
                                                                device.category,
                                                            )}
                                                        </p>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    ) : (
                                        <p className="text-sm">
                                            No approved devices match this rule.
                                        </p>
                                    )}
                                    {autoPreview.count >
                                        autoPreview.sample.length && (
                                        <p className="text-caption mt-3">
                                            Showing the first{' '}
                                            {autoPreview.sample.length}{' '}
                                            {autoPreview.sample.length === 1
                                                ? 'match'
                                                : 'matches'}
                                            .
                                        </p>
                                    )}
                                </ReviewCard>
                            ))}
                    </WizardStepPane>
                </WizardShell>
                <ConfirmDialog
                    open={discardOpen}
                    onClose={() => setDiscardOpen(false)}
                    onConfirm={() => {
                        setDiscardOpen(false);
                        setSelectedDevice('');
                        setAddError(null);
                        setAddOpen(false);
                    }}
                    title="Discard this selection?"
                    description="The selected device has not been added to the group."
                    confirmText="Discard selection"
                    cancelText="Keep selection"
                />
                <ConfirmDialog
                    open={deleteOpen}
                    onClose={() => {
                        if (!deleting) setDeleteOpen(false);
                    }}
                    onConfirm={deleteGroup}
                    processing={deleting}
                    title="Delete device group?"
                    description={
                        <>
                            <p>
                                Delete “{group.name}”? Its membership and saved
                                automatic rule will no longer be available.
                                Device records are not deleted.
                            </p>
                            {mutationError && (
                                <Alert variant="destructive" className="mt-2">
                                    <AlertTriangle />
                                    <AlertDescription>
                                        {mutationError}
                                    </AlertDescription>
                                </Alert>
                            )}
                        </>
                    }
                    confirmText="Delete group"
                />
                <ConfirmDialog
                    open={pendingMemberRemoval !== null}
                    onClose={() => {
                        if (!removing) setPendingMemberRemoval(null);
                    }}
                    onConfirm={removeMember}
                    processing={removing}
                    title="Remove device from group?"
                    description={
                        <>
                            <p>
                                Remove “{pendingMemberRemoval?.name}” from this
                                group? The device record is not deleted. A
                                future automatic-membership apply may add it
                                again if it still matches the saved rule.
                            </p>
                            {mutationError && (
                                <Alert variant="destructive" className="mt-2">
                                    <AlertTriangle />
                                    <AlertDescription>
                                        {mutationError}
                                    </AlertDescription>
                                </Alert>
                            )}
                        </>
                    }
                    confirmText="Remove device"
                />
                {currentPreviewMember && canPreview && (
                    <MaintenancePolicyPreview
                        group={group}
                        member={currentPreviewMember}
                        actorId={actor_id}
                        sourceActor={sourceActor}
                        asOf={as_of}
                        onClose={() => setMaintenanceMember(null)}
                        onCloseAutoFocus={(event) => {
                            event.preventDefault();
                            restoreOverlayFocus(maintenanceFocus);
                        }}
                    />
                )}
                <GroupWizard
                    open={editOpen}
                    group={group}
                    onSaved={() => setEdited(true)}
                    onClose={() => {
                        setEditOpen(false);
                        if (edited)
                            router.reload({
                                only: ['group', 'members', 'availableDevices'],
                                preserveScroll: true,
                            });
                    }}
                />
            </PageLayout>
        </AppLayout>
    );
}
