import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    Cpu,
    LoaderCircle,
    Network,
    NotebookText,
    ScanLine,
    Server,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';

type FilterOption = { value: string; label: string };
type DeviceTaxonomy = Record<string, Record<string, Record<string, string>>>;

type DeviceFormOptions = {
    taxonomy: DeviceTaxonomy;
    domains: FilterOption[];
    statuses: FilterOption[];
    device?: Partial<EditableDevice>;
};

type EditableDevice = {
    id: number;
    name: string;
    domain: string;
    category: string;
    subcategory: string | null;
    manufacturer: string | null;
    model: string | null;
    serial_number: string | null;
    mac_address: string | null;
    imei: string | null;
    asset_tag: string | null;
    firmware_version: string | null;
    ip_address: string | null;
    status: string | null;
    provider: string | null;
    location_description: string | null;
    notes: string | null;
};

type AddDeviceForm = {
    name: string;
    domain: string;
    category: string;
    subcategory: string;
    manufacturer: string;
    model: string;
    serial_number: string;
    mac_address: string;
    imei: string;
    asset_tag: string;
    firmware_version: string;
    ip_address: string;
    status: string;
    provider: string;
    location_description: string;
    notes: string;
    _modal: boolean;
};

const steps: readonly WizardStep[] = [
    {
        key: 'classification',
        label: 'Classification',
        blurb: 'Name, workspace and type',
        icon: Cpu,
    },
    {
        key: 'identity',
        label: 'Hardware identity',
        blurb: 'Manufacturer and identifiers',
        icon: ScanLine,
    },
    {
        key: 'connection',
        label: 'Connection',
        blurb: 'Provider, firmware and location',
        icon: Network,
    },
    {
        key: 'review',
        label: 'Review & register',
        blurb: 'Confirm the canonical record',
        icon: NotebookText,
    },
];

function dialogSteps(mode: 'add' | 'edit'): readonly WizardStep[] {
    if (mode === 'add') return steps;

    return steps.map((step) =>
        step.key === 'review'
            ? {
                  ...step,
                  label: 'Review & save',
                  blurb: 'Confirm the registry changes',
              }
            : step,
    );
}

function emptyForm(prefillDomain: string): AddDeviceForm {
    return {
        name: '',
        domain: prefillDomain,
        category: '',
        subcategory: '',
        manufacturer: '',
        model: '',
        serial_number: '',
        mac_address: '',
        imei: '',
        asset_tag: '',
        firmware_version: '',
        ip_address: '',
        status: 'active',
        provider: '',
        location_description: '',
        notes: '',
        _modal: true,
    };
}

function formFromDevice(device: Partial<EditableDevice>): AddDeviceForm {
    return {
        name: device.name ?? '',
        domain: device.domain ?? '',
        category: device.category ?? '',
        subcategory: device.subcategory ?? '',
        manufacturer: device.manufacturer ?? '',
        model: device.model ?? '',
        serial_number: device.serial_number ?? '',
        mac_address: device.mac_address ?? '',
        imei: device.imei ?? '',
        asset_tag: device.asset_tag ?? '',
        firmware_version: device.firmware_version ?? '',
        ip_address: device.ip_address ?? '',
        status: device.status ?? 'active',
        provider: device.provider ?? '',
        location_description: device.location_description ?? '',
        notes: device.notes ?? '',
        _modal: true,
    };
}

function humanise(value: string): string {
    if (!value) return '—';

    return value
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function useDeviceDialogState(dialog: 'add-device' | 'edit-device') {
    const [open, setOpen] = useState(false);

    useEffect(() => {
        const syncDialogFromUrl = () => {
            setOpen(
                new URLSearchParams(window.location.search).get('dialog') ===
                    dialog,
            );
        };

        syncDialogFromUrl();
        window.addEventListener('popstate', syncDialogFromUrl);
        return () => window.removeEventListener('popstate', syncDialogFromUrl);
    }, [dialog]);

    const openDialog = () => {
        const url = new URL(window.location.href);
        url.searchParams.set('dialog', dialog);
        window.history.pushState(
            window.history.state,
            '',
            `${url.pathname}${url.search}${url.hash}`,
        );
        setOpen(true);
    };

    const closeDialog = () => {
        const url = new URL(window.location.href);
        url.searchParams.delete('dialog');
        window.history.replaceState(
            window.history.state,
            '',
            `${url.pathname}${url.search}${url.hash}`,
        );
        setOpen(false);
    };

    return { open, openDialog, closeDialog };
}

export function useAddDeviceDialogState() {
    return useDeviceDialogState('add-device');
}

export function useEditDeviceDialogState() {
    return useDeviceDialogState('edit-device');
}

export function AddDeviceDialog({
    open,
    onClose,
    prefillDomain = '',
    mode = 'add',
    deviceId,
}: {
    open: boolean;
    onClose: () => void;
    prefillDomain?: string;
    mode?: 'add' | 'edit';
    deviceId?: number;
}) {
    const [stepIndex, setStepIndex] = useState(0);
    const [options, setOptions] = useState<DeviceFormOptions | null>(null);
    const [optionsError, setOptionsError] = useState<string | null>(null);
    const [completedName, setCompletedName] = useState<string | null>(null);
    const {
        data,
        setData,
        post,
        put,
        processing,
        errors,
        clearErrors,
        reset,
    } = useForm<AddDeviceForm>(emptyForm(prefillDomain));
    const activeSteps = useMemo(() => dialogSteps(mode), [mode]);
    const setDataRef = useRef(setData);

    useEffect(() => {
        setDataRef.current = setData;
    }, [setData]);

    useEffect(() => {
        if (!open || options) return;

        const controller = new AbortController();
        setOptionsError(null);
        const optionsUrl =
            mode === 'edit' && deviceId
                ? `/security-devices/devices/${deviceId}/edit`
                : '/security-devices/devices/create';

        fetch(optionsUrl, {
            signal: controller.signal,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error('Device form options unavailable');
                }

                return (await response.json()) as DeviceFormOptions;
            })
            .then((payload) => {
                setOptions(payload);
                if (mode === 'edit' && payload.device) {
                    setDataRef.current(formFromDevice(payload.device));
                }
            })
            .catch((error: unknown) => {
                if (
                    error instanceof DOMException &&
                    error.name === 'AbortError'
                )
                    return;
                setOptionsError(
                    'Device details could not be loaded. Close the dialog and try again.',
                );
            });

        return () => controller.abort();
    }, [deviceId, mode, open, options]);

    const categories = useMemo(() => {
        if (!data.domain || !options?.taxonomy[data.domain]) return [];

        return Object.keys(options.taxonomy[data.domain]).map((value) => ({
            value,
            label: humanise(value),
        }));
    }, [data.domain, options]);

    const subcategories = useMemo(() => {
        if (
            !data.domain ||
            !data.category ||
            !options?.taxonomy[data.domain]?.[data.category]
        ) {
            return [];
        }

        return Object.entries(options.taxonomy[data.domain][data.category]).map(
            ([value, label]) => ({ value, label }),
        );
    }, [data.category, data.domain, options]);

    const close = () => {
        if (processing) return;
        setCompletedName(null);
        setStepIndex(0);
        setOptions(null);
        clearErrors();
        reset();
        onClose();
    };

    const submit = () => {
        const submitOptions = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setCompletedName(data.name);
                clearErrors();
            },
            onError: (serverErrors) => {
                const keys = Object.keys(serverErrors);
                if (
                    keys.some((key) =>
                        [
                            'name',
                            'domain',
                            'category',
                            'subcategory',
                            'status',
                        ].includes(key),
                    )
                ) {
                    setStepIndex(0);
                } else if (
                    keys.some((key) =>
                        [
                            'manufacturer',
                            'model',
                            'serial_number',
                            'mac_address',
                            'imei',
                            'asset_tag',
                        ].includes(key),
                    )
                ) {
                    setStepIndex(1);
                } else {
                    setStepIndex(2);
                }
            },
        };

        if (mode === 'edit' && deviceId) {
            put(`/security-devices/devices/${deviceId}`, submitOptions);
        } else {
            post('/security-devices/devices', submitOptions);
        }
    };

    const basicsComplete = Boolean(
        data.name.trim() && data.domain && data.category,
    );

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={mode === 'edit' ? 'Edit device' : 'Register device'}
            description={
                mode === 'edit'
                    ? 'Update the canonical Security & Devices registry record.'
                    : 'Create one canonical Security & Devices registry record.'
            }
            railIcon={Cpu}
            railTitle={mode === 'edit' ? 'Edit device' : 'Register device'}
            railSub="Canonical inventory"
            steps={activeSteps}
            stepIndex={stepIndex}
            onStepClick={(index) => {
                if (index <= stepIndex || basicsComplete) setStepIndex(index);
            }}
            pct={Math.round(((stepIndex + 1) / activeSteps.length) * 100)}
            pctLabel={
                mode === 'edit' ? 'Update progress' : 'Registration progress'
            }
            maxWidth="min(94vw, 1040px)"
            maxHeight="min(88vh, 820px)"
            footerStart={
                <Button
                    type="button"
                    variant="ghost"
                    onClick={
                        stepIndex === 0
                            ? close
                            : () => setStepIndex((i) => i - 1)
                    }
                    disabled={processing}
                >
                    {stepIndex === 0 ? (
                        'Cancel'
                    ) : (
                        <>
                            <ArrowLeft className="mr-2 h-4 w-4" /> Back
                        </>
                    )}
                </Button>
            }
            footerEnd={
                stepIndex === activeSteps.length - 1 ? (
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={!basicsComplete || processing || !options}
                    >
                        {processing ? (
                            <LoaderCircle className="mr-2 h-4 w-4 animate-spin" />
                        ) : (
                            <Check className="mr-2 h-4 w-4" />
                        )}
                        {processing
                            ? mode === 'edit'
                                ? 'Saving…'
                                : 'Registering…'
                            : mode === 'edit'
                              ? 'Save changes'
                              : 'Register device'}
                    </Button>
                ) : (
                    <Button
                        type="button"
                        onClick={() => setStepIndex((i) => i + 1)}
                        disabled={
                            (stepIndex === 0 && !basicsComplete) ||
                            !options ||
                            processing
                        }
                    >
                        Continue <ArrowRight className="ml-2 h-4 w-4" />
                    </Button>
                )
            }
            success={
                completedName ? (
                    <WizardSuccessPane
                        title={
                            mode === 'edit'
                                ? 'Device updated'
                                : 'Device registered'
                        }
                        blurb={
                            <>
                                <strong>{completedName}</strong>{' '}
                                {mode === 'edit'
                                    ? 'has been updated in the canonical device registry.'
                                    : 'is now in the canonical device registry.'}
                            </>
                        }
                        actions={
                            <Button type="button" onClick={close}>
                                {mode === 'edit'
                                    ? 'Return to profile'
                                    : 'Return to devices'}
                            </Button>
                        }
                    />
                ) : undefined
            }
        >
            {!options ? (
                <div className="flex min-h-64 items-center justify-center text-sm text-muted-foreground">
                    {optionsError ? (
                        <p className="max-w-sm text-center text-destructive">
                            {optionsError}
                        </p>
                    ) : (
                        <>
                            <LoaderCircle className="mr-2 h-4 w-4 animate-spin" />
                            Loading device details…
                        </>
                    )}
                </div>
            ) : stepIndex === 0 ? (
                <WizardStepPane>
                    <StepHeading
                        icon={Cpu}
                        title="What are we registering?"
                        description="Give the device a recognisable name and place it in the correct technology workspace."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField
                            id="device-name"
                            label="Device name"
                            error={errors.name}
                            required
                        >
                            <Input
                                id="device-name"
                                value={data.name}
                                onChange={(event) =>
                                    setData('name', event.target.value)
                                }
                                placeholder="e.g. Main entrance camera"
                                autoFocus
                            />
                        </FormField>
                        <FormField
                            id="device-status"
                            label="Status"
                            error={errors.status}
                        >
                            <Select
                                value={data.status}
                                onValueChange={(value) =>
                                    setData('status', value)
                                }
                            >
                                <SelectTrigger id="device-status">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.statuses.map((status) => (
                                        <SelectItem
                                            key={status.value}
                                            value={status.value}
                                        >
                                            {status.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FormField>
                        <FormField
                            id="device-domain"
                            label="Workspace"
                            error={errors.domain}
                            required
                        >
                            <Select
                                value={data.domain}
                                onValueChange={(value) =>
                                    setData({
                                        ...data,
                                        domain: value,
                                        category: '',
                                        subcategory: '',
                                    })
                                }
                            >
                                <SelectTrigger id="device-domain">
                                    <SelectValue placeholder="Select workspace" />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.domains.map((domain) => (
                                        <SelectItem
                                            key={domain.value}
                                            value={domain.value}
                                        >
                                            {domain.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FormField>
                        <FormField
                            id="device-category"
                            label="Device category"
                            error={errors.category}
                            required
                        >
                            <Select
                                value={data.category}
                                disabled={!data.domain}
                                onValueChange={(value) =>
                                    setData({
                                        ...data,
                                        category: value,
                                        subcategory: '',
                                    })
                                }
                            >
                                <SelectTrigger id="device-category">
                                    <SelectValue placeholder="Select category" />
                                </SelectTrigger>
                                <SelectContent>
                                    {categories.map((category) => (
                                        <SelectItem
                                            key={category.value}
                                            value={category.value}
                                        >
                                            {category.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FormField>
                        <FormField
                            id="device-subcategory"
                            label="Device type"
                            error={errors.subcategory}
                        >
                            <Select
                                value={data.subcategory || '_none'}
                                disabled={subcategories.length === 0}
                                onValueChange={(value) =>
                                    setData(
                                        'subcategory',
                                        value === '_none' ? '' : value,
                                    )
                                }
                            >
                                <SelectTrigger id="device-subcategory">
                                    <SelectValue placeholder="Select type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="_none">None</SelectItem>
                                    {subcategories.map((subcategory) => (
                                        <SelectItem
                                            key={subcategory.value}
                                            value={subcategory.value}
                                        >
                                            {subcategory.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FormField>
                    </div>
                </WizardStepPane>
            ) : stepIndex === 1 ? (
                <WizardStepPane>
                    <StepHeading
                        icon={ScanLine}
                        title="Hardware identity"
                        description="Record the identifiers technicians use to match the physical device."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField
                            id="device-manufacturer"
                            label="Manufacturer"
                            value={data.manufacturer}
                            error={errors.manufacturer}
                            onChange={(value) => setData('manufacturer', value)}
                        />
                        <TextField
                            id="device-model"
                            label="Model"
                            value={data.model}
                            error={errors.model}
                            onChange={(value) => setData('model', value)}
                        />
                        <TextField
                            id="device-serial-number"
                            label="Serial number"
                            value={data.serial_number}
                            error={errors.serial_number}
                            onChange={(value) =>
                                setData('serial_number', value)
                            }
                        />
                        <TextField
                            id="device-asset-tag"
                            label="Asset tag"
                            value={data.asset_tag}
                            error={errors.asset_tag}
                            onChange={(value) => setData('asset_tag', value)}
                        />
                        <TextField
                            id="device-mac-address"
                            label="MAC address"
                            value={data.mac_address}
                            error={errors.mac_address}
                            placeholder="AA:BB:CC:DD:EE:FF"
                            onChange={(value) => setData('mac_address', value)}
                        />
                        <TextField
                            id="device-imei"
                            label="IMEI"
                            value={data.imei}
                            error={errors.imei}
                            onChange={(value) => setData('imei', value)}
                        />
                    </div>
                </WizardStepPane>
            ) : stepIndex === 2 ? (
                <WizardStepPane>
                    <StepHeading
                        icon={Network}
                        title="Connection and location"
                        description="Add operational context without storing credentials or raw provider targets."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField
                            id="device-provider"
                            label="Provider"
                            value={data.provider}
                            error={errors.provider}
                            placeholder="e.g. UniFi, Queclink or manual"
                            onChange={(value) => setData('provider', value)}
                        />
                        <TextField
                            id="device-firmware-version"
                            label="Firmware version"
                            value={data.firmware_version}
                            error={errors.firmware_version}
                            onChange={(value) =>
                                setData('firmware_version', value)
                            }
                        />
                        <TextField
                            id="device-ip-address"
                            label="IP address"
                            value={data.ip_address}
                            error={errors.ip_address}
                            placeholder="192.168.1.1"
                            onChange={(value) => setData('ip_address', value)}
                        />
                        <TextField
                            id="device-location-description"
                            label="Location description"
                            value={data.location_description}
                            error={errors.location_description}
                            placeholder="e.g. Server room rack A"
                            onChange={(value) =>
                                setData('location_description', value)
                            }
                        />
                        <FormField
                            id="device-notes"
                            label="Notes"
                            error={errors.notes}
                        >
                            <textarea
                                id="device-notes"
                                rows={5}
                                value={data.notes}
                                onChange={(event) =>
                                    setData('notes', event.target.value)
                                }
                                className="min-h-28 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:col-span-2"
                                placeholder="Operational notes only—never credentials or secrets."
                            />
                        </FormField>
                    </div>
                </WizardStepPane>
            ) : (
                <WizardStepPane>
                    <StepHeading
                        icon={NotebookText}
                        title={
                            mode === 'edit'
                                ? 'Review the registry changes'
                                : 'Review the canonical record'
                        }
                        description={
                            mode === 'edit'
                                ? 'Confirm the device identity before saving the shared registry record.'
                                : 'Confirm the device identity before adding it to the shared registry.'
                        }
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ReviewCard
                            icon={Cpu}
                            title="Classification"
                            onEdit={() => setStepIndex(0)}
                        >
                            <ReviewRow label="Name" value={data.name} />
                            <ReviewRow
                                label="Workspace"
                                value={humanise(data.domain)}
                            />
                            <ReviewRow
                                label="Category"
                                value={humanise(data.category)}
                            />
                            <ReviewRow
                                label="Type"
                                value={humanise(data.subcategory)}
                            />
                            <ReviewRow
                                label="Status"
                                value={humanise(data.status)}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={ScanLine}
                            title="Hardware identity"
                            onEdit={() => setStepIndex(1)}
                        >
                            <ReviewRow
                                label="Manufacturer"
                                value={data.manufacturer}
                            />
                            <ReviewRow label="Model" value={data.model} />
                            <ReviewRow
                                label="Serial number"
                                value={data.serial_number}
                            />
                            <ReviewRow
                                label="Asset tag"
                                value={data.asset_tag}
                            />
                            <ReviewRow
                                label="MAC address"
                                value={data.mac_address}
                            />
                            <ReviewRow label="IMEI" value={data.imei} />
                        </ReviewCard>
                        <ReviewCard
                            icon={Server}
                            title="Connection"
                            span
                            onEdit={() => setStepIndex(2)}
                        >
                            <ReviewRow label="Provider" value={data.provider} />
                            <ReviewRow
                                label="Firmware"
                                value={data.firmware_version}
                            />
                            <ReviewRow
                                label="IP address"
                                value={data.ip_address}
                            />
                            <ReviewRow
                                label="Location"
                                value={data.location_description}
                            />
                            <ReviewRow label="Notes" value={data.notes} />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export function EditDeviceDialog({
    open,
    onClose,
    deviceId,
}: {
    open: boolean;
    onClose: () => void;
    deviceId: number;
}) {
    return (
        <AddDeviceDialog
            open={open}
            onClose={onClose}
            mode="edit"
            deviceId={deviceId}
        />
    );
}

function StepHeading({
    icon: Icon,
    title,
    description,
}: {
    icon: typeof Cpu;
    title: string;
    description: string;
}) {
    return (
        <div className="mb-6 flex items-start gap-3">
            <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary">
                <Icon className="h-5 w-5" />
            </span>
            <div>
                <h2 className="text-lg font-bold">{title}</h2>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
        </div>
    );
}

function TextField({
    id,
    label,
    value,
    error,
    placeholder,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    error?: string;
    placeholder?: string;
    onChange: (value: string) => void;
}) {
    return (
        <FormField id={id} label={label} error={error}>
            <Input
                id={id}
                value={value}
                placeholder={placeholder}
                onChange={(event) => onChange(event.target.value)}
            />
        </FormField>
    );
}

function FormField({
    id,
    label,
    error,
    required,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    required?: boolean;
    children: ReactNode;
}) {
    return (
        <div>
            <label htmlFor={id} className="mb-1.5 block text-sm font-medium">
                {label}
                {required ? (
                    <span className="ml-0.5 text-destructive">*</span>
                ) : null}
            </label>
            {children}
            {error ? (
                <p className="mt-1 text-xs text-destructive">{error}</p>
            ) : null}
        </div>
    );
}
