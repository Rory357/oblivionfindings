import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Textarea } from '@/components/ui/textarea';
import axios from 'axios';
import { ShieldX } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';

export type NativeMonitorManagement = {
    can_manage: boolean;
    create_url: string | null;
    kinds: Array<{ value: string; label: string }>;
    profiles: Array<{ id: number; name: string }>;
    devices: Array<{
        id: number;
        name: string;
        site: { id: number; name: string };
    }>;
};

export type NativeMonitorTarget = {
    id: number;
    name: string;
    kind: string;
    enabled: boolean;
    affects_availability: boolean;
    profile: { id: number; name: string } | null;
    device: { id: number; name: string };
    actions: {
        can_manage: boolean;
        update_url: string | null;
        deactivate_url: string | null;
    };
};

function lines(value: string): string[] {
    return value
        .split(/[\n,]/)
        .map((item) => item.trim())
        .filter(Boolean);
}

function integers(value: string): number[] {
    return lines(value)
        .map(Number)
        .filter((item) => Number.isInteger(item));
}

function failureMessage(error: unknown): string {
    if (axios.isAxiosError(error)) {
        const errors = error.response?.data?.errors;
        if (errors && typeof errors === 'object') {
            const message = Object.values(errors as Record<string, unknown>)
                .flatMap((value) => (Array.isArray(value) ? value : [value]))
                .find(
                    (value): value is string =>
                        typeof value === 'string' && value.length > 0,
                );
            if (message) return message;
        }
    }

    return 'The monitor change could not be applied. Review the Site scope and try again.';
}

export function NativeMonitorDialog({
    open,
    onOpenChange,
    management,
    monitor,
    onSaved,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    management: NativeMonitorManagement;
    monitor: NativeMonitorTarget | null;
    onSaved: () => void;
}) {
    const editing = monitor !== null;
    const [deviceId, setDeviceId] = useState('');
    const [profileId, setProfileId] = useState('');
    const [kind, setKind] = useState('icmp');
    const [name, setName] = useState('');
    const [target, setTarget] = useState('');
    const [port, setPort] = useState('');
    const [dnsName, setDnsName] = useState('');
    const [dnsType, setDnsType] = useState('A');
    const [expectedAnswers, setExpectedAnswers] = useState('');
    const [expectedStatus, setExpectedStatus] = useState('200');
    const [warnDays, setWarnDays] = useState('30');
    const [credentialReference, setCredentialReference] = useState('');
    const [inventoryProfile, setInventoryProfile] = useState('');
    const [hostKeyFingerprint, setHostKeyFingerprint] = useState('');
    const [affectsAvailability, setAffectsAvailability] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const createDefinitionMissing =
        !editing &&
        ((kind === 'tcp' && !port) ||
            (kind === 'dns' && !dnsName.trim()) ||
            (['snmp', 'ssh_inventory', 'winrm_inventory'].includes(kind) &&
                !credentialReference.trim()) ||
            (['ssh_inventory', 'winrm_inventory'].includes(kind) &&
                !inventoryProfile) ||
            (kind === 'ssh_inventory' && !hostKeyFingerprint.trim()));

    useEffect(() => {
        if (!open) return;
        setDeviceId(
            monitor?.device.id.toString() ??
                (management.devices.length === 1
                    ? management.devices[0].id.toString()
                    : ''),
        );
        setProfileId(
            monitor?.profile?.id.toString() ??
                (management.profiles.length === 1
                    ? management.profiles[0].id.toString()
                    : ''),
        );
        setKind(monitor?.kind ?? 'icmp');
        setName(monitor?.name ?? '');
        setTarget('');
        setPort('');
        setDnsName('');
        setDnsType('A');
        setExpectedAnswers('');
        setExpectedStatus(editing ? '' : '200');
        setWarnDays(editing ? '' : '30');
        setCredentialReference('');
        setInventoryProfile('');
        setHostKeyFingerprint('');
        setAffectsAvailability(monitor?.affects_availability ?? false);
        setError(null);
    }, [open, monitor, management.devices, management.profiles, editing]);

    const close = () => {
        setTarget('');
        setCredentialReference('');
        setHostKeyFingerprint('');
        setError(null);
        onOpenChange(false);
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        const url = monitor?.actions.update_url ?? management.create_url;
        if (
            !url ||
            !profileId ||
            !name.trim() ||
            (!editing && (!deviceId || !target.trim())) ||
            createDefinitionMissing
        ) {
            return;
        }
        const payload: Record<string, unknown> = {
            profile_id: Number(profileId),
            name: name.trim(),
            affects_availability: affectsAvailability,
        };
        if (!editing) {
            payload.device_id = Number(deviceId);
            payload.kind = kind;
        }
        if (target.trim()) payload.target = target.trim();
        if (port) payload.port = Number(port);
        if (kind === 'dns') {
            if (dnsName.trim()) payload.dns_name = dnsName.trim();
            if (!editing || target.trim()) payload.dns_type = dnsType;
            if (expectedAnswers.trim())
                payload.expected_answers = lines(expectedAnswers);
        }
        if (kind === 'http' && (!editing || expectedStatus.trim()))
            payload.expected_status = integers(expectedStatus);
        if (kind === 'tls' && (!editing || warnDays))
            payload.warn_days = Number(warnDays);
        if (['snmp', 'ssh_inventory', 'winrm_inventory'].includes(kind)) {
            if (credentialReference.trim())
                payload.credential_reference = credentialReference.trim();
        }
        if (['ssh_inventory', 'winrm_inventory'].includes(kind)) {
            if (inventoryProfile) payload.inventory_profile = inventoryProfile;
        }
        if (kind === 'ssh_inventory' && hostKeyFingerprint.trim())
            payload.host_key_fingerprint = hostKeyFingerprint.trim();

        setSubmitting(true);
        setError(null);
        try {
            if (editing) await axios.patch(url, payload);
            else await axios.post(url, payload);
            onSaved();
            close();
        } catch (requestError) {
            setError(failureMessage(requestError));
        } finally {
            setSubmitting(false);
        }
    };

    const needsPort = ['tcp', 'dns', 'tls', 'snmp', 'ssh_inventory'].includes(
        kind,
    );
    const needsCredential = [
        'snmp',
        'ssh_inventory',
        'winrm_inventory',
    ].includes(kind);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) close();
            }}
        >
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {editing
                            ? `Update ${monitor.name}`
                            : 'Create native direct monitor'}
                    </DialogTitle>
                    <DialogDescription>
                        The main application runs this check over the Site
                        SD-WAN. The target must already be inside an active
                        direct discovery scope.
                    </DialogDescription>
                </DialogHeader>
                <form className="space-y-4" onSubmit={submit}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {!editing ? (
                            <div className="space-y-2">
                                <Label htmlFor="native-monitor-device">
                                    Device
                                </Label>
                                <Select
                                    value={deviceId}
                                    onValueChange={setDeviceId}
                                >
                                    <SelectTrigger id="native-monitor-device">
                                        <SelectValue placeholder="Select a Site Device" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {management.devices.map((device) => (
                                            <SelectItem
                                                key={device.id}
                                                value={device.id.toString()}
                                            >
                                                {device.name} ·{' '}
                                                {device.site.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        ) : (
                            <div className="rounded-lg border p-3 text-sm">
                                <strong>{monitor.device.name}</strong>
                                <p className="text-muted-foreground">
                                    {monitor.kind.replaceAll('_', ' ')}
                                </p>
                            </div>
                        )}
                        <div className="space-y-2">
                            <Label htmlFor="native-monitor-profile">
                                Policy profile
                            </Label>
                            <Select
                                value={profileId}
                                onValueChange={setProfileId}
                            >
                                <SelectTrigger id="native-monitor-profile">
                                    <SelectValue placeholder="Select a profile" />
                                </SelectTrigger>
                                <SelectContent>
                                    {management.profiles.map((profile) => (
                                        <SelectItem
                                            key={profile.id}
                                            value={profile.id.toString()}
                                        >
                                            {profile.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    {!editing ? (
                        <div className="space-y-2">
                            <Label htmlFor="native-monitor-kind">
                                Native adapter
                            </Label>
                            <Select value={kind} onValueChange={setKind}>
                                <SelectTrigger id="native-monitor-kind">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {management.kinds.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    ) : null}
                    <div className="space-y-2">
                        <Label htmlFor="native-monitor-name">
                            Monitor name
                        </Label>
                        <Input
                            id="native-monitor-name"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            maxLength={128}
                            required
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="native-monitor-target">
                            {editing
                                ? 'Replacement target (optional)'
                                : 'Approved target'}
                        </Label>
                        <Input
                            id="native-monitor-target"
                            value={target}
                            onChange={(event) => setTarget(event.target.value)}
                            required={!editing}
                            autoComplete="off"
                        />
                        <p className="text-xs text-muted-foreground">
                            {editing
                                ? 'Leave blank to keep the existing hidden target.'
                                : 'Targets are stored for execution but never projected into the Monitoring workspace or audit metadata.'}
                        </p>
                    </div>
                    {needsPort ? (
                        <div className="space-y-2">
                            <Label htmlFor="native-monitor-port">
                                Port {editing ? '(optional)' : ''}
                            </Label>
                            <Input
                                id="native-monitor-port"
                                type="number"
                                min={1}
                                max={65535}
                                value={port}
                                onChange={(event) =>
                                    setPort(event.target.value)
                                }
                                required={!editing && kind === 'tcp'}
                            />
                        </div>
                    ) : null}
                    {kind === 'dns' ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="native-monitor-dns-name">
                                    DNS query name
                                </Label>
                                <Input
                                    id="native-monitor-dns-name"
                                    value={dnsName}
                                    onChange={(event) =>
                                        setDnsName(event.target.value)
                                    }
                                    required={!editing}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="native-monitor-dns-type">
                                    Record type
                                </Label>
                                <Select
                                    value={dnsType}
                                    onValueChange={setDnsType}
                                >
                                    <SelectTrigger id="native-monitor-dns-type">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {[
                                            'A',
                                            'AAAA',
                                            'CNAME',
                                            'MX',
                                            'TXT',
                                        ].map((value) => (
                                            <SelectItem
                                                key={value}
                                                value={value}
                                            >
                                                {value}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor="native-monitor-dns-answers">
                                    Expected answers (optional)
                                </Label>
                                <Textarea
                                    id="native-monitor-dns-answers"
                                    value={expectedAnswers}
                                    onChange={(event) =>
                                        setExpectedAnswers(event.target.value)
                                    }
                                    rows={2}
                                />
                            </div>
                        </div>
                    ) : null}
                    {kind === 'http' ? (
                        <div className="space-y-2">
                            <Label htmlFor="native-monitor-http-status">
                                Expected HTTP statuses
                            </Label>
                            <Input
                                id="native-monitor-http-status"
                                value={expectedStatus}
                                onChange={(event) =>
                                    setExpectedStatus(event.target.value)
                                }
                                placeholder="200, 204"
                            />
                        </div>
                    ) : null}
                    {kind === 'tls' ? (
                        <div className="space-y-2">
                            <Label htmlFor="native-monitor-warn-days">
                                Certificate warning days
                            </Label>
                            <Input
                                id="native-monitor-warn-days"
                                type="number"
                                min={1}
                                max={365}
                                value={warnDays}
                                onChange={(event) =>
                                    setWarnDays(event.target.value)
                                }
                            />
                        </div>
                    ) : null}
                    {needsCredential ? (
                        <div className="space-y-2">
                            <Label htmlFor="native-monitor-credential">
                                Credential reference{' '}
                                {editing ? '(blank preserves current)' : ''}
                            </Label>
                            <Input
                                id="native-monitor-credential"
                                value={credentialReference}
                                onChange={(event) =>
                                    setCredentialReference(event.target.value)
                                }
                                required={!editing}
                                autoComplete="off"
                            />
                            <p className="text-xs text-muted-foreground">
                                Use only an active Site reference. Secret
                                material is leased at execution and never
                                entered here.
                            </p>
                        </div>
                    ) : null}
                    {['ssh_inventory', 'winrm_inventory'].includes(kind) ? (
                        <div className="space-y-2">
                            <Label htmlFor="native-monitor-inventory-profile">
                                Read-only inventory profile
                            </Label>
                            <Select
                                value={inventoryProfile}
                                onValueChange={setInventoryProfile}
                            >
                                <SelectTrigger id="native-monitor-inventory-profile">
                                    <SelectValue placeholder="Select an approved profile" />
                                </SelectTrigger>
                                <SelectContent>
                                    {kind === 'ssh_inventory' ? (
                                        <SelectItem value="linux.basic">
                                            Linux basic
                                        </SelectItem>
                                    ) : (
                                        <SelectItem value="windows.basic">
                                            Windows basic
                                        </SelectItem>
                                    )}
                                </SelectContent>
                            </Select>
                        </div>
                    ) : null}
                    {kind === 'ssh_inventory' ? (
                        <div className="space-y-2">
                            <Label htmlFor="native-monitor-host-key">
                                Pinned SSH host-key fingerprint
                            </Label>
                            <Input
                                id="native-monitor-host-key"
                                value={hostKeyFingerprint}
                                onChange={(event) =>
                                    setHostKeyFingerprint(event.target.value)
                                }
                                placeholder="SHA256:…"
                                required={!editing}
                                autoComplete="off"
                            />
                        </div>
                    ) : null}
                    <label className="flex items-start gap-3 rounded-lg border p-3 text-sm">
                        <Checkbox
                            checked={affectsAvailability}
                            onCheckedChange={(checked) =>
                                setAffectsAvailability(Boolean(checked))
                            }
                        />
                        <span>
                            <strong>Affects Device availability</strong>
                            <span className="mt-1 block text-xs text-muted-foreground">
                                Use only for checks that should change the
                                canonical Device availability state.
                            </span>
                        </span>
                    </label>
                    {error ? (
                        <p className="text-sm text-destructive" role="alert">
                            {error}
                        </p>
                    ) : null}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={close}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                submitting ||
                                !profileId ||
                                !name.trim() ||
                                (!editing && (!deviceId || !target.trim())) ||
                                createDefinitionMissing
                            }
                        >
                            {submitting
                                ? 'Applying…'
                                : editing
                                  ? 'Apply monitor update'
                                  : 'Create direct monitor'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function NativeMonitorDeactivateDialog({
    open,
    onOpenChange,
    monitor,
    onDeactivated,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    monitor: NativeMonitorTarget | null;
    onDeactivated: () => void;
}) {
    const [reasonCode, setReasonCode] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    useEffect(() => {
        if (!open) {
            setReasonCode('');
            setError(null);
        }
    }, [open]);
    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (!monitor?.actions.deactivate_url || !reasonCode) return;
        setSubmitting(true);
        setError(null);
        try {
            await axios.post(monitor.actions.deactivate_url, {
                reason_code: reasonCode,
            });
            onDeactivated();
            onOpenChange(false);
        } catch (requestError) {
            setError(failureMessage(requestError));
        } finally {
            setSubmitting(false);
        }
    };
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Deactivate {monitor?.name ?? 'monitor'}
                    </DialogTitle>
                    <DialogDescription>
                        Scheduling stops immediately. Active dependency records
                        must be removed or replaced first.
                    </DialogDescription>
                </DialogHeader>
                <form className="space-y-4" onSubmit={submit}>
                    <div className="space-y-2">
                        <Label htmlFor="native-monitor-deactivate-reason">
                            Operational reason
                        </Label>
                        <Select
                            value={reasonCode}
                            onValueChange={setReasonCode}
                        >
                            <SelectTrigger id="native-monitor-deactivate-reason">
                                <SelectValue placeholder="Select a safe audit reason" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="replaced">
                                    Replaced by an approved definition
                                </SelectItem>
                                <SelectItem value="obsolete">
                                    Obsolete check
                                </SelectItem>
                                <SelectItem value="coverage_removed">
                                    Coverage intentionally removed
                                </SelectItem>
                                <SelectItem value="device_retired">
                                    Device retired
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            Audit history stores only this approved reason code,
                            never a target or credential.
                        </p>
                    </div>
                    {error ? (
                        <p className="text-sm text-destructive" role="alert">
                            {error}
                        </p>
                    ) : null}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={submitting || !reasonCode}
                        >
                            <ShieldX className="mr-2 h-4 w-4" />
                            {submitting
                                ? 'Deactivating…'
                                : 'Deactivate monitor'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
