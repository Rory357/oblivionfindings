import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import {
    CheckCircle2,
    CircleAlert,
    MapPin,
    Phone,
    ShieldAlert,
    Users,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';
import type {
    SiteProfileAttentionData,
    SiteProfileHeroData,
    SiteProfileOverviewData,
    SiteProfileSite,
} from '../show';
import { SiteAttentionPanel } from './attention-panel';

export function SiteProfileOverview({
    hero,
    overview,
    attention,
    onNavigate,
}: {
    site: SiteProfileSite;
    hero: SiteProfileHeroData;
    overview: SiteProfileOverviewData;
    attention: SiteProfileAttentionData;
    onNavigate: (tab: string) => void;
}) {
    return (
        <div className="space-y-6">
            {hero.readiness.missing_critical > 0 ? (
                <button
                    type="button"
                    onClick={() => onNavigate('readiness')}
                    className="flex min-h-11 w-full items-center gap-3 rounded-xl border border-status-warning/30 bg-status-warning-bg px-4 py-3 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <CircleAlert className="h-5 w-5 shrink-0 text-status-warning" />
                    <span className="min-w-0 flex-1">
                        <span className="block font-medium">
                            Complete {hero.readiness.missing_critical} critical
                            setup{' '}
                            {hero.readiness.missing_critical === 1
                                ? 'item'
                                : 'items'}
                        </span>
                        <span className="block text-sm text-muted-foreground">
                            This active Site has an incomplete operational
                            profile.
                        </span>
                    </span>
                    <span className="text-sm font-medium">Review</span>
                </button>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-3">
                <OverviewCard title="Location & access" icon={MapPin}>
                    <p className="text-sm font-medium">
                        {overview.location.address || 'Address not recorded'}
                    </p>
                    {overview.location.region ? (
                        <p className="mt-1 text-sm text-muted-foreground">
                            {overview.location.region}
                        </p>
                    ) : null}
                    {overview.location.access_instructions ? (
                        <p className="mt-3 border-t pt-3 text-sm text-muted-foreground">
                            {overview.location.access_instructions}
                        </p>
                    ) : null}
                </OverviewCard>
                <OverviewCard title="Key contacts" icon={Phone}>
                    {overview.contacts.length ? (
                        <div className="space-y-3">
                            {overview.contacts.slice(0, 4).map((contact) => (
                                <div key={contact.id}>
                                    <p className="text-sm font-medium">
                                        {contact.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {contact.role ||
                                            contact.type.replaceAll('_', ' ')}
                                        {contact.phone
                                            ? ` · ${contact.phone}`
                                            : ''}
                                    </p>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No key contacts recorded.
                        </p>
                    )}
                </OverviewCard>
                <OverviewCard title="Occupancy" icon={Users}>
                    <p className="text-3xl font-bold tabular-nums">
                        {hero.occupancy.occupied}
                        <span className="text-base font-normal text-muted-foreground">
                            {' '}
                            of {hero.occupancy.total}
                        </span>
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {hero.occupancy.label.toLowerCase()} currently occupied
                    </p>
                </OverviewCard>
            </div>

            <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
                <SiteAttentionPanel attention={attention} />
                <div className="space-y-4">
                    <OverviewCard title="Safety summary" icon={ShieldAlert}>
                        <div className="flex flex-wrap gap-2">
                            <Badge
                                variant="outline"
                                className={cn(
                                    overview.safety.is_high_risk &&
                                        'border-status-critical/30 bg-status-critical-bg text-status-critical',
                                )}
                            >
                                {overview.safety.is_high_risk
                                    ? 'High-risk Site'
                                    : 'Standard risk'}
                            </Badge>
                            {overview.safety.is_high_needs ? (
                                <Badge variant="outline">High needs</Badge>
                            ) : null}
                        </div>
                        <p className="mt-3 text-sm text-muted-foreground">
                            {overview.safety.emergency_plan_location ||
                                'Emergency plan location not recorded.'}
                        </p>
                    </OverviewCard>
                    <OverviewCard title="Services" icon={CheckCircle2}>
                        {overview.services.length ? (
                            <div className="flex flex-wrap gap-2">
                                {overview.services.map((service) => (
                                    <Badge key={service.id} variant="secondary">
                                        {service.name}
                                    </Badge>
                                ))}
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No active services linked.
                            </p>
                        )}
                    </OverviewCard>
                </div>
            </div>
        </div>
    );
}

function OverviewCard({
    title,
    icon: Icon,
    children,
}: {
    title: string;
    icon: LucideIcon;
    children: ReactNode;
}) {
    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-base">
                    <Icon className="h-4 w-4" /> {title}
                </CardTitle>
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}
