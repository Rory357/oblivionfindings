import { ConfirmDialog } from '@/components/confirm-dialog';
import { EntityCard, EntityCardGrid } from '@/components/lists/entity-card';
import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { ListCaption } from '@/components/lists/list-caption';
import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import {
    formatDateTimeInZone,
    timeOffsetInZone,
    timeOffsetsInZone,
    toDatetimeLocal,
} from '@/lib/datetime';
import { router } from '@inertiajs/react';
import axios from 'axios';
import {
    Activity,
    Archive,
    ArrowUpRight,
    BellRing,
    CalendarClock,
    ClipboardCheck,
    Gauge,
    GitBranch,
    Layers,
    LayoutDashboard,
    Pencil,
    Plus,
    ShieldCheck,
} from 'lucide-react';
import type { ReactElement, ReactNode } from 'react';
import {
    Children,
    Fragment,
    cloneElement,
    isValidElement,
    useEffect,
    useId,
    useMemo,
    useRef,
    useState,
} from 'react';
import {
    maintenanceClockPreview,
    type MaintenanceClockPreview,
} from './maintenance-clock-preview';
import type {
    MaintenanceCommandReply,
    MaintenanceCommandScope,
} from './maintenance-command';
import { useMaintenanceCommand } from './use-maintenance-command';

type Option = { value: string; label: string };
type SiteOption = { id: number; name: string };
type DeviceOption = SiteOption & { site_id: number; site_name: string };
type MonitorOption = {
    id: number;
    name: string;
    kind: string;
    device_id: number;
    device_name: string;
    site_id: number;
    site_name: string;
    enabled: boolean;
};
type PolicyBase = {
    id: number;
    version: number;
    state: string;
    is_active?: boolean;
    can_manage?: boolean;
};
export type MonitoringProfilePolicy = PolicyBase & {
    name: string;
    description: string | null;
    interval_seconds: number;
    failure_confirmations: number;
    failure_duration_seconds: number;
    recovery_confirmations: number;
    recovery_duration_seconds: number;
    stale_after_seconds: number;
    threshold_direction?: 'above' | 'below';
    warning_rising_threshold?: number | string | null;
    warning_falling_threshold?: number | string | null;
    rising_threshold: number | string | null;
    falling_threshold: number | string | null;
    baseline_window_seconds: number;
    baseline_minimum_samples: number;
    baseline_deviation_multiplier: number | string | null;
    retention_policy_id: number | null;
    used_by_count: number;
};
export type MonitoringThresholdPolicy = PolicyBase & {
    name: string;
    scope_kind: string;
    scope_name: string;
    site_id: number | null;
    device_id: number | null;
    monitor_id: number | null;
    device_domain: string | null;
    device_category: string | null;
    monitor_kind: string | null;
    metric_key: string;
    threshold_direction: 'above' | 'below';
    warning_rising_threshold: number | string | null;
    warning_falling_threshold: number | string | null;
    critical_rising_threshold: number | string | null;
    critical_falling_threshold: number | string | null;
};
export type MonitoringAlertRoutePolicy = PolicyBase & {
    name: string;
    scope_kind: string;
    scope_name: string;
    site_id: number | null;
    device_id: number | null;
    monitor_id: number | null;
    device_domain: string | null;
    device_category: string | null;
    monitor_kind: string | null;
    warning_notify_in_app: boolean;
    warning_notify_email: boolean;
    warning_open_it_ticket: boolean;
    critical_notify_in_app: boolean;
    critical_notify_email: boolean;
    critical_open_it_ticket: boolean;
    critical_escalate_control_room: boolean;
    recipient_role: string;
};
type CoveragePolicy = PolicyBase & {
    site_id: number | null;
    site_name: string;
    device_domain: string;
    device_category: string | null;
    capability: string;
    monitor_kind: string;
    minimum_count: number;
    support_status: string;
    rationale: string;
};
type DependencyPolicy = PolicyBase & {
    site_id: number;
    site_name: string;
    upstream_monitor_id: number;
    upstream_monitor_name: string;
    downstream_monitor_id: number;
    downstream_monitor_name: string;
    confidence: number | string;
    source: string;
};
type MaintenancePolicy = PolicyBase & {
    site_id: number;
    site_name: string;
    monitor_id: number | null;
    monitor_name: string | null;
    device_id: number | null;
    device_name: string | null;
    name: string;
    starts_at: string;
    ends_at: string;
    recurrence: string | null;
    recurrence_until: string | null;
    timezone: string;
    dst_overlap?: 'reject' | 'earlier' | 'later';
    dst_gap?: 'reject' | 'skip';
    status: string;
    reason: string;
};
type RetentionPolicy = PolicyBase & {
    name: string;
    scope_kind: string;
    scope_name: string;
    site_id: number | null;
    device_id: number | null;
    data_class: string | null;
    privacy_class: string | null;
    raw_days: number;
    hourly_days: number;
    daily_days: number;
    legal_hold: boolean;
};

export type MonitoringPolicyWorkspace = {
    actor_id?: number;
    visible: boolean;
    can_manage: boolean;
    can_manage_application: boolean;
    retention_confirmation: string;
    sites: SiteOption[];
    devices: DeviceOption[];
    monitors: MonitorOption[];
    catalogs: {
        domains: Array<Option & { categories: Option[] }>;
        capabilities: Array<Option & { monitor_kind: string }>;
        monitor_kinds?: Option[];
        metric_keys?: string[];
        data_classes: string[];
        privacy_classes: string[];
        timezones: string[];
    };
    profiles: MonitoringProfilePolicy[];
    thresholds?: MonitoringThresholdPolicy[];
    alert_routes?: MonitoringAlertRoutePolicy[];
    coverage: CoveragePolicy[];
    dependencies: DependencyPolicy[];
    maintenance: MaintenancePolicy[];
    retention: RetentionPolicy[];
};

type EffectivePolicyPreview = {
    monitor: {
        id: number;
        name: string;
        kind: string;
        metric_key: string;
    };
    device: {
        id: number;
        name: string;
        domain: string | null;
        category: string | null;
        site_id: number | null;
    };
    threshold: {
        source_kind: string;
        rule_id: number | null;
        rule_name: string | null;
        metric_key: string;
        direction: 'above' | 'below';
        warning_rising: number | null;
        warning_falling: number | null;
        critical_rising: number | null;
        critical_falling: number | null;
    };
    alert_route: {
        source_kind: string;
        rule_id: number | null;
        rule_name: string | null;
        life_safety_floor: boolean;
        recipient_role: string;
        warning: {
            notify_in_app: boolean;
            notify_email: boolean;
            open_it_ticket: boolean;
        };
        critical: {
            notify_in_app: boolean;
            notify_email: boolean;
            open_it_ticket: boolean;
            escalate_control_room: boolean;
        };
    };
    maintenance: {
        active_window: {
            id: number;
            name: string;
            starts_at: string | null;
            ends_at: string | null;
            timezone: string;
            life_safety_protected: boolean;
        } | null;
        upcoming_window: {
            id: number;
            name: string;
            starts_at: string | null;
            ends_at: string | null;
            timezone: string;
            life_safety_protected: boolean;
        } | null;
    };
    recipient_count: number;
};

type Kind =
    | 'profiles'
    | 'thresholds'
    | 'alert_routes'
    | 'coverage'
    | 'dependencies'
    | 'maintenance'
    | 'retention';
type PolicyRecord =
    | MonitoringProfilePolicy
    | MonitoringThresholdPolicy
    | MonitoringAlertRoutePolicy
    | CoveragePolicy
    | DependencyPolicy
    | MaintenancePolicy
    | RetentionPolicy;
type Preview = {
    metric_series_candidates: number;
    snapshot_candidates: number;
    requires_confirmation: boolean;
    legal_hold_removal: boolean;
    scope_changed: boolean;
};

const labels: Record<Kind, string> = {
    profiles: 'Profile',
    thresholds: 'Threshold rule',
    alert_routes: 'Alert route',
    coverage: 'Coverage expectation',
    dependencies: 'Dependency',
    maintenance: 'Maintenance window',
    retention: 'Retention policy',
};

const endpoints: Record<Kind, string> = {
    profiles: '/security-devices/settings/monitoring/profiles',
    thresholds: '/security-devices/settings/monitoring/thresholds',
    alert_routes: '/security-devices/settings/monitoring/alert-routes',
    coverage: '/security-devices/settings/monitoring/coverage',
    dependencies: '/security-devices/settings/monitoring/dependencies',
    maintenance: '/security-devices/settings/monitoring/maintenance',
    retention: '/security-devices/settings/monitoring/retention',
};

function dateInput(value: unknown, timezone = 'Pacific/Auckland'): string {
    return typeof value === 'string' ? toDatetimeLocal(value, timezone) : '';
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    const generated = useId();
    let inputId: string | undefined;
    const controls = Children.map(children, (child) => {
        if (
            !inputId &&
            isValidElement(child) &&
            [
                Input,
                Textarea,
                SelectField,
                'input',
                'select',
                'textarea',
            ].includes(child.type as typeof Input)
        ) {
            const control = child as ReactElement<{ id?: string }>;
            inputId = control.props.id ?? generated;
            return cloneElement(control, { id: inputId });
        }
        return child;
    });
    return (
        <div className="space-y-1.5">
            <Label htmlFor={inputId}>{label}</Label>
            {controls}
        </div>
    );
}

function SelectField({
    id,
    value,
    onChange,
    children,
    disabled = false,
}: {
    id?: string;
    value: unknown;
    onChange: (value: string) => void;
    children: ReactNode;
    disabled?: boolean;
}) {
    const options = Children.toArray(children).filter(
        isValidElement,
    ) as ReactElement<{
        value?: string | number;
        disabled?: boolean;
        children: ReactNode;
    }>[];
    return (
        <Select
            value={String(value ?? '') || '__empty__'}
            disabled={disabled}
            onValueChange={(next) => onChange(next === '__empty__' ? '' : next)}
        >
            <SelectTrigger id={id} className="w-full">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {options.map((option, index) => (
                    <SelectItem
                        key={String(option.props.value ?? index)}
                        value={String(option.props.value ?? '') || '__empty__'}
                        disabled={option.props.disabled}
                    >
                        {option.props.children}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function summaryParts(node: ReactNode): ReactNode[] {
    if (Array.isArray(node)) return node.flatMap(summaryParts);
    if (
        isValidElement<{ children?: ReactNode }>(node) &&
        node.type === Fragment
    )
        return Children.toArray(node.props.children).flatMap(summaryParts);
    return [node];
}

function RecordCard({
    title,
    summary,
    state,
    canManage,
    onEdit,
    onDeactivate,
    lifecycleLabel = 'Deactivate',
}: {
    title: string;
    summary: ReactNode;
    state: string;
    canManage: boolean;
    onEdit?: () => void;
    onDeactivate?: () => void;
    lifecycleLabel?: string;
}) {
    const context = useEntityContextMenu<string>();
    const parts = summaryParts(summary);
    const breakAt = parts.findIndex(
        (part) => isValidElement(part) && part.type === 'br',
    );
    const subline = breakAt < 0 ? summary : <>{parts.slice(0, breakAt)}</>;
    const facts =
        breakAt < 0
            ? null
            : parts
                  .slice(breakAt + 1)
                  .map((part) =>
                      isValidElement(part) && part.type === 'br' ? ' · ' : part,
                  );
    const actions: MenuItem[] = [];
    if (canManage && onEdit)
        actions.push({ label: 'Edit', icon: Pencil, onClick: onEdit });
    if (canManage && onDeactivate)
        actions.push({
            label: lifecycleLabel,
            onClick: onDeactivate,
            danger: lifecycleLabel !== 'Reactivate',
        });
    return (
        <>
            <EntityCard
                meridian="neutral"
                icon={ShieldCheck}
                name={title}
                subline={subline}
                chips={
                    <>
                        <StatusBadge status={state} />
                        {facts ? (
                            <span className="text-caption">{facts}</span>
                        ) : null}
                    </>
                }
                actions={actions}
                onOpen={canManage ? onEdit : undefined}
                onContextMenu={
                    actions.length
                        ? (event) => context.open(event, title)
                        : undefined
                }
                muted={state !== 'active'}
                footer={{
                    personIcon: ShieldCheck,
                    primary: canManage ? 'Review this rule' : 'Read only',
                    trailing: canManage ? (
                        <div className="flex gap-2">
                            {onEdit ? (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={(event) => {
                                        event.stopPropagation();
                                        onEdit();
                                    }}
                                >
                                    Edit
                                </Button>
                            ) : null}
                            {onDeactivate ? (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={(event) => {
                                        event.stopPropagation();
                                        onDeactivate();
                                    }}
                                >
                                    {lifecycleLabel}
                                </Button>
                            ) : null}
                        </div>
                    ) : undefined,
                }}
            />
            {context.ctx ? (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    title={title}
                    items={actions}
                    onClose={context.close}
                />
            ) : null}
        </>
    );
}
type PolicyView = Kind | 'overview';
type MonitoringSettingsProps = {
    workspace: MonitoringPolicyWorkspace;
    initialView?: PolicyView;
    searchQuery?: string;
    onDraftChange?: (open: boolean) => void;
};

export function MonitoringPolicySettings({
    workspace,
    ...options
}: MonitoringSettingsProps) {
    const identity = JSON.stringify([
        workspace.actor_id ?? 0,
        workspace.visible,
        workspace.can_manage,
        workspace.can_manage_application,
        workspace.sites.map((s) => s.id),
        workspace.devices.map((d) => [d.id, d.site_id]),
        workspace.monitors.map((m) => [m.id, m.device_id, m.site_id]),
    ]);
    return (
        <MonitoringPolicyEditor
            key={identity}
            workspace={workspace}
            {...options}
        />
    );
}

function MonitoringPolicyEditor({
    workspace,
    initialView = 'profiles',
    searchQuery = '',
    onDraftChange,
}: MonitoringSettingsProps) {
    const [editor, setEditor] = useState<{
        kind: Kind;
        record?: PolicyRecord;
    } | null>(null);
    const [policyView, setPolicyView] = useState<PolicyView>(initialView);
    const panelBase = useId();
    const editorBaseline = useRef('');
    const [discardDraft, setDiscardDraft] = useState<
        'editor' | 'lifecycle' | null
    >(null);
    const [form, setForm] = useState<Record<string, unknown>>({});
    const [lifecycle, setLifecycle] = useState<{
        kind: Kind;
        record: PolicyRecord;
    } | null>(null);
    const [reason, setReason] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [replacementProfileId, setReplacementProfileId] = useState('');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [clockPreview, setClockPreview] =
        useState<MaintenanceClockPreview | null>(null);
    const [maintenanceStep, setMaintenanceStep] = useState(0);
    const clockCall = useRef<AbortController | null>(null);
    const clockBody = useRef('');
    const saving = useRef(false);
    const living = useRef(true);
    const [maintenanceUnconfirmed, setMaintenanceUnconfirmed] = useState(false);
    useEffect(() => {
        living.current = true;
        return () => {
            living.current = false;
            clockCall.current?.abort();
        };
    }, []);
    const command = useMaintenanceCommand({
        actorId: workspace.actor_id,
        active: workspace.visible && workspace.can_manage,
        context: JSON.stringify([
            workspace.sites.map((s) => s.id),
            workspace.devices.map((d) => [d.id, d.site_id]),
            workspace.monitors.map((m) => [m.id, m.device_id, m.site_id]),
        ]),
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const thresholds = workspace.thresholds ?? [];
    const alertRoutes = workspace.alert_routes ?? [];
    const metricKeys = workspace.catalogs.metric_keys ?? [
        'value',
        'latency_ms',
        'in_utilization_pct',
        'out_utilization_pct',
        'errors',
        'discards',
    ];
    const monitorKinds =
        workspace.catalogs.monitor_kinds ??
        workspace.catalogs.capabilities.map((cap) => ({
            value: cap.monitor_kind,
            label: cap.label,
        }));
    const [previewMonitorId, setPreviewMonitorId] = useState<string>(() =>
        workspace.monitors[0] ? String(workspace.monitors[0].id) : '',
    );
    const [effectivePreview, setEffectivePreview] =
        useState<EffectivePolicyPreview | null>(null);
    const [previewBusy, setPreviewBusy] = useState(false);
    const [previewError, setPreviewError] = useState('');

    const selectedDomain = workspace.catalogs.domains.find(
        (domain) => domain.value === form.device_domain,
    );
    const siteMonitors = useMemo(
        () =>
            workspace.monitors.filter(
                (monitor) => String(monitor.site_id) === String(form.site_id),
            ),
        [form.site_id, workspace.monitors],
    );
    const siteDevices = useMemo(
        () =>
            workspace.devices.filter(
                (device) => String(device.site_id) === String(form.site_id),
            ),
        [form.site_id, workspace.devices],
    );

    const draftOpen = editor !== null || lifecycle !== null || command.blocked;
    useEffect(() => {
        onDraftChange?.(draftOpen);
    }, [draftOpen, onDraftChange]);
    useEffect(() => () => onDraftChange?.(false), [onDraftChange]);
    if (!workspace.visible)
        return (
            <SettingsNotice>
                You need monitoring permission to review these settings.
            </SettingsNotice>
        );

    function defaults(kind: Kind): Record<string, unknown> {
        const siteId = workspace.sites[0]?.id ?? '';
        if (kind === 'profiles')
            return {
                name: '',
                description: '',
                interval_seconds: 60,
                failure_confirmations: 3,
                failure_duration_seconds: 0,
                recovery_confirmations: 2,
                recovery_duration_seconds: 0,
                stale_after_seconds: 300,
                baseline_window_seconds: 3600,
                baseline_minimum_samples: 10,
                baseline_deviation_multiplier: '',
                threshold_direction: 'above',
                warning_rising_threshold: '',
                warning_falling_threshold: '',
                rising_threshold: '',
                falling_threshold: '',
                retention_policy_id: '',
            };
        if (kind === 'thresholds')
            return {
                name: '',
                scope_kind: workspace.can_manage_application
                    ? 'application'
                    : 'site',
                site_id: workspace.can_manage_application ? '' : siteId,
                device_id: '',
                monitor_id: '',
                device_domain: 'it_infrastructure',
                device_category: '',
                monitor_kind: '',
                metric_key: 'value',
                threshold_direction: 'above',
                warning_rising_threshold: '',
                warning_falling_threshold: '',
                critical_rising_threshold: '',
                critical_falling_threshold: '',
            };
        if (kind === 'alert_routes')
            return {
                name: '',
                scope_kind: workspace.can_manage_application
                    ? 'application'
                    : 'site',
                site_id: workspace.can_manage_application ? '' : siteId,
                device_id: '',
                monitor_id: '',
                device_domain: 'it_infrastructure',
                device_category: '',
                monitor_kind: '',
                warning_notify_in_app: true,
                warning_notify_email: false,
                warning_open_it_ticket: false,
                critical_notify_in_app: true,
                critical_notify_email: true,
                critical_open_it_ticket: true,
                critical_escalate_control_room: false,
                recipient_role: 'it_staff',
            };
        if (kind === 'coverage')
            return {
                site_id: workspace.can_manage_application ? '' : siteId,
                device_domain: workspace.catalogs.domains[0]?.value ?? '',
                device_category: '',
                capability: workspace.catalogs.capabilities[0]?.value ?? '',
                minimum_count: 1,
                support_status: 'supported',
                rationale: '',
            };
        if (kind === 'dependencies')
            return {
                site_id: siteId,
                upstream_monitor_id: '',
                downstream_monitor_id: '',
                confidence: 1,
            };
        if (kind === 'maintenance')
            return {
                site_id: siteId,
                scope_mode: 'site',
                monitor_id: '',
                device_id: '',
                name: '',
                starts_at: '',
                ends_at: '',
                recurrence: '',
                recurrence_until: '',
                timezone: 'Pacific/Auckland',
                dst_overlap: 'reject',
                dst_gap: 'reject',
                reason: '',
            };
        return {
            name: '',
            scope_kind: workspace.can_manage_application
                ? 'application'
                : 'site',
            site_id: workspace.can_manage_application ? '' : siteId,
            device_id: '',
            data_class: '',
            privacy_class: '',
            raw_days: 14,
            hourly_days: 180,
            daily_days: 1825,
            legal_hold: false,
        };
    }

    function openEditor(kind: Kind, record?: PolicyRecord) {
        const values: Record<string, unknown> = record
            ? { ...record }
            : defaults(kind);
        if (kind === 'maintenance') {
            const row = record as MaintenancePolicy | undefined;
            values.scope_mode = row?.monitor_id
                ? 'monitor'
                : row?.device_id
                  ? 'device'
                  : 'site';
            const zone = String(values.timezone ?? 'Pacific/Auckland');
            for (const field of ['starts_at', 'ends_at', 'recurrence_until']) {
                const original = values[field];
                values[field] = dateInput(original, zone);
                values[field + '_offset'] =
                    typeof original === 'string'
                        ? timeOffsetInZone(original, zone)
                        : '';
            }
            values.dst_overlap ??= 'reject';
            values.dst_gap ??= 'reject';
        }
        clockCall.current?.abort();
        clockBody.current = '';
        setClockPreview(null);
        setMaintenanceStep(0);
        setMaintenanceUnconfirmed(false);
        setEditor({ kind, record });
        editorBaseline.current = JSON.stringify(values);
        setForm(values);
        setErrors({});
        setPreview(null);
        setConfirmation('');
        setReason('');
    }

    function update(key: string, value: unknown) {
        if (
            saving.current ||
            (editor?.kind === 'maintenance' && command.blocked)
        )
            return;
        if (editor?.kind === 'maintenance') command.clearErrors();
        clockCall.current?.abort();
        clockBody.current = '';
        setClockPreview(null);
        setMaintenanceStep(0);
        setBusy(false);
        if (
            ['starts_at', 'ends_at', 'recurrence_until', 'timezone'].includes(
                key,
            )
        ) {
            setForm((current) => ({
                ...current,
                starts_at_offset:
                    key === 'starts_at' || key === 'timezone'
                        ? ''
                        : current.starts_at_offset,
                ends_at_offset:
                    key === 'ends_at' || key === 'timezone'
                        ? ''
                        : current.ends_at_offset,
                recurrence_until_offset:
                    key === 'recurrence_until' || key === 'timezone'
                        ? ''
                        : current.recurrence_until_offset,
            }));
        }
        setForm((current) => ({ ...current, [key]: value }));
        setErrors((current) => ({ ...current, [key]: '' }));
        if (editor?.kind === 'retention') setPreview(null);
    }

    function cleanPayload(kind: Kind): Record<string, unknown> {
        const payload = { ...form };
        delete payload.id;
        delete payload.state;
        delete payload.status;
        delete payload.is_active;
        delete payload.can_manage;
        delete payload.used_by_count;
        delete payload.site_name;
        delete payload.scope_name;
        delete payload.monitor_name;
        delete payload.device_name;
        delete payload.upstream_monitor_name;
        delete payload.downstream_monitor_name;
        delete payload.source;
        if (kind === 'coverage') delete payload.monitor_kind;
        if (editor?.record) payload.version = editor.record.version;
        for (const key of Object.keys(payload)) {
            if (payload[key] === '') payload[key] = null;
        }
        if (kind === 'thresholds' || kind === 'alert_routes') {
            const scope = form.scope_kind;
            payload.site_id = scope === 'site' ? form.site_id || null : null;
            payload.device_id =
                scope === 'device' ? form.device_id || null : null;
            payload.monitor_id =
                scope === 'monitor' ? form.monitor_id || null : null;
        }
        if (kind === 'maintenance') {
            const mode = form.scope_mode;
            delete payload.scope_mode;
            payload.monitor_id = mode === 'monitor' ? form.monitor_id : null;
            payload.device_id = mode === 'device' ? form.device_id : null;
            payload.dst_overlap = form.dst_overlap ?? 'reject';
            payload.dst_gap = form.dst_gap ?? 'reject';
            payload.expected_actor_id = workspace.actor_id;
            const zone = String(form.timezone ?? 'Pacific/Auckland');
            for (const field of ['starts_at', 'ends_at', 'recurrence_until']) {
                const local = String(form[field] ?? '');
                const choices = local ? timeOffsetsInZone(local, zone) : [];
                const selected = String(form[field + '_offset'] ?? '');
                delete payload[field + '_offset'];
                const offset =
                    choices.length === 1
                        ? choices[0]
                        : choices.includes(selected)
                          ? selected
                          : '';
                const original = editor?.record as
                    | MaintenancePolicy
                    | undefined;
                const initial =
                    original?.[
                        field as 'starts_at' | 'ends_at' | 'recurrence_until'
                    ];
                payload[field] = !local
                    ? null
                    : original &&
                        zone === original.timezone &&
                        local === dateInput(initial, zone) &&
                        offset ===
                            (typeof initial === 'string'
                                ? timeOffsetInZone(initial, zone)
                                : '')
                      ? initial
                      : offset
                        ? local + ':00' + offset
                        : local;
            }
        }
        if (kind === 'retention') {
            const scope = form.scope_kind;
            payload.site_id = scope === 'site' ? form.site_id : null;
            payload.device_id = scope === 'device' ? form.device_id : null;
            payload.data_class =
                scope === 'data_class' ? form.data_class : null;
            payload.privacy_class =
                scope === 'privacy' ? form.privacy_class : null;
        }
        return payload;
    }

    function captureError(error: unknown) {
        if (axios.isAxiosError(error)) {
            const responseErrors = error.response?.data?.errors;
            if (responseErrors && typeof responseErrors === 'object') {
                setErrors(
                    Object.fromEntries(
                        Object.entries(responseErrors).map(([key, value]) => [
                            key,
                            Array.isArray(value)
                                ? String(value[0])
                                : String(value),
                        ]),
                    ),
                );
                return;
            }
            setErrors({
                form:
                    error.response?.status === 409
                        ? 'This record changed. Refresh and review the latest version.'
                        : String(
                              error.response?.data?.message ??
                                  'The change could not be saved.',
                          ),
            });
        } else {
            setErrors({ form: 'The change could not be saved.' });
        }
    }

    async function previewMaintenance() {
        if (
            !editor ||
            editor.kind !== 'maintenance' ||
            !workspace.actor_id ||
            busy
        )
            return;
        const payload = cleanPayload('maintenance');
        delete payload.version;
        const signature = JSON.stringify(payload);
        clockCall.current?.abort();
        const controller = new AbortController();
        clockCall.current = controller;
        clockBody.current = signature;
        setClockPreview(null);
        setBusy(true);
        setErrors({});
        try {
            const response = await axios.post(
                endpoints.maintenance + '/preview',
                payload,
                { signal: controller.signal },
            );
            if (!controller.signal.aborted && clockBody.current === signature) {
                setClockPreview(
                    maintenanceClockPreview(
                        response.data.preview,
                        workspace.actor_id,
                        payload,
                    ),
                );
                setMaintenanceStep(1);
            }
        } catch (failure) {
            if (!controller.signal.aborted) {
                if (axios.isAxiosError(failure)) captureError(failure);
                else
                    setErrors({
                        form: 'The maintenance time preview could not be verified. Review the dates and try again.',
                    });
            }
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }

    function commandScope(
        values: Record<string, unknown>,
    ): MaintenanceCommandScope {
        const id = (value: unknown) =>
            value === null || value === undefined || value === ''
                ? null
                : Number(value);
        const monitor = id(values.monitor_id);
        return {
            site_id: Number(values.site_id),
            device_id: id(values.device_id),
            monitor_id: monitor,
            monitor_device_id:
                monitor === null
                    ? null
                    : (workspace.monitors.find((row) => row.id === monitor)
                          ?.device_id ?? null),
        };
    }
    function acceptMaintenance(_reply: MaintenanceCommandReply) {
        if (!living.current) return;
        setEditor(null);
        setLifecycle(null);
        setMaintenanceUnconfirmed(false);
        router.reload({
            only: [
                'monitoringPolicyWorkspace',
                'monitoringProfiles',
                'monitoringRetention',
            ],
        });
    }
    async function recoverMaintenance(retry = false) {
        if (busy || saving.current) return;
        setBusy(true);
        saving.current = true;
        try {
            const reply = await (retry ? command.retry() : command.recover());
            if (reply) acceptMaintenance(reply);
        } finally {
            saving.current = false;
            if (living.current) setBusy(false);
        }
    }
    function maintenanceRecovery() {
        if (!command.message && Object.keys(command.errors).length === 0)
            return null;
        return (
            <div
                className="space-y-3 rounded-lg border border-border p-3 text-sm"
                role="status"
            >
                {command.message && <p>{command.message}</p>}
                {Object.values(command.errors).map((message) => (
                    <p key={message} role="alert" className="text-destructive">
                        {message}
                    </p>
                ))}
                {command.pending && (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            className="frontline-tap frontline-focus"
                            disabled={
                                busy || command.busy || !workspace.can_manage
                            }
                            onClick={() => void recoverMaintenance()}
                        >
                            Check saved request
                        </Button>
                        {command.canRetry && (
                            <Button
                                variant="outline"
                                className="frontline-tap frontline-focus"
                                disabled={
                                    busy ||
                                    command.busy ||
                                    !workspace.can_manage
                                }
                                onClick={() => void recoverMaintenance(true)}
                            >
                                Retry same request
                            </Button>
                        )}
                    </div>
                )}
            </div>
        );
    }
    async function submitEditor() {
        if (!editor || busy || saving.current) return;
        const payload = cleanPayload(editor.kind);
        if (editor.kind === 'maintenance') {
            const reviewed = { ...payload };
            delete reviewed.version;
            if (
                !clockPreview ||
                clockBody.current !== JSON.stringify(reviewed) ||
                command.blocked
            )
                return;
            setBusy(true);
            saving.current = true;
            setErrors({});
            try {
                const reply = await command.submit(
                    editor.record ? 'update' : 'create',
                    editor.record?.id ?? null,
                    payload,
                    commandScope(payload),
                );
                if (reply) acceptMaintenance(reply);
            } finally {
                saving.current = false;
                if (living.current) setBusy(false);
            }
            return;
        }
        setBusy(true);
        setErrors({});
        try {
            if (editor.kind === 'retention' && preview === null) {
                const previewPayload = { ...payload };
                delete previewPayload.version;
                const response = await axios.post<{ preview: Preview }>(
                    endpoints.retention + '/preview',
                    { ...previewPayload, policy_id: editor.record?.id ?? null },
                );
                setPreview(response.data.preview);
                return;
            }
            if (editor.kind === 'retention' && preview?.requires_confirmation) {
                payload.confirmation = confirmation;
                payload.reason = reason;
            }
            const url = editor.record
                ? endpoints[editor.kind] + '/' + editor.record.id
                : endpoints[editor.kind];
            saving.current = true;
            if (editor.record) await axios.patch(url, payload);
            else await axios.post(url, payload);
            if (!living.current) return;
            setEditor(null);
            router.reload({
                only: [
                    'monitoringPolicyWorkspace',
                    'monitoringProfiles',
                    'monitoringRetention',
                ],
            });
        } catch (error) {
            if (living.current) captureError(error);
        } finally {
            saving.current = false;
            if (living.current) setBusy(false);
        }
    }

    function openLifecycle(kind: Kind, record: PolicyRecord) {
        setLifecycle({ kind, record });
        setReason('');
        setConfirmation('');
        setReplacementProfileId('');
        setErrors({});
    }

    async function submitLifecycle() {
        if (!lifecycle || busy || saving.current) return;
        if (lifecycle.kind === 'maintenance') {
            if (command.blocked) return;
            const row = lifecycle.record as MaintenancePolicy;
            setBusy(true);
            saving.current = true;
            setErrors({});
            try {
                const reply = await command.submit(
                    'cancel',
                    row.id,
                    { version: row.version, reason },
                    commandScope(row),
                );
                if (reply) acceptMaintenance(reply);
            } finally {
                saving.current = false;
                if (living.current) setBusy(false);
            }
            return;
        }
        setBusy(true);
        setErrors({});
        try {
            const reactivating =
                lifecycle.record.state === 'inactive' &&
                (lifecycle.kind === 'thresholds' ||
                    lifecycle.kind === 'alert_routes' ||
                    lifecycle.kind === 'coverage' ||
                    lifecycle.kind === 'retention');
            const verb = reactivating ? 'reactivate' : 'deactivate';
            await axios.post(
                `${endpoints[lifecycle.kind]}/${lifecycle.record.id}/${verb}`,
                {
                    version: lifecycle.record.version,
                    reason,
                    replacement_profile_id: replacementProfileId || null,
                    confirmation: confirmation || null,
                },
            );
            setLifecycle(null);
            router.reload({
                only: [
                    'monitoringPolicyWorkspace',
                    'monitoringProfiles',
                    'monitoringRetention',
                ],
            });
        } catch (error) {
            captureError(error);
        } finally {
            setBusy(false);
        }
    }

    const canCreate = (kind: Kind) =>
        workspace.can_manage &&
        !busy &&
        (kind !== 'maintenance' ||
            (!!workspace.actor_id && !command.blocked)) &&
        (kind !== 'profiles' || workspace.can_manage_application) &&
        workspace.sites.length > 0;

    function closeDraft(target: 'editor' | 'lifecycle') {
        if (target === 'editor') {
            clockCall.current?.abort();
            clockBody.current = '';
            setClockPreview(null);
            setEditor(null);
            setBusy(false);
        } else {
            setLifecycle(null);
            setReason('');
            setConfirmation('');
            setReplacementProfileId('');
        }
    }
    function requestClose(target: 'editor' | 'lifecycle') {
        if (
            saving.current ||
            ((target === 'editor' ? editor?.kind : lifecycle?.kind) ===
                'maintenance' &&
                command.blocked)
        )
            return;
        const changed =
            target === 'editor'
                ? JSON.stringify(form) !== editorBaseline.current
                : Boolean(
                      reason.trim() || confirmation || replacementProfileId,
                  );
        if (changed) setDiscardDraft(target);
        else closeDraft(target);
    }
    const policyVisible = (row: PolicyRecord) =>
        Object.entries(row)
            .filter(
                ([key, value]) =>
                    [
                        'name',
                        'description',
                        'reason',
                        'rationale',
                        'capability',
                        'site_name',
                        'scope_name',
                        'device_name',
                        'monitor_name',
                        'device_domain',
                        'device_category',
                        'monitor_kind',
                        'metric_key',
                        'recipient_role',
                        'data_class',
                        'privacy_class',
                    ].includes(key) && typeof value === 'string',
            )
            .map(([, value]) => value)
            .join(' ')
            .toLowerCase()
            .includes(searchQuery.trim().toLowerCase());
    async function loadEffectivePreview(monitorId: string) {
        if (!monitorId || previewBusy) return;
        setPreviewBusy(true);
        setPreviewError('');
        try {
            const response = await axios.get<{
                preview: EffectivePolicyPreview;
            }>(
                `/security-devices/settings/monitoring/effective-preview/${monitorId}`,
            );
            if (living.current) {
                setEffectivePreview(response.data.preview);
            }
        } catch (error) {
            if (living.current) {
                setEffectivePreview(null);
                setPreviewError(
                    axios.isAxiosError(error)
                        ? String(
                              error.response?.data?.message ??
                                  'The winning policy preview could not be loaded.',
                          )
                        : 'The winning policy preview could not be loaded.',
                );
            }
        } finally {
            if (living.current) setPreviewBusy(false);
        }
    }
    function policyRecordsForKind(kind: Kind): PolicyRecord[] {
        if (kind === 'thresholds') return thresholds;
        if (kind === 'alert_routes') return alertRoutes;
        return workspace[kind];
    }
    function winningRulePreview() {
        if (workspace.monitors.length === 0) return null;
        return (
            <ReviewCard icon={Gauge} title="Winning policy preview">
                <p className="text-sm text-muted-foreground">
                    Inspect which threshold rule, alert route and maintenance
                    window apply to a specific check using the monitor, device,
                    Site and application precedence order.
                </p>
                <div className="mt-3 flex flex-wrap items-end gap-3">
                    <div className="min-w-64 flex-1">
                        <Field label="Monitor to inspect">
                            <SelectField
                                value={previewMonitorId}
                                onChange={(value) => {
                                    setPreviewMonitorId(value);
                                    setEffectivePreview(null);
                                    setPreviewError('');
                                }}
                            >
                                {workspace.monitors.map((monitor) => (
                                    <option key={monitor.id} value={monitor.id}>
                                        {monitor.site_name} ·{' '}
                                        {monitor.device_name} · {monitor.name}
                                    </option>
                                ))}
                            </SelectField>
                        </Field>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={!previewMonitorId || previewBusy}
                        onClick={() =>
                            void loadEffectivePreview(previewMonitorId)
                        }
                    >
                        {previewBusy
                            ? 'Checking preview…'
                            : 'Preview winning policy'}
                    </Button>
                </div>
                {previewError ? (
                    <p role="alert" className="mt-3 text-sm text-destructive">
                        {previewError}
                    </p>
                ) : null}
                {effectivePreview ? (
                    <div className="mt-4 space-y-2 border-t border-border pt-3">
                        <ReviewRow
                            label="Winning threshold source"
                            value={
                                <span className="inline-flex flex-wrap items-center gap-2">
                                    <StatusBadge variant="info">
                                        {effectivePreview.threshold.source_kind.replaceAll(
                                            '_',
                                            ' ',
                                        )}
                                    </StatusBadge>
                                    <span>
                                        {effectivePreview.threshold.rule_name ??
                                            'Built-in default'}
                                    </span>
                                </span>
                            }
                        />
                        <ReviewRow
                            label="Threshold levels"
                            value={
                                effectivePreview.threshold.warning_rising ===
                                    null &&
                                effectivePreview.threshold.critical_rising ===
                                    null
                                    ? 'Reported check state only'
                                    : `${effectivePreview.threshold.direction === 'below' ? 'Below' : 'Above'} (${effectivePreview.threshold.metric_key.replaceAll('_', ' ')}) · warning ${effectivePreview.threshold.warning_rising ?? 'none'} (clear ${effectivePreview.threshold.warning_falling ?? 'same'}) · critical ${effectivePreview.threshold.critical_rising ?? 'none'} (clear ${effectivePreview.threshold.critical_falling ?? 'same'})`
                            }
                        />
                        <ReviewRow
                            label="Winning alert route"
                            value={
                                <span className="inline-flex flex-wrap items-center gap-2">
                                    <StatusBadge
                                        variant={
                                            effectivePreview.alert_route
                                                .life_safety_floor
                                                ? 'critical'
                                                : 'info'
                                        }
                                    >
                                        {effectivePreview.alert_route.source_kind.replaceAll(
                                            '_',
                                            ' ',
                                        )}
                                    </StatusBadge>
                                    <span>
                                        {effectivePreview.alert_route
                                            .rule_name ??
                                            'Default operational routing'}
                                    </span>
                                </span>
                            }
                        />
                        <ReviewRow
                            label="Warning delivery"
                            value={
                                [
                                    effectivePreview.alert_route.warning
                                        .notify_in_app
                                        ? 'In-app'
                                        : null,
                                    effectivePreview.alert_route.warning
                                        .notify_email
                                        ? 'Email'
                                        : null,
                                    effectivePreview.alert_route.warning
                                        .open_it_ticket
                                        ? 'IT incident ticket'
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ') || 'Recorded on monitor only'
                            }
                        />
                        <ReviewRow
                            label="Critical delivery"
                            value={
                                [
                                    effectivePreview.alert_route.critical
                                        .notify_in_app
                                        ? 'In-app'
                                        : null,
                                    effectivePreview.alert_route.critical
                                        .notify_email
                                        ? 'Email'
                                        : null,
                                    effectivePreview.alert_route.critical
                                        .open_it_ticket
                                        ? 'IT incident ticket'
                                        : null,
                                    effectivePreview.alert_route.critical
                                        .escalate_control_room
                                        ? 'Control Room escalation'
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ') || 'Recorded on monitor only'
                            }
                        />
                        <ReviewRow
                            label="Permitted recipients"
                            value={`${effectivePreview.recipient_count} ${effectivePreview.alert_route.recipient_role.replaceAll('_', ' ')} accounts`}
                        />
                        <ReviewRow
                            label="Maintenance schedule"
                            value={
                                effectivePreview.maintenance.active_window
                                    ? `Active now: ${effectivePreview.maintenance.active_window.name}`
                                    : effectivePreview.maintenance
                                            .upcoming_window
                                      ? `Upcoming: ${effectivePreview.maintenance.upcoming_window.name}`
                                      : 'No active or upcoming maintenance window'
                            }
                        />
                    </div>
                ) : null}
            </ReviewCard>
        );
    }
    const policyTabs = [
        { key: 'overview', label: 'Overview', icon: LayoutDashboard },
        { key: 'profiles', label: 'Profiles', icon: Activity },
        { key: 'thresholds', label: 'Thresholds', icon: Gauge },
        { key: 'alert_routes', label: 'Alert routing', icon: BellRing },
        { key: 'coverage', label: 'Coverage', icon: Layers },
        { key: 'dependencies', label: 'Dependencies', icon: GitBranch },
        { key: 'maintenance', label: 'Maintenance', icon: CalendarClock },
        { key: 'retention', label: 'Retention', icon: Archive },
    ];
    return (
        <section className="space-y-5" aria-label="Monitoring settings">
            <TierTwoTabs
                tabs={policyTabs}
                testIdPrefix={panelBase}
                panelId={panelBase + '-panel'}
                activeTab={policyView}
                onTab={(key) => setPolicyView(key as PolicyView)}
                ariaLabel="Monitoring settings sections"
                renderLink={(tab, className, inner, accessibility) => (
                    <Button
                        key={tab.key}
                        variant="ghost"
                        className={className}
                        {...accessibility}
                        onClick={() => setPolicyView(tab.key as PolicyView)}
                    >
                        {inner}
                    </Button>
                )}
            />
            {policyView === 'overview' ? (
                <section
                    role="tabpanel"
                    id={panelBase + '-panel'}
                    aria-label="Overview"
                    className="space-y-5"
                >
                    <div className="grid gap-5 md:grid-cols-2">
                        {policyTabs
                            .filter((tab) => tab.key !== 'overview')
                            .map((tab) => (
                                <ReviewCard
                                    key={tab.key}
                                    icon={tab.icon}
                                    title={tab.label}
                                >
                                    <ReviewRow
                                        label="Saved rules"
                                        value={
                                            policyRecordsForKind(
                                                tab.key as Kind,
                                            ).length
                                        }
                                    />
                                    <Button
                                        variant="link"
                                        className="px-0"
                                        onClick={() =>
                                            setPolicyView(tab.key as PolicyView)
                                        }
                                    >
                                        Review {tab.label.toLowerCase()}{' '}
                                        <ArrowUpRight className="size-3.5" />
                                    </Button>
                                </ReviewCard>
                            ))}
                    </div>
                    {winningRulePreview()}
                    <SettingsNotice role="note">
                        Each rule has its own review and save. Technical
                        maintenance and custom alert routing never mute or
                        reroute life-safety, security or care alarms.
                    </SettingsNotice>
                </section>
            ) : null}
            <PolicyPanel kind="profiles" view={policyView} base={panelBase}>
                <TabIntro
                    caption={
                        workspace.profiles.filter(policyVisible).length +
                        ' of ' +
                        workspace.profiles.length +
                        ' shown'
                    }
                    title="Profiles"
                    action="Add profile"
                    enabled={canCreate('profiles')}
                    onAction={() => openEditor('profiles')}
                />
                <EntityCardGrid>
                    {workspace.profiles.filter(policyVisible).map((row) => (
                        <RecordCard
                            key={row.id}
                            title={row.name}
                            state={row.state}
                            summary={
                                <>
                                    {row.description || 'No description'}
                                    <br />
                                    Every {row.interval_seconds}s · stale after{' '}
                                    {row.stale_after_seconds}s ·{' '}
                                    {row.used_by_count} monitors
                                </>
                            }
                            canManage={workspace.can_manage_application}
                            onEdit={
                                row.state === 'active'
                                    ? () => openEditor('profiles', row)
                                    : undefined
                            }
                            onDeactivate={
                                row.state === 'active'
                                    ? () => openLifecycle('profiles', row)
                                    : undefined
                            }
                        />
                    ))}
                </EntityCardGrid>
            </PolicyPanel>

            <PolicyPanel kind="thresholds" view={policyView} base={panelBase}>
                <TabIntro
                    caption={
                        thresholds.filter(policyVisible).length +
                        ' of ' +
                        thresholds.length +
                        ' shown'
                    }
                    title="Thresholds"
                    action="Add threshold rule"
                    enabled={canCreate('thresholds')}
                    onAction={() => openEditor('thresholds')}
                />
                <p className="text-sm text-muted-foreground">
                    Override warning and critical numeric thresholds with
                    hysteresis recovery by monitor, device, Site or application
                    scope. More specific scopes win over broader rules.
                </p>
                {winningRulePreview()}
                <EntityCardGrid>
                    {thresholds.filter(policyVisible).map((row) => (
                        <RecordCard
                            key={row.id}
                            title={row.name}
                            state={row.state}
                            summary={
                                <>
                                    {row.scope_kind.replaceAll('_', ' ')} ·{' '}
                                    {row.scope_name} ·{' '}
                                    {row.metric_key.replaceAll('_', ' ')}
                                    <br />
                                    {row.threshold_direction === 'below'
                                        ? 'Below'
                                        : 'Above'}{' '}
                                    · warning{' '}
                                    {row.warning_rising_threshold ?? 'none'} ·
                                    critical{' '}
                                    {row.critical_rising_threshold ?? 'none'}
                                </>
                            }
                            canManage={Boolean(row.can_manage)}
                            onEdit={
                                row.state === 'active'
                                    ? () => openEditor('thresholds', row)
                                    : undefined
                            }
                            onDeactivate={
                                row.state === 'active' ||
                                row.state === 'inactive'
                                    ? () => openLifecycle('thresholds', row)
                                    : undefined
                            }
                            lifecycleLabel={
                                row.state === 'inactive'
                                    ? 'Reactivate'
                                    : 'Deactivate'
                            }
                        />
                    ))}
                </EntityCardGrid>
            </PolicyPanel>

            <PolicyPanel kind="alert_routes" view={policyView} base={panelBase}>
                <TabIntro
                    caption={
                        alertRoutes.filter(policyVisible).length +
                        ' of ' +
                        alertRoutes.length +
                        ' shown'
                    }
                    title="Alert routing"
                    action="Add alert route"
                    enabled={canCreate('alert_routes')}
                    onAction={() => openEditor('alert_routes')}
                />
                <SettingsNotice role="note">
                    Life-safety, security and healthcare alarms stay locked to
                    Control Room and cannot be muted or rerouted here. Until a
                    custom route matches, technical network checks notify IT in
                    app on warnings and open an IT incident ticket with email
                    and in-app notification on critical failures.
                </SettingsNotice>
                {winningRulePreview()}
                <EntityCardGrid>
                    {alertRoutes.filter(policyVisible).map((row) => (
                        <RecordCard
                            key={row.id}
                            title={row.name}
                            state={row.state}
                            summary={
                                <>
                                    {row.scope_kind.replaceAll('_', ' ')} ·{' '}
                                    {row.scope_name} ·{' '}
                                    {row.recipient_role.replaceAll('_', ' ')}
                                    <br />
                                    Warning:{' '}
                                    {[
                                        row.warning_notify_in_app
                                            ? 'in-app'
                                            : null,
                                        row.warning_notify_email
                                            ? 'email'
                                            : null,
                                        row.warning_open_it_ticket
                                            ? 'IT ticket'
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(', ') || 'none'}{' '}
                                    · Critical:{' '}
                                    {[
                                        row.critical_notify_in_app
                                            ? 'in-app'
                                            : null,
                                        row.critical_notify_email
                                            ? 'email'
                                            : null,
                                        row.critical_open_it_ticket
                                            ? 'IT ticket'
                                            : null,
                                        row.critical_escalate_control_room
                                            ? 'Control Room'
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(', ') || 'none'}
                                </>
                            }
                            canManage={Boolean(row.can_manage)}
                            onEdit={
                                row.state === 'active'
                                    ? () => openEditor('alert_routes', row)
                                    : undefined
                            }
                            onDeactivate={
                                row.state === 'active' ||
                                row.state === 'inactive'
                                    ? () => openLifecycle('alert_routes', row)
                                    : undefined
                            }
                            lifecycleLabel={
                                row.state === 'inactive'
                                    ? 'Reactivate'
                                    : 'Deactivate'
                            }
                        />
                    ))}
                </EntityCardGrid>
            </PolicyPanel>

            <PolicyPanel kind="coverage" view={policyView} base={panelBase}>
                <TabIntro
                    caption={
                        workspace.coverage.filter(policyVisible).length +
                        ' of ' +
                        workspace.coverage.length +
                        ' shown'
                    }
                    title="Coverage"
                    action="Add expectation"
                    enabled={canCreate('coverage')}
                    onAction={() => openEditor('coverage')}
                />
                <EntityCardGrid>
                    {workspace.coverage.filter(policyVisible).map((row) => (
                        <RecordCard
                            key={row.id}
                            title={`${row.site_name} · ${row.capability.replaceAll('_', ' ')}`}
                            state={row.state}
                            summary={
                                <>
                                    {row.device_domain.replaceAll('_', ' ')}
                                    {row.device_category
                                        ? ` / ${row.device_category.replaceAll('_', ' ')}`
                                        : ''}{' '}
                                    · minimum {row.minimum_count}
                                    <br />
                                    {row.rationale}
                                </>
                            }
                            canManage={Boolean(row.can_manage)}
                            onEdit={
                                row.state === 'active'
                                    ? () => openEditor('coverage', row)
                                    : undefined
                            }
                            onDeactivate={
                                row.state === 'active' ||
                                row.state === 'inactive'
                                    ? () => openLifecycle('coverage', row)
                                    : undefined
                            }
                            lifecycleLabel={
                                row.state === 'inactive'
                                    ? 'Reactivate'
                                    : 'Deactivate'
                            }
                        />
                    ))}
                </EntityCardGrid>
            </PolicyPanel>

            <PolicyPanel kind="dependencies" view={policyView} base={panelBase}>
                <TabIntro
                    caption={
                        workspace.dependencies.filter(policyVisible).length +
                        ' of ' +
                        workspace.dependencies.length +
                        ' shown'
                    }
                    title="Dependencies"
                    action="Add manual dependency"
                    enabled={canCreate('dependencies')}
                    onAction={() => openEditor('dependencies')}
                />
                <EntityCardGrid>
                    {workspace.dependencies.filter(policyVisible).map((row) => (
                        <RecordCard
                            key={row.id}
                            title={`${row.upstream_monitor_name} → ${row.downstream_monitor_name}`}
                            state={row.state}
                            summary={
                                <>
                                    {row.site_name} · {row.source} evidence ·
                                    confidence{' '}
                                    {Number(row.confidence).toFixed(2)}
                                    <br />
                                    Downstream notifications and ticketing are
                                    suppressed only while the upstream
                                    dependency explains the outage.
                                </>
                            }
                            canManage={Boolean(row.can_manage)}
                            onEdit={
                                row.state === 'active' &&
                                row.source === 'manual'
                                    ? () => openEditor('dependencies', row)
                                    : undefined
                            }
                            onDeactivate={
                                row.state === 'active' &&
                                row.source === 'manual'
                                    ? () => openLifecycle('dependencies', row)
                                    : undefined
                            }
                        />
                    ))}
                </EntityCardGrid>
            </PolicyPanel>

            <PolicyPanel kind="maintenance" view={policyView} base={panelBase}>
                <TabIntro
                    caption={
                        workspace.maintenance.filter(policyVisible).length +
                        ' of ' +
                        workspace.maintenance.length +
                        ' shown'
                    }
                    title="Maintenance"
                    action="Schedule window"
                    enabled={canCreate('maintenance')}
                    onAction={() => openEditor('maintenance')}
                />
                <EntityCardGrid>
                    {workspace.maintenance.filter(policyVisible).map((row) => (
                        <RecordCard
                            key={row.id}
                            title={row.name}
                            state={row.status}
                            summary={
                                <>
                                    {row.site_name}
                                    {row.device_name
                                        ? ` · ${row.device_name}`
                                        : ''}
                                    {row.monitor_name
                                        ? ` · ${row.monitor_name}`
                                        : ''}
                                    <br />
                                    {formatDateTimeInZone(
                                        row.starts_at,
                                        row.timezone,
                                    )}{' '}
                                    –{' '}
                                    {formatDateTimeInZone(
                                        row.ends_at,
                                        row.timezone,
                                    )}{' '}
                                    · {row.timezone}
                                </>
                            }
                            canManage={
                                workspace.can_manage &&
                                !busy &&
                                !command.blocked
                            }
                            onEdit={
                                row.status === 'active'
                                    ? () => openEditor('maintenance', row)
                                    : undefined
                            }
                            onDeactivate={
                                row.status === 'active'
                                    ? () => openLifecycle('maintenance', row)
                                    : undefined
                            }
                            lifecycleLabel="Cancel"
                        />
                    ))}
                </EntityCardGrid>
            </PolicyPanel>

            <PolicyPanel kind="retention" view={policyView} base={panelBase}>
                <TabIntro
                    caption={
                        workspace.retention.filter(policyVisible).length +
                        ' of ' +
                        workspace.retention.length +
                        ' shown'
                    }
                    title="Retention"
                    action="Add retention policy"
                    enabled={canCreate('retention')}
                    onAction={() => openEditor('retention')}
                />
                <p className="text-sm text-muted-foreground">
                    The most restrictive matching policy applies. Legal hold
                    preserves matching evidence.
                </p>
                <EntityCardGrid>
                    {workspace.retention.filter(policyVisible).map((row) => (
                        <RecordCard
                            key={row.id}
                            title={row.name}
                            state={row.state}
                            summary={
                                <>
                                    {row.scope_kind.replaceAll('_', ' ')} ·{' '}
                                    {row.scope_name}
                                    {row.legal_hold ? ' · legal hold' : ''}
                                    <br />
                                    Raw {row.raw_days} days · hourly{' '}
                                    {row.hourly_days} days · daily{' '}
                                    {row.daily_days} days
                                </>
                            }
                            canManage={Boolean(row.can_manage)}
                            onEdit={
                                row.state === 'active'
                                    ? () => openEditor('retention', row)
                                    : undefined
                            }
                            onDeactivate={
                                row.state === 'active' ||
                                row.state === 'inactive'
                                    ? () => openLifecycle('retention', row)
                                    : undefined
                            }
                            lifecycleLabel={
                                row.state === 'inactive'
                                    ? 'Reactivate'
                                    : 'Deactivate'
                            }
                        />
                    ))}
                </EntityCardGrid>
            </PolicyPanel>

            {editor?.kind !== 'maintenance' && lifecycle?.kind !== 'maintenance'
                ? maintenanceRecovery()
                : null}

            <ConfirmDialog
                open={discardDraft !== null}
                onClose={() => setDiscardDraft(null)}
                title="Discard this settings draft?"
                description="Your unsaved changes will be lost. Saved rules and any separately unconfirmed request are kept."
                confirmText="Discard draft"
                cancelText="Keep reviewing"
                variant="destructive"
                onConfirm={() => {
                    const target = discardDraft;
                    setDiscardDraft(null);
                    if (target) closeDraft(target);
                }}
            />
            <Dialog
                open={editor !== null && editor.kind !== 'maintenance'}
                onOpenChange={(open) => !open && requestClose('editor')}
            >
                <DialogContent
                    className="max-h-[90vh] overflow-y-auto"
                    style={{
                        maxWidth: 'min(92vw, 720px)',
                        width: 'min(92vw, 720px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {editor?.record ? 'Edit' : 'Add'}{' '}
                            {editor
                                ? labels[editor.kind].toLowerCase()
                                : 'policy'}
                        </DialogTitle>
                        <DialogDescription>
                            Changes are version checked and recorded in
                            monitoring audit evidence.
                        </DialogDescription>
                    </DialogHeader>
                    {editor ? renderFields(editor.kind) : null}
                    {preview ? (
                        <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-4 text-sm">
                            <strong>Retention impact preview</strong>
                            <p className="mt-1">
                                {preview.metric_series_candidates} metric series
                                and {preview.snapshot_candidates} configuration
                                snapshots currently match the proposed expiry
                                boundary.
                            </p>
                            {preview.requires_confirmation ? (
                                <div className="mt-3 grid gap-3">
                                    <Field
                                        label={`Type “${workspace.retention_confirmation}”`}
                                    >
                                        <Input
                                            value={confirmation}
                                            onChange={(event) =>
                                                setConfirmation(
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Reason for the retention change">
                                        <Textarea
                                            value={reason}
                                            onChange={(event) =>
                                                setReason(event.target.value)
                                            }
                                        />
                                    </Field>
                                </div>
                            ) : null}
                        </div>
                    ) : null}
                    {errors.form ? (
                        <p className="text-sm text-destructive">
                            {errors.form}
                        </p>
                    ) : null}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => requestClose('editor')}
                        >
                            Cancel
                        </Button>
                        <Button
                            disabled={
                                busy ||
                                Boolean(
                                    preview?.requires_confirmation &&
                                    (confirmation !==
                                        workspace.retention_confirmation ||
                                        reason.trim().length < 10),
                                )
                            }
                            onClick={submitEditor}
                        >
                            {busy
                                ? 'Saving…'
                                : editor?.kind === 'retention' &&
                                    preview === null
                                  ? 'Preview impact'
                                  : 'Save policy change'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <WizardShell
                open={editor?.kind === 'maintenance'}
                onClose={() => requestClose('editor')}
                title={
                    editor?.record
                        ? 'Edit maintenance window'
                        : 'Add maintenance window'
                }
                description="Review the approved scope and exact local clock times before saving."
                railIcon={CalendarClock}
                railTitle="Technical maintenance"
                railSub="Monitoring policy"
                steps={[
                    {
                        key: 'plan',
                        label: 'Plan',
                        blurb: 'Scope and local times',
                        icon: CalendarClock,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Exact occurrence preview',
                        icon: ClipboardCheck,
                        disabled: !clockPreview,
                    },
                ]}
                stepIndex={maintenanceStep}
                onStepClick={setMaintenanceStep}
                frontline
                footerStart={
                    <Button
                        variant="outline"
                        className="frontline-tap frontline-focus"
                        disabled={busy}
                        onClick={() =>
                            maintenanceStep
                                ? setMaintenanceStep(0)
                                : requestClose('editor')
                        }
                    >
                        {maintenanceStep ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <Button
                        className="frontline-tap frontline-focus"
                        disabled={
                            busy ||
                            maintenanceUnconfirmed ||
                            command.blocked ||
                            !workspace.actor_id
                        }
                        onClick={() =>
                            void (maintenanceStep
                                ? submitEditor()
                                : previewMaintenance())
                        }
                    >
                        {busy
                            ? maintenanceStep
                                ? 'Saving…'
                                : 'Checking times…'
                            : maintenanceStep
                              ? 'Save maintenance window'
                              : 'Preview times'}
                    </Button>
                }
            >
                <WizardStepPane>
                    {maintenanceStep === 0 ? (
                        <fieldset disabled={saving.current || command.blocked}>
                            {renderFields('maintenance')}
                        </fieldset>
                    ) : clockPreview ? (
                        <div className="space-y-4">
                            <p>
                                Times use {clockPreview.timezone}. This preview
                                has not saved a policy. Saving checks current
                                permission and scope again.
                            </p>
                            <p>
                                {clockPreview.scheduled_occurrences} scheduled
                                occurrences · {clockPreview.skipped_occurrences}{' '}
                                explicitly skipped
                            </p>
                            <ul className="space-y-3">
                                {clockPreview.occurrences.map((row) => (
                                    <li
                                        key={row.local_start}
                                        className="rounded-lg border border-border p-3"
                                    >
                                        <p>
                                            {row.state === 'ready'
                                                ? formatDateTimeInZone(
                                                      row.starts_at,
                                                      row.timezone,
                                                  ) +
                                                  ' – ' +
                                                  formatDateTimeInZone(
                                                      row.ends_at,
                                                      row.timezone,
                                                  )
                                                : row.local_start
                                                      .slice(0, 16)
                                                      .replace('T', ' ') +
                                                  ' · Skipped because this clock time does not occur'}
                                        </p>
                                        {row.state === 'ready' && (
                                            <p className="text-sm text-muted-foreground">
                                                UTC
                                                {timeOffsetInZone(
                                                    row.starts_at!,
                                                    row.timezone,
                                                )}{' '}
                                                – UTC
                                                {timeOffsetInZone(
                                                    row.ends_at!,
                                                    row.timezone,
                                                )}
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                            {clockPreview.truncated && (
                                <p>
                                    The first ten occurrences are shown; the
                                    complete bounded schedule was checked.
                                </p>
                            )}
                            <p>
                                Maintenance affects technical failure alerts. It
                                does not establish equipment health or cancel a
                                real safety event.
                            </p>
                        </div>
                    ) : null}
                    {errors.form && (
                        <p role="alert" className="text-sm text-destructive">
                            {errors.form}
                        </p>
                    )}
                    {Object.keys(errors).some(
                        (key) => key !== 'form' && errors[key],
                    ) && (
                        <div role="alert" className="text-sm text-destructive">
                            <p>
                                Review the fields before previewing or saving:
                            </p>
                            <ul className="list-disc pl-5">
                                {[
                                    ...new Set(
                                        Object.entries(errors)
                                            .filter(
                                                ([key, value]) =>
                                                    key !== 'form' && value,
                                            )
                                            .map(([, value]) => value),
                                    ),
                                ].map((message) => (
                                    <li key={message}>{message}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {maintenanceRecovery()}
                    {!workspace.actor_id && (
                        <p role="alert">
                            Reopen monitoring settings to verify the current
                            account before reviewing maintenance.
                        </p>
                    )}
                </WizardStepPane>
            </WizardShell>

            <Dialog
                open={lifecycle !== null}
                onOpenChange={(open) => !open && requestClose('lifecycle')}
            >
                <DialogContent
                    style={{
                        maxWidth: 'min(92vw, 480px)',
                        width: 'min(92vw, 480px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {lifecycle?.kind === 'maintenance'
                                ? 'Cancel maintenance window'
                                : lifecycle?.record.state === 'inactive'
                                  ? `Reactivate ${labels[lifecycle.kind].toLowerCase()}`
                                  : `Deactivate ${lifecycle ? labels[lifecycle.kind].toLowerCase() : 'policy'}`}
                        </DialogTitle>
                        <DialogDescription>
                            Records are retained. This action changes lifecycle
                            state and requires an operational reason.
                        </DialogDescription>
                    </DialogHeader>
                    <Field label="Operational reason">
                        <Textarea
                            value={reason}
                            disabled={
                                busy ||
                                (lifecycle?.kind === 'maintenance' &&
                                    command.blocked)
                            }
                            onChange={(event) => setReason(event.target.value)}
                        />
                    </Field>
                    {lifecycle?.kind === 'profiles' &&
                    (lifecycle.record as MonitoringProfilePolicy)
                        .used_by_count > 0 ? (
                        <Field label="Replacement profile">
                            <SelectField
                                value={replacementProfileId}
                                onChange={setReplacementProfileId}
                            >
                                <option value="">Choose a replacement</option>
                                {workspace.profiles
                                    .filter(
                                        (row) =>
                                            row.state === 'active' &&
                                            row.id !== lifecycle.record.id,
                                    )
                                    .map((row) => (
                                        <option key={row.id} value={row.id}>
                                            {row.name}
                                        </option>
                                    ))}
                            </SelectField>
                        </Field>
                    ) : null}
                    {lifecycle?.kind === 'retention' &&
                    (lifecycle.record.state === 'inactive' ||
                        (lifecycle.record as RetentionPolicy).legal_hold) ? (
                        <Field
                            label={`Type “${workspace.retention_confirmation}”`}
                        >
                            <Input
                                value={confirmation}
                                onChange={(event) =>
                                    setConfirmation(event.target.value)
                                }
                            />
                        </Field>
                    ) : null}
                    {errors.form ? (
                        <p className="text-sm text-destructive">
                            {errors.form}
                        </p>
                    ) : null}
                    {lifecycle?.kind === 'maintenance'
                        ? maintenanceRecovery()
                        : null}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => requestClose('lifecycle')}
                        >
                            {lifecycle?.record.state === 'inactive'
                                ? 'Keep inactive'
                                : 'Keep active'}
                        </Button>
                        <Button
                            variant={
                                lifecycle?.record.state === 'inactive'
                                    ? 'default'
                                    : 'destructive'
                            }
                            disabled={
                                busy ||
                                (lifecycle?.kind === 'maintenance' &&
                                    command.blocked) ||
                                reason.trim().length < 10 ||
                                Boolean(
                                    lifecycle?.kind === 'retention' &&
                                    (lifecycle.record.state === 'inactive' ||
                                        (lifecycle.record as RetentionPolicy)
                                            .legal_hold) &&
                                    confirmation !==
                                        workspace.retention_confirmation,
                                )
                            }
                            onClick={submitLifecycle}
                        >
                            {busy
                                ? 'Saving…'
                                : lifecycle?.kind === 'maintenance'
                                  ? 'Cancel window'
                                  : lifecycle?.record.state === 'inactive'
                                    ? 'Reactivate'
                                    : 'Deactivate'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );

    function errorFor(key: string) {
        return errors[key] ? (
            <p className="text-xs text-destructive">{errors[key]}</p>
        ) : null;
    }

    function clockOffsetField(field: string, label: string) {
        const choices = timeOffsetsInZone(
            String(form[field] ?? ''),
            String(form.timezone ?? 'Pacific/Auckland'),
        );
        if (choices.length < 2) return null;
        return (
            <Field label={label + ' clock occurrence'}>
                <SelectField
                    value={form[field + '_offset']}
                    onChange={(value) => update(field + '_offset', value)}
                >
                    <option value="">Choose which repeated time</option>
                    {choices.map((offset, i) => (
                        <option key={offset} value={offset}>
                            {i === 0 ? 'First' : 'Second'} ({offset})
                        </option>
                    ))}
                </SelectField>
            </Field>
        );
    }

    function renderFields(kind: Kind) {
        if (kind === 'profiles')
            return (
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Profile name">
                        <Input
                            value={String(form.name ?? '')}
                            onChange={(e) => update('name', e.target.value)}
                        />
                        {errorFor('name')}
                    </Field>
                    <Field label="Monitoring interval (seconds)">
                        <Input
                            type="number"
                            value={String(form.interval_seconds ?? '')}
                            onChange={(e) =>
                                update('interval_seconds', e.target.value)
                            }
                        />
                        {errorFor('interval_seconds')}
                    </Field>
                    <Field label="Description">
                        <Textarea
                            value={String(form.description ?? '')}
                            onChange={(e) =>
                                update('description', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Retention policy">
                        <SelectField
                            value={form.retention_policy_id}
                            onChange={(value) =>
                                update('retention_policy_id', value)
                            }
                        >
                            <option value="">Use matching policy</option>
                            {workspace.retention
                                .filter((row) => row.state === 'active')
                                .map((row) => (
                                    <option key={row.id} value={row.id}>
                                        {row.name}
                                    </option>
                                ))}
                        </SelectField>
                    </Field>
                    <Field label="Failure confirmations">
                        <Input
                            type="number"
                            value={String(form.failure_confirmations ?? '')}
                            onChange={(e) =>
                                update('failure_confirmations', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Failure duration (seconds)">
                        <Input
                            type="number"
                            value={String(form.failure_duration_seconds ?? '')}
                            onChange={(e) =>
                                update(
                                    'failure_duration_seconds',
                                    e.target.value,
                                )
                            }
                        />
                    </Field>
                    <Field label="Recovery confirmations">
                        <Input
                            type="number"
                            value={String(form.recovery_confirmations ?? '')}
                            onChange={(e) =>
                                update('recovery_confirmations', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Recovery duration (seconds)">
                        <Input
                            type="number"
                            value={String(form.recovery_duration_seconds ?? '')}
                            onChange={(e) =>
                                update(
                                    'recovery_duration_seconds',
                                    e.target.value,
                                )
                            }
                        />
                    </Field>
                    <Field label="Stale after (seconds)">
                        <Input
                            type="number"
                            value={String(form.stale_after_seconds ?? '')}
                            onChange={(e) =>
                                update('stale_after_seconds', e.target.value)
                            }
                        />
                        {errorFor('stale_after_seconds')}
                    </Field>
                    <Field label="Baseline window (seconds)">
                        <Input
                            type="number"
                            value={String(form.baseline_window_seconds ?? '')}
                            onChange={(e) =>
                                update(
                                    'baseline_window_seconds',
                                    e.target.value,
                                )
                            }
                        />
                    </Field>
                    <Field label="Baseline minimum samples">
                        <Input
                            type="number"
                            value={String(form.baseline_minimum_samples ?? '')}
                            onChange={(e) =>
                                update(
                                    'baseline_minimum_samples',
                                    e.target.value,
                                )
                            }
                        />
                    </Field>
                    <Field label="Baseline deviation multiplier">
                        <Input
                            type="number"
                            step="0.001"
                            value={String(
                                form.baseline_deviation_multiplier ?? '',
                            )}
                            onChange={(e) =>
                                update(
                                    'baseline_deviation_multiplier',
                                    e.target.value,
                                )
                            }
                        />
                    </Field>
                    <Field label="Threshold direction">
                        <SelectField
                            value={form.threshold_direction ?? 'above'}
                            onChange={(value) =>
                                update('threshold_direction', value)
                            }
                        >
                            <option value="above">
                                Above threshold triggers alert
                            </option>
                            <option value="below">
                                Below threshold triggers alert
                            </option>
                        </SelectField>
                        {errorFor('threshold_direction')}
                    </Field>
                    <Field label="Warning trigger threshold">
                        <Input
                            type="number"
                            step="any"
                            value={String(form.warning_rising_threshold ?? '')}
                            onChange={(e) =>
                                update(
                                    'warning_rising_threshold',
                                    e.target.value,
                                )
                            }
                        />
                        {errorFor('warning_rising_threshold')}
                    </Field>
                    <Field label="Warning clear threshold (hysteresis)">
                        <Input
                            type="number"
                            step="any"
                            value={String(form.warning_falling_threshold ?? '')}
                            onChange={(e) =>
                                update(
                                    'warning_falling_threshold',
                                    e.target.value,
                                )
                            }
                        />
                        {errorFor('warning_falling_threshold')}
                    </Field>
                    <Field label="Rising threshold">
                        <Input
                            type="number"
                            step="any"
                            value={String(form.rising_threshold ?? '')}
                            onChange={(e) =>
                                update('rising_threshold', e.target.value)
                            }
                        />
                        {errorFor('rising_threshold')}
                    </Field>
                    <Field label="Falling threshold">
                        <Input
                            type="number"
                            step="any"
                            value={String(form.falling_threshold ?? '')}
                            onChange={(e) =>
                                update('falling_threshold', e.target.value)
                            }
                        />
                        {errorFor('falling_threshold')}
                    </Field>
                </div>
            );
        if (kind === 'thresholds')
            return (
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Rule name">
                        <Input
                            value={String(form.name ?? '')}
                            onChange={(e) => update('name', e.target.value)}
                        />
                        {errorFor('name')}
                    </Field>
                    <Field label="Scope">
                        <SelectField
                            value={form.scope_kind}
                            onChange={(value) => update('scope_kind', value)}
                        >
                            <option
                                value="application"
                                disabled={!workspace.can_manage_application}
                            >
                                Application
                            </option>
                            <option value="site">Site</option>
                            <option value="device">Device</option>
                            <option value="monitor">Monitor</option>
                        </SelectField>
                        {errorFor('scope_kind')}
                    </Field>
                    {form.scope_kind === 'site' ? (
                        <Field label="Site">
                            <SelectField
                                value={form.site_id}
                                onChange={(value) => update('site_id', value)}
                            >
                                {workspace.sites.map((site) => (
                                    <option key={site.id} value={site.id}>
                                        {site.name}
                                    </option>
                                ))}
                            </SelectField>
                            {errorFor('site_id')}
                        </Field>
                    ) : null}
                    {form.scope_kind === 'device' ? (
                        <Field label="Device">
                            <SelectField
                                value={form.device_id}
                                onChange={(value) => update('device_id', value)}
                            >
                                <option value="">Choose device</option>
                                {workspace.devices.map((row) => (
                                    <option key={row.id} value={row.id}>
                                        {row.site_name} · {row.name}
                                    </option>
                                ))}
                            </SelectField>
                            {errorFor('device_id')}
                        </Field>
                    ) : null}
                    {form.scope_kind === 'monitor' ? (
                        <Field label="Monitor">
                            <SelectField
                                value={form.monitor_id}
                                onChange={(value) =>
                                    update('monitor_id', value)
                                }
                            >
                                <option value="">Choose monitor</option>
                                {workspace.monitors.map((row) => (
                                    <option key={row.id} value={row.id}>
                                        {row.site_name} · {row.device_name} ·{' '}
                                        {row.name}
                                    </option>
                                ))}
                            </SelectField>
                            {errorFor('monitor_id')}
                        </Field>
                    ) : null}
                    <Field label="Device domain filter">
                        <SelectField
                            value={form.device_domain}
                            onChange={(value) => {
                                update('device_domain', value);
                                update('device_category', '');
                            }}
                        >
                            <option value="">All domains</option>
                            {workspace.catalogs.domains.map((row) => (
                                <option key={row.value} value={row.value}>
                                    {row.label}
                                </option>
                            ))}
                        </SelectField>
                    </Field>
                    <Field label="Device category filter">
                        <SelectField
                            value={form.device_category}
                            onChange={(value) =>
                                update('device_category', value)
                            }
                        >
                            <option value="">All categories</option>
                            {selectedDomain?.categories.map((row) => (
                                <option key={row.value} value={row.value}>
                                    {row.label}
                                </option>
                            ))}
                        </SelectField>
                    </Field>
                    <Field label="Check type filter">
                        <SelectField
                            value={form.monitor_kind}
                            onChange={(value) => update('monitor_kind', value)}
                        >
                            <option value="">All check types</option>
                            {monitorKinds.map((row) => (
                                <option key={row.value} value={row.value}>
                                    {row.label}
                                </option>
                            ))}
                        </SelectField>
                    </Field>
                    <Field label="Observed metric">
                        <SelectField
                            value={form.metric_key}
                            onChange={(value) => update('metric_key', value)}
                        >
                            {metricKeys.map((metric) => (
                                <option key={metric} value={metric}>
                                    {metric.replaceAll('_', ' ')}
                                </option>
                            ))}
                        </SelectField>
                        {errorFor('metric_key')}
                    </Field>
                    <Field label="Direction">
                        <SelectField
                            value={form.threshold_direction}
                            onChange={(value) =>
                                update('threshold_direction', value)
                            }
                        >
                            <option value="above">
                                Above threshold triggers alert
                            </option>
                            <option value="below">
                                Below threshold triggers alert
                            </option>
                        </SelectField>
                        {errorFor('threshold_direction')}
                    </Field>
                    <Field label="Warning trigger threshold">
                        <Input
                            type="number"
                            step="any"
                            value={String(form.warning_rising_threshold ?? '')}
                            onChange={(e) =>
                                update(
                                    'warning_rising_threshold',
                                    e.target.value,
                                )
                            }
                        />
                        {errorFor('warning_rising_threshold')}
                    </Field>
                    <Field label="Warning clear threshold (hysteresis)">
                        <Input
                            type="number"
                            step="any"
                            value={String(form.warning_falling_threshold ?? '')}
                            onChange={(e) =>
                                update(
                                    'warning_falling_threshold',
                                    e.target.value,
                                )
                            }
                        />
                        {errorFor('warning_falling_threshold')}
                    </Field>
                    <Field label="Critical trigger threshold">
                        <Input
                            type="number"
                            step="any"
                            value={String(form.critical_rising_threshold ?? '')}
                            onChange={(e) =>
                                update(
                                    'critical_rising_threshold',
                                    e.target.value,
                                )
                            }
                        />
                        {errorFor('critical_rising_threshold')}
                    </Field>
                    <Field label="Critical clear threshold (hysteresis)">
                        <Input
                            type="number"
                            step="any"
                            value={String(
                                form.critical_falling_threshold ?? '',
                            )}
                            onChange={(e) =>
                                update(
                                    'critical_falling_threshold',
                                    e.target.value,
                                )
                            }
                        />
                        {errorFor('critical_falling_threshold')}
                    </Field>
                </div>
            );
        if (kind === 'alert_routes')
            return (
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="sm:col-span-2">
                        <SettingsNotice role="note">
                            Life-safety, security and healthcare checks stay
                            locked to Control Room and cannot be muted or
                            rerouted. Custom routes apply to technical network,
                            facilities and tracking checks.
                        </SettingsNotice>
                    </div>
                    <Field label="Route name">
                        <Input
                            value={String(form.name ?? '')}
                            onChange={(e) => update('name', e.target.value)}
                        />
                        {errorFor('name')}
                    </Field>
                    <Field label="Scope">
                        <SelectField
                            value={form.scope_kind}
                            onChange={(value) => update('scope_kind', value)}
                        >
                            <option
                                value="application"
                                disabled={!workspace.can_manage_application}
                            >
                                Application
                            </option>
                            <option value="site">Site</option>
                            <option value="device">Device</option>
                            <option value="monitor">Monitor</option>
                        </SelectField>
                        {errorFor('scope_kind')}
                    </Field>
                    {form.scope_kind === 'site' ? (
                        <Field label="Site">
                            <SelectField
                                value={form.site_id}
                                onChange={(value) => update('site_id', value)}
                            >
                                {workspace.sites.map((site) => (
                                    <option key={site.id} value={site.id}>
                                        {site.name}
                                    </option>
                                ))}
                            </SelectField>
                            {errorFor('site_id')}
                        </Field>
                    ) : null}
                    {form.scope_kind === 'device' ? (
                        <Field label="Device">
                            <SelectField
                                value={form.device_id}
                                onChange={(value) => update('device_id', value)}
                            >
                                <option value="">Choose device</option>
                                {workspace.devices.map((row) => (
                                    <option key={row.id} value={row.id}>
                                        {row.site_name} · {row.name}
                                    </option>
                                ))}
                            </SelectField>
                            {errorFor('device_id')}
                        </Field>
                    ) : null}
                    {form.scope_kind === 'monitor' ? (
                        <Field label="Monitor">
                            <SelectField
                                value={form.monitor_id}
                                onChange={(value) =>
                                    update('monitor_id', value)
                                }
                            >
                                <option value="">Choose monitor</option>
                                {workspace.monitors.map((row) => (
                                    <option key={row.id} value={row.id}>
                                        {row.site_name} · {row.device_name} ·{' '}
                                        {row.name}
                                    </option>
                                ))}
                            </SelectField>
                            {errorFor('monitor_id')}
                        </Field>
                    ) : null}
                    <Field label="Device domain filter">
                        <SelectField
                            value={form.device_domain}
                            onChange={(value) => {
                                update('device_domain', value);
                                update('device_category', '');
                            }}
                        >
                            <option value="">
                                All non-life-safety domains
                            </option>
                            {workspace.catalogs.domains
                                .filter(
                                    (row) =>
                                        row.value !== 'security' &&
                                        row.value !== 'iot_healthcare',
                                )
                                .map((row) => (
                                    <option key={row.value} value={row.value}>
                                        {row.label}
                                    </option>
                                ))}
                        </SelectField>
                        {errorFor('device_domain')}
                    </Field>
                    <Field label="Device category filter">
                        <SelectField
                            value={form.device_category}
                            onChange={(value) =>
                                update('device_category', value)
                            }
                        >
                            <option value="">All categories</option>
                            {selectedDomain?.categories.map((row) => (
                                <option key={row.value} value={row.value}>
                                    {row.label}
                                </option>
                            ))}
                        </SelectField>
                    </Field>
                    <Field label="Check type filter">
                        <SelectField
                            value={form.monitor_kind}
                            onChange={(value) => update('monitor_kind', value)}
                        >
                            <option value="">All check types</option>
                            {monitorKinds.map((row) => (
                                <option key={row.value} value={row.value}>
                                    {row.label}
                                </option>
                            ))}
                        </SelectField>
                    </Field>
                    <Field label="Recipient group">
                        <SelectField
                            value={form.recipient_role}
                            onChange={(value) =>
                                update('recipient_role', value)
                            }
                        >
                            <option value="it_staff">
                                IT staff for the Site
                            </option>
                            <option value="monitoring_managers">
                                Monitoring managers for the Site
                            </option>
                            <option value="site_managers">
                                Site managers and IT staff
                            </option>
                        </SelectField>
                        {errorFor('recipient_role')}
                    </Field>
                    <div className="space-y-1 sm:col-span-2">
                        <p className="text-sm font-medium text-foreground">
                            Warning state (degraded)
                        </p>
                        {[
                            [
                                'warning_notify_in_app',
                                'Notify recipients in app on warnings',
                            ],
                            [
                                'warning_notify_email',
                                'Send email notification on warnings',
                            ],
                            [
                                'warning_open_it_ticket',
                                'Open or update an IT incident ticket on warnings',
                            ],
                        ].map(([fieldKey, label]) => (
                            <div
                                key={fieldKey}
                                className="flex items-center justify-between gap-4 border-b border-border py-2.5"
                            >
                                <Label htmlFor={`${panelBase}-${fieldKey}`}>
                                    {label}
                                </Label>
                                <div className="flex items-center gap-2">
                                    <span className="text-caption">
                                        {form[fieldKey] ? 'On' : 'Off'}
                                    </span>
                                    <Switch
                                        id={`${panelBase}-${fieldKey}`}
                                        checked={Boolean(form[fieldKey])}
                                        onCheckedChange={(value) =>
                                            update(fieldKey, value)
                                        }
                                    />
                                </div>
                            </div>
                        ))}
                    </div>
                    <div className="space-y-1 sm:col-span-2">
                        <p className="text-sm font-medium text-foreground">
                            Critical state (failed)
                        </p>
                        {[
                            [
                                'critical_notify_in_app',
                                'Notify recipients in app on critical failures',
                            ],
                            [
                                'critical_notify_email',
                                'Send email notification on critical failures',
                            ],
                            [
                                'critical_open_it_ticket',
                                'Open or update an IT incident ticket on critical failures',
                            ],
                            [
                                'critical_escalate_control_room',
                                'Escalate critical failures to Control Room',
                            ],
                        ].map(([fieldKey, label]) => (
                            <div
                                key={fieldKey}
                                className="flex items-center justify-between gap-4 border-b border-border py-2.5"
                            >
                                <Label htmlFor={`${panelBase}-${fieldKey}`}>
                                    {label}
                                </Label>
                                <div className="flex items-center gap-2">
                                    <span className="text-caption">
                                        {form[fieldKey] ? 'On' : 'Off'}
                                    </span>
                                    <Switch
                                        id={`${panelBase}-${fieldKey}`}
                                        checked={Boolean(form[fieldKey])}
                                        onCheckedChange={(value) =>
                                            update(fieldKey, value)
                                        }
                                    />
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            );
        if (kind === 'coverage')
            return (
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Site scope">
                        <SelectField
                            value={form.site_id}
                            onChange={(value) => update('site_id', value)}
                        >
                            <option
                                value=""
                                disabled={!workspace.can_manage_application}
                            >
                                All Sites
                            </option>
                            {workspace.sites.map((site) => (
                                <option key={site.id} value={site.id}>
                                    {site.name}
                                </option>
                            ))}
                        </SelectField>
                    </Field>
                    <Field label="Device domain">
                        <SelectField
                            value={form.device_domain}
                            onChange={(value) => {
                                update('device_domain', value);
                                update('device_category', '');
                            }}
                        >
                            {workspace.catalogs.domains.map((row) => (
                                <option key={row.value} value={row.value}>
                                    {row.label}
                                </option>
                            ))}
                        </SelectField>
                    </Field>
                    <Field label="Device category">
                        <SelectField
                            value={form.device_category}
                            onChange={(value) =>
                                update('device_category', value)
                            }
                        >
                            <option value="">All categories</option>
                            {selectedDomain?.categories.map((row) => (
                                <option key={row.value} value={row.value}>
                                    {row.label}
                                </option>
                            ))}
                        </SelectField>
                    </Field>
                    <Field label="Required capability">
                        <SelectField
                            value={form.capability}
                            onChange={(value) => update('capability', value)}
                        >
                            {workspace.catalogs.capabilities.map((row) => (
                                <option key={row.value} value={row.value}>
                                    {row.label}
                                </option>
                            ))}
                        </SelectField>
                    </Field>
                    <Field label="Minimum monitor count">
                        <Input
                            type="number"
                            value={String(form.minimum_count ?? '')}
                            onChange={(e) =>
                                update('minimum_count', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Support status">
                        <SelectField
                            value={form.support_status}
                            onChange={(value) =>
                                update('support_status', value)
                            }
                        >
                            <option value="supported">Supported</option>
                            <option value="unsupported">
                                Unsupported with evidence
                            </option>
                        </SelectField>
                    </Field>
                    <div className="sm:col-span-2">
                        <Field label="Coverage rationale">
                            <Textarea
                                value={String(form.rationale ?? '')}
                                onChange={(e) =>
                                    update('rationale', e.target.value)
                                }
                            />
                            {errorFor('rationale')}
                        </Field>
                    </div>
                </div>
            );
        if (kind === 'dependencies')
            return (
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Site">
                        <SelectField
                            value={form.site_id}
                            onChange={(value) => {
                                update('site_id', value);
                                update('upstream_monitor_id', '');
                                update('downstream_monitor_id', '');
                            }}
                        >
                            {workspace.sites.map((site) => (
                                <option key={site.id} value={site.id}>
                                    {site.name}
                                </option>
                            ))}
                        </SelectField>
                    </Field>
                    <Field label="Confidence (0–1)">
                        <Input
                            type="number"
                            min="0"
                            max="1"
                            step="0.01"
                            value={String(form.confidence ?? '')}
                            onChange={(e) =>
                                update('confidence', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Upstream monitor">
                        <SelectField
                            value={form.upstream_monitor_id}
                            onChange={(value) =>
                                update('upstream_monitor_id', value)
                            }
                        >
                            <option value="">Choose upstream monitor</option>
                            {siteMonitors.map((row) => (
                                <option key={row.id} value={row.id}>
                                    {row.device_name} · {row.name}
                                </option>
                            ))}
                        </SelectField>
                    </Field>
                    <Field label="Downstream monitor">
                        <SelectField
                            value={form.downstream_monitor_id}
                            onChange={(value) =>
                                update('downstream_monitor_id', value)
                            }
                        >
                            <option value="">Choose downstream monitor</option>
                            {siteMonitors.map((row) => (
                                <option key={row.id} value={row.id}>
                                    {row.device_name} · {row.name}
                                </option>
                            ))}
                        </SelectField>
                    </Field>
                </div>
            );
        if (kind === 'maintenance')
            return (
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Site">
                        <SelectField
                            value={form.site_id}
                            onChange={(value) => update('site_id', value)}
                        >
                            {workspace.sites.map((site) => (
                                <option key={site.id} value={site.id}>
                                    {site.name}
                                </option>
                            ))}
                        </SelectField>
                    </Field>
                    <Field label="Scope">
                        <SelectField
                            value={form.scope_mode}
                            onChange={(value) => update('scope_mode', value)}
                        >
                            <option value="site">Whole Site</option>
                            <option value="device">One device</option>
                            <option value="monitor">One monitor</option>
                        </SelectField>
                    </Field>
                    {form.scope_mode === 'device' ? (
                        <Field label="Device">
                            <SelectField
                                value={form.device_id}
                                onChange={(value) => update('device_id', value)}
                            >
                                <option value="">Choose device</option>
                                {siteDevices.map((row) => (
                                    <option key={row.id} value={row.id}>
                                        {row.name}
                                    </option>
                                ))}
                            </SelectField>
                        </Field>
                    ) : null}
                    {form.scope_mode === 'monitor' ? (
                        <Field label="Monitor">
                            <SelectField
                                value={form.monitor_id}
                                onChange={(value) =>
                                    update('monitor_id', value)
                                }
                            >
                                <option value="">Choose monitor</option>
                                {siteMonitors.map((row) => (
                                    <option key={row.id} value={row.id}>
                                        {row.device_name} · {row.name}
                                    </option>
                                ))}
                            </SelectField>
                        </Field>
                    ) : null}
                    <Field label="Window name">
                        <Input
                            value={String(form.name ?? '')}
                            onChange={(e) => update('name', e.target.value)}
                        />
                    </Field>
                    <Field label="Timezone">
                        <SelectField
                            value={form.timezone}
                            onChange={(value) => update('timezone', value)}
                        >
                            {workspace.catalogs.timezones.map((timezone) => (
                                <option key={timezone} value={timezone}>
                                    {timezone}
                                </option>
                            ))}
                        </SelectField>
                    </Field>
                    <Field label="Starts">
                        <Input
                            type="datetime-local"
                            value={String(form.starts_at ?? '')}
                            onChange={(e) =>
                                update('starts_at', e.target.value)
                            }
                        />
                        {clockOffsetField('starts_at', 'Starts')}
                        {errorFor('starts_at')}
                    </Field>
                    <Field label="Ends">
                        <Input
                            type="datetime-local"
                            value={String(form.ends_at ?? '')}
                            onChange={(e) => update('ends_at', e.target.value)}
                        />
                        {clockOffsetField('ends_at', 'Ends')}
                        {errorFor('ends_at')}
                    </Field>
                    <Field label="Repeated local hour">
                        <SelectField
                            value={form.dst_overlap}
                            onChange={(v) => update('dst_overlap', v)}
                        >
                            <option value="reject">
                                Require a schedule change
                            </option>
                            <option value="earlier">
                                Use the first occurrence
                            </option>
                            <option value="later">
                                Use the second occurrence
                            </option>
                        </SelectField>
                        {errorFor('dst_overlap')}
                    </Field>
                    <Field label="Skipped local hour">
                        <SelectField
                            value={form.dst_gap}
                            onChange={(v) => update('dst_gap', v)}
                        >
                            <option value="reject">
                                Require a schedule change
                            </option>
                            <option value="skip">
                                Skip that maintenance occurrence
                            </option>
                        </SelectField>
                        {errorFor('dst_gap')}
                    </Field>
                    <Field label="Recurrence">
                        <SelectField
                            value={form.recurrence}
                            onChange={(value) => update('recurrence', value)}
                        >
                            <option value="">One-off</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                        </SelectField>
                    </Field>
                    {form.recurrence ? (
                        <Field label="Recurrence ends">
                            <Input
                                type="datetime-local"
                                value={String(form.recurrence_until ?? '')}
                                onChange={(e) =>
                                    update('recurrence_until', e.target.value)
                                }
                            />
                            {clockOffsetField(
                                'recurrence_until',
                                'Recurrence ends',
                            )}
                            {errorFor('recurrence_until')}
                        </Field>
                    ) : null}
                    <div className="sm:col-span-2">
                        <Field label="Maintenance reason">
                            <Textarea
                                value={String(form.reason ?? '')}
                                onChange={(e) =>
                                    update('reason', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                </div>
            );
        return (
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Policy name">
                    <Input
                        value={String(form.name ?? '')}
                        onChange={(e) => update('name', e.target.value)}
                    />
                </Field>
                <Field label="Scope">
                    <SelectField
                        value={form.scope_kind}
                        onChange={(value) => update('scope_kind', value)}
                    >
                        <option
                            value="application"
                            disabled={!workspace.can_manage_application}
                        >
                            Application
                        </option>
                        <option value="site">Site</option>
                        <option value="device">Device</option>
                        <option
                            value="data_class"
                            disabled={!workspace.can_manage_application}
                        >
                            Data class
                        </option>
                        <option
                            value="privacy"
                            disabled={!workspace.can_manage_application}
                        >
                            Privacy class
                        </option>
                    </SelectField>
                </Field>
                {form.scope_kind === 'site' ? (
                    <Field label="Site">
                        <SelectField
                            value={form.site_id}
                            onChange={(value) => update('site_id', value)}
                        >
                            {workspace.sites.map((site) => (
                                <option key={site.id} value={site.id}>
                                    {site.name}
                                </option>
                            ))}
                        </SelectField>
                    </Field>
                ) : null}
                {form.scope_kind === 'device' ? (
                    <Field label="Device">
                        <SelectField
                            value={form.device_id}
                            onChange={(value) => update('device_id', value)}
                        >
                            <option value="">Choose device</option>
                            {workspace.devices.map((row) => (
                                <option key={row.id} value={row.id}>
                                    {row.site_name} · {row.name}
                                </option>
                            ))}
                        </SelectField>
                    </Field>
                ) : null}
                {form.scope_kind === 'data_class' ? (
                    <Field label="Data class">
                        <SelectField
                            value={form.data_class}
                            onChange={(value) => update('data_class', value)}
                        >
                            {workspace.catalogs.data_classes.map((value) => (
                                <option key={value} value={value}>
                                    {value.replaceAll('_', ' ')}
                                </option>
                            ))}
                        </SelectField>
                    </Field>
                ) : null}
                {form.scope_kind === 'privacy' ? (
                    <Field label="Privacy class">
                        <SelectField
                            value={form.privacy_class}
                            onChange={(value) => update('privacy_class', value)}
                        >
                            {workspace.catalogs.privacy_classes.map((value) => (
                                <option key={value} value={value}>
                                    {value}
                                </option>
                            ))}
                        </SelectField>
                    </Field>
                ) : null}
                <Field label="Raw retention days">
                    <Input
                        type="number"
                        value={String(form.raw_days ?? '')}
                        onChange={(e) => update('raw_days', e.target.value)}
                    />
                </Field>
                <Field label="Hourly roll-up days">
                    <Input
                        type="number"
                        value={String(form.hourly_days ?? '')}
                        onChange={(e) => update('hourly_days', e.target.value)}
                    />
                </Field>
                <Field label="Daily roll-up days">
                    <Input
                        type="number"
                        value={String(form.daily_days ?? '')}
                        onChange={(e) => update('daily_days', e.target.value)}
                    />
                </Field>
                <div className="flex items-center justify-between gap-4 border-b border-border py-3">
                    <Label htmlFor={panelBase + '-legal-hold'}>
                        Preserve matching evidence under legal hold
                    </Label>
                    <div className="flex items-center gap-2">
                        <span className="text-caption">
                            {form.legal_hold ? 'On' : 'Off'}
                        </span>
                        <Switch
                            id={panelBase + '-legal-hold'}
                            checked={Boolean(form.legal_hold)}
                            onCheckedChange={(value) =>
                                update('legal_hold', value)
                            }
                        />
                    </div>
                </div>
            </div>
        );
    }
}

function TabIntro({
    title,
    caption,
    action,
    enabled,
    onAction,
}: {
    title: string;
    caption?: string;
    action: string;
    enabled: boolean;
    onAction: () => void;
}) {
    return (
        <ListCaption
            title={title}
            caption={caption}
            right={
                enabled ? (
                    <Button size="sm" onClick={onAction}>
                        <Plus />
                        {action}
                    </Button>
                ) : undefined
            }
        />
    );
}

function PolicyPanel({
    kind,
    view,
    base,
    children,
}: {
    kind: Kind;
    view: PolicyView;
    base: string;
    children: ReactNode;
}) {
    return view === kind ? (
        <section
            role="tabpanel"
            id={base + '-panel'}
            aria-label={labels[kind]}
            className="space-y-5"
        >
            {children}
        </section>
    ) : null;
}
