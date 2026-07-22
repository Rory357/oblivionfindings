import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { registerLabel } from './safety-register';

type ServiceContextRow = {
    id: number;
    name: string;
    type?: string | null;
    description?: string | null;
    status: 'active' | 'inactive';
};

export type SiteServicesData = {
    locked?: boolean;
    items: ServiceContextRow[];
    can_manage: boolean;
    href?: string | null;
};

export function SiteProfileServices({ data }: { data: SiteServicesData }) {
    return (
        <div className="space-y-5">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-lg font-semibold">Service contexts</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Complete active and inactive service contexts linked to
                        this Site, used by clients, coverage and shifts.
                    </p>
                </div>
                {data.href ? (
                    <Button asChild size="sm" variant="outline">
                        <Link href={data.href}>
                            Manage service contexts
                            <ArrowUpRight className="ml-1.5 h-4 w-4" />
                        </Link>
                    </Button>
                ) : null}
            </div>
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">
                        Site services ({data.items.length})
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="divide-y rounded-xl border">
                        {data.items.map((service) => (
                            <div
                                key={service.id}
                                className="flex items-start justify-between gap-3 p-4"
                            >
                                <div>
                                    <div className="font-medium">
                                        {service.name}
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        {registerLabel(service.type)}
                                    </div>
                                    {service.description ? (
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            {service.description}
                                        </p>
                                    ) : null}
                                </div>
                                <Badge
                                    variant={
                                        service.status === 'active'
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {registerLabel(service.status)}
                                </Badge>
                            </div>
                        ))}
                        {!data.items.length ? (
                            <p className="p-8 text-center text-sm text-muted-foreground">
                                No service contexts are linked to this Site.
                            </p>
                        ) : null}
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
