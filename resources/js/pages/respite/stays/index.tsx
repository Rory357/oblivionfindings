import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link } from '@inertiajs/react';
import { Home } from 'lucide-react';

type Props = {
    stays: any;
};

export default function RespiteStaysIndex({ stays }: Props) {
    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Stays', href: '/respite/stays' },
        ]}>
            <Head title="Respite Stays" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Home}
                        title="Stays"
                        description="Active and past respite stays."
                        stats={[
                            { label: 'Total', value: stays.data.length },
                            { label: 'Active', value: stays.data.filter((s: any) => s.status === 'active' || s.status === 'in_progress').length },
                            { label: 'Completed', value: stays.data.filter((s: any) => s.status === 'completed').length },
                        ]}
                    />
                }
            >
                <RespiteSubnav />

                <div className="space-y-2">
                    {stays.data.map((s: any) => (
                        <Card key={s.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">
                                                {s.client?.first_name} {s.client?.last_name}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge variant="outline">{s.status}</Badge>
                                            </div>
                                            <div className="mt-2 text-xs text-muted-foreground">
                                                {s.actual_start && <>Started: {formatDateTimeLong(s.actual_start)}</>}
                                                {s.actual_end && <> — Ended: {formatDateTimeLong(s.actual_end)}</>}
                                            </div>
                                        </div>
                                        <Link href={`/respite/stays/${s.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!stays.data.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No stays found.
                        </div>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
