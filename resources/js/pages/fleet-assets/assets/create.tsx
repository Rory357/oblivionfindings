import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Loader2, Save } from 'lucide-react';
import { useState } from 'react';

type Props = {
    categories: Array<{ id: number; name: string; slug: string }>;
    sites: Array<{ id: number; name: string }>;
};

export default function AssetCreate({ categories, sites }: Props) {
    const [step, setStep] = useState(1);
    const steps = ['Basic Info', 'Details', 'Location', 'Compliance'];

    const form = useForm({
        name: '',
        asset_tag: '',
        category: 'vehicle',
        asset_category_id: '',
        status: 'active',
        risk_level: 'low',
        site_id: '',
        home_site_id: '',
        location: '',
        manufacturer: '',
        model: '',
        serial_number: '',
        description: '',
        registration_number: '',
        registration_expires_at: '',
        wof_expires_at: '',
        cof_expires_at: '',
        fuel_type: '',
        odometer_km: '',
        purchase_date: '',
        warranty_expires_at: '',
        requires_inspection: false,
        inspection_due_at: '',
        requires_maintenance: false,
        maintenance_due_at: '',
        notes: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/fleet-assets/assets');
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Assets', href: '/fleet-assets/assets' },
                { title: 'Create', href: '#' },
            ]}
        >
            <Head title="Create Asset" />
            <PageShell>
                <PageHeader
                    title="Create Asset"
                    description="Add a new asset to the system."
                    backHref="/fleet-assets/assets"
                    backLabel="Back to Assets"
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Step Progress Indicator */}
                    <div className="flex items-center gap-2 mb-6 flex-wrap">
                        {steps.map((s, i) => (
                            <button
                                key={i}
                                type="button"
                                onClick={() => setStep(i + 1)}
                                className={cn(
                                    "flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-medium transition-colors",
                                    step === i + 1
                                        ? "bg-purple-600 text-white"
                                        : "bg-muted text-muted-foreground hover:bg-muted/80"
                                )}
                            >
                                <span className="flex h-5 w-5 items-center justify-center rounded-full bg-white/20 text-xs">{i + 1}</span>
                                {s}
                            </button>
                        ))}
                    </div>

                    {/* Step 1: Basic Info */}
                    {step === 1 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Basic Information</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label className="text-sm font-medium">Name *</label>
                                    <Input
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                        placeholder="Asset name"
                                    />
                                    {form.errors.name && <p className="mt-1 text-xs text-destructive">{form.errors.name}</p>}
                                </div>
                                <div>
                                    <label className="text-sm font-medium">Asset Tag</label>
                                    <Input
                                        value={form.data.asset_tag}
                                        onChange={(e) => form.setData('asset_tag', e.target.value)}
                                        placeholder="e.g. VEH-001"
                                    />
                                    {form.errors.asset_tag && <p className="mt-1 text-xs text-destructive">{form.errors.asset_tag}</p>}
                                </div>
                                <div>
                                    <label className="text-sm font-medium">Category</label>
                                    <Select value={form.data.category} onValueChange={(v) => form.setData('category', v)}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="vehicle">Vehicle</SelectItem>
                                            <SelectItem value="equipment">Equipment</SelectItem>
                                            <SelectItem value="property">Property</SelectItem>
                                            <SelectItem value="other">Other</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {form.errors.category && <p className="mt-1 text-xs text-destructive">{form.errors.category}</p>}
                                </div>
                                <div>
                                    <label className="text-sm font-medium">Status</label>
                                    <Select value={form.data.status} onValueChange={(v) => form.setData('status', v)}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="active">Active</SelectItem>
                                            <SelectItem value="out_of_service">Out of Service</SelectItem>
                                            <SelectItem value="retired">Retired</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {form.errors.status && <p className="mt-1 text-xs text-destructive">{form.errors.status}</p>}
                                </div>
                                <div>
                                    <label className="text-sm font-medium">Risk Level</label>
                                    <Select value={form.data.risk_level} onValueChange={(v) => form.setData('risk_level', v)}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="low">Low</SelectItem>
                                            <SelectItem value="medium">Medium</SelectItem>
                                            <SelectItem value="high">High</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {form.errors.risk_level && <p className="mt-1 text-xs text-destructive">{form.errors.risk_level}</p>}
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Step 2: Details */}
                    {step === 2 && (
                        <>
                            <Card>
                                <CardHeader>
                                    <CardTitle>Details</CardTitle>
                                </CardHeader>
                                <CardContent className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label className="text-sm font-medium">Manufacturer</label>
                                        <Input
                                            value={form.data.manufacturer}
                                            onChange={(e) => form.setData('manufacturer', e.target.value)}
                                        />
                                    </div>
                                    <div>
                                        <label className="text-sm font-medium">Model</label>
                                        <Input
                                            value={form.data.model}
                                            onChange={(e) => form.setData('model', e.target.value)}
                                        />
                                    </div>
                                    <div>
                                        <label className="text-sm font-medium">Serial Number</label>
                                        <Input
                                            value={form.data.serial_number}
                                            onChange={(e) => form.setData('serial_number', e.target.value)}
                                        />
                                    </div>
                                    <div className="sm:col-span-2">
                                        <label className="text-sm font-medium">Description</label>
                                        <textarea
                                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                            rows={3}
                                            value={form.data.description}
                                            onChange={(e) => form.setData('description', e.target.value)}
                                        />
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Vehicle Fields */}
                            {form.data.category === 'vehicle' && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Vehicle Details</CardTitle>
                                    </CardHeader>
                                    <CardContent className="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <label className="text-sm font-medium">Registration Number</label>
                                            <Input
                                                value={form.data.registration_number}
                                                onChange={(e) => form.setData('registration_number', e.target.value)}
                                            />
                                        </div>
                                        <div>
                                            <label className="text-sm font-medium">Fuel Type</label>
                                            <Select value={form.data.fuel_type} onValueChange={(v) => form.setData('fuel_type', v)}>
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select fuel type" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="petrol">Petrol</SelectItem>
                                                    <SelectItem value="diesel">Diesel</SelectItem>
                                                    <SelectItem value="electric">Electric</SelectItem>
                                                    <SelectItem value="hybrid">Hybrid</SelectItem>
                                                    <SelectItem value="lpg">LPG</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div>
                                            <label className="text-sm font-medium">Odometer (km)</label>
                                            <Input
                                                type="number"
                                                value={form.data.odometer_km}
                                                onChange={(e) => form.setData('odometer_km', e.target.value)}
                                            />
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Purchase & Warranty */}
                            <Card>
                                <CardHeader>
                                    <CardTitle>Purchase & Warranty</CardTitle>
                                </CardHeader>
                                <CardContent className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label className="text-sm font-medium">Purchase Date</label>
                                        <Input
                                            type="date"
                                            value={form.data.purchase_date}
                                            onChange={(e) => form.setData('purchase_date', e.target.value)}
                                        />
                                    </div>
                                    <div>
                                        <label className="text-sm font-medium">Warranty Expires</label>
                                        <Input
                                            type="date"
                                            value={form.data.warranty_expires_at}
                                            onChange={(e) => form.setData('warranty_expires_at', e.target.value)}
                                        />
                                    </div>
                                </CardContent>
                            </Card>
                        </>
                    )}

                    {/* Step 3: Location */}
                    {step === 3 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Location</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label className="text-sm font-medium">Site</label>
                                    <Select value={form.data.site_id} onValueChange={(v) => form.setData('site_id', v)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select site" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(sites ?? []).map((site) => (
                                                <SelectItem key={site.id} value={String(site.id)}>
                                                    {site.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.site_id && <p className="mt-1 text-xs text-destructive">{form.errors.site_id}</p>}
                                </div>
                                <div>
                                    <label className="text-sm font-medium">Home Site</label>
                                    <Select value={form.data.home_site_id} onValueChange={(v) => form.setData('home_site_id', v)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select home site" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(sites ?? []).map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>
                                                    {s.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.home_site_id && <p className="mt-1 text-xs text-destructive">{form.errors.home_site_id}</p>}
                                </div>
                                <div className="sm:col-span-2">
                                    <label className="text-sm font-medium">Location Description</label>
                                    <Input
                                        value={form.data.location}
                                        onChange={(e) => form.setData('location', e.target.value)}
                                        placeholder="e.g. Bay 3, Warehouse A"
                                    />
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Step 4: Compliance */}
                    {step === 4 && (
                        <>
                            {form.data.category === 'vehicle' && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Compliance Dates</CardTitle>
                                    </CardHeader>
                                    <CardContent className="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <label className="text-sm font-medium">Registration Expires</label>
                                            <Input
                                                type="date"
                                                value={form.data.registration_expires_at}
                                                onChange={(e) => form.setData('registration_expires_at', e.target.value)}
                                            />
                                        </div>
                                        <div>
                                            <label className="text-sm font-medium">WOF Expires</label>
                                            <Input
                                                type="date"
                                                value={form.data.wof_expires_at}
                                                onChange={(e) => form.setData('wof_expires_at', e.target.value)}
                                            />
                                        </div>
                                        <div>
                                            <label className="text-sm font-medium">COF Expires</label>
                                            <Input
                                                type="date"
                                                value={form.data.cof_expires_at}
                                                onChange={(e) => form.setData('cof_expires_at', e.target.value)}
                                            />
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            <Card>
                                <CardHeader>
                                    <CardTitle>Inspection & Maintenance</CardTitle>
                                </CardHeader>
                                <CardContent className="grid gap-4 sm:grid-cols-2">
                                    <div className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            id="requires_inspection"
                                            checked={form.data.requires_inspection}
                                            onChange={(e) => form.setData('requires_inspection', e.target.checked)}
                                            className="rounded border-gray-300"
                                        />
                                        <label htmlFor="requires_inspection" className="text-sm font-medium">Requires Inspection</label>
                                    </div>
                                    {form.data.requires_inspection && (
                                        <div>
                                            <label className="text-sm font-medium">Next Inspection Due</label>
                                            <Input
                                                type="date"
                                                value={form.data.inspection_due_at}
                                                onChange={(e) => form.setData('inspection_due_at', e.target.value)}
                                            />
                                        </div>
                                    )}
                                    <div className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            id="requires_maintenance"
                                            checked={form.data.requires_maintenance}
                                            onChange={(e) => form.setData('requires_maintenance', e.target.checked)}
                                            className="rounded border-gray-300"
                                        />
                                        <label htmlFor="requires_maintenance" className="text-sm font-medium">Requires Maintenance</label>
                                    </div>
                                    {form.data.requires_maintenance && (
                                        <div>
                                            <label className="text-sm font-medium">Next Maintenance Due</label>
                                            <Input
                                                type="date"
                                                value={form.data.maintenance_due_at}
                                                onChange={(e) => form.setData('maintenance_due_at', e.target.value)}
                                            />
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Notes</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <textarea
                                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        rows={4}
                                        value={form.data.notes}
                                        onChange={(e) => form.setData('notes', e.target.value)}
                                        placeholder="Additional notes..."
                                    />
                                </CardContent>
                            </Card>
                        </>
                    )}

                    {/* Navigation Buttons */}
                    <div className="flex items-center justify-between">
                        <div>
                            {step > 1 && (
                                <Button type="button" variant="outline" onClick={() => setStep(step - 1)}>
                                    <ChevronLeft className="mr-1 h-4 w-4" />
                                    Previous
                                </Button>
                            )}
                        </div>
                        <div className="flex items-center gap-2">
                            {step < steps.length ? (
                                <Button type="button" onClick={() => setStep(step + 1)}>
                                    Next
                                    <ChevronRight className="ml-1 h-4 w-4" />
                                </Button>
                            ) : (
                                <>
                                    <Button type="submit" disabled={form.processing}>
                                        {form.processing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                                        Create Asset
                                    </Button>
                                    <Button variant="outline" asChild>
                                        <Link href="/fleet-assets/assets">Cancel</Link>
                                    </Button>
                                </>
                            )}
                        </div>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
