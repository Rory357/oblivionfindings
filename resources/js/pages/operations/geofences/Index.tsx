import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Eye, MapPin, Pencil, Plus, Search } from 'lucide-react';

type Geofence = {
    id: number;
    name: string;
    radius_meters: number;
    latitude: number;
    longitude: number;
    is_active: boolean;
    client: { id: number; first_name: string; last_name: string } | null;
    site_name: string | null;
};

type Props = {
    geofences: {
        data: Geofence[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        q?: string;
    };
};

export default function GeofencesIndex({ geofences = { data: [], links: [], current_page: 1, last_page: 1, total: 0 }, filters = {} as any }: Props) {
    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/geofences', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title="Geofences" />
            <PageHeader
                title="Geofences"
                description="Manage geofence zones for electronic visit verification."
                backHref="/operations"
            />
            <PageShell>
                {/* Filters */}
                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search geofences..."
                            className="h-9 pl-8 text-sm"
                            defaultValue={filters?.q ?? ''}
                            onChange={(e) => updateFilters('q', e.target.value || null)}
                        />
                    </div>
                    <Button asChild size="sm">
                        <Link href="/operations/geofences/create">
                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                            New Geofence
                        </Link>
                    </Button>
                </div>

                {/* List */}
                <div className="mt-4 space-y-2">
                    {(geofences?.data ?? []).length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <MapPin className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">No Geofences</h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">Create your first geofence zone to enable location-based verification.</p>
                                <Button asChild size="sm" className="mt-4">
                                    <Link href="/operations/geofences/create">Create Geofence</Link>
                                </Button>
                            </CardContent>
                        </Card>
                    )}
                    {(geofences?.data ?? []).map((fence) => (
                        <Card key={fence.id} className="transition-all hover:border-border hover:shadow-sm">
                            <CardContent className="flex items-center gap-4 p-4">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary dark:bg-primary/40 dark:text-primary/70">
                                    <MapPin className="h-5 w-5" />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <Link href={`/operations/geofences/${fence.id}`} className="text-sm font-semibold hover:underline">
                                            {fence.name}
                                        </Link>
                                        <Badge variant={fence.is_active ? 'default' : 'secondary'} className="h-4 px-1.5 text-[9px]">
                                            {fence.is_active ? 'Active' : 'Inactive'}
                                        </Badge>
                                        <Badge variant="outline" className="h-4 px-1.5 text-[9px]">
                                            {fence.radius_meters}m radius
                                        </Badge>
                                    </div>
                                    <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                        {fence.client && (
                                            <span>{fence.client.first_name} {fence.client.last_name}</span>
                                        )}
                                        {fence.site_name && <span>Site: {fence.site_name}</span>}
                                        <span className="tabular-nums">
                                            {fence.latitude.toFixed(4)}, {fence.longitude.toFixed(4)}
                                        </span>
                                    </div>
                                </div>
                                <div className="flex shrink-0 gap-1">
                                    <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0">
                                        <Link href={`/operations/geofences/${fence.id}`}>
                                            <Eye className="h-3.5 w-3.5" />
                                        </Link>
                                    </Button>
                                    <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0">
                                        <Link href={`/operations/geofences/${fence.id}/edit`}>
                                            <Pencil className="h-3.5 w-3.5" />
                                        </Link>
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Pagination */}
                {(geofences?.last_page ?? 1) > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {(geofences?.links ?? []).map((link: any, i: number) => (
                            <Button
                                key={i}
                                size="sm"
                                variant={link.active ? 'default' : 'outline'}
                                className="h-7 min-w-[28px] px-2 text-xs"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
