import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, FileDown, MapPinned, Pencil, Plus, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import SiteTypePlanBuilderDialog from './_builder-dialog';
import { PlanThumbnail } from './_thumbnail';
import type { Inventory, PlanLayout, PlanPin, Taxonomy } from './_types';

type Site = {
    id: number;
    name: string;
    type: string;
    display_type: string;
};

type PlanRecord = {
    id: number;
    status: string;
    version: number;
    layout: PlanLayout;
    notes?: string | null;
    pins: PlanPin[];
    published_at?: string | null;
};

type TypePlanSummary = {
    tab_label: string;
    inventory_label: string;
    inventory_href: string;
    status: 'empty' | 'draft' | 'published' | 'draft_over_published';
    draft?: PlanRecord | null;
    published?: PlanRecord | null;
    has_plan: boolean;
    has_published: boolean;
    has_emergency_layer: boolean;
    has_medication_pin: boolean;
    pin_counts: Record<string, number>;
    inventory?: Inventory | null;
    taxonomy?: Taxonomy | null;
};

type Props = {
    site: Site;
    typePlan: TypePlanSummary;
    can: { update?: boolean };
};

function planStatusLabel(status: TypePlanSummary['status']) {
    if (status === 'draft_over_published') return 'Draft changes';
    if (status === 'draft') return 'Draft';
    if (status === 'published') return 'Published';
    return 'Not started';
}

export default function SitePlanIndex({ site, typePlan, can }: Props) {
    const [builderOpen, setBuilderOpen] = useState(false);
    const activePlan = typePlan.draft ?? typePlan.published;

    return (
        <AppLayout>
            <Head title={`${site.name} ${typePlan.tab_label}`} />
            <PageShell>
                <PageHeader
                    title={typePlan.tab_label}
                    description={`${site.name} ${site.display_type}`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Button asChild variant="outline">
                                <Link href={typePlan.inventory_href}>
                                    {typePlan.inventory_label}
                                    <ArrowRight className="ml-2 h-4 w-4" />
                                </Link>
                            </Button>
                            {typePlan.has_published && (
                                <Button asChild variant="outline">
                                    <Link href={`/sites/${site.id}/emergency-plan`}>
                                        <ShieldAlert className="mr-2 h-4 w-4" />
                                        Emergency Plan
                                    </Link>
                                </Button>
                            )}
                            {can.update && (
                                <Button onClick={() => setBuilderOpen(true)}>
                                    {activePlan ? (
                                        <Pencil className="mr-2 h-4 w-4" />
                                    ) : (
                                        <Plus className="mr-2 h-4 w-4" />
                                    )}
                                    {activePlan ? 'Edit Plan' : 'Build Plan'}
                                </Button>
                            )}
                        </div>
                    }
                />

                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between gap-3 space-y-0">
                            <CardTitle className="flex items-center gap-2">
                                <MapPinned className="h-5 w-5 text-primary" />
                                Current Plan
                            </CardTitle>
                            <Badge variant="outline">{planStatusLabel(typePlan.status)}</Badge>
                        </CardHeader>
                        <CardContent>
                            {activePlan ? (
                                <PlanThumbnail
                                    layout={activePlan.layout}
                                    pins={activePlan.pins}
                                    taxonomy={typePlan.taxonomy ?? null}
                                    showScale
                                    className="min-h-[420px]"
                                />
                            ) : (
                                <div className="flex min-h-[420px] items-center justify-center rounded-md border border-dashed bg-muted/30 p-8 text-center">
                                    <div className="max-w-sm space-y-3">
                                        <MapPinned className="mx-auto h-10 w-10 text-muted-foreground" />
                                        <p className="text-sm text-muted-foreground">
                                            No plan has been started for this site.
                                        </p>
                                        {can.update && (
                                            <Button onClick={() => setBuilderOpen(true)}>
                                                <Plus className="mr-2 h-4 w-4" />
                                                Build Plan
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Readiness</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="flex items-center justify-between rounded-md border p-3">
                                    <span>Emergency layer</span>
                                    <Badge variant={typePlan.has_emergency_layer ? 'default' : 'outline'}>
                                        {typePlan.has_emergency_layer ? 'Ready' : 'Needs pins'}
                                    </Badge>
                                </div>
                                <div className="flex items-center justify-between rounded-md border p-3">
                                    <span>Medication storage</span>
                                    <Badge variant={typePlan.has_medication_pin ? 'default' : 'outline'}>
                                        {typePlan.has_medication_pin ? 'Pinned' : 'Not pinned'}
                                    </Badge>
                                </div>
                                {typePlan.has_published && (
                                    <Button asChild variant="outline" className="w-full justify-start">
                                        <Link href={`/sites/${site.id}/emergency-plan.pdf?paper=a4`}>
                                            <FileDown className="mr-2 h-4 w-4" />
                                            Export A4 Emergency Plan
                                        </Link>
                                    </Button>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Pin Counts</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2 text-sm">
                                {Object.entries(typePlan.pin_counts).length > 0 ? (
                                    Object.entries(typePlan.pin_counts).map(([kind, count]) => (
                                        <div key={kind} className="flex items-center justify-between rounded-md border p-2">
                                            <span className="capitalize">{kind.replaceAll('_', ' ')}</span>
                                            <Badge variant="secondary">{count}</Badge>
                                        </div>
                                    ))
                                ) : (
                                    <p className="text-muted-foreground">No pins yet.</p>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </PageShell>

            <SiteTypePlanBuilderDialog
                site={site}
                typePlan={typePlan}
                open={builderOpen}
                onOpenChange={setBuilderOpen}
            />
        </AppLayout>
    );
}
