import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import {
    TabsRoot as Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import { cn } from '@/lib/utils';
import { Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    BadgeCheck,
    Cake,
    CalendarDays,
    DoorOpen,
    Heart,
    Link2,
    Loader2,
    Shield,
    User,
    UserCog,
    UserPlus,
} from 'lucide-react';
import { useMemo, useState, type ComponentType } from 'react';

// ── Types ─────────────────────────────────────────────────────────────────

export type ClientStatus = 'active' | 'onboarding' | 'inactive';
export type ClientRiskLevel = 'low' | 'medium' | 'high';

export type ClientRecord = {
    id: number;
    first_name: string;
    last_name: string;
    preferred_name?: string | null;
    full_name?: string | null;
    status: string;
    profile_photo_url?: string | null;
    date_of_birth?: string | null;
    age?: number | null;
    gender?: string | null;
    risk_level?: string | null;
    safeguarding_flag?: boolean;
    service_start_date?: string | null;
    funding_type?: string | null;
    key_worker?: { id: number; name: string } | null;
    service_context?: { id: number; name: string; type?: string | null } | null;
    room_name?: string | null;
};

export type AvailableClient = {
    id: number;
    first_name: string;
    last_name: string;
    preferred_name?: string | null;
    full_name?: string | null;
    status: string;
};

// ── Helpers ───────────────────────────────────────────────────────────────

export function getClientDisplayName(c: {
    first_name: string;
    last_name: string;
    preferred_name?: string | null;
}): string {
    const full = `${c.first_name ?? ''} ${c.last_name ?? ''}`.trim();
    if (c.preferred_name && c.preferred_name.trim() && c.preferred_name !== c.first_name) {
        return `${c.preferred_name} (${full})`;
    }
    return full;
}

export function getClientInitials(c: {
    first_name?: string | null;
    last_name?: string | null;
}): string {
    const f = (c.first_name?.[0] ?? '').toUpperCase();
    const l = (c.last_name?.[0] ?? '').toUpperCase();
    return (f + l) || '?';
}

const STATUS_STYLES: Record<string, { label: string; cls: string; ring: string }> = {
    active: {
        label: 'Active',
        cls: 'border-status-success/30 bg-status-success-bg text-status-success',
        ring: 'ring-status-success/40',
    },
    onboarding: {
        label: 'Onboarding',
        cls: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
        ring: 'ring-status-warning/40',
    },
    inactive: {
        label: 'Inactive',
        cls: 'border-border bg-muted/40 text-muted-foreground',
        ring: 'ring-border',
    },
};

export function getClientStatusStyle(status?: string | null) {
    return STATUS_STYLES[status ?? 'inactive'] ?? STATUS_STYLES.inactive;
}

const RISK_STYLES: Record<string, { label: string; cls: string }> = {
    low: {
        label: 'Low risk',
        cls: 'border-status-success/30 text-status-success',
    },
    medium: {
        label: 'Medium risk',
        cls: 'border-status-warning/30 text-status-warning',
    },
    high: {
        label: 'High risk',
        cls: 'border-status-critical/30 text-status-critical',
    },
};

export function getClientRiskStyle(level?: string | null) {
    return RISK_STYLES[level ?? 'low'] ?? RISK_STYLES.low;
}

// ── Quick-add fields (used inside the "Create new" tab) ──────────────────

type QuickAddValues = {
    first_name: string;
    last_name: string;
    preferred_name: string;
    date_of_birth: string;
    gender: string;
    status: ClientStatus;
    risk_level: ClientRiskLevel;
    phone: string;
    email: string;
    nhi_number: string;
};

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="mt-1 text-xs text-status-critical">{message}</p>;
}

function QuickAddFields({
    form,
}: {
    form: ReturnType<typeof useForm<QuickAddValues>>;
}) {
    return (
        <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2">
                <div>
                    <Label htmlFor="qc-first">
                        First name <span className="text-status-critical">*</span>
                    </Label>
                    <Input
                        id="qc-first"
                        value={form.data.first_name}
                        onChange={(e) =>
                            form.setData('first_name', e.target.value)
                        }
                        required
                    />
                    <FieldError message={form.errors.first_name} />
                </div>
                <div>
                    <Label htmlFor="qc-last">
                        Last name <span className="text-status-critical">*</span>
                    </Label>
                    <Input
                        id="qc-last"
                        value={form.data.last_name}
                        onChange={(e) =>
                            form.setData('last_name', e.target.value)
                        }
                        required
                    />
                    <FieldError message={form.errors.last_name} />
                </div>
                <div>
                    <Label htmlFor="qc-preferred">Preferred name</Label>
                    <Input
                        id="qc-preferred"
                        value={form.data.preferred_name}
                        onChange={(e) =>
                            form.setData('preferred_name', e.target.value)
                        }
                        placeholder="What they like to be called"
                    />
                    <FieldError message={form.errors.preferred_name} />
                </div>
                <div>
                    <Label htmlFor="qc-dob">Date of birth</Label>
                    <Input
                        id="qc-dob"
                        type="date"
                        value={form.data.date_of_birth}
                        onChange={(e) =>
                            form.setData('date_of_birth', e.target.value)
                        }
                    />
                    <FieldError message={form.errors.date_of_birth} />
                </div>
                <div>
                    <Label htmlFor="qc-status">Status</Label>
                    <Select
                        value={form.data.status}
                        onValueChange={(v) =>
                            form.setData('status', v as ClientStatus)
                        }
                    >
                        <SelectTrigger id="qc-status">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="onboarding">
                                Onboarding
                            </SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>
                    <FieldError message={form.errors.status} />
                </div>
                <div>
                    <Label htmlFor="qc-risk">Risk level</Label>
                    <Select
                        value={form.data.risk_level}
                        onValueChange={(v) =>
                            form.setData('risk_level', v as ClientRiskLevel)
                        }
                    >
                        <SelectTrigger id="qc-risk">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="low">Low</SelectItem>
                            <SelectItem value="medium">Medium</SelectItem>
                            <SelectItem value="high">High</SelectItem>
                        </SelectContent>
                    </Select>
                    <FieldError message={form.errors.risk_level} />
                </div>
                <div>
                    <Label htmlFor="qc-gender">Gender</Label>
                    <Input
                        id="qc-gender"
                        value={form.data.gender}
                        onChange={(e) =>
                            form.setData('gender', e.target.value)
                        }
                        placeholder="e.g. Male, Female, Non-binary"
                    />
                    <FieldError message={form.errors.gender} />
                </div>
                <div>
                    <Label htmlFor="qc-nhi">NHI number</Label>
                    <Input
                        id="qc-nhi"
                        value={form.data.nhi_number}
                        onChange={(e) =>
                            form.setData(
                                'nhi_number',
                                e.target.value.toUpperCase(),
                            )
                        }
                        placeholder="e.g. ZAC5961"
                        maxLength={10}
                    />
                    <FieldError message={form.errors.nhi_number} />
                </div>
                <div>
                    <Label htmlFor="qc-phone">Phone</Label>
                    <Input
                        id="qc-phone"
                        value={form.data.phone}
                        onChange={(e) =>
                            form.setData('phone', e.target.value)
                        }
                        placeholder="+64 21 …"
                    />
                    <FieldError message={form.errors.phone} />
                </div>
                <div>
                    <Label htmlFor="qc-email">Email</Label>
                    <Input
                        id="qc-email"
                        type="email"
                        value={form.data.email}
                        onChange={(e) =>
                            form.setData('email', e.target.value)
                        }
                    />
                    <FieldError message={form.errors.email} />
                </div>
            </div>
            <p className="text-xs text-muted-foreground">
                You'll be able to add medical, support plan, funding and other
                full details from the client's profile after saving.
            </p>
        </div>
    );
}

// ── Add Client dialog (tabs: Link existing | Create new) ──────────────────

export function AddClientDialog({
    siteId,
    availableClients,
    isOpen,
    onClose,
}: {
    siteId: number;
    availableClients: AvailableClient[];
    isOpen: boolean;
    onClose: () => void;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-2xl">
                {isOpen && (
                    <AddClientBody
                        siteId={siteId}
                        availableClients={availableClients}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function AddClientBody({
    siteId,
    availableClients,
    onClose,
}: {
    siteId: number;
    availableClients: AvailableClient[];
    onClose: () => void;
}) {
    const [tab, setTab] = useState<'link' | 'create'>(
        availableClients.length > 0 ? 'link' : 'create',
    );

    return (
        <>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <UserPlus className="h-4 w-4 text-primary" />
                    Add a client to this site
                </DialogTitle>
                <DialogDescription>
                    Link an existing client, or quick-create a new one. Full
                    profile details can be added later.
                </DialogDescription>
            </DialogHeader>

            <Tabs
                value={tab}
                onValueChange={(v) => setTab(v as 'link' | 'create')}
                className="mt-3"
            >
                <TabsList className="grid w-full grid-cols-2">
                    <TabsTrigger value="link">
                        <Link2 className="mr-1.5 h-3.5 w-3.5" />
                        Link existing
                    </TabsTrigger>
                    <TabsTrigger value="create">
                        <UserPlus className="mr-1.5 h-3.5 w-3.5" />
                        Create new
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="link" className="mt-4">
                    <LinkExistingForm
                        siteId={siteId}
                        availableClients={availableClients}
                        onClose={onClose}
                        onSwitchToCreate={() => setTab('create')}
                    />
                </TabsContent>

                <TabsContent value="create" className="mt-4">
                    <QuickCreateForm siteId={siteId} onClose={onClose} />
                </TabsContent>
            </Tabs>
        </>
    );
}

function LinkExistingForm({
    siteId,
    availableClients,
    onClose,
    onSwitchToCreate,
}: {
    siteId: number;
    availableClients: AvailableClient[];
    onClose: () => void;
    onSwitchToCreate: () => void;
}) {
    const [query, setQuery] = useState('');
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return availableClients;
        return availableClients.filter((c) => {
            const hay = `${c.first_name} ${c.last_name} ${c.preferred_name ?? ''}`.toLowerCase();
            return hay.includes(q);
        });
    }, [availableClients, query]);

    if (availableClients.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center rounded-xl border border-dashed py-10 text-center">
                <div className="rounded-full bg-muted/40 p-3">
                    <User className="h-6 w-6 text-muted-foreground" />
                </div>
                <p className="mt-3 text-sm font-medium">
                    No unassigned clients in your organisation
                </p>
                <p className="mt-1 max-w-sm text-xs text-muted-foreground">
                    Every client is already linked to a site. To move a client
                    to this site, open their profile and update their site, or
                    create a brand-new client.
                </p>
                <Button
                    type="button"
                    size="sm"
                    className="mt-4"
                    onClick={onSwitchToCreate}
                >
                    <UserPlus className="mr-1 h-4 w-4" />
                    Create a new client
                </Button>
            </div>
        );
    }

    const handleLink = () => {
        if (!selectedId) return;
        setSubmitting(true);
        router.post(
            `/sites/${siteId}/clients/link`,
            { client_id: selectedId },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSubmitting(false),
                onSuccess: () => onClose(),
            },
        );
    };

    return (
        <div className="space-y-4">
            <div>
                <Label htmlFor="link-search">Search clients</Label>
                <Input
                    id="link-search"
                    placeholder="Search by name…"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                />
            </div>

            <div className="max-h-72 overflow-y-auto rounded-xl border bg-card/40">
                {filtered.length === 0 ? (
                    <p className="px-4 py-6 text-center text-xs text-muted-foreground">
                        No clients match "{query}".
                    </p>
                ) : (
                    <ul className="divide-y">
                        {filtered.map((c) => {
                            const active = selectedId === c.id;
                            const status = getClientStatusStyle(c.status);
                            return (
                                <li key={c.id}>
                                    <button
                                        type="button"
                                        onClick={() => setSelectedId(c.id)}
                                        className={cn(
                                            'flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm transition-colors',
                                            active
                                                ? 'bg-primary/10'
                                                : 'hover:bg-muted/50',
                                        )}
                                    >
                                        <div className="flex items-center gap-3">
                                            <Avatar className="size-8">
                                                <AvatarFallback className="text-[10px]">
                                                    {getClientInitials(c)}
                                                </AvatarFallback>
                                            </Avatar>
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">
                                                    {getClientDisplayName(c)}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    Currently unassigned
                                                </p>
                                            </div>
                                        </div>
                                        <Badge
                                            variant="outline"
                                            className={cn(
                                                'text-[10px]',
                                                status.cls,
                                            )}
                                        >
                                            {status.label}
                                        </Badge>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            <DialogFooter>
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="button"
                    onClick={handleLink}
                    disabled={!selectedId || submitting}
                >
                    {submitting && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Link client
                </Button>
            </DialogFooter>
        </div>
    );
}

function QuickCreateForm({
    siteId,
    onClose,
}: {
    siteId: number;
    onClose: () => void;
}) {
    const form = useForm<QuickAddValues>({
        first_name: '',
        last_name: '',
        preferred_name: '',
        date_of_birth: '',
        gender: '',
        status: 'onboarding',
        risk_level: 'low',
        phone: '',
        email: '',
        nhi_number: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/sites/${siteId}/clients`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <QuickAddFields form={form} />
            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Save client
                </Button>
            </DialogFooter>
        </form>
    );
}

// ── Show / overview dialog ────────────────────────────────────────────────

export function ShowClientDialog({
    client,
    siteId,
    canManage,
    isOpen,
    onClose,
    onUnlink,
}: {
    client: ClientRecord | null;
    siteId: number;
    canManage: boolean;
    isOpen: boolean;
    onClose: () => void;
    onUnlink: () => void;
}) {
    if (!client) {
        return (
            <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
                <DialogContent className="max-w-md" />
            </Dialog>
        );
    }

    const status = getClientStatusStyle(client.status);
    const risk = getClientRiskStyle(client.risk_level);
    const displayName = getClientDisplayName(client);
    const profileUrl = `/clients/${client.id}`;

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <div className="flex items-start gap-3">
                        <Avatar
                            className={cn(
                                'size-14 ring-2 ring-offset-2 ring-offset-background',
                                status.ring,
                            )}
                        >
                            {client.profile_photo_url && (
                                <AvatarImage
                                    src={client.profile_photo_url}
                                    alt={displayName}
                                />
                            )}
                            <AvatarFallback>
                                {getClientInitials(client)}
                            </AvatarFallback>
                        </Avatar>
                        <div className="min-w-0 flex-1">
                            <DialogTitle className="truncate text-base">
                                {displayName}
                            </DialogTitle>
                            <DialogDescription className="mt-1 flex flex-wrap items-center gap-2">
                                <Badge
                                    variant="outline"
                                    className={cn('text-[10px]', status.cls)}
                                >
                                    {status.label}
                                </Badge>
                                {client.risk_level && (
                                    <Badge
                                        variant="outline"
                                        className={cn(
                                            'text-[10px]',
                                            risk.cls,
                                        )}
                                    >
                                        <Shield className="mr-1 h-3 w-3" />
                                        {risk.label}
                                    </Badge>
                                )}
                                {client.safeguarding_flag && (
                                    <Badge
                                        variant="outline"
                                        className="border-status-critical/30 text-[10px] text-status-critical"
                                    >
                                        <AlertTriangle className="mr-1 h-3 w-3" />
                                        Safeguarding
                                    </Badge>
                                )}
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <div className="mt-2 space-y-2 text-sm">
                    <DetailRow
                        icon={Cake}
                        label="Age / DOB"
                        value={
                            client.age != null
                                ? `${client.age} yrs${
                                      client.date_of_birth
                                          ? ` · ${client.date_of_birth}`
                                          : ''
                                  }`
                                : client.date_of_birth ?? null
                        }
                    />
                    <DetailRow
                        icon={DoorOpen}
                        label="Room"
                        value={client.room_name}
                    />
                    <DetailRow
                        icon={UserCog}
                        label="Key worker"
                        value={client.key_worker?.name}
                    />
                    <DetailRow
                        icon={Heart}
                        label="Service"
                        value={client.service_context?.name}
                    />
                    <DetailRow
                        icon={BadgeCheck}
                        label="Funding"
                        value={client.funding_type}
                    />
                    <DetailRow
                        icon={CalendarDays}
                        label="Service start"
                        value={client.service_start_date}
                    />
                </div>

                <DialogFooter className="mt-4 flex-wrap gap-2 sm:flex-nowrap">
                    {canManage && (
                        <Button
                            type="button"
                            variant="outline"
                            className="text-status-critical"
                            onClick={onUnlink}
                        >
                            <Link2 className="mr-2 h-4 w-4" />
                            Unlink from site
                        </Button>
                    )}
                    <Button type="button" variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    <Button asChild>
                        <Link href={profileUrl}>
                            <User className="mr-2 h-4 w-4" />
                            View full profile
                        </Link>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function DetailRow({
    icon: Icon,
    label,
    value,
}: {
    icon: ComponentType<{ className?: string }>;
    label: string;
    value?: string | null;
}) {
    return (
        <div className="flex items-center gap-3 rounded-lg border bg-background/40 px-3 py-2">
            <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />
            <div className="min-w-0 flex-1">
                <p className="text-xs text-muted-foreground">{label}</p>
                {value ? (
                    <p className="truncate text-sm">{value}</p>
                ) : (
                    <p className="text-sm text-muted-foreground">—</p>
                )}
            </div>
        </div>
    );
}

// ── Unlink confirm ────────────────────────────────────────────────────────

export function UnlinkClientDialog({
    siteId,
    client,
    isOpen,
    onClose,
}: {
    siteId: number;
    client: ClientRecord | null;
    isOpen: boolean;
    onClose: () => void;
}) {
    const [submitting, setSubmitting] = useState(false);

    const handleUnlink = () => {
        if (!client) return;
        setSubmitting(true);
        router.post(
            `/sites/${siteId}/clients/${client.id}/unlink`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSubmitting(false),
                onSuccess: () => onClose(),
            },
        );
    };

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Unlink client from site?</DialogTitle>
                    <DialogDescription>
                        {client && (
                            <>
                                <span className="font-medium">
                                    {getClientDisplayName(client)}
                                </span>{' '}
                                will no longer be associated with this site.
                                Their profile and history remain intact — only
                                the site assignment is removed.
                            </>
                        )}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={handleUnlink}
                        disabled={submitting}
                    >
                        {submitting && (
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        )}
                        Unlink client
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

