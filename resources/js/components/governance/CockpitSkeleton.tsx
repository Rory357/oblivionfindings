import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * Loading skeleton matching Governance Home — Next meeting + My work, the
 * board priorities list and the assurance tiles. Rendered while the
 * dashboard JSON is being fetched, so the page doesn't jump on arrival.
 */
export function CockpitSkeleton() {
    return (
        <div
            className="flex flex-col gap-5"
            aria-busy="true"
            aria-live="polite"
            aria-label="Loading governance home"
            data-dusk="cockpit-skeleton"
        >
            <div className="grid gap-5 lg:grid-cols-[1.25fr_1fr]">
                {Array.from({ length: 2 }).map((_, idx) => (
                    <Card key={idx}>
                        <CardHeader>
                            <Skeleton className="h-5 w-40" />
                            <Skeleton className="mt-2 h-3 w-64" />
                        </CardHeader>
                        <CardContent className="flex flex-col gap-2">
                            {Array.from({ length: 4 }).map((__, row) => (
                                <Skeleton
                                    key={row}
                                    className="h-14 w-full rounded-lg"
                                />
                            ))}
                        </CardContent>
                    </Card>
                ))}
            </div>

            <Card>
                <CardHeader>
                    <Skeleton className="h-5 w-48" />
                    <Skeleton className="mt-2 h-3 w-72" />
                </CardHeader>
                <CardContent className="flex flex-col gap-3">
                    {Array.from({ length: 4 }).map((_, idx) => (
                        <Skeleton key={idx} className="h-16 w-full rounded-lg" />
                    ))}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <Skeleton className="h-5 w-36" />
                </CardHeader>
                <CardContent className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {Array.from({ length: 4 }).map((_, idx) => (
                        <Skeleton key={idx} className="h-24 w-full rounded-lg" />
                    ))}
                </CardContent>
            </Card>
        </div>
    );
}

export default CockpitSkeleton;
