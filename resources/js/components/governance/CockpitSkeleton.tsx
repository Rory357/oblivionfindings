import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * Loading skeleton matching the cockpit layout — KPI band, 3-zone grid,
 * panels and rails. Rendered while the dashboard JSON is being fetched.
 */
export function CockpitSkeleton() {
    return (
        <div
            className="space-y-6"
            aria-busy="true"
            aria-live="polite"
            data-dusk="cockpit-skeleton"
        >
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                {Array.from({ length: 4 }).map((_, idx) => (
                    <Card key={idx}>
                        <CardContent className="p-5">
                            <Skeleton className="h-3 w-24" />
                            <Skeleton className="mt-3 h-8 w-16" />
                            <Skeleton className="mt-3 h-3 w-32" />
                        </CardContent>
                    </Card>
                ))}
            </div>

            <div className="grid gap-6 lg:grid-cols-[2fr,1fr]">
                <Card>
                    <CardHeader>
                        <Skeleton className="h-5 w-40" />
                        <Skeleton className="mt-2 h-3 w-72" />
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {Array.from({ length: 5 }).map((_, idx) => (
                            <Skeleton
                                key={idx}
                                className="h-16 w-full rounded-lg"
                            />
                        ))}
                    </CardContent>
                </Card>
                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <Skeleton className="h-5 w-32" />
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {Array.from({ length: 4 }).map((_, idx) => (
                                <Skeleton
                                    key={idx}
                                    className="h-12 w-full rounded-lg"
                                />
                            ))}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <Skeleton className="h-5 w-40" />
                        </CardHeader>
                        <CardContent>
                            <Skeleton className="h-40 w-full rounded-lg" />
                        </CardContent>
                    </Card>
                </div>
            </div>

            <div className="grid gap-6 md:grid-cols-2">
                {Array.from({ length: 2 }).map((_, idx) => (
                    <Card key={idx}>
                        <CardHeader>
                            <Skeleton className="h-5 w-44" />
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <Skeleton className="h-20 w-full rounded-lg" />
                            <Skeleton className="h-20 w-full rounded-lg" />
                        </CardContent>
                    </Card>
                ))}
            </div>
        </div>
    );
}

export default CockpitSkeleton;
