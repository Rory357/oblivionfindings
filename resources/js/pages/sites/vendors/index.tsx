import FleetHero from '@/components/fleet-hero';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import {
    Eye,
    Lock,
    Mail,
    Pencil,
    Phone,
    Plus,
    Search,
    Star,
    Trash2,
    Truck,
} from 'lucide-react';
import { lazy, Suspense, useState, type ReactNode } from 'react';
import type { VendorRecord } from './_dialogs';

const AddVendorDialog = lazy(() =>
    import('./_dialogs').then((module) => ({
        default: module.AddVendorDialog,
    })),
);
const EditVendorDialog = lazy(() =>
    import('./_dialogs').then((module) => ({
        default: module.EditVendorDialog,
    })),
);
const ShowVendorDialog = lazy(() =>
    import('./_dialogs').then((module) => ({
        default: module.ShowVendorDialog,
    })),
);
const DeleteVendorDialog = lazy(() =>
    import('./_dialogs').then((module) => ({
        default: module.DeleteVendorDialog,
    })),
);

type Site = {
    id: number;
    name: string;
    type: string;
};

type Props = {
    site: Site;
    vendors: VendorRecord[];
    serviceTypes: string[];
    filters: {
        service_type?: string;
        status?: string;
    };
    canManage: boolean;
};

type VendorDialogMode = 'add' | 'edit' | 'show' | 'delete' | null;

function LazyDialog({ children }: { children: ReactNode }) {
    return <Suspense fallback={null}>{children}</Suspense>;
}

export default function SiteVendors({ site, vendors, canManage }: Props) {
    const [search, setSearch] = useState('');
    const [vendorDialog, setVendorDialog] = useState<{
        mode: VendorDialogMode;
        target: VendorRecord | null;
    }>({ mode: null, target: null });

    const filteredVendors = vendors.filter((vendor) => {
        const query = search.toLowerCase();

        return (
            vendor.company_name.toLowerCase().includes(query) ||
            vendor.service_type.toLowerCase().includes(query) ||
            (vendor.contact_name ?? '').toLowerCase().includes(query)
        );
    });

    const groupedVendors = filteredVendors.reduce(
        (groups, vendor) => {
            if (!groups[vendor.service_type]) groups[vendor.service_type] = [];
            groups[vendor.service_type].push(vendor);
            return groups;
        },
        {} as Record<string, VendorRecord[]>,
    );

    const activeVendors = vendors.filter((vendor) => vendor.is_active).length;
    const preferredVendors = vendors.filter((vendor) => vendor.is_preferred).length;

    const closeVendorDialog = () => setVendorDialog({ mode: null, target: null });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Vendors', href: `/sites/${site.id}/vendors` },
            ]}
        >
            <Head title={`${site.name} - Vendors`} />

            <div className="flex flex-col gap-6 p-6">
                <FleetHero
                    title="Vendors"
                    description={site.name}
                    icon={<Truck className="h-7 w-7 text-white" />}
                    backHref={`/sites/${site.id}`}
                    backLabel={`Back to ${site.name}`}
                    stats={[
                        { label: 'Total', value: vendors.length },
                        { label: 'Active', value: activeVendors },
                        { label: 'Preferred', value: preferredVendors },
                        { label: 'Inactive', value: vendors.length - activeVendors },
                    ]}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Button asChild size="sm" variant="outline">
                                <Link href={`/sites/${site.id}/credentials`}>
                                    <Lock className="mr-1.5 h-4 w-4" />
                                    Credentials
                                </Link>
                            </Button>
                            {canManage && (
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        setVendorDialog({
                                            mode: 'add',
                                            target: null,
                                        })
                                    }
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Add Vendor
                                </Button>
                            )}
                        </div>
                    }
                />

                {vendors.length > 0 && (
                    <div className="relative max-w-md">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search by company, contact, or service type..."
                            className="pl-9"
                        />
                    </div>
                )}

                {vendors.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center text-muted-foreground">
                            <Truck className="mx-auto mb-3 h-12 w-12 opacity-50" />
                            <p className="mb-1 text-lg font-medium">
                                No vendors registered
                            </p>
                            <p className="text-sm">
                                Add vendors to keep track of service providers
                                for this site.
                            </p>
                            {canManage && (
                                <Button
                                    onClick={() =>
                                        setVendorDialog({
                                            mode: 'add',
                                            target: null,
                                        })
                                    }
                                    className="mt-4"
                                >
                                    <Plus className="mr-1 h-4 w-4" />
                                    Add Your First Vendor
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : filteredVendors.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-center text-muted-foreground">
                            <Search className="mx-auto mb-3 h-10 w-10 opacity-50" />
                            <p>No vendors match &quot;{search}&quot;</p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-6">
                        {Object.entries(groupedVendors).map(
                            ([serviceType, serviceVendors]) => (
                                <div key={serviceType}>
                                    <h2 className="mb-2 text-sm font-medium tracking-wide text-muted-foreground uppercase">
                                        {serviceType}
                                        <span className="ml-2 text-xs text-muted-foreground">
                                            ({serviceVendors.length})
                                        </span>
                                    </h2>
                                    <div className="space-y-2">
                                        {serviceVendors.map((vendor) => (
                                            <Card
                                                key={vendor.id}
                                                className={
                                                    vendor.is_active === false
                                                        ? 'opacity-60'
                                                        : ''
                                                }
                                            >
                                                <CardContent className="p-4">
                                                    <div className="flex items-start justify-between gap-4">
                                                        <div className="min-w-0 flex-1">
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <span className="font-medium">
                                                                    {vendor.company_name}
                                                                </span>
                                                                {vendor.is_preferred && (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="border-status-warning/30 text-status-warning"
                                                                    >
                                                                        <Star className="mr-1 h-3 w-3" />
                                                                        Preferred
                                                                    </Badge>
                                                                )}
                                                                {vendor.is_active ===
                                                                    false && (
                                                                    <Badge variant="outline">
                                                                        Inactive
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                            {vendor.contact_name && (
                                                                <div className="text-sm text-muted-foreground">
                                                                    {vendor.contact_name}
                                                                </div>
                                                            )}
                                                            <div className="mt-2 flex flex-wrap gap-3 text-sm">
                                                                {vendor.phone && (
                                                                    <a
                                                                        href={`tel:${vendor.phone}`}
                                                                        className="flex items-center gap-1 text-primary hover:text-primary/70"
                                                                    >
                                                                        <Phone className="h-4 w-4" />
                                                                        {vendor.phone}
                                                                    </a>
                                                                )}
                                                                {vendor.after_hours_phone && (
                                                                    <a
                                                                        href={`tel:${vendor.after_hours_phone}`}
                                                                        className="flex items-center gap-1 text-status-warning hover:text-status-warning"
                                                                    >
                                                                        <Phone className="h-4 w-4" />
                                                                        After-hours:{' '}
                                                                        {
                                                                            vendor.after_hours_phone
                                                                        }
                                                                    </a>
                                                                )}
                                                                {vendor.email && (
                                                                    <a
                                                                        href={`mailto:${vendor.email}`}
                                                                        className="flex items-center gap-1 text-primary hover:text-primary/70"
                                                                    >
                                                                        <Mail className="h-4 w-4" />
                                                                        {vendor.email}
                                                                    </a>
                                                                )}
                                                            </div>
                                                            {vendor.account_number && (
                                                                <div className="mt-1 text-xs text-muted-foreground">
                                                                    Account:{' '}
                                                                    {
                                                                        vendor.account_number
                                                                    }
                                                                </div>
                                                            )}
                                                            {vendor.notes && (
                                                                <div className="mt-2 whitespace-pre-wrap border-t border-border/50 pt-2 text-sm text-muted-foreground">
                                                                    {vendor.notes}
                                                                </div>
                                                            )}
                                                        </div>
                                                        <div className="flex shrink-0 items-center gap-1">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                aria-label="Show vendor"
                                                                onClick={() =>
                                                                    setVendorDialog({
                                                                        mode: 'show',
                                                                        target: vendor,
                                                                    })
                                                                }
                                                            >
                                                                <Eye className="h-4 w-4" />
                                                            </Button>
                                                            {canManage && (
                                                                <>
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        aria-label="Edit vendor"
                                                                        onClick={() =>
                                                                            setVendorDialog({
                                                                                mode: 'edit',
                                                                                target: vendor,
                                                                            })
                                                                        }
                                                                    >
                                                                        <Pencil className="h-4 w-4" />
                                                                    </Button>
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        aria-label="Delete vendor"
                                                                        className="text-status-critical hover:text-status-critical"
                                                                        onClick={() =>
                                                                            setVendorDialog({
                                                                                mode: 'delete',
                                                                                target: vendor,
                                                                            })
                                                                        }
                                                                    >
                                                                        <Trash2 className="h-4 w-4" />
                                                                    </Button>
                                                                </>
                                                            )}
                                                        </div>
                                                    </div>
                                                </CardContent>
                                            </Card>
                                        ))}
                                    </div>
                                </div>
                            ),
                        )}
                    </div>
                )}

                {vendorDialog.mode === 'add' && canManage && (
                    <LazyDialog>
                        <AddVendorDialog
                            siteId={site.id}
                            isOpen
                            onClose={closeVendorDialog}
                        />
                    </LazyDialog>
                )}
                {vendorDialog.mode === 'edit' && canManage && (
                    <LazyDialog>
                        <EditVendorDialog
                            siteId={site.id}
                            vendor={vendorDialog.target}
                            isOpen
                            onClose={closeVendorDialog}
                        />
                    </LazyDialog>
                )}
                {vendorDialog.mode === 'show' && (
                    <LazyDialog>
                        <ShowVendorDialog
                            vendor={vendorDialog.target}
                            isOpen
                            canManage={canManage}
                            onClose={closeVendorDialog}
                            onEdit={() =>
                                setVendorDialog((previous) => ({
                                    ...previous,
                                    mode: 'edit',
                                }))
                            }
                            onDelete={() =>
                                setVendorDialog((previous) => ({
                                    ...previous,
                                    mode: 'delete',
                                }))
                            }
                        />
                    </LazyDialog>
                )}
                {vendorDialog.mode === 'delete' && canManage && (
                    <LazyDialog>
                        <DeleteVendorDialog
                            siteId={site.id}
                            vendor={vendorDialog.target}
                            isOpen
                            onClose={closeVendorDialog}
                        />
                    </LazyDialog>
                )}
            </div>
        </AppLayout>
    );
}
