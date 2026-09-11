import {
    PageHeader,
    PageHeaderFilterCheck,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, router } from '@inertiajs/react';
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
    Settings,
    ShieldCheck,
    Star,
    Trash2,
    Truck,
    X,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { toast } from 'sonner';
import {
    type CredentialPickerOption,
    credentialTypeIcon,
    credentialTypeLabel,
    RotationBadge,
    rotationStatus,
    type SiteOption,
    SiteTypeBadge,
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
import {
    type ContextMenuItem,
    type ContextMenuState,
    RowContextMenu,
} from './_context-menu';
import { ManageCredentialTypesDialog } from './_manage-types-dialog';

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
    credentialTypeOptions: CredentialPickerOption[];
    filters: {
        site_id?: string | number;
        service_type?: string;
        vendor_status?: 'active' | 'inactive';
        preferred?: 'yes';
        credential_type?: string;
        requires_reauth?: 'yes' | 'no';
        tab?: 'vendors' | 'credentials';
    };
    can: {
        vendors: boolean;
        credentials: boolean;
        vendorsManage: boolean;
        credentialsManage: boolean;
        credentialsReveal: boolean;
        credentialsAudit: boolean;
        manageCredentialTypes: boolean;
    };
};

type VendorDialog = {
    mode: 'add' | 'edit' | 'show' | 'delete' | null;
    target: VendorRow | null;
};
type CredentialDialog = {
    mode: 'add' | 'edit' | 'show' | 'delete' | 'remove-totp' | null;
    target: CredentialRow | null;
};

function csvEscape(cell: unknown) {
    return `"${String(cell ?? '').replace(/"/g, '""')}"`;
}

function downloadCsv(
    filename: string,
    head: string[],
    rows: (string | number | null | undefined)[][],
) {
    const csv = [
        head.map(csvEscape).join(','),
        ...rows.map((r) => r.map(csvEscape).join(',')),
    ].join('\n');
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

export default function GlobalVendorsCredentials({
    vendors,
    credentials,
    sites,
    serviceTypes,
    credentialTypes,
    credentialTypeOptions,
    filters,
    can,
}: Props) {
    const [tab, setTab] = useState<'vendors' | 'credentials'>(() => {
        // Deep-links (e.g. the Site Calendar credential/vendor reminders) can
        // request a starting tab via ?tab=; honour it only when the viewer can
        // actually see that tab, otherwise fall back to the permission default.
        if (filters.tab === 'credentials' && can.credentials)
            return 'credentials';
        if (filters.tab === 'vendors' && can.vendors) return 'vendors';
        return can.vendors ? 'vendors' : 'credentials';
    });
    const [search, setSearch] = useState('');
    const [siteFilter, setSiteFilter] = useState<string>(
        filters.site_id ? String(filters.site_id) : 'all',
    );
    const [serviceTypeFilter, setServiceTypeFilter] = useState<string>(
        filters.service_type ?? 'all',
    );
    const [vendorStatusFilter, setVendorStatusFilter] = useState<string>(
        filters.vendor_status ?? 'all',
    );
    const [preferredFilter, setPreferredFilter] = useState<string>(
        filters.preferred ?? 'all',
    );
    const [credentialTypeFilter, setCredentialTypeFilter] = useState<string>(
        filters.credential_type ?? 'all',
    );
    const [reauthFilter, setReauthFilter] = useState<string>(
        filters.requires_reauth ?? 'all',
    );
    const [rotFilter, setRotFilter] = useState<string>('all');

    // Dialog + menu state
    const [vendorDialog, setVendorDialog] = useState<VendorDialog>({
        mode: null,
        target: null,
    });
    const [credentialDialog, setCredentialDialog] = useState<CredentialDialog>({
        mode: null,
        target: null,
    });
    const [auditOpen, setAuditOpen] = useState<{ focusLabel?: string } | null>(
        null,
    );
    const [typesOpen, setTypesOpen] = useState(false);
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
                if (siteFilter !== 'all' && String(v.site_id) !== siteFilter)
                    return false;
                if (
                    serviceTypeFilter !== 'all' &&
                    v.service_type !== serviceTypeFilter
                )
                    return false;
                if (vendorStatusFilter === 'active' && !v.is_active)
                    return false;
                if (vendorStatusFilter === 'inactive' && v.is_active)
                    return false;
                if (preferredFilter === 'yes' && !v.is_preferred) return false;
                return matchSearch([
                    v.company_name,
                    v.service_type,
                    v.site_name,
                    v.contact_name,
                ]);
            }),
        [
            vendors,
            siteFilter,
            serviceTypeFilter,
            vendorStatusFilter,
            preferredFilter,
            matchSearch,
        ],
    );

    const filteredCredentials = useMemo(
        () =>
            credentials.filter((c) => {
                if (siteFilter !== 'all' && String(c.site_id) !== siteFilter)
                    return false;
                if (
                    credentialTypeFilter !== 'all' &&
                    c.credential_type !== credentialTypeFilter
                )
                    return false;
                if (reauthFilter === 'yes' && !c.requires_reauth) return false;
                if (reauthFilter === 'no' && c.requires_reauth) return false;
                if (rotFilter !== 'all') {
                    const key = rotationStatus(c.last_rotated_at).key;
                    if (rotFilter === 'ok' && key !== 'ok') return false;
                    if (rotFilter === 'due' && key !== 'due') return false;
                    if (
                        rotFilter === 'overdue' &&
                        key !== 'overdue' &&
                        key !== 'unknown'
                    )
                        return false;
                }
                return matchSearch([
                    c.label,
                    c.credential_type,
                    c.site_name,
                    c.vendor_name,
                ]);
            }),
        [
            credentials,
            siteFilter,
            credentialTypeFilter,
            reauthFilter,
            rotFilter,
            matchSearch,
        ],
    );

    // Counts respect the site filter only (matching how the hero already scopes).
    const scopedVendors = useMemo(
        () =>
            vendors.filter(
                (v) => siteFilter === 'all' || String(v.site_id) === siteFilter,
            ),
        [vendors, siteFilter],
    );
    const scopedCredentials = useMemo(
        () =>
            credentials.filter(
                (c) => siteFilter === 'all' || String(c.site_id) === siteFilter,
            ),
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
            ['due', 'overdue', 'unknown'].includes(
                rotationStatus(c.last_rotated_at).key,
            ),
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

    const siteById = (id?: number | null) => sites.find((s) => s.id === id);
    const lockedSiteFor = (row: {
        site_id: number;
        site_name?: string | null;
        site_type?: string | null;
    }) => ({
        id: row.site_id,
        name: row.site_name ?? siteById(row.site_id)?.name ?? 'This site',
        type: row.site_type ?? siteById(row.site_id)?.type ?? '',
    });

    // ── quick actions (context menu) ───────────────────────────────────────
    const copyText = (text: string, label: string) => {
        try {
            void navigator.clipboard.writeText(text);
        } catch {
            // clipboard may be blocked
        }
        toast.success(`${label} copied`);
    };

    const toggleVendorFlag = (
        vendor: VendorRow,
        patch: { is_preferred?: boolean; is_active?: boolean },
    ) => {
        router.patch(
            `/sites/${vendor.site_id}/vendors/${vendor.id}/flags`,
            patch,
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
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

    const vendorMenuItems = (v: VendorRow): ContextMenuItem[] => [
        {
            icon: Eye,
            label: 'View details',
            onClick: () => setVendorDialog({ mode: 'show', target: v }),
        },
        ...(can.vendorsManage
            ? [
                  {
                      icon: Pencil,
                      label: 'Edit vendor',
                      onClick: () =>
                          setVendorDialog({ mode: 'edit', target: v }),
                  },
              ]
            : []),
        ...(v.phone
            ? [
                  {
                      icon: Phone,
                      label: 'Call main line',
                      onClick: () => (window.location.href = `tel:${v.phone}`),
                  },
              ]
            : []),
        ...(v.after_hours_phone
            ? [
                  {
                      icon: Clock,
                      label: 'Call after-hours',
                      onClick: () =>
                          (window.location.href = `tel:${v.after_hours_phone}`),
                  },
              ]
            : []),
        ...(v.email
            ? [
                  {
                      icon: Mail,
                      label: 'Email vendor',
                      onClick: () =>
                          (window.location.href = `mailto:${v.email}`),
                  },
              ]
            : []),
        ...(v.phone
            ? [
                  {
                      icon: FileText,
                      label: 'Copy phone number',
                      onClick: () => copyText(v.phone!, 'Phone'),
                  },
              ]
            : []),
        { sep: true } as ContextMenuItem,
        ...(can.vendorsManage
            ? [
                  {
                      icon: Star,
                      label: v.is_preferred
                          ? 'Remove preferred'
                          : 'Mark as preferred',
                      onClick: () =>
                          toggleVendorFlag(v, {
                              is_preferred: !v.is_preferred,
                          }),
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
                      label: v.is_active
                          ? 'Deactivate vendor'
                          : 'Activate vendor',
                      onClick: () =>
                          toggleVendorFlag(v, { is_active: !v.is_active }),
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
                      onClick: () =>
                          setVendorDialog({ mode: 'delete', target: v }),
                  },
              ]
            : []),
    ];

    const openVendorMenu = (e: React.MouseEvent, v: VendorRow) => {
        e.preventDefault();
        const items = vendorMenuItems(v);
        setCtxMenu({
            x: e.clientX,
            y: e.clientY,
            header: { icon: Truck, title: v.company_name, sub: v.service_type },
            items,
        });
    };

    const credentialMenuItems = (c: CredentialRow): ContextMenuItem[] => {
        const word = c.credential_type === 'pin' ? 'code' : 'password';
        return [
            ...(can.credentialsReveal
                ? [
                      {
                          icon: Eye,
                          label: c.requires_reauth
                              ? 'Re-authenticate & reveal'
                              : `Reveal ${word}`,
                          onClick: () =>
                              setCredentialDialog({ mode: 'show', target: c }),
                      },
                  ]
                : [
                      {
                          icon: Eye,
                          label: 'View details',
                          onClick: () =>
                              setCredentialDialog({ mode: 'show', target: c }),
                      },
                  ]),
            ...(c.username
                ? [
                      {
                          icon: FileText,
                          label: 'Copy username',
                          onClick: () => copyText(c.username!, 'Username'),
                      },
                  ]
                : []),
            ...(c.url
                ? [
                      {
                          icon: Globe,
                          label: 'Open URL',
                          onClick: () =>
                              window.open(c.url!, '_blank', 'noopener'),
                      },
                  ]
                : []),
            { sep: true } as ContextMenuItem,
            ...(can.credentialsManage
                ? [
                      {
                          icon: Pencil,
                          label: 'Edit credential',
                          onClick: () =>
                              setCredentialDialog({ mode: 'edit', target: c }),
                      },
                      {
                          icon: RefreshCcw,
                          label: 'Mark rotated now',
                          onClick: () => markRotated(c),
                      },
                      {
                          icon: ShieldCheck,
                          label: c.requires_reauth
                              ? 'Drop re-auth requirement'
                              : 'Require re-auth to reveal',
                          onClick: () => toggleReauth(c),
                      },
                  ]
                : []),
            ...(can.credentialsAudit
                ? [
                      {
                          icon: History,
                          label: 'Reveal history',
                          onClick: () => setAuditOpen({ focusLabel: c.label }),
                      },
                  ]
                : []),
            ...(can.credentialsManage
                ? [
                      { sep: true } as ContextMenuItem,
                      {
                          icon: Trash2,
                          label: 'Delete credential',
                          danger: true,
                          onClick: () =>
                              setCredentialDialog({
                                  mode: 'delete',
                                  target: c,
                              }),
                      },
                  ]
                : []),
        ];
    };

    const openCredentialMenu = (e: React.MouseEvent, c: CredentialRow) => {
        e.preventDefault();
        const items = credentialMenuItems(c);
        setCtxMenu({
            x: e.clientX,
            y: e.clientY,
            header: {
                icon: credentialTypeIcon(c.credential_type),
                title: c.label,
                sub: credentialTypeLabel(c.credential_type),
            },
            items,
        });
    };

    // ── CSV exports ────────────────────────────────────────────────────────
    const exportVendors = () => {
        downloadCsv(
            `vendors-${new Date().toISOString().slice(0, 10)}.csv`,
            [
                'Company',
                'Service',
                'Site',
                'Contact',
                'Phone',
                'After-hours',
                'Email',
                'Preferred',
                'Active',
                'H&S induction',
                'Induction date',
                'Qualifications verified',
                'Insurance verified',
                'Insurance expiry',
                'Insurance provider',
                'H&S rating',
                'Last H&S review',
            ],
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
                v.hs_induction_completed ? 'Yes' : 'No',
                v.hs_induction_date,
                v.qualifications_verified ? 'Yes' : 'No',
                v.insurance_verified ? 'Yes' : 'No',
                v.insurance_expiry,
                v.insurance_provider,
                v.hs_performance_rating,
                v.hs_last_reviewed_at,
            ]),
        );
        toast.success('Vendors exported to CSV');
    };
    const exportCredentials = () => {
        downloadCsv(
            `credentials-${new Date().toISOString().slice(0, 10)}.csv`,
            [
                'Label',
                'Type',
                'Site',
                'Vendor',
                'Username',
                'URL',
                'Re-auth',
                'Authenticator',
                'Last rotated',
                'Health',
            ],
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

    // ── header derivations ────────────────────────────────────────────────
    const hasMoreActions =
        can.vendorsManage ||
        can.credentialsManage ||
        can.credentialsAudit ||
        can.manageCredentialTypes;

    const healthTotal = credHealth.ok + credHealth.due + credHealth.overdue;
    const healthPercent =
        healthTotal > 0 ? Math.round((credHealth.ok / healthTotal) * 100) : 100;

    const sublineParts = [
        'Vendor directory & access vault',
        `${sites.length} ${sites.length === 1 ? 'site' : 'sites'}`,
        can.vendors
            ? `${counts.vendors} ${counts.vendors === 1 ? 'provider' : 'providers'}`
            : null,
        can.credentials
            ? `${counts.credentials} ${counts.credentials === 1 ? 'credential' : 'credentials'}`
            : null,
        'encrypted at rest · every reveal audited',
    ].filter((part): part is string => part !== null);

    const railItems: PageHeaderRailItem<'vendors' | 'credentials'>[] = [
        ...(can.vendors
            ? [
                  {
                      key: 'vendors' as const,
                      label: 'Vendors',
                      icon: Truck,
                      count: filteredVendors.length,
                  },
              ]
            : []),
        ...(can.credentials
            ? [
                  {
                      key: 'credentials' as const,
                      label: 'Credentials',
                      icon: Lock,
                      count: filteredCredentials.length,
                  },
              ]
            : []),
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Sites', href: '/sites' },
                { title: 'Vendors & Credentials', href: '/vendors' },
            ]}
        >
            <Head title="Vendors & Credentials" />

            <PageLayout
                hero={
                    <PageHeader
                        icon={Package}
                        title="Vendors & Credentials"
                        titleChip={
                            can.credentials ? (
                                credHealth.overdue > 0 ? (
                                    <PageHeaderStatusChip variant="critical">
                                        {credHealth.overdue} overdue
                                    </PageHeaderStatusChip>
                                ) : credHealth.due > 0 ? (
                                    <PageHeaderStatusChip variant="warning">
                                        {credHealth.due} rotation due
                                    </PageHeaderStatusChip>
                                ) : (
                                    <PageHeaderStatusChip variant="success">
                                        Vault healthy
                                    </PageHeaderStatusChip>
                                )
                            ) : (
                                <PageHeaderStatusChip variant="success">
                                    {counts.activeVendors} active
                                </PageHeaderStatusChip>
                            )
                        }
                        subline={sublineParts.join(' · ')}
                        actions={
                            <>
                                <PageHeaderSearch
                                    value={search}
                                    onChange={setSearch}
                                    placeholder="Search company, credential, type, or site…"
                                />
                                {can.vendorsManage ? (
                                    can.credentialsManage ? (
                                        <PageHeaderGlassButton
                                            icon={Truck}
                                            onClick={() =>
                                                setVendorDialog({
                                                    mode: 'add',
                                                    target: null,
                                                })
                                            }
                                        >
                                            Add vendor
                                        </PageHeaderGlassButton>
                                    ) : (
                                        <PageHeaderPrimaryButton
                                            icon={Truck}
                                            onClick={() =>
                                                setVendorDialog({
                                                    mode: 'add',
                                                    target: null,
                                                })
                                            }
                                        >
                                            Add vendor
                                        </PageHeaderPrimaryButton>
                                    )
                                ) : null}
                                {hasMoreActions && (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <PageHeaderGlassButton
                                                icon={MoreHorizontal}
                                                aria-label="More actions"
                                            />
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            className="w-56"
                                        >
                                            {can.vendorsManage && (
                                                <DropdownMenuItem
                                                    onClick={exportVendors}
                                                >
                                                    <Truck className="mr-2 h-4 w-4" />
                                                    Export vendors (CSV)
                                                </DropdownMenuItem>
                                            )}
                                            {can.credentialsManage && (
                                                <DropdownMenuItem
                                                    onClick={exportCredentials}
                                                >
                                                    <Lock className="mr-2 h-4 w-4" />
                                                    Export credentials (CSV)
                                                </DropdownMenuItem>
                                            )}
                                            {can.credentialsAudit && (
                                                <>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            setAuditOpen({
                                                                focusLabel: '',
                                                            })
                                                        }
                                                    >
                                                        <History className="mr-2 h-4 w-4" />
                                                        View reveal &amp; audit
                                                        log
                                                    </DropdownMenuItem>
                                                </>
                                            )}
                                            {can.manageCredentialTypes && (
                                                <>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            setTypesOpen(true)
                                                        }
                                                    >
                                                        <Settings className="mr-2 h-4 w-4" />
                                                        Manage credential types
                                                    </DropdownMenuItem>
                                                </>
                                            )}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                )}
                                {can.credentialsManage && (
                                    <PageHeaderPrimaryButton
                                        icon={Plus}
                                        onClick={() =>
                                            setCredentialDialog({
                                                mode: 'add',
                                                target: null,
                                            })
                                        }
                                    >
                                        Add credential
                                    </PageHeaderPrimaryButton>
                                )}
                            </>
                        }
                        meters={
                            <>
                                {can.vendors ? (
                                    <PageHeaderMeterBlock
                                        label="Vendors"
                                        ariaLabel="View all vendors"
                                        onClick={() => {
                                            setTab('vendors');
                                            setVendorStatusFilter('all');
                                            setPreferredFilter('all');
                                        }}
                                    >
                                        <PageHeaderMeterBig>
                                            {counts.vendors}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {counts.activeVendors} active
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ) : null}
                                {can.vendors ? (
                                    <PageHeaderMeterBlock
                                        label="Preferred"
                                        ariaLabel="View preferred vendors"
                                        onClick={() => {
                                            setTab('vendors');
                                            setVendorStatusFilter('all');
                                            setPreferredFilter('yes');
                                        }}
                                    >
                                        <PageHeaderMeterBig>
                                            {counts.preferredVendors}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            vendors on call
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ) : null}
                                {can.credentials ? (
                                    <PageHeaderMeterBlock
                                        label="Vault health"
                                        value={counts.credentials}
                                        ariaLabel="View the access vault"
                                        onClick={() => {
                                            setTab('credentials');
                                            setCredentialTypeFilter('all');
                                            setReauthFilter('all');
                                            setRotFilter('all');
                                        }}
                                    >
                                        <PageHeaderMeterDonut
                                            percent={healthPercent}
                                            caption={
                                                <>
                                                    {credHealth.ok} of{' '}
                                                    {healthTotal}
                                                    <br />
                                                    rotated on time
                                                </>
                                            }
                                        />
                                    </PageHeaderMeterBlock>
                                ) : null}
                                {can.credentials ? (
                                    <PageHeaderMeterBlock
                                        label="Rotation due"
                                        tone={
                                            credHealth.due > 0
                                                ? 'warning'
                                                : 'brand'
                                        }
                                        ariaLabel="View credentials due for rotation"
                                        onClick={() => {
                                            setTab('credentials');
                                            setCredentialTypeFilter('all');
                                            setReauthFilter('all');
                                            setRotFilter('due');
                                        }}
                                    >
                                        <PageHeaderMeterBig>
                                            {credHealth.due}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            need rotating soon
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ) : null}
                                {can.credentials ? (
                                    <PageHeaderMeterBlock
                                        label="Overdue"
                                        tone={
                                            credHealth.overdue > 0
                                                ? 'critical'
                                                : 'success'
                                        }
                                        ariaLabel="View overdue credentials"
                                        onClick={() => {
                                            setTab('credentials');
                                            setCredentialTypeFilter('all');
                                            setReauthFilter('all');
                                            setRotFilter('overdue');
                                        }}
                                    >
                                        <PageHeaderMeterBig>
                                            {credHealth.overdue}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            incl. never rotated
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ) : null}
                                {can.credentials ? (
                                    <PageHeaderMeterBlock
                                        label="Re-auth"
                                        tone={
                                            counts.reauth > 0
                                                ? 'warning'
                                                : 'brand'
                                        }
                                        ariaLabel="View credentials requiring re-authentication"
                                        onClick={() => {
                                            setTab('credentials');
                                            setCredentialTypeFilter('all');
                                            setRotFilter('all');
                                            setReauthFilter('yes');
                                        }}
                                    >
                                        <PageHeaderMeterBig>
                                            {counts.reauth}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            required to reveal
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ) : null}
                            </>
                        }
                        filters={
                            <>
                                <PageHeaderFilterSelect
                                    icon={Building2}
                                    label="All sites"
                                    value={siteFilter}
                                    options={[
                                        { value: 'all', label: 'All sites' },
                                        ...sites.map((s) => ({
                                            value: String(s.id),
                                            label: s.name,
                                        })),
                                    ]}
                                    onChange={setSiteFilter}
                                />
                                {tab === 'vendors' ? (
                                    <>
                                        <PageHeaderFilterSelect
                                            label="All services"
                                            value={serviceTypeFilter}
                                            options={[
                                                {
                                                    value: 'all',
                                                    label: 'All services',
                                                },
                                                ...serviceTypes.map((s) => ({
                                                    value: s,
                                                    label: s,
                                                })),
                                            ]}
                                            onChange={setServiceTypeFilter}
                                        />
                                        <PageHeaderFilterSelect
                                            label="Any status"
                                            value={vendorStatusFilter}
                                            options={[
                                                {
                                                    value: 'all',
                                                    label: 'Any status',
                                                },
                                                {
                                                    value: 'active',
                                                    label: 'Active',
                                                },
                                                {
                                                    value: 'inactive',
                                                    label: 'Inactive',
                                                },
                                            ]}
                                            onChange={setVendorStatusFilter}
                                        />
                                        <PageHeaderFilterCheck
                                            label="Preferred"
                                            checked={preferredFilter === 'yes'}
                                            onChange={(checked) =>
                                                setPreferredFilter(
                                                    checked ? 'yes' : 'all',
                                                )
                                            }
                                        />
                                    </>
                                ) : (
                                    <>
                                        <PageHeaderFilterSelect
                                            icon={KeyRound}
                                            label="Any type"
                                            value={credentialTypeFilter}
                                            options={[
                                                {
                                                    value: 'all',
                                                    label: 'Any type',
                                                },
                                                ...credentialTypes.map((t) => ({
                                                    value: t,
                                                    label: credentialTypeLabel(
                                                        t,
                                                    ),
                                                })),
                                            ]}
                                            onChange={setCredentialTypeFilter}
                                        />
                                        <PageHeaderFilterSelect
                                            icon={ShieldCheck}
                                            label="Any reveal rule"
                                            value={reauthFilter}
                                            options={[
                                                {
                                                    value: 'all',
                                                    label: 'Any reveal rule',
                                                },
                                                {
                                                    value: 'yes',
                                                    label: 'Re-auth required',
                                                },
                                                {
                                                    value: 'no',
                                                    label: 'No re-auth',
                                                },
                                            ]}
                                            onChange={setReauthFilter}
                                        />
                                        <PageHeaderFilterSelect
                                            icon={RefreshCcw}
                                            label="Any health"
                                            value={rotFilter}
                                            options={[
                                                {
                                                    value: 'all',
                                                    label: 'Any health',
                                                },
                                                {
                                                    value: 'ok',
                                                    label: 'Healthy',
                                                },
                                                {
                                                    value: 'due',
                                                    label: 'Rotation due',
                                                },
                                                {
                                                    value: 'overdue',
                                                    label: 'Overdue',
                                                },
                                            ]}
                                            onChange={setRotFilter}
                                        />
                                    </>
                                )}
                            </>
                        }
                        rail={
                            <PageHeaderRail
                                items={railItems}
                                value={tab}
                                onSelect={setTab}
                                ariaLabel="Vendor and credential views"
                            />
                        }
                    />
                }
            >
                {tab === 'vendors' && can.vendors ? (
                    <VendorTable
                        rows={filteredVendors}
                        hasFilters={hasFilters}
                        onOpen={(v) =>
                            setVendorDialog({ mode: 'show', target: v })
                        }
                        onContext={openVendorMenu}
                        menuItems={vendorMenuItems}
                    />
                ) : null}
                {tab === 'credentials' && can.credentials ? (
                    <CredentialTable
                        rows={filteredCredentials}
                        hasFilters={hasFilters}
                        onOpen={(c) =>
                            setCredentialDialog({ mode: 'show', target: c })
                        }
                        onContext={openCredentialMenu}
                        menuItems={credentialMenuItems}
                        canReveal={can.credentialsReveal}
                    />
                ) : null}
            </PageLayout>

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
                        onClose={() =>
                            setVendorDialog({ mode: null, target: null })
                        }
                    />
                    <ShowVendorDialog
                        isOpen={vendorDialog.mode === 'show'}
                        vendor={vendorDialog.target}
                        canManage={can.vendorsManage}
                        onClose={() =>
                            setVendorDialog({ mode: null, target: null })
                        }
                        onEdit={() =>
                            setVendorDialog((p) => ({ ...p, mode: 'edit' }))
                        }
                        onDelete={() =>
                            setVendorDialog((p) => ({ ...p, mode: 'delete' }))
                        }
                    />
                    <DeleteVendorDialog
                        isOpen={vendorDialog.mode === 'delete'}
                        siteId={vendorDialog.target.site_id}
                        vendor={vendorDialog.target}
                        onClose={() =>
                            setVendorDialog({ mode: null, target: null })
                        }
                    />
                </>
            )}

            <AddCredentialDialog
                isOpen={credentialDialog.mode === 'add'}
                sites={sites}
                vendors={vendors}
                typeOptions={credentialTypeOptions}
                onClose={() =>
                    setCredentialDialog({ mode: null, target: null })
                }
            />
            {credentialDialog.target && (
                <>
                    <EditCredentialDialog
                        isOpen={credentialDialog.mode === 'edit'}
                        siteId={credentialDialog.target.site_id}
                        credential={credentialDialog.target}
                        lockedSite={lockedSiteFor(credentialDialog.target)}
                        vendors={vendors}
                        typeOptions={credentialTypeOptions}
                        onClose={() =>
                            setCredentialDialog({ mode: null, target: null })
                        }
                    />
                    <ShowCredentialDialog
                        isOpen={credentialDialog.mode === 'show'}
                        siteId={credentialDialog.target.site_id}
                        credential={credentialDialog.target}
                        canManage={can.credentialsManage}
                        canReveal={can.credentialsReveal}
                        onClose={() =>
                            setCredentialDialog({ mode: null, target: null })
                        }
                        onEdit={() =>
                            setCredentialDialog((p) => ({ ...p, mode: 'edit' }))
                        }
                        onDelete={() =>
                            setCredentialDialog((p) => ({
                                ...p,
                                mode: 'delete',
                            }))
                        }
                        onRemoveTotp={() =>
                            setCredentialDialog((p) => ({
                                ...p,
                                mode: 'remove-totp',
                            }))
                        }
                        onHistory={
                            can.credentialsAudit
                                ? () => {
                                      const label =
                                          credentialDialog.target?.label;
                                      setCredentialDialog({
                                          mode: null,
                                          target: null,
                                      });
                                      setAuditOpen({ focusLabel: label });
                                  }
                                : undefined
                        }
                    />
                    <DeleteCredentialDialog
                        isOpen={credentialDialog.mode === 'delete'}
                        siteId={credentialDialog.target.site_id}
                        credential={credentialDialog.target}
                        onClose={() =>
                            setCredentialDialog({ mode: null, target: null })
                        }
                    />
                    <RemoveTotpDialog
                        isOpen={credentialDialog.mode === 'remove-totp'}
                        siteId={credentialDialog.target.site_id}
                        credential={credentialDialog.target}
                        onClose={() =>
                            setCredentialDialog({ mode: null, target: null })
                        }
                    />
                </>
            )}

            {can.credentialsAudit && (
                <AuditLogDialog
                    isOpen={!!auditOpen}
                    focusLabel={auditOpen?.focusLabel}
                    siteId={siteFilter !== 'all' ? Number(siteFilter) : null}
                    onClose={() => setAuditOpen(null)}
                />
            )}

            <ManageCredentialTypesDialog
                isOpen={typesOpen}
                onClose={() => setTypesOpen(false)}
            />

            <RowContextMenu menu={ctxMenu} onClose={() => setCtxMenu(null)} />
        </AppLayout>
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
                        <Icon className="h-4 w-4 text-primary" />
                        {title}
                    </h3>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {subtitle}
                    </p>
                </div>
                {badge}
            </div>
            <div className="overflow-x-auto">{children}</div>
        </div>
    );
}

function EmptyRow({
    icon: Icon,
    title,
    sub,
    colSpan,
}: {
    icon: typeof Truck;
    title: string;
    sub: string;
    colSpan: number;
}) {
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

function RowActionDropdown({
    items,
    label,
}: {
    items: ContextMenuItem[];
    label: string;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="sm" aria-label={label}>
                    <MoreHorizontal className="h-4 w-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
                {items.map((item, index) => {
                    if (item.sep) {
                        return <DropdownMenuSeparator key={`sep-${index}`} />;
                    }

                    const Icon = item.icon;
                    return (
                        <DropdownMenuItem
                            key={`${item.label}-${index}`}
                            disabled={item.disabled}
                            className={
                                item.danger
                                    ? 'text-status-critical focus:text-status-critical'
                                    : undefined
                            }
                            onClick={item.onClick}
                        >
                            <Icon className="mr-2 h-4 w-4" />
                            {item.label}
                        </DropdownMenuItem>
                    );
                })}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

const TH =
    'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground';

function VendorTable({
    rows,
    hasFilters,
    onOpen,
    onContext,
    menuItems,
}: {
    rows: VendorRow[];
    hasFilters: boolean;
    onOpen: (v: VendorRow) => void;
    onContext: (e: React.MouseEvent, v: VendorRow) => void;
    menuItems: (v: VendorRow) => ContextMenuItem[];
}) {
    return (
        <TableShell
            title="Service providers"
            icon={Truck}
            subtitle={`Click a vendor to view · use row actions for quick changes · ${rows.length} shown`}
        >
            <table className="w-full text-sm">
                <thead className="border-b bg-muted/50">
                    <tr>
                        <th className={TH}>Company</th>
                        <th className={cn(TH, 'hidden md:table-cell')}>Site</th>
                        <th className={cn(TH, 'hidden sm:table-cell')}>
                            Service
                        </th>
                        <th className={cn(TH, 'hidden lg:table-cell')}>
                            Contact
                        </th>
                        <th className={TH}>Status</th>
                        <th className={cn(TH, 'text-right')}>Actions</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.length === 0 ? (
                        <EmptyRow
                            icon={Truck}
                            title="No vendors found"
                            sub={
                                hasFilters
                                    ? 'Try adjusting your filters'
                                    : 'Add a vendor to get started'
                            }
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
                                        <span className="font-medium group-hover:text-primary">
                                            {v.company_name}
                                        </span>
                                        {v.is_preferred && (
                                            <Star className="h-3.5 w-3.5 fill-status-warning text-status-warning" />
                                        )}
                                    </div>
                                    {v.contact_name && (
                                        <div className="text-xs text-muted-foreground">
                                            {v.contact_name}
                                        </div>
                                    )}
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
                                    <Badge
                                        variant="outline"
                                        className="border-border bg-muted text-muted-foreground"
                                    >
                                        {v.service_type}
                                    </Badge>
                                </td>
                                <td
                                    className="hidden px-4 py-3 lg:table-cell"
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    <div className="flex flex-col gap-0.5 text-xs">
                                        {v.phone && (
                                            <a
                                                href={`tel:${v.phone}`}
                                                className="inline-flex items-center gap-1 text-primary hover:underline"
                                            >
                                                <Phone className="h-3 w-3" />
                                                {v.phone}
                                            </a>
                                        )}
                                        {v.email && (
                                            <a
                                                href={`mailto:${v.email}`}
                                                className="inline-flex items-center gap-1 text-primary hover:underline"
                                            >
                                                <Mail className="h-3 w-3" />
                                                <span className="max-w-[160px] truncate">
                                                    {v.email}
                                                </span>
                                            </a>
                                        )}
                                        {!v.phone && !v.email && (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </div>
                                </td>
                                <td className="px-4 py-3">
                                    <div className="flex flex-wrap gap-1.5">
                                        <Badge
                                            variant="outline"
                                            className={
                                                v.is_active
                                                    ? 'border-status-success/30 bg-status-success-bg text-status-success'
                                                    : 'border-border bg-muted text-muted-foreground'
                                            }
                                        >
                                            {v.is_active
                                                ? 'Active'
                                                : 'Inactive'}
                                        </Badge>
                                        {(v.hs_induction_completed ||
                                            v.insurance_verified ||
                                            v.qualifications_verified) && (
                                            <Badge
                                                variant="outline"
                                                className="border-status-info/30 bg-status-info-bg text-status-info"
                                            >
                                                H&S
                                            </Badge>
                                        )}
                                    </div>
                                </td>
                                <td
                                    className="px-4 py-3 text-right"
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    <div className="inline-flex items-center justify-end gap-1">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => onOpen(v)}
                                        >
                                            <Eye className="mr-1 h-3.5 w-3.5" />
                                            View
                                        </Button>
                                        <RowActionDropdown
                                            items={menuItems(v)}
                                            label={`Actions for ${v.company_name}`}
                                        />
                                    </div>
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
    menuItems,
    canReveal,
}: {
    rows: CredentialRow[];
    hasFilters: boolean;
    onOpen: (c: CredentialRow) => void;
    onContext: (e: React.MouseEvent, c: CredentialRow) => void;
    menuItems: (c: CredentialRow) => ContextMenuItem[];
    canReveal: boolean;
}) {
    return (
        <TableShell
            title="Access vault"
            icon={Lock}
            subtitle={
                canReveal
                    ? `Encrypted at rest · reveal is audited · use row actions for quick changes · ${rows.length} shown`
                    : `Encrypted at rest · open to view metadata · reveal permission required for secrets · ${rows.length} shown`
            }
            badge={
                <Badge
                    variant="outline"
                    className="gap-1 border-status-info/30 bg-status-info-bg text-status-info"
                >
                    <ShieldCheck className="h-3 w-3" />
                    Encrypted vault
                </Badge>
            }
        >
            <table className="w-full text-sm">
                <thead className="border-b bg-muted/50">
                    <tr>
                        <th className={TH}>Credential</th>
                        <th className={cn(TH, 'hidden md:table-cell')}>Site</th>
                        <th className={cn(TH, 'hidden sm:table-cell')}>Type</th>
                        <th className={cn(TH, 'hidden lg:table-cell')}>
                            Health
                        </th>
                        <th className={TH}>Status</th>
                        <th className={cn(TH, 'text-right')}>Actions</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.length === 0 ? (
                        <EmptyRow
                            icon={Lock}
                            title="No credentials found"
                            sub={
                                hasFilters
                                    ? 'Try adjusting your filters'
                                    : 'Add a credential to get started'
                            }
                            colSpan={6}
                        />
                    ) : (
                        rows.map((c) => {
                            const TypeIcon = credentialTypeIcon(
                                c.credential_type,
                            );
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
                                            <span className="font-medium group-hover:text-primary">
                                                {c.label}
                                            </span>
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
                                        {c.vendor_name && (
                                            <div className="ml-6 text-xs text-muted-foreground">
                                                {c.vendor_name}
                                            </div>
                                        )}
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
                                        <Badge
                                            variant="outline"
                                            className="border-border bg-muted text-muted-foreground"
                                        >
                                            {credentialTypeLabel(
                                                c.credential_type,
                                            )}
                                        </Badge>
                                    </td>
                                    <td className="hidden px-4 py-3 lg:table-cell">
                                        <RotationBadge
                                            lastRotatedAt={c.last_rotated_at}
                                        />
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
                                            <Badge
                                                variant="outline"
                                                className="gap-1 border-border bg-muted text-muted-foreground"
                                            >
                                                <Lock className="h-3 w-3" />
                                                Stored
                                            </Badge>
                                        )}
                                    </td>
                                    <td
                                        className="px-4 py-3 text-right"
                                        onClick={(e) => e.stopPropagation()}
                                    >
                                        <div className="inline-flex items-center justify-end gap-1">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => onOpen(c)}
                                            >
                                                <Eye className="mr-1 h-3.5 w-3.5" />
                                                {canReveal ? 'Reveal' : 'View'}
                                            </Button>
                                            <RowActionDropdown
                                                items={menuItems(c)}
                                                label={`Actions for ${c.label}`}
                                            />
                                        </div>
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
