import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    EntityCard,
    EntityCardGrid,
    EntityChip,
    EntityContextMenu,
    EntityTable,
    ListCaption,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { formatDateTimeLong, formatRelative } from '@/lib/datetime';
import { type Page } from '@inertiajs/core';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Archive,
    BookMarked,
    Clock,
    Database,
    Eye,
    Gauge,
    Inbox,
    Loader2,
    Play,
    RefreshCw,
    Save,
    Send,
    Server,
    Settings2,
    ShieldCheck,
    Smartphone,
    XCircle,
} from 'lucide-react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useId,
    useMemo,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import type {
    AdvancedCommandDefinition,
    AdvancedField,
    Device,
    GlobalSettingsForm,
    Preset,
    Props,
    RecentCommand,
    RetiredPreset,
    SectionReadOption,
    ServerSettingsForm,
} from './queclink-hub';

type Authority = {
    user?: { id: number } | null;
    can?: {
        securityDevices?: {
            viewAny?: boolean;
            integrationsManage?: boolean;
            devicesView?: boolean;
        };
    };
};
type Fields = Record<string, string>;
type Payload = Record<
    string,
    string | string[] | Fields | Record<string, Fields>
>;
const hubPath = '/security-devices/integrations/queclink';
const refreshWorkspace = () =>
    router.get(hubPath, {}, { preserveState: false, preserveScroll: true });
const stamp = (value: string | null | undefined) =>
    value
        ? `${formatRelative(value, Date.now(), 'Not recorded')} · ${formatDateTimeLong(value)}`
        : 'Not recorded';

// ── Plain labels for returned keys ─────────────────────────────────
// Raw enum values never reach the page (DESIGN.md "Auditor and developer
// language"); an unknown key falls back to sentence-case words.

export function plainLabel(
    value: string | null | undefined,
    fallback = 'Not recorded',
): string {
    const words = (value ?? '').replace(/[_-]+/g, ' ').trim().toLowerCase();
    return words ? words.charAt(0).toUpperCase() + words.slice(1) : fallback;
}
const ASSIGNMENT_TYPE_LABELS: Record<string, string> = {
    vehicle: 'Vehicle',
    staff: 'Staff member',
    client: 'Client',
    site: 'Site',
    room: 'Room',
};
export const assignmentTypeLabel = (type: string | null | undefined) =>
    type ? (ASSIGNMENT_TYPE_LABELS[type] ?? plainLabel(type)) : 'Not recorded';
export const pairingStateLabel = (status: Device['status']) =>
    status === 'paired'
        ? 'Paired'
        : status === 'pending'
          ? 'Pending claim'
          : 'Rejected';
export const connectionStateLabel = (
    state: Device['connection_state'] | null | undefined,
) =>
    state === 'connected'
        ? 'Connected'
        : state === 'disconnected'
          ? 'Disconnected'
          : 'Not recorded';
const COMMAND_STATUS_LABELS: Record<RecentCommand['status'], string> = {
    queued: 'Queued',
    sent: 'Sent',
    acked: 'Acknowledged',
    failed: 'Failed',
    expired: 'Expired',
    cancelled: 'Cancelled',
};
export function CommandStatusBadge({
    status,
}: {
    status: RecentCommand['status'];
}) {
    return (
        <StatusBadge
            variant={
                status === 'failed'
                    ? 'critical'
                    : status === 'sent'
                      ? 'warning'
                      : status === 'queued' || status === 'acked'
                        ? 'info'
                        : 'neutral'
            }
        >
            {COMMAND_STATUS_LABELS[status] ?? plainLabel(status)}
        </StatusBadge>
    );
}
const PRESET_SECTION_LABELS: Record<string, string> = {
    server: 'Server connection',
    tracking: 'Global tracking',
    pin: 'SIM PIN',
    dog: 'Watchdog',
    time: 'Time zone',
    non_movement: 'Non-movement detection',
    power: 'Power saving',
    wifi: 'Wi-Fi',
    geo: 'On-device geofence',
    bluetooth: 'Bluetooth',
    beacons: 'BLE accessories',
    allowlist: 'Phone allow-list',
    firmware_update: 'Firmware update',
    firmware_version: 'Firmware version',
};
const presetSections = (sections: string[]) =>
    sections
        .map((section) => PRESET_SECTION_LABELS[section] ?? plainLabel(section))
        .join(', ');
/** Names for the tracker section codes a configuration readback lists. */
export function sectionReadLabels(
    sections: string[],
    options: SectionReadOption[],
): string {
    return sections
        .map(
            (code) =>
                options.find((option) => option.code === code)?.label ?? code,
        )
        .join(', ');
}
const TARGET_CATEGORY_LABELS: Record<string, string> = {
    personal_tracker: 'Personal trackers',
    vehicle_tracker: 'Vehicle trackers',
    all: 'All trackers',
};
const deviceRecord = (device: Device) =>
    device.canonical_device_id
        ? `Device #${device.canonical_device_id}`
        : 'Not linked to a device record';
const presetVersion = (preset: Preset) =>
    preset.profile_version
        ? `Version ${preset.profile_version}`
        : 'Version not recorded';

/**
 * Tracker pickers list the paired trackers returned on the current page of
 * results (25 at a time), not every paired tracker. Say so, and point to the
 * header search, which searches every tracker on the server.
 */
export const pageScopeHint = (shown: number, total: number) =>
    `Showing the ${shown} paired tracker${shown === 1 ? '' : 's'} on this page of results, out of ${total}. To choose a different tracker, use Search devices at the top of the page.`;

// ── Replies that are not a page ────────────────────────────────────

export type RequestFailure = 'denied' | 'not_saved' | 'refused' | 'unconfirmed';
type FailedResponse = {
    status?: number;
    config?: { method?: string; url?: string };
    request?: { responseURL?: string };
};
function replyPath(url: string | undefined): string | null {
    if (!url) return null;
    try {
        return new URL(url, window.location.href).pathname;
    } catch {
        return null;
    }
}
/**
 * What a non-page reply to a Queclink request means for the person.
 * 401/403, or a refused read of the hub page itself, is lost access: private
 * records are cleared. A 404 or 409/422 for a write that was not redirected
 * means the server refused it and nothing changed, so the form stays and the
 * person can try again. Anything else (server error, a refused follow-up page)
 * may have been recorded and must be confirmed by refreshing.
 */
export function requestFailure(response: FailedResponse): RequestFailure {
    const status = response.status ?? 0;
    if (status === 401 || status === 403) return 'denied';
    const requested = replyPath(response.config?.url);
    const final = replyPath(response.request?.responseURL) ?? requested;
    if (status === 404 && final === hubPath) return 'denied';
    const method = (response.config?.method ?? 'get').toLowerCase();
    const redirected = final !== requested;
    if (method !== 'get' && !redirected) {
        if (status === 404) return 'not_saved';
        if (status === 409 || status === 422) return 'refused';
    }
    return 'unconfirmed';
}
export const REQUEST_FAILURE_MESSAGES: Record<
    Exclude<RequestFailure, 'denied'>,
    string
> = {
    not_saved:
        'This change was not saved. The record may have changed or been removed. Refresh current records, then try again.',
    refused:
        'This change was not accepted, so nothing was saved. Refresh current records to check the current state, then try again.',
    unconfirmed:
        'This response did not confirm the request. Refresh current records before trying again.',
};
const enabled: Array<[string, string]> = [
    ['1', 'Enabled'],
    ['0', 'Disabled'],
];
const field = (
    name: string,
    label: string,
    type: AdvancedField['type'] = 'text',
    options?: Array<[string, string]>,
): AdvancedField => ({ name, label, type, options });
const serverFields: AdvancedField[][] = [
    [
        field('main_host', 'Main host'),
        field('main_port', 'Main port', 'number'),
        field('backup_host', 'Backup host'),
        field('backup_port', 'Backup port', 'number'),
        field('sms_gateway', 'SMS gateway'),
        field('heartbeat_interval_minutes', 'Heartbeat minutes', 'number'),
        field('psm_network_hold_time_seconds', 'PSM hold seconds', 'number'),
    ],
    [
        field('report_mode', 'Report mode', 'text', [
            ['3', 'TCP long connection'],
            ['7', 'TCP long + backup'],
            ['2', 'TCP short forced'],
        ]),
        field('sack_enable', 'SACK', 'text', [
            ['1', 'Enable and check'],
            ['2', 'Enable no serial check'],
            ['0', 'Disabled'],
        ]),
        field('manual_netreg', 'Manual network', 'text', enabled),
        field('buffer_mode', 'Buffer mode', 'text', [
            ['1', 'Low priority'],
            ['2', 'High priority'],
            ['0', 'Disabled'],
        ]),
        field('sms_ack_enable', 'SMS acknowledgement', 'text', enabled),
        field('protocol_format', 'Protocol format', 'number'),
    ],
];
const globalFields: AdvancedField[][] = [
    [
        field('device_name', 'Device name'),
        field(
            'continuous_send_interval_seconds',
            'Send interval seconds',
            'number',
        ),
        field('mode_selection', 'Mode', 'text', [
            ['1', 'Continuous'],
            ['0', 'Power saving'],
        ]),
        field('gnss_timeout_seconds', 'GNSS timeout seconds', 'number'),
        field('gnss_enable', 'GNSS', 'text', enabled),
        field('agps_mode', 'AGPS', 'text', enabled),
        field('wifi_report', 'Wi-Fi fallback', 'text', [
            ['2', 'Report Wi-Fi if GNSS fails'],
            ['1', 'Always report GTFRI'],
            ['4', 'Only Wi-Fi report'],
            ['8', 'Wi-Fi assisted position'],
        ]),
        field('report_item_mask', 'Report item mask'),
        field('event_mask', 'Event mask'),
    ],
    [
        field('led_on', 'LEDs', 'text', [
            ['1', 'Normal'],
            ['0', 'Reduced GNSS LED'],
            ['2', 'Mostly off'],
        ]),
        field('start_mode', 'Start mode', 'text', [
            ['0', 'First wakeup at time'],
            ['1', 'Wake by interval'],
            ['2', 'Wake by both'],
        ]),
        field('specified_time_of_day', 'Specified time'),
        field('wakeup_interval_hours', 'Wakeup interval hours', 'number'),
        field('battery_low_percentage', 'Battery low percentage', 'number'),
        field('gsm_report', 'GSM report mask'),
        field('charge_standby_mode', 'Charge standby', 'text', enabled),
        field('function_button_mode', 'Function button mode', 'number'),
        field('sos_report_mode', 'SOS report mode', 'number'),
    ],
];

function FieldsReader({
    values,
    fields,
}: {
    values: Fields;
    fields: AdvancedField[];
}) {
    const known = new Set(fields.map((item) => item.name));
    return (
        <>
            {[
                ...fields,
                ...Object.keys(values)
                    .filter((key) => !known.has(key))
                    .map((key) => field(key, key.replaceAll('_', ' '))),
            ].map((item) => (
                <ReviewRow
                    key={item.name}
                    label={item.label}
                    value={
                        item.options?.find(
                            (option) => option[0] === values[item.name],
                        )
                            ? `${item.options.find((option) => option[0] === values[item.name])![1]} (${values[item.name]})`
                            : values[item.name]
                    }
                />
            ))}
        </>
    );
}
function EditableField({
    definition,
    value,
    onChange,
    error,
    disabled,
}: {
    definition: AdvancedField;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    disabled: boolean;
}) {
    const id = useId();
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>{definition.label}</Label>
            {definition.options ? (
                <Select
                    value={value}
                    onValueChange={onChange}
                    disabled={disabled}
                >
                    <SelectTrigger
                        id={id}
                        aria-invalid={Boolean(error)}
                        aria-describedby={error ? `${id}-error` : undefined}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {definition.options.map(([key, label]) => (
                            <SelectItem key={key} value={key}>
                                {label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            ) : definition.type === 'textarea' ? (
                <Textarea
                    id={id}
                    value={value}
                    placeholder={definition.placeholder}
                    disabled={disabled}
                    aria-invalid={Boolean(error)}
                    aria-describedby={error ? `${id}-error` : undefined}
                    onChange={(event) => onChange(event.target.value)}
                />
            ) : (
                <Input
                    id={id}
                    type={definition.type === 'number' ? 'number' : 'text'}
                    value={value}
                    disabled={disabled}
                    aria-invalid={Boolean(error)}
                    aria-describedby={error ? `${id}-error` : undefined}
                    autoComplete="off"
                    onChange={(event) => onChange(event.target.value)}
                />
            )}
            {error && (
                <p id={`${id}-error`} className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}

type RequestLedger = {
    uncertain: boolean;
    isLocked: () => boolean;
    markUncertain: () => void;
};
const RequestLedgerContext = createContext<RequestLedger | null>(null);
export function useQueclinkRequestLedger(): RequestLedger {
    const [uncertain, setUncertain] = useState(false);
    const locked = useRef(false);
    const isLocked = useCallback(() => locked.current, []);
    const markUncertain = useCallback(() => {
        locked.current = true;
        setUncertain(true);
    }, []);
    return useMemo(
        () => ({ uncertain, isLocked, markUncertain }),
        [uncertain, isLocked, markUncertain],
    );
}
export function QueclinkRequestBoundary({
    ledger,
    children,
}: {
    ledger: RequestLedger;
    children: ReactNode;
}) {
    return (
        <RequestLedgerContext.Provider value={ledger}>
            {children}
        </RequestLedgerContext.Provider>
    );
}
function targetIdentity(device: Device | null): string | null {
    return device
        ? JSON.stringify([
              device.id,
              device.canonical_device_id ?? null,
              device.status,
              device.assignment?.type ?? null,
              device.assignment?.assigned_at ?? null,
          ])
        : null;
}
function presetIdentity(preset: Preset): string {
    return JSON.stringify([
        preset.id,
        preset.profile_uuid ?? null,
        preset.profile_version ?? null,
        preset.payload_hash ?? null,
        preset.target_category,
        preset.is_system,
        [...preset.sections].sort(),
    ]);
}

function useQueclinkRequest(actor: number, onDenied: () => void) {
    const inheritedLedger = useContext(RequestLedgerContext);
    const fallbackLedger = useQueclinkRequestLedger();
    const ledger = inheritedLedger ?? fallbackLedger;
    const [busy, setBusy] = useState(false),
        [errors, setErrors] = useState<Record<string, string>>({});
    const uncertain = ledger.uncertain;
    const setUncertain = ledger.markUncertain;
    const owned = useRef<{
        path: string;
        method: 'post' | 'delete';
        settled: boolean;
        cancel?: () => void;
    } | null>(null);
    const mounted = useRef(true);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
            const request = owned.current;
            owned.current = null;
            if (request && !request.settled) setUncertain();
            request?.cancel?.();
        };
    }, [setUncertain]);
    useEffect(
        () =>
            router.on('invalid', (event) => {
                const response = event.detail
                    .response as unknown as FailedResponse;
                const active = owned.current;
                if (
                    !active ||
                    replyPath(response.config?.url) !== active.path ||
                    response.config?.method?.toLowerCase() !== active.method
                )
                    return;
                event.preventDefault();
                const failure = requestFailure(response);
                if (failure === 'denied') onDenied();
                else if (failure === 'unconfirmed') {
                    setErrors({ response: REQUEST_FAILURE_MESSAGES[failure] });
                    setUncertain();
                } else {
                    // The server refused the write: nothing changed, so the
                    // draft stays and the person may try again.
                    active.settled = true;
                    setErrors({ response: REQUEST_FAILURE_MESSAGES[failure] });
                }
            }),
        [onDenied, setUncertain],
    );
    const run = (
        method: 'post' | 'delete',
        path: string,
        payload: Payload,
        success: (page: Page) => boolean = () => true,
        finish?: () => void,
    ) => {
        if (owned.current || ledger.isLocked() || !actor) return;
        const request = {
            path,
            method,
            settled: false,
            cancel: undefined as undefined | (() => void),
        };
        owned.current = request;
        setBusy(true);
        setErrors({});
        const current = () => mounted.current && owned.current === request;
        const options = {
            preserveScroll: true,
            onCancelToken: (token: { cancel: () => void }) => {
                request.cancel = token.cancel;
            },
            onSuccess: (page: Page) => {
                if (!current() || ledger.isLocked()) return;
                const auth = page.props.auth as Authority | undefined;
                if (
                    auth?.user?.id !== actor ||
                    auth.can?.securityDevices?.viewAny !== true ||
                    auth.can?.securityDevices?.integrationsManage !== true
                ) {
                    if (
                        auth &&
                        (auth.user?.id !== actor ||
                            auth.can?.securityDevices?.viewAny === false ||
                            auth.can?.securityDevices?.integrationsManage ===
                                false)
                    )
                        onDenied();
                    else {
                        setUncertain();
                        setErrors({
                            response:
                                'Refresh current records to confirm this request before trying again.',
                        });
                    }
                    return;
                }
                if (success(page)) request.settled = true;
                else {
                    setUncertain();
                    setErrors({
                        response:
                            'The response did not confirm this request. Refresh current records before trying again.',
                    });
                }
            },
            onError: (next: Record<string, string>) => {
                if (current()) {
                    request.settled = true;
                    setErrors(next);
                }
            },
            onCancel: () => {
                if (current()) {
                    setUncertain();
                    setErrors({
                        response:
                            'The response was interrupted. Refresh current records before trying again.',
                    });
                }
            },
            onFinish: () => {
                if (current()) {
                    if (!request.settled) ledger.markUncertain();
                    owned.current = null;
                    setBusy(false);
                    finish?.();
                }
            },
        };
        if (method === 'post') router.post(path, payload, options);
        else router.delete(path, { ...options, data: payload });
    };
    return { busy, errors, uncertain, run };
}
type Request = ReturnType<typeof useQueclinkRequest>;
function RequestNotice({ request }: { request: Request }) {
    return Object.keys(request.errors).length || request.uncertain ? (
        <Alert role="alert" variant="destructive">
            <AlertDescription>
                <ul>
                    {Object.entries(
                        Object.keys(request.errors).length
                            ? request.errors
                            : {
                                  response:
                                      'A previous request has not been confirmed. Refresh current records before trying again.',
                              },
                    ).map(([key, value]) => (
                        <li key={key}>{value}</li>
                    ))}
                </ul>
                {request.uncertain && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={refreshWorkspace}
                        disabled={request.busy}
                    >
                        Refresh current records
                    </Button>
                )}
            </AlertDescription>
        </Alert>
    ) : null;
}

function SettingsEditor({
    kind,
    target,
    initial,
    definitions,
    payloadFor,
    actor,
    onDenied,
    onClose,
    onKeep,
}: {
    kind: 'server' | 'global' | 'advanced';
    target: Device;
    initial: Fields;
    definitions: AdvancedCommandDefinition[];
    payloadFor: (
        definition: AdvancedCommandDefinition,
        values: Fields,
    ) => Record<string, string | string[]>;
    actor: number;
    onDenied: () => void;
    onClose: () => void;
    onKeep: (values: Fields) => void;
}) {
    const [step, setStep] = useState(0),
        [definition, setDefinition] = useState(definitions[0]),
        [values, setValues] = useState(initial),
        [discard, setDiscard] = useState(false),
        [nextDefinition, setNextDefinition] =
            useState<AdvancedCommandDefinition | null>(null);
    const request = useQueclinkRequest(actor, onDenied);
    const [sourceInitial] = useState(initial);
    const baseline = kind === 'advanced' ? definition.defaults : sourceInitial;
    const dirty = JSON.stringify(values) !== JSON.stringify(baseline);
    const groups = useMemo(
        () =>
            kind === 'server'
                ? serverFields
                : kind === 'global'
                  ? globalFields
                  : [[], definition.fields],
        [kind, definition],
    );
    const steps = (
        kind === 'server'
            ? ['Connection', 'Delivery', 'Review']
            : kind === 'global'
              ? ['Tracking', 'Alerts & power', 'Review']
              : ['Section', 'Settings', 'Review']
    ).map((label, index) => ({
        key: String(index),
        label,
        blurb: index === 2 ? 'All values and target' : 'Prepare draft values',
        icon: index === 2 ? ShieldCheck : Settings2,
        disabled: request.busy,
    }));
    const [requiredError, setRequiredError] = useState('');
    const validate = () => {
        const valid =
            kind === 'server'
                ? Boolean(values.main_host?.trim())
                : kind === 'global'
                  ? Boolean(values.device_name?.trim())
                  : true;
        setRequiredError(
            valid
                ? ''
                : kind === 'server'
                  ? 'Main host is required.'
                  : 'Device name is required.',
        );
        if (!valid) setStep(0);
        return valid;
    };
    const title =
        kind === 'server'
            ? 'Edit server connection'
            : kind === 'global'
              ? 'Edit global tracking'
              : 'Edit advanced GL30 sections';
    const close = () => {
        if (request.busy) return;
        if (dirty) setDiscard(true);
        else onClose();
    };
    const choose = (next: AdvancedCommandDefinition) => {
        if (next.key === definition.key) return;
        if (dirty) setNextDefinition(next);
        else {
            setDefinition(next);
            setValues({ ...next.defaults });
        }
    };
    const submit = () => {
        if (!validate()) return;
        const section = kind === 'advanced' ? definition.section : kind;
        request.run(
            'post',
            `${hubPath}/devices/${target.id}/configuration/${section}`,
            kind === 'advanced' ? payloadFor(definition, values) : values,
            (page) => {
                const url = new URL(page.url, 'https://local.invalid');
                const confirmed =
                    url.pathname ===
                        `/security-devices/devices/${target.canonical_device_id}` &&
                    url.searchParams.get('section') === 'management' &&
                    url.searchParams.get('action') === 'configuration.apply';
                if (confirmed) onClose();
                return confirmed;
            },
        );
    };
    useEffect(() => {
        if (Object.keys(request.errors).length && !request.uncertain)
            setStep(
                kind === 'advanced'
                    ? 1
                    : groups[1].some((item) => item.name in request.errors)
                      ? 1
                      : 0,
            );
    }, [request.errors, request.uncertain, kind, groups]);
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={title}
                description="Prepare values and continue to Device Management for approval and application."
                railIcon={Settings2}
                railTitle={title}
                railSub={target.reference}
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => !request.busy && setStep(index)}
                pct={Math.round(
                    (Object.values(values).filter(Boolean).length /
                        Math.max(Object.keys(values).length, 1)) *
                        100,
                )}
                pctLabel="Draft fields"
                footerStart={
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={request.busy}
                            onClick={close}
                        >
                            Cancel
                        </Button>
                        {step > 0 && (
                            <Button
                                type="button"
                                variant="ghost"
                                disabled={request.busy}
                                onClick={() => setStep(step - 1)}
                            >
                                Back
                            </Button>
                        )}
                    </>
                }
                footerEnd={
                    step < 2 ? (
                        <Button
                            type="button"
                            disabled={request.busy}
                            onClick={() => {
                                if (validate()) setStep(step + 1);
                            }}
                        >
                            Continue
                        </Button>
                    ) : (
                        <>
                            {kind !== 'advanced' && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={request.busy || request.uncertain}
                                    onClick={() => {
                                        if (validate()) {
                                            onKeep(values);
                                            onClose();
                                        }
                                    }}
                                >
                                    Keep draft
                                </Button>
                            )}
                            <Button
                                type="button"
                                disabled={
                                    request.busy ||
                                    request.uncertain ||
                                    !target.canonical_device_id
                                }
                                onClick={submit}
                            >
                                {request.busy && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}
                                Continue to Device Management
                            </Button>
                        </>
                    )
                }
            >
                <WizardStepPane key={step}>
                    <RequestNotice request={request} />
                    {kind === 'advanced' && (
                        <p className="text-sm text-muted-foreground">
                            {definition.description}
                        </p>
                    )}
                    {requiredError && (
                        <Alert role="alert" variant="destructive">
                            <AlertDescription>{requiredError}</AlertDescription>
                        </Alert>
                    )}
                    {kind === 'advanced' && step === 1 && (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={request.busy}
                            onClick={() =>
                                dirty
                                    ? setNextDefinition(definition)
                                    : setValues({ ...definition.defaults })
                            }
                        >
                            Revert fields
                        </Button>
                    )}
                    {step === 0 && kind === 'advanced' ? (
                        <div className="grid gap-3 sm:grid-cols-2">
                            {definitions.map((item) => (
                                <Button
                                    key={item.key}
                                    type="button"
                                    variant={
                                        item.key === definition.key
                                            ? 'default'
                                            : 'outline'
                                    }
                                    className="h-auto min-h-16 justify-start text-left whitespace-normal"
                                    disabled={request.busy}
                                    onClick={() => choose(item)}
                                >
                                    {item.label}
                                </Button>
                            ))}
                        </div>
                    ) : step < 2 ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            {groups[step].map((item) => (
                                <EditableField
                                    key={item.name}
                                    definition={item}
                                    value={values[item.name] ?? ''}
                                    error={request.errors[item.name]}
                                    disabled={request.busy}
                                    onChange={(value) =>
                                        setValues((current) => ({
                                            ...current,
                                            [item.name]: [
                                                'event_mask',
                                                'report_item_mask',
                                                'gsm_report',
                                            ].includes(item.name)
                                                ? value.toUpperCase()
                                                : value,
                                        }))
                                    }
                                />
                            ))}
                        </div>
                    ) : (
                        <>
                            <ReviewCard
                                icon={ShieldCheck}
                                title="Target and review"
                            >
                                <ReviewRow
                                    label="Tracker"
                                    value={target.reference}
                                />
                                <ReviewRow
                                    label="Device record"
                                    value={deviceRecord(target)}
                                />
                                <ReviewRow
                                    label="Section"
                                    value={
                                        kind === 'advanced'
                                            ? definition.label
                                            : kind === 'server'
                                              ? 'Server connection'
                                              : 'Global tracking'
                                    }
                                />
                                <ReviewRow
                                    label="Observed configuration"
                                    value={
                                        target.configuration?.state ===
                                        'observed'
                                            ? stamp(
                                                  target.configuration
                                                      .observed_at,
                                              )
                                            : 'No readback recorded'
                                    }
                                />
                                <p className="mt-3 text-sm text-muted-foreground">
                                    These are draft values. Continue in Device
                                    Management to review access, tracking
                                    consent and the approved change before
                                    application.
                                </p>
                            </ReviewCard>
                            <ReviewCard
                                icon={Settings2}
                                title="Complete draft values"
                                onEdit={() =>
                                    setStep(kind === 'advanced' ? 1 : 0)
                                }
                            >
                                <FieldsReader
                                    values={values}
                                    fields={groups.flat()}
                                />
                            </ReviewCard>
                        </>
                    )}
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard || nextDefinition !== null}
                onClose={() => {
                    setDiscard(false);
                    setNextDefinition(null);
                }}
                variant="destructive"
                title="Discard this settings draft?"
                description="Your edits will be lost."
                confirmText="Discard draft"
                onConfirm={() => {
                    if (nextDefinition) {
                        setDefinition(nextDefinition);
                        setValues({ ...nextDefinition.defaults });
                        setNextDefinition(null);
                    } else onClose();
                }}
            />
        </>
    );
}

function SavePresetWizard({
    server,
    global,
    actor,
    existingIds,
    onDenied,
    onClose,
}: {
    server: Fields;
    global: Fields;
    actor: number;
    existingIds: number[];
    onDenied: () => void;
    onClose: () => void;
}) {
    const [step, setStep] = useState(0),
        [name, setName] = useState(''),
        [description, setDescription] = useState(''),
        [includeServer, setIncludeServer] = useState(false),
        [includeTracking, setIncludeTracking] = useState(true),
        [discard, setDiscard] = useState(false),
        [saved, setSaved] = useState<Preset | null>(null);
    const [capturedServer] = useState(server),
        [capturedGlobal] = useState(global);
    const request = useQueclinkRequest(actor, onDenied);
    const sectionsId = useId();
    const dirty = Boolean(
        name || description || includeServer || !includeTracking,
    );
    const close = () => {
        if (request.busy) return;
        if (dirty && !saved) setDiscard(true);
        else onClose();
    };
    const sections: Record<string, Fields> = {
        ...(includeTracking ? { tracking: { ...capturedGlobal } } : {}),
        ...(includeServer ? { server: { ...capturedServer } } : {}),
    };
    // The server trims every string (TrimStrings), so the saved name is
    // compared with the trimmed entry, never the raw typed value.
    const savedName = name.trim();
    const submit = () =>
        request.run(
            'post',
            `${hubPath}/presets`,
            { name: savedName, description: description.trim(), sections },
            (page) => {
                if (
                    page.component ===
                        'security-devices/integrations/queclink-hub' &&
                    typeof page.props.actor_id === 'number' &&
                    page.props.actor_id !== actor
                ) {
                    onDenied();
                    return false;
                }
                const presets = page.props.presets as Preset[] | undefined;
                const authoritative =
                    page.component ===
                        'security-devices/integrations/queclink-hub' &&
                    page.props.actor_id === actor
                        ? presets?.find(
                              (preset) =>
                                  !existingIds.includes(preset.id) &&
                                  preset.id > 0 &&
                                  typeof preset.profile_uuid === 'string' &&
                                  preset.profile_uuid.length > 0 &&
                                  typeof preset.profile_version === 'number' &&
                                  preset.profile_version > 0 &&
                                  typeof preset.payload_hash === 'string' &&
                                  preset.payload_hash.length > 0 &&
                                  preset.name === savedName &&
                                  preset.sections.length ===
                                      Object.keys(sections).length &&
                                  Object.keys(sections).every((section) =>
                                      preset.sections.includes(section),
                                  ),
                          )
                        : undefined;
                if (authoritative) setSaved(authoritative);
                return Boolean(authoritative);
            },
        );
    useEffect(() => {
        if (!request.uncertain && Object.keys(request.errors).length)
            setStep(0);
    }, [request.errors, request.uncertain]);
    const steps = [
        {
            key: 'identity',
            label: 'Name & sections',
            blurb: 'Name this reusable profile',
            icon: BookMarked,
            disabled: request.busy,
        },
        {
            key: 'review',
            label: 'Review',
            blurb: 'Every included field',
            icon: ShieldCheck,
            disabled: request.busy,
        },
    ];
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title="Save configuration preset"
                description="Save the current draft values as a reusable preset."
                railIcon={BookMarked}
                railTitle="Save configuration preset"
                railSub="Reusable settings"
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => !request.busy && setStep(index)}
                pct={name.trim() && Object.keys(sections).length ? 100 : 0}
                footerStart={
                    <Button
                        type="button"
                        variant="outline"
                        onClick={close}
                        disabled={request.busy}
                    >
                        Cancel
                    </Button>
                }
                footerEnd={
                    step === 0 ? (
                        <Button
                            type="button"
                            disabled={
                                !name.trim() ||
                                !Object.keys(sections).length ||
                                request.busy
                            }
                            onClick={() => setStep(1)}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            disabled={
                                !name.trim() ||
                                !Object.keys(sections).length ||
                                request.busy ||
                                request.uncertain
                            }
                            onClick={submit}
                        >
                            {request.busy && (
                                <Loader2 className="size-4 animate-spin" />
                            )}
                            Save preset
                        </Button>
                    )
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Preset saved"
                            blurb={`${saved.name} is available for review and application.`}
                            actions={<Button onClick={onClose}>Done</Button>}
                        />
                    ) : undefined
                }
            >
                <WizardStepPane key={step}>
                    <RequestNotice request={request} />
                    {step === 0 ? (
                        <div className="space-y-4">
                            <EditableField
                                definition={field('name', 'Name')}
                                value={name}
                                onChange={setName}
                                error={request.errors.name}
                                disabled={request.busy}
                            />
                            <EditableField
                                definition={field(
                                    'description',
                                    'Description',
                                    'textarea',
                                )}
                                value={description}
                                onChange={setDescription}
                                error={request.errors.description}
                                disabled={request.busy}
                            />
                            <fieldset className="space-y-3">
                                <legend className="text-sm font-medium">
                                    Sections to include
                                </legend>
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id={`${sectionsId}-tracking`}
                                        checked={includeTracking}
                                        disabled={request.busy}
                                        onCheckedChange={(checked) =>
                                            setIncludeTracking(checked === true)
                                        }
                                    />
                                    <Label htmlFor={`${sectionsId}-tracking`}>
                                        Global tracking
                                    </Label>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id={`${sectionsId}-server`}
                                        checked={includeServer}
                                        disabled={request.busy}
                                        onCheckedChange={(checked) =>
                                            setIncludeServer(checked === true)
                                        }
                                    />
                                    <Label htmlFor={`${sectionsId}-server`}>
                                        Server connection
                                    </Label>
                                </div>
                            </fieldset>
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <ReviewCard
                                icon={BookMarked}
                                title="Preset identity"
                                onEdit={() => setStep(0)}
                            >
                                <ReviewRow label="Name" value={name} />
                                <ReviewRow
                                    label="Description"
                                    value={description}
                                />
                            </ReviewCard>
                            {includeTracking && (
                                <ReviewCard
                                    icon={Gauge}
                                    title="Global tracking"
                                >
                                    <FieldsReader
                                        values={capturedGlobal}
                                        fields={globalFields.flat()}
                                    />
                                </ReviewCard>
                            )}
                            {includeServer && (
                                <ReviewCard
                                    icon={Server}
                                    title="Server connection"
                                >
                                    <FieldsReader
                                        values={capturedServer}
                                        fields={serverFields.flat()}
                                    />
                                </ReviewCard>
                            )}
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                variant="destructive"
                title="Discard this preset draft?"
                description="Your preset name and section choices will be lost."
                confirmText="Discard draft"
            />
        </>
    );
}

function ApplicationWizard({
    target,
    preset,
    actor,
    onDenied,
    onClose,
}: {
    target: Device;
    preset: Preset | null;
    actor: number;
    onDenied: () => void;
    onClose: () => void;
}) {
    const request = useQueclinkRequest(actor, onDenied),
        [step, setStep] = useState(0);
    const title = preset
        ? `Review ${preset.name}`
        : 'Review resident safety profile';
    const submit = () =>
        request.run(
            'post',
            preset
                ? `${hubPath}/devices/${target.id}/presets/${preset.id}/apply`
                : `${hubPath}/devices/${target.id}/configuration/resident-safety-profile`,
            {},
            (page) => {
                const url = new URL(page.url, 'https://local.invalid');
                const confirmed =
                    url.pathname ===
                        `/security-devices/devices/${target.canonical_device_id}` &&
                    url.searchParams.get('section') === 'management' &&
                    url.searchParams.get('action') === 'configuration.apply';
                if (confirmed) onClose();
                return confirmed;
            },
        );
    return (
        <WizardShell
            open
            onClose={() => !request.busy && onClose()}
            title={title}
            description="Review this profile and target before continuing to Device Management."
            railIcon={ShieldCheck}
            railTitle={title}
            railSub={target.reference}
            steps={[
                {
                    key: 'profile',
                    label: 'Profile',
                    blurb: 'Identity and included sections',
                    icon: BookMarked,
                    disabled: request.busy,
                },
                {
                    key: 'target',
                    label: 'Target & review',
                    blurb: 'Selected tracker and approvals',
                    icon: ShieldCheck,
                    disabled: request.busy,
                },
            ]}
            stepIndex={step}
            onStepClick={(index) => !request.busy && setStep(index)}
            pct={100}
            footerStart={
                <Button
                    variant="outline"
                    disabled={request.busy}
                    onClick={onClose}
                >
                    Cancel
                </Button>
            }
            footerEnd={
                step === 0 ? (
                    <Button onClick={() => setStep(1)}>Continue</Button>
                ) : (
                    <Button
                        disabled={
                            request.busy ||
                            request.uncertain ||
                            !target.canonical_device_id
                        }
                        onClick={submit}
                    >
                        {request.busy && (
                            <Loader2 className="size-4 animate-spin" />
                        )}
                        Continue to governed review
                    </Button>
                )
            }
        >
            <WizardStepPane key={step}>
                <RequestNotice request={request} />
                {step === 0 ? (
                    <ReviewCard icon={BookMarked} title="Profile identity">
                        {preset ? (
                            <PresetRows preset={preset} />
                        ) : (
                            <>
                                <ReviewRow
                                    label="Profile"
                                    value="Resident safety"
                                />
                                <p className="text-sm text-muted-foreground">
                                    Review the current resident safety profile
                                    in Device Management.
                                </p>
                            </>
                        )}
                    </ReviewCard>
                ) : (
                    <ReviewCard
                        icon={ShieldCheck}
                        title="Target and application"
                    >
                        <ReviewRow label="Tracker" value={target.reference} />
                        <ReviewRow
                            label="Device record"
                            value={deviceRecord(target)}
                        />
                        <ReviewRow
                            label="Assignment"
                            value={target.assignment?.label}
                        />
                        <p className="mt-3 text-sm text-muted-foreground">
                            Continue to Device Management to review the Site,
                            tracking consent, approved change and independent
                            approval. Device readback confirms the result after
                            application.
                        </p>
                    </ReviewCard>
                )}
            </WizardStepPane>
        </WizardShell>
    );
}

function PresetRows({ preset }: { preset: Preset }) {
    return (
        <>
            <ReviewRow label="Name" value={preset.name} />
            <ReviewRow label="Description" value={preset.description} />
            <ReviewRow
                label="Suitable for"
                value={
                    TARGET_CATEGORY_LABELS[preset.target_category] ??
                    plainLabel(preset.target_category)
                }
            />
            <ReviewRow
                label="Source"
                value={preset.is_system ? 'Built-in' : 'Custom'}
            />
            <ReviewRow
                label="Settings included"
                value={presetSections(preset.sections) || 'None recorded'}
            />
            <ReviewRow label="Version" value={presetVersion(preset)} />
            <ReviewRow
                label="Saved settings reference"
                value={
                    preset.profile_uuid ? (
                        <span className="text-caption break-all">
                            {preset.profile_uuid}
                        </span>
                    ) : null
                }
            />
            <ReviewRow label="Created" value={stamp(preset.created_at)} />
        </>
    );
}
function CommandBadge({ command }: { command: RecentCommand }) {
    return <CommandStatusBadge status={command.status} />;
}
type Reader = {
    targetIdentity?: string | null;
    presetIdentity?: string;
    presetId?: number;
    retired?: boolean;
    commandId?: number;
    title: string;
    sections: Array<{ label: string; body: ReactNode }>;
};
function SettingsReader({
    reader,
    onClose,
}: {
    reader: Reader;
    onClose: () => void;
}) {
    const [section, setSection] = useState(0);
    return (
        <WizardShell
            open
            onClose={onClose}
            title={reader.title}
            description="Recorded facts and complete field values."
            railIcon={Database}
            railTitle={reader.title}
            railSub="Recorded details"
            steps={reader.sections.map((item, index) => ({
                key: String(index),
                label: item.label,
                blurb: 'Complete details',
                icon: Database,
            }))}
            stepIndex={section}
            onStepClick={setSection}
            headerLabel={reader.sections[section].label}
            sequential={false}
            pct={null}
            footerEnd={<Button onClick={onClose}>Close</Button>}
        >
            <WizardStepPane key={section}>
                {reader.sections[section].body}
            </WizardStepPane>
        </WizardShell>
    );
}

export function QueclinkSettingsWorkspace({
    devices,
    total,
    listener,
    presets,
    retiredPresets = [],
    can,
    layout = 'cards',
    onAccessDenied,
    serverDefaults,
    globalDefaults,
    definitions,
    readOptions,
    payloadFor,
}: {
    devices: Device[];
    /** Every paired tracker matching the current search, across all pages. */
    total?: number;
    listener: Props['listener'];
    presets: Preset[];
    retiredPresets?: RetiredPreset[];
    can: Props['can'];
    layout?: 'cards' | 'table';
    onAccessDenied?: () => void;
    serverDefaults: (
        device: Device | null,
        listener: Props['listener'],
    ) => ServerSettingsForm;
    globalDefaults: (device: Device | null) => GlobalSettingsForm;
    definitions: AdvancedCommandDefinition[];
    readOptions: SectionReadOption[];
    payloadFor: (
        definition: AdvancedCommandDefinition,
        values: Fields,
    ) => Record<string, string | string[]>;
}) {
    const inheritedLedger = useContext(RequestLedgerContext);
    const fallbackLedger = useQueclinkRequestLedger();
    const ledger = inheritedLedger ?? fallbackLedger;
    const { auth } = usePage<{ auth?: Authority }>().props;
    const [sourceActor] = useState(auth?.user?.id ?? 0),
        [denied, setDenied] = useState(false);
    const visible =
        !denied &&
        sourceActor > 0 &&
        auth?.user?.id === sourceActor &&
        auth.can?.securityDevices?.viewAny === true &&
        auth.can?.securityDevices?.integrationsManage === true;
    if (!visible)
        return (
            <Alert>
                <AlertDescription>
                    Queclink settings are restricted. Refresh after your access
                    changes.
                    <Button variant="outline" onClick={refreshWorkspace}>
                        Refresh current records
                    </Button>
                </AlertDescription>
            </Alert>
        );
    return (
        <QueclinkRequestBoundary ledger={ledger}>
            <SettingsWorkspaceBody
                key={JSON.stringify([auth.can?.securityDevices, can.manage])}
                devices={devices}
                total={total ?? devices.length}
                listener={listener}
                presets={presets}
                retiredPresets={retiredPresets}
                manage={
                    can.manage &&
                    auth.can?.securityDevices?.integrationsManage === true
                }
                profiles={auth.can?.securityDevices?.devicesView === true}
                layout={layout}
                actor={sourceActor}
                onDenied={() => {
                    setDenied(true);
                    onAccessDenied?.();
                }}
                serverDefaults={serverDefaults}
                globalDefaults={globalDefaults}
                definitions={definitions}
                readOptions={readOptions}
                payloadFor={payloadFor}
            />
        </QueclinkRequestBoundary>
    );
}
function SettingsWorkspaceBody({
    devices,
    total,
    listener,
    presets,
    retiredPresets,
    manage,
    profiles,
    layout,
    actor,
    onDenied,
    serverDefaults,
    globalDefaults,
    definitions,
    readOptions,
    payloadFor,
}: {
    devices: Device[];
    total: number;
    listener: Props['listener'];
    presets: Preset[];
    retiredPresets: RetiredPreset[];
    manage: boolean;
    profiles: boolean;
    layout: 'cards' | 'table';
    actor: number;
    onDenied: () => void;
    serverDefaults: (
        device: Device | null,
        listener: Props['listener'],
    ) => ServerSettingsForm;
    globalDefaults: (device: Device | null) => GlobalSettingsForm;
    definitions: AdvancedCommandDefinition[];
    readOptions: SectionReadOption[];
    payloadFor: (
        definition: AdvancedCommandDefinition,
        values: Fields,
    ) => Record<string, string | string[]>;
}) {
    const [targetId, setTargetId] = useState(String(devices[0]?.id ?? ''));
    const pageLimited = total > devices.length;
    const target =
        devices.find((device) => String(device.id) === targetId) ?? null;
    const currentTargetIdentity = targetIdentity(target);
    const [formTargetIdentity, setFormTargetIdentity] = useState<string | null>(
        null,
    );
    const [drafts, setDrafts] = useState<
        Record<
            number,
            {
                identity: string;
                server: ServerSettingsForm;
                global: GlobalSettingsForm;
            }
        >
    >({});
    const currentDraft =
        target && drafts[target.id]?.identity === currentTargetIdentity
            ? drafts[target.id]
            : undefined;
    const server = target
        ? (currentDraft?.server ?? serverDefaults(target, listener))
        : serverDefaults(null, listener);
    const global = target
        ? (currentDraft?.global ?? globalDefaults(target))
        : globalDefaults(null);
    useEffect(() => {
        setDrafts((current) => {
            const retained = Object.fromEntries(
                Object.entries(current).filter(([id, draft]) =>
                    devices.some(
                        (device) =>
                            String(device.id) === id &&
                            targetIdentity(device) === draft.identity,
                    ),
                ),
            );
            return Object.keys(retained).length === Object.keys(current).length
                ? current
                : retained;
        });
    }, [devices]);
    const [editing, setEditing] = useState<
            'server' | 'global' | 'advanced' | null
        >(null),
        [saving, setSaving] = useState(false),
        [application, setApplication] = useState<{
            preset: Preset | null;
        } | null>(null),
        [reader, setReader] = useState<Reader | null>(null),
        [retiring, setRetiring] = useState<Preset | null>(null),
        [cancelling, setCancelling] = useState<{
            command: RecentCommand;
            identity: string | null;
        } | null>(null);
    const request = useQueclinkRequest(actor, onDenied);
    const configurationContext = useEntityContextMenu<{
        title: string;
        icon: typeof Settings2;
        actions: MenuItem[];
        identity: string | null;
    }>();
    const presetsContext = useEntityContextMenu<Preset | RetiredPreset>(),
        commandsContext = useEntityContextMenu<RecentCommand>();
    const [menuTargetIdentity, setMenuTargetIdentity] = useState<string | null>(
        null,
    );
    const menuTargetCurrent = menuTargetIdentity === currentTargetIdentity;
    const commands = target?.recent_commands ?? [],
        config = target?.configuration;
    const closeConfigurationMenu = configurationContext.close;
    const closePresetMenu = presetsContext.close;
    const closeCommandMenu = commandsContext.close;
    useEffect(() => {
        if (!menuTargetCurrent) {
            closeConfigurationMenu();
            closePresetMenu();
            closeCommandMenu();
        }
    }, [
        menuTargetCurrent,
        closeConfigurationMenu,
        closePresetMenu,
        closeCommandMenu,
    ]);
    const formTargetCurrent = Boolean(
        currentTargetIdentity && formTargetIdentity === currentTargetIdentity,
    );
    const currentPreset = (preset: Preset | RetiredPreset) => {
        const records = 'retired_at' in preset ? retiredPresets : presets;
        return records.find(
            (record) =>
                record.id === preset.id &&
                presetIdentity(record) === presetIdentity(preset),
        );
    };
    const applicationCurrent = Boolean(
        formTargetCurrent &&
        (!application?.preset || currentPreset(application.preset)),
    );
    const retiringCurrent = retiring
        ? [...presets, ...retiredPresets].some(
              (preset) =>
                  preset.id === retiring.id &&
                  presetIdentity(preset) === presetIdentity(retiring),
          )
        : false;
    useEffect(() => {
        if (!formTargetCurrent) {
            setEditing(null);
            setSaving(false);
            setApplication(null);
        } else if (
            application?.preset &&
            !presets.some(
                (preset) =>
                    preset.id === application.preset?.id &&
                    presetIdentity(preset) ===
                        presetIdentity(application.preset!),
            )
        )
            setApplication(null);
        if (retiring && !retiringCurrent) setRetiring(null);
    }, [formTargetCurrent, application, presets, retiring, retiringCurrent]);
    const targetInputId = useId();
    const commandReader = (command: RecentCommand) =>
        setReader({
            targetIdentity: currentTargetIdentity,
            commandId: command.id,
            title: `Command status · ${command.command_word} #${command.id}`,
            sections: [
                {
                    label: 'Delivery',
                    body: (
                        <ReviewCard icon={Send} title="Recorded command">
                            <ReviewRow label="Command ID" value={command.id} />
                            <ReviewRow
                                label="Command"
                                value={command.command_word}
                            />
                            <ReviewRow
                                label="Status"
                                value={<CommandBadge command={command} />}
                            />
                            <ReviewRow
                                label="Started from"
                                value={
                                    command.governed
                                        ? 'Device Management'
                                        : 'Older Queclink console (read only)'
                                }
                            />
                            <ReviewRow
                                label="Reason it failed"
                                value={
                                    command.failure_category
                                        ? plainLabel(command.failure_category)
                                        : null
                                }
                            />
                            <p className="mt-3 text-sm text-muted-foreground">
                                Command and acknowledgement content remain
                                protected. Review current Device state before
                                starting another action.
                            </p>
                        </ReviewCard>
                    ),
                },
                {
                    label: 'Times',
                    body: (
                        <ReviewCard icon={Clock} title="Recorded timestamps">
                            {[
                                ['Created', command.created_at],
                                ['Sent', command.sent_at],
                                ['Acknowledged', command.acked_at],
                                ['Fulfilled', command.fulfilled_at],
                                ['Cancelled', command.cancelled_at],
                                ['Expires', command.expires_at],
                            ].map(([label, value]) => (
                                <ReviewRow
                                    key={label}
                                    label={label!}
                                    value={stamp(value)}
                                />
                            ))}
                        </ReviewCard>
                    ),
                },
            ],
        });
    const presetReader = (preset: Preset | RetiredPreset) =>
        setReader({
            targetIdentity: currentTargetIdentity,
            presetId: preset.id,
            presetIdentity: presetIdentity(preset),
            retired: 'retired_at' in preset,
            title: `Preset · ${preset.name}`,
            sections: [
                {
                    label: 'Identity & sections',
                    body: (
                        <ReviewCard icon={BookMarked} title="Preset identity">
                            <PresetRows preset={preset} />
                        </ReviewCard>
                    ),
                },
                {
                    label: 'History',
                    body: (
                        <ReviewCard icon={Archive} title="Retained history">
                            <ReviewRow
                                label="State"
                                value={
                                    'retired_at' in preset
                                        ? 'Retired'
                                        : 'Active'
                                }
                            />
                            {'retired_at' in preset ? (
                                <>
                                    <ReviewRow
                                        label="Retired"
                                        value={stamp(preset.retired_at)}
                                    />
                                    <ReviewRow
                                        label="Retired by"
                                        value={preset.retired_by}
                                    />
                                    <ReviewRow
                                        label="Reason"
                                        value={preset.retirement_reason}
                                    />
                                </>
                            ) : (
                                <ReviewRow
                                    label="Created"
                                    value={stamp(preset.created_at)}
                                />
                            )}
                        </ReviewCard>
                    ),
                },
            ],
        });
    const configurationReader = () => {
        if (!target) return;
        setReader({
            targetIdentity: currentTargetIdentity,
            title: `Configuration snapshot · ${target.reference}`,
            sections: [
                {
                    label: 'Readback',
                    body: (
                        <ReviewCard
                            icon={Database}
                            title="Observed configuration"
                        >
                            <ReviewRow
                                label="State"
                                value={
                                    config?.state === 'observed'
                                        ? 'Observed'
                                        : 'Not observed'
                                }
                            />
                            <ReviewRow
                                label="Observed"
                                value={stamp(config?.observed_at)}
                            />
                            <ReviewRow
                                label="Sections"
                                value={
                                    config
                                        ? sectionReadLabels(
                                              config.sections,
                                              readOptions,
                                          )
                                        : undefined
                                }
                            />
                            <p className="mt-3 text-sm text-muted-foreground">
                                Values are protected. Request a readback in
                                Device Management to confirm the current
                                settings.
                            </p>
                        </ReviewCard>
                    ),
                },
                {
                    label: 'Tracker context',
                    body: (
                        <ReviewCard icon={Smartphone} title="Selected tracker">
                            <ReviewRow
                                label="Reference"
                                value={target.reference}
                            />
                            <ReviewRow
                                label="Device record"
                                value={deviceRecord(target)}
                            />
                            <ReviewRow
                                label="Pairing state"
                                value={pairingStateLabel(target.status)}
                            />
                            <ReviewRow
                                label="Connection"
                                value={connectionStateLabel(
                                    target.connection_state,
                                )}
                            />
                            <ReviewRow
                                label="Model"
                                value={target.model_hint}
                            />
                            <ReviewRow
                                label="Protocol"
                                value={target.protocol_version}
                            />
                            <ReviewRow
                                label="Firmware"
                                value={target.firmware_version}
                            />
                            <ReviewRow
                                label="First seen"
                                value={stamp(target.first_seen_at)}
                            />
                            <ReviewRow
                                label="Last seen"
                                value={stamp(target.last_seen_at)}
                            />
                            <ReviewRow
                                label="Last frame"
                                value={stamp(target.last_frame_at)}
                            />
                            <ReviewRow
                                label="Assignment"
                                value={target.assignment?.label}
                            />
                            <ReviewRow
                                label="Assignment type"
                                value={
                                    target.assignment
                                        ? assignmentTypeLabel(
                                              target.assignment.type,
                                          )
                                        : null
                                }
                            />
                            <ReviewRow
                                label="Assigned"
                                value={stamp(target.assignment?.assigned_at)}
                            />
                        </ReviewCard>
                    ),
                },
            ],
        });
    };
    const commandActions = (command: RecentCommand): MenuItem[] => [
        {
            label: 'Inspect command status',
            icon: Eye,
            onClick: () => commandReader(command),
        },
        ...(manage && command.status === 'queued' && !command.governed
            ? [
                  {
                      label: 'Cancel queued command',
                      icon: XCircle,
                      disabled: request.busy
                          ? 'A request is in progress.'
                          : request.uncertain
                            ? 'Refresh current records first.'
                            : undefined,
                      danger: true,
                      onClick: () =>
                          setCancelling({
                              command,
                              identity: currentTargetIdentity,
                          }),
                  },
              ]
            : []),
    ];
    // The confirmation closes itself if the command is no longer queued for
    // the same tracker (sent, cancelled elsewhere, or a different target).
    const cancellingCommand =
        cancelling &&
        cancelling.identity === currentTargetIdentity &&
        !request.busy &&
        !request.uncertain
            ? commands.find(
                  (command) =>
                      command.id === cancelling.command.id &&
                      command.status === 'queued' &&
                      !command.governed,
              )
            : undefined;
    useEffect(() => {
        if (cancelling && !cancellingCommand) setCancelling(null);
    }, [cancelling, cancellingCommand]);
    const presetActions = (preset: Preset | RetiredPreset): MenuItem[] => [
        {
            label: 'View preset details',
            icon: Eye,
            onClick: () => presetReader(preset),
        },
        ...(!('retired_at' in preset) && manage
            ? [
                  {
                      label: 'Review application',
                      icon: Play,
                      disabled: !target?.canonical_device_id
                          ? 'Select a tracker that is linked to a device record.'
                          : undefined,
                      onClick: () => {
                          setFormTargetIdentity(currentTargetIdentity);
                          setApplication({ preset });
                      },
                  },
                  ...(!preset.is_system
                      ? [
                            {
                                label: 'Retire preset',
                                icon: Archive,
                                danger: true,
                                onClick: () => setRetiring(preset),
                            },
                        ]
                      : []),
              ]
            : []),
    ];
    const readerCurrent = Boolean(
        reader &&
        reader.targetIdentity === currentTargetIdentity &&
        (!reader.commandId ||
            commands.some((command) => command.id === reader.commandId)) &&
        (!reader.presetId ||
            (reader.retired ? retiredPresets : presets).some(
                (preset) =>
                    preset.id === reader.presetId &&
                    presetIdentity(preset) === reader.presetIdentity,
            )),
    );
    const presetContextRecord = presetsContext.ctx
        ? currentPreset(presetsContext.ctx.record)
        : undefined;
    const commandContextRecord = commandsContext.ctx
        ? commands.find(
              (command) => command.id === commandsContext.ctx?.record.id,
          )
        : undefined;
    useEffect(() => {
        if (reader && !readerCurrent) setReader(null);
    }, [reader, readerCurrent]);
    if (!devices.length)
        return (
            <EmptyState
                icon={Smartphone}
                title="No paired trackers to configure"
                description="Pair a Queclink device before configuring device settings. Claim a recognised tracker from the Pending view."
            />
        );
    const presetList = (
        rows: Array<Preset | RetiredPreset>,
        retired: boolean,
    ) =>
        layout === 'table' ? (
            <EntityTable
                rows={rows}
                rowKey={(preset) => preset.id}
                identity={(preset) => ({
                    name: preset.name,
                    icon: BookMarked,
                    subline: preset.description ?? 'No description recorded',
                })}
                columns={[
                    {
                        key: 'sections',
                        label: 'Sections',
                        width: '1fr',
                        cell: (preset) =>
                            presetSections(preset.sections) || 'Not recorded',
                    },
                    {
                        key: 'version',
                        label: 'Version',
                        width: '110px',
                        cell: presetVersion,
                    },
                    {
                        key: 'history',
                        label: 'History',
                        width: '1.2fr',
                        cell: (preset) =>
                            'retired_at' in preset
                                ? `${stamp(preset.retired_at)} · ${preset.retired_by ?? 'Person not recorded'} · ${preset.retirement_reason ?? 'Reason not recorded'}`
                                : stamp(preset.created_at),
                    },
                ]}
                actionsFor={presetActions}
                onOpen={presetReader}
                onRowContextMenu={(event, preset) => (
                    setMenuTargetIdentity(currentTargetIdentity),
                    presetsContext.open(event, preset)
                )}
            />
        ) : (
            <EntityCardGrid>
                {rows.map((preset) => (
                    <EntityCard
                        key={preset.id}
                        name={preset.name}
                        icon={BookMarked}
                        meridian="neutral"
                        subline={
                            preset.description ?? 'No description recorded'
                        }
                        actions={presetActions(preset)}
                        onContextMenu={(event) => (
                            setMenuTargetIdentity(currentTargetIdentity),
                            presetsContext.open(event, preset)
                        )}
                        onOpen={() => presetReader(preset)}
                        linkLabel="View details"
                        chips={
                            <>
                                <EntityChip>
                                    {retired
                                        ? 'Retired'
                                        : preset.is_system
                                          ? 'Built-in'
                                          : 'Custom'}
                                </EntityChip>
                                {preset.sections.map((section) => (
                                    <EntityChip key={section}>
                                        {presetSections([section])}
                                    </EntityChip>
                                ))}
                            </>
                        }
                        footer={{
                            primary: presetVersion(preset),
                            secondary:
                                'retired_at' in preset ? (
                                    <>
                                        {stamp(preset.retired_at)} ·{' '}
                                        {preset.retired_by ??
                                            'Person not recorded'}
                                        <p>
                                            {preset.retirement_reason ??
                                                'Reason not recorded'}
                                        </p>
                                    </>
                                ) : (
                                    stamp(preset.created_at)
                                ),
                        }}
                    />
                ))}
            </EntityCardGrid>
        );
    return (
        <div className="space-y-5">
            <Card>
                <CardHeader>
                    <CardTitle>Device settings</CardTitle>
                    <CardDescription>
                        Protected GL30 readback and configuration profiles
                        governed through Device Management.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="min-w-64 space-y-2">
                            <Label htmlFor={targetInputId}>Target device</Label>
                            <Select
                                value={targetId}
                                onValueChange={(value) => {
                                    if (!request.busy && !request.uncertain) {
                                        setTargetId(value);
                                        setReader(null);
                                        setApplication(null);
                                        setEditing(null);
                                    }
                                }}
                                disabled={request.busy || request.uncertain}
                            >
                                <SelectTrigger
                                    id={targetInputId}
                                    aria-describedby={
                                        pageLimited
                                            ? `${targetInputId}-scope`
                                            : undefined
                                    }
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {devices.map((device) => (
                                        <SelectItem
                                            key={device.id}
                                            value={String(device.id)}
                                        >
                                            {device.reference}
                                            {device.assignment?.label
                                                ? ` — ${device.assignment.label}`
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {pageLimited && (
                                <p
                                    id={`${targetInputId}-scope`}
                                    className="text-caption max-w-md"
                                >
                                    {pageScopeHint(devices.length, total)}
                                </p>
                            )}
                        </div>
                        <Button
                            disabled={
                                !manage ||
                                !target ||
                                request.busy ||
                                request.uncertain
                            }
                            onClick={() =>
                                target &&
                                request.run(
                                    'post',
                                    `${hubPath}/devices/${target.id}/configuration/read`,
                                    { section: 'all' },
                                )
                            }
                        >
                            <RefreshCw className="size-4" />
                            Read full config
                        </Button>
                        {profiles && target?.canonical_device_id && (
                            <Button asChild variant="outline">
                                <Link
                                    href={`/security-devices/devices/${target.canonical_device_id}?section=management`}
                                >
                                    Open Device Management
                                </Link>
                            </Button>
                        )}
                    </div>
                    <div className="grid gap-x-5 sm:grid-cols-2 lg:grid-cols-3">
                        <ReviewRow
                            label="Connection"
                            value={connectionStateLabel(
                                target?.connection_state,
                            )}
                        />
                        <ReviewRow
                            label="Model"
                            value={target?.model_hint ?? 'Unknown'}
                        />
                        <ReviewRow
                            label="Last frame"
                            value={stamp(target?.last_frame_at)}
                        />
                        <ReviewRow
                            label="Config read"
                            value={
                                config?.state === 'observed'
                                    ? stamp(config.observed_at)
                                    : 'Not read yet'
                            }
                        />
                        <ReviewRow
                            label="Queued commands"
                            value={
                                commands.filter(
                                    (command) => command.status === 'queued',
                                ).length
                            }
                        />
                        <ReviewRow
                            label="Loaded command history"
                            value={commands.length}
                        />
                    </div>
                    <RequestNotice request={request} />
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>Read one section</CardTitle>
                    <CardDescription>
                        Continue to Device Management to review a configuration
                        readback.
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-wrap gap-2">
                    {readOptions.map((option) => (
                        <Button
                            key={`${option.section}-${option.code}`}
                            variant="outline"
                            disabled={
                                !manage ||
                                !target ||
                                request.busy ||
                                request.uncertain
                            }
                            onClick={() =>
                                target &&
                                request.run(
                                    'post',
                                    `${hubPath}/devices/${target.id}/configuration/${option.section}/read`,
                                    { command: option.code },
                                )
                            }
                        >
                            {option.label}
                        </Button>
                    ))}
                </CardContent>
            </Card>
            <section className="space-y-3">
                <ListCaption
                    title="Configuration drafts"
                    caption="Draft values · device values stay protected"
                />
                <EntityCardGrid>
                    {(
                        [
                            {
                                key: 'server',
                                name: 'Server connection',
                                icon: Server,
                                detail: 'Hosts, ports, heartbeat and delivery',
                            },
                            {
                                key: 'global',
                                name: 'Global tracking',
                                icon: Gauge,
                                detail: 'Cadence, positioning, alerts and power',
                            },
                            {
                                key: 'advanced',
                                name: 'Advanced GL30 sections',
                                icon: Settings2,
                                detail: `${definitions.length} supported configuration sections`,
                            },
                        ] as const
                    ).map((item) => {
                        const edit = () => {
                            setFormTargetIdentity(currentTargetIdentity);
                            setEditing(item.key);
                        };
                        const read = () =>
                            setReader({
                                targetIdentity: currentTargetIdentity,
                                title: item.name + ' draft',
                                sections:
                                    item.key === 'advanced'
                                        ? definitions.map((definition) => ({
                                              label: definition.label,
                                              body: (
                                                  <ReviewCard
                                                      icon={Settings2}
                                                      title="Complete draft values"
                                                  >
                                                      <p className="mb-3 text-sm text-muted-foreground">
                                                          {
                                                              definition.description
                                                          }
                                                      </p>
                                                      <FieldsReader
                                                          values={
                                                              definition.defaults
                                                          }
                                                          fields={
                                                              definition.fields
                                                          }
                                                      />
                                                  </ReviewCard>
                                              ),
                                          }))
                                        : [
                                              {
                                                  label: 'Draft fields',
                                                  body: (
                                                      <ReviewCard
                                                          icon={item.icon}
                                                          title="Complete draft values"
                                                      >
                                                          <FieldsReader
                                                              values={
                                                                  item.key ===
                                                                  'server'
                                                                      ? server
                                                                      : global
                                                              }
                                                              fields={
                                                                  item.key ===
                                                                  'server'
                                                                      ? serverFields.flat()
                                                                      : globalFields.flat()
                                                              }
                                                          />
                                                      </ReviewCard>
                                                  ),
                                              },
                                          ],
                            });
                        const actions: MenuItem[] = [
                            {
                                label: 'View draft values',
                                icon: Eye,
                                onClick: read,
                            },
                            ...(manage
                                ? [
                                      {
                                          label: `Edit ${item.name.toLowerCase()}`,
                                          icon: Settings2,
                                          onClick: edit,
                                      },
                                  ]
                                : []),
                        ];
                        return (
                            <EntityCard
                                key={item.key}
                                name={item.name}
                                icon={item.icon}
                                meridian="neutral"
                                subline={item.detail}
                                actions={actions}
                                onContextMenu={(event) => {
                                    setMenuTargetIdentity(
                                        currentTargetIdentity,
                                    );
                                    configurationContext.open(event, {
                                        title: item.name,
                                        icon: item.icon,
                                        actions,
                                        identity: currentTargetIdentity,
                                    });
                                }}
                                onOpen={manage ? edit : read}
                                openLabel={manage ? 'Edit draft' : 'View draft'}
                                linkLabel={
                                    manage
                                        ? `Edit ${item.name.toLowerCase()}`
                                        : 'View draft values'
                                }
                                footer={{
                                    primary: 'Draft values',
                                    secondary:
                                        'Review every field before application',
                                }}
                            />
                        );
                    })}
                </EntityCardGrid>
                <Button
                    variant="outline"
                    disabled={!manage || !target?.canonical_device_id}
                    onClick={() => {
                        setFormTargetIdentity(currentTargetIdentity);
                        setApplication({ preset: null });
                    }}
                >
                    <ShieldCheck className="size-4" />
                    Resident safety profile
                </Button>
                <Button
                    variant="outline"
                    disabled={!target}
                    onClick={configurationReader}
                >
                    View configuration snapshot
                </Button>
                <p className="text-sm text-muted-foreground">
                    {config?.state === 'observed'
                        ? `Configuration observed. Sections: ${sectionReadLabels(config.sections, readOptions) || 'none reported'}. Values are protected.`
                        : 'No configuration readback has been received yet.'}
                </p>
            </section>
            <section className="space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <ListCaption
                        title="Configuration presets"
                        caption={`${presets.length} active presets shown`}
                    />
                    <Button
                        variant="outline"
                        disabled={!manage}
                        onClick={() => {
                            setFormTargetIdentity(currentTargetIdentity);
                            setSaving(true);
                        }}
                    >
                        <Save className="size-4" />
                        Save current as preset
                    </Button>
                </div>
                {presets.length ? (
                    presetList(presets, false)
                ) : (
                    <EmptyState
                        variant="compact"
                        icon={BookMarked}
                        title="No presets yet"
                        description="Prepare server connection or global tracking draft values, then save them as a reusable preset."
                    />
                )}
            </section>
            {retiredPresets.length > 0 && (
                <section className="space-y-3">
                    <ListCaption
                        title="Retired preset history"
                        caption={`${retiredPresets.length} retained presets shown`}
                    />
                    {presetList(retiredPresets, true)}
                </section>
            )}
            <section className="space-y-3">
                <ListCaption
                    title="Command history"
                    caption={`${commands.length} recorded commands shown · new or repeated actions start from Device Management`}
                />
                {commands.length ? (
                    layout === 'table' ? (
                        <EntityTable
                            rows={commands}
                            rowKey={(command) => command.id}
                            identity={(command) => ({
                                name: command.command_word,
                                icon: Send,
                                subline: `Command #${command.id}`,
                            })}
                            columns={[
                                {
                                    key: 'status',
                                    label: 'Delivery',
                                    width: '110px',
                                    cell: (command) => (
                                        <CommandBadge command={command} />
                                    ),
                                },
                                {
                                    key: 'created',
                                    label: 'Created',
                                    width: '1fr',
                                    cell: (command) =>
                                        stamp(command.created_at),
                                },
                                {
                                    key: 'acked',
                                    label: 'Acknowledged',
                                    width: '1fr',
                                    cell: (command) => stamp(command.acked_at),
                                },
                                {
                                    key: 'execution',
                                    label: 'Started from',
                                    width: '1fr',
                                    cell: (command) =>
                                        command.governed
                                            ? 'Device Management'
                                            : 'Older Queclink console',
                                },
                            ]}
                            actionsFor={commandActions}
                            onOpen={commandReader}
                            onRowContextMenu={(event, command) => (
                                setMenuTargetIdentity(currentTargetIdentity),
                                commandsContext.open(event, command)
                            )}
                        />
                    ) : (
                        <EntityCardGrid>
                            {commands.map((command) => (
                                <EntityCard
                                    key={command.id}
                                    name={command.command_word}
                                    icon={Send}
                                    meridian={
                                        command.status === 'failed'
                                            ? 'critical'
                                            : 'neutral'
                                    }
                                    subline={`Command #${command.id} · ${stamp(command.created_at)}`}
                                    actions={commandActions(command)}
                                    onContextMenu={(event) => (
                                        setMenuTargetIdentity(
                                            currentTargetIdentity,
                                        ),
                                        commandsContext.open(event, command)
                                    )}
                                    onOpen={() => commandReader(command)}
                                    linkLabel="Inspect"
                                    chips={<CommandBadge command={command} />}
                                    footer={{
                                        primary: command.governed
                                            ? 'Started in Device Management · details protected'
                                            : command.status === 'queued'
                                              ? 'Older Queclink console command · can only be cancelled · details protected'
                                              : 'Older Queclink console history · read only · details protected',
                                        secondary: `Acknowledged: ${stamp(command.acked_at)}`,
                                    }}
                                />
                            ))}
                        </EntityCardGrid>
                    )
                ) : (
                    <EmptyState
                        variant="compact"
                        icon={Inbox}
                        title="No command history for this tracker"
                        description="Commands sent to this tracker appear here. New device actions start from Device Management."
                    />
                )}
            </section>
            {configurationContext.ctx &&
                configurationContext.ctx.record.identity ===
                    currentTargetIdentity &&
                menuTargetCurrent && (
                    <EntityContextMenu
                        x={configurationContext.ctx.x}
                        y={configurationContext.ctx.y}
                        title={configurationContext.ctx.record.title}
                        icon={configurationContext.ctx.record.icon}
                        items={configurationContext.ctx.record.actions}
                        onClose={configurationContext.close}
                    />
                )}
            {presetsContext.ctx && presetContextRecord && menuTargetCurrent && (
                <EntityContextMenu
                    x={presetsContext.ctx.x}
                    y={presetsContext.ctx.y}
                    title={presetContextRecord.name}
                    icon={BookMarked}
                    items={presetActions(presetContextRecord)}
                    onClose={presetsContext.close}
                />
            )}
            {commandsContext.ctx &&
                commandContextRecord &&
                menuTargetCurrent && (
                    <EntityContextMenu
                        x={commandsContext.ctx.x}
                        y={commandsContext.ctx.y}
                        title={`${commandContextRecord.command_word} #${commandContextRecord.id}`}
                        icon={Send}
                        items={commandActions(commandContextRecord)}
                        onClose={commandsContext.close}
                    />
                )}
            {target && editing && formTargetCurrent && manage && (
                <SettingsEditor
                    key={`${target.id}-${editing}`}
                    kind={editing}
                    target={target}
                    initial={
                        editing === 'server'
                            ? server
                            : editing === 'global'
                              ? global
                              : { ...definitions[0].defaults }
                    }
                    definitions={definitions}
                    payloadFor={payloadFor}
                    actor={actor}
                    onDenied={onDenied}
                    onClose={() => setEditing(null)}
                    onKeep={(values) => {
                        if (editing !== 'advanced')
                            setDrafts((current) => ({
                                ...current,
                                [target.id]: {
                                    identity: currentTargetIdentity!,
                                    server,
                                    global,
                                    [editing]: values,
                                },
                            }));
                    }}
                />
            )}
            {saving && formTargetCurrent && manage && (
                <SavePresetWizard
                    server={server}
                    global={global}
                    actor={actor}
                    existingIds={presets.map((preset) => preset.id)}
                    onDenied={onDenied}
                    onClose={() => setSaving(false)}
                />
            )}
            {target && application && applicationCurrent && manage && (
                <ApplicationWizard
                    key={target.id}
                    target={target}
                    preset={application.preset}
                    actor={actor}
                    onDenied={onDenied}
                    onClose={() => setApplication(null)}
                />
            )}
            {reader && readerCurrent && (
                <SettingsReader
                    reader={reader}
                    onClose={() => setReader(null)}
                />
            )}
            {retiring && retiringCurrent && manage && (
                <RetirePresetWizard
                    preset={retiring}
                    actor={actor}
                    onDenied={onDenied}
                    onClose={() => setRetiring(null)}
                />
            )}
            <ConfirmDialog
                open={Boolean(cancellingCommand && target && manage)}
                onClose={() => setCancelling(null)}
                onConfirm={() => {
                    if (cancellingCommand && manage)
                        request.run(
                            'post',
                            `${hubPath}/commands/${cancellingCommand.id}/cancel`,
                            {},
                        );
                    setCancelling(null);
                }}
                variant="destructive"
                title="Cancel this queued command?"
                description={
                    cancellingCommand && target
                        ? `${cancellingCommand.command_word} #${cancellingCommand.id} has not been sent to ${target.reference} yet. Cancelling removes it from the queue and it cannot be resumed here. Start any new device action from Device Management.`
                        : ''
                }
                confirmText="Cancel command"
                cancelText="Keep command"
            />
        </div>
    );
}

function RetirePresetWizard({
    preset,
    actor,
    onDenied,
    onClose,
}: {
    preset: Preset;
    actor: number;
    onDenied: () => void;
    onClose: () => void;
}) {
    const [step, setStep] = useState(0),
        [reason, setReason] = useState(''),
        [discard, setDiscard] = useState(false);
    const request = useQueclinkRequest(actor, onDenied);
    const close = () => {
        if (request.busy) return;
        if (reason) setDiscard(true);
        else onClose();
    };
    const submit = () =>
        request.run(
            'delete',
            hubPath + '/presets/' + preset.id,
            { reason: reason.trim() },
            (page) => {
                const retired = page.props.retiredPresets as
                    | RetiredPreset[]
                    | undefined;
                const confirmed =
                    page.component ===
                        'security-devices/integrations/queclink-hub' &&
                    page.props.actor_id === actor &&
                    retired?.some(
                        (record) =>
                            record.id === preset.id &&
                            Boolean(record.retired_at) &&
                            record.retirement_reason === reason.trim(),
                    );
                if (confirmed) onClose();
                return Boolean(confirmed);
            },
        );
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title="Retire configuration preset?"
                description="Record why this preset should no longer be available for application."
                railIcon={Archive}
                railTitle="Retire configuration preset"
                railSub={preset.name}
                steps={[
                    {
                        key: 'reason',
                        label: 'Reason',
                        blurb: 'Explain the change',
                        icon: Archive,
                        disabled: request.busy,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Preset identity and reason',
                        icon: ShieldCheck,
                        disabled: request.busy,
                    },
                ]}
                stepIndex={step}
                onStepClick={(index) => !request.busy && setStep(index)}
                pct={reason.trim() ? 100 : 0}
                footerStart={
                    <Button
                        variant="outline"
                        onClick={close}
                        disabled={request.busy}
                    >
                        Cancel
                    </Button>
                }
                footerEnd={
                    step === 0 ? (
                        <Button
                            disabled={!reason.trim() || request.busy}
                            onClick={() => setStep(1)}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            variant="destructive"
                            disabled={
                                !reason.trim() ||
                                request.busy ||
                                request.uncertain
                            }
                            onClick={submit}
                        >
                            {request.busy && (
                                <Loader2 className="size-4 animate-spin" />
                            )}
                            Retire preset
                        </Button>
                    )
                }
            >
                <WizardStepPane key={step}>
                    <RequestNotice request={request} />
                    {step === 0 ? (
                        <EditableField
                            definition={field(
                                'reason',
                                'Reason for retiring this preset',
                                'textarea',
                            )}
                            value={reason}
                            onChange={setReason}
                            error={request.errors.reason}
                            disabled={request.busy}
                        />
                    ) : (
                        <ReviewCard
                            icon={Archive}
                            title="Preset and retained history"
                        >
                            <PresetRows preset={preset} />
                            <ReviewRow label="Reason" value={reason.trim()} />
                            <p className="mt-3 text-sm text-muted-foreground">
                                This preset will be unavailable for new
                                applications. Its configuration versions and
                                history will remain available.
                            </p>
                        </ReviewCard>
                    )}
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                variant="destructive"
                title="Discard this retirement draft?"
                description="Your reason will be lost."
                confirmText="Discard draft"
            />
        </>
    );
}
