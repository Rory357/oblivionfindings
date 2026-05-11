import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, Shield } from 'lucide-react';
import {
    securityDevicesSectionMap,
    securityDevicesSections,
    type SecurityDevicesSectionKey,
} from './config';

const assignmentScopes = [
    'organisation',
    'site / house',
    'staff',
    'clients',
] as const;

const moduleRules = [
    'Keep the architecture vendor-neutral.',
    'Design for organisation, site / house, staff, and client-level visibility.',
    'Use this phase to establish structure only, with no API, CRUD, or data migration work.',
] as const;

interface Props {
    sectionKey: SecurityDevicesSectionKey;
}

export default function SecurityDevicesShell({ sectionKey }: Props) {
    const section = securityDevicesSectionMap[sectionKey];

    const breadcrumbs =
        section.key === 'dashboard'
            ? [{ title: 'Security & Devices', href: section.href }]
            : [
                  { title: 'Security & Devices', href: '/security-devices' },
                  { title: section.title, href: section.href },
              ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${section.title} - Security & Devices`} />

            <PageShell>
                <PageHeader
                    title={
                        <span className="flex items-center gap-3">
                            <span className="rounded-xl border bg-primary/5 p-2 text-primary">
                                <section.icon className="h-5 w-5" />
                            </span>
                            <span>{section.title}</span>
                        </span>
                    }
                    description={section.description}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge variant="secondary">Skeleton</Badge>
                            <Badge variant="outline">Vendor-neutral</Badge>
                        </div>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Planned capabilities</CardTitle>
                            <CardDescription>
                                {section.futureFocus}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="rounded-xl border border-dashed bg-muted/40 p-4 text-sm text-muted-foreground">
                                This page is intentionally a placeholder. It
                                defines the future shape of the module without
                                moving existing workflows or introducing new
                                APIs yet.
                            </div>

                            <div className="space-y-3">
                                {section.capabilities.map((capability) => (
                                    <div
                                        key={capability}
                                        className="flex items-start gap-3 rounded-xl border p-4"
                                    >
                                        <CheckCircle2 className="mt-0.5 h-4 w-4 text-primary" />
                                        <p className="text-sm leading-6">
                                            {capability}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>

                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Module foundations</CardTitle>
                                <CardDescription>
                                    Core rules for the future Security & Devices
                                    platform.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="flex items-start gap-3 rounded-xl border bg-primary/5 p-4">
                                    <Shield className="mt-0.5 h-4 w-4 text-primary" />
                                    <p className="text-sm leading-6">
                                        Hardware management, integrations, and
                                        API-driven device workflows will be
                                        managed here in a vendor-neutral way.
                                    </p>
                                </div>

                                <div className="space-y-2">
                                    <p className="text-sm font-medium">
                                        Assignment scope
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        {assignmentScopes.map((scope) => (
                                            <Badge
                                                key={scope}
                                                variant="outline"
                                            >
                                                {scope}
                                            </Badge>
                                        ))}
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    {moduleRules.map((rule) => (
                                        <p
                                            key={rule}
                                            className="text-sm text-muted-foreground"
                                        >
                                            {rule}
                                        </p>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>

                        <Card
                            className={
                                section.key === 'access-control'
                                    ? 'border-status-warning/30 bg-status-warning-bg dark:border-status-warning/30'
                                    : undefined
                            }
                        >
                            <CardHeader>
                                <CardTitle>Implementation note</CardTitle>
                                <CardDescription>
                                    Scope guardrails for this skeleton phase.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm leading-6 text-muted-foreground">
                                <p>
                                    No major changes are being made behind these
                                    pages yet. Existing Control Room, Fleet &
                                    Assets, and Site Hardware flows remain where
                                    they are.
                                </p>
                                {section.key === 'access-control' ? (
                                    <p>
                                        Access Control here means future
                                        physical access hardware and entry-event
                                        management only. It does not replace
                                        software roles, permissions, or system
                                        access screens.
                                    </p>
                                ) : null}
                            </CardContent>
                        </Card>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Module map</CardTitle>
                        <CardDescription>
                            All placeholder sections for the future hardware and
                            API management surface.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {securityDevicesSections.map((item) => {
                            const isActive = item.key === section.key;

                            return (
                                <Link
                                    key={item.key}
                                    href={item.href}
                                    className={`group rounded-xl border p-4 transition-colors ${
                                        isActive
                                            ? 'border-primary bg-primary/5'
                                            : 'hover:border-primary/40 hover:bg-muted/30'
                                    }`}
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="space-y-2">
                                            <div className="flex items-center gap-2">
                                                <item.icon className="h-4 w-4 text-primary" />
                                                <span className="font-medium">
                                                    {item.title}
                                                </span>
                                            </div>
                                            <p className="text-sm leading-6 text-muted-foreground">
                                                {item.description}
                                            </p>
                                        </div>
                                        <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                                    </div>
                                    {isActive ? (
                                        <Badge
                                            className="mt-4"
                                            variant="secondary"
                                        >
                                            Current section
                                        </Badge>
                                    ) : null}
                                </Link>
                            );
                        })}
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
