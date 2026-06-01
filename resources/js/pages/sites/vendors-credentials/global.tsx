import { PageHero } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Building2,
    CheckCircle2,
    Clock,
    Eye,
    FileText,
    Globe,
    History,
    KeyRound,
    Lock,
    Mail,
    MapPin,
    MoreHorizontal,
    Package,
    Pencil,
    Phone,
    Plus,
    RefreshCcw,
    Search,
    ShieldCheck,
    Star,
    Trash2,
    Truck,
    X,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { toast } from 'sonner';
import {
    CREDENTIAL_TYPE_META,
    credentialTypeIcon,
    credentialTypeLabel,
    type FilterOption,
    FilterSelect,
    RotationBadge,
    rotationStatus,
    SITE_TYPE_META,
    SiteTypeBadge,
    type SiteOption,
} from '../_dialog-shared';
import {
    AddCredentialDialog,
    type CredentialRecord,
    DeleteCredentialDialog,
    EditCredentialDialog,
    RemoveTotpDialog,
    ShowCredentialDialog,
} from '../credentials/_dialogs';
import {
    AddVendorDialog,
    DeleteVendorDialog,
    EditVendorDialog,
    ShowVendorDialog,
    type VendorRecord,
} from '../vendors/_dialogs';
import { AuditLogDialog } from './_audit-dialog';
import { type ContextMenuItem, type ContextMenuState, RowContextMenu } from './_context-menu';

type VendorRow = VendorRecord & {
    site_id: number;
    site_name?: string | null;
    site_type?: string | null;
    is_active: boolean;
};

type CredentialRow = CredentialRecord & {
    site_id: number;
    site_name?: string | null;
    site_type?: string | null;
    vendor_service_type?: string | null;
};

type Props = {
    vendors: VendorRow[];
    credentials: CredentialRow[];
    sites: SiteOption[];
    serviceTypes: string[];
    credentialTypes: string[];
    filters: {
        site_id?: string | number;
        service_type?: string;
        vendor_status?: 'active' | 'inactive';
        preferred?: 'yes';
        credential_type?: string;
        requires_reauth?: 'yes' | 'no';
    };
    can: {
        vendors: boolean;
        credentials: boolean;
        vendorsManage: boolean;
        credentialsManage: boolean;
        credentialsReveal: boolean;
    };
};

type VendorDialog = { mode: 'add' | 'edit' | 'show' | 'delete' | null; target: VendorRow | null };
type CredentialDialog = {
    mode: 'add' | 'edit' | 'show' | 'delete' | 'remove-totp' | null;
    target: CredentialRow | null;
};

function csvEscape(cell: unknown) {
    return `"${String(cell ?? '').replace(/"/g, '""')}"`;
}

function downloadCsv(filename: string, head: string[], rows: (string | number | null | undefined)[][]) {
    const csv = [head.map(csvEscape).join(','), ...rows.map((r) => r.map(csvEscape).join(','))].join('\n');
    try {
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    } catch {
        // download blocked; ignore
    }
}

function firstNameFromPage(name?: string | null) {
    if (!name) return 'there';
    return name.trim().split(/\s+/)[0] || 'there';
}

export default function GlobalVendorsCredentials({
    vendors,
    credentials,
    sites,
    serviceTypes,
    credentialTypes,
    filters,
    can,
}: Props) {
    const page = usePage<{ auth?: { user?: { name?: string } } }>();
    const firstName = firstNameFromPage(page.props.auth?.user?.name);

    const [tab, setTab] = useState<'vendors' | 'credentials'>(can.vendors ? 'vendors' : 'credentials');
    const [search, setSearch] = useState('');
    const [siteFilter, setSiteFilter] = useState<string>(filters.site_id ? String(filters.site_id) : 'all');
    const [serviceTypeFilter, setServiceTypeFilter] = useState<string>(filters.service_type ?? 'all');
    const [vendorStatusFilter, setVendorStatusFilter] = useState<string>(filters.vendor_status ?? 'all');
    const [preferredFilter, setPreferredFilter] = useState<string>(filters.preferred ?? 'all');
    const [credentialTypeFilter, setCredentialTypeFilter] = useState<string>(filters.credential_type ?? 'all');
    const [reauthFilter, setReauthFilter] = useState<string>(filters.requires_reauth ?? 'all');
    const [rotFilter, setRotFilter] = useState<string>('all');

    // Dialog + menu state
    const [vendorDialog, setVendorDialog] = useState<VendorDialog>({ mode: null, target: null });
    const [credentialDialog, setCredentialDialog] = useState<CredentialDialog>({ mode: null, target: null });
    const [auditOpen, setAuditOpen] = useState<{ focusLabel?: string } | null>(null);
    const [ctxMenu, setCtxMenu] = useState<ContextMenuState | null>(null);

    const matchSearch = useCallback(
        (fields: (string | null | undefined)[]) => {
            const s = search.trim().toLowerCase();
            if (!s) return true;
            return fields.some((f) => (f ?? '').toLowerCase().includes(s));
        },
        [search],
    );

    const filteredVendors = useMemo(
        () =>
            vendors.filter((v) => {
                if (siteFilter !== 'all' && String(v.site_id) !== siteFilter) return false;
                if (serviceTypeFilter !== 'all' && v.service_type !== serviceTypeFilter) return false;
                if (vendorStatusFilter === 'active' && !v.is_active) return false;
                if (vendorStatusFilter === 'inactive' && v.is_active) return false;
                if (preferredFilter === 'yes' && !v.is_preferred) return false;
                return matchSearch([v.company_name, v.service_type, v.site_name, v.contact_name]);
            }),
        [vendors, siteFilter, serviceTypeFilter, vendorStatusFilter, preferredFilter, matchSearch],
    );

    const filteredCredentials = useMemo(
        () =>
            credentials.filter((c) => {
                if (siteFilter !== 'all' && String(c.site_id) !== siteFilter) return false;
                if (credentialTypeFilter !== 'all' && c.credential_type !== credentialTypeFilter) return false;
                if (reauthFilter === 'yes' && !c.requires_reauth) return false;
                if (reauthFilter === 'no' && c.requires_reauth) return false;
                if (rotFilter !== 'all') {
                    const key = rotationStatus(c.last_rotated_at).key;
                    if (rotFilter === 'ok' && key !== 'ok') return false;
                    if (rotFilter === 'due' && key !== 'due') return false;
                    if (rotFilter === 'overdue' && key !== 'overdue' && key !== 'unknown') return false;
                }
                return matchSearch([c.label, c.credential_type, c.site_name, c.vendor_name]);
            }),
        [credentials, siteFilter, credentialTypeFilter, reauthFilter, rotFilter, matchSearch],
    );

    // Counts respect the site filter only (matching how the hero already scopes).
    const scopedVendors = useMemo(
        () => vendors.filter((v) => siteFilter === 'all' || String(v.site_id) === siteFilter),
        [vendors, siteFilter],
    );
    const scopedCredentials = useMemo(
        () => credentials.filter((c) => siteFilter === 'all' || String(c.site_id) === siteFilter),
        [credentials, siteFilter],
    );

    const credHealth = useMemo(() => {
        const h = { ok: 0, due: 0, overdue: 0 };
        scopedCredentials.forEach((c) => {
            const key = rotationStatus(c.last_rotated_at).key;
            if (key === 'ok') h.ok += 1;
            else if (key === 'due') h.due += 1;
            else h.overdue += 1; // overdue + never-rotated
        });
        return h;
    }, [scopedCredentials]);

    const counts = {
        vendors: scopedVendors.length,
        activeVendors: scopedVendors.filter((v) => v.is_active).length,
        preferredVendors: scopedVendors.filter((v) => v.is_preferred).length,
        credentials: scopedCredentials.length,
        reauth: scopedCredentials.filter((c) => c.requires_reauth).length,
        rotationDue: scopedCredentials.filter((c) =>
            ['due', 'overdue', 'unknown'].includes(rotationStatus(c.last_rotated_at).key),
        ).length,
    };

    const hasFilters =
        siteFilter !== 'all' ||
        serviceTypeFilter !== 'all' ||
        vendorStatusFilter !== 'all' ||
        preferredFilter !== 'all' ||
        credentialTypeFilter !== 'all' ||
        reauthFilter !== 'all' ||
        rotFilter !== 'all' ||
        search.trim() !== '';

    const clearFilters = () => {
        setSearch('');
        setSiteFilter('all');
        setServiceTypeFilter('all');
        setVendorStatusFilter('all');
        setPreferredFilter('all');
        setCredentialTypeFilter('all');
        setReauthFilter('all');
        setRotFilter('all');
    };

    const siteById = (id?: number | null) => sites.find((s) => s.id === id);
    const lockedSiteFor = (row: { site_id: number; site_name?: string | null; site_type?: string | null }) => ({
        id: row.site_id,
        name: row.site_name ?? siteById(row.site_id)?.name ?? 'This site',
        type: row.site_type ?? siteById(row.site_id)?.type ?? '',
    });

    // ── filter option sets ─────────────────────────────────────────────────
    const siteOptions: FilterOption[] = [
        { value: 'all', label: 'All sites', icon: Globe },
        ...sites.map((s) => ({
            value: String(s.id),
            label: s.name,
            icon: SITE_TYPE_META[s.type]?.icon ?? Building2,
        })),
    ];
    const serviceOptions: FilterOption[] = [
        { value: 'all', label: 'All services' },
        ...serviceTypes.map((s) => ({ value: s, label: s })),
    ];
    const statusOptions: FilterOption[] = [
        { value: 'all', label: 'Any status' },
        { value: 'active', label: 'Active', icon: CheckCircle2 },
        { value: 'inactive', label: 'Inactive' },
    ];
    const preferredOptions: FilterOption[] = [
        { value: 'all', label: 'Any vendor' },
        { value: 'yes', label: 'Preferred only', icon: Star },
    ];
    const credTypeOptions: FilterOption[] = [
        { value: 'all', label: 'Any type' },
        ...credentialTypes.map((t) => ({ value: t, label: credentialTypeLabel(t), icon: credentialTypeIcon(t) })),
    ];
    const reauthOptions: FilterOption[] = [
        { value: 'all', label: 'Any reveal rule' },
        { value: 'yes', label: 'Re-auth required', icon: ShieldCheck },
        { value: 'no', label: 'No re-auth', icon: Lock },
    ];

    // ── quick actions (context menu) ───────────────────────────────────────
    const copyText = (text: string, label: string) => {
        try {
            void navigator.clipboard.writeText(text);
        } catch {
            // clipboard may be blocked
        }
        toast.success(`${label} copied`);
    };

    const toggleVendorFlag = (vendor: VendorRow, patch: { is_preferred?: boolean; is_active?: boolean }) => {
        router.patch(`/sites/${vendor.site_id}/vendors/${vendor.id}/flags`, patch, {
            preserveScroll: true,
            preserveState: true,
        });
    };
    const markRotated = (credential: CredentialRow) => {
        router.post(
            `/sites/${credential.site_id}/credentials/${credential.id}/rotate`,
            {},
            { preserveScroll: true, preserveState: true },
        );
    };
    const toggleReauth = (credential: CredentialRow) => {
        router.patch(
            `/sites/${credential.site_id}/credentials/${credential.id}/reauth`,
            { requires_reauth: !credential.requires_reauth },
            { preserveScroll: true, preserveState: true },
        );
    };

    const openVendorMenu = (e: React.MouseEvent, v: VendorRow) => {
        e.preventDefault();
        const items: ContextMenuItem[] = [
            { icon: Eye, label: 'View details', onClick: () => setVendorDialog({ mode: 'show', target: v }) },
            ...(can.vendorsManage
                ? [{ icon: Pencil, label: 'Edit vendor', onClick: () => setVendorDialog({ mode: 'edit', target: v }) }]
                : []),
            ...(v.phone
                ? [{ icon: Phone, label: 'Call main line', onClick: () => (window.location.href = `tel:${v.phone}`) }]
                : []),
            ...(v.after_hours_phone
                ? [
                      {
                          icon: Clock,
                          label: 'Call after-hours',
                          onClick: () => (window.location.href = `tel:${v.after_hours_phone}`),
                      },
                  ]
                : []),
            ...(v.email
                ? [{ icon: Mail, label: 'Email vendor', onClick: () => (window.location.href = `mailto:${v.email}`) }]
                : []),
            ...(v.phone ? [{ icon: FileText, label: 'Copy phone number', onClick: () => copyText(v.phone!, 'Phone') }] : []),
            { sep: true } as ContextMenuItem,
            ...(can.vendorsManage
                ? [
                      {
                          icon: Star,
                          label: v.is_preferred ? 'Remove preferred' : 'Mark as preferred',
                          onClick: () => toggleVendorFlag(v, { is_preferred: !v.is_preferred }),
                      },
                  ]
                : []),
            {
                icon: Lock,
                label: 'View linked credentials',
                onClick: () => {
                    setTab('credentials');
                    setCredentialTypeFilter('all');
                    setReauthFilter('all');
                    setRotFilter('all');
                    setSearch(v.company_name);
                },
            },
            ...(can.vendorsManage
                ? [
                      {
                          icon: v.is_active ? X : CheckCircle2,
                          label: v.is_active ? 'Deactivate vendor' : 'Activate vendor',
                          onClick: () => toggleVendorFlag(v, { is_active: !v.is_active }),
                      },
                  ]
                : []),
            ...(can.vendorsManage
                ? [
                      { sep: true } as ContextMenuItem,
                      {
                          icon: Trash2,
                          label: 'Delete vendor',
                          danger: true,
                          onClick: () => setVendorDialog({ mode: 'delete', target: v }),
                      },
                  ]
                : []),
        ];
        setCtxMenu({ x: e.clientX, y: e.clientY, header: { icon: Truck, title: v.company_name, sub: v.service_type }, items });
    };

    const openCredentialMenu = (e: React.MouseEvent, c: CredentialRow) => {
        e.preventDefault();
        const word = c.credential_type === 'pin' ? 'code' : 'password';
        const items: ContextMenuItem[] = [
            ...(can.credentialsReveal
                ? [
                      {
                          icon: Eye,
                          label: c.requires_reauth ? 'Re-authenticate & reveal' : `Reveal ${word}`,
                          onClick: () => setCredentialDialog({ mode: 'show', target: c }),
                      },
                  ]
                : [{ icon: Eye, label: 'View details', onClick: () => setCredentialDialog({ mode: 'show', target: c }) }]),
            ...(c.username
                ? [{ icon: FileText, label: 'Copy username', onClick: () => copyText(c.username!, 'Username') }]
                : []),
            ...(c.url
                ? [{ icon: Globe, label: 'Open URL', onClick: () => window.open(c.url!, '_blank', 'noopener') }]
                : []),
            { sep: true } as ContextMenuItem,
            ...(can.credentialsManage
                ? [
                      { icon: Pencil, label: 'Edit credential', onClick: () => setCredentialDialog({ mode: 'edit', target: c }) },
                      { icon: RefreshCcw, label: 'Mark rotated now', onClick: () => markRotated(c) },
                      {
                          icon: ShieldCheck,
                          label: c.requires_reauth ? 'Drop re-auth requirement' : 'Require re-auth to reveal',
                          onClick: () => toggleReauth(c),
                      },
                  ]
                : []),
            ...(can.credentials
                ? [{ icon: History, label: 'Reveal history', onClick: () => setAuditOpen({ focusLabel: c.label }) }]
                : []),
            ...(can.credentialsManage
                ? [
                      { sep: true } as ContextMenuItem,
                      {
                          icon: Trash2,
                          label: 'Delete credential',
                          danger: true,
                          onClick: () => setCredentialDialog({ mode: 'delete', target: c }),
                      },
                  ]
                : []),
        ];
        setCtxMenu({
            x: e.clientX,
            y: e.clientY,
            header: { icon: credentialTypeIcon(c.credential_type), title: c.label, sub: credentialTypeLabel(c.credential_type) },
            items,
        });
    };

    // ── CSV exports ────────────────────────────────────────────────────────
    const exportVendors = () => {
        downloadCsv(
            `vendors-${new Date().toISOString().slice(0, 10)}.csv`,
            ['Company', 'Service', 'Site', 'Contact', 'Phone', 'After-hours', 'Email', 'Preferred', 'Active'],
            filteredVendors.map((v) => [
                v.company_name,
                v.service_type,
                v.site_name,
                v.contact_name,
                v.phone,
                v.after_hours_phone,
                v.email,
                v.is_preferred ? 'Yes' : 'No',
                v.is_active ? 'Yes' : 'No',
            ]),
        );
        toast.success('Vendors exported to CSV');
    };
    const exportCredentials = () => {
        downloadCsv(
            `credentials-${new Date().toISOString().slice(0, 10)}.csv`,
            ['Label', 'Type', 'Site', 'Vendor', 'Username', 'URL', 'Re-auth', 'Authenticator', 'Last rotated', 'Health'],
            filteredCredentials.map((c) => [
                c.label,
                credentialTypeLabel(c.credential_type),
                c.site_name,
                c.vendor_name,
                c.username,
                c.url,
                c.requires_reauth ? 'Yes' : 'No',
                c.has_totp ? 'Yes' : 'No',
                c.last_rotated_at,
                rotationStatus(c.last_rotated_at).label,
            ]),
        );
        toast.success('Credentials exported to CSV');
    };

    // ── hero scope + narrative ─────────────────────────────────────────────
    const scopeLabel =
        siteFilter === 'all'
            ? `${sites.length} ${sites.length === 1 ? 'site' : 'sites'}`
            : siteById(Number(siteFilter))?.name ?? '1 site';

    const heroBadges = [
        ...(can.vendors
            ? [{ icon: Star, label: `${counts.preferredVendors} preferred`, tone: 'default' as const }]
            : []),
        ...(can.credentials && counts.reauth > 0
            ? [{ icon: ShieldCheck, label: `${counts.reauth} re-auth`, tone: 'warning' as const }]
            : []),
        ...(can.credentials && counts.rotationDue > 0
            ? [{ icon: Clock, label: `${counts.rotationDue} rotation due`, tone: 'warning' as const }]
            : []),
    ];

    const heroStats = [
        ...(can.vendors ? [{ label: 'Vendors', value: counts.vendors }] : []),
        ...(can.credentials ? [{ label: 'Credentials', value: counts.credentials }] : []),
        ...(can.credentials
            ? [{ label: 'Re-auth', value: counts.reauth, tone: counts.reauth > 0 ? ('warning' as const) : undefined }]
            : []),
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: 'Vendors & Credentials', href: '/vendors' },
            ]}
        >
            <Head title="Vendors & Credentials" />

            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    category="sites"
                    icon={Package}
                    title={
                        <span className="block space-y-1.5">
                            <span className="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-primary-foreground/80">
                                <span className="relative flex h-2 w-2">
                                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary-foreground/70 opacity-75" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-primary-foreground" />
                                </span>
                                Live · vendor directory &amp; access vault
                            </span>
                            <span className="block">
                                Kia ora {firstName}, here's who keeps the lights on across{' '}
                                <span className="underline decoration-primary-foreground/40 underline-offset-4">
                                    {scopeLabel}
                                </span>
                            </span>
                        </span>
                    }
                    description={
                        <>
                            {can.vendors && (
                                <>
                                    <strong className="font-semibold text-primary-foreground">{counts.vendors}</strong>{' '}
                                    service {counts.vendors === 1 ? 'provider' : 'providers'}
                                </>
                            )}
                            {can.vendors && can.credentials ? ' and ' : ''}
                            {can.credentials && (
                                <>
                                    <strong className="font-semibold text-primary-foreground">
                                        {counts.credentials}
                                    </strong>{' '}
                                    stored {counts.credentials === 1 ? 'credential' : 'credentials'}
                                </>
                            )}{' '}
                            in view.
                            {can.credentials && counts.reauth > 0 ? (
                                <>
                                    {' '}
                                    {counts.reauth} {counts.reauth === 1 ? 'credential needs' : 'credentials need'} re-auth
                                    to reveal.
                                </>
                            ) : null}
                            {can.vendors ? (
                                <>
                                    {' '}
                                    <strong className="font-semibold text-primary-foreground">
                                        {counts.preferredVendors}
                                    </strong>{' '}
                                    preferred {counts.preferredVendors === 1 ? 'vendor' : 'vendors'} on call.
                                </>
                            ) : null}
                        </>
                    }
                    meta={[
                        { icon: Building2, label: `${sites.length} ${sites.length === 1 ? 'site' : 'sites'}` },
                        ...(can.vendors ? [{ icon: Truck, label: `${counts.activeVendors} active vendors` }] : []),
                        { icon: ShieldCheck, label: 'Encrypted at rest · every reveal audited' },
                    ]}
                    badges={heroBadges}
                    stats={heroStats}
                    actions={
                        <>
                            {can.credentialsManage && (
                                <Button size="sm" onClick={() => setCredentialDialog({ mode: 'add', target: null })}>
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Add credential
                                </Button>
                            )}
                            {can.vendorsManage && (
                                <Button size="sm" variant="outline" onClick={() => setVendorDialog({ mode: 'add', target: null })}>
                                    <Truck className="mr-1.5 h-4 w-4" />
                                    Add vendor
                                </Button>
                            )}
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button size="icon" variant="outline" aria-label="More actions">
                                        <MoreHorizontal className="h-4 w-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="w-56">
                                    {can.vendors && (
                                        <DropdownMenuItem onClick={exportVendors}>
                                            <Truck className="mr-2 h-4 w-4" />
                                            Export vendors (CSV)
                                        </DropdownMenuItem>
                                    )}
                                    {can.credentials && (
                                        <DropdownMenuItem onClick={exportCredentials}>
                                            <Lock className="mr-2 h-4 w-4" />
                                            Export credentials (CSV)
                                        </DropdownMenuItem>
                                    )}
                                    {can.credentials && (
                                        <>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem onClick={() => setAuditOpen({ focusLabel: '' })}>
                                                <History className="mr-2 h-4 w-4" />
                                                View reveal &amp; audit log
                                            </DropdownMenuItem>
                                        </>
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </>
                    }
                    footer={
                        <div className="flex flex-wrap items-center gap-2 py-3">
                            <div className="relative min-w-[220px] flex-1">
                                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-primary-foreground/60" />
                                <Input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search company, credential, type, or site…"
                                    aria-label="Search vendors and credentials"
                                    className="border-primary-foreground/20 bg-primary-foreground/15 pl-9 text-primary-foreground placeholder:text-primary-foreground/70 focus-visible:ring-primary-foreground/30"
                                />
                            </div>
                            <FilterSelect
                                value={siteFilter}
                                onChange={setSiteFilter}
                                options={siteOptions}
                                variant="dark"
                                aria-label="Filter by site"
                            />
                            {tab === 'vendors' ? (
                                <>
                                    <FilterSelect
                                        value={serviceTypeFilter}
                                        onChange={setServiceTypeFilter}
                                        options={serviceOptions}
                                        variant="dark"
                                        widthClass="w-40"
                                        aria-label="Filter by service"
                                    />
                                    <FilterSelect
                                        value={vendorStatusFilter}
                                        onChange={setVendorStatusFilter}
                                        options={statusOptions}
                                        variant="dark"
                                        widthClass="w-36"
                                        aria-label="Filter by status"
                                    />
                                    <FilterSelect
                                        value={preferredFilter}
                                        onChange={setPreferredFilter}
                                        options={preferredOptions}
                                        variant="dark"
                                        widthClass="w-40"
                                        aria-label="Filter by preferred"
                                    />
                                </>
                            ) : (
                                <>
                                    <FilterSelect
                                        value={credentialTypeFilter}
                                        onChange={setCredentialTypeFilter}
                                        options={credTypeOptions}
                                        variant="dark"
                                        widthClass="w-40"
                                        aria-label="Filter by credential type"
                                    />
                                    <FilterSelect
                                        value={reauthFilter}
                                        onChange={setReauthFilter}
                                        options={reauthOptions}
                                        variant="dark"
                                        widthClass="w-44"
                                        aria-label="Filter by reveal rule"
                                    />
                                </>
                            )}
                            {hasFilters && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={clearFilters}
                                    className="gap-1.5 text-primary-foreground/80 hover:bg-primary-foreground/10 hover:text-primary-foreground"
                                >
                                    <X className="h-3.5 w-3.5" />
                                    Clear
                                </Button>
                            )}
                        </div>
                    }
                />

                <HealthStrip
                    can={can}
                    credHealth={credHealth}
                    credentials={counts.credentials}
                    reauth={counts.reauth}
                    activeVendors={counts.activeVendors}
                    preferredVendors={counts.preferredVendors}
                    onCredFilter={(key) => {
                        // Land on exactly the intended subset — clear sibling credential filters.
                        setTab('credentials');
                        setCredentialTypeFilter('all');
                        setReauthFilter('all');
                        setRotFilter(key);
                    }}
                    onReauth={() => {
                        setTab('credentials');
                        setCredentialTypeFilter('all');
                        setRotFilter('all');
                        setReauthFilter('yes');
                    }}
                    onVendorFilter={(which) => {
                        setTab('vendors');
                        setServiceTypeFilter('all');
                        setVendorStatusFilter(which === 'active' ? 'active' : 'all');
                        setPreferredFilter(which === 'preferred' ? 'yes' : 'all');
                    }}
                />

                {/* Segmented pill tabs */}
                {/* eslint-disable-next-line no-restricted-syntax -- segmented tab-bar container, not a Card */}
                <div className="inline-flex self-start rounded-xl border border-border bg-card p-1">
                    {can.vendors && (
                        <TabPill
                            active={tab === 'vendors'}
                            onClick={() => setTab('vendors')}
                            icon={Truck}
                            label="Vendors"
                            count={filteredVendors.length}
                        />
                    )}
                    {can.credentials && (
                        <TabPill
                            active={tab === 'credentials'}
                            onClick={() => setTab('credentials')}
                            icon={Lock}
                            label="Credentials"
                            count={filteredCredentials.length}
                        />
                    )}
                </div>

                {tab === 'vendors' && can.vendors ? (
                    <VendorTable
                        rows={filteredVendors}
                        hasFilters={hasFilters}
                        onOpen={(v) => setVendorDialog({ mode: 'show', target: v })}
                        onContext={openVendorMenu}
                    />
                ) : null}
                {tab === 'credentials' && can.credentials ? (
                    <CredentialTable
                        rows={filteredCredentials}
                        hasFilters={hasFilters}
                        onOpen={(c) => setCredentialDialog({ mode: 'show', target: c })}
                        onContext={openCredentialMenu}
                    />
                ) : null}
            </div>

            {/* ── Dialogs ──────────────────────────────────────────────── */}
            <AddVendorDialog
                isOpen={vendorDialog.mode === 'add'}
                sites={sites}
                onClose={() => setVendorDialog({ mode: null, target: null })}
            />
            {vendorDialog.target && (
                <>
                    <EditVendorDialog
                        isOpen={vendorDialog.mode === 'edit'}
                        siteId={vendorDialog.target.site_id}
                        vendor={vendorDialog.target}
                        lockedSite={lockedSiteFor(vendorDialog.target)}
                        onClose={() => setVendorDialog({ mode: null, target: null })}
                    />
                    <ShowVendorDialog
                        isOpen={vendorDialog.mode === 'show'}
                        vendor={vendorDialog.target}
                        canManage={can.vendorsManage}
                        onClose={() => setVendorDialog({ mode: null, target: null })}
                        onEdit={() => setVendorDialog((p) => ({ ...p, mode: 'edit' }))}
                        onDelete={() => setVendorDialog((p) => ({ ...p, mode: 'delete' }))}
                    />
                    <DeleteVendorDialog
                        isOpen={vendorDialog.mode === 'delete'}
                        siteId={vendorDialog.target.site_id}
                        vendor={vendorDialog.target}
                        onClose={() => setVendorDialog({ mode: null, target: null })}
                    />
                </>
            )}

            <AddCredentialDialog
                isOpen={credentialDialog.mode === 'add'}
                sites={sites}
                vendors={vendors}
                onClose={() => setCredentialDialog({ mode: null, target: null })}
            />
            {credentialDialog.target && (
                <>
                    <EditCredentialDialog
                        isOpen={credentialDialog.mode === 'edit'}
                        siteId={credentialDialog.target.site_id}
                        credential={credentialDialog.target}
                        lockedSite={lockedSiteFor(credentialDialog.target)}
                        vendors={vendors}
                        onClose={() => setCredentialDialog({ mode: null, target: null })}
                    />
                    <ShowCredentialDialog
                        isOpen={credentialDialog.mode === 'show'}
                        siteId={credentialDialog.target.site_id}
                        credential={credentialDialog.target}
                        canManage={can.credentialsManage}
                        canReveal={can.credentialsReveal}
                        onClose={() => setCredentialDialog({ mode: null, target: null })}
                        onEdit={() => setCredentialDialog((p) => ({ ...p, mode: 'edit' }))}
                        onDelete={() => setCredentialDialog((p) => ({ ...p, mode: 'delete' }))}
                        onRemoveTotp={() => setCredentialDialog((p) => ({ ...p, mode: 'remove-totp' }))}
                        onHistory={() => {
                            const label = credentialDialog.target?.label;
                            setCredentialDialog({ mode: null, target: null });
                            setAuditOpen({ focusLabel: label });
                        }}
                    />
                    <DeleteCredentialDialog
                        isOpen={credentialDialog.mode === 'delete'}
                        siteId={credentialDialog.target.site_id}
                        credential={credentialDialog.target}
                        onClose={() => setCredentialDialog({ mode: null, target: null })}
                    />
                    <RemoveTotpDialog
                        isOpen={credentialDialog.mode === 'remove-totp'}
                        siteId={credentialDialog.target.site_id}
                        credential={credentialDialog.target}
                        onClose={() => setCredentialDialog({ mode: null, target: null })}
                    />
                </>
            )}

            <AuditLogDialog
                isOpen={!!auditOpen}
                focusLabel={auditOpen?.focusLabel}
                siteId={siteFilter !== 'all' ? Number(siteFilter) : null}
                onClose={() => setAuditOpen(null)}
            />

            <RowContextMenu menu={ctxMenu} onClose={() => setCtxMenu(null)} />
        </AppLayout>
    );
}

// ── Tab pill ────────────────────────────────────────────────────────────────

function TabPill({
    active,
    onClick,
    icon: Icon,
    label,
    count,
}: {
    active: boolean;
    onClick: () => void;
    icon: typeof Truck;
    label: string;
    count: number;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- segmented tab control, not a standard Button
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'inline-flex items-center gap-2 rounded-lg px-3.5 py-1.5 text-sm font-medium transition-colors',
                active ? 'bg-accent text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
            )}
        >
            <Icon className="h-4 w-4" />
            {label}
            <span
                className={cn(
                    'rounded-full px-1.5 py-0.5 text-xs tabular-nums',
                    active ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground',
                )}
            >
                {count}
            </span>
        </button>
    );
}

// ── Health strip ─────────────────────────────────────────────────────────────

function HealthStrip({
    can,
    credHealth,
    credentials,
    reauth,
    activeVendors,
    preferredVendors,
    onCredFilter,
    onReauth,
    onVendorFilter,
}: {
    can: Props['can'];
    credHealth: { ok: number; due: number; overdue: number };
    credentials: number;
    reauth: number;
    activeVendors: number;
    preferredVendors: number;
    onCredFilter: (key: string) => void;
    onReauth: () => void;
    onVendorFilter: (which: 'active' | 'preferred') => void;
}) {
    const total = Math.max(1, credHealth.ok + credHealth.due + credHealth.overdue);
    const pct = (n: number) => `${(n / total) * 100}%`;

    return (
        // eslint-disable-next-line no-restricted-syntax -- two-zone health panel with custom layout, not a Card
        <div className="flex flex-col gap-4 rounded-2xl border border-border bg-card p-4 lg:flex-row lg:items-stretch">
            {can.credentials && (
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 text-sm font-medium">
                        <Lock className="h-4 w-4 text-muted-foreground" />
                        Credential health
                        <span className="text-xs font-normal text-muted-foreground">· {credentials} stored</span>
                    </div>
                    <div
                        className="mt-3 flex h-2 overflow-hidden rounded-full bg-muted"
                        role="img"
                        aria-label={`Rotation health: ${credHealth.ok} healthy, ${credHealth.due} due, ${credHealth.overdue} overdue`}
                    >
                        {credHealth.ok > 0 && <div className="bg-status-success" style={{ width: pct(credHealth.ok) }} />}
                        {credHealth.due > 0 && <div className="bg-status-warning" style={{ width: pct(credHealth.due) }} />}
                        {credHealth.overdue > 0 && (
                            <div className="bg-status-critical" style={{ width: pct(credHealth.overdue) }} />
                        )}
                    </div>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <HealthChip label="Healthy" n={credHealth.ok} dotClass="bg-status-success" onClick={() => onCredFilter('ok')} />
                        <HealthChip
                            label="Rotation due"
                            n={credHealth.due}
                            dotClass="bg-status-warning"
                            attn={credHealth.due > 0}
                            onClick={() => onCredFilter('due')}
                        />
                        <HealthChip
                            label="Overdue"
                            n={credHealth.overdue}
                            dotClass="bg-status-critical"
                            attn={credHealth.overdue > 0}
                            crit
                            onClick={() => onCredFilter('overdue')}
                        />
                        <HealthChip
                            label="Re-auth"
                            n={reauth}
                            dotClass="bg-status-info"
                            attn={reauth > 0}
                            onClick={onReauth}
                        />
                    </div>
                </div>
            )}

            {can.credentials && can.vendors && <div className="hidden w-px bg-border lg:block" />}

            {can.vendors && (
                <div className="lg:w-64">
                    <div className="flex items-center gap-2 text-sm font-medium">
                        <Truck className="h-4 w-4 text-muted-foreground" />
                        Vendor coverage
                    </div>
                    <div className="mt-3 grid grid-cols-2 gap-2">
                        {/* eslint-disable-next-line no-restricted-syntax -- clickable stat tile (filters the table) */}
                        <button
                            type="button"
                            onClick={() => onVendorFilter('active')}
                            className="rounded-xl border border-border bg-background/40 p-3 text-left transition-colors hover:border-primary/50"
                        >
                            <div className="text-xl font-bold tabular-nums">{activeVendors}</div>
                            <div className="text-xs text-muted-foreground">Active</div>
                        </button>
                        {/* eslint-disable-next-line no-restricted-syntax -- clickable stat tile (filters the table) */}
                        <button
                            type="button"
                            onClick={() => onVendorFilter('preferred')}
                            className="rounded-xl border border-border bg-background/40 p-3 text-left transition-colors hover:border-primary/50"
                        >
                            <div className="flex items-center gap-1 text-xl font-bold tabular-nums">
                                {preferredVendors}
                                <Star className="h-4 w-4 fill-status-warning text-status-warning" />
                            </div>
                            <div className="text-xs text-muted-foreground">Preferred</div>
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}

function HealthChip({
    label,
    n,
    dotClass,
    attn,
    crit,
    onClick,
}: {
    label: string;
    n: number;
    dotClass: string;
    attn?: boolean;
    crit?: boolean;
    onClick: () => void;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- filter chip, not a standard Button
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors hover:bg-muted',
                attn
                    ? crit
                        ? 'border-status-critical/40 bg-status-critical-bg'
                        : 'border-status-warning/40 bg-status-warning-bg'
                    : 'border-border',
            )}
        >
            <span className={cn('h-1.5 w-1.5 rounded-full', dotClass)} />
            {label}
            <span className="font-semibold tabular-nums">{n}</span>
        </button>
    );
}

// ── Tables ───────────────────────────────────────────────────────────────────

function TableShell({
    title,
    icon: Icon,
    subtitle,
    badge,
    children,
}: {
    title: string;
    icon: typeof Truck;
    subtitle: string;
    badge?: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- table surface with custom header, not a Card
        <div className="overflow-hidden rounded-2xl border border-border bg-card">
            <div className="flex items-start justify-between gap-3 border-b border-border px-4 py-3">
                <div>
                    <h3 className="flex items-center gap-2 text-sm font-semibold">
                        <Icon className="h-4 w-4 text-category-sites" />
                        {title}
                    </h3>
                    <p className="mt-0.5 text-xs text-muted-foreground">{subtitle}</p>
                </div>
                {badge}
            </div>
            <div className="overflow-x-auto">{children}</div>
        </div>
    );
}

function EmptyRow({ icon: Icon, title, sub, colSpan }: { icon: typeof Truck; title: string; sub: string; colSpan: number }) {
    return (
        <tr>
            <td colSpan={colSpan} className="px-4 py-16 text-center">
                <Icon className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                <p className="font-medium text-muted-foreground">{title}</p>
                <p className="mt-1 text-sm text-muted-foreground/70">{sub}</p>
            </td>
        </tr>
    );
}

const TH = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground';

function VendorTable({
    rows,
    hasFilters,
    onOpen,
    onContext,
}: {
    rows: VendorRow[];
    hasFilters: boolean;
    onOpen: (v: VendorRow) => void;
    onContext: (e: React.MouseEvent, v: VendorRow) => void;
}) {
    return (
        <TableShell
            title="Service providers"
            icon={Truck}
            subtitle={`Click a vendor to view · right-click for quick actions · ${rows.length} shown`}
        >
            <table className="w-full text-sm">
                <thead className="border-b bg-muted/50">
                    <tr>
                        <th className={TH}>Company</th>
                        <th className={cn(TH, 'hidden md:table-cell')}>Site</th>
                        <th className={cn(TH, 'hidden sm:table-cell')}>Service</th>
                        <th className={cn(TH, 'hidden lg:table-cell')}>Contact</th>
                        <th className={TH}>Status</th>
                        <th className={cn(TH, 'text-right')}>Actions</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.length === 0 ? (
                        <EmptyRow
                            icon={Truck}
                            title="No vendors found"
                            sub={hasFilters ? 'Try adjusting your filters' : 'Add a vendor to get started'}
                            colSpan={6}
                        />
                    ) : (
                        rows.map((v) => (
                            <tr
                                key={v.id}
                                className="group cursor-pointer transition-colors hover:bg-muted/40"
                                onClick={() => onOpen(v)}
                                onContextMenu={(e) => onContext(e, v)}
                            >
                                <td className="px-4 py-3">
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium group-hover:text-primary">{v.company_name}</span>
                                        {v.is_preferred && (
                                            <Star className="h-3.5 w-3.5 fill-status-warning text-status-warning" />
                                        )}
                                    </div>
                                    {v.contact_name && <div className="text-xs text-muted-foreground">{v.contact_name}</div>}
                                </td>
                                <td className="hidden px-4 py-3 md:table-cell">
                                    <span className="inline-flex items-center gap-1 text-sm">
                                        <MapPin className="h-3 w-3 text-muted-foreground" />
                                        {v.site_name ?? 'Unknown site'}
                                    </span>
                                    <div className="mt-1">
                                        <SiteTypeBadge type={v.site_type} />
                                    </div>
                                </td>
                                <td className="hidden px-4 py-3 sm:table-cell">
                                    <Badge variant="outline" className="border-border bg-muted text-muted-foreground">
                                        {v.service_type}
                                    </Badge>
                                </td>
                                <td className="hidden px-4 py-3 lg:table-cell" onClick={(e) => e.stopPropagation()}>
                                    <div className="flex flex-col gap-0.5 text-xs">
                                        {v.phone && (
                                            <a href={`tel:${v.phone}`} className="inline-flex items-center gap-1 text-primary hover:underline">
                                                <Phone className="h-3 w-3" />
                                                {v.phone}
                                            </a>
                                        )}
                                        {v.email && (
                                            <a href={`mailto:${v.email}`} className="inline-flex items-center gap-1 text-primary hover:underline">
                                                <Mail className="h-3 w-3" />
                                                <span className="max-w-[160px] truncate">{v.email}</span>
                                            </a>
                                        )}
                                        {!v.phone && !v.email && <span className="text-muted-foreground">—</span>}
                                    </div>
                                </td>
                                <td className="px-4 py-3">
                                    <Badge
                                        variant="outline"
                                        className={
                                            v.is_active
                                                ? 'border-status-success/30 bg-status-success-bg text-status-success'
                                                : 'border-border bg-muted text-muted-foreground'
                                        }
                                    >
                                        {v.is_active ? 'Active' : 'Inactive'}
                                    </Badge>
                                </td>
                                <td className="px-4 py-3 text-right" onClick={(e) => e.stopPropagation()}>
                                    <Button variant="ghost" size="sm" onClick={() => onOpen(v)}>
                                        <Eye className="mr-1 h-3.5 w-3.5" />
                                        View
                                    </Button>
                                </td>
                            </tr>
                        ))
                    )}
                </tbody>
            </table>
        </TableShell>
    );
}

function CredentialTable({
    rows,
    hasFilters,
    onOpen,
    onContext,
}: {
    rows: CredentialRow[];
    hasFilters: boolean;
    onOpen: (c: CredentialRow) => void;
    onContext: (e: React.MouseEvent, c: CredentialRow) => void;
}) {
    return (
        <TableShell
            title="Access vault"
            icon={Lock}
            subtitle={`Encrypted at rest · click to reveal (audited) · right-click for quick actions · ${rows.length} shown`}
            badge={
                <Badge variant="outline" className="gap-1 border-status-info/30 bg-status-info-bg text-status-info">
                    <ShieldCheck className="h-3 w-3" />
                    Zero-knowledge storage
                </Badge>
            }
        >
            <table className="w-full text-sm">
                <thead className="border-b bg-muted/50">
                    <tr>
                        <th className={TH}>Credential</th>
                        <th className={cn(TH, 'hidden md:table-cell')}>Site</th>
                        <th className={cn(TH, 'hidden sm:table-cell')}>Type</th>
                        <th className={cn(TH, 'hidden lg:table-cell')}>Health</th>
                        <th className={TH}>Status</th>
                        <th className={cn(TH, 'text-right')}>Actions</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.length === 0 ? (
                        <EmptyRow
                            icon={Lock}
                            title="No credentials found"
                            sub={hasFilters ? 'Try adjusting your filters' : 'Add a credential to get started'}
                            colSpan={6}
                        />
                    ) : (
                        rows.map((c) => {
                            const TypeIcon = credentialTypeIcon(c.credential_type);
                            return (
                                <tr
                                    key={c.id}
                                    className="group cursor-pointer transition-colors hover:bg-muted/40"
                                    onClick={() => onOpen(c)}
                                    onContextMenu={(e) => onContext(e, c)}
                                >
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-2">
                                            <TypeIcon className="h-4 w-4 text-muted-foreground" />
                                            <span className="font-medium group-hover:text-primary">{c.label}</span>
                                            {c.has_totp && (
                                                <Badge
                                                    variant="outline"
                                                    className="gap-1 border-status-success/30 bg-status-success-bg text-status-success"
                                                >
                                                    <KeyRound className="h-3 w-3" />
                                                    OTP
                                                </Badge>
                                            )}
                                        </div>
                                        {c.vendor_name && <div className="ml-6 text-xs text-muted-foreground">{c.vendor_name}</div>}
                                    </td>
                                    <td className="hidden px-4 py-3 md:table-cell">
                                        <span className="inline-flex items-center gap-1 text-sm">
                                            <MapPin className="h-3 w-3 text-muted-foreground" />
                                            {c.site_name ?? 'Unknown site'}
                                        </span>
                                        <div className="mt-1">
                                            <SiteTypeBadge type={c.site_type} />
                                        </div>
                                    </td>
                                    <td className="hidden px-4 py-3 sm:table-cell">
                                        <Badge variant="outline" className="border-border bg-muted text-muted-foreground">
                                            {credentialTypeLabel(c.credential_type)}
                                        </Badge>
                                    </td>
                                    <td className="hidden px-4 py-3 lg:table-cell">
                                        <RotationBadge lastRotatedAt={c.last_rotated_at} />
                                    </td>
                                    <td className="px-4 py-3">
                                        {c.requires_reauth ? (
                                            <Badge
                                                variant="outline"
                                                className="gap-1 border-status-warning/30 bg-status-warning-bg text-status-warning"
                                            >
                                                <ShieldCheck className="h-3 w-3" />
                                                Re-auth
                                            </Badge>
                                        ) : (
                                            <Badge variant="outline" className="gap-1 border-border bg-muted text-muted-foreground">
                                                <Lock className="h-3 w-3" />
                                                Stored
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right" onClick={(e) => e.stopPropagation()}>
                                        <Button variant="ghost" size="sm" onClick={() => onOpen(c)}>
                                            <Eye className="mr-1 h-3.5 w-3.5" />
                                            Reveal
                                        </Button>
                                    </td>
                                </tr>
                            );
                        })
                    )}
                </tbody>
            </table>
        </TableShell>
    );
}
