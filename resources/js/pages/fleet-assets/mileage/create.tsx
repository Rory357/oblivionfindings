import FleetHero from '@/components/fleet-hero';
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
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { DollarSign, Loader2, Save } from 'lucide-react';
import { useCallback, useMemo } from 'react';
import { formatCurrency } from '@/lib/fleet-utils';

type Props = {
    clients?: Array<{ id: number; name: string }>;
    ird_rate?: number;
};

export default function MileageCreate({ clients, ird_rate }: Props) {
    const { labels } = usePage().props as any;
    const clientSingular = labels?.['client.singular'] ?? 'Client';

    const PURPOSE_OPTIONS = [
        { value: 'client_visit', label: `${clientSingular} Visit` },
        { value: 'meeting', label: 'Meeting' },
        { value: 'training', label: 'Training' },
        { value: 'admin', label: 'Admin' },
        { value: 'other', label: 'Other' },
    ];
    const safeClients = clients ?? [];
    const rate = ird_rate ?? 0.95;

    const form = useForm({
        date: new Date().toISOString().slice(0, 10),
        start_location: '',
        end_location: '',
        distance_km: '',
        purpose: '',
        client_id: '',
        notes: '',
    });

    const calculatedTotal = useMemo(() => {
        const dist = parseFloat(form.data.distance_km) || 0;
        return dist * rate;
    }, [form.data.distance_km, rate]);

    const handleSubmit = useCallback((e: React.FormEvent) => {
        e.preventDefault();
        form.post('/fleet-assets/mileage');
    }, [form]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Mileage Claims', href: '/fleet-assets/mileage' },
                { title: 'Log Trip', href: '#' },
            ]}
        >
            <Head title="Log Personal Trip" />
            <PageShell>
                <FleetHero
                    title="Log Personal Vehicle Trip"
                    description="Record a trip using your personal vehicle for mileage reimbursement."
                    backHref="/fleet-assets/mileage"
                    backLabel="Back to Mileage Claims"
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    <div className="grid gap-6 lg:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Trip Details</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4">
                                <div>
                                    <label className="text-sm font-medium">Date *</label>
                                    <Input
                                        type="date"
                                        value={form.data.date}
                                        onChange={(e) => form.setData('date', e.target.value)}
                                    />
                                    {form.errors.date && <p className="mt-1 text-xs text-destructive">{form.errors.date}</p>}
                                </div>

                                <div>
                                    <label className="text-sm font-medium">Start Location *</label>
                                    <Input
                                        value={form.data.start_location}
                                        onChange={(e) => form.setData('start_location', e.target.value)}
                                        placeholder="e.g. Office, Home"
                                    />
                                    {form.errors.start_location && <p className="mt-1 text-xs text-destructive">{form.errors.start_location}</p>}
                                </div>

                                <div>
                                    <label className="text-sm font-medium">End Location *</label>
                                    <Input
                                        value={form.data.end_location}
                                        onChange={(e) => form.setData('end_location', e.target.value)}
                                        placeholder="e.g. Client home, Meeting venue"
                                    />
                                    {form.errors.end_location && <p className="mt-1 text-xs text-destructive">{form.errors.end_location}</p>}
                                </div>

                                <div>
                                    <label className="text-sm font-medium">Distance (km) *</label>
                                    <Input
                                        type="number"
                                        step="0.1"
                                        min="0.1"
                                        max="9999"
                                        value={form.data.distance_km}
                                        onChange={(e) => form.setData('distance_km', e.target.value)}
                                        placeholder="0.0"
                                    />
                                    {form.errors.distance_km && <p className="mt-1 text-xs text-destructive">{form.errors.distance_km}</p>}
                                </div>

                                <div>
                                    <label className="text-sm font-medium">Purpose *</label>
                                    <Select value={form.data.purpose} onValueChange={(v) => form.setData('purpose', v)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select purpose" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {PURPOSE_OPTIONS.map((opt) => (
                                                <SelectItem key={opt.value} value={opt.value}>
                                                    {opt.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.purpose && <p className="mt-1 text-xs text-destructive">{form.errors.purpose}</p>}
                                </div>
                            </CardContent>
                        </Card>

                        <div className="space-y-6">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Optional Details</CardTitle>
                                </CardHeader>
                                <CardContent className="grid gap-4">
                                    {safeClients.length > 0 && (
                                        <div>
                                            <label className="text-sm font-medium">Link to Client</label>
                                            <Select
                                                value={form.data.client_id}
                                                onValueChange={(v) => form.setData('client_id', v === 'none' ? '' : v)}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select client (optional)" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="none">No client</SelectItem>
                                                    {safeClients.map((c) => (
                                                        <SelectItem key={c.id} value={String(c.id)}>
                                                            {c.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    )}

                                    <div>
                                        <label className="text-sm font-medium">Notes</label>
                                        <textarea
                                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                            rows={3}
                                            value={form.data.notes}
                                            onChange={(e) => form.setData('notes', e.target.value)}
                                            placeholder="Any additional details about this trip..."
                                        />
                                        {form.errors.notes && <p className="mt-1 text-xs text-destructive">{form.errors.notes}</p>}
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Rate & Total Summary */}
                            <Card className="border-primary bg-primary/10/50 dark:border-primary/30 dark:bg-primary/20">
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-primary dark:text-primary/70">
                                        <DollarSign className="h-5 w-5" />
                                        Reimbursement Calculation
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-2">
                                        <div className="flex items-center justify-between text-sm">
                                            <span className="text-muted-foreground">IRD Rate</span>
                                            <span className="font-medium">${rate.toFixed(2)}/km</span>
                                        </div>
                                        <div className="flex items-center justify-between text-sm">
                                            <span className="text-muted-foreground">Distance</span>
                                            <span className="font-medium tabular-nums">
                                                {parseFloat(form.data.distance_km) || 0} km
                                            </span>
                                        </div>
                                        <div className="border-t pt-2 mt-2">
                                            <div className="flex items-center justify-between">
                                                <span className="font-medium text-primary dark:text-primary/70">Total</span>
                                                <span className="text-xl font-bold tabular-nums text-primary dark:text-primary/70">
                                                    {formatCurrency(calculatedTotal)}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                            Submit Claim
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/fleet-assets/mileage">Cancel</Link>
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
