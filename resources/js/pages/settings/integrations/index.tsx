import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Camera, MapPin, Plug, Radio, Wifi } from 'lucide-react';
import { type ComponentType } from 'react';

type IntegrationProvider = {
    provider: string;
    display_name: string;
    status: 'active' | 'inactive' | 'error';
    last_tested_at?: string;
    has_key: boolean;
};

type Props = {
    integrations: IntegrationProvider[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Integrations', href: '/settings/integrations' },
];

const providerIcons: Record<string, ComponentType<{ className?: string }>> = {
    unifi: Wifi,
    iot: Radio,
    hikvision: Camera,
    queclink: MapPin,
    generic_webhook: Plug,
};

const statusConfig: Record<string, { label: string; className: string }> = {
    active: {
        label: 'Active',
        className: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
    },
    inactive: {
        label: 'Inactive',
        className: 'bg-gray-100 text-gray-800 dark:bg-gray-800/50 dark:text-gray-400',
    },
    error: {
        label: 'Error',
        className: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
    },
};

export default function IntegrationsIndex({ integrations }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Integrations" />

            <div className="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold tracking-tight">Integrations</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Connect third-party systems to your platform
                    </p>
                </div>

                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    {integrations.map((integration) => {
                        const Icon = providerIcons[integration.provider] ?? Plug;
                        const status = statusConfig[integration.status] ?? statusConfig.inactive;
                        const isUnifi = integration.provider === 'unifi';

                        return (
                            <Card key={integration.provider}>
                                <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-2">
                                    <div className="flex items-center gap-3">
                                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                                            <Icon className="h-5 w-5 text-muted-foreground" />
                                        </div>
                                        <CardTitle className="text-base">
                                            {integration.display_name}
                                        </CardTitle>
                                    </div>
                                    <Badge className={status.className}>{status.label}</Badge>
                                </CardHeader>
                                <CardContent>
                                    <div className="mb-4 min-h-[2rem]">
                                        {integration.has_key && integration.last_tested_at ? (
                                            <p className="text-sm text-muted-foreground">
                                                Last tested: {integration.last_tested_at}
                                            </p>
                                        ) : !integration.has_key ? (
                                            <p className="text-sm text-muted-foreground">
                                                Not configured
                                            </p>
                                        ) : null}
                                    </div>
                                    <div>
                                        {isUnifi ? (
                                            <Button size="sm" asChild>
                                                <Link href={`/settings/integrations/${integration.provider}`}>
                                                    Configure
                                                </Link>
                                            </Button>
                                        ) : (
                                            <Badge variant="secondary">Coming Soon</Badge>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}

                    {integrations.length === 0 && (
                        <div className="col-span-full rounded-md border p-8 text-center text-sm text-muted-foreground">
                            No integration providers available.
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
