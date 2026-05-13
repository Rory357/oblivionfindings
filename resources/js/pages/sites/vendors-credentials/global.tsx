import AppLayout from '@/layouts/app-layout';
import FleetHero from '@/components/fleet-hero';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Building2,
    Eye,
    Home,
    Lock,
    Mail,
    MapPin,
    Phone,
    Search,
    ShieldCheck,
    Star,
    Truck,
    Warehouse,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type SiteOption = {
    id: number;
    name: string;
    type: 'head_office' | 'house' | 'facility' | 'residential';
};

type VendorRow = {
    id: number;
    site_id: number;
    site_name?: string | null;
    site_type?: string | null;
    service_type: string;
    company_name: string;
    contact_name?: string | null;
    phone?: string | null;
    after_hours_phone?: string | null;
    email?: string | null;
    preferred_contact_method?: string | null;
    is_preferred: boolean;
    is_active: boolean;
};

type CredentialRow = {
    id: number;
    site_id: number;
    site_name?: string | null;
    site_type?: string | null;
    label: string;
    credential_type: string;
    vendor_name?: string | null;
    vendor_service_type?: string | null;
    requires_reauth: boolean;
    last_rotated_at?: string | null;
    value_preview: string;
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
    };
};

const typeIcons: Record<string, typeof Building2> = {
    head_office: Building2,
    house: Home,
    facility: Warehouse,
    residential: Home,
};

const typeLabels: Record<string, string> = {
    head_office: 'Head Office',
    house: 'House',
    facility: 'Facility',
    residential: 'Residential',
};

const typeColors: Record<string, string> = {
    head_office:
        'border-status-info/30 bg-status-info-bg text-status-info dark:border-status-info/30 dark:bg-status-info-bg dark:text-status-info',
    house:
        'border-status-success/30 bg-status-success-bg text-status-success dark:border-status-success/30 dark:bg-status-success-bg dark:text-status-success',
    facility:
        'border-status-warning/30 bg-status-warning-bg text-status-warning dark:border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning',
    residential:
        'border-primary bg-primary/10 text-primary dark:border-primary/30 dark:bg-primary/10 dark:text-primary/70',
};

function SiteTypeBadge({ type }: { type?: string | null }) {
    if (!type) return <span className="text-muted-foreground">—</span>;
    const Icon = typeIcons[type] ?? Building2;
    return (
        <Badge variant="outline" className={typeColors[type] || ''}>
            <Icon className="mr-1 h-3 w-3" />
            {typeLabels[type] || type}
        </Badge>
    );
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
    const [search, setSearch] = useState('');
    const [siteFilter, setSiteFilter] = useState<string>(filters.site_id ? String(filters.site_id) : 'all');
    const [serviceTypeFilter, setServiceTypeFilter] = useState<string>(filters.service_type ?? 'all');
    const [vendorStatusFilter, setVendorStatusFilter] = useState<string>(filters.vendor_status ?? 'all');
    const [preferredFilter, setPreferredFilter] = useState<string>(filters.preferred ?? 'all');
    const [credentialTypeFilter, setCredentialTypeFilter] = useState<string>(filters.credential_type ?? 'all');
    const [reauthFilter, setReauthFilter] = useState<string>(filters.requires_reauth ?? 'all');

    const filteredVendors = useMemo(() => {
        return vendors.filter((vendor) => {
            if (siteFilter !== 'all' && String(vendor.site_id) !== siteFilter) return false;
            if (serviceTypeFilter !== 'all' && vendor.service_type !== serviceTypeFilter) return false;
            if (vendorStatusFilter === 'active' && !vendor.is_active) return false;
            if (vendorStatusFilter === 'inactive' && vendor.is_active) return false;
            if (preferredFilter === 'yes' && !vendor.is_preferred) return false;
            if (search.trim() !== '') {
                const s = search.toLowerCase();
                if (
                    !vendor.company_name.toLowerCase().includes(s) &&
                    !vendor.service_type.toLowerCase().includes(s) &&
                    !(vendor.site_name ?? '').toLowerCase().includes(s) &&
                    !(vendor.contact_name ?? '').toLowerCase().includes(s)
                ) {
                    return false;
                }
            }
            return true;
        });
    }, [vendors, siteFilter, serviceTypeFilter, vendorStatusFilter, preferredFilter, search]);

    const filteredCredentials = useMemo(() => {
        return credentials.filter((credential) => {
            if (siteFilter !== 'all' && String(credential.site_id) !== siteFilter) return false;
            if (credentialTypeFilter !== 'all' && credential.credential_type !== credentialTypeFilter) return false;
            if (reauthFilter === 'yes' && !credential.requires_reauth) return false;
            if (reauthFilter === 'no' && credential.requires_reauth) return false;
            if (search.trim() !== '') {
                const s = search.toLowerCase();
                if (
                    !credential.label.toLowerCase().includes(s) &&
                    !credential.credential_type.toLowerCase().includes(s) &&
                    !(credential.site_name ?? '').toLowerCase().includes(s) &&
                    !(credential.vendor_name ?? '').toLowerCase().includes(s)
                ) {
                    return false;
                }
            }
            return true;
        });
    }, [credentials, siteFilter, credentialTypeFilter, reauthFilter, search]);

    const activeVendors = filteredVendors.filter((v) => v.is_active).length;
    const preferredVendors = filteredVendors.filter((v) => v.is_preferred).length;
    const reauthCount = filteredCredentials.filter((c) => c.requires_reauth).length;

    const hasFilters =
        siteFilter !== 'all' ||
        serviceTypeFilter !== 'all' ||
        vendorStatusFilter !== 'all' ||
        preferredFilter !== 'all' ||
        credentialTypeFilter !== 'all' ||
        reauthFilter !== 'all' ||
        search.trim() !== '';

    const clearFilters = () => {
        setSearch('');
        setSiteFilter('all');
        setServiceTypeFilter('all');
        setVendorStatusFilter('all');
        setPreferredFilter('all');
        setCredentialTypeFilter('all');
        setReauthFilter('all');
    };

    const heroStats = can.vendors && can.credentials
        ? [
              { label: 'Vendors', value: filteredVendors.length },
              { label: 'Active', value: activeVendors },
              { label: 'Credentials', value: filteredCredentials.length },
              { label: 'Re-auth', value: reauthCount },
          ]
        : can.vendors
        ? [
              { label: 'Total', value: filteredVendors.length },
              { label: 'Active', value: activeVendors },
              { label: 'Preferred', value: preferredVendors },
              { label: 'Inactive', value: filteredVendors.length - activeVendors },
          ]
        : [
              { label: 'Total', value: filteredCredentials.length },
              { label: 'Re-auth', value: reauthCount },
              { label: 'Sites', value: sites.length },
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
                <FleetHero
                    title="Vendors & Credentials"
                    description={`Service providers and access codes across every site — ${sites.length} ${sites.length === 1 ? 'site' : 'sites'} in view`}
                    icon={<Truck className="h-7 w-7 text-white" />}
                    stats={heroStats}
                />

                {/* Filters — flex-wrap row matching sites/index.tsx */}
                <div className="flex flex-wrap items-center gap-3">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search company, credential, type, or site..."
                            className="w-72 pl-9"
                        />
                    </div>

                    <Select value={siteFilter} onValueChange={setSiteFilter}>
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="All Sites" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Sites</SelectItem>
                            {sites.map((site) => (
                                <SelectItem key={site.id} value={String(site.id)}>
                                    {site.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    {can.vendors && (
                        <>
                            <Select value={serviceTypeFilter} onValueChange={setServiceTypeFilter}>
                                <SelectTrigger className="w-40">
                                    <SelectValue placeholder="Service Type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Services</SelectItem>
                                    {serviceTypes.map((serviceType) => (
                                        <SelectItem key={serviceType} value={serviceType}>
                                            {serviceType}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <Select value={vendorStatusFilter} onValueChange={setVendorStatusFilter}>
                                <SelectTrigger className="w-36">
                                    <SelectValue placeholder="Status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Status</SelectItem>
                                    <SelectItem value="active">Active</SelectItem>
                                    <SelectItem value="inactive">Inactive</SelectItem>
                                </SelectContent>
                            </Select>

                            <Select value={preferredFilter} onValueChange={setPreferredFilter}>
                                <SelectTrigger className="w-40">
                                    <SelectValue placeholder="Preferred" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Vendors</SelectItem>
                                    <SelectItem value="yes">Preferred only</SelectItem>
                                </SelectContent>
                            </Select>
                        </>
                    )}

                    {can.credentials && (
                        <>
                            <Select value={credentialTypeFilter} onValueChange={setCredentialTypeFilter}>
                                <SelectTrigger className="w-40">
                                    <SelectValue placeholder="Credential Type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Types</SelectItem>
                                    {credentialTypes.map((credentialType) => (
                                        <SelectItem key={credentialType} value={credentialType}>
                                            {credentialType}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <Select value={reauthFilter} onValueChange={setReauthFilter}>
                                <SelectTrigger className="w-40">
                                    <SelectValue placeholder="Re-auth" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    <SelectItem value="yes">Re-auth required</SelectItem>
                                    <SelectItem value="no">No re-auth</SelectItem>
                                </SelectContent>
                            </Select>
                        </>
                    )}

                    {hasFilters && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={clearFilters}
                            className="gap-1.5 text-muted-foreground"
                        >
                            <X className="h-3.5 w-3.5" />
                            Clear
                        </Button>
                    )}
                </div>

                {/* Tabs render only the sections the viewer is allowed to see. */}
                <Tabs defaultValue={can.vendors ? 'vendors' : 'credentials'} className="space-y-4">
                    <TabsList
                        className={
                            can.vendors && can.credentials
                                ? 'grid w-full grid-cols-2'
                                : 'grid w-full grid-cols-1'
                        }
                    >
                        {can.vendors && (
                            <TabsTrigger value="vendors" className="flex items-center gap-2">
                                <Truck className="h-4 w-4" />
                                Vendors ({filteredVendors.length})
                            </TabsTrigger>
                        )}
                        {can.credentials && (
                            <TabsTrigger value="credentials" className="flex items-center gap-2">
                                <Lock className="h-4 w-4" />
                                Credentials ({filteredCredentials.length})
                            </TabsTrigger>
                        )}
                    </TabsList>

                    {can.vendors && (
                        <TabsContent value="vendors">
                            <Card>
                                <CardContent className="p-0">
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead className="border-b bg-muted/50">
                                                <tr>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                                        Company
                                                    </th>
                                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground md:table-cell">
                                                        Site
                                                    </th>
                                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground sm:table-cell">
                                                        Service
                                                    </th>
                                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground lg:table-cell">
                                                        Contact
                                                    </th>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                                        Status
                                                    </th>
                                                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                                        Actions
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {filteredVendors.length === 0 ? (
                                                    <tr>
                                                        <td className="px-4 py-16 text-center" colSpan={6}>
                                                            <Truck className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                                                            <p className="font-medium text-muted-foreground">
                                                                No vendors found
                                                            </p>
                                                            <p className="mt-1 text-sm text-muted-foreground/70">
                                                                {hasFilters
                                                                    ? 'Try adjusting your filters'
                                                                    : 'Add vendors from a site to get started'}
                                                            </p>
                                                        </td>
                                                    </tr>
                                                ) : (
                                                    filteredVendors.map((vendor) => (
                                                        <tr
                                                            key={vendor.id}
                                                            className="group cursor-pointer transition-colors hover:bg-muted/40"
                                                            onClick={() =>
                                                                router.visit(`/sites/${vendor.site_id}/vendors`)
                                                            }
                                                        >
                                                            <td className="px-4 py-3">
                                                                <div>
                                                                    <div className="flex items-center gap-2">
                                                                        <span className="font-medium text-foreground group-hover:text-primary">
                                                                            {vendor.company_name}
                                                                        </span>
                                                                        {vendor.is_preferred && (
                                                                            <Star
                                                                                className="h-3.5 w-3.5 fill-status-warning text-status-warning"
                                                                                aria-label="Preferred vendor"
                                                                            />
                                                                        )}
                                                                    </div>
                                                                    {vendor.contact_name && (
                                                                        <div className="text-xs text-muted-foreground">
                                                                            {vendor.contact_name}
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            </td>
                                                            <td className="hidden px-4 py-3 md:table-cell">
                                                                <div className="flex flex-col gap-1">
                                                                    <Link
                                                                        href={`/sites/${vendor.site_id}`}
                                                                        className="text-sm text-foreground hover:text-primary"
                                                                        onClick={(e) => e.stopPropagation()}
                                                                    >
                                                                        <span className="inline-flex items-center gap-1">
                                                                            <MapPin className="h-3 w-3" />
                                                                            {vendor.site_name || 'Unknown site'}
                                                                        </span>
                                                                    </Link>
                                                                    <SiteTypeBadge type={vendor.site_type} />
                                                                </div>
                                                            </td>
                                                            <td className="hidden px-4 py-3 sm:table-cell">
                                                                <Badge
                                                                    variant="outline"
                                                                    className="border-border bg-muted text-muted-foreground"
                                                                >
                                                                    {vendor.service_type}
                                                                </Badge>
                                                            </td>
                                                            <td
                                                                className="hidden px-4 py-3 text-xs text-muted-foreground lg:table-cell"
                                                                onClick={(e) => e.stopPropagation()}
                                                            >
                                                                <div className="flex flex-col gap-0.5">
                                                                    {vendor.phone && (
                                                                        <a
                                                                            href={`tel:${vendor.phone}`}
                                                                            className="inline-flex items-center gap-1 text-primary hover:text-primary/70"
                                                                        >
                                                                            <Phone className="h-3 w-3" />
                                                                            {vendor.phone}
                                                                        </a>
                                                                    )}
                                                                    {vendor.email && (
                                                                        <a
                                                                            href={`mailto:${vendor.email}`}
                                                                            className="inline-flex items-center gap-1 text-primary hover:text-primary/70"
                                                                        >
                                                                            <Mail className="h-3 w-3" />
                                                                            <span className="max-w-[160px] truncate">
                                                                                {vendor.email}
                                                                            </span>
                                                                        </a>
                                                                    )}
                                                                    {!vendor.phone && !vendor.email && (
                                                                        <span>—</span>
                                                                    )}
                                                                </div>
                                                            </td>
                                                            <td className="px-4 py-3">
                                                                <Badge
                                                                    variant="outline"
                                                                    className={
                                                                        vendor.is_active
                                                                            ? 'border-status-success/30 bg-status-success-bg text-status-success'
                                                                            : 'border-border bg-muted text-muted-foreground'
                                                                    }
                                                                >
                                                                    {vendor.is_active ? 'Active' : 'Inactive'}
                                                                </Badge>
                                                            </td>
                                                            <td
                                                                className="px-4 py-3 text-right"
                                                                onClick={(e) => e.stopPropagation()}
                                                            >
                                                                <Button asChild size="sm" variant="ghost">
                                                                    <Link href={`/sites/${vendor.site_id}/vendors`}>
                                                                        <Eye className="mr-1 h-3.5 w-3.5" />
                                                                        Open
                                                                    </Link>
                                                                </Button>
                                                            </td>
                                                        </tr>
                                                    ))
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>
                    )}

                    {can.credentials && (
                        <TabsContent value="credentials">
                            <Card>
                                <CardContent className="p-0">
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead className="border-b bg-muted/50">
                                                <tr>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                                        Credential
                                                    </th>
                                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground md:table-cell">
                                                        Site
                                                    </th>
                                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground sm:table-cell">
                                                        Type
                                                    </th>
                                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground lg:table-cell">
                                                        Last Rotated
                                                    </th>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                                        Status
                                                    </th>
                                                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                                        Actions
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {filteredCredentials.length === 0 ? (
                                                    <tr>
                                                        <td className="px-4 py-16 text-center" colSpan={6}>
                                                            <Lock className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                                                            <p className="font-medium text-muted-foreground">
                                                                No credentials found
                                                            </p>
                                                            <p className="mt-1 text-sm text-muted-foreground/70">
                                                                {hasFilters
                                                                    ? 'Try adjusting your filters'
                                                                    : 'Add credentials from a site to get started'}
                                                            </p>
                                                        </td>
                                                    </tr>
                                                ) : (
                                                    filteredCredentials.map((credential) => (
                                                        <tr
                                                            key={credential.id}
                                                            className="group cursor-pointer transition-colors hover:bg-muted/40"
                                                            onClick={() =>
                                                                router.visit(`/sites/${credential.site_id}/credentials`)
                                                            }
                                                        >
                                                            <td className="px-4 py-3">
                                                                <div>
                                                                    <div className="font-medium text-foreground group-hover:text-primary">
                                                                        {credential.label}
                                                                    </div>
                                                                    {credential.vendor_name && (
                                                                        <div className="text-xs text-muted-foreground">
                                                                            {credential.vendor_name}
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            </td>
                                                            <td className="hidden px-4 py-3 md:table-cell">
                                                                <div className="flex flex-col gap-1">
                                                                    <Link
                                                                        href={`/sites/${credential.site_id}`}
                                                                        className="text-sm text-foreground hover:text-primary"
                                                                        onClick={(e) => e.stopPropagation()}
                                                                    >
                                                                        <span className="inline-flex items-center gap-1">
                                                                            <MapPin className="h-3 w-3" />
                                                                            {credential.site_name || 'Unknown site'}
                                                                        </span>
                                                                    </Link>
                                                                    <SiteTypeBadge type={credential.site_type} />
                                                                </div>
                                                            </td>
                                                            <td className="hidden px-4 py-3 sm:table-cell">
                                                                <Badge
                                                                    variant="outline"
                                                                    className="border-border bg-muted text-muted-foreground"
                                                                >
                                                                    {credential.credential_type}
                                                                </Badge>
                                                            </td>
                                                            <td className="hidden px-4 py-3 text-xs text-muted-foreground lg:table-cell">
                                                                {credential.last_rotated_at
                                                                    ? new Date(credential.last_rotated_at).toLocaleDateString()
                                                                    : '—'}
                                                            </td>
                                                            <td className="px-4 py-3">
                                                                {credential.requires_reauth ? (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="border-status-warning/30 bg-status-warning-bg text-status-warning"
                                                                    >
                                                                        <ShieldCheck className="mr-1 h-3 w-3" />
                                                                        Re-auth
                                                                    </Badge>
                                                                ) : (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="border-border bg-muted text-muted-foreground"
                                                                    >
                                                                        <Lock className="mr-1 h-3 w-3" />
                                                                        Stored
                                                                    </Badge>
                                                                )}
                                                            </td>
                                                            <td
                                                                className="px-4 py-3 text-right"
                                                                onClick={(e) => e.stopPropagation()}
                                                            >
                                                                <Button asChild size="sm" variant="ghost">
                                                                    <Link href={`/sites/${credential.site_id}/credentials`}>
                                                                        <Eye className="mr-1 h-3.5 w-3.5" />
                                                                        Open
                                                                    </Link>
                                                                </Button>
                                                            </td>
                                                        </tr>
                                                    ))
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>
                    )}
                </Tabs>
            </div>
        </AppLayout>
    );
}
