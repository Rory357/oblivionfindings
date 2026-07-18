import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import { BadgeCheck, ExternalLink, GraduationCap } from 'lucide-react';
import {
    SiteProfileEmptyState,
    SiteProfileLockedState,
} from './site-profile-states';

export type SiteStaffRequirementsData = {
    locked: boolean;
    can_manage: boolean;
    items: Array<{
        id: number;
        name: string;
        category?: string | null;
        description?: string | null;
        certification_required: boolean;
        expiry_period_months?: number | null;
    }>;
};

export function SiteProfileStaffRequirements({
    siteId,
    data,
}: {
    siteId: number;
    data: SiteStaffRequirementsData;
}) {
    if (data.locked) {
        return <SiteProfileLockedState label="Staff requirements" />;
    }

    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold">
                        Staff requirements
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Skills and certifications expected for work at this
                        Site.
                    </p>
                </div>
                {data.can_manage ? (
                    <Button asChild variant="outline" className="min-h-11">
                        <Link href={`/sites/${siteId}/edit`}>
                            Manage Site requirements
                            <ExternalLink className="ml-2 h-4 w-4" />
                        </Link>
                    </Button>
                ) : null}
            </div>

            {data.items.length ? (
                <div className="grid gap-3 md:grid-cols-2">
                    {data.items.map((requirement) => (
                        <Card key={requirement.id}>
                            <CardContent className="flex gap-3 p-4">
                                <div className="rounded-lg bg-primary/10 p-2 text-primary">
                                    {requirement.certification_required ? (
                                        <BadgeCheck className="h-5 w-5" />
                                    ) : (
                                        <GraduationCap className="h-5 w-5" />
                                    )}
                                </div>
                                <div className="min-w-0">
                                    <p className="font-semibold">
                                        {requirement.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {[
                                            requirement.category,
                                            requirement.certification_required
                                                ? 'Certification required'
                                                : null,
                                            requirement.expiry_period_months
                                                ? `Renew every ${requirement.expiry_period_months} months`
                                                : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ') || 'Site requirement'}
                                    </p>
                                    {requirement.description ? (
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            {requirement.description}
                                        </p>
                                    ) : null}
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            ) : (
                <SiteProfileEmptyState
                    icon={GraduationCap}
                    title="No staff requirements recorded"
                    description="Record the skills and certifications staff need before working at this Site."
                    action={
                        data.can_manage
                            ? {
                                  label: 'Edit Site requirements',
                                  href: `/sites/${siteId}/edit`,
                              }
                            : undefined
                    }
                />
            )}
        </div>
    );
}
