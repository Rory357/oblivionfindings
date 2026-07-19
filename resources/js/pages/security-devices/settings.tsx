import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, FileClock, GitBranch, Settings2 } from 'lucide-react';

interface SettingsArea {
    title: string;
    description: string;
    href: string;
}

export default function SettingsAudit({
    summary,
    areas,
}: {
    summary: { device_groups: number; audit_entries: number };
    areas: SettingsArea[];
}) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                {
                    title: 'Settings & audit',
                    href: '/security-devices/settings',
                },
            ]}
        >
            <Head title="Settings & audit - Security & Devices" />
            <PageShell>
                <PageHero
                    variant="compact"
                    icon={Settings2}
                    title="Settings & audit"
                    description="Device organisation, integration setup, reports, and the current audit footprint in one understandable place."
                    stats={[
                        {
                            label: 'Device groups',
                            value: summary.device_groups,
                        },
                        {
                            label: 'Organisation audit entries',
                            value: summary.audit_entries,
                        },
                    ]}
                />

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {areas.map((area) => (
                        <Link
                            key={area.href}
                            href={area.href}
                            className="frontline-focus group rounded-2xl"
                        >
                            <Card className="h-full transition-colors group-hover:border-primary/40 group-hover:bg-muted/30">
                                <CardHeader>
                                    <CardTitle className="flex items-center justify-between gap-3 text-base">
                                        <span className="flex items-center gap-2">
                                            {area.title === 'Device groups' ? (
                                                <GitBranch
                                                    className="h-4 w-4 text-primary"
                                                    aria-hidden="true"
                                                />
                                            ) : (
                                                <FileClock
                                                    className="h-4 w-4 text-primary"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {area.title}
                                        </span>
                                        <ArrowRight
                                            className="h-4 w-4 text-muted-foreground transition-transform group-hover:translate-x-1"
                                            aria-hidden="true"
                                        />
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="text-sm text-muted-foreground">
                                    {area.description}
                                </CardContent>
                            </Card>
                        </Link>
                    ))}
                </div>
            </PageShell>
        </AppLayout>
    );
}
