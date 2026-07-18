import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import { CalendarClock, ExternalLink } from 'lucide-react';
import {
    SiteProfileEmptyState,
    SiteProfileLockedState,
} from './site-profile-states';

export type SiteShiftCoverageData = {
    locked: boolean;
    href?: string | null;
    summary: unknown;
};

export function SiteProfileShiftCoverage({
    data,
}: {
    data: SiteShiftCoverageData;
}) {
    if (data.locked) return <SiteProfileLockedState label="Shift coverage" />;

    const rows = Array.isArray(data.summary)
        ? (data.summary as Array<Record<string, unknown>>)
        : [];

    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold">Shift coverage</h2>
                    <p className="text-sm text-muted-foreground">
                        A Site-filtered summary; Rostering remains the owner of
                        shift changes.
                    </p>
                </div>
                {data.href ? (
                    <Button asChild className="min-h-11">
                        <Link href={data.href}>
                            Open Rostering
                            <ExternalLink className="ml-2 h-4 w-4" />
                        </Link>
                    </Button>
                ) : null}
            </div>

            {rows.length ? (
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {rows.map((row, index) => (
                        <Card key={String(row.id ?? index)}>
                            <CardContent className="p-4">
                                <p className="font-semibold">
                                    {String(
                                        row.name ??
                                            row.label ??
                                            row.date ??
                                            `Coverage ${index + 1}`,
                                    )}
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {String(
                                        row.status ??
                                            row.summary ??
                                            'Open Rostering for detail.',
                                    )}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            ) : (
                <SiteProfileEmptyState
                    icon={CalendarClock}
                    title="No coverage summary available"
                    description="Open Rostering to review shifts and resolve coverage gaps for this Site."
                    action={
                        data.href
                            ? { label: 'Open Rostering', href: data.href }
                            : undefined
                    }
                />
            )}
        </div>
    );
}
