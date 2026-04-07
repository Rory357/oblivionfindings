import { FleetStatCard } from '@/components/fleet-stat-card';
import { FLEET_COLORS } from '@/components/fleet-charts';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/fleet-utils';
import { Head } from '@inertiajs/react';
import { Calculator, DollarSign, Download, MapPin, Receipt, Users } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

type StaffRow = { name: string; trips: number; distance_km: number; amount: number };

export default function MileageReimbursement() {
    const [period, setPeriod] = useState('this_month');
    const [customFrom, setCustomFrom] = useState('');
    const [customTo, setCustomTo] = useState('');
    const [rate, setRate] = useState('0.95');
    const [data, setData] = useState<StaffRow[] | null>(null);
    const [loading, setLoading] = useState(false);

    const handleGenerate = useCallback(async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams();
            params.set('period', period); params.set('rate', rate);
            if (period === 'custom') { params.set('from', customFrom); params.set('to', customTo); }
            const response = await fetch(`/fleet-assets/reports/reimbursement/data?${params.toString()}`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (response.ok) { const json = await response.json(); setData(json.staff ?? []); }
            else setData([]);
        } catch { setData([]); }
        finally { setLoading(false); }
    }, [period, rate, customFrom, customTo]);

    const totalDistance = useMemo(() => data?.reduce((sum, r) => sum + r.distance_km, 0) ?? 0, [data]);
    const totalTrips = useMemo(() => data?.reduce((sum, r) => sum + r.trips, 0) ?? 0, [data]);
    const totalAmount = useMemo(() => data?.reduce((sum, r) => sum + r.amount, 0) ?? 0, [data]);
    const staffCount = data?.length ?? 0;

    const handleExportCSV = () => {
        if (!data || data.length === 0) return;
        const headers = ['Staff Name', 'Trips', 'Distance (km)', 'Reimbursement ($)'];
        const rows = data.map((r) => [r.name, r.trips, r.distance_km.toFixed(1), r.amount.toFixed(2)]);
        rows.push(['TOTAL', String(totalTrips), totalDistance.toFixed(1), totalAmount.toFixed(2)]);
        const csv = [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a'); a.href = url;
        a.download = `mileage-reimbursement-${new Date().toISOString().slice(0, 10)}.csv`;
        a.click(); URL.revokeObjectURL(url);
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Reports', href: '/fleet-assets/reports' },
                { title: 'Mileage Reimbursement', href: '/fleet-assets/reports/reimbursement' },
            ]}
        >
            <Head title="Mileage Reimbursement" />
            <PageShell>
                <PageHeader
                    title="Mileage Reimbursement"
                    description="Calculate staff mileage reimbursement based on trip data and the NZ IRD rate."
                />

                {/* Dark KPI Cards (visible when data is loaded) */}
                {data !== null && data.length > 0 && (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <FleetStatCard label="TOTAL STAFF" value={staffCount} icon={Users} subtitle="Staff with trips" />
                        <FleetStatCard label="TOTAL DISTANCE" value={`${totalDistance.toFixed(1)} km`} icon={MapPin} subtitle="Kilometres driven" />
                        <FleetStatCard label="TOTAL REIMBURSEMENT" value={formatCurrency(totalAmount)} icon={DollarSign} subtitle={`At ${formatCurrency(rate)}/km`} />
                    </div>
                )}

                {/* Controls */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Calculator className="h-5 w-5" /> Report Parameters
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                            <div>
                                <label className="mb-1 block text-sm font-medium">Period</label>
                                <Select value={period} onValueChange={setPeriod}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="this_month">This Month</SelectItem>
                                        <SelectItem value="last_month">Last Month</SelectItem>
                                        <SelectItem value="custom">Custom Range</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            {period === 'custom' && (
                                <>
                                    <div><label className="mb-1 block text-sm font-medium">From</label><Input type="date" value={customFrom} onChange={(e) => setCustomFrom(e.target.value)} /></div>
                                    <div><label className="mb-1 block text-sm font-medium">To</label><Input type="date" value={customTo} onChange={(e) => setCustomTo(e.target.value)} /></div>
                                </>
                            )}
                            <div>
                                <label className="mb-1 block text-sm font-medium">Rate (NZD/km)</label>
                                <div className="relative">
                                    <DollarSign className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                    <Input
                                        type="number" step="0.01" min="0" value={rate}
                                        onChange={(e) => setRate(e.target.value)} placeholder="0.95"
                                        className="pl-8 text-lg font-semibold"
                                    />
                                </div>
                                <p className="mt-1 text-xs text-muted-foreground">IRD rate: $0.95/km (2024/25)</p>
                            </div>
                        </div>
                        <div className="mt-4 flex gap-2">
                            <Button onClick={handleGenerate} disabled={loading}>
                                <Receipt className="mr-2 h-4 w-4" />{loading ? 'Generating...' : 'Generate Report'}
                            </Button>
                            {data && data.length > 0 && (
                                <Button variant="outline" onClick={handleExportCSV}>
                                    <Download className="mr-2 h-4 w-4" />Export CSV for Payroll
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Results */}
                {data !== null && (
                    <Card>
                        <CardHeader><CardTitle>Reimbursement Summary</CardTitle></CardHeader>
                        <CardContent>
                            {data.length === 0 ? (
                                <div className="py-8 text-center text-muted-foreground">No trip data found for the selected period.</div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-left text-muted-foreground">
                                                <th className="pb-2 pr-4 font-medium">Staff Name</th>
                                                <th className="pb-2 pr-4 text-right font-medium">Trips</th>
                                                <th className="pb-2 pr-4 text-right font-medium">Distance (km)</th>
                                                <th className="pb-2 text-right font-medium">Reimbursement ($)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {data.map((row, idx) => (
                                                <tr key={idx} className="border-b last:border-0 transition-colors hover:bg-muted/30 transition-colors">
                                                    <td className="py-3 pr-4 font-medium">{row.name}</td>
                                                    <td className="py-3 pr-4 text-right">{row.trips}</td>
                                                    <td className="py-3 pr-4 text-right">{row.distance_km.toFixed(1)}</td>
                                                    <td className="py-3 text-right font-medium">${row.amount.toFixed(2)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                        <tfoot>
                                            <tr className="border-t-2 font-bold">
                                                <td className="py-3 pr-4">Total</td>
                                                <td className="py-3 pr-4 text-right">{totalTrips}</td>
                                                <td className="py-3 pr-4 text-right">{totalDistance.toFixed(1)}</td>
                                                <td className="py-3 text-right">${totalAmount.toFixed(2)}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}
            </PageShell>
        </AppLayout>
    );
}
