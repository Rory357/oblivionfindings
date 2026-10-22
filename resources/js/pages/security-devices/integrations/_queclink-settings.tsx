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
                const response = event.detail.response as unknown as {
                    status?: number;
                    config?: { method?: string; url?: string };
                };
                const active = owned.current;
                if (
                    !active ||
                    new URL(response.config?.url ?? '', window.location.href)
                        .pathname !== active.path ||
                    response.config?.method?.toLowerCase() !== active.method
                )
                    return;
                event.preventDefault();
                if (
                    response.status === 401 ||
                    response.status === 403 ||
                    response.status === 404
                )
                    onDenied();
                else {
                    setErrors({
                        response:
                            'This response did not confirm the request. Refresh current records before trying again.',
                    });
                    setUncertain();
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
                                    label="Canonical Device"
                                    value={target.canonical_device_id}
                                />
                                <ReviewRow
                                    label="Section"
                                    value={
                                        kind === 'advanced'
                                            ? definition.label
                                            : kind
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
    const submit = () =>
        request.run(
            'post',
            `${hubPath}/presets`,
            { name, description, sections },
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
                                  preset.name === name &&
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
                                <Label className="flex gap-2">
                                    <input
                                        type="checkbox"
                                        checked={includeTracking}
                                        disabled={request.busy}
                                        onChange={(event) =>
                                            setIncludeTracking(
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    Global tracking
                                </Label>
                                <Label className="flex gap-2">
                                    <input
                                        type="checkbox"
                                        checked={includeServer}
                                        disabled={request.busy}
                                        onChange={(event) =>
                                            setIncludeServer(
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    Server connection
                                </Label>
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
                            label="Canonical Device"
                            value={target.canonical_device_id}
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
            <ReviewRow label="Preset ID" value={preset.id} />
            <ReviewRow label="Name" value={preset.name} />
            <ReviewRow label="Slug" value={preset.slug} />
            <ReviewRow label="Description" value={preset.description} />
            <ReviewRow label="Target category" value={preset.target_category} />
            <ReviewRow
                label="Source"
                value={preset.is_system ? 'Built-in' : 'Custom'}
            />
            <ReviewRow label="Sections" value={preset.sections.join(', ')} />
            <ReviewRow label="Profile UUID" value={preset.profile_uuid} />
            <ReviewRow
                label="Profile version"
                value={
                    preset.profile_version
                        ? `Profile v${preset.profile_version}`
                        : null
                }
            />
            <ReviewRow label="Payload hash" value={preset.payload_hash} />
            <ReviewRow label="Created" value={stamp(preset.created_at)} />
        </>
    );
}
function CommandBadge({ command }: { command: RecentCommand }) {
    return (
        <StatusBadge
            variant={
                command.status === 'failed'
                    ? 'critical'
                    : command.status === 'sent'
                      ? 'warning'
                      : command.status === 'queued' ||
                          command.status === 'acked'
                        ? 'info'
                        : 'neutral'
            }
        >
            {command.status === 'acked' ? 'Acknowledged' : command.status}
        </StatusBadge>
    );
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
        [retiring, setRetiring] = useState<Preset | null>(null);
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
                                label="Execution"
                                value={
                                    command.governed
                                        ? 'Device Management'
                                        : 'Legacy provider-console history'
                                }
                            />
                            <ReviewRow
                                label="Failure category"
                                value={command.failure_category}
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
                                value={config?.sections.join(', ')}
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
                            <ReviewRow label="Tracker ID" value={target.id} />
                            <ReviewRow
                                label="Reference"
                                value={target.reference}
                            />
                            <ReviewRow
                                label="Canonical Device"
                                value={target.canonical_device_id}
                            />
                            <ReviewRow
                                label="Pairing state"
                                value={target.status}
                            />
                            <ReviewRow
                                label="Connection state"
                                value={target.connection_state}
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
                                value={target.assignment?.type}
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
                      onClick: () =>
                          request.run(
                              'post',
                              `${hubPath}/commands/${command.id}/cancel`,
                              {},
                          ),
                  },
              ]
            : []),
    ];
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
                          ? 'Select a tracker linked to a canonical Device.'
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
            <Card>
                <CardHeader>
                    <CardTitle>Device settings</CardTitle>
                    <CardDescription>
                        Pair a Queclink device before configuring device
                        settings.
                    </CardDescription>
                </CardHeader>
            </Card>
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
                            preset.sections.join(', ') || 'Not recorded',
                    },
                    {
                        key: 'version',
                        label: 'Version',
                        width: '110px',
                        cell: (preset) =>
                            preset.profile_version
                                ? `Profile v${preset.profile_version}`
                                : 'Not recorded',
                    },
                    {
                        key: 'history',
                        label: 'History',
                        width: '1.2fr',
                        cell: (preset) =>
                            'retired_at' in preset
                                ? `${stamp(preset.retired_at)} · ${preset.retired_by ?? 'Actor not recorded'} · ${preset.retirement_reason ?? 'Reason not recorded'}`
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
                                        {section}
                                    </EntityChip>
                                ))}
                            </>
                        }
                        footer={{
                            primary: preset.profile_version
                                ? `Profile v${preset.profile_version}`
                                : 'Version not recorded',
                            secondary:
                                'retired_at' in preset ? (
                                    <>
                                        {stamp(preset.retired_at)} ·{' '}
                                        {preset.retired_by ??
                                            'Actor not recorded'}
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
                                <SelectTrigger id={targetInputId}>
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
                            label="Connection health"
                            value={target?.connection_state}
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
                        ? `Configuration observed. Sections: ${config.sections.join(', ') || 'none reported'}. Values are protected.`
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
                    <p className="text-sm text-muted-foreground">
                        No presets yet. Prepare Server or Global draft values,
                        then save them as a reusable preset.
                    </p>
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
                    caption={`${commands.length} recorded commands shown`}
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
                                    label: 'Execution',
                                    width: '1fr',
                                    cell: (command) =>
                                        command.governed
                                            ? 'Device Management'
                                            : 'Legacy history',
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
                                            ? 'Governed Device execution detail · content protected'
                                            : command.status === 'queued'
                                              ? 'Legacy provider-console command · cancellation only · content protected'
                                              : 'Legacy provider-console history · read only · content protected',
                                        secondary: `ACK: ${stamp(command.acked_at)}`,
                                    }}
                                />
                            ))}
                        </EntityCardGrid>
                    )
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No command history is returned for this tracker.
                    </p>
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
                title="Discard this retirement draft?"
                description="Your reason will be lost."
                confirmText="Discard draft"
            />
        </>
    );
}
