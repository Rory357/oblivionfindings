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
import { Play, RadioTower, Server, ShieldX } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';

export type DiscoveryScopeCollectorOption = {
    id: number;
    name: string;
    site_id: number;
    authorised_site_ids: number[];
};

export type DiscoveryScopeManagement = {
    can_manage: boolean;
    create_url: string | null;
    protocols: string[];
    sites: Array<{ id: number; name: string }>;
    collectors?: DiscoveryScopeCollectorOption[];
};

export type GovernedDiscoveryScope = {
    id: number;
    name: string;
    status: string;
    site: { id: number; name: string } | null;
    collection_mode: string;
    collector_id?: number | null;
    collector?: { id: number; name: string; state?: string } | null;
    protocols: string[];
    max_targets_per_run: number;
    packets_per_second: number;
    actions: {
        can_manage: boolean;
        update_url: string | null;
        apply_url: string | null;
        deactivate_url: string | null;
    };
};

function list(value: string): string[] {
    return value
        .split(/\r?\n/)
        .map((item) => item.trim())
        .filter(Boolean);
}

function ports(value: string): number[] {
    return value
        .split(/[\s,]+/)
        .map(Number)
        .filter((item) => Number.isInteger(item));
}

const DEFAULT_PORTS: Record<string, string> = {
    tcp: '22, 80, 443, 161, 5985, 5986',
    dns: '53',
    http: '80, 443',
    tls: '443',
    snmp: '161',
};

const EXTENDED_DEFAULT_PORTS: Record<string, string> = {
    tcp: '22, 80, 161, 443, 515, 554, 631, 1883, 3389, 5000, 5001, 5985, 5986, 8000, 8080, 8443, 9100',
    dns: '53',
    http: '80, 443, 5000, 5001, 8000, 8080, 8443',
    tls: '443, 8443',
    snmp: '161',
    mdns: '5353',
    ssdp: '1900',
    onvif: '3702',
};

const PORTLESS_PROTOCOLS = new Set(['icmp', 'arp']);
const EXTENDED_PROTOCOL_DEFAULTS = [
    'icmp',
    'arp',
    'tcp',
    'dns',
    'http',
    'tls',
    'snmp',
    'mdns',
    'ssdp',
    'onvif',
];
const LEGACY_PROTOCOL_DEFAULTS = ['icmp', 'tcp', 'dns', 'tls'];

const PROTOCOL_LABELS: Record<string, string> = {
    icmp: 'ICMP · Ping',
    arp: 'ARP · Local MAC table',
    tcp: 'TCP · Common ports',
    dns: 'DNS · Hostnames',
    http: 'HTTP · Web banners',
    tls: 'TLS · Certificates',
    snmp: 'SNMP · Network gear',
    mdns: 'mDNS · Local services',
    ssdp: 'SSDP · Plug and play',
    onvif: 'ONVIF · Cameras',
};

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

    return 'The discovery change could not be applied. Review the approved Site bounds and try again.';
}

export function DiscoveryScopeDialog({
    open,
    onOpenChange,
    management,
    scope,
    onSaved,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    management: DiscoveryScopeManagement;
    scope: GovernedDiscoveryScope | null;
    onSaved: () => void;
}) {
    const editing = scope !== null;
    const defaultPortMap = management.protocols.includes('onvif')
        ? EXTENDED_DEFAULT_PORTS
        : DEFAULT_PORTS;
    const [siteId, setSiteId] = useState('');
    const [collectorId, setCollectorId] = useState('');
    const [name, setName] = useState('');
    const [cidrs, setCidrs] = useState('');
    const [seedHosts, setSeedHosts] = useState('');
    const [exclusions, setExclusions] = useState('');
    const [selectedProtocols, setSelectedProtocols] = useState<string[]>([]);
    const [allowedPorts, setAllowedPorts] = useState<Record<string, string>>({
        ...defaultPortMap,
    });
    const [snmpReference, setSnmpReference] = useState('');
    const [maxTargets, setMaxTargets] = useState('1024');
    const [packetsPerSecond, setPacketsPerSecond] = useState('20');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) return;
        const extended = management.protocols.includes('onvif');
        const nextPortMap = extended ? EXTENDED_DEFAULT_PORTS : DEFAULT_PORTS;
        const nextProtocols = (
            extended ? EXTENDED_PROTOCOL_DEFAULTS : LEGACY_PROTOCOL_DEFAULTS
        ).filter((protocol) => management.protocols.includes(protocol));
        setSiteId(
            scope?.site?.id.toString() ??
                (management.sites.length === 1
                    ? management.sites[0].id.toString()
                    : ''),
        );
        setCollectorId(
            String(scope?.collector_id ?? scope?.collector?.id ?? ''),
        );
        setName(scope?.name ?? '');
        setCidrs('');
        setSeedHosts('');
        setExclusions('');
        setSelectedProtocols(scope?.protocols ?? nextProtocols);
        setAllowedPorts(editing ? {} : { ...nextPortMap });
        setSnmpReference('');
        setMaxTargets(scope?.max_targets_per_run.toString() ?? '1024');
        setPacketsPerSecond(scope?.packets_per_second.toString() ?? '20');
        setError(null);
    }, [open, scope, management.sites, management.protocols, editing]);

    const close = () => {
        setCidrs('');
        setSeedHosts('');
        setExclusions('');
        setSnmpReference('');
        setError(null);
        onOpenChange(false);
    };

    const toggleProtocol = (protocol: string, checked: boolean) => {
        setSelectedProtocols((current) =>
            checked
                ? [...new Set([...current, protocol])]
                : current.filter((item) => item !== protocol),
        );
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        const url = scope?.actions.update_url ?? management.create_url;
        if (
            !url ||
            !name.trim() ||
            !selectedProtocols.length ||
            (!editing && (!siteId || !cidrs.trim()))
        ) {
            return;
        }
        const payload: Record<string, unknown> = {
            name: name.trim(),
            protocols: selectedProtocols,
            max_targets_per_run: Number(maxTargets),
            packets_per_second: Number(packetsPerSecond),
        };
        if (!editing) {
            payload.site_id = Number(siteId);
            if (collectorId !== '') {
                payload.collector_id = Number(collectorId);
            }
        } else {
            const initialCollectorId = String(
                scope?.collector_id ?? scope?.collector?.id ?? '',
            );
            if (collectorId !== initialCollectorId) {
                payload.collector_id =
                    collectorId === '' ? null : Number(collectorId);
            }
        }
        if (cidrs.trim()) payload.cidrs = list(cidrs);
        if (seedHosts.trim()) payload.seed_hosts = list(seedHosts);
        if (exclusions.trim()) payload.exclusions = list(exclusions);
        const submittedPortBounds = Object.fromEntries(
            selectedProtocols
                .filter(
                    (protocol) =>
                        !PORTLESS_PROTOCOLS.has(protocol) &&
                        Boolean(allowedPorts[protocol]?.trim()),
                )
                .map((protocol) => [protocol, ports(allowedPorts[protocol])]),
        );
        if (Object.keys(submittedPortBounds).length) {
            payload.port_bounds = submittedPortBounds;
        }
        if (selectedProtocols.includes('snmp') && snmpReference.trim()) {
            payload.snmp_credential_reference = snmpReference.trim();
        }

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

    const selectedSiteNumeric = Number(
        editing ? (scope?.site?.id ?? 0) : siteId,
    );
    const eligibleHubs = (management.collectors ?? []).filter(
        (hub) =>
            !selectedSiteNumeric ||
            hub.authorised_site_ids.includes(selectedSiteNumeric),
    );
    const selectedHub = eligibleHubs.find(
        (hub) => String(hub.id) === collectorId,
    );
    const handleSiteChange = (nextSiteId: string) => {
        setSiteId(nextSiteId);
        const numeric = Number(nextSiteId);
        if (
            collectorId !== '' &&
            numeric > 0 &&
            !(management.collectors ?? []).some(
                (hub) =>
                    String(hub.id) === collectorId &&
                    hub.authorised_site_ids.includes(numeric),
            )
        ) {
            setCollectorId('');
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) close();
            }}
        >
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{
                    maxWidth: 'min(92vw, 720px)',
                    width: 'min(92vw, 720px)',
                }}
            >
                <DialogHeader>
                    <DialogTitle>
                        {editing
                            ? `Update ${scope.name}`
                            : collectorId
                              ? 'Add hub discovery scope'
                              : 'Add direct discovery scope'}
                    </DialogTitle>
                    <DialogDescription>
                        Server scans run directly when routable, or you can
                        assign a Site hub covering this Site.
                    </DialogDescription>
                </DialogHeader>
                <form className="space-y-4" onSubmit={submit}>
                    {!editing ? (
                        <div className="space-y-2">
                            <Label htmlFor="discovery-scope-site">Site</Label>
                            <Select
                                value={siteId}
                                onValueChange={handleSiteChange}
                            >
                                <SelectTrigger id="discovery-scope-site">
                                    <SelectValue placeholder="Select an approved Site" />
                                </SelectTrigger>
                                <SelectContent>
                                    {management.sites.map((site) => (
                                        <SelectItem
                                            key={site.id}
                                            value={site.id.toString()}
                                        >
                                            {site.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    ) : (
                        <div className="rounded-lg border p-3 text-sm">
                            <strong>
                                {scope.site?.name ?? 'Site unavailable'}
                            </strong>
                            <p className="text-muted-foreground">
                                {selectedHub
                                    ? `Runs from: hub ${selectedHub.name}`
                                    : 'Runs from: server'}
                            </p>
                        </div>
                    )}
                    {eligibleHubs.length > 0 ? (
                        <div className="space-y-2">
                            <Label>Runs from</Label>
                            <div className="grid gap-2 sm:grid-cols-2">
                                <Button
                                    type="button"
                                    variant={
                                        collectorId === ''
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                    aria-pressed={collectorId === ''}
                                    className="h-auto flex-col items-start gap-1 p-3 text-left whitespace-normal"
                                    onClick={() => setCollectorId('')}
                                >
                                    <span className="flex items-center gap-1.5 font-semibold">
                                        <Server className="size-4" />
                                        Server (direct when routable)
                                    </span>
                                    <span className="text-caption">
                                        Runs from: server
                                    </span>
                                </Button>
                                {eligibleHubs.map((hub) => {
                                    const active =
                                        collectorId === String(hub.id);
                                    const isSdWan =
                                        selectedSiteNumeric > 0 &&
                                        hub.site_id !== selectedSiteNumeric;
                                    return (
                                        <Button
                                            key={hub.id}
                                            type="button"
                                            variant={
                                                active ? 'secondary' : 'outline'
                                            }
                                            aria-pressed={active}
                                            className="h-auto flex-col items-start gap-1 p-3 text-left whitespace-normal"
                                            onClick={() =>
                                                setCollectorId(String(hub.id))
                                            }
                                        >
                                            <span className="flex items-center gap-1.5 font-semibold">
                                                <RadioTower className="size-4" />
                                                Hub {hub.name}
                                            </span>
                                            <span className="text-caption">
                                                Runs from: hub {hub.name}
                                                {isSdWan
                                                    ? ' · covers this Site over SD-WAN'
                                                    : ''}
                                            </span>
                                        </Button>
                                    );
                                })}
                            </div>
                        </div>
                    ) : null}
                    <div className="space-y-2">
                        <Label htmlFor="discovery-scope-name">Scope name</Label>
                        <Input
                            id="discovery-scope-name"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            minLength={3}
                            maxLength={128}
                            required
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="discovery-scope-cidrs">
                            {editing
                                ? 'Replacement approved networks (optional)'
                                : 'Approved networks'}
                        </Label>
                        <Textarea
                            id="discovery-scope-cidrs"
                            value={cidrs}
                            onChange={(event) => setCidrs(event.target.value)}
                            rows={3}
                            required={!editing}
                            placeholder="10.44.0.0/16"
                        />
                        <p className="text-caption">
                            One CIDR per line. Existing ranges remain hidden;
                            leave blank while editing to preserve them.
                        </p>
                    </div>
                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">
                            Native discovery protocols
                        </legend>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {management.protocols.map((protocol) => (
                                <label
                                    key={protocol}
                                    className="flex items-center gap-2 rounded-lg border p-3 text-sm"
                                >
                                    <Checkbox
                                        checked={selectedProtocols.includes(
                                            protocol,
                                        )}
                                        onCheckedChange={(checked) =>
                                            toggleProtocol(
                                                protocol,
                                                Boolean(checked),
                                            )
                                        }
                                    />
                                    <span>
                                        {PROTOCOL_LABELS[protocol] ??
                                            protocol.toUpperCase()}
                                    </span>
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    <div className="space-y-3">
                        <div>
                            <Label>Protocol-specific allowed ports</Label>
                            <p className="text-caption">
                                Each adapter is limited to its own approved
                                ports. Leave every field blank while editing to
                                preserve the hidden allowlist.
                            </p>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {selectedProtocols
                                .filter(
                                    (protocol) =>
                                        !PORTLESS_PROTOCOLS.has(protocol),
                                )
                                .map((protocol) => (
                                    <div key={protocol} className="space-y-2">
                                        <Label
                                            htmlFor={`discovery-scope-ports-${protocol}`}
                                        >
                                            {protocol.toUpperCase()} ports
                                        </Label>
                                        <Input
                                            id={`discovery-scope-ports-${protocol}`}
                                            value={allowedPorts[protocol] ?? ''}
                                            onChange={(event) =>
                                                setAllowedPorts((current) => ({
                                                    ...current,
                                                    [protocol]:
                                                        event.target.value,
                                                }))
                                            }
                                            placeholder={
                                                defaultPortMap[protocol] ??
                                                'Approved ports'
                                            }
                                        />
                                    </div>
                                ))}
                        </div>
                    </div>
                    {selectedProtocols.includes('snmp') ? (
                        <div className="space-y-2">
                            <Label htmlFor="discovery-scope-snmp-reference">
                                SNMP credential reference (optional)
                            </Label>
                            <Input
                                id="discovery-scope-snmp-reference"
                                value={snmpReference}
                                onChange={(event) =>
                                    setSnmpReference(event.target.value)
                                }
                                autoComplete="off"
                            />
                            <p className="text-caption">
                                Leave blank to check standard read-only system
                                info, or enter a Site reference for SNMPv3.
                            </p>
                        </div>
                    ) : null}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="discovery-scope-target-limit">
                                Maximum targets per run
                            </Label>
                            <Input
                                id="discovery-scope-target-limit"
                                type="number"
                                min={1}
                                max={65536}
                                value={maxTargets}
                                onChange={(event) =>
                                    setMaxTargets(event.target.value)
                                }
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="discovery-scope-rate">
                                Packets per second
                            </Label>
                            <Input
                                id="discovery-scope-rate"
                                type="number"
                                min={1}
                                max={1000}
                                value={packetsPerSecond}
                                onChange={(event) =>
                                    setPacketsPerSecond(event.target.value)
                                }
                            />
                        </div>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="discovery-scope-seeds">
                                {editing
                                    ? 'Replacement seed hosts (optional)'
                                    : 'Seed hosts (optional)'}
                            </Label>
                            <Textarea
                                id="discovery-scope-seeds"
                                value={seedHosts}
                                onChange={(event) =>
                                    setSeedHosts(event.target.value)
                                }
                                rows={3}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="discovery-scope-exclusions">
                                {editing
                                    ? 'Replacement exclusions (optional)'
                                    : 'Exclusions (optional)'}
                            </Label>
                            <Textarea
                                id="discovery-scope-exclusions"
                                value={exclusions}
                                onChange={(event) =>
                                    setExclusions(event.target.value)
                                }
                                rows={3}
                            />
                        </div>
                    </div>
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
                                !name.trim() ||
                                !selectedProtocols.length ||
                                (!editing && (!siteId || !cidrs.trim()))
                            }
                        >
                            {submitting
                                ? 'Applying…'
                                : editing
                                  ? 'Apply scope update'
                                  : collectorId
                                    ? 'Add hub scope'
                                    : 'Add direct scope'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function DiscoveryScopeActionDialog({
    open,
    onOpenChange,
    scope,
    action,
    onApplied,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    scope: GovernedDiscoveryScope | null;
    action: 'apply' | 'deactivate';
    onApplied: () => void;
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
        const url =
            action === 'apply'
                ? scope?.actions.apply_url
                : scope?.actions.deactivate_url;
        if (!url || (action === 'deactivate' && !reasonCode)) return;
        setSubmitting(true);
        setError(null);
        try {
            await axios.post(
                url,
                action === 'deactivate' ? { reason_code: reasonCode } : {},
            );
            onApplied();
            onOpenChange(false);
        } catch (requestError) {
            setError(failureMessage(requestError));
        } finally {
            setSubmitting(false);
        }
    };
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                style={{
                    maxWidth: 'min(92vw, 480px)',
                    width: 'min(92vw, 480px)',
                }}
            >
                <DialogHeader>
                    <DialogTitle>
                        {action === 'apply'
                            ? `Run ${scope?.name ?? 'scope'} now`
                            : `Deactivate ${scope?.name ?? 'scope'}`}
                    </DialogTitle>
                    <DialogDescription>
                        {action === 'apply'
                            ? 'Queues one bounded central discovery run. If a run is already active, the existing run is returned without duplicating work.'
                            : 'Stops future discovery runs. An active queued or running discovery must finish first.'}
                    </DialogDescription>
                </DialogHeader>
                <form className="space-y-4" onSubmit={submit}>
                    {action === 'deactivate' ? (
                        <div className="space-y-2">
                            <Label htmlFor="discovery-scope-deactivate-reason">
                                Operational reason
                            </Label>
                            <Select
                                value={reasonCode}
                                onValueChange={setReasonCode}
                            >
                                <SelectTrigger id="discovery-scope-deactivate-reason">
                                    <SelectValue placeholder="Select a safe audit reason" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="network_retired">
                                        Approved network retired
                                    </SelectItem>
                                    <SelectItem value="scope_replaced">
                                        Replaced by another scope
                                    </SelectItem>
                                    <SelectItem value="duplicate_scope">
                                        Duplicate scope
                                    </SelectItem>
                                    <SelectItem value="site_connectivity_changed">
                                        Site connectivity changed
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    ) : null}
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
                            variant={
                                action === 'deactivate'
                                    ? 'destructive'
                                    : 'default'
                            }
                            disabled={
                                submitting ||
                                (action === 'deactivate' && !reasonCode)
                            }
                        >
                            {action === 'apply' ? (
                                <Play className="mr-2 h-4 w-4" />
                            ) : (
                                <ShieldX className="mr-2 h-4 w-4" />
                            )}
                            {submitting
                                ? 'Applying…'
                                : action === 'apply'
                                  ? 'Queue discovery run'
                                  : 'Deactivate scope'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
