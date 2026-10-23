import {
    plainLabel,
    severityBadge,
} from '@/components/control-room/alert-record/alert-labels';
import { ControlRoomPage } from '@/components/control-room/control-room-page';
import {
    ControlRoomRowActions,
    type ControlRoomRowAction,
} from '@/components/control-room/control-room-row-actions';
import {
    TimePicker,
    displayTime,
} from '@/components/fleet-assets/maintenance/time-picker';
import {
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
} from '@/components/page';
import {
    PopupConfirm,
    PopupField,
    PopupForm,
    PopupSection,
} from '@/components/popup';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatDurationMinutes } from '@/lib/datetime';
import type { FormDataConvertible, Page } from '@inertiajs/core';
import { Link, router } from '@inertiajs/react';
import {
    ArrowUpCircle,
    CalendarClock,
    Check,
    Clock,
    FileText,
    Filter,
    Gauge,
    Pencil,
    Plus,
    Power,
    Target,
    Timer,
    ToggleRight,
} from 'lucide-react';
import { useRef, useState } from 'react';

// --- Types ---

interface ComplianceData {
    acknowledge_pct: number | null;
    response_pct: number | null;
    resolution_pct: number | null;
}

interface SlaDefinitionData {
    id: number;
    name: string;
    code: string;
    description: string | null;
    alert_types: string[];
    severities: string[];
    sources: string[];
    acknowledge_target_minutes: number | null;
    response_target_minutes: number | null;
    resolution_target_minutes: number | null;
    business_hours_only: boolean;
    business_hours: { start?: string; end?: string; days?: number[] } | null;
    escalate_on_acknowledge_breach: boolean;
    escalate_on_response_breach: boolean;
    escalate_on_resolution_breach: boolean;
    breach_notify_roles: string[];
    is_active: boolean;
    total_alerts: number;
    compliance: ComplianceData;
}

interface Props {
    slaDefinitions: SlaDefinitionData[];
    can: {
        manage: boolean;
    };
}

interface TargetForm {
    name: string;
    code: string;
    description: string;
    alert_types: string[];
    severities: string[];
    sources: string[];
    acknowledge_target_minutes: string;
    response_target_minutes: string;
    resolution_target_minutes: string;
    business_hours_only: boolean;
    business_hours_start: string;
    business_hours_end: string;
    business_hours_days: number[];
    escalate_on_acknowledge_breach: boolean;
    escalate_on_response_breach: boolean;
    escalate_on_resolution_breach: boolean;
    breach_notify_roles: string;
    is_active: boolean;
}

type StatusFilter = 'all' | 'on' | 'off';

// --- Constants ---

const BASE = '/control-room/sla';

const SEVERITY_OPTIONS = ['critical', 'high', 'medium', 'low'] as const;
const SOURCE_OPTIONS = [
    'fleet',
    'personal_tracker',
    'manual',
    'external',
    'compliance',
    'other',
] as const;
const ALERT_TYPE_OPTIONS = [
    'geofence_breach',
    'sos_alert',
    'fall_detected',
    'speed_violation',
    'wandering',
    'device_offline',
    'battery_low',
    'medication_due',
    'check_in_missed',
    'other',
] as const;

const ALERT_TYPE_LABEL: Record<string, string> = {
    geofence_breach: 'Outside a safe zone',
    sos_alert: 'SOS alert',
    fall_detected: 'Fall detected',
    speed_violation: 'Speeding',
    wandering: 'Wandering',
    device_offline: 'Device offline',
    battery_low: 'Battery low',
    medication_due: 'Medication due',
    check_in_missed: 'Missed check-in',
    other: 'Other',
};

const SOURCE_LABEL: Record<string, string> = {
    fleet: 'Fleet',
    personal_tracker: 'Personal tracker',
    manual: 'Added by staff',
    external: 'Another system',
    compliance: 'Compliance',
    other: 'Other',
};

const DAYS = [1, 2, 3, 4, 5, 6, 7] as const;
const DAY_LABEL: Record<number, string> = {
    1: 'Mon',
    2: 'Tue',
    3: 'Wed',
    4: 'Thu',
    5: 'Fri',
    6: 'Sat',
    7: 'Sun',
};

const STATUS_OPTIONS = [
    { value: 'all', label: 'On and off' },
    { value: 'on', label: 'On' },
    { value: 'off', label: 'Off' },
];

// --- Helpers ---

const typeLabel = (value: string) => ALERT_TYPE_LABEL[value] ?? plainLabel(value);
const sourceLabel = (value: string) => SOURCE_LABEL[value] ?? plainLabel(value);

/** Every known option, plus any value already saved on the target, so a save never drops it. */
function withSaved(options: readonly string[], saved: string[]): string[] {
    return [...options, ...saved.filter((value) => !options.includes(value))];
}

function daysLabel(days: number[]): string {
    const sorted = [...new Set(days)].sort((a, b) => a - b);
    if (sorted.length === 7) return 'Every day';
    const run =
        sorted.length > 2 &&
        sorted.every((day, index) => index === 0 || day === sorted[index - 1] + 1);
    if (run) return `${DAY_LABEL[sorted[0]]}–${DAY_LABEL[sorted[sorted.length - 1]]}`;
    return sorted.map((day) => DAY_LABEL[day]).join(', ');
}

function hoursLabel(sla: SlaDefinitionData): string {
    const hours = sla.business_hours ?? {};
    return [
        hours.days?.length ? daysLabel(hours.days) : null,
        hours.start && hours.end
            ? `${displayTime(hours.start)} – ${displayTime(hours.end)}`
            : null,
    ]
        .filter(Boolean)
        .join(', ');
}

function joinOr(words: string[]): string {
    if (words.length <= 1) return words.join('');
    return `${words.slice(0, -1).join(', ')} or ${words[words.length - 1]}`;
}

function escalationLabel(sla: SlaDefinitionData): string {
    const missed = [
        sla.escalate_on_acknowledge_breach ? 'acknowledge' : null,
        sla.escalate_on_response_breach ? 'response' : null,
        sla.escalate_on_resolution_breach ? 'resolution' : null,
    ].filter((word): word is string => word !== null);
    if (missed.length === 0) return "Doesn't escalate";
    if (missed.length === 3) return 'Escalates when any target is missed';
    return `Escalates when the ${joinOr(missed)} target is missed`;
}

function emptyForm(): TargetForm {
    return {
        name: '',
        code: '',
        description: '',
        alert_types: [],
        severities: [],
        sources: [],
        acknowledge_target_minutes: '',
        response_target_minutes: '',
        resolution_target_minutes: '',
        business_hours_only: false,
        business_hours_start: '08:00',
        business_hours_end: '18:00',
        business_hours_days: [1, 2, 3, 4, 5],
        escalate_on_acknowledge_breach: true,
        escalate_on_response_breach: true,
        escalate_on_resolution_breach: true,
        breach_notify_roles: '',
        is_active: true,
    };
}

function toForm(sla: SlaDefinitionData): TargetForm {
    return {
        name: sla.name,
        code: sla.code,
        description: sla.description ?? '',
        alert_types: sla.alert_types,
        severities: sla.severities,
        sources: sla.sources,
        acknowledge_target_minutes: sla.acknowledge_target_minutes?.toString() ?? '',
        response_target_minutes: sla.response_target_minutes?.toString() ?? '',
        resolution_target_minutes: sla.resolution_target_minutes?.toString() ?? '',
        business_hours_only: sla.business_hours_only,
        business_hours_start: sla.business_hours?.start ?? '08:00',
        business_hours_end: sla.business_hours?.end ?? '18:00',
        business_hours_days: sla.business_hours?.days ?? [1, 2, 3, 4, 5],
        escalate_on_acknowledge_breach: sla.escalate_on_acknowledge_breach,
        escalate_on_response_breach: sla.escalate_on_response_breach,
        escalate_on_resolution_breach: sla.escalate_on_resolution_breach,
        breach_notify_roles: (sla.breach_notify_roles ?? []).join(', '),
        is_active: sla.is_active,
    };
}

/** The same request body the page has always sent. */
function toPayload(form: TargetForm): Record<string, FormDataConvertible> {
    const whole = (value: string) => (value ? parseInt(value, 10) : null);
    const payload: Record<string, unknown> = {
        name: form.name,
        code: form.code,
        description: form.description || null,
        alert_types: form.alert_types.length > 0 ? form.alert_types : null,
        severities: form.severities.length > 0 ? form.severities : null,
        sources: form.sources.length > 0 ? form.sources : null,
        acknowledge_target_minutes: whole(form.acknowledge_target_minutes),
        response_target_minutes: whole(form.response_target_minutes),
        resolution_target_minutes: whole(form.resolution_target_minutes),
        business_hours_only: form.business_hours_only,
        business_hours: form.business_hours_only
            ? {
                  start: form.business_hours_start,
                  end: form.business_hours_end,
                  days: form.business_hours_days,
              }
            : null,
        escalate_on_acknowledge_breach: form.escalate_on_acknowledge_breach,
        escalate_on_response_breach: form.escalate_on_response_breach,
        escalate_on_resolution_breach: form.escalate_on_resolution_breach,
        breach_notify_roles: form.breach_notify_roles
            ? form.breach_notify_roles
                  .split(',')
                  .map((role) => role.trim())
                  .filter(Boolean)
            : null,
        is_active: form.is_active,
    };
    return payload as Record<string, FormDataConvertible>;
}

/** Safety checks an edit turns off — flagged in the pop-up and saved in red. */
function loosenedChecks(before: TargetForm, after: TargetForm): string[] {
    const loosened: string[] = [];
    if (before.is_active && !after.is_active) loosened.push('turns this target off');
    const escalation: Array<[keyof TargetForm, string]> = [
        ['escalate_on_acknowledge_breach', 'acknowledge'],
        ['escalate_on_response_breach', 'response'],
        ['escalate_on_resolution_breach', 'resolution'],
    ];
    const stopped = escalation
        .filter(([key]) => before[key] === true && after[key] === false)
        .map(([, word]) => word);
    if (stopped.length) {
        loosened.push(`stops escalating a missed ${joinOr(stopped)} target`);
    }
    return loosened;
}

type Errors = Record<string, string | undefined>;

/**
 * Save through Inertia and close only when the server took it. A flash
 * error (which Inertia still reports as success) keeps the pop-up open
 * with the message; field errors show under their fields.
 */
function useSave(onDone: () => void) {
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Errors>({});
    const [error, setError] = useState<string | undefined>();
    const send = (
        method: 'post' | 'put',
        url: string,
        data: Record<string, FormDataConvertible> = {},
    ) => {
        setProcessing(true);
        setError(undefined);
        router[method](url, data, {
            preserveScroll: true,
            onSuccess: (page: Page) => {
                const flash = (page.props as { flash?: { error?: string } }).flash;
                if (flash?.error) setError(flash.error);
                else onDone();
            },
            onError: (next: Errors) => {
                setErrors(next);
                if (Object.keys(next).length === 0)
                    setError('This could not be saved. Your entries are kept — try again.');
            },
            onFinish: () => setProcessing(false),
        });
    };
    return { send, processing, errors, error };
}

// --- Small parts ---

/** A switch with its On / Off word (DESIGN.md settings pattern). */
function SwitchRow({
    id,
    label,
    hint,
    checked,
    onChange,
}: {
    id: string;
    label: string;
    hint?: string;
    checked: boolean;
    onChange: (on: boolean) => void;
}) {
    return (
        <div className="flex items-start justify-between gap-4 rounded-[10px] border border-border px-3.5 py-2.5 sm:col-span-full">
            <div className="min-w-0">
                <label htmlFor={id} className="text-sm font-semibold text-foreground">
                    {label}
                </label>
                {hint ? <p className="text-caption mt-0.5">{hint}</p> : null}
            </div>
            <span className="flex shrink-0 items-center gap-2">
                <span className="text-sm text-muted-foreground" aria-hidden="true">
                    {checked ? 'On' : 'Off'}
                </span>
                <Switch id={id} checked={checked} onCheckedChange={onChange} />
            </span>
        </div>
    );
}

/** Tick chips for "any of these" choices; none ticked means all. */
function Chips({
    label,
    options,
    value,
    onChange,
    format,
}: {
    label: string;
    options: readonly string[];
    value: string[];
    onChange: (next: string[]) => void;
    format: (value: string) => string;
}) {
    return (
        <ToggleGroup
            type="multiple"
            variant="outline"
            aria-label={label}
            value={value}
            onValueChange={onChange}
            className="flex flex-wrap justify-start gap-1.5 rounded-none shadow-none"
        >
            {options.map((option) => (
                <ToggleGroupItem
                    key={option}
                    value={option}
                    className="rounded-full! border! px-3 data-[state=on]:border-primary"
                >
                    {value.includes(option) ? (
                        <Check aria-hidden="true" className="size-3.5" />
                    ) : null}
                    {format(option)}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}

/** On/off in a list: the switch with its word. */
function OnOff({
    label,
    checked,
    onChange,
}: {
    label: string;
    checked: boolean;
    onChange: (on: boolean) => void;
}) {
    return (
        <span className="inline-flex items-center gap-2">
            <Switch aria-label={label} checked={checked} onCheckedChange={onChange} />
            <span className="text-sm text-muted-foreground" aria-hidden="true">
                {checked ? 'On' : 'Off'}
            </span>
        </span>
    );
}

// --- Add / edit pop-up ---

function ResponseTargetPopup({
    target,
    onClose,
}: {
    target: SlaDefinitionData | null;
    onClose: () => void;
}) {
    const [initial] = useState<TargetForm>(() =>
        target ? toForm(target) : emptyForm(),
    );
    const [form, setForm] = useState<TargetForm>(initial);
    const save = useSave(onClose);
    const set = <K extends keyof TargetForm>(key: K, value: TargetForm[K]) =>
        setForm((current) => ({ ...current, [key]: value }));
    const dirty = JSON.stringify(form) !== JSON.stringify(initial);
    const loosened = target ? loosenedChecks(initial, form) : [];
    const errors = save.errors;

    const submit = () =>
        save.send(
            target ? 'put' : 'post',
            target ? `${BASE}/${target.id}` : BASE,
            toPayload(form),
        );

    const minutesField = (
        key:
            | 'acknowledge_target_minutes'
            | 'response_target_minutes'
            | 'resolution_target_minutes',
        id: string,
        label: string,
        placeholder: string,
    ) => (
        <PopupField label={label} htmlFor={id} error={errors[key]}>
            <Input
                id={id}
                type="number"
                min={1}
                max={43200}
                inputMode="numeric"
                value={form[key]}
                onChange={(e) => set(key, e.target.value)}
                placeholder={placeholder}
            />
        </PopupField>
    );

    const escalateBox = (
        key:
            | 'escalate_on_acknowledge_breach'
            | 'escalate_on_response_breach'
            | 'escalate_on_resolution_breach',
        id: string,
        label: string,
    ) => (
        <label
            htmlFor={id}
            className="flex items-center gap-2.5 text-sm text-foreground sm:col-span-full"
        >
            <Checkbox
                id={id}
                checked={form[key]}
                onCheckedChange={(on) => set(key, on === true)}
            />
            {label}
        </label>
    );

    return (
        <PopupForm
            open
            onClose={onClose}
            size="lg"
            icon={Timer}
            title={target ? 'Edit response target' : 'Add response target'}
            description="How quickly matching alerts must be acknowledged, responded to and resolved."
            error={save.error}
            callout={
                loosened.length
                    ? {
                          tone: 'warning',
                          content: `This change ${joinOr(loosened)}. Late responses to matching alerts may not be flagged.`,
                      }
                    : undefined
            }
            submitLabel="Save target"
            processing={save.processing}
            submitDisabled={!form.name.trim() || !form.code.trim()}
            destructive={loosened.length > 0}
            onSubmit={submit}
            dirty={dirty}
            draftName={target ? `changes to ${target.name}` : 'a new response target'}
            testId="response-target-popup"
        >
            <PopupSection icon={FileText} title="Details" columns={2}>
                <PopupField label="Name" htmlFor="target-name" required error={errors.name}>
                    <Input
                        id="target-name"
                        value={form.name}
                        onChange={(e) => set('name', e.target.value)}
                        placeholder="e.g. Critical alerts"
                    />
                </PopupField>
                <PopupField
                    label="Short code"
                    htmlFor="target-code"
                    required
                    hint="Unique, shown on reports — for example CRIT-5."
                    error={errors.code}
                >
                    <Input
                        id="target-code"
                        value={form.code}
                        onChange={(e) => set('code', e.target.value)}
                        placeholder="e.g. CRIT-5"
                    />
                </PopupField>
                <PopupField
                    label="Description"
                    htmlFor="target-description"
                    hint="Optional — when this target applies."
                    error={errors.description}
                    wide
                >
                    <Textarea
                        id="target-description"
                        rows={2}
                        value={form.description}
                        onChange={(e) => set('description', e.target.value)}
                    />
                </PopupField>
            </PopupSection>

            <PopupSection
                icon={Filter}
                title="Matching criteria"
                hint="Leave a group empty to match every alert."
            >
                <PopupField label="Alert types" error={errors.alert_types}>
                    <Chips
                        label="Alert types"
                        options={withSaved(ALERT_TYPE_OPTIONS, form.alert_types)}
                        value={form.alert_types}
                        onChange={(next) => set('alert_types', next)}
                        format={typeLabel}
                    />
                </PopupField>
                <PopupField label="Severities" error={errors.severities}>
                    <Chips
                        label="Severities"
                        options={withSaved(SEVERITY_OPTIONS, form.severities)}
                        value={form.severities}
                        onChange={(next) => set('severities', next)}
                        format={plainLabel}
                    />
                </PopupField>
                <PopupField label="Where the alert comes from" error={errors.sources}>
                    <Chips
                        label="Where the alert comes from"
                        options={withSaved(SOURCE_OPTIONS, form.sources)}
                        value={form.sources}
                        onChange={(next) => set('sources', next)}
                        format={sourceLabel}
                    />
                </PopupField>
            </PopupSection>

            <PopupSection
                icon={Clock}
                title="Target times (minutes)"
                hint="Leave one empty for no target."
                columns={3}
            >
                {minutesField('acknowledge_target_minutes', 'target-ack', 'Acknowledge within', 'e.g. 5')}
                {minutesField('response_target_minutes', 'target-respond', 'Respond within', 'e.g. 15')}
                {minutesField('resolution_target_minutes', 'target-resolve', 'Resolve within', 'e.g. 60')}
            </PopupSection>

            <PopupSection icon={CalendarClock} title="Business hours" columns={2}>
                <SwitchRow
                    id="target-business-hours"
                    label="Only count business hours"
                    hint="The clock pauses outside these hours."
                    checked={form.business_hours_only}
                    onChange={(on) => set('business_hours_only', on)}
                />
                {form.business_hours_only ? (
                    <>
                        <PopupField
                            label="Start time"
                            htmlFor="target-hours-start"
                            error={errors['business_hours.start']}
                        >
                            <TimePicker
                                id="target-hours-start"
                                label="Start time"
                                value={form.business_hours_start}
                                onChange={(value) => set('business_hours_start', value)}
                                invalid={!!errors['business_hours.start']}
                            />
                        </PopupField>
                        <PopupField
                            label="End time"
                            htmlFor="target-hours-end"
                            error={errors['business_hours.end']}
                        >
                            <TimePicker
                                id="target-hours-end"
                                label="End time"
                                value={form.business_hours_end}
                                onChange={(value) => set('business_hours_end', value)}
                                invalid={!!errors['business_hours.end']}
                            />
                        </PopupField>
                        <PopupField label="Days" error={errors['business_hours.days']} wide>
                            <Chips
                                label="Days"
                                options={DAYS.map(String)}
                                value={form.business_hours_days.map(String)}
                                onChange={(next) =>
                                    set(
                                        'business_hours_days',
                                        next.map(Number).sort((a, b) => a - b),
                                    )
                                }
                                format={(day) => DAY_LABEL[Number(day)]}
                            />
                        </PopupField>
                    </>
                ) : null}
            </PopupSection>

            <PopupSection icon={ArrowUpCircle} title="Escalation on breach">
                {escalateBox(
                    'escalate_on_acknowledge_breach',
                    'target-escalate-ack',
                    'Escalate if the acknowledge target is missed',
                )}
                {escalateBox(
                    'escalate_on_response_breach',
                    'target-escalate-respond',
                    'Escalate if the response target is missed',
                )}
                {escalateBox(
                    'escalate_on_resolution_breach',
                    'target-escalate-resolve',
                    'Escalate if the resolution target is missed',
                )}
                <PopupField
                    label="Tell these roles when a target is missed"
                    htmlFor="target-notify-roles"
                    hint="Role names separated by commas, for example admin, coordinator, manager. The alert's queue roles are told as well."
                    error={errors.breach_notify_roles}
                >
                    <Input
                        id="target-notify-roles"
                        value={form.breach_notify_roles}
                        onChange={(e) => set('breach_notify_roles', e.target.value)}
                        placeholder="e.g. admin, coordinator"
                    />
                </PopupField>
            </PopupSection>

            <PopupSection icon={ToggleRight} title="Status">
                <SwitchRow
                    id="target-active"
                    label="Target is on"
                    hint="Only targets that are on are applied to new alerts."
                    checked={form.is_active}
                    onChange={(on) => set('is_active', on)}
                />
            </PopupSection>
        </PopupForm>
    );
}

// --- Turn off / turn on ---

/** Turning a target off loosens a safety check, so it is confirmed in red. */
function TurnOffTargetConfirm({
    target,
    onClose,
}: {
    target: SlaDefinitionData;
    onClose: () => void;
}) {
    const save = useSave(onClose);
    const escalates =
        target.escalate_on_acknowledge_breach ||
        target.escalate_on_response_breach ||
        target.escalate_on_resolution_breach;
    return (
        <PopupConfirm
            open
            onClose={onClose}
            onConfirm={() => save.send('post', `${BASE}/${target.id}/toggle-active`)}
            variant="warning"
            title="Turn off this response target?"
            description={
                <>
                    New alerts won&apos;t be timed against{' '}
                    <b className="font-semibold text-foreground">{target.name}</b>, so a
                    slow response to them isn&apos;t flagged
                    {escalates ? ' or escalated' : ''} by it. Another target that
                    matches may apply instead. Alerts it is already timing keep their
                    clocks, and you can turn it back on at any time.
                </>
            }
            confirmLabel="Turn off target"
            confirmDestructive
            processing={save.processing}
            error={save.error}
            testId="turn-off-target"
        />
    );
}

const turnOn = (target: SlaDefinitionData) =>
    router.post(`${BASE}/${target.id}/toggle-active`, {}, { preserveScroll: true });

// --- Main page ---

type Open =
    | { kind: 'edit'; target: SlaDefinitionData | null }
    | { kind: 'off'; target: SlaDefinitionData }
    | null;

/**
 * Control Room › Settings › Response targets — how quickly alerts must be
 * acknowledged, responded to and resolved, which alerts each target times,
 * and what happens when one is missed. Every change opens a kit pop-up;
 * turning a target off is confirmed because it loosens a safety check.
 */
export default function SlaIndex({ slaDefinitions, can }: Props) {
    const [status, setStatus] = useState<StatusFilter>('all');
    const [severity, setSeverity] = useState('all');
    const [open, setOpen] = useState<Open>(null);
    const listRef = useRef<HTMLDivElement>(null);

    const activeCount = slaDefinitions.filter((sla) => sla.is_active).length;
    const offCount = slaDefinitions.length - activeCount;
    const totalAlerts = slaDefinitions.reduce((sum, sla) => sum + sla.total_alerts, 0);

    const shown = slaDefinitions.filter(
        (sla) =>
            (status === 'all' || sla.is_active === (status === 'on')) &&
            (severity === 'all' ||
                sla.severities.length === 0 ||
                sla.severities.includes(severity)),
    );
    const filtered = status !== 'all' || severity !== 'all';

    const showList = () => {
        setStatus('all');
        setSeverity('all');
        listRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        listRef.current?.focus({ preventScroll: true });
    };

    const rowActions = (sla: SlaDefinitionData): ControlRoomRowAction[] =>
        can.manage
            ? [
                  {
                      key: 'edit',
                      label: 'Edit response target',
                      icon: Pencil,
                      onSelect: () => setOpen({ kind: 'edit', target: sla }),
                  },
                  sla.is_active
                      ? {
                            key: 'off',
                            label: 'Turn off',
                            icon: Power,
                            onSelect: () => setOpen({ kind: 'off', target: sla }),
                        }
                      : {
                            key: 'on',
                            label: 'Turn on',
                            icon: Power,
                            onSelect: () => turnOn(sla),
                        },
              ]
            : [];

    return (
        <ControlRoomPage
            headTitle="Response targets"
            header={{
                subline:
                    'How quickly alerts must be acknowledged, responded to and resolved',
                actions: (
                    <>
                        <PageHeaderGlassButton icon={Target} asChild>
                            <Link href={`${BASE}/breaches`}>Target breaches</Link>
                        </PageHeaderGlassButton>
                        {can.manage ? (
                            <PageHeaderPrimaryButton
                                icon={Plus}
                                onClick={() => setOpen({ kind: 'edit', target: null })}
                            >
                                Add response target
                            </PageHeaderPrimaryButton>
                        ) : null}
                    </>
                ),
                meters: (
                    <>
                        <PageHeaderMeterBlock
                            label="Response targets"
                            onClick={() => {
                                setStatus('all');
                                setSeverity('all');
                            }}
                            pressed={!filtered}
                            ariaLabel="Show every response target"
                        >
                            <PageHeaderMeterBig>{slaDefinitions.length}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>set up</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="On"
                            value={`${activeCount}/${slaDefinitions.length}`}
                            onClick={() => setStatus('on')}
                            pressed={status === 'on'}
                            ariaLabel="Show response targets that are on"
                        >
                            <PageHeaderMeterBig>{activeCount}</PageHeaderMeterBig>
                            <PageHeaderMeterBar
                                percent={
                                    slaDefinitions.length
                                        ? Math.round((activeCount / slaDefinitions.length) * 100)
                                        : 0
                                }
                            />
                            <PageHeaderMeterCaption>applied to new alerts</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Off"
                            tone={offCount > 0 ? 'warning' : 'brand'}
                            onClick={() => setStatus('off')}
                            pressed={status === 'off'}
                            ariaLabel="Show response targets that are off"
                        >
                            <PageHeaderMeterBig>{offCount}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>not applied to new alerts</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Alerts timed"
                            onClick={showList}
                            ariaLabel="Show how many alerts each target has timed"
                        >
                            <PageHeaderMeterBig>{totalAlerts}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>against these targets</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                ),
                filters: (
                    <>
                        <PageHeaderFilterSelect
                            icon={ToggleRight}
                            label="On and off"
                            value={status}
                            options={STATUS_OPTIONS}
                            onChange={(value) => setStatus(value as StatusFilter)}
                        />
                        <PageHeaderFilterSelect
                            icon={Gauge}
                            label="All severities"
                            value={severity}
                            options={[
                                { value: 'all', label: 'All severities' },
                                ...SEVERITY_OPTIONS.map((value) => ({
                                    value,
                                    label: plainLabel(value),
                                })),
                            ]}
                            onChange={setSeverity}
                        />
                    </>
                ),
            }}
        >
            <Card
                ref={listRef}
                tabIndex={-1}
                className="min-w-0 gap-0 overflow-hidden py-0 outline-none"
            >
                <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                    <div className="min-w-0">
                        <h2 className="text-section-title">Targets for new alerts</h2>
                        <p className="text-subtle mt-0.5">
                            A new alert takes the first target that is on and matches it. The
                            percentage under each time is how often it was met.
                        </p>
                    </div>
                    {slaDefinitions.length > 0 ? (
                        <StatusBadge variant="neutral" className="tabular-nums">
                            {filtered
                                ? `Showing ${shown.length} of ${slaDefinitions.length}`
                                : `${slaDefinitions.length} target${slaDefinitions.length === 1 ? '' : 's'}`}
                        </StatusBadge>
                    ) : null}
                </div>
                {shown.length === 0 ? (
                    <div className="px-5 py-10">
                        <EmptyState
                            icon={Timer}
                            title={
                                slaDefinitions.length === 0
                                    ? 'No response targets yet'
                                    : 'No response targets match these filters'
                            }
                            description={
                                slaDefinitions.length === 0
                                    ? 'Add one to start timing how quickly alerts are acknowledged, responded to and resolved.'
                                    : 'Change the filters to see the other targets.'
                            }
                        />
                    </div>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-5">Response target</TableHead>
                                <TableHead>Applies to</TableHead>
                                <TableHead>Acknowledge within</TableHead>
                                <TableHead>Respond within</TableHead>
                                <TableHead>Resolve within</TableHead>
                                <TableHead>Clock</TableHead>
                                <TableHead>If missed</TableHead>
                                <TableHead>On</TableHead>
                                <TableHead className="w-12 pr-3">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {shown.map((sla) => (
                                <TargetRow
                                    key={sla.id}
                                    sla={sla}
                                    canManage={can.manage}
                                    items={rowActions(sla)}
                                    onEdit={() => setOpen({ kind: 'edit', target: sla })}
                                    onTurnOff={() => setOpen({ kind: 'off', target: sla })}
                                />
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Card>

            {open?.kind === 'edit' ? (
                <ResponseTargetPopup
                    key={open.target?.id ?? 'new'}
                    target={open.target}
                    onClose={() => setOpen(null)}
                />
            ) : null}
            {open?.kind === 'off' ? (
                <TurnOffTargetConfirm target={open.target} onClose={() => setOpen(null)} />
            ) : null}
        </ControlRoomPage>
    );
}

// --- Target row ---

function TargetTime({
    minutes,
    percent,
}: {
    minutes: number | null;
    percent: number | null;
}) {
    if (!minutes) return null;
    return (
        <>
            <span className="block font-medium whitespace-nowrap text-foreground tabular-nums">
                {formatDurationMinutes(minutes)}
            </span>
            {percent !== null ? (
                <span className="text-caption block whitespace-nowrap tabular-nums">
                    {Math.round(percent)}% met
                </span>
            ) : null}
        </>
    );
}

function TargetRow({
    sla,
    canManage,
    items,
    onEdit,
    onTurnOff,
}: {
    sla: SlaDefinitionData;
    canManage: boolean;
    items: ControlRoomRowAction[];
    onEdit: () => void;
    onTurnOff: () => void;
}) {
    const matches: string[] = [];
    if (sla.alert_types.length) matches.push(sla.alert_types.map(typeLabel).join(', '));
    if (sla.sources.length) matches.push(`from ${sla.sources.map(sourceLabel).join(', ')}`);
    const matchesAll =
        !sla.severities.length && !sla.alert_types.length && !sla.sources.length;

    return (
        <ControlRoomRowActions label={`Actions for ${sla.name}`} items={items}>
            {({ rowProps, overflowButton }) => (
                <TableRow
                    {...rowProps}
                    className={
                        canManage
                            ? 'cursor-pointer outline-none focus-visible:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset'
                            : undefined
                    }
                    onClick={
                        canManage
                            ? (event) => {
                                  // The switch and the kebab handle their own clicks.
                                  if ((event.target as HTMLElement).closest('a,button'))
                                      return;
                                  onEdit();
                              }
                            : undefined
                    }
                    onKeyDown={
                        canManage
                            ? (event) => {
                                  if (event.key === 'Enter' && event.target === event.currentTarget)
                                      onEdit();
                              }
                            : undefined
                    }
                >
                    <TableCell className="pl-5">
                        <span className="block font-semibold text-foreground">{sla.name}</span>
                        <span className="text-caption block">
                            {sla.code} · {sla.total_alerts} alert
                            {sla.total_alerts === 1 ? '' : 's'} timed
                        </span>
                        {sla.description ? (
                            <span className="text-caption line-clamp-2 block max-w-[28rem]">
                                {sla.description}
                            </span>
                        ) : null}
                    </TableCell>
                    <TableCell>
                        {matchesAll ? (
                            <span className="text-subtle">All alerts</span>
                        ) : (
                            <>
                                {sla.severities.length ? (
                                    <span className="flex flex-wrap gap-1">
                                        {sla.severities.map((value) => (
                                            <StatusBadge
                                                key={value}
                                                variant={severityBadge(value).tone}
                                                size="sm"
                                            >
                                                {plainLabel(value)}
                                            </StatusBadge>
                                        ))}
                                    </span>
                                ) : null}
                                {matches.length ? (
                                    <span className="text-caption mt-1 block max-w-[18rem]">
                                        {matches.join(' · ')}
                                    </span>
                                ) : null}
                            </>
                        )}
                    </TableCell>
                    <TableCell>
                        <TargetTime
                            minutes={sla.acknowledge_target_minutes}
                            percent={sla.compliance.acknowledge_pct}
                        />
                    </TableCell>
                    <TableCell>
                        <TargetTime
                            minutes={sla.response_target_minutes}
                            percent={sla.compliance.response_pct}
                        />
                    </TableCell>
                    <TableCell>
                        <TargetTime
                            minutes={sla.resolution_target_minutes}
                            percent={sla.compliance.resolution_pct}
                        />
                    </TableCell>
                    <TableCell>
                        {sla.business_hours_only ? (
                            <>
                                <span className="block whitespace-nowrap text-foreground">
                                    Business hours
                                </span>
                                {hoursLabel(sla) ? (
                                    <span className="text-caption block whitespace-nowrap">
                                        {hoursLabel(sla)}
                                    </span>
                                ) : null}
                            </>
                        ) : (
                            <span className="text-subtle whitespace-nowrap">Around the clock</span>
                        )}
                    </TableCell>
                    <TableCell>
                        <span className="block max-w-[14rem] text-foreground">
                            {escalationLabel(sla)}
                        </span>
                        {sla.breach_notify_roles.length ? (
                            <span className="text-caption block max-w-[14rem]">
                                Tells {sla.breach_notify_roles.join(', ')}
                            </span>
                        ) : null}
                    </TableCell>
                    <TableCell>
                        {canManage ? (
                            <OnOff
                                label={`Response target ${sla.name} on`}
                                checked={sla.is_active}
                                onChange={(on) => (on ? turnOn(sla) : onTurnOff())}
                            />
                        ) : (
                            <StatusBadge variant={sla.is_active ? 'success' : 'neutral'} size="sm">
                                {sla.is_active ? 'On' : 'Off'}
                            </StatusBadge>
                        )}
                    </TableCell>
                    <TableCell className="pr-3 text-right">{overflowButton}</TableCell>
                </TableRow>
            )}
        </ControlRoomRowActions>
    );
}
