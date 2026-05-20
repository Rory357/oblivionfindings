import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from '@/components/ui/table';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { BarChart3 } from 'lucide-react';
import { useState } from 'react';

type AssetItem = {
    id: number;
    name: string;
    asset_tag?: string;
    category?: string;
    manufacturer?: string;
    model?: string;
    status: string;
    warranty_expires_at?: string;
    site_name?: string;
};

type ConditionGroup = {
    status: string;
    count: number;
    warranty_expired: number;
    warranty_expiring_soon: number;
    assets: AssetItem[];
};

type Props = {
    conditionGroups: ConditionGroup[];
    sites: Array<{ id: number; name: string }>;
    filters: { site_id?: string };
    summary: {
        total_assets: number;
        warranty_expired: number;
        warranty_expiring_soon: number;
    };
};

const statusColors: Record<string, string> = {
    active: 'bg-status-success-bg text-status-success',
    inactive: 'bg-muted-foreground/80/20 text-muted-foreground',
    maintenance: 'bg-status-warning-bg text-status-warning',
    retired: 'bg-status-critical-bg text-status-critical',
    disposed: 'bg-status-critical-bg text-status-critical',
};

export default function AssetConditionReport({ conditionGroups, sites, filters, summary }: Props) {
    const [siteId, setSiteId] = useState(filters.site_id || '');

    const applyFilters = () => {
        const params: Record<string, string> = {};
        if (siteId) params.site_id = siteId;
        router.get('/sites/reports/asset-condition', params, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Reports', href: '/sites/reports' },
            { title: 'Asset Condition', href: '/sites/reports/asset-condition' },
        ]}>
            <Head title="Asset Condition Report" />

            <PageLayout
                hero={
                    <PageHero
                        icon={BarChart3}
                        title="Asset Condition Report"
                        description="Assets grouped by condition with warranty expiry information"
                        stats={[
                            { label: 'Total assets', value: summary.total_assets },
                            { label: 'Warranty expired', value: summary.warranty_expired },
                            { label: 'Expiring 30d', value: summary.warranty_expiring_soon },
                        ]}
                    />
                }
            >
                {/* Filter */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-sm">Filters</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex gap-4 flex-wrap">
                            <div className="w-48">
                                <Select value={siteId || undefined} onValueChange={setSiteId}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="All Sites" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {sites.map(site => (
                                            <SelectItem key={site.id} value={String(site.id)}>
                                                {site.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <Button variant="outline" onClick={applyFilters}>Apply</Button>
                            <Button
                                variant="ghost"
                                onClick={() => {
                                    setSiteId('');
                                    router.get('/sites/reports/asset-condition', {}, { preserveState: true });
                                }}
                            >
                                Clear
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Condition Groups */}
                {conditionGroups.length === 0 ? (
                    <Card>
                        <CardContent className="p-8 text-center">
                            <p className="text-sm text-muted-foreground">No assets found.</p>
                        </CardContent>
                    </Card>
                ) : (
                    conditionGroups.map((group) => (
                        <Card key={group.status}>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <Badge className={statusColors[group.status] || 'bg-muted-foreground/80/20 text-muted-foreground'}>
                                            {group.status}
                                        </Badge>
                                        <span className="text-muted-foreground font-normal">
                                            ({group.count} assets)
                                        </span>
                                    </CardTitle>
                                    <div className="flex gap-2">
                                        {group.warranty_expired > 0 && (
                                            <Badge variant="outline" className="text-status-critical">
                                                {group.warranty_expired} expired warranty
                                            </Badge>
                                        )}
                                        {group.warranty_expiring_soon > 0 && (
                                            <Badge variant="outline" className="text-status-warning">
                                                {group.warranty_expiring_soon} expiring soon
                                            </Badge>
                                        )}
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Asset Tag</TableHead>
                                            <TableHead>Name</TableHead>
                                            <TableHead>Category</TableHead>
                                            <TableHead>Manufacturer</TableHead>
                                            <TableHead>Model</TableHead>
                                            <TableHead>Site</TableHead>
                                            <TableHead>Warranty Expires</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {group.assets.map((asset) => {
                                            const isExpired = asset.warranty_expires_at && new Date(asset.warranty_expires_at) < new Date();
                                            const isExpiringSoon = asset.warranty_expires_at && !isExpired && new Date(asset.warranty_expires_at).getTime() - new Date().getTime() <= 30 * 24 * 60 * 60 * 1000;

                                            return (
                                                <TableRow key={asset.id}>
                                                    <TableCell className="font-mono text-sm">
                                                        {asset.asset_tag || '-'}
                                                    </TableCell>
                                                    <TableCell className="font-medium">{asset.name}</TableCell>
                                                    <TableCell>{asset.category || '-'}</TableCell>
                                                    <TableCell>{asset.manufacturer || '-'}</TableCell>
                                                    <TableCell>{asset.model || '-'}</TableCell>
                                                    <TableCell>{asset.site_name || '-'}</TableCell>
                                                    <TableCell>
                                                        {asset.warranty_expires_at ? (
                                                            <Badge
                                                                variant="outline"
                                                                className={
                                                                    isExpired
                                                                        ? 'text-status-critical'
                                                                        : isExpiringSoon
                                                                        ? 'text-status-warning'
                                                                        : 'text-status-success'
                                                                }
                                                            >
                                                                {new Date(asset.warranty_expires_at).toLocaleDateString()}
                                                            </Badge>
                                                        ) : (
                                                            <span className="text-muted-foreground">N/A</span>
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    ))
                )}
            </PageLayout>
        </AppLayout>
    );
}
