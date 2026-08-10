import { PageHero, PageLayout } from '@/components/page';
import RespiteSubnav from '@/components/respite-subnav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import { Plus, ShieldAlert } from 'lucide-react';

type Props = {
    activations: { data: any[]; links: any[] };
    filters: any;
    planTypes: Record<string, string>;
    statuses: Record<string, string>;
};

const statusColors: Record<string, string> = {
    pending_review: 'bg-status-warning-bg text-status-warning',
    active: 'bg-status-success-bg text-status-success',
    modified: 'bg-status-info-bg text-status-info',
    suspended: 'bg-muted text-muted-foreground',
    completed: 'bg-muted text-foreground',
};

const typeColors: Record<string, string> = {
    behaviour: 'bg-primary/10 text-primary',
    safety: 'bg-status-critical-bg text-status-critical',
    medical: 'bg-status-info-bg text-status-info',
    mobility: 'bg-status-warning-bg text-status-warning',
    communication: 'bg-status-info-bg text-status-info',
};

export default function RiskPlanActivationsIndex({
    activations,
    filters,
    planTypes,
    statuses,
}: Props) {
    const ANY = '__any__';

    const onFilter = (next: Record<string, any>) => {
        router.get(
            '/respite/risk-plan-activations',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Respite', href: '/respite' },
                {
                    title: 'Risk Plan Activations',
                    href: '/respite/risk-plan-activations',
                },
            ]}
        >
            <Head title="Risk Plan Activations" />

            <PageLayout
                hero={
                    <PageHero
                        icon={ShieldAlert}
                        title="Risk Plan Activations"
                        description="Activated risk plans for respite stays."
                        stats={[
                            { label: 'Total', value: activations.data.length },
                            {
                                label: 'Active',
                                value: activations.data.filter(
                                    (a: any) => a.status === 'active',
                                ).length,
                            },
                            {
                                label: 'Pending review',
                                value: activations.data.filter(
                                    (a: any) => a.status === 'pending_review',
                                ).length,
                            },
                        ]}
                        actions={
                            <Link href="/respite/risk-plan-activations/create">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New Activation
                                </Button>
                            </Link>
                        }
                    />
                }
            >
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Plan Type
                            </Label>
                            <Select
                                value={filters.plan_type ?? ANY}
                                onValueChange={(v) =>
                                    onFilter({
                                        plan_type: v === ANY ? null : v,
                                    })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {Object.entries(planTypes).map(
                                        ([value, label]) => (
                                            <SelectItem
                                                key={value}
                                                value={value}
                                            >
                                                {label}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Status
                            </Label>
                            <Select
                                value={filters.status ?? ANY}
                                onValueChange={(v) =>
                                    onFilter({ status: v === ANY ? null : v })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {Object.entries(statuses).map(
                                        ([value, label]) => (
                                            <SelectItem
                                                key={value}
                                                value={value}
                                            >
                                                {label}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {activations.data.map((a: any) => (
                        <Card key={a.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">
                                                {a.plan_name}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge
                                                    className={
                                                        typeColors[
                                                            a.plan_type
                                                        ] ||
                                                        'bg-muted text-foreground'
                                                    }
                                                >
                                                    {a.plan_type?.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </Badge>
                                                <Badge
                                                    className={
                                                        statusColors[
                                                            a.status
                                                        ] || ''
                                                    }
                                                >
                                                    {a.status?.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </Badge>
                                            </div>
                                            <div className="mt-2 text-xs text-muted-foreground">
                                                Client:{' '}
                                                {a.stay?.client?.first_name}{' '}
                                                {a.stay?.client?.last_name}
                                            </div>
                                            {a.stay && (
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    Stay #{a.stay.id}
                                                </div>
                                            )}
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                {formatDateTimeLong(
                                                    a.created_at,
                                                )}
                                            </div>
                                        </div>
                                        <Link
                                            href={`/respite/risk-plan-activations/${a.id}`}
                                            className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                        >
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!activations.data.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No risk plan activations found.
                        </div>
                    )}
                </div>

                {activations?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {activations.links.map((l: any) => (
                            <Button
                                key={l.label}
                                variant="outline"
                                size="sm"
                                disabled={!l.url}
                                onClick={() =>
                                    l.url &&
                                    router.get(
                                        l.url,
                                        {},
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                        },
                                    )
                                }
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </PageLayout>
        </AppLayout>
    );
}
