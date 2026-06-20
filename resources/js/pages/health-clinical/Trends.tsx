import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    HealthClinicalShell,
    type HealthClinicalKpis,
} from '@/pages/health-clinical/components/health-clinical-shell';
import { TrendChartsGrid, type TrendSetsMap } from '@/pages/health-clinical/components/trend-charts';
import { Link, router } from '@inertiajs/react';
import { ArrowUpRight, Filter, LineChart, TrendingUp } from 'lucide-react';
import { useState } from 'react';

type ClientRef = { id: number; first_name: string; last_name: string };

type Filters = {
    client_id: number | null;
    date_from: string;
    date_to: string;
};

type Props = {
    clients: ClientRef[];
    selected_client: ClientRef | null;
    filters: Filters;
    trend_sets: TrendSetsMap | null;
    kpis: HealthClinicalKpis;
    tab_counts?: Record<string, number>;
};

const NONE_SENTINEL = '__none__';

export default function Trends({ clients, selected_client, filters, trend_sets, kpis, tab_counts }: Props) {
    const [local, setLocal] = useState<{ client_id: string; date_from: string; date_to: string }>({
        client_id: filters.client_id ? String(filters.client_id) : '',
        date_from: filters.date_from,
        date_to: filters.date_to,
    });

    const apply = (overrides?: Partial<typeof local>) => {
        const next = { ...local, ...overrides };
        const params: Record<string, string> = {};
        if (next.client_id) params.client_id = next.client_id;
        if (next.date_from) params.date_from = next.date_from;
        if (next.date_to) params.date_to = next.date_to;
        router.get('/health-clinical/trends', params, { preserveState: true, replace: true });
    };

    const onClientChange = (value: string) => {
        const client_id = value === NONE_SENTINEL ? '' : value;
        setLocal((c) => ({ ...c, client_id }));
        apply({ client_id });
    };

    const clientName = selected_client ? `${selected_client.first_name} ${selected_client.last_name}` : null;

    return (
        <HealthClinicalShell activeTab="trends" kpis={kpis} tabCounts={tab_counts}>
            <Card>
                <CardHeader className="pb-3">
                    <CardTitle className="flex items-center gap-2 text-sm">
                        <Filter className="h-4 w-4" /> Client &amp; date range
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="lg:col-span-2">
                            <Label className="text-xs">Client</Label>
                            <Select value={local.client_id || NONE_SENTINEL} onValueChange={onClientChange}>
                                <SelectTrigger className="h-8 text-xs">
                                    <SelectValue placeholder="Select a client" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE_SENTINEL}>Select a client…</SelectItem>
                                    {clients.map((c) => (
                                        <SelectItem key={c.id} value={String(c.id)}>
                                            {c.first_name} {c.last_name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs">From</Label>
                            <Input
                                type="date"
                                className="h-8 text-xs"
                                value={local.date_from}
                                onChange={(e) => setLocal((c) => ({ ...c, date_from: e.target.value }))}
                            />
                        </div>
                        <div>
                            <Label className="text-xs">To</Label>
                            <Input
                                type="date"
                                className="h-8 text-xs"
                                value={local.date_to}
                                onChange={(e) => setLocal((c) => ({ ...c, date_to: e.target.value }))}
                            />
                        </div>
                    </div>
                    <div className="mt-3 flex items-center gap-2">
                        <Button size="sm" onClick={() => apply()} disabled={!local.client_id}>
                            Apply range
                        </Button>
                        {clientName ? (
                            <>
                                <Link href={`/health-clinical/clients/${selected_client!.id}/trends`}>
                                    <Button size="sm" variant="outline" className="gap-1.5">
                                        Full client trends <ArrowUpRight className="h-3.5 w-3.5" />
                                    </Button>
                                </Link>
                                <Link href={`/health-clinical/observations?client_id=${selected_client!.id}`}>
                                    <Button size="sm" variant="ghost">
                                        Observation register
                                    </Button>
                                </Link>
                            </>
                        ) : null}
                    </div>
                </CardContent>
            </Card>

            {!selected_client || !trend_sets ? (
                <Card>
                    <CardContent className="flex h-[260px] flex-col items-center justify-center gap-2 text-center">
                        <LineChart className="h-10 w-10 text-muted-foreground/40" />
                        <h2 className="text-lg font-semibold">Pick a client to chart their trends</h2>
                        <p className="max-w-lg text-sm text-muted-foreground">
                            Choose a client above to see their NEWS2 early-warning score, vitals, weight, pain and fluid-intake
                            trends over the selected window.
                        </p>
                    </CardContent>
                </Card>
            ) : (
                <div className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <TrendingUp className="h-4 w-4 text-primary" />
                        <h2 className="text-sm font-semibold">
                            {clientName} · {filters.date_from} to {filters.date_to}
                        </h2>
                    </div>
                    <TrendChartsGrid trendSets={trend_sets} />
                </div>
            )}
        </HealthClinicalShell>
    );
}
