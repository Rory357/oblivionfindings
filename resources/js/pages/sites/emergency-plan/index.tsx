import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { FileDown, Pencil, Phone, ShieldAlert, Siren, Users } from 'lucide-react';
import { useState } from 'react';
import SiteTypePlanBuilderDialog from '../plan/_builder-dialog';
import { PlanThumbnail, type PlanLayout, type PlanPin } from '../plan/_thumbnail';
import type { BuilderMode, Inventory, Taxonomy } from '../plan/_types';

type Site = {
    id: number;
    name: string;
    type: string;
    address?: string | null;
    phone?: string | null;
};

type PlanRecord = {
    id: number;
    version: number;
    layout: PlanLayout;
    notes?: string | null;
    pins: PlanPin[];
};

type Contact = {
    name: string;
    role?: string | null;
    phone?: string | null;
    email?: string | null;
};

type LegendItem = {
    kind: string;
    label: string;
    count: number;
};

type TypePlanSummary = {
    tab_label: string;
    inventory_label: string;
    inventory_href: string;
    draft?: { layout: PlanLayout; notes?: string | null; pins: PlanPin[] } | null;
    published?: { layout: PlanLayout; notes?: string | null; pins: PlanPin[] } | null;
    inventory?: Inventory | null;
    taxonomy?: Taxonomy | null;
    emergency_pin_kinds?: string[];
    has_emergency_layer?: boolean;
};

type Props = {
    site: Site;
    organisation: { name: string; logo_url?: string | null };
    plan: PlanRecord;
    ready: boolean;
    legend: LegendItem[];
    contacts: Contact[];
    procedures: string[];
    support_notes?: string | null;
    footer?: string | null;
    typePlan: TypePlanSummary;
    can: { update: boolean };
};

export default function SiteEmergencyPlanIndex({
    site,
    organisation,
    plan,
    ready,
    legend,
    contacts,
    procedures,
    support_notes,
    footer,
    typePlan,
    can,
}: Props) {
    const [builderOpen, setBuilderOpen] = useState(false);
    const [builderMode, setBuilderMode] = useState<BuilderMode>('emergency');
    const [builderFocus, setBuilderFocus] = useState<string | undefined>();
    const emergencyKinds = typePlan.emergency_pin_kinds ?? [];
    const emergencyPins = plan.pins.filter((pin) => emergencyKinds.includes(pin.kind));

    return (
        <AppLayout>
            <Head title={`${site.name} Emergency Plan`} />
            <PageShell>
                <PageHero
                    icon={Siren}
                    backHref={`/sites/${site.id}/plan`}
                    backLabel="Back to site plan"
                    title="Emergency Plan"
                    description={`${site.name} - ${organisation.name}`}
                    stats={[
                        { label: 'Status', value: ready ? 'Ready' : 'Draft' },
                        { label: 'Emergency pins', value: emergencyPins.length },
                        { label: 'Contacts', value: contacts.length },
                        { label: 'Procedures', value: procedures.length },
                    ]}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            {['a3', 'a4', 'a5'].map((paper) => (
                                <Button
                                    key={paper}
                                    asChild
                                    variant={paper === 'a4' ? 'default' : 'outline'}
                                    disabled={!ready}
                                    className={
                                        paper === 'a4'
                                            ? undefined
                                            : 'border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground'
                                    }
                                >
                                    <Link href={`/sites/${site.id}/emergency-plan.pdf?paper=${paper}`}>
                                        <FileDown className="mr-2 h-4 w-4" />
                                        {paper.toUpperCase()}
                                    </Link>
                                </Button>
                            ))}
                            {can.update && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                    onClick={() => {
                                        setBuilderMode('emergency');
                                        setBuilderFocus(ready ? undefined : 'assembly_point');
                                        setBuilderOpen(true);
                                    }}
                                >
                                    <Pencil className="mr-2 h-4 w-4" />
                                    Edit emergency plan
                                </Button>
                            )}
                        </div>
                    }
                />

                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between gap-3 space-y-0">
                            <CardTitle className="flex items-center gap-2">
                                <ShieldAlert className="h-5 w-5 text-primary" />
                                Evacuation Map
                            </CardTitle>
                            <Badge variant={ready ? 'default' : 'outline'}>
                                {ready ? 'Ready to export' : 'Needs assembly point and exit'}
                            </Badge>
                        </CardHeader>
                        <CardContent>
                            <PlanThumbnail
                                layout={plan.layout}
                                pins={emergencyPins}
                                taxonomy={typePlan.taxonomy ?? null}
                                className="min-h-[480px]"
                            />
                        </CardContent>
                    </Card>

                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Phone className="h-4 w-4" />
                                    Emergency Contacts
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {contacts.map((contact, index) => (
                                    <div key={`${contact.name}-${index}`} className="rounded-md border p-3 text-sm">
                                        <div className="font-medium">{contact.name}</div>
                                        {contact.role && (
                                            <div className="text-muted-foreground">{contact.role}</div>
                                        )}
                                        {contact.phone && (
                                            <div className="mt-1 font-mono text-base">{contact.phone}</div>
                                        )}
                                    </div>
                                ))}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Users className="h-4 w-4" />
                                    Procedure
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ol className="space-y-2 text-sm">
                                    {procedures.map((procedure, index) => (
                                        <li key={procedure} className="flex gap-2">
                                            <span className="font-medium">{index + 1}.</span>
                                            <span>{procedure}</span>
                                        </li>
                                    ))}
                                </ol>
                            </CardContent>
                        </Card>

                        {legend.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Legend</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2 text-sm">
                                    {legend.map((item) => (
                                        <div key={item.kind} className="flex items-center justify-between rounded-md border p-2">
                                            <span>{item.label}</span>
                                            <Badge variant="secondary">{item.count}</Badge>
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>
                        )}

                        {support_notes && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Support Notes</CardTitle>
                                </CardHeader>
                                <CardContent className="whitespace-pre-wrap text-sm text-muted-foreground">
                                    {support_notes}
                                </CardContent>
                            </Card>
                        )}

                        {footer && <p className="px-1 text-xs text-muted-foreground">{footer}</p>}
                    </div>
                </div>
            </PageShell>
            <SiteTypePlanBuilderDialog
                site={site}
                typePlan={typePlan}
                open={builderOpen}
                onOpenChange={(open) => {
                    setBuilderOpen(open);
                    if (!open) {
                        setBuilderMode('emergency');
                        setBuilderFocus(undefined);
                    }
                }}
                focusTool={builderFocus}
                mode={builderMode}
            />
        </AppLayout>
    );
}
