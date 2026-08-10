import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    Info,
    MapPin,
    MapPinned,
    Plus,
    Search,
} from 'lucide-react';

type SiteOption = { id: number; name: string };

type Geofence = {
    id: number;
    name: string;
    type: string;
    scope: string | null;
    radius_meters: number;
    latitude: number;
    longitude: number;
    is_active: boolean;
    site: SiteOption | null;
    canonical_href: string | null;
};

type Props = {
    geofences: {
        data: Geofence[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        total: number;
    };
    sites: SiteOption[];
    filters: { q?: string; site_id?: number | string };
    canManage: boolean;
    migrationNotice: string;
};

export default function GeofencesIndex({
    geofences = {
        data: [],
        links: [],
        current_page: 1,
        last_page: 1,
        total: 0,
    },
    sites = [],
    filters = {},
    canManage = false,
    migrationNotice,
}: Props) {
    const updateFilters = (key: string, value: string | null) => {
        router.get(
            '/operations/geofences',
            { ...filters, [key]: value },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AppLayout>
            <Head title="Site Geofences" />
            <PageHero
                icon={MapPinned}
                title="Site Geofences"
                description="View the canonical Site geofences used by EVV and location monitoring. Changes are made from the Site Profile."
                stats={[
                    { label: 'Accessible geofences', value: geofences.total },
                    {
                        label: 'Active on this page',
                        value: geofences.data.filter(
                            (geofence) => geofence.is_active,
                        ).length,
                    },
                ]}
            />
            <PageShell>
                <div className="mb-4 flex gap-3 rounded-lg border border-border bg-muted/50 p-4 text-sm text-foreground">
                    <Info className="mt-0.5 h-4 w-4 shrink-0" />
                    <p>{migrationNotice}</p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-64 flex-1">
                        <Search className="absolute top-2.5 left-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search canonical geofences..."
                            className="h-9 pl-8 text-sm"
                            defaultValue={filters.q ?? ''}
                            onChange={(event) =>
                                updateFilters('q', event.target.value || null)
                            }
                        />
                    </div>
                    <select
                        aria-label="Filter by Site"
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                        value={String(filters.site_id ?? '')}
                        onChange={(event) =>
                            updateFilters('site_id', event.target.value || null)
                        }
                    >
                        <option value="">All accessible Sites</option>
                        {sites.map((site) => (
                            <option key={site.id} value={site.id}>
                                {site.name}
                            </option>
                        ))}
                    </select>
                    {canManage && (
                        <Button asChild size="sm">
                            <Link href="/operations/geofences/create">
                                <Plus className="mr-1.5 h-3.5 w-3.5" />
                                Manage a Site geofence
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="mt-4 space-y-2">
                    {geofences.data.length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16 text-center">
                                <MapPin className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold">
                                    No canonical Site geofences found
                                </h2>
                                <p className="mt-1 max-w-xl text-sm text-muted-foreground">
                                    Choose an accessible Site and use Map &amp;
                                    Site Geofence on its profile. This
                                    compatibility area never creates a second
                                    copy.
                                </p>
                            </CardContent>
                        </Card>
                    )}
                    {geofences.data.map((geofence) => (
                        <Card key={geofence.id}>
                            <CardContent className="flex items-center gap-4 p-4">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary dark:bg-primary/40 dark:text-primary/70">
                                    <MapPin className="h-5 w-5" />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-sm font-semibold">
                                            {geofence.name}
                                        </span>
                                        <Badge
                                            variant={
                                                geofence.is_active
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {geofence.is_active
                                                ? 'Active'
                                                : 'Inactive'}
                                        </Badge>
                                        <Badge variant="outline">
                                            {geofence.scope ?? 'Site'}
                                        </Badge>
                                        {geofence.type === 'circle' && (
                                            <Badge variant="outline">
                                                {geofence.radius_meters}m radius
                                            </Badge>
                                        )}
                                    </div>
                                    <div className="mt-1 flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                                        <span>
                                            {geofence.site?.name ??
                                                'No Site assigned'}
                                        </span>
                                        {geofence.type === 'circle' && (
                                            <span className="tabular-nums">
                                                {geofence.latitude.toFixed(4)},{' '}
                                                {geofence.longitude.toFixed(4)}
                                            </span>
                                        )}
                                    </div>
                                </div>
                                {geofence.canonical_href && (
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={geofence.canonical_href}>
                                            Open Site Profile
                                            <ArrowUpRight className="ml-1.5 h-3.5 w-3.5" />
                                        </Link>
                                    </Button>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {geofences.last_page > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {geofences.links.map((link, index) => (
                            <Button
                                key={`${link.label}-${index}`}
                                size="sm"
                                variant={link.active ? 'default' : 'outline'}
                                className="h-7 min-w-7 px-2 text-xs"
                                disabled={!link.url}
                                onClick={() =>
                                    link.url &&
                                    router.get(
                                        link.url,
                                        {},
                                        { preserveState: true },
                                    )
                                }
                            >
                                <span
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            </Button>
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
