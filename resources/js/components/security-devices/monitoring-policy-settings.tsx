import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
    TabsContent,
    TabsList,
    TabsRoot,
    TabsTrigger,
} from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Pencil, Plus, ShieldCheck } from 'lucide-react';
import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';

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
    rising_threshold: number | string | null;
    falling_threshold: number | string | null;
    baseline_window_seconds: number;
    baseline_minimum_samples: number;
    baseline_deviation_multiplier: number | string | null;
    retention_policy_id: number | null;
    used_by_count: number;
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
        data_classes: string[];
        privacy_classes: string[];
        timezones: string[];
    };
    profiles: MonitoringProfilePolicy[];
    coverage: CoveragePolicy[];
    dependencies: DependencyPolicy[];
    maintenance: MaintenancePolicy[];
    retention: RetentionPolicy[];
};

type Kind =
    | 'profiles'
    | 'coverage'
    | 'dependencies'
    | 'maintenance'
    | 'retention';
type PolicyRecord =
    | MonitoringProfilePolicy
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
    coverage: 'Coverage expectation',
    dependencies: 'Dependency',
    maintenance: 'Maintenance window',
    retention: 'Retention policy',
};

const endpoints: Record<Kind, string> = {
    profiles: '/security-devices/settings/monitoring/profiles',
    coverage: '/security-devices/settings/monitoring/coverage',
    dependencies: '/security-devices/settings/monitoring/dependencies',
    maintenance: '/security-devices/settings/monitoring/maintenance',
    retention: '/security-devices/settings/monitoring/retention',
};

function dateInput(value: unknown): string {
    return typeof value === 'string' ? value.slice(0, 16) : '';
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="space-y-1.5">
            <Label>{label}</Label>
            {children}
        </div>
    );
}

function SelectField({
    value,
    onChange,
    children,
    disabled = false,
}: {
    value: unknown;
    onChange: (value: string) => void;
    children: ReactNode;
    disabled?: boolean;
}) {
    return (
        <select
            className="h-9 w-full rounded-md border bg-background px-3 text-sm"
            value={String(value ?? '')}
            disabled={disabled}
            onChange={(event) => onChange(event.target.value)}
        >
            {children}
        </select>
    );
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
    return (
        <article className="rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <strong>{title}</strong>
                        <Badge variant="outline">
                            {state.replaceAll('_', ' ')}
                        </Badge>
                    </div>
                    <div className="mt-2 text-sm text-muted-foreground">
                        {summary}
                    </div>
                </div>
                {canManage ? (
                    <div className="flex gap-2">
                        {onEdit ? (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={onEdit}
                            >
                                <Pencil /> Edit
                            </Button>
                        ) : null}
                        {onDeactivate ? (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={onDeactivate}
                            >
                                {lifecycleLabel}
                            </Button>
                        ) : null}
                    </div>
                ) : null}
            </div>
        </article>
    );
}

export function MonitoringPolicySettings({
    workspace,
}: {
    workspace: MonitoringPolicyWorkspace;
}) {
    const [editor, setEditor] = useState<{
        kind: Kind;
        record?: PolicyRecord;
    } | null>(null);
    const [form, setForm] = useState<Record<string, unknown>>({});
    const [lifecycle, setLifecycle] = useState<{
        kind: Kind;
        record: PolicyRecord;
    } | null>(null);
    const [reason, setReason] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [replacementProfileId, setReplacementProfileId] = useState('');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

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

    if (!workspace.visible) return null;

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
                rising_threshold: '',
                falling_threshold: '',
                retention_policy_id: '',
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
            values.starts_at = dateInput(values.starts_at);
            values.ends_at = dateInput(values.ends_at);
            values.recurrence_until = dateInput(values.recurrence_until);
        }
        setEditor({ kind, record });
        setForm(values);
        setErrors({});
        setPreview(null);
        setConfirmation('');
        setReason('');
    }

    function update(key: string, value: unknown) {
        setForm((current) => ({ ...current, [key]: value }));
        setErrors((current) => ({ ...current, [key]: '' }));
        if (editor?.kind === 'retention') setPreview(null);
    }

    function cleanPayload(kind: Kind): Record<string, unknown> {
        const payload = { ...form };
        delete payload.id;
        delete payload.state;
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
        delete payload.monitor_kind;
        if (editor?.record) payload.version = editor.record.version;
        for (const key of Object.keys(payload)) {
            if (payload[key] === '') payload[key] = null;
        }
        if (kind === 'maintenance') {
            const mode = form.scope_mode;
            delete payload.scope_mode;
            payload.monitor_id = mode === 'monitor' ? form.monitor_id : null;
            payload.device_id = mode === 'device' ? form.device_id : null;
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

    async function submitEditor() {
        if (!editor) return;
        setBusy(true);
        setErrors({});
        const payload = cleanPayload(editor.kind);
        try {
            if (editor.kind === 'retention' && preview === null) {
                const previewPayload = { ...payload };
                delete previewPayload.version;
                const response = await axios.post<{ preview: Preview }>(
                    `${endpoints.retention}/preview`,
                    {
                        ...previewPayload,
                        policy_id: editor.record?.id ?? null,
                    },
                );
                setPreview(response.data.preview);
                setBusy(false);
                return;
            }
            if (editor.kind === 'retention' && preview?.requires_confirmation) {
                payload.confirmation = confirmation;
                payload.reason = reason;
            }
            const url = editor.record
                ? `${endpoints[editor.kind]}/${editor.record.id}`
                : endpoints[editor.kind];
            if (editor.record) await axios.patch(url, payload);
            else await axios.post(url, payload);
            setEditor(null);
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

    function openLifecycle(kind: Kind, record: PolicyRecord) {
        setLifecycle({ kind, record });
        setReason('');
        setConfirmation('');
        setReplacementProfileId('');
        setErrors({});
    }

    async function submitLifecycle() {
        if (!lifecycle) return;
        setBusy(true);
        setErrors({});
        try {
            const reactivating =
                lifecycle.record.state === 'inactive' &&
                (lifecycle.kind === 'coverage' ||
                    lifecycle.kind === 'retention');
            const verb =
                lifecycle.kind === 'maintenance'
                    ? 'cancel'
                    : reactivating
                      ? 'reactivate'
                      : 'deactivate';
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
        (kind !== 'profiles' || workspace.can_manage_application) &&
        workspace.sites.length > 0;

    return (
        <Card>
            <CardHeader className="gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <CardTitle className="flex items-center gap-2">
                        <ShieldCheck className="h-5 w-5" />
                        Monitoring policy
                    </CardTitle>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Govern detection, coverage, dependency suppression,
                        planned maintenance, and evidence retention from one
                        auditable workspace.
                    </p>
                </div>
                {!workspace.can_manage_application ? (
                    <Badge variant="outline">Accessible Sites only</Badge>
                ) : (
                    <Badge variant="outline">Application-wide manager</Badge>
                )}
            </CardHeader>
            <CardContent>
                <TabsRoot defaultValue="profiles">
                    <TabsList className="grid h-auto w-full grid-cols-5">
                        {(
                            [
                                'profiles',
                                'coverage',
                                'dependencies',
                                'maintenance',
                                'retention',
                            ] as Kind[]
                        ).map((kind) => (
                            <TabsTrigger
                                key={kind}
                                value={kind}
                                className="min-h-10"
                            >
                                {kind[0].toUpperCase() + kind.slice(1)}
                            </TabsTrigger>
                        ))}
                    </TabsList>

                    <TabsContent value="profiles" className="space-y-3 pt-4">
                        <TabIntro
                            title="Profiles"
                            action="New profile"
                            enabled={canCreate('profiles')}
                            onAction={() => openEditor('profiles')}
                        />
                        {workspace.profiles.map((row) => (
                            <RecordCard
                                key={row.id}
                                title={row.name}
                                state={row.state}
                                summary={
                                    <>
                                        {row.description || 'No description'}
                                        <br />
                                        Every {row.interval_seconds}s · stale
                                        after {row.stale_after_seconds}s ·{' '}
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
                    </TabsContent>

                    <TabsContent value="coverage" className="space-y-3 pt-4">
                        <TabIntro
                            title="Coverage"
                            action="New expectation"
                            enabled={canCreate('coverage')}
                            onAction={() => openEditor('coverage')}
                        />
                        {workspace.coverage.map((row) => (
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
                    </TabsContent>

                    <TabsContent
                        value="dependencies"
                        className="space-y-3 pt-4"
                    >
                        <TabIntro
                            title="Dependencies"
                            action="New manual dependency"
                            enabled={canCreate('dependencies')}
                            onAction={() => openEditor('dependencies')}
                        />
                        {workspace.dependencies.map((row) => (
                            <RecordCard
                                key={row.id}
                                title={`${row.upstream_monitor_name} → ${row.downstream_monitor_name}`}
                                state={row.state}
                                summary={
                                    <>
                                        {row.site_name} · {row.source} evidence
                                        · confidence{' '}
                                        {Number(row.confidence).toFixed(2)}
                                        <br />
                                        Downstream notifications and ticketing
                                        are suppressed only while the upstream
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
                                        ? () =>
                                              openLifecycle('dependencies', row)
                                        : undefined
                                }
                            />
                        ))}
                    </TabsContent>

                    <TabsContent value="maintenance" className="space-y-3 pt-4">
                        <TabIntro
                            title="Maintenance"
                            action="Schedule window"
                            enabled={canCreate('maintenance')}
                            onAction={() => openEditor('maintenance')}
                        />
                        {workspace.maintenance.map((row) => (
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
                                        {new Date(
                                            row.starts_at,
                                        ).toLocaleString()}{' '}
                                        –{' '}
                                        {new Date(row.ends_at).toLocaleString()}{' '}
                                        · {row.timezone}
                                    </>
                                }
                                canManage={workspace.can_manage}
                                onEdit={
                                    row.status === 'active'
                                        ? () => openEditor('maintenance', row)
                                        : undefined
                                }
                                onDeactivate={
                                    row.status === 'active'
                                        ? () =>
                                              openLifecycle('maintenance', row)
                                        : undefined
                                }
                                lifecycleLabel="Cancel"
                            />
                        ))}
                    </TabsContent>

                    <TabsContent value="retention" className="space-y-3 pt-4">
                        <TabIntro
                            title="Retention"
                            action="New retention policy"
                            enabled={canCreate('retention')}
                            onAction={() => openEditor('retention')}
                        />
                        <p className="text-sm text-muted-foreground">
                            The most restrictive matching policy applies. Legal
                            hold preserves matching evidence.
                        </p>
                        {workspace.retention.map((row) => (
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
                    </TabsContent>
                </TabsRoot>
            </CardContent>

            <Dialog
                open={editor !== null}
                onOpenChange={(open) => !open && setEditor(null)}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>
                            {editor?.record ? 'Edit' : 'Create'}{' '}
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
                            onClick={() => setEditor(null)}
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
                                  : 'Save governed change'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={lifecycle !== null}
                onOpenChange={(open) => !open && setLifecycle(null)}
            >
                <DialogContent>
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
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setLifecycle(null)}
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
        </Card>
    );

    function errorFor(key: string) {
        return errors[key] ? (
            <p className="text-xs text-destructive">{errors[key]}</p>
        ) : null;
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
                    <Field label="Rising threshold">
                        <Input
                            type="number"
                            step="any"
                            value={String(form.rising_threshold ?? '')}
                            onChange={(e) =>
                                update('rising_threshold', e.target.value)
                            }
                        />
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
                    </Field>
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
                    </Field>
                    <Field label="Ends">
                        <Input
                            type="datetime-local"
                            value={String(form.ends_at ?? '')}
                            onChange={(e) => update('ends_at', e.target.value)}
                        />
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
                <label className="flex items-center gap-2 self-end rounded-md border p-3 text-sm">
                    <input
                        type="checkbox"
                        checked={Boolean(form.legal_hold)}
                        onChange={(e) => update('legal_hold', e.target.checked)}
                    />{' '}
                    Preserve matching evidence under legal hold
                </label>
            </div>
        );
    }
}

function TabIntro({
    title,
    action,
    enabled,
    onAction,
}: {
    title: string;
    action: string;
    enabled: boolean;
    onAction: () => void;
}) {
    return (
        <div className="flex items-center justify-between gap-3">
            <h3 className="font-semibold">{title}</h3>
            {enabled ? (
                <Button size="sm" onClick={onAction}>
                    <Plus /> {action}
                </Button>
            ) : null}
        </div>
    );
}
