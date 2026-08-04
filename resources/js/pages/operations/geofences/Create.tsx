import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, MapPin } from 'lucide-react';

type SiteOption = { id: number; name: string };

type Props = {
    sites: SiteOption[];
    selectedSiteId: number | null;
};

export default function GeofenceCreate({ sites = [], selectedSiteId }: Props) {
    const orderedSites = [...sites].sort((left, right) => {
        if (left.id === selectedSiteId) return -1;
        if (right.id === selectedSiteId) return 1;

        return left.name.localeCompare(right.name);
    });

    return (
        <AppLayout>
            <Head title="Manage a Site Geofence" />
            <PageHero
                variant="compact"
                icon={MapPin}
                title="Manage a Site Geofence"
                description="Choose the Site that owns the geofence. The Site Profile is the single place to draw, edit, activate, or remove it."
                backHref="/operations/geofences"
            />
            <PageShell>
                <div className="mb-4 rounded-lg border border-border bg-muted/30 p-4 text-sm text-muted-foreground">
                    Operations geofences no longer create a separate zone. Open
                    the Site Profile, then choose{' '}
                    <strong className="text-foreground">
                        Map &amp; Site Geofence
                    </strong>
                    .
                </div>

                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {orderedSites.map((site) => (
                        <Card
                            key={site.id}
                            className={
                                site.id === selectedSiteId
                                    ? 'border-primary/60 shadow-sm'
                                    : ''
                            }
                        >
                            <CardContent className="flex items-center gap-3 p-4">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <MapPin className="h-4 w-4" />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-semibold">
                                        {site.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Canonical Site-owned geofence
                                    </p>
                                </div>
                                <Button asChild size="sm" variant="outline">
                                    <Link href={`/sites/${site.id}`}>
                                        Open
                                        <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
                                    </Link>
                                </Button>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {orderedSites.length === 0 && (
                    <Card>
                        <CardContent className="py-12 text-center">
                            <p className="font-medium">No accessible Sites</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Ask an administrator to assign the correct Site
                                before managing a geofence.
                            </p>
                        </CardContent>
                    </Card>
                )}
            </PageShell>
        </AppLayout>
    );
}
