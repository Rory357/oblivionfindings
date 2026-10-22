import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import {
    formatDateTimeInZone,
    formatDateTimeLong,
    timeOffsetsInZone,
    toDatetimeLocal,
    WORKER_TIMEZONE,
} from '@/lib/datetime';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle,
    CalendarClock,
    FileClock,
    Info,
    ListChecks,
    Monitor,
    ShieldAlert,
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
        blurb: 'One device in this group and a time to check',
        icon: CalendarClock,
    },
    {
        key: 'result',
        label: 'Policy preview',
        blurb: 'Which maintenance rule applies',
        icon: ListChecks,
    },
    {
        key: 'records',
        label: 'Full records',
        blurb: 'Rule history, schedules and Site assignment',
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
        'The most specific recorded rule covers this time.',
    no_applicable_maintenance:
        'No recorded maintenance rule applies at this time.',
    equal_priority_rules:
        'More than one rule has the same highest priority, so a single rule can’t be chosen.',
    unsupported_maintenance_policy:
        'The recorded policy isn’t supported by this preview.',
    history_storage_unavailable: 'Maintenance history isn’t available.',
    observation_before_history_tracking:
        'This time is earlier than the start of recorded maintenance history.',
    occurrence_assignment_unverified:
        'The device’s Site assignment can’t be confirmed for this time.',
    legacy_rule_history_unavailable:
        'An existing rule has no confirmed history.',
    revision_chain_unverified:
        'The rule’s recorded changes can’t be confirmed.',
    current_rule_history_diverged:
        'The current rule differs from its recorded history.',
    observation_before_reviewed_baseline:
        'This time is earlier than the rule’s reviewed starting record.',
};
const states: Record<MaintenanceResolution['state'], string> = {
    applied: 'Applicable technical maintenance rule',
    none: 'No applicable maintenance',
    conflict: 'Conflicting maintenance rules',
    unsupported: 'Policy unavailable for this preview',
    unavailable: 'Maintenance history unavailable',
};
type Denial = 'signed_out' | 'access' | 'unavailable';
/** Genuine denials: private results are cleared and only a full group read recovers. */
const denials: Record<Denial, string> = {
    signed_out:
        'You’ve been signed out. Sign in again, then reopen this group to preview maintenance.',
    access: 'Your access to maintenance previews has changed. Refresh the group to see what you can do now.',
    unavailable:
        'This preview is no longer available. The device may have left the group, or its monitor or Site may have changed. Refresh the group to see what’s current.',
};
/** Failed reads: nothing changed and the person can simply try again. */
const READ_FAILED =
    'The preview couldn’t be loaded. Nothing has changed. Try again.';
const READ_UNCHECKED =
    'The preview couldn’t be checked, so it isn’t shown. Nothing has changed. Try again.';
const READ_THROTTLED =
    'Too many previews in a short time. Wait a minute, then try again.';
const text = (v: unknown) =>
    v === null || v === undefined || v === '' ? 'Not recorded' : String(v);
const humanise = (v: string) =>
    v.replace(/[._-]+/g, ' ').replace(/^./, (c) => c.toUpperCase());
const zoneLabel = (zone: string) =>
    zone === WORKER_TIMEZONE ? 'New Zealand time' : zone;
const nzTime = (v: string | null) =>
    v ? formatDateTimeLong(v) + ' · New Zealand time' : 'Not recorded';
const zonedTime = (v: string | null, zone: string) => {
    if (!v) return 'Not recorded';
    try {
        return formatDateTimeInZone(v, zone) + ' · ' + zoneLabel(zone);
    } catch {
        return nzTime(v);
    }
};
const plain = (labels: Record<string, string>) => (v: string | null) =>
    v === null ? 'Not recorded' : (labels[v] ?? humanise(v));
const ruleStateLabel = plain({ active: 'Active', cancelled: 'Cancelled' });
const operationLabel = plain({
    create: 'Rule created',
    update: 'Rule changed',
    baseline: 'Reviewed starting record',
    cancel: 'Rule cancelled',
});
const policyLabel = plain({
    suppress_notifications_and_ticketing:
        'Pauses technical notifications and automatic tickets',
});
const overlapLabel = plain({
    reject: 'Not allowed',
    earlier: 'Use the first occurrence',
    later: 'Use the second occurrence',
});
const gapLabel = plain({ reject: 'Not allowed', skip: 'Skip that time' });
const recurrenceLabel = (v: string | null) =>
    v === null
        ? 'Does not repeat'
        : plain({ daily: 'Every day', weekly: 'Every week' })(v);
const scopeLabel = (s: Snapshot) =>
    s.monitor_id !== null
        ? 'This monitor'
        : s.device_id !== null
          ? 'This device'
          : 'The whole Site';
const monitorKindLabel = (kind: string | null) =>
    kind ? humanise(kind) : 'Type not recorded';
/** Identifiers and raw recorded values, kept out of the plain view (as on the device profile). */
function TechnicalDetails({ rows }: { rows: Array<[string, unknown]> }) {
    return (
        <details className="mt-3 min-w-0 rounded-lg border border-border p-3">
            <summary className="frontline-focus text-caption cursor-pointer font-medium">
                Technical details
            </summary>
            <div className="mt-3 min-w-0 [overflow-wrap:anywhere]">
                {rows.map(([label, value]) => (
                    <ReviewRow key={label} label={label} value={text(value)} />
                ))}
            </div>
        </details>
    );
}
function RuleRecord({ rule, label }: { rule: Rule; label: string }) {
    const s = rule.snapshot;
    return (
        <ReviewCard title={label + ': ' + s.name} icon={FileClock}>
            <ReviewRow label="Applies to" value={scopeLabel(s)} />
            <ReviewRow label="Status" value={ruleStateLabel(s.state)} />
            <ReviewRow
                label="Starts"
                value={zonedTime(s.starts_at, s.timezone)}
            />
            <ReviewRow label="Ends" value={zonedTime(s.ends_at, s.timezone)} />
            <ReviewRow label="Repeats" value={recurrenceLabel(s.recurrence)} />
            {s.recurrence !== null && (
                <ReviewRow
                    label="Repeats until"
                    value={
                        s.recurrence_until
                            ? zonedTime(s.recurrence_until, s.timezone)
                            : 'No end date'
                    }
                />
            )}
            <ReviewRow
                label="Schedule time zone"
                value={zoneLabel(s.timezone)}
            />
            <ReviewRow
                label="If a clock change repeats the time"
                value={overlapLabel(s.dst_overlap)}
            />
            <ReviewRow
                label="If a clock change skips the time"
                value={gapLabel(s.dst_gap)}
            />
            <ReviewRow label="What it pauses" value={policyLabel(s.policy)} />
            <ReviewRow label="Reason" value={text(s.reason)} />
            <ReviewRow label="Version" value={rule.version} />
            <ReviewRow
                label="Recorded change"
                value={operationLabel(rule.operation)}
            />
            <ReviewRow
                label="In effect from"
                value={nzTime(rule.effective_from)}
            />
            <ReviewRow label="Recorded" value={nzTime(rule.recorded_at)} />
            {s.cancelled_at && (
                <>
                    <ReviewRow
                        label="Cancelled"
                        value={nzTime(s.cancelled_at)}
                    />
                    <ReviewRow
                        label="Cancellation reason"
                        value={text(s.cancellation_reason)}
                    />
                </>
            )}
            <TechnicalDetails
                rows={[
                    ['Revision ID', rule.revision_id],
                    ['Rule ID', rule.window_id],
                    ['Recorded by (user ID)', rule.actor_id],
                    ['Created by (user ID)', s.created_by_user_id],
                    ['Updated by (user ID)', s.updated_by_user_id],
                    ['Cancelled by (user ID)', s.cancelled_by_user_id],
                    ['Site ID', s.site_id],
                    ['Device ID', s.device_id],
                    ['Monitor ID', s.monitor_id],
                    ['Monitor’s device ID', s.monitor_device_id],
                    ['Recorded state', s.state],
                    ['Recorded change type', rule.operation],
                    ['Recorded policy', s.policy],
                    ['Recorded start', s.starts_at],
                    ['Recorded end', s.ends_at],
                    ['Recorded repeat end', s.recurrence_until],
                    ['Recorded effective from', rule.effective_from],
                    ['Recorded at', rule.recorded_at],
                    ['Record checksum (SHA-256)', rule.snapshot_hash],
                ]}
            />
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
    const identityChanged =
        source.group !== group.id ||
        source.member !== maintenanceMemberIdentity(member);
    const allowed =
        positive(source.actor) &&
        source.actor === actorId &&
        auth?.user?.id === source.actor &&
        auth.can?.securityDevices?.groupsManage === true &&
        auth.can.securityDevices.monitoringManage === true &&
        !identityChanged &&
        strictPreviewUtc(asOf);
    const currentAllowed = useRef(allowed);
    currentAllowed.current = allowed;
    const [denied, setDenied] = useState<Denial | null>(null),
        [choices, setChoices] = useState<MaintenanceChoices | null>(null),
        [result, setResult] = useState<MaintenancePreview | null>(null),
        [monitorId, setMonitorId] = useState(''),
        [local, setLocal] = useState(() => toDatetimeLocal(asOf)),
        [offset, setOffset] = useState(''),
        [exact, setExact] = useState(''),
        [step, setStep] = useState(0),
        [busy, setBusy] = useState(false),
        [error, setError] = useState<string | null>(null),
        [notice, setNotice] = useState<string | null>(null),
        [timeError, setTimeError] = useState<string | null>(null);
    const initialLoad = useRef(false);
    const lastRead = useRef<'choices' | 'preview'>('choices');
    const request = useRef<AbortController | null>(null),
        mounted = useRef(true);
    const visible = allowed && denied === null;
    /** A genuine denial: abort, drop every private value, and require a full group read. */
    const deny = (reason: Denial) => {
        request.current?.abort();
        request.current = null;
        setChoices(null);
        setResult(null);
        setMonitorId('');
        setLocal('');
        setExact('');
        setOffset('');
        setError(null);
        setNotice(null);
        setBusy(false);
        setDenied((current) => current ?? reason);
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
        if (!allowed) deny(identityChanged ? 'unavailable' : 'access');
        // Runs when authority is lost; `deny` is recreated each render but
        // only aborts and clears state, so it is not a dependency.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [allowed, identityChanged]);
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
        lastRead.current = mode;
        request.current?.abort();
        const active = new AbortController();
        request.current = active;
        setBusy(true);
        setError(null);
        setNotice(null);
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
            // An answer for another person is a denial, not a failed read.
            if (
                record(data) &&
                positive(data.actor_id) &&
                data.actor_id !== source.actor
            ) {
                deny('access');
                return;
            }
            if (!commonValid(data, mode, source.actor, source.group, member))
                throw Error('unchecked');
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
                    throw Error('unchecked');
                const siteChanged =
                    choices && value.site.id !== choices.site.id;
                const monitorWithdrawn =
                    monitorId &&
                    !value.monitors.some((m) => String(m.id) === monitorId);
                if (siteChanged || monitorWithdrawn) {
                    // Current, checked choices replace the old ones; anything
                    // read for the old Site or monitor is dropped.
                    setMonitorId('');
                    setResult(null);
                    setStep(0);
                    setNotice(
                        siteChanged
                            ? 'This device’s Site has changed. Choose a monitor again.'
                            : 'The monitor you chose is no longer listed. Choose a current monitor.',
                    );
                }
                setChoices(value);
            } else {
                const value = data as MaintenancePreview;
                if (!choices || value.site.id !== choices.site.id) {
                    setChoices(null);
                    setMonitorId('');
                    setResult(null);
                    setStep(0);
                    setNotice(
                        'This device’s Site has changed since the monitors were loaded. Load the current monitors and choose again.',
                    );
                    return;
                }
                if (
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
                )
                    throw Error('unchecked');
                setResult(value);
                setStep(1);
            }
        } catch (failure) {
            if (!owns()) return;
            const status = (failure as { response?: { status?: number } })
                ?.response?.status;
            if (status === 401) deny('signed_out');
            else if (status === 403) deny('access');
            // The server answers every withdrawn member, monitor, Site or
            // permission with the same private 404, so it is treated as a
            // denial (and private data is cleared) without claiming which.
            else if (status === 404) deny('unavailable');
            else if (status === 429) setError(READ_THROTTLED);
            else if (
                failure instanceof Error &&
                failure.message === 'unchecked'
            )
                setError(READ_UNCHECKED);
            else setError(READ_FAILED);
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
    const monitors = choices?.monitors ?? [];
    return (
        <WizardShell
            open
            onClose={close}
            onCloseAutoFocus={onCloseAutoFocus}
            title="Maintenance policy preview"
            description="Check which technical maintenance rule applies to one device at one time."
            railIcon={CalendarClock}
            railTitle="Maintenance preview"
            railSub={visible ? member.name : 'Preview not available'}
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
                        disabled={busy || !monitors.length || !monitorId}
                        onClick={() => void load('preview')}
                    >
                        {busy && <Spinner className="mr-2" />}
                        Preview policy
                    </Button>
                )
            }
        >
            <WizardStepPane>
                {!visible ? (
                    <div className="space-y-3">
                        <Alert variant="warning">
                            <ShieldAlert />
                            <AlertDescription>
                                {
                                    denials[
                                        denied ??
                                            (identityChanged
                                                ? 'unavailable'
                                                : 'access')
                                    ]
                                }
                            </AlertDescription>
                        </Alert>
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
                            Site rules. Groups don’t pass maintenance rules on
                            to their devices.
                        </p>
                        {busy && (
                            <p role="status" className="text-sm">
                                Reading current maintenance records…
                            </p>
                        )}
                        {notice && (
                            <Alert variant="info" role="status">
                                <Info />
                                <AlertDescription>{notice}</AlertDescription>
                            </Alert>
                        )}
                        {error && (
                            <div className="space-y-2">
                                <Alert variant="destructive">
                                    <AlertTriangle />
                                    <AlertDescription>
                                        <p>{error}</p>
                                        {result && (
                                            <p>
                                                The previous preview is retained
                                                with its original time below.
                                            </p>
                                        )}
                                    </AlertDescription>
                                </Alert>
                                {error !== 'Choose a current monitor.' && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={busy}
                                        onClick={() =>
                                            void load(lastRead.current)
                                        }
                                    >
                                        Try again
                                    </Button>
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
                                                label="Monitors read"
                                                value={nzTime(choices.as_of)}
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
                                    <div role="status">
                                        <EmptyState
                                            variant="compact"
                                            icon={Monitor}
                                            title="No monitors to preview"
                                            description="No current monitors are available for this member. Choose another member or refresh the current choices."
                                            action={
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
                                            }
                                        />
                                    </div>
                                )}
                                {choices && monitors.length > 0 && (
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
                                                    setNotice(null);
                                                }}
                                            >
                                                <SelectTrigger
                                                    id="maintenance-preview-monitor"
                                                    aria-label="Monitor"
                                                >
                                                    <SelectValue placeholder="Choose a current monitor" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {monitors.map((m) => (
                                                        <SelectItem
                                                            key={m.id}
                                                            value={String(m.id)}
                                                        >
                                                            {m.name} ·{' '}
                                                            {m.is_enabled
                                                                ? 'On'
                                                                : 'Off'}{' '}
                                                            ·{' '}
                                                            {monitorKindLabel(
                                                                m.kind,
                                                            )}
                                                        </SelectItem>
                                                    ))}
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
                                            label="Time to check"
                                            value={local}
                                            onChange={(value) => {
                                                setLocal(value);
                                                setOffset('');
                                                setExact('');
                                                setTimeError(null);
                                            }}
                                            clearable={false}
                                            error={
                                                !exact
                                                    ? (timeError ?? undefined)
                                                    : undefined
                                            }
                                            hint="New Zealand time. Choose when to apply the recorded maintenance rules."
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
                                        {chosen.at && (
                                            <p className="text-sm">
                                                Chosen time: {nzTime(chosen.at)}
                                            </p>
                                        )}
                                        <details
                                            className="rounded-lg border border-border p-3"
                                            open={Boolean(exact) || undefined}
                                        >
                                            <summary className="frontline-focus cursor-pointer text-sm font-medium">
                                                Exact UTC time
                                            </summary>
                                            <div className="mt-3 space-y-2">
                                                <Label htmlFor="maintenance-preview-utc">
                                                    UTC time including seconds
                                                </Label>
                                                <Input
                                                    id="maintenance-preview-utc"
                                                    value={exact}
                                                    placeholder="2026-10-14T03:00:00Z"
                                                    aria-invalid={
                                                        Boolean(
                                                            exact && timeError,
                                                        ) || undefined
                                                    }
                                                    aria-describedby="maintenance-preview-utc-help"
                                                    onChange={(e) => {
                                                        setExact(
                                                            e.target.value,
                                                        );
                                                        setTimeError(null);
                                                    }}
                                                />
                                                <InputError
                                                    role="alert"
                                                    message={
                                                        exact
                                                            ? (timeError ??
                                                              undefined)
                                                            : undefined
                                                    }
                                                />
                                                <p
                                                    id="maintenance-preview-utc-help"
                                                    className="text-caption"
                                                >
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
                                        value={nzTime(result.requested_at)}
                                    />
                                    <ReviewRow
                                        label="Read at"
                                        value={nzTime(result.as_of)}
                                    />
                                    <ReviewRow
                                        label="Monitor"
                                        value={result.monitor.name}
                                    />
                                    <ReviewRow
                                        label="Monitor on"
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
                                            'This result doesn’t show an applicable rule.'}
                                    </p>
                                    {r.winner && (
                                        <>
                                            <ReviewRow
                                                label="Winning rule"
                                                value={r.winner.snapshot.name}
                                            />
                                            <ReviewRow
                                                label="Applies to"
                                                value={scopeLabel(
                                                    r.winner.snapshot,
                                                )}
                                            />
                                            <ReviewRow
                                                label="Version"
                                                value={r.winner.version}
                                            />
                                            <ReviewRow
                                                label="Schedule time zone"
                                                value={zoneLabel(
                                                    r.winner.snapshot.timezone,
                                                )}
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
                                    <ReviewRow
                                        label="Group"
                                        value={result.group.name}
                                    />
                                    <ReviewRow
                                        label="Device"
                                        value={result.device.name}
                                    />
                                    <ReviewRow
                                        label="Site"
                                        value={result.site.name}
                                    />
                                    <ReviewRow
                                        label="Monitor"
                                        value={result.monitor.name}
                                    />
                                    <ReviewRow
                                        label="Monitor type"
                                        value={monitorKindLabel(
                                            result.monitor.kind,
                                        )}
                                    />
                                    <ReviewRow
                                        label="Monitor on"
                                        value={
                                            result.monitor.is_enabled
                                                ? 'Yes'
                                                : 'No'
                                        }
                                    />
                                    <ReviewRow
                                        label="Time checked"
                                        value={nzTime(result.requested_at)}
                                    />
                                    <ReviewRow
                                        label="Read at"
                                        value={nzTime(result.as_of)}
                                    />
                                    <ReviewRow
                                        label="Result"
                                        value={states[r.state]}
                                    />
                                    <ReviewRow
                                        label="Why"
                                        value={
                                            reasons[r.reason_code ?? ''] ??
                                            'Not recorded'
                                        }
                                    />
                                    <TechnicalDetails
                                        rows={[
                                            ['Group ID', result.group.id],
                                            ['Device ID', result.device.id],
                                            [
                                                'Device identifier',
                                                result.device.device_uid,
                                            ],
                                            ['Site ID', result.site.id],
                                            ['Monitor ID', result.monitor.id],
                                            [
                                                'Recorded monitor type',
                                                result.monitor.kind,
                                            ],
                                            [
                                                'Exact chosen instant',
                                                result.requested_at,
                                            ],
                                            ['Read at (UTC)', result.as_of],
                                            [
                                                'Occurrence instant',
                                                r.observed_at,
                                            ],
                                            [
                                                'Interpreted at (UTC)',
                                                r.interpreted_at,
                                            ],
                                            [
                                                'Clock uncertainty seconds',
                                                r.clock_uncertainty_seconds,
                                            ],
                                            ['Recorded result', r.state],
                                            ['Recorded reason', r.reason_code],
                                            ['Recorded basis', r.basis],
                                        ]}
                                    />
                                </ReviewCard>
                                {r.assignment && (
                                    <ReviewCard
                                        title="Recorded Site assignment"
                                        icon={Monitor}
                                    >
                                        <ReviewRow
                                            label="Assigned"
                                            value={nzTime(
                                                r.assignment.assigned_at,
                                            )}
                                        />
                                        <ReviewRow
                                            label="Released"
                                            value={
                                                r.assignment.released_at
                                                    ? nzTime(
                                                          r.assignment
                                                              .released_at,
                                                      )
                                                    : 'Still assigned'
                                            }
                                        />
                                        <ReviewRow
                                            label="Recorded"
                                            value={nzTime(
                                                r.assignment.row_created_at,
                                            )}
                                        />
                                        <TechnicalDetails
                                            rows={[
                                                [
                                                    'Assignment ID',
                                                    r.assignment.assignment_id,
                                                ],
                                                [
                                                    'Site ID',
                                                    r.assignment.site_id,
                                                ],
                                                [
                                                    'Device ID',
                                                    r.assignment.device_id,
                                                ],
                                                [
                                                    'Recorded basis',
                                                    r.assignment.basis,
                                                ],
                                                [
                                                    'Recorded assigned at',
                                                    r.assignment.assigned_at,
                                                ],
                                                [
                                                    'Recorded released at',
                                                    r.assignment.released_at,
                                                ],
                                                [
                                                    'Recorded row created at',
                                                    r.assignment.row_created_at,
                                                ],
                                            ]}
                                        />
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
