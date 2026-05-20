import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, router, useForm } from '@inertiajs/react';
import { Folder } from 'lucide-react';

type Props = {
    allocations: any;
    filters: {
        resource_type?: string | null;
    };
    assets: Array<{ id: number; name: string; asset_tag?: string | null; category?: string | null; status?: string | null }>;
};

export default function RespiteResourcesIndex({ allocations, filters, assets }: Props) {
    const ANY = '__any__';
    const onFilter = (next: Partial<Props['filters']>) => {
        router.get('/respite/resources', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    const form = useForm({
        booking_id: '',
        resource_type: 'asset',
        resource_id: '',
        start_at: '',
        end_at: '',
    });

    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Resources', href: '/respite/resources' },
        ]}>
            <Head title="Respite Resources" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Folder}
                        title="Resource Allocations"
                        description="Track bed/room/equipment allocations."
                        stats={[
                            { label: 'Allocations', value: allocations.data.length },
                            { label: 'Assets', value: assets.length },
                        ]}
                    />
                }
            >
                <RespiteSubnav />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/respite/resources', {
                            onSuccess: () => form.reset('booking_id', 'resource_id', 'start_at', 'end_at'),
                        });
                    }}
                >
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Add Allocation</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-5">
                            <div className="sm:col-span-2">
                                <Label className="text-xs text-muted-foreground">Asset *</Label>
                                <Select
                                    value={form.data.resource_id}
                                    onValueChange={(v) => form.setData('resource_id', v)}
                                >
                                    <SelectTrigger><SelectValue placeholder="Select asset" /></SelectTrigger>
                                    <SelectContent>
                                        {assets.map((asset) => (
                                            <SelectItem key={asset.id} value={String(asset.id)}>
                                                {asset.name}
                                                {asset.asset_tag ? ` (${asset.asset_tag})` : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="sm:col-span-1">
                                <Label className="text-xs text-muted-foreground">Booking ID</Label>
                                <Input
                                    value={form.data.booking_id}
                                    onChange={(e) => form.setData('booking_id', e.target.value)}
                                    placeholder="Optional"
                                />
                            </div>
                            <div className="sm:col-span-1">
                                <Label className="text-xs text-muted-foreground">Start *</Label>
                                <Input
                                    type="datetime-local"
                                    value={form.data.start_at}
                                    onChange={(e) => form.setData('start_at', e.target.value)}
                                />
                            </div>
                            <div className="sm:col-span-1">
                                <Label className="text-xs text-muted-foreground">End *</Label>
                                <Input
                                    type="datetime-local"
                                    value={form.data.end_at}
                                    onChange={(e) => form.setData('end_at', e.target.value)}
                                />
                            </div>
                            <div className="sm:col-span-5 flex justify-end">
                                <Button type="submit" size="sm" disabled={form.processing}>
                                    {form.processing ? 'Saving...' : 'Add Allocation'}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </form>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <Label className="text-xs text-muted-foreground">Resource Type</Label>
                            <Select value="asset" onValueChange={() => {}}>
                                <SelectTrigger><SelectValue placeholder="Asset" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="asset">Asset</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {allocations.data.map((a: any) => (
                        <Card key={a.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    {a.asset ? `${a.asset.name}${a.asset.asset_tag ? ` (${a.asset.asset_tag})` : ''}` : `Asset #${a.resource_id}`}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="text-sm text-muted-foreground space-y-1">
                                <div>{formatDateTime(a.start_at)} → {formatDateTime(a.end_at)}</div>
                                <div>Status: {a.status}</div>
                            </CardContent>
                        </Card>
                    ))}
                    {!allocations.data.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No resource allocations found.
                        </div>
                    )}
                </div>

                {allocations?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {allocations.links.map((l: any) => (
                            <Button
                                key={l.label}
                                variant="outline"
                                size="sm"
                                disabled={!l.url}
                                onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </PageLayout>
        </AppLayout>
    );
}
