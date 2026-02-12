import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from '@/components/ui/table';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ArrowLeft, Package, ShieldAlert, Clock } from 'lucide-react';
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
    active: 'bg-emerald-500/20 text-emerald-400',
    inactive: 'bg-slate-500/20 text-slate-400',
    maintenance: 'bg-yellow-500/20 text-yellow-400',
    retired: 'bg-red-500/20 text-red-400',
    disposed: 'bg-red-500/20 text-red-400',
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

            <div className="m-4 space-y-4">
                {/* Header */}
                <div>
                    <Button asChild variant="ghost" size="sm" className="mb-2">
                        <Link href="/sites/reports">
                            <ArrowLeft className="w-4 h-4 mr-1" />
                            Back
                        </Link>
                    </Button>
                    <h1 className="text-lg font-semibold flex items-center gap-2">
                        <Package className="w-5 h-5 text-blue-400" />
                        Asset Condition Report
                    </h1>
                    <p className="text-sm text-slate-400">
                        Assets grouped by condition with warranty expiry information
                    </p>
                </div>

                {/* Summary */}
                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">{summary.total_assets}</div>
                            <div className="text-sm text-slate-400">Total Assets</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-red-500/5 border-red-500/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-red-400 flex items-center gap-1">
                                <ShieldAlert className="w-5 h-5" />
                                {summary.warranty_expired}
                            </div>
                            <div className="text-sm text-slate-400">Warranty Expired</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-orange-500/5 border-orange-500/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-orange-400 flex items-center gap-1">
                                <Clock className="w-5 h-5" />
                                {summary.warranty_expiring_soon}
                            </div>
                            <div className="text-sm text-slate-400">Expiring Within 30 Days</div>
                        </CardContent>
                    </Card>
                </div>

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
                            <p className="text-sm text-slate-400">No assets found.</p>
                        </CardContent>
                    </Card>
                ) : (
                    conditionGroups.map((group) => (
                        <Card key={group.status}>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <Badge className={statusColors[group.status] || 'bg-slate-500/20 text-slate-400'}>
                                            {group.status}
                                        </Badge>
                                        <span className="text-slate-400 font-normal">
                                            ({group.count} assets)
                                        </span>
                                    </CardTitle>
                                    <div className="flex gap-2">
                                        {group.warranty_expired > 0 && (
                                            <Badge variant="outline" className="text-red-400">
                                                {group.warranty_expired} expired warranty
                                            </Badge>
                                        )}
                                        {group.warranty_expiring_soon > 0 && (
                                            <Badge variant="outline" className="text-orange-400">
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
                                                                        ? 'text-red-400'
                                                                        : isExpiringSoon
                                                                        ? 'text-orange-400'
                                                                        : 'text-emerald-400'
                                                                }
                                                            >
                                                                {new Date(asset.warranty_expires_at).toLocaleDateString()}
                                                            </Badge>
                                                        ) : (
                                                            <span className="text-slate-500">N/A</span>
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
            </div>
        </AppLayout>
    );
}
