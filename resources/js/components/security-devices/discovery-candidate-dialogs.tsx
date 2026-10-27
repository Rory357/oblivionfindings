import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
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
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import axios from 'axios';
import { Check, ChevronsUpDown, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';

export type CandidateSiteDeviceOption = {
    id: number;
    uid: string;
    name: string;
    ip_address: string | null;
    category: string | null;
};

export type CandidateManagement = {
    can_adopt: boolean;
    can_same_as: boolean;
    can_ignore: boolean;
    adopt_all_url: string | null;
    adoptable_count: number;
    taxonomy: Record<string, Record<string, Record<string, string>>>;
    domains: Array<{ value: string; label: string }>;
    devices_by_site: Record<string, CandidateSiteDeviceOption[]>;
};

export type CandidateActionTarget = {
    id: number;
    hostname?: string | null;
    ip_address?: string | null;
    mac_address?: string | null;
    site_id?: number | null;
    site_name?: string | null;
    adopt_url: string | null;
    same_as_url: string | null;
};

function humaniseCategoryKey(value: string): string {
    const words = value.replace(/[_-]+/g, ' ').trim();
    return words.charAt(0).toUpperCase() + words.slice(1);
}

function extractAxiosError(error: unknown, fallback: string): string {
    if (axios.isAxiosError(error) && error.response?.data) {
        const data = error.response.data as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        const firstField = data.errors
            ? Object.values(data.errors).flat()[0]
            : undefined;
        return firstField ?? data.message ?? fallback;
    }
    return fallback;
}

export function CandidateAdoptDialog({
    candidate,
    management,
    onClose,
    onCompleted,
}: {
    candidate: CandidateActionTarget | null;
    management: CandidateManagement;
    onClose: () => void;
    onCompleted: () => void;
}) {
    const defaultDomain = management.domains[0]?.value ?? 'it_infrastructure';
    const [name, setName] = useState('');
    const [domain, setDomain] = useState(defaultDomain);
    const [category, setCategory] = useState('network');
    const [manufacturer, setManufacturer] = useState('');
    const [model, setModel] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!candidate) return;
        const suggested =
            candidate.hostname ??
            candidate.ip_address ??
            `Discovered device #${candidate.id}`;
        setName(suggested);
        const initialDomain =
            management.taxonomy.it_infrastructure
                ? 'it_infrastructure'
                : (management.domains[0]?.value ?? 'it_infrastructure');
        setDomain(initialDomain);
        const categoryKeys = Object.keys(
            management.taxonomy[initialDomain] ?? {},
        );
        setCategory(categoryKeys[0] ?? 'network');
        setManufacturer('');
        setModel('');
        setError(null);
    }, [candidate, management]);

    const categoryKeys = Object.keys(management.taxonomy[domain] ?? {});

    const handleDomainChange = (nextDomain: string) => {
        setDomain(nextDomain);
        const nextCategories = Object.keys(
            management.taxonomy[nextDomain] ?? {},
        );
        setCategory(nextCategories[0] ?? '');
    };

    const submit = async () => {
        if (!candidate?.adopt_url || submitting) return;
        setSubmitting(true);
        setError(null);
        try {
            await axios.post(candidate.adopt_url, {
                name: name.trim() || null,
                domain: domain || null,
                category: category || null,
                manufacturer: manufacturer.trim() || null,
                model: model.trim() || null,
            });
            onClose();
            onCompleted();
        } catch (err: unknown) {
            setError(
                extractAxiosError(
                    err,
                    'Could not add this found device right now.',
                ),
            );
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Dialog
            open={candidate !== null}
            onOpenChange={(open) => {
                if (!open && !submitting) onClose();
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add as device</DialogTitle>
                    <DialogDescription>
                        Register this discovered address in Devices
                        {candidate?.site_name
                            ? ` and assign it to ${candidate.site_name}.`
                            : '.'}
                    </DialogDescription>
                </DialogHeader>

                {candidate && (
                    <div className="space-y-4 py-1">
                        {(candidate.ip_address || candidate.mac_address) && (
                            <div className="rounded-lg bg-muted/30 p-3 text-xs">
                                {candidate.ip_address && (
                                    <p>
                                        IP address:{' '}
                                        <span className="font-mono font-medium">
                                            {candidate.ip_address}
                                        </span>
                                    </p>
                                )}
                                {candidate.mac_address && (
                                    <p className="mt-1">
                                        MAC address:{' '}
                                        <span className="font-mono font-medium">
                                            {candidate.mac_address}
                                        </span>
                                    </p>
                                )}
                            </div>
                        )}

                        <div className="space-y-1.5">
                            <Label htmlFor="adopt-candidate-name">
                                Device name
                            </Label>
                            <Input
                                id="adopt-candidate-name"
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                            />
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Device group</Label>
                                <Select
                                    value={domain}
                                    onValueChange={handleDomainChange}
                                >
                                    <SelectTrigger aria-label="Device group">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {management.domains.map((item) => (
                                            <SelectItem
                                                key={item.value}
                                                value={item.value}
                                            >
                                                {item.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1.5">
                                <Label>Device type</Label>
                                <Select
                                    value={category}
                                    onValueChange={setCategory}
                                >
                                    <SelectTrigger aria-label="Device type">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {categoryKeys.map((key) => (
                                            <SelectItem key={key} value={key}>
                                                {humaniseCategoryKey(key)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="adopt-candidate-manufacturer">
                                    Brand (optional)
                                </Label>
                                <Input
                                    id="adopt-candidate-manufacturer"
                                    value={manufacturer}
                                    placeholder="e.g. Ubiquiti, Hikvision"
                                    onChange={(event) =>
                                        setManufacturer(event.target.value)
                                    }
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="adopt-candidate-model">
                                    Model (optional)
                                </Label>
                                <Input
                                    id="adopt-candidate-model"
                                    value={model}
                                    placeholder="e.g. UniFi Express 7"
                                    onChange={(event) =>
                                        setModel(event.target.value)
                                    }
                                />
                            </div>
                        </div>

                        {error && (
                            <Alert variant="destructive">
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}
                    </div>
                )}

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={submitting}
                        onClick={onClose}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={submitting || name.trim() === ''}
                        onClick={() => void submit()}
                    >
                        {submitting && (
                            <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                        )}
                        Add device
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function CandidateSameAsDialog({
    candidate,
    management,
    onClose,
    onCompleted,
}: {
    candidate: CandidateActionTarget | null;
    management: CandidateManagement;
    onClose: () => void;
    onCompleted: () => void;
}) {
    const [pickerOpen, setPickerOpen] = useState(false);
    const [selectedDeviceId, setSelectedDeviceId] = useState<string>('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const siteKey = candidate?.site_id ? String(candidate.site_id) : '';
    const siteDevices = siteKey
        ? (management.devices_by_site[siteKey] ?? [])
        : [];
    const selectedDevice = siteDevices.find(
        (item) => String(item.id) === selectedDeviceId,
    );

    useEffect(() => {
        setSelectedDeviceId('');
        setPickerOpen(false);
        setError(null);
    }, [candidate]);

    const submit = async () => {
        if (!candidate?.same_as_url || !selectedDeviceId || submitting) return;
        setSubmitting(true);
        setError(null);
        try {
            await axios.post(candidate.same_as_url, {
                device_id: Number(selectedDeviceId),
            });
            onClose();
            onCompleted();
        } catch (err: unknown) {
            setError(
                extractAxiosError(
                    err,
                    'Could not link this candidate to the selected device.',
                ),
            );
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Dialog
            open={candidate !== null}
            onOpenChange={(open) => {
                if (!open && !submitting) onClose();
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Same as existing device</DialogTitle>
                    <DialogDescription>
                        Link this scan result to a device already registered
                        {candidate?.site_name
                            ? ` at ${candidate.site_name}`
                            : ' at this Site'}{' '}
                        and fill in any missing IP or MAC details.
                    </DialogDescription>
                </DialogHeader>

                {candidate && (
                    <div className="space-y-4 py-1">
                        {siteDevices.length === 0 ? (
                            <Alert>
                                <AlertDescription>
                                    No existing devices are assigned to{' '}
                                    {candidate.site_name ?? 'this Site'} yet.
                                    Choose Add as device instead, or assign the
                                    existing device to this Site first.
                                </AlertDescription>
                            </Alert>
                        ) : (
                            <div className="space-y-1.5">
                                <Label>Existing device</Label>
                                <Popover
                                    open={pickerOpen}
                                    onOpenChange={setPickerOpen}
                                >
                                    <PopoverTrigger asChild>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            role="combobox"
                                            aria-expanded={pickerOpen}
                                            aria-label="Choose existing device"
                                            className="w-full justify-between font-normal"
                                        >
                                            <span className="truncate">
                                                {selectedDevice
                                                    ? `${selectedDevice.name} (${selectedDevice.ip_address ?? selectedDevice.uid})`
                                                    : 'Search devices at this Site…'}
                                            </span>
                                            <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent
                                        className="w-[var(--radix-popover-trigger-width)] p-0"
                                        align="start"
                                    >
                                        <Command>
                                            <CommandInput placeholder="Search by name, IP, or UID…" />
                                            <CommandList>
                                                <CommandEmpty>
                                                    No matching device found.
                                                </CommandEmpty>
                                                <CommandGroup>
                                                    {siteDevices.map((item) => (
                                                        <CommandItem
                                                            key={item.id}
                                                            value={`${item.name} ${item.uid} ${item.ip_address ?? ''}`}
                                                            onSelect={() => {
                                                                setSelectedDeviceId(
                                                                    String(
                                                                        item.id,
                                                                    ),
                                                                );
                                                                setPickerOpen(
                                                                    false,
                                                                );
                                                            }}
                                                        >
                                                            <Check
                                                                className={cn(
                                                                    'mr-2 h-4 w-4',
                                                                    selectedDeviceId ===
                                                                        String(
                                                                            item.id,
                                                                        )
                                                                        ? 'opacity-100'
                                                                        : 'opacity-0',
                                                                )}
                                                            />
                                                            <div className="min-w-0 flex-1">
                                                                <p className="truncate text-sm font-medium">
                                                                    {item.name}
                                                                </p>
                                                                <p className="truncate text-xs text-muted-foreground">
                                                                    {item.ip_address ??
                                                                        item.uid}
                                                                </p>
                                                            </div>
                                                        </CommandItem>
                                                    ))}
                                                </CommandGroup>
                                            </CommandList>
                                        </Command>
                                    </PopoverContent>
                                </Popover>
                            </div>
                        )}

                        {error && (
                            <Alert variant="destructive">
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}
                    </div>
                )}

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={submitting}
                        onClick={onClose}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={submitting || !selectedDeviceId}
                        onClick={() => void submit()}
                    >
                        {submitting && (
                            <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                        )}
                        Link device
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function CandidateAdoptAllDialog({
    open,
    adoptAllUrl,
    count,
    siteId,
    siteName,
    onClose,
    onCompleted,
}: {
    open: boolean;
    adoptAllUrl: string | null;
    count: number;
    siteId: number | null;
    siteName?: string;
    onClose: () => void;
    onCompleted: () => void;
}) {
    const [processing, setProcessing] = useState(false);

    const handleConfirm = async () => {
        if (!adoptAllUrl || processing) return;
        setProcessing(true);
        try {
            await axios.post(adoptAllUrl, {
                site_id: siteId,
            });
            onClose();
            onCompleted();
        } finally {
            setProcessing(false);
        }
    };

    return (
        <ConfirmDialog
            open={open}
            onClose={onClose}
            onConfirm={() => void handleConfirm()}
            title="Add all found devices"
            description={
                siteName
                    ? `Add all unreviewed candidates at ${siteName} as registered devices? You can edit each device's name and type afterwards.`
                    : `Add ${count} unreviewed ${count === 1 ? 'candidate' : 'candidates'} across your Sites as registered devices? You can edit each device's name and type afterwards.`
            }
            confirmText="Add all"
            variant="default"
            processing={processing}
        />
    );
}
