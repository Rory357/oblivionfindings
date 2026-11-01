/**
 * The Devices pop-ups (S&D rebuild drawings sdr-1, 2, 7, 8, 9, 10, 11 and 72), all on the pop-up
 * kit. They use today's device routes: add one device, edit (the device form), ping, the offline
 * check, retire (keeps history) and move. Nothing here offers a feature that isn't built.
 */
import { VehicleSearchSelect } from '@/components/fleet-assets/vehicle-workspace/search-select';
import type { MenuItem } from '@/components/lists';
import {
    PopupCallout,
    PopupConfirm,
    PopupFacts,
    PopupField,
    PopupForm,
    PopupSection,
    PopupViewer,
} from '@/components/popup';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/datetime';
import {
    deviceStateLabel,
    type DeviceState,
} from '@/lib/security-devices/device-words';
import { cn } from '@/lib/utils';
import type {
    DeviceFormData,
    DevicesCan,
} from '@/pages/security-devices/devices/_types';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    Activity,
    ArrowRightLeft,
    BellPlus,
    Box,
    Cable,
    Cctv,
    CirclePlus,
    DoorClosed,
    MapPin,
    Monitor,
    Network,
    Pencil,
    PlugZap,
    Plus,
    Radar,
    Siren,
    StickyNote,
    Tag,
    Thermometer,
    Trash2,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';

/** The device a pop-up acts on, as the Devices page knows it. */
export type PopupDevice = {
    id: number;
    name: string;
    sub?: string;
    kind?: string;
    state?: DeviceState;
    address?: string | null;
    site?: string | null;
    site_id?: number | null;
    watching?: string;
};

/* ------------------------------------------------------------------ */
/*  Shared bits                                                        */
/* ------------------------------------------------------------------ */

/** The pop-ups' options load only when one opens (an optional page prop). */
export function useDeviceFormOptions(prop = 'deviceForm') {
    const form = usePage<Record<string, unknown>>().props[prop] as
        | DeviceFormData
        | undefined;
    const load = () => {
        if (!form) router.reload({ only: [prop] });
    };
    return { form, load };
}

function contextFor(device: PopupDevice) {
    return {
        title: device.name,
        tag: device.state ? deviceStateLabel[device.state] : undefined,
        note: device.sub ?? device.site ?? undefined,
        icon: iconForKind(device.kind ?? 'other'),
    };
}

/** A failed save keeps every entry and says what went wrong in plain words. */
function firstError(error: unknown, fallback: string): string {
    if (axios.isAxiosError(error)) {
        const data = error.response?.data as
            | { message?: string; errors?: Record<string, string[]> }
            | undefined;
        const first = data?.errors ? Object.values(data.errors)[0]?.[0] : null;
        if (first) return first;
        if (error.response?.status === 403) return 'You can’t do this here.';
        if (!error.response) return 'Result not confirmed. Check the device before trying again.';
    }
    return fallback;
}

export type ChoiceTile<K extends string> = {
    value: K;
    label: string;
    description?: string;
    icon: LucideIcon;
};

/** Choice tiles (pop-up kit "choice tiles"): the active tile takes the brand tint and ring. */
export function ChoiceTiles<K extends string>({
    options,
    value,
    onChange,
    columns = 1,
    label,
}: {
    options: ChoiceTile<K>[];
    value: K | '';
    onChange: (value: K) => void;
    columns?: 1 | 2 | 3 | 4;
    label: string;
}) {
    return (
        <div
            role="group"
            aria-label={label}
            className={cn(
                'grid gap-2',
                columns === 2 && 'sm:grid-cols-2',
                columns === 3 && 'sm:grid-cols-3',
                columns === 4 && 'grid-cols-2 sm:grid-cols-4',
            )}
        >
            {options.map((option) => {
                const Icon = option.icon;
                const active = option.value === value;
                return (
                    // eslint-disable-next-line no-restricted-syntax -- a choice tile (pop-up kit), not a form button.
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={active}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'flex items-start gap-3 rounded-xl border bg-card p-3 text-left transition-colors outline-none hover:border-primary/50 focus-visible:ring-2 focus-visible:ring-ring',
                            active
                                ? 'border-primary bg-primary-fill/10 ring-1 ring-primary/40'
                                : 'border-border',
                            !option.description && 'items-center',
                        )}
                    >
                        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                            <Icon className="size-4" />
                        </span>
                        <span className="min-w-0">
                            <span
                                className={cn(
                                    'block text-sm font-semibold',
                                    active && 'text-primary',
                                )}
                            >
                                {option.label}
                            </span>
                            {option.description ? (
                                <span className="text-caption block">
                                    {option.description}
                                </span>
                            ) : null}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  1 · Add device                                                     */
/* ------------------------------------------------------------------ */

export type AddChoice = 'scan' | 'one' | 'service';

const ADD_CHOICES: (ChoiceTile<AddChoice> & { button: string })[] = [
    {
        value: 'scan',
        label: 'Find devices on a network',
        description:
            'Scan a Site and pick from what we find. Best when there are lots of devices.',
        icon: Radar,
        button: 'Continue to scan',
    },
    {
        value: 'one',
        label: 'Add one device by its address',
        description: 'You know its IP address or name',
        icon: CirclePlus,
        button: 'Continue',
    },
    {
        value: 'service',
        label: 'Connect a service',
        description:
            'UniFi, Milesight, alarms or trackers bring their devices in',
        icon: PlugZap,
        button: 'Continue to services',
    },
];

export function AddDeviceChooser({
    open,
    can,
    onClose,
    onChoose,
    onConnect,
}: {
    open: boolean;
    can: Pick<DevicesCan, 'add' | 'scan' | 'connect'>;
    onClose: () => void;
    onChoose: (choice: AddChoice) => void;
    /** Connect a service opens pop-up 15 here; without it, the old services page. */
    onConnect?: () => void;
}) {
    const options = ADD_CHOICES.filter(
        (choice) =>
            (choice.value === 'scan' && can.scan) ||
            (choice.value === 'one' && can.add) ||
            (choice.value === 'service' && can.connect),
    );
    const [choice, setChoice] = useState<AddChoice | ''>('');
    useEffect(() => {
        if (open) setChoice(options[0]?.value ?? '');
        // Only when it opens: the choice is the person's after that.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);
    const picked = ADD_CHOICES.find((option) => option.value === choice);

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={Plus}
            title="Add device"
            description="Choose how you want to add it. Each way takes a minute or two."
            submitLabel={picked?.button ?? 'Continue'}
            submitDisabled={!picked}
            onSubmit={() => {
                if (!picked) return;
                if (picked.value === 'service') {
                    if (onConnect) onConnect();
                    else router.visit('/security-devices/integrations');
                    return;
                }
                onChoose(picked.value);
            }}
            testId="sd-add-device"
        >
            <PopupField label="How do you want to add it?" required>
                <ChoiceTiles
                    label="How do you want to add it?"
                    options={options}
                    value={choice}
                    onChange={setChoice}
                />
            </PopupField>
            {can.scan ? (
                <PopupCallout tone="info">
                    Not sure? Start with a scan. It finds most cameras, network
                    gear and printers by itself.
                </PopupCallout>
            ) : null}
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  2 · Add one device · 7 · Edit device                               */
/* ------------------------------------------------------------------ */

const KIND_ICONS: Record<string, LucideIcon> = {
    network: Network,
    camera: Cctv,
    alarm: Siren,
    door: DoorClosed,
    sensor: Thermometer,
    tracker: MapPin,
    computer: Monitor,
    other: Box,
};

type EditableDevice = {
    id: number;
    name: string;
    domain: string;
    category: string;
    subcategory: string | null;
    manufacturer: string | null;
    model: string | null;
    ip_address: string | null;
    location_description: string | null;
    notes: string | null;
    site_id: number | null;
    active_assignment: { assignable_type: string; label: string } | null;
};

type FormState = {
    kind: string;
    model: string;
    manufacturerText: string;
    modelText: string;
    name: string;
    siteId: string;
    location: string;
    address: string;
    notes: string;
    alert: boolean;
};

const EMPTY_FORM: FormState = {
    kind: '',
    model: '',
    manufacturerText: '',
    modelText: '',
    name: '',
    siteId: '',
    location: '',
    address: '',
    notes: '',
    alert: true,
};

/** Kind of a registered device in the form's eight words (mirrors DeviceFormOptions::kindOf). */
function kindFromTaxonomy(device: EditableDevice): string {
    const category = device.category?.toLowerCase() ?? '';
    const sub = device.subcategory?.toLowerCase() ?? '';
    if (category === 'cctv' || sub.includes('camera') || ['nvr', 'dvr'].includes(sub)) return 'camera';
    if (['alarm', 'alarm_panel', 'intrusion'].includes(category)) return 'alarm';
    if (['access_control', 'door'].includes(category) || sub.includes('door') || sub.includes('reader')) return 'door';
    if (['personal_tracker', 'vehicle_tracker'].includes(category) || device.domain === 'tracking') return 'tracker';
    if (['endpoint', 'server'].includes(category)) return 'computer';
    if (category === 'network' || ['switch', 'router', 'wireless_ap'].includes(sub)) return 'network';
    if (['iot_healthcare', 'facilities'].includes(device.domain)) return 'sensor';
    return 'other';
}

export function DeviceFormPopup({
    open,
    mode,
    deviceId,
    context,
    form,
    canAssign,
    initialSiteId,
    onClose,
}: {
    open: boolean;
    mode: 'add' | 'edit';
    /** Edit: the device to load and change. */
    deviceId?: number | null;
    context?: PopupDevice | null;
    form: DeviceFormData | undefined;
    canAssign: boolean;
    /** Add: start with this Site chosen (a Site page's own "Add device"). */
    initialSiteId?: number | null;
    onClose: () => void;
}) {
    const [state, setState] = useState<FormState>(EMPTY_FORM);
    const [original, setOriginal] = useState<FormState>(EMPTY_FORM);
    const [device, setDevice] = useState<EditableDevice | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<{ text: string; href: string } | null>(null);
    const [processing, setProcessing] = useState(false);
    const [notListed, setNotListed] = useState(false);

    useEffect(() => {
        if (!open) return;
        setErrors({});
        setError(null);
        setNotice(null);
        setNotListed(false);
        if (mode === 'add') {
            // Opened from a Site's page: that Site is already chosen.
            const start = initialSiteId && canAssign ? { ...EMPTY_FORM, siteId: String(initialSiteId) } : EMPTY_FORM;
            setDevice(null);
            setState(start);
            setOriginal(start);
            return;
        }
        if (!deviceId) return;
        setLoadError(null);
        setDevice(null);
        axios
            .get<{ device: EditableDevice }>(
                `/security-devices/devices/${deviceId}/edit`,
                { headers: { Accept: 'application/json' } },
            )
            .then(({ data }) => {
                const loaded = data.device;
                const next: FormState = {
                    kind: kindFromTaxonomy(loaded),
                    model: '',
                    manufacturerText: loaded.manufacturer ?? '',
                    modelText: loaded.model ?? '',
                    name: loaded.name,
                    siteId: loaded.site_id ? String(loaded.site_id) : '',
                    location: loaded.location_description ?? '',
                    address: loaded.ip_address ?? '',
                    notes: loaded.notes ?? '',
                    alert: false,
                };
                setDevice(loaded);
                setState(next);
                setOriginal(next);
                setNotListed(true);
            })
            .catch(() =>
                setLoadError('This device couldn’t be loaded. Close and try again.'),
            );
    }, [open, mode, deviceId, canAssign, initialSiteId]);

    const models = useMemo(
        () =>
            (form?.models ?? []).filter(
                (option) => !state.kind || option.kind === state.kind,
            ),
        [form, state.kind],
    );
    const site = form?.sites.find((option) => String(option.id) === state.siteId);
    const withPerson =
        device?.active_assignment &&
        device.active_assignment.assignable_type !== 'site';
    const dirty = JSON.stringify(state) !== JSON.stringify(original);
    const set = <K extends keyof FormState>(key: K, value: FormState[K]) =>
        setState((current) => ({ ...current, [key]: value }));

    const chosenModel = form?.models.find((option) => option.value === state.model);
    const manufacturer = notListed ? state.manufacturerText : (chosenModel?.brand ?? '');
    const modelName = notListed ? state.modelText : (chosenModel?.model ?? '');

    const validate = (): boolean => {
        const next: Record<string, string> = {};
        if (!state.kind) next.kind = 'Choose what kind of device it is.';
        if (!state.name.trim()) next.name = 'Give it the name staff call it.';
        setErrors(next);
        return Object.keys(next).length === 0;
    };

    const submit = async () => {
        if (!validate()) return;
        setProcessing(true);
        setError(null);
        if (mode === 'add') {
            try {
                const { data } = await axios.post<{ id: number; href: string; name: string }>(
                    '/security-devices/devices/add-one',
                    {
                        kind: state.kind,
                        name: state.name.trim(),
                        manufacturer: manufacturer || null,
                        model: modelName || null,
                        site_id: state.siteId ? Number(state.siteId) : null,
                        location: state.location || null,
                        ip_address: state.address || null,
                    },
                    { headers: { Accept: 'application/json' } },
                );
                if (state.alert && state.address && state.siteId) {
                    try {
                        await axios.post(
                            `/security-devices/devices/${data.id}/offline-alert`,
                            { enabled: true },
                            { headers: { Accept: 'application/json' } },
                        );
                    } catch (alertError) {
                        setProcessing(false);
                        setNotice({
                            text: `Added ${data.name}. Offline alerts aren’t on yet: ${firstError(alertError, 'they couldn’t be turned on.')}`,
                            href: data.href,
                        });
                        return;
                    }
                }
                router.visit(data.href);
            } catch (saveError) {
                const data = axios.isAxiosError(saveError)
                    ? (saveError.response?.data as { errors?: Record<string, string[]> } | undefined)
                    : undefined;
                if (data?.errors) {
                    setErrors(
                        Object.fromEntries(
                            Object.entries(data.errors).map(([key, messages]) => [
                                key === 'ip_address' ? 'address' : key === 'site_id' ? 'site' : key,
                                messages[0],
                            ]),
                        ),
                    );
                } else {
                    setError(firstError(saveError, 'It couldn’t be added. Try again.'));
                }
                setProcessing(false);
            }
            return;
        }

        if (!device) return;
        const keepTaxonomy = state.kind === original.kind;
        const kind = KIND_TAXONOMY[state.kind] ?? KIND_TAXONOMY.other;
        router.put(
            `/security-devices/devices/${device.id}`,
            {
                _modal: true,
                name: state.name.trim(),
                domain: keepTaxonomy ? device.domain : kind.domain,
                category: keepTaxonomy ? device.category : kind.category,
                subcategory: keepTaxonomy ? device.subcategory : kind.subcategory,
                manufacturer: manufacturer || null,
                model: modelName || null,
                ip_address: state.address || null,
                location_description: state.location || null,
                notes: state.notes || null,
                ...(withPerson || !canAssign ? {} : { site_id: state.siteId ? Number(state.siteId) : null }),
            },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onError: (serverErrors) => {
                    const mapped: Record<string, string> = {};
                    for (const [key, message] of Object.entries(serverErrors)) {
                        mapped[key === 'ip_address' ? 'address' : key === 'site_id' ? 'site' : key] = String(message);
                    }
                    setErrors(mapped);
                    if (!mapped.name && !mapped.address && !mapped.site) {
                        setError(Object.values(mapped)[0] ?? 'It couldn’t be saved. Try again.');
                    }
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    const kinds = (form?.kinds ?? []).map((kind) => ({
        value: kind.value,
        label: kind.label,
        icon: KIND_ICONS[kind.value] ?? Box,
    }));

    const editing = mode === 'edit';
    const loading = !form || (editing && !device && !loadError);

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="lg"
            icon={editing ? Pencil : CirclePlus}
            title={editing ? 'Edit device' : 'Add one device'}
            description={
                editing
                    ? 'Change its name, where it is, how we reach it or how it’s watched.'
                    : 'Tell us what it is, where it is and how to reach it.'
            }
            context={editing && context ? contextFor(context) : undefined}
            error={loadError ?? error ?? undefined}
            callout={
                notice
                    ? {
                          tone: 'warning',
                          content: (
                              <>
                                  {notice.text}{' '}
                                  <Button
                                      type="button"
                                      variant="link"
                                      className="h-auto p-0"
                                      onClick={() => router.visit(notice.href)}
                                  >
                                      Open the device
                                  </Button>
                              </>
                          ),
                      }
                    : undefined
            }
            submitLabel={editing ? 'Save changes' : 'Add device'}
            processingLabel={editing ? 'Saving…' : 'Adding…'}
            processing={processing}
            submitDisabled={loading || !!loadError || !!notice}
            dirty={dirty && !notice}
            draftName={editing ? `Edit device for ${context?.name ?? 'this device'}` : 'Add one device'}
            onSubmit={submit}
            testId={editing ? 'sd-edit-device' : 'sd-add-one-device'}
        >
            {loading && !loadError ? (
                <p className="text-subtle" role="status">
                    Getting the form ready…
                </p>
            ) : (
                <>
                    <PopupSection icon={Tag} title="What it is" columns={2}>
                        <PopupField label="Kind" required wide error={errors.kind}>
                            <ChoiceTiles
                                label="Kind"
                                columns={4}
                                options={kinds}
                                value={state.kind}
                                onChange={(value) => {
                                    set('kind', value);
                                    if (!notListed) set('model', '');
                                }}
                            />
                        </PopupField>
                        <PopupField
                            label="Brand and model"
                            hint={notListed ? 'as written on the device' : 'from Supported devices'}
                            error={errors.manufacturer ?? errors.model}
                        >
                            {notListed ? (
                                <div className="grid grid-cols-2 gap-2">
                                    <Input
                                        aria-label="Brand"
                                        placeholder="Brand"
                                        value={state.manufacturerText}
                                        onChange={(event) => set('manufacturerText', event.target.value)}
                                    />
                                    <Input
                                        aria-label="Model"
                                        placeholder="Model"
                                        value={state.modelText}
                                        onChange={(event) => set('modelText', event.target.value)}
                                    />
                                </div>
                            ) : (
                                <VehicleSearchSelect
                                    label="Brand and model"
                                    value={state.model}
                                    options={models}
                                    onChange={(value) => set('model', value)}
                                />
                            )}
                            <Button
                                type="button"
                                variant="link"
                                className="h-auto justify-start p-0 text-xs"
                                onClick={() => setNotListed((current) => !current)}
                            >
                                {notListed ? 'Pick from Supported devices' : 'Not in the list? Type it'}
                            </Button>
                        </PopupField>
                        <PopupField
                            label="Name"
                            htmlFor="sd-device-name"
                            required
                            hint="what staff call it"
                            error={errors.name}
                        >
                            <Input
                                id="sd-device-name"
                                placeholder="e.g. Front door camera"
                                value={state.name}
                                onChange={(event) => set('name', event.target.value)}
                            />
                        </PopupField>
                    </PopupSection>

                    <PopupSection icon={MapPin} title="Where" columns={2}>
                        <PopupField label="Site" error={errors.site}>
                            {withPerson ? (
                                <p className="text-subtle">
                                    With {device?.active_assignment?.label}. Move it from
                                    its assignments so consent and custody rules stay
                                    intact.
                                </p>
                            ) : canAssign ? (
                                <VehicleSearchSelect
                                    label="Site"
                                    value={state.siteId}
                                    options={(form?.sites ?? []).map((option) => ({
                                        value: String(option.id),
                                        label: option.name,
                                    }))}
                                    onChange={(value) => set('siteId', value)}
                                />
                            ) : (
                                <p className="text-subtle">
                                    {editing
                                        ? `${context?.site ?? 'No Site'} · you can’t move devices between Sites`
                                        : 'It’s added without a Site · you can’t put devices at a Site'}
                                </p>
                            )}
                        </PopupField>
                        <PopupField label="Room or spot" htmlFor="sd-device-room">
                            <Input
                                id="sd-device-room"
                                placeholder="e.g. Lounge, Room 3, front porch"
                                value={state.location}
                                onChange={(event) => set('location', event.target.value)}
                            />
                        </PopupField>
                    </PopupSection>

                    <PopupSection icon={Cable} title="How to reach it" columns={2}>
                        <PopupField
                            label="Address"
                            htmlFor="sd-device-address"
                            hint="IP address or hostname"
                            error={errors.address}
                        >
                            <Input
                                id="sd-device-address"
                                placeholder="e.g. 10.20.0.31"
                                value={state.address}
                                onChange={(event) => set('address', event.target.value)}
                            />
                        </PopupField>
                        <PopupField label="Checked by" hint="follows the Site">
                            <p className="flex h-9 items-center text-sm">
                                {site?.checked_by ?? 'Chosen from the Site'}
                            </p>
                        </PopupField>
                    </PopupSection>

                    {editing ? (
                        <>
                            <PopupSection icon={BellPlus} title="Watch it">
                                <PopupField label="Checks">
                                    <p className="text-sm">
                                        {context?.watching && context.watching !== 'Nothing'
                                            ? context.watching
                                            : 'Not watched yet · use Watch it in the device menu'}
                                    </p>
                                </PopupField>
                            </PopupSection>
                            <PopupSection icon={StickyNote} title="Notes">
                                <PopupField label="Notes" htmlFor="sd-device-notes" wide>
                                    <Textarea
                                        id="sd-device-notes"
                                        rows={3}
                                        value={state.notes}
                                        onChange={(event) => set('notes', event.target.value)}
                                    />
                                </PopupField>
                            </PopupSection>
                        </>
                    ) : (
                        <PopupSection icon={BellPlus} title="Watch it">
                            <label className="flex items-start justify-between gap-4">
                                <span>
                                    <span className="block text-sm font-semibold">
                                        Alert me if it goes offline
                                    </span>
                                    <span className="text-caption block">
                                        {form?.watch
                                            ? `${form.watch.every.toLowerCase()} · after ${form.watch.after}`
                                            : 'uses the standard offline check'}
                                        {state.address && state.siteId ? '' : ' · needs a Site and an address'}
                                    </span>
                                </span>
                                <span className="flex items-center gap-2 text-sm">
                                    {state.alert ? 'On' : 'Off'}
                                    <Switch
                                        aria-label="Alert me if it goes offline"
                                        checked={state.alert}
                                        onCheckedChange={(checked) => set('alert', checked)}
                                    />
                                </span>
                            </label>
                        </PopupSection>
                    )}
                </>
            )}
        </PopupForm>
    );
}

const KIND_TAXONOMY: Record<string, { domain: string; category: string; subcategory: string | null }> = {
    network: { domain: 'it_infrastructure', category: 'network', subcategory: null },
    camera: { domain: 'security', category: 'cctv', subcategory: 'ip_camera' },
    alarm: { domain: 'security', category: 'alarm', subcategory: 'panel' },
    door: { domain: 'security', category: 'access_control', subcategory: 'card_reader' },
    sensor: { domain: 'iot_healthcare', category: 'environmental', subcategory: null },
    tracker: { domain: 'tracking', category: 'personal_tracker', subcategory: 'wearable_gps' },
    computer: { domain: 'it_infrastructure', category: 'endpoint', subcategory: 'workstation' },
    other: { domain: 'it_infrastructure', category: 'other', subcategory: null },
};

/* ------------------------------------------------------------------ */
/*  8 · Ping                                                           */
/* ------------------------------------------------------------------ */

type PingResult = {
    reachable: boolean;
    target: string | null;
    resolved_address: string | null;
    latency_label: string | null;
    message: string;
    checked_at: string;
    checked_from?: string;
    covering_hub?: string | null;
};

export function PingViewer({
    open,
    device,
    onClose,
}: {
    open: boolean;
    device: PopupDevice | null;
    onClose: () => void;
}) {
    const [result, setResult] = useState<PingResult | null>(null);
    const [failed, setFailed] = useState(false);
    const [asking, setAsking] = useState(false);

    const ask = () => {
        if (!device) return;
        setAsking(true);
        setFailed(false);
        axios
            .post<PingResult>(
                `/security-devices/devices/${device.id}/ping`,
                {},
                { headers: { Accept: 'application/json' } },
            )
            .then(({ data }) => setResult(data))
            .catch(() => setFailed(true))
            .finally(() => setAsking(false));
    };

    useEffect(() => {
        if (open) {
            setResult(null);
            ask();
        }
        // A new ping each time it opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, device?.id]);

    const askedBy = 'The Oblivion server';
    let callout: { tone: 'success' | 'critical' | 'info'; content: ReactNode } | undefined;
    if (asking && !result) {
        callout = { tone: 'info', content: 'Asking it now…' };
    } else if (failed) {
        callout = {
            tone: 'critical',
            content: 'Result not confirmed. The ping didn’t come back to this page. Ping again to check.',
        };
    } else if (result?.reachable) {
        callout = {
            tone: 'success',
            content: (
                <>
                    <b className="font-semibold">{device?.name} replied</b> in{' '}
                    {result.latency_label} · checked by the Oblivion server
                </>
            ),
        };
    } else if (result) {
        callout = { tone: 'critical', content: result.message };
    }

    return (
        <PopupViewer
            open={open}
            onClose={onClose}
            size="sm"
            icon={Activity}
            title={`Ping: ${device?.name ?? 'device'}`}
            description={
                result?.covering_hub
                    ? `Checked by the Oblivion server just now. ${result.covering_hub} covers this Site; hub pings come later.`
                    : 'Checked by the Oblivion server just now.'
            }
            callout={callout}
            facts={
                result
                    ? [
                          ['Address', result.resolved_address ?? result.target ?? '—'],
                          ['Reply', result.reachable ? 'Replied' : 'No reply'],
                          ['Reply time', result.latency_label ?? '—'],
                          ['Asked by', askedBy],
                          ['When', formatDateTime(result.checked_at)],
                      ]
                    : undefined
            }
            action={
                <Button type="button" onClick={ask} disabled={asking}>
                    Ping again
                </Button>
            }
            testId="sd-ping"
        />
    );
}

/* ------------------------------------------------------------------ */
/*  9 · Watch this device                                              */
/* ------------------------------------------------------------------ */

export function WatchDevice({
    open,
    device,
    form,
    onClose,
}: {
    open: boolean;
    device: PopupDevice | null;
    form: DeviceFormData | undefined;
    onClose: () => void;
}) {
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    useEffect(() => {
        if (open) setError(null);
    }, [open]);
    const site = form?.sites.find((option) => option.id === device?.site_id);
    const missing = !device?.address
        ? 'Add its address first (Edit), so it can be pinged.'
        : !device.site_id
          ? 'Put it at a Site first (Edit), so we know where it lives.'
          : null;

    const submit = () => {
        if (!device) return;
        setProcessing(true);
        setError(null);
        axios
            .post(
                `/security-devices/devices/${device.id}/offline-alert`,
                { enabled: true },
                { headers: { Accept: 'application/json' } },
            )
            .then(() => {
                onClose();
                router.reload();
            })
            .catch((failure) => setError(firstError(failure, 'Watching couldn’t be turned on. Try again.')))
            .finally(() => setProcessing(false));
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={BellPlus}
            title="Watch this device"
            description="Choose what to check and who’s told when it stops working."
            context={device ? contextFor(device) : undefined}
            error={error ?? undefined}
            callout={missing ? { tone: 'warning', content: missing } : undefined}
            submitLabel="Start watching"
            processingLabel="Starting…"
            processing={processing}
            submitDisabled={!!missing || !form}
            onSubmit={submit}
            testId="sd-watch-device"
        >
            <PopupField label="What should we check?">
                <ChoiceTiles
                    label="What should we check?"
                    options={[
                        {
                            value: 'online',
                            label: 'Is it online',
                            description: 'We ping it',
                            icon: Activity,
                        },
                    ]}
                    value="online"
                    onChange={() => undefined}
                />
            </PopupField>
            <PopupFacts
                rows={[
                    ['How often', form?.watch.every ?? '—'],
                    ['Alert after', form?.watch.after ?? '—'],
                    ['Checked by', site?.checked_by ?? 'The Oblivion server, when no hub covers the Site'],
                ]}
            />
            <PopupCallout tone="info">
                With these settings, you’ll know {form?.watch.know_within ?? 'within a few minutes'} after
                it stops replying.
            </PopupCallout>
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  11 · Remove or retire                                              */
/* ------------------------------------------------------------------ */

export function RetireDevice({
    open,
    device,
    onClose,
}: {
    open: boolean;
    device: PopupDevice | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    useEffect(() => {
        if (open) setError(null);
    }, [open]);

    const retire = () => {
        if (!device) return;
        setProcessing(true);
        router.delete(`/security-devices/devices/${device.id}`, {
            data: { _modal: true },
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (errors) => {
                const message = String(Object.values(errors)[0] ?? '');
                setError(
                    /monitor/i.test(message)
                        ? 'Stop watching it first: its checks are still on. Then retire it.'
                        : /provider integration/i.test(message)
                          ? 'It comes from a connected service. Release it there first, then retire it.'
                          : message || 'It couldn’t be retired. Try again.',
                );
            },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <PopupConfirm
            open={open}
            onClose={onClose}
            onConfirm={retire}
            variant="destructive"
            icon={Trash2}
            title={`Remove ${device?.name ?? 'this device'}?`}
            description={
                <>
                    <b className="font-semibold text-foreground">{device?.name}</b> and its
                    history would be removed from Oblivion. The device itself isn’t changed.
                </>
            }
            error={error ?? undefined}
            confirmLabel="Remove device"
            confirmDisabled
            secondary={
                <Button type="button" variant="outline" onClick={retire} disabled={processing}>
                    {processing ? 'Retiring…' : 'Retire instead'}
                </Button>
            }
            testId="sd-retire-device"
        >
            <PopupCallout tone="info">
                Removing isn’t available yet. Retire it instead: it leaves the lists and keeps
                its history.
            </PopupCallout>
        </PopupConfirm>
    );
}

/* ------------------------------------------------------------------ */
/*  72 · Move to another Site                                          */
/* ------------------------------------------------------------------ */

export function MoveDevices({
    open,
    devices,
    form,
    onClose,
}: {
    open: boolean;
    devices: PopupDevice[];
    form: DeviceFormData | undefined;
    onClose: () => void;
}) {
    const [siteId, setSiteId] = useState('');
    const [room, setRoom] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [refused, setRefused] = useState<{ name: string | null; reason: string }[]>([]);
    const [processing, setProcessing] = useState(false);
    useEffect(() => {
        if (!open) return;
        setSiteId('');
        setRoom('');
        setError(null);
        setRefused([]);
    }, [open]);

    const one = devices.length === 1 ? devices[0] : null;
    const fromSite = one?.site_id ? form?.sites.find((site) => site.id === one.site_id) : undefined;
    const toSite = form?.sites.find((site) => String(site.id) === siteId);

    const submit = () => {
        if (!siteId) {
            setError('Choose the Site it’s moving to.');
            return;
        }
        setProcessing(true);
        setError(null);
        axios
            .post<{ moved: number[]; refused: { name: string | null; reason: string }[] }>(
                '/security-devices/devices/move',
                { device_ids: devices.map((device) => device.id), site_id: Number(siteId), room: room || null },
                { headers: { Accept: 'application/json' } },
            )
            .then(({ data }) => {
                if (data.refused.length === 0) {
                    onClose();
                    router.reload();
                    return;
                }
                setRefused(data.refused);
                if (data.moved.length > 0) router.reload();
            })
            .catch((failure) => setError(firstError(failure, 'It couldn’t be moved. Try again.')))
            .finally(() => setProcessing(false));
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={ArrowRightLeft}
            title="Move to another Site"
            description="Moving it to another house? Its history comes too."
            context={
                one
                    ? { ...contextFor(one), tag: one.site ?? undefined }
                    : {
                          title: `${devices.length} devices`,
                          note: devices.slice(0, 3).map((device) => device.name).join(', ') + (devices.length > 3 ? ` and ${devices.length - 3} more` : ''),
                          icon: Box,
                      }
            }
            error={error ?? undefined}
            callout={
                refused.length > 0
                    ? {
                          tone: 'warning',
                          content: (
                              <ul className="grid gap-1">
                                  {refused.map((item, index) => (
                                      <li key={index}>
                                          <b className="font-semibold">{item.name ?? 'A device'}</b>: {item.reason}
                                      </li>
                                  ))}
                              </ul>
                          ),
                      }
                    : undefined
            }
            submitLabel={devices.length > 1 ? `Move ${devices.length} devices` : 'Move device'}
            processingLabel="Moving…"
            processing={processing}
            submitDisabled={!form}
            dirty={!!siteId || !!room}
            draftName="Move to another Site"
            onSubmit={submit}
            testId="sd-move-device"
        >
            <PopupField label="New Site" required error={error && !siteId ? error : undefined}>
                <VehicleSearchSelect
                    label="New Site"
                    value={siteId}
                    options={(form?.sites ?? []).map((site) => ({ value: String(site.id), label: site.name }))}
                    onChange={setSiteId}
                />
            </PopupField>
            <PopupField label="Room or spot" htmlFor="sd-move-room">
                <Input
                    id="sd-move-room"
                    placeholder="e.g. Office, Lounge"
                    value={room}
                    onChange={(event) => setRoom(event.target.value)}
                />
            </PopupField>
            <PopupField label="Checked by" hint="follows the new Site">
                <p className="text-sm">
                    {toSite
                        ? fromSite && fromSite.checked_by !== toSite.checked_by
                            ? `${fromSite.checked_by} → ${toSite.checked_by}`
                            : toSite.checked_by
                        : 'Chosen from the new Site'}
                </p>
            </PopupField>
            <PopupCallout tone="info">Its history moves with it.</PopupCallout>
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  10 · Device menu                                                   */
/* ------------------------------------------------------------------ */

export type DeviceAction = 'ping' | 'edit' | 'watch' | 'move' | 'remove';

/** One list for the ⋮ button and right-click (pop-up 10). Remove device is the only red item. */
export function deviceMenu(
    device: PopupDevice,
    can: Pick<DevicesCan, 'edit' | 'watch' | 'move' | 'retire'>,
    act: (action: DeviceAction, device: PopupDevice) => void,
): MenuItem[] {
    const items: MenuItem[] = [];
    items.push(
        device.address
            ? { label: 'Ping now', icon: Activity, onClick: () => act('ping', device) }
            : { label: 'Ping now', icon: Activity, disabled: 'Add its address first' },
    );
    if (can.edit) items.push({ label: 'Edit', icon: Pencil, onClick: () => act('edit', device) });
    if (can.watch && device.state === 'not_watched') {
        items.push({ label: 'Watch it', icon: BellPlus, onClick: () => act('watch', device) });
    }
    if (can.move) {
        items.push({ separator: true });
        items.push({ label: 'Move to another Site', icon: ArrowRightLeft, onClick: () => act('move', device) });
    }
    if (can.retire) {
        items.push({ separator: true });
        items.push({ label: 'Remove device', icon: Trash2, danger: true, onClick: () => act('remove', device) });
    }
    return items;
}
