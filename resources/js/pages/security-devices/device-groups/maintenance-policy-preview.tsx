import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import {
    formatDateTimeLong,
    timeOffsetsInZone,
    toDatetimeLocal,
} from '@/lib/datetime';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    CalendarClock,
    FileClock,
    ListChecks,
    Loader2,
    Monitor,
} from 'lucide-react';
import { type ComponentProps, useEffect, useRef, useState } from 'react';

type Named = { id: number; name: string };
export type PreviewMember = Named & {
    device_uid: string;
    assignment_type?: string | null;
    assigned_to?: string | null;
};
type MonitorChoice = Named & {
    device_id: number;
    kind: string | null;
    is_enabled: boolean;
};
type Snapshot = {
    id: number;
    version: number;
    state: string;
    site_id: number;
    device_id: number | null;
    monitor_id: number | null;
    monitor_device_id: number | null;
    name: string;
    reason: string;
    policy: string;
    starts_at: string;
    ends_at: string;
    recurrence: string | null;
    recurrence_until: string | null;
    timezone: string;
    dst_overlap: string;
    dst_gap: string;
    created_by_user_id: number | null;
    updated_by_user_id: number | null;
    cancelled_at: string | null;
    cancelled_by_user_id: number | null;
    cancellation_reason: string | null;
};
type Rule = {
    revision_id: number;
    window_id: number;
    version: number;
    actor_id: number | null;
    operation: string;
    effective_from: string;
    recorded_at: string;
    snapshot_hash: string;
    snapshot: Snapshot;
};
export type MaintenanceResolution = {
    schema_version: 1;
    owner: 'Monitoring';
    basis: 'occurrence_effective_maintenance_revisions';
    observed_at: string;
    interpreted_at: string;
    clock_uncertainty_seconds: null;
    state: 'applied' | 'none' | 'conflict' | 'unsupported' | 'unavailable';
    reason_code: string | null;
    winner: Rule | null;
    matching: Rule[];
    overridden: Rule[];
    assignment: null | {
        basis: string;
        assignment_id: number;
        site_id: number;
        device_id: number;
        assigned_at: string;
        released_at: string | null;
        row_created_at: string;
    };
};
type Common = {
    schema_version: 1;
    mode: 'choices' | 'preview';
    actor_id: number;
    as_of: string;
    owner: 'Monitoring';
    capability: 'technical_maintenance_notification_ticket_suppression';
    supported_dimensions: ['site', 'device', 'monitor'];
    precedence: ['monitor', 'device', 'site'];
    group_inheritance: {
        supported: false;
        reason_code: 'group_not_policy_dimension';
    };
    commits_change: false;
    execution_authority: false;
    provider_action: false;
    health_claim: false;
    safety_response_suppression: false;
    group: Named;
    device: Named & { device_uid: string };
    site: Named;
};
export type MaintenanceChoices = Common & {
    mode: 'choices';
    monitors: MonitorChoice[];
    monitor_limit: 50;
    truncated: boolean;
    state: 'ready' | 'no_monitors';
};
export type MaintenancePreview = Common & {
    mode: 'preview';
    monitor: MonitorChoice;
    requested_at: string;
    resolution: MaintenanceResolution;
};
type Authority = {
    user?: { id: number } | null;
    can?: {
        securityDevices?: {
            groupsManage?: boolean;
            monitoringManage?: boolean;
        };
    };
};
const steps = [
    {
        key: 'choice',
        label: 'Monitor and time',
        blurb: 'One current member and a chosen instant',
        icon: CalendarClock,
    },
    {
        key: 'result',
        label: 'Policy preview',
        blurb: 'Rule precedence and availability',
        icon: ListChecks,
    },
    {
        key: 'records',
        label: 'Full records',
        blurb: 'Revisions, schedules and assignment',
        icon: FileClock,
    },
];
const positive = (v: unknown): v is number =>
    typeof v === 'number' && Number.isSafeInteger(v) && v > 0;
const record = (v: unknown): v is Record<string, unknown> =>
    Boolean(v && typeof v === 'object' && !Array.isArray(v));
export function strictPreviewUtc(v: string): boolean {
    return (
        /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/.test(v) &&
        Number(v.slice(0, 4)) > 0 &&
        Number.isFinite(Date.parse(v)) &&
        new Date(v).toISOString().slice(0, 19) === v.slice(0, 19)
    );
}
const normalizedUtc = (v: string) =>
    v.slice(0, 19) +
    '.' +
    (v.slice(19, -1).replace(/^\./, '') || '').padEnd(6, '0') +
    'Z';
export function maintenancePreviewInstant(
    local: string,
    offset: string,
    exactUtc = '',
): { at: string | null; error: string | null } {
    if (exactUtc)
        return strictPreviewUtc(exactUtc)
            ? { at: exactUtc, error: null }
            : {
                  at: null,
                  error: 'Enter a complete UTC time ending in Z, including seconds and up to six fractional digits.',
              };
    const offsets = timeOffsetsInZone(local);
    if (!offsets.length)
        return {
            at: null,
            error: local
                ? 'This local time does not exist or is incomplete. Choose another time.'
                : 'Choose a date and time.',
        };
    if (offsets.length > 1 && !offsets.includes(offset))
        return {
            at: null,
            error: 'This time occurs twice. Choose the first or second occurrence.',
        };
    const selected = offsets.length === 1 ? offsets[0] : offset;
    return {
        at: new Date(local + ':00' + selected)
            .toISOString()
            .replace('.000Z', 'Z'),
        error: null,
    };
}
export const maintenanceMemberIdentity = (member: PreviewMember) =>
    JSON.stringify([
        member.id,
        member.device_uid,
        member.assignment_type ?? null,
        member.assigned_to ?? null,
    ]);
function monitorValid(v: unknown, device: number): v is MonitorChoice {
    return (
        record(v) &&
        positive(v.id) &&
        v.device_id === device &&
        typeof v.name === 'string' &&
        (v.kind === null || typeof v.kind === 'string') &&
        typeof v.is_enabled === 'boolean'
    );
}
function commonValid(
    v: unknown,
    mode: string,
    actor: number,
    group: number,
    member: PreviewMember,
): v is Common {
    return (
        record(v) &&
        v.schema_version === 1 &&
        v.mode === mode &&
        v.actor_id === actor &&
        typeof v.as_of === 'string' &&
        strictPreviewUtc(v.as_of) &&
        v.owner === 'Monitoring' &&
        v.capability ===
            'technical_maintenance_notification_ticket_suppression' &&
        JSON.stringify(v.supported_dimensions) ===
            JSON.stringify(['site', 'device', 'monitor']) &&
        JSON.stringify(v.precedence) ===
            JSON.stringify(['monitor', 'device', 'site']) &&
        record(v.group_inheritance) &&
        v.group_inheritance.supported === false &&
        v.group_inheritance.reason_code === 'group_not_policy_dimension' &&
        [
            'commits_change',
            'execution_authority',
            'provider_action',
            'health_claim',
            'safety_response_suppression',
        ].every((k) => v[k] === false) &&
        record(v.group) &&
        v.group.id === group &&
        typeof v.group.name === 'string' &&
        record(v.device) &&
        v.device.id === member.id &&
        v.device.device_uid === member.device_uid &&
        typeof v.device.name === 'string' &&
        record(v.site) &&
        positive(v.site.id) &&
        typeof v.site.name === 'string'
    );
}
function ruleValid(v: unknown, site: number): v is Rule {
    if (
        !record(v) ||
        !positive(v.revision_id) ||
        !positive(v.window_id) ||
        !positive(v.version) ||
        !(v.actor_id === null || positive(v.actor_id)) ||
        typeof v.operation !== 'string' ||
        typeof v.effective_from !== 'string' ||
        typeof v.recorded_at !== 'string' ||
        typeof v.snapshot_hash !== 'string' ||
        !record(v.snapshot)
    )
        return false;
    const s = v.snapshot;
    return (
        s.id === v.window_id &&
        s.version === v.version &&
        s.site_id === site &&
        [
            'state',
            'name',
            'reason',
            'policy',
            'starts_at',
            'ends_at',
            'timezone',
            'dst_overlap',
            'dst_gap',
        ].every((k) => typeof s[k] === 'string') &&
        [
            'device_id',
            'monitor_id',
            'monitor_device_id',
            'created_by_user_id',
            'updated_by_user_id',
            'cancelled_by_user_id',
        ].every((k) => s[k] === null || positive(s[k])) &&
        [
            'recurrence',
            'recurrence_until',
            'cancelled_at',
            'cancellation_reason',
        ].every((k) => s[k] === null || typeof s[k] === 'string')
    );
}
function resolutionValid(
    v: unknown,
    site: number,
    member: number,
    at: string,
    monitorId: number,
): v is MaintenanceResolution {
    if (
        !record(v) ||
        v.schema_version !== 1 ||
        v.owner !== 'Monitoring' ||
        v.basis !== 'occurrence_effective_maintenance_revisions' ||
        v.observed_at !== normalizedUtc(at) ||
        typeof v.interpreted_at !== 'string' ||
        !strictPreviewUtc(v.interpreted_at) ||
        v.clock_uncertainty_seconds !== null ||
        !['applied', 'none', 'conflict', 'unsupported', 'unavailable'].includes(
            String(v.state),
        ) ||
        !(v.reason_code === null || typeof v.reason_code === 'string') ||
        !Array.isArray(v.matching) ||
        !Array.isArray(v.overridden) ||
        v.matching.length + v.overridden.length > 200 ||
        ![...v.matching, ...v.overridden].every((r) => ruleValid(r, site))
    )
        return false;
    const scopeMatches = (rule: Rule) =>
        rule.snapshot.monitor_id !== null
            ? rule.snapshot.monitor_id === monitorId &&
              rule.snapshot.monitor_device_id === member &&
              rule.snapshot.device_id === null
            : rule.snapshot.device_id === null ||
              rule.snapshot.device_id === member;
    if (![...v.matching, ...v.overridden].every(scopeMatches)) return false;
    if (
        v.state === 'applied'
            ? !ruleValid(v.winner, site) ||
              v.matching.length !== 1 ||
              JSON.stringify(v.winner) !== JSON.stringify(v.matching[0])
            : v.winner !== null
    )
        return false;
    if (v.state === 'conflict' && v.matching.length < 2) return false;
    if (
        v.assignment !== null &&
        (!record(v.assignment) ||
            v.assignment.site_id !== site ||
            v.assignment.device_id !== member ||
            !positive(v.assignment.assignment_id) ||
            typeof v.assignment.basis !== 'string' ||
            typeof v.assignment.assigned_at !== 'string' ||
            typeof v.assignment.row_created_at !== 'string' ||
            !(
                v.assignment.released_at === null ||
                typeof v.assignment.released_at === 'string'
            ))
    )
        return false;
    return true;
}
const reasons: Record<string, string> = {
    most_specific_occurrence_rule:
        'The most specific recorded rule covers this instant.',
    no_applicable_maintenance:
        'No recorded maintenance rule applies at this instant.',
    equal_priority_rules:
        'More than one rule has the same highest priority. A single winner cannot be chosen.',
    unsupported_maintenance_policy:
        'The recorded policy is not supported by this preview.',
    history_storage_unavailable: 'Maintenance history is unavailable.',
    observation_before_history_tracking:
        'This instant is earlier than recorded history tracking.',
    occurrence_assignment_unverified:
        'The device’s Site assignment cannot be verified for this instant.',
    legacy_rule_history_unavailable:
        'An existing rule has no verified history.',
    revision_chain_unverified:
        'The recorded revision sequence cannot be verified.',
    current_rule_history_diverged:
        'The current rule differs from its recorded history.',
    observation_before_reviewed_baseline:
        'This instant is earlier than the reviewed rule baseline.',
};
const states: Record<MaintenanceResolution['state'], string> = {
    applied: 'Applicable technical maintenance rule',
    none: 'No applicable maintenance',
    conflict: 'Conflicting maintenance rules',
    unsupported: 'Policy unavailable for this preview',
    unavailable: 'Maintenance history unavailable',
};
const text = (v: unknown) =>
    v === null || v === undefined || v === '' ? 'Not recorded' : String(v);
const detailLabels: Record<string, string> = {
    dst_overlap: 'Repeated local time',
    dst_gap: 'Skipped local time',
    monitor_device_id: 'Monitor’s device ID',
    policy: 'Technical notification and ticket policy',
    snapshot_hash: 'Recorded fingerprint',
};
const detailLabel = (v: string) =>
    detailLabels[v] ??
    v.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
function RuleRecord({ rule, label }: { rule: Rule; label: string }) {
    return (
        <ReviewCard title={label + ': ' + rule.snapshot.name} icon={FileClock}>
            {Object.entries(rule)
                .filter(([key]) => key !== 'snapshot')
                .map(([key, value]) => (
                    <ReviewRow
                        key={key}
                        label={detailLabel(key)}
                        value={text(value)}
                    />
                ))}
            {Object.entries(rule.snapshot).map(([key, value]) => (
                <ReviewRow
                    key={key}
                    label={detailLabel(key)}
                    value={
                        key === 'recurrence' && value === null
                            ? 'Does not repeat'
                            : text(value)
                    }
                />
            ))}
        </ReviewCard>
    );
}
export default function MaintenancePolicyPreview({
    group,
    member,
    actorId,
    sourceActor,
    asOf,
    onClose,
    onCloseAutoFocus,
}: {
    group: Named;
    member: PreviewMember;
    actorId: number;
    sourceActor: number;
    asOf: string;
    onClose: () => void;
    onCloseAutoFocus?: ComponentProps<typeof WizardShell>['onCloseAutoFocus'];
}) {
    const { auth } = usePage<{ auth?: Authority }>().props;
    const [source] = useState(() => ({
        group: group.id,
        member: maintenanceMemberIdentity(member),
        actor: sourceActor,
    }));
    const allowed =
        positive(source.actor) &&
        source.actor === actorId &&
        auth?.user?.id === source.actor &&
        auth.can?.securityDevices?.groupsManage === true &&
        auth.can.securityDevices.monitoringManage === true &&
        source.group === group.id &&
        source.member === maintenanceMemberIdentity(member) &&
        strictPreviewUtc(asOf);
    const currentAllowed = useRef(allowed);
    currentAllowed.current = allowed;
    const [denied, setDenied] = useState(false),
        [choices, setChoices] = useState<MaintenanceChoices | null>(null),
        [result, setResult] = useState<MaintenancePreview | null>(null),
        [monitorId, setMonitorId] = useState(''),
        [local, setLocal] = useState(() => toDatetimeLocal(asOf)),
        [offset, setOffset] = useState(''),
        [exact, setExact] = useState(''),
        [step, setStep] = useState(0),
        [busy, setBusy] = useState(false),
        [error, setError] = useState<string | null>(null),
        [timeError, setTimeError] = useState<string | null>(null);
    const initialLoad = useRef(false);
    const request = useRef<AbortController | null>(null),
        mounted = useRef(true);
    const visible = allowed && !denied;
    const clearPrivate = () => {
        request.current?.abort();
        request.current = null;
        setChoices(null);
        setResult(null);
        setMonitorId('');
        setLocal('');
        setExact('');
        setOffset('');
        setBusy(false);
        setDenied(true);
    };
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
            initialLoad.current = false;
            request.current?.abort();
        };
    }, []);
    useEffect(() => {
        if (!allowed) {
            request.current?.abort();
            setChoices(null);
            setResult(null);
            setMonitorId('');
            setLocal('');
            setExact('');
            setOffset('');
            setBusy(false);
            setDenied(true);
        }
    }, [allowed]);
    const path =
        '/security-devices/device-groups/' +
        group.id +
        '/maintenance-policy-preview';
    const load = async (mode: 'choices' | 'preview') => {
        if (!visible || !currentAllowed.current) return;
        const instant = maintenancePreviewInstant(local, offset, exact),
            monitor = choices?.monitors.find((m) => String(m.id) === monitorId);
        if (mode === 'preview' && (!monitor || !instant.at)) {
            setTimeError(instant.error);
            setError(monitor ? null : 'Choose a current monitor.');
            return;
        }
        request.current?.abort();
        const active = new AbortController();
        request.current = active;
        setBusy(true);
        setError(null);
        setTimeError(null);
        const params = {
            mode,
            expected_actor_id: String(source.actor),
            device_id: String(member.id),
            ...(mode === 'preview'
                ? { monitor_id: String(monitor!.id), at: instant.at! }
                : {}),
        };
        const owns = () =>
            mounted.current &&
            currentAllowed.current &&
            request.current === active &&
            !active.signal.aborted;
        try {
            const response = await axios.get<unknown>(path, {
                params,
                signal: active.signal,
                timeout: 30_000,
            });
            if (!owns()) return;
            const data = response.data;
            if (!commonValid(data, mode, source.actor, source.group, member)) {
                clearPrivate();
                return;
            }
            if (mode === 'choices') {
                const value = data as MaintenanceChoices;
                if (
                    value.monitor_limit !== 50 ||
                    typeof value.truncated !== 'boolean' ||
                    !Array.isArray(value.monitors) ||
                    value.monitors.length > 50 ||
                    !value.monitors.every((m) => monitorValid(m, member.id)) ||
                    new Set(value.monitors.map((m) => m.id)).size !==
                        value.monitors.length ||
                    (value.state === 'ready'
                        ? value.monitors.length < 1
                        : value.state !== 'no_monitors' ||
                          value.monitors.length !== 0)
                )
                    throw Error('unverified choices');
                if (
                    choices &&
                    (value.site.id !== choices.site.id ||
                        (monitorId &&
                            !value.monitors.some(
                                (m) => String(m.id) === monitorId,
                            )))
                ) {
                    clearPrivate();
                    return;
                }
                setChoices(value);
            } else {
                const value = data as MaintenancePreview;
                if (
                    !choices ||
                    value.site.id !== choices.site.id ||
                    !monitorValid(value.monitor, member.id) ||
                    value.monitor.id !== monitor!.id ||
                    value.requested_at !== normalizedUtc(instant.at!) ||
                    !resolutionValid(
                        value.resolution,
                        value.site.id,
                        member.id,
                        instant.at!,
                        value.monitor.id,
                    )
                ) {
                    clearPrivate();
                    return;
                }
                setResult(value);
                setStep(1);
            }
        } catch (failure) {
            if (!owns()) return;
            const status = (failure as { response?: { status?: number } })
                ?.response?.status;
            if (status === 401 || status === 403 || status === 404)
                clearPrivate();
            else
                setError(
                    'The latest preview could not be loaded. Refresh the choices or try this read again.',
                );
        } finally {
            if (owns()) {
                request.current = null;
                setBusy(false);
            }
        }
    };
    useEffect(() => {
        if (allowed && !initialLoad.current) {
            initialLoad.current = true;
            void load('choices');
        }
    });
    const offsets = timeOffsetsInZone(local),
        chosen = maintenancePreviewInstant(local, offset, exact);
    const close = () => {
        request.current?.abort();
        onClose();
    };
    const r = result?.resolution;
    return (
        <WizardShell
            open
            onClose={close}
            onCloseAutoFocus={onCloseAutoFocus}
            title="Maintenance policy preview"
            description="Review technical maintenance rules for one member and one instant."
            railIcon={CalendarClock}
            railTitle="Maintenance preview"
            railSub={visible ? member.name : 'Access unavailable'}
            steps={steps}
            stepIndex={step}
            onStepClick={(index) => {
                if (visible && (index === 0 || result)) setStep(index);
            }}
            sequential={false}
            headerLabel={steps[step].label}
            maxWidth="min(94vw, 1100px)"
            footerStart={
                <Button variant="outline" onClick={close}>
                    Close
                </Button>
            }
            footerEnd={
                visible && (
                    <Button
                        disabled={
                            busy || !choices?.monitors.length || !monitorId
                        }
                        onClick={() => void load('preview')}
                    >
                        {busy && (
                            <Loader2 className="mr-2 size-4 animate-spin" />
                        )}
                        Preview policy
                    </Button>
                )
            }
        >
            <WizardStepPane>
                {!visible ? (
                    <div role="alert" className="space-y-3">
                        <p>
                            This preview is unavailable for the current account,
                            member or monitor. Refresh the group to review
                            current access.
                        </p>
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.get(
                                    '/security-devices/device-groups/' +
                                        group.id,
                                    {},
                                    {
                                        preserveState: false,
                                        preserveScroll: true,
                                    },
                                )
                            }
                        >
                            Refresh group
                        </Button>
                    </div>
                ) : (
                    <div className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            Monitor rules take priority over device rules, then
                            Site rules. Groups do not supply inherited
                            maintenance policies.
                        </p>
                        {busy && (
                            <p role="status">
                                Reading current maintenance records…
                            </p>
                        )}
                        {error && (
                            <div role="alert" className="space-y-2">
                                <p className="text-destructive">{error}</p>
                                {result && (
                                    <p>
                                        The previous preview is retained with
                                        its original time below.
                                    </p>
                                )}
                            </div>
                        )}
                        {step === 0 && (
                            <>
                                <ReviewCard
                                    title="Current member"
                                    icon={Monitor}
                                >
                                    <ReviewRow
                                        label="Group"
                                        value={group.name}
                                    />
                                    <ReviewRow
                                        label="Device"
                                        value={member.name}
                                    />
                                    {choices && (
                                        <>
                                            <ReviewRow
                                                label="Site"
                                                value={choices.site.name}
                                            />
                                            <ReviewRow
                                                label="Choices read"
                                                value={
                                                    formatDateTimeLong(
                                                        choices.as_of,
                                                    ) + ' · Pacific/Auckland'
                                                }
                                            />
                                        </>
                                    )}
                                </ReviewCard>
                                {!choices && !busy && (
                                    <Button
                                        variant="outline"
                                        onClick={() => void load('choices')}
                                    >
                                        Load current monitors
                                    </Button>
                                )}
                                {choices?.state === 'no_monitors' && (
                                    <p role="status">
                                        No current monitors are available for
                                        this member. Choose another member or
                                        refresh the current choices.
                                    </p>
                                )}
                                {choices && (
                                    <>
                                        <div className="space-y-2">
                                            <Label htmlFor="maintenance-preview-monitor">
                                                Monitor{' '}
                                                <span className="text-status-critical">
                                                    *
                                                </span>
                                            </Label>
                                            <Select
                                                value={monitorId}
                                                onValueChange={(value) => {
                                                    request.current?.abort();
                                                    request.current = null;
                                                    setBusy(false);
                                                    setMonitorId(value);
                                                    setResult(null);
                                                    setError(null);
                                                }}
                                            >
                                                <SelectTrigger
                                                    id="maintenance-preview-monitor"
                                                    aria-label="Monitor"
                                                >
                                                    <SelectValue placeholder="Choose a current monitor" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {choices.monitors.map(
                                                        (m) => (
                                                            <SelectItem
                                                                key={m.id}
                                                                value={String(
                                                                    m.id,
                                                                )}
                                                            >
                                                                {m.name} ·{' '}
                                                                {m.is_enabled
                                                                    ? 'Enabled'
                                                                    : 'Disabled'}{' '}
                                                                ·{' '}
                                                                {m.kind ??
                                                                    'Kind not recorded'}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            {choices.truncated && (
                                                <p className="text-caption">
                                                    Showing the first 50 current
                                                    monitors. Further monitors
                                                    are not included in these
                                                    choices.
                                                </p>
                                            )}
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                disabled={busy}
                                                onClick={() =>
                                                    void load('choices')
                                                }
                                            >
                                                Refresh monitor choices
                                            </Button>
                                        </div>
                                        <DateTimeField
                                            id="maintenance-preview-at"
                                            label="Preview instant"
                                            value={local}
                                            onChange={(value) => {
                                                setLocal(value);
                                                setOffset('');
                                                setExact('');
                                                setTimeError(null);
                                            }}
                                            clearable={false}
                                            error={timeError ?? undefined}
                                            hint="Choose when to interpret the recorded maintenance rules."
                                        />
                                        {!exact && offsets.length > 1 && (
                                            <div className="space-y-2">
                                                <Label>
                                                    Repeated local time
                                                </Label>
                                                <Select
                                                    value={offset}
                                                    onValueChange={setOffset}
                                                >
                                                    <SelectTrigger aria-label="Repeated local time">
                                                        <SelectValue placeholder="Choose which occurrence" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {offsets.map((o, i) => (
                                                            <SelectItem
                                                                key={o}
                                                                value={o}
                                                            >
                                                                {i === 0
                                                                    ? 'First occurrence'
                                                                    : 'Second occurrence'}{' '}
                                                                · UTC{o}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        )}
                                        {timeError && (
                                            <p
                                                role="alert"
                                                className="text-sm text-destructive"
                                            >
                                                {timeError}
                                            </p>
                                        )}
                                        {chosen.at && (
                                            <p className="text-sm">
                                                Chosen instant:{' '}
                                                {formatDateTimeLong(chosen.at)}{' '}
                                                · Pacific/Auckland
                                            </p>
                                        )}
                                        <details className="rounded-lg border border-border p-3">
                                            <summary className="cursor-pointer text-sm font-medium">
                                                Exact UTC time
                                            </summary>
                                            <div className="mt-3 space-y-2">
                                                <Label htmlFor="maintenance-preview-utc">
                                                    UTC instant including
                                                    seconds
                                                </Label>
                                                <Input
                                                    id="maintenance-preview-utc"
                                                    value={exact}
                                                    placeholder="2026-10-14T03:00:00Z"
                                                    onChange={(e) => {
                                                        setExact(
                                                            e.target.value,
                                                        );
                                                        setTimeError(null);
                                                    }}
                                                />
                                                <p className="text-caption">
                                                    Optional exact time. End
                                                    with Z; use up to six
                                                    fractional digits. This
                                                    value replaces the local
                                                    date and time while entered.
                                                </p>
                                            </div>
                                        </details>
                                    </>
                                )}
                            </>
                        )}
                        {step === 1 && result && r && (
                            <>
                                <ReviewCard
                                    title={states[r.state]}
                                    icon={ListChecks}
                                >
                                    <ReviewRow
                                        label={
                                            error ||
                                            busy ||
                                            (chosen.at &&
                                                normalizedUtc(chosen.at) !==
                                                    result.requested_at)
                                                ? 'Previous preview for'
                                                : 'Preview for'
                                        }
                                        value={
                                            formatDateTimeLong(
                                                result.requested_at,
                                            ) + ' · Pacific/Auckland'
                                        }
                                    />
                                    <ReviewRow
                                        label="Read at"
                                        value={
                                            formatDateTimeLong(result.as_of) +
                                            ' · Pacific/Auckland'
                                        }
                                    />
                                    <ReviewRow
                                        label="Interpreted at"
                                        value={
                                            formatDateTimeLong(
                                                r.interpreted_at,
                                            ) + ' · Pacific/Auckland'
                                        }
                                    />
                                    <ReviewRow
                                        label="Monitor"
                                        value={result.monitor.name}
                                    />
                                    <ReviewRow
                                        label="Monitor enabled"
                                        value={
                                            result.monitor.is_enabled
                                                ? 'Yes'
                                                : 'No'
                                        }
                                    />
                                    <ReviewRow
                                        label="Site"
                                        value={result.site.name}
                                    />
                                    <p className="mt-3 text-sm">
                                        {reasons[r.reason_code ?? ''] ??
                                            'The returned interpretation does not establish an applicable rule.'}
                                    </p>
                                    {r.winner && (
                                        <>
                                            <ReviewRow
                                                label="Winning rule"
                                                value={r.winner.snapshot.name}
                                            />
                                            <ReviewRow
                                                label="Priority"
                                                value={
                                                    r.winner.snapshot
                                                        .monitor_id !== null
                                                        ? 'Monitor'
                                                        : r.winner.snapshot
                                                                .device_id !==
                                                            null
                                                          ? 'Device'
                                                          : 'Site'
                                                }
                                            />
                                            <ReviewRow
                                                label="Revision"
                                                value={r.winner.version}
                                            />
                                            <ReviewRow
                                                label="Schedule timezone"
                                                value={
                                                    r.winner.snapshot.timezone
                                                }
                                            />
                                        </>
                                    )}
                                    <ReviewRow
                                        label="Highest-priority matches"
                                        value={r.matching.length}
                                    />
                                    <ReviewRow
                                        label="Overridden rules"
                                        value={r.overridden.length}
                                    />
                                </ReviewCard>
                                <p className="text-sm text-muted-foreground">
                                    This read concerns technical notification
                                    and ticket maintenance. It does not change
                                    rules, act on equipment, assess device
                                    health or suppress a safety response.
                                </p>
                                <Button
                                    variant="outline"
                                    onClick={() => setStep(2)}
                                >
                                    Read full records
                                </Button>
                            </>
                        )}
                        {step === 2 && result && r && (
                            <>
                                <ReviewCard
                                    title="Preview context"
                                    icon={CalendarClock}
                                >
                                    {[
                                        ['Group', result.group.name],
                                        ['Group ID', result.group.id],
                                        ['Device', result.device.name],
                                        ['Device ID', result.device.id],
                                        [
                                            'Device UID',
                                            result.device.device_uid,
                                        ],
                                        ['Site', result.site.name],
                                        ['Site ID', result.site.id],
                                        ['Monitor', result.monitor.name],
                                        ['Monitor ID', result.monitor.id],
                                        ['Monitor kind', result.monitor.kind],
                                        [
                                            'Monitor enabled',
                                            result.monitor.is_enabled
                                                ? 'Yes'
                                                : 'No',
                                        ],
                                        [
                                            'Exact chosen instant',
                                            result.requested_at,
                                        ],
                                        ['Read at', result.as_of],
                                        ['Occurrence instant', r.observed_at],
                                        ['Interpreted at', r.interpreted_at],
                                        [
                                            'Clock uncertainty seconds',
                                            r.clock_uncertainty_seconds,
                                        ],
                                        ['Interpretation state', r.state],
                                        ['Recorded reason', r.reason_code],
                                        ['Recorded basis', r.basis],
                                    ].map(([label, value]) => (
                                        <ReviewRow
                                            key={String(label)}
                                            label={String(label)}
                                            value={text(value)}
                                        />
                                    ))}
                                </ReviewCard>
                                {r.assignment && (
                                    <ReviewCard
                                        title="Recorded Site assignment"
                                        icon={Monitor}
                                    >
                                        {Object.entries(r.assignment).map(
                                            ([key, value]) => (
                                                <ReviewRow
                                                    key={key}
                                                    label={detailLabel(key)}
                                                    value={text(value)}
                                                />
                                            ),
                                        )}
                                    </ReviewCard>
                                )}
                                {r.winner && (
                                    <RuleRecord
                                        rule={r.winner}
                                        label="Winning revision"
                                    />
                                )}
                                {r.matching
                                    .filter(
                                        (rule) =>
                                            rule.revision_id !==
                                            r.winner?.revision_id,
                                    )
                                    .map((rule) => (
                                        <RuleRecord
                                            key={rule.revision_id}
                                            rule={rule}
                                            label={
                                                r.state === 'conflict'
                                                    ? 'Equal-priority revision'
                                                    : 'Matching revision'
                                            }
                                        />
                                    ))}
                                {r.overridden.map((rule) => (
                                    <RuleRecord
                                        key={rule.revision_id}
                                        rule={rule}
                                        label="Overridden revision"
                                    />
                                ))}
                            </>
                        )}
                    </div>
                )}
            </WizardStepPane>
        </WizardShell>
    );
}
