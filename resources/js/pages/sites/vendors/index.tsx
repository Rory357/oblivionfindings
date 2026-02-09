import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Truck, Phone, Mail, Star, Plus, X } from 'lucide-react';
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

export default function SiteVendors({ site, vendors, serviceTypes }: Props) {
    const [showForm, setShowForm] = useState(false);
    const [editingVendor, setEditingVendor] = useState<Vendor | null>(null);

    const form = useForm({
        service_type: '',
        company_name: '',
        contact_name: '',
        phone: '',
        after_hours_phone: '',
        email: '',
        account_number: '',
        notes: '',
        preferred_contact_method: 'phone' as const,
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

    // Group vendors by service type
    const groupedVendors = vendors.reduce((acc, vendor) => {
        if (!acc[vendor.service_type]) acc[vendor.service_type] = [];
        acc[vendor.service_type].push(vendor);
        return acc;
    }, {} as Record<string, Vendor[]>);

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites', href: '/sites' }, { title: site.name, href: `/sites/${site.id}` }, { title: 'Vendors', href: `/sites/${site.id}/vendors` }]}>
            <Head title={`${site.name} - Vendors`} />

            <div className="m-4 space-y-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <Truck className="w-5 h-5" />
                            Vendors & Credentials
                        </h1>
                        <p className="text-sm text-slate-400">{site.name}</p>
                    </div>
                    <Button onClick={() => setShowForm(true)}>
                        <Plus className="w-4 h-4 mr-1" />
                        Add Vendor
                    </Button>
                </div>

                {/* Add/Edit Form */}
                {showForm && (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>{editingVendor ? 'Edit Vendor' : 'Add Vendor'}</CardTitle>
                            <Button variant="ghost" size="sm" onClick={resetForm}>
                                <X className="w-4 h-4" />
                            </Button>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label>Service Type *</Label>
                                        <Input
                                            value={form.data.service_type}
                                            onChange={(e) => form.setData('service_type', e.target.value)}
                                            placeholder="e.g., Plumbing, Electrical, Cleaning"
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>Company Name *</Label>
                                        <Input
                                            value={form.data.company_name}
                                            onChange={(e) => form.setData('company_name', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>Contact Name</Label>
                                        <Input
                                            value={form.data.contact_name}
                                            onChange={(e) => form.setData('contact_name', e.target.value)}
                                        />
                                    </div>
                                    <div>
                                        <Label>Account Number</Label>
                                        <Input
                                            value={form.data.account_number}
                                            onChange={(e) => form.setData('account_number', e.target.value)}
                                        />
                                    </div>
                                    <div>
                                        <Label>Phone</Label>
                                        <Input
                                            value={form.data.phone}
                                            onChange={(e) => form.setData('phone', e.target.value)}
                                        />
                                    </div>
                                    <div>
                                        <Label>After-hours Phone</Label>
                                        <Input
                                            value={form.data.after_hours_phone}
                                            onChange={(e) => form.setData('after_hours_phone', e.target.value)}
                                        />
                                    </div>
                                    <div>
                                        <Label>Email</Label>
                                        <Input
                                            type="email"
                                            value={form.data.email}
                                            onChange={(e) => form.setData('email', e.target.value)}
                                        />
                                    </div>
                                    <div>
                                        <Label>Preferred Contact</Label>
                                        <Select
                                            value={form.data.preferred_contact_method}
                                            onValueChange={(v) => form.setData('preferred_contact_method', v as any)}
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
                                <div>
                                    <Label>Notes</Label>
                                    <Textarea
                                        value={form.data.notes}
                                        onChange={(e) => form.setData('notes', e.target.value)}
                                        rows={3}
                                    />
                                </div>
                                <div className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={form.data.is_preferred}
                                        onChange={(e) => form.setData('is_preferred', e.target.checked)}
                                    />
                                    <Label className="font-normal">Preferred vendor</Label>
                                </div>
                                <div className="flex gap-2">
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
                        <CardContent className="py-8 text-center text-slate-400">
                            <Truck className="w-12 h-12 mx-auto mb-3 opacity-50" />
                            <p>No vendors registered for this site</p>
                        </CardContent>
                    </Card>
                ) : (
                    Object.entries(groupedVendors).map(([serviceType, serviceVendors]) => (
                        <div key={serviceType}>
                            <h2 className="text-sm font-medium text-slate-400 uppercase tracking-wide mb-2">{serviceType}</h2>
                            <div className="space-y-2">
                                {serviceVendors.map((vendor) => (
                                    <Card key={vendor.id} className={!vendor.is_active ? 'opacity-60' : ''}>
                                        <CardContent className="p-4">
                                            <div className="flex items-start justify-between">
                                                <div>
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-medium">{vendor.company_name}</span>
                                                        {vendor.is_preferred && (
                                                            <Badge variant="outline" className="border-yellow-500/30 text-yellow-400">
                                                                <Star className="w-3 h-3 mr-1" />
                                                                Preferred
                                                            </Badge>
                                                        )}
                                                        {!vendor.is_active && (
                                                            <Badge variant="outline" className="text-slate-500">Inactive</Badge>
                                                        )}
                                                    </div>
                                                    {vendor.contact_name && (
                                                        <div className="text-sm text-slate-400">{vendor.contact_name}</div>
                                                    )}
                                                    <div className="flex flex-wrap gap-3 mt-2 text-sm">
                                                        {vendor.phone && (
                                                            <a href={`tel:${vendor.phone}`} className="flex items-center gap-1 text-indigo-400 hover:text-indigo-300">
                                                                <Phone className="w-4 h-4" />
                                                                {vendor.phone}
                                                            </a>
                                                        )}
                                                        {vendor.after_hours_phone && (
                                                            <a href={`tel:${vendor.after_hours_phone}`} className="flex items-center gap-1 text-amber-400 hover:text-amber-300">
                                                                <Phone className="w-4 h-4" />
                                                                After-hours: {vendor.after_hours_phone}
                                                            </a>
                                                        )}
                                                        {vendor.email && (
                                                            <a href={`mailto:${vendor.email}`} className="flex items-center gap-1 text-indigo-400 hover:text-indigo-300">
                                                                <Mail className="w-4 h-4" />
                                                                {vendor.email}
                                                            </a>
                                                        )}
                                                    </div>
                                                    {vendor.account_number && (
                                                        <div className="text-xs text-slate-500 mt-1">
                                                            Account: {vendor.account_number}
                                                        </div>
                                                    )}
                                                </div>
                                                <Button variant="ghost" size="sm" onClick={() => startEdit(vendor)}>
                                                    Edit
                                                </Button>
                                            </div>
                                        </CardContent>
                                    </Card>
                                ))}
                            </div>
                        </div>
                    ))
                )}
            </div>
        </AppLayout>
    );
}
