import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
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
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Archive, Clock, Database, Plus } from 'lucide-react';

type Props = {
    filters: {
        q: string;
        active: string | null;
    };
    policies: any;
    stats?: {
        total: number;
        active: number;
    };
};

export default function DataRetentionPolicies({
    filters,
    policies,
    stats,
}: Props) {
    const ANY = '__any__';
    const { auth } = usePage().props as any;
    const can = auth?.can?.privacy ?? {};

    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/privacy/retention',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Privacy', href: '/privacy/dashboard' },
                {
                    title: 'Data Retention Policies',
                    href: '/privacy/retention',
                },
            ]}
        >
            <Head title="Data Retention Policies" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Archive}
                        title="Data Retention Policies"
                        description="Manage data retention periods and automated deletion rules"
                        stats={
                            stats
                                ? [
                                      { label: 'Total', value: stats.total },
                                      { label: 'Active', value: stats.active },
                                  ]
                                : undefined
                        }
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <Link href="/privacy/dashboard">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                    >
                                        Privacy Dashboard
                                    </Button>
                                </Link>
                                <Link href="/privacy/retention/review">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                    >
                                        Review Data
                                    </Button>
                                </Link>
                                {can.manageRetention && (
                                    <Link href="/privacy/retention/create">
                                        <Button size="sm">
                                            <Plus className="mr-1.5 h-4 w-4" />
                                            New Policy
                                        </Button>
                                    </Link>
                                )}
                            </div>
                        }
                    />
                }
            >
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Search
                            </Label>
                            <Input
                                placeholder="Search by name or model type"
                                value={filters.q || ''}
                                onChange={(e) =>
                                    onFilter({ q: e.target.value })
                                }
                            />
                        </div>

                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Status
                            </Label>
                            <Select
                                value={filters.active ?? ANY}
                                onValueChange={(v) =>
                                    onFilter({ active: v === ANY ? null : v })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    <SelectItem value="1">Active</SelectItem>
                                    <SelectItem value="0">Inactive</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {policies.data.map((policy: any) => (
                        <Card key={policy.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="flex items-center gap-2 font-semibold">
                                                <Database className="h-4 w-4 text-primary" />
                                                {policy.policy_name}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge
                                                    variant={
                                                        policy.active
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {policy.active
                                                        ? 'Active'
                                                        : 'Inactive'}
                                                </Badge>
                                                <Badge variant="outline">
                                                    {policy.model_type}
                                                </Badge>
                                                <Badge
                                                    variant="outline"
                                                    className="border-status-info/30 bg-status-info-bg text-status-info"
                                                >
                                                    <Clock className="mr-1 h-3 w-3" />
                                                    {
                                                        policy.retention_period_years
                                                    }{' '}
                                                    year
                                                    {policy.retention_period_years !==
                                                    1
                                                        ? 's'
                                                        : ''}{' '}
                                                    retention
                                                </Badge>
                                                {policy.legal_hold_exemption && (
                                                    <Badge
                                                        variant="outline"
                                                        className="border-status-warning/30 bg-status-warning-bg text-status-warning"
                                                    >
                                                        Legal Hold Exempt
                                                    </Badge>
                                                )}
                                            </div>
                                            {policy.description && (
                                                <div className="mt-2 text-sm text-muted-foreground">
                                                    {policy.description}
                                                </div>
                                            )}
                                            <div className="mt-2 text-xs text-muted-foreground">
                                                {policy.archive_after_years &&
                                                    `Archive after ${policy.archive_after_years} years • `}
                                                {policy.hard_delete_after_years &&
                                                    `Delete after ${policy.hard_delete_after_years} years`}
                                            </div>
                                        </div>
                                        <Link
                                            href={`/privacy/retention/${policy.id}/edit`}
                                            className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                        >
                                            Edit
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!policies.data.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No retention policies found.
                        </div>
                    )}
                </div>

                {policies?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {policies.links.map((l: any) => (
                            <Button
                                key={l.label}
                                type="button"
                                variant={l.active ? 'secondary' : 'outline'}
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
