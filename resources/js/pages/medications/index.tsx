import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';

type Props = {
    date: string;
    clients: Array<{
        id: number;
        name: string;
        status?: string | null;
        counts: { due: number; late: number; missed: number };
    }>;
};

function pill(label: string, kind: 'ok' | 'warn' | 'bad') {
    const className =
        kind === 'ok'
            ? 'bg-emerald-100 text-emerald-800 border-emerald-200'
            : kind === 'warn'
              ? 'bg-amber-100 text-amber-800 border-amber-200'
              : 'bg-rose-100 text-rose-800 border-rose-200';
    return (
        <Badge variant="outline" className={className}>
            {label}
        </Badge>
    );
}

export default function MedicationsIndex({ date, clients }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Medications', href: '/medications' }]}>
            <Head title="Medications" />

            <div className="space-y-4">
                <div>
                    <div className="text-lg font-semibold">Medications</div>
                    <div className="text-xs text-slate-500">Centralised “run-the-day” view • {date}</div>
                </div>

                <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {clients.map((c) => (
                        <Card key={c.id}>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-base">
                                    <Link className="hover:underline" href={`/clients/${c.id}/mar`}>
                                        {c.name}
                                    </Link>
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                <div className="text-xs text-slate-500">Status: {c.status ?? '—'}</div>
                                <div className="flex flex-wrap items-center gap-2">
                                    {pill(`Due: ${c.counts.due}`, c.counts.due > 0 ? 'warn' : 'ok')}
                                    {pill(`Late: ${c.counts.late}`, c.counts.late > 0 ? 'bad' : 'ok')}
                                    {pill(`Missed: ${c.counts.missed}`, c.counts.missed > 0 ? 'bad' : 'ok')}
                                </div>
                                <div className="text-xs text-slate-500">
                                    <Link className="hover:underline" href={`/clients/${c.id}/medical`}>
                                        View medication orders
                                    </Link>
                                    <span className="px-2">•</span>
                                    <Link className="hover:underline" href={`/clients/${c.id}/mar`}>
                                        Open MAR
                                    </Link>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                    {!clients.length && (
                        <div className="text-sm text-slate-500">No clients available.</div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
