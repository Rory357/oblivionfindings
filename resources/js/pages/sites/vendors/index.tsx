import AppLayout from '@/layouts/app-layout';
import FleetHero from '@/components/fleet-hero';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Truck, Phone, Mail, Star, Plus, X, Search, Trash2, Lock } from 'lucide-react';
import { useState } from 'react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Site = {
    id: number;
    name: string;
    type: string;
};

type Vendor = {
    id: number;
    service_type: string;
    company_name: string;
    contact_name?: string;
    phone?: string;
    after_hours_phone?: string;
    email?: string;
    account_number?: string;
    notes?: string;
    preferred_contact_method: 'phone' | 'after_hours' | 'email';
    is_preferred: boolean;
    is_active: boolean;
};

type Props = {
    site: Site;
    vendors: Vendor[];
    serviceTypes: string[];
    filters: {
        service_type?: string;
        status?: string;
    };
};

export default function SiteVendors({ site, vendors }: Props) {
    const [showForm, setShowForm] = useState(false);
    const [editingVendor, setEditingVendor] = useState<Vendor | null>(null);
    const [search, setSearch] = useState('');

    const form = useForm<{
        service_type: string;
        company_name: string;
        contact_name: string;
        phone: string;
        after_hours_phone: string;
        email: string;
        account_number: string;
        notes: string;
        preferred_contact_method: Vendor['preferred_contact_method'];
        is_preferred: boolean;
    }>({
        service_type: '',
        company_name: '',
        contact_name: '',
        phone: '',
        after_hours_phone: '',
        email: '',
        account_number: '',
        notes: '',
        preferred_contact_method: 'phone',
        is_preferred: false,
    });

    const startEdit = (vendor: Vendor) => {
        setEditingVendor(vendor);
        form.setData({
            service_type: vendor.service_type,
            company_name: vendor.company_name,
            contact_name: vendor.contact_name || '',
            phone: vendor.phone || '',
            after_hours_phone: vendor.after_hours_phone || '',
            email: vendor.email || '',
            account_number: vendor.account_number || '',
            notes: vendor.notes || '',
            preferred_contact_method: vendor.preferred_contact_method,
            is_preferred: vendor.is_preferred,
        });
        setShowForm(true);
    };

    const resetForm = () => {
        setEditingVendor(null);
        setShowForm(false);
        form.reset();
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editingVendor) {
            form.put(`/sites/${site.id}/vendors/${editingVendor.id}`, {
                onSuccess: resetForm,
            });
        } else {
            form.post(`/sites/${site.id}/vendors`, {
                onSuccess: resetForm,
            });
        }
    };

    const filteredVendors = vendors.filter(
        (v) =>
            v.company_name.toLowerCase().includes(search.toLowerCase()) ||
            v.service_type.toLowerCase().includes(search.toLowerCase()),
    );

    const groupedVendors = filteredVendors.reduce((acc, vendor) => {
        if (!acc[vendor.service_type]) acc[vendor.service_type] = [];
        acc[vendor.service_type].push(vendor);
        return acc;
    }, {} as Record<string, Vendor[]>);

    const activeVendors = vendors.filter((v) => v.is_active).length;
    const preferredVendors = vendors.filter((v) => v.is_preferred).length;

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
                            <Button size="sm" onClick={() => setShowForm(true)}>
                                <Plus className="mr-1.5 h-4 w-4" />
                                Add Vendor
                            </Button>
                        </div>
                    }
                />

                {/* Search Bar */}
                {vendors.length > 0 && (
                    <div className="relative max-w-md">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search by company name or service type..."
                            className="pl-9"
                        />
                    </div>
                )}

                {/* Add/Edit Form */}
                {showForm && (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between border-b bg-muted/30">
                            <CardTitle className="text-base">
                                {editingVendor ? 'Edit Vendor' : 'Add Vendor'}
                            </CardTitle>
                            <Button variant="ghost" size="sm" onClick={resetForm}>
                                <X className="h-4 w-4" />
                            </Button>
                        </CardHeader>
                        <CardContent className="pt-6">
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label>Service Type *</Label>
                                        <Input
                                            value={form.data.service_type}
                                            onChange={(e) => form.setData('service_type', e.target.value)}
                                            placeholder="e.g., Plumbing, Electrical, Cleaning"
                                            required
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Company Name *</Label>
                                        <Input
                                            value={form.data.company_name}
                                            onChange={(e) => form.setData('company_name', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Contact Name</Label>
                                        <Input
                                            value={form.data.contact_name}
                                            onChange={(e) => form.setData('contact_name', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Account Number</Label>
                                        <Input
                                            value={form.data.account_number}
                                            onChange={(e) => form.setData('account_number', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Phone</Label>
                                        <Input
                                            value={form.data.phone}
                                            onChange={(e) => form.setData('phone', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>After-hours Phone</Label>
                                        <Input
                                            value={form.data.after_hours_phone}
                                            onChange={(e) => form.setData('after_hours_phone', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Email</Label>
                                        <Input
                                            type="email"
                                            value={form.data.email}
                                            onChange={(e) => form.setData('email', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Preferred Contact</Label>
                                        <Select
                                            value={form.data.preferred_contact_method}
                                            onValueChange={(v) => form.setData('preferred_contact_method', v as Vendor['preferred_contact_method'])}
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="phone">Phone</SelectItem>
                                                <SelectItem value="after_hours">After-hours Phone</SelectItem>
                                                <SelectItem value="email">Email</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Notes</Label>
                                    <Textarea
                                        value={form.data.notes}
                                        onChange={(e) => form.setData('notes', e.target.value)}
                                        rows={3}
                                    />
                                </div>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.data.is_preferred}
                                        onChange={(e) => form.setData('is_preferred', e.target.checked)}
                                        className="h-4 w-4 rounded border-border"
                                    />
                                    <span>Preferred vendor</span>
                                </label>
                                <div className="flex gap-2 border-t pt-4">
                                    <Button type="submit" disabled={form.processing}>
                                        {editingVendor ? 'Save Changes' : 'Add Vendor'}
                                    </Button>
                                    <Button type="button" variant="outline" onClick={resetForm}>
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {/* Vendors List */}
                {vendors.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center text-muted-foreground">
                            <Truck className="mx-auto mb-3 h-12 w-12 opacity-50" />
                            <p className="mb-1 text-lg font-medium">No vendors registered</p>
                            <p className="text-sm">Add your first vendor to keep track of service providers for this site.</p>
                            <Button onClick={() => setShowForm(true)} className="mt-4">
                                <Plus className="mr-1 h-4 w-4" />
                                Add Your First Vendor
                            </Button>
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
                        {Object.entries(groupedVendors).map(([serviceType, serviceVendors]) => (
                            <div key={serviceType}>
                                <h2 className="mb-2 text-sm font-medium uppercase tracking-wide text-muted-foreground">
                                    {serviceType}
                                    <span className="ml-2 text-xs text-muted-foreground">({serviceVendors.length})</span>
                                </h2>
                                <div className="space-y-2">
                                    {serviceVendors.map((vendor) => (
                                        <Card key={vendor.id} className={!vendor.is_active ? 'opacity-60' : ''}>
                                            <CardContent className="p-4">
                                                <div className="flex items-start justify-between">
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-medium">{vendor.company_name}</span>
                                                            {vendor.is_preferred && (
                                                                <Badge variant="outline" className="border-status-warning/30 text-status-warning">
                                                                    <Star className="mr-1 h-3 w-3" />
                                                                    Preferred
                                                                </Badge>
                                                            )}
                                                            {!vendor.is_active && (
                                                                <Badge variant="outline" className="text-muted-foreground">Inactive</Badge>
                                                            )}
                                                        </div>
                                                        {vendor.contact_name && (
                                                            <div className="text-sm text-muted-foreground">{vendor.contact_name}</div>
                                                        )}
                                                        <div className="mt-2 flex flex-wrap gap-3 text-sm">
                                                            {vendor.phone && (
                                                                <a href={`tel:${vendor.phone}`} className="flex items-center gap-1 text-primary hover:text-primary/70">
                                                                    <Phone className="h-4 w-4" />
                                                                    {vendor.phone}
                                                                </a>
                                                            )}
                                                            {vendor.after_hours_phone && (
                                                                <a href={`tel:${vendor.after_hours_phone}`} className="flex items-center gap-1 text-status-warning hover:text-status-warning">
                                                                    <Phone className="h-4 w-4" />
                                                                    After-hours: {vendor.after_hours_phone}
                                                                </a>
                                                            )}
                                                            {vendor.email && (
                                                                <a href={`mailto:${vendor.email}`} className="flex items-center gap-1 text-primary hover:text-primary/70">
                                                                    <Mail className="h-4 w-4" />
                                                                    {vendor.email}
                                                                </a>
                                                            )}
                                                        </div>
                                                        {vendor.account_number && (
                                                            <div className="mt-1 text-xs text-muted-foreground">
                                                                Account: {vendor.account_number}
                                                            </div>
                                                        )}
                                                        {vendor.notes && (
                                                            <div className="mt-2 whitespace-pre-wrap border-t border-border/50 pt-2 text-sm text-muted-foreground">
                                                                {vendor.notes}
                                                            </div>
                                                        )}
                                                    </div>
                                                    <div className="ml-2 flex shrink-0 items-center gap-1">
                                                        <Button variant="ghost" size="sm" onClick={() => startEdit(vendor)}>
                                                            Edit
                                                        </Button>
                                                        <AlertDialog>
                                                            <AlertDialogTrigger asChild>
                                                                <Button variant="ghost" size="sm" className="text-status-critical hover:text-status-critical">
                                                                    <Trash2 className="h-4 w-4" />
                                                                </Button>
                                                            </AlertDialogTrigger>
                                                            <AlertDialogContent>
                                                                <AlertDialogHeader>
                                                                    <AlertDialogTitle>Delete Vendor</AlertDialogTitle>
                                                                    <AlertDialogDescription>
                                                                        Delete &quot;{vendor.company_name}&quot;? This cannot be undone. Vendors with linked credentials cannot be deleted.
                                                                    </AlertDialogDescription>
                                                                </AlertDialogHeader>
                                                                <AlertDialogFooter>
                                                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                                    <AlertDialogAction
                                                                        className="bg-status-critical hover:bg-status-critical"
                                                                        onClick={() => router.delete(`/sites/${site.id}/vendors/${vendor.id}`)}
                                                                    >
                                                                        Delete
                                                                    </AlertDialogAction>
                                                                </AlertDialogFooter>
                                                            </AlertDialogContent>
                                                        </AlertDialog>
                                                    </div>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
