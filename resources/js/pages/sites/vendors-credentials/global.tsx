import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Truck, Lock, Search, ShieldCheck } from 'lucide-react';
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
};

export default function GlobalVendorsCredentials({
    vendors,
    credentials,
    sites,
    serviceTypes,
    credentialTypes,
    filters,
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
                    !(vendor.site_name ?? '').toLowerCase().includes(s)
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
                    !(credential.site_name ?? '').toLowerCase().includes(s)
                ) {
                    return false;
                }
            }
            return true;
        });
    }, [credentials, siteFilter, credentialTypeFilter, reauthFilter, search]);

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites', href: '/sites' }, { title: 'Vendors & Credentials', href: '/vendors' }]}>
            <Head title="Vendors & Credentials" />

            <div className="m-4 space-y-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <Truck className="w-5 h-5" />
                            Vendors & Credentials
                        </h1>
                        <p className="text-sm text-muted-foreground">All sites</p>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">{filteredVendors.length}</div>
                            <div className="text-sm text-muted-foreground">Vendors</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">{filteredCredentials.length}</div>
                            <div className="text-sm text-muted-foreground">Credentials</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-status-warning border-status-warning/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-status-warning">
                                {filteredCredentials.filter((c) => c.requires_reauth).length}
                            </div>
                            <div className="text-sm text-muted-foreground">Re-auth Required</div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="relative">
                            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search company, credential label, type, or site"
                                className="pl-10"
                            />
                        </div>
                        <div className="grid gap-3 md:grid-cols-6">
                            <div>
                                <Label className="text-xs">Site</Label>
                                <Select value={siteFilter} onValueChange={setSiteFilter}>
                                    <SelectTrigger>
                                        <SelectValue />
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
                            </div>
                            <div>
                                <Label className="text-xs">Service Type</Label>
                                <Select value={serviceTypeFilter} onValueChange={setServiceTypeFilter}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All</SelectItem>
                                        {serviceTypes.map((serviceType) => (
                                            <SelectItem key={serviceType} value={serviceType}>
                                                {serviceType}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Vendor Status</Label>
                                <Select value={vendorStatusFilter} onValueChange={setVendorStatusFilter}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All</SelectItem>
                                        <SelectItem value="active">Active</SelectItem>
                                        <SelectItem value="inactive">Inactive</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Preferred Vendor</Label>
                                <Select value={preferredFilter} onValueChange={setPreferredFilter}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All</SelectItem>
                                        <SelectItem value="yes">Preferred only</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Credential Type</Label>
                                <Select value={credentialTypeFilter} onValueChange={setCredentialTypeFilter}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All</SelectItem>
                                        {credentialTypes.map((credentialType) => (
                                            <SelectItem key={credentialType} value={credentialType}>
                                                {credentialType}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Re-auth Required</Label>
                                <Select value={reauthFilter} onValueChange={setReauthFilter}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All</SelectItem>
                                        <SelectItem value="yes">Yes</SelectItem>
                                        <SelectItem value="no">No</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Tabs defaultValue="vendors" className="space-y-4">
                    <TabsList className="grid w-full grid-cols-2">
                        <TabsTrigger value="vendors" className="flex items-center gap-2">
                            <Truck className="w-4 h-4" />
                            Vendors ({filteredVendors.length})
                        </TabsTrigger>
                        <TabsTrigger value="credentials" className="flex items-center gap-2">
                            <Lock className="w-4 h-4" />
                            Credentials ({filteredCredentials.length})
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="vendors">
                        <Card>
                            <CardContent className="p-4">
                                {filteredVendors.length === 0 ? (
                                    <div className="text-center py-8 text-muted-foreground">No vendors match your filters.</div>
                                ) : (
                                    <div className="space-y-2">
                                        {filteredVendors.map((vendor) => (
                                            <div key={vendor.id} className="rounded-lg border p-3 flex items-center justify-between gap-3">
                                                <div>
                                                    <div className="font-medium">{vendor.company_name}</div>
                                                    <div className="text-sm text-muted-foreground">
                                                        {vendor.site_name} • {vendor.service_type}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground mt-1">
                                                        {vendor.contact_name ? `${vendor.contact_name} • ` : ''}
                                                        {vendor.email || vendor.phone || vendor.after_hours_phone || 'No contact details'}
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    {vendor.is_preferred && (
                                                        <Badge variant="outline" className="border-status-warning/30 text-status-warning">
                                                            Preferred
                                                        </Badge>
                                                    )}
                                                    {!vendor.is_active && (
                                                        <Badge variant="outline" className="border-border/30 text-muted-foreground">
                                                            Inactive
                                                        </Badge>
                                                    )}
                                                    <Button asChild size="sm" variant="outline">
                                                        <Link href={`/sites/${vendor.site_id}/vendors`}>Open Site</Link>
                                                    </Button>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="credentials">
                        <Card>
                            <CardContent className="p-4">
                                {filteredCredentials.length === 0 ? (
                                    <div className="text-center py-8 text-muted-foreground">No credentials match your filters.</div>
                                ) : (
                                    <div className="space-y-2">
                                        {filteredCredentials.map((credential) => (
                                            <div key={credential.id} className="rounded-lg border p-3 flex items-center justify-between gap-3">
                                                <div>
                                                    <div className="font-medium">{credential.label}</div>
                                                    <div className="text-sm text-muted-foreground">
                                                        {credential.site_name} • {credential.credential_type}
                                                        {credential.vendor_name ? ` • ${credential.vendor_name}` : ''}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground mt-1">
                                                        Last rotated: {credential.last_rotated_at ?? '—'}
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Badge variant="outline" className="border-border/30 text-muted-foreground">
                                                        {credential.value_preview}
                                                    </Badge>
                                                    {credential.requires_reauth && (
                                                        <Badge variant="outline" className="border-status-warning/30 text-status-warning">
                                                            <ShieldCheck className="w-3 h-3 mr-1" />
                                                            Re-auth
                                                        </Badge>
                                                    )}
                                                    <Button asChild size="sm" variant="outline">
                                                        <Link href={`/sites/${credential.site_id}/credentials`}>Open Site</Link>
                                                    </Button>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
