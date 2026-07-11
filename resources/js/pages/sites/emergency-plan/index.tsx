import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import {
    FileDown,
    Pencil,
    Phone,
    ShieldAlert,
    Siren,
    Users,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import SiteTypePlanBuilderDialog from '../plan/_builder-dialog';
import {
    PlanThumbnail,
    type PlanLayout,
    type PlanPin,
} from '../plan/_thumbnail';
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
    draft?: {
        layout: PlanLayout;
        notes?: string | null;
        pins: PlanPin[];
    } | null;
    published?: {
        layout: PlanLayout;
        notes?: string | null;
        pins: PlanPin[];
    } | null;
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

type PaperSize = 'a3' | 'a4' | 'a5';

const PAPER_SIZES: Record<
    PaperSize,
    { label: string; widthMm: number; heightMm: number }
> = {
    a3: { label: 'A3', widthMm: 420, heightMm: 297 },
    a4: { label: 'A4', widthMm: 297, heightMm: 210 },
    a5: { label: 'A5', widthMm: 148, heightMm: 210 },
};

function isPaperSize(value: string | null | undefined): value is PaperSize {
    return value === 'a3' || value === 'a4' || value === 'a5';
}

function paperAspectRatio(paper: PaperSize, layout: PlanLayout): string {
    const canvas = layout.canvas ?? {};
    const landscape =
        paper !== 'a5' && (canvas.width ?? 1000) >= (canvas.height ?? 700);
    const preset = PAPER_SIZES[paper];
    const width = landscape
        ? preset.widthMm
        : Math.min(preset.widthMm, preset.heightMm);
    const height = landscape
        ? preset.heightMm
        : Math.max(preset.widthMm, preset.heightMm);

    return `${width} / ${height}`;
}

function filenameFromDisposition(
    disposition: string | null,
    fallback: string,
): string {
    const match = disposition?.match(/filename="?([^";]+)"?/i);
    return match?.[1] ?? fallback;
}

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
    const [selectedPaper, setSelectedPaper] = useState<PaperSize>(() => {
        const stored =
            typeof window !== 'undefined'
                ? window.localStorage.getItem(`site:${site.id}:emergency-paper`)
                : null;
        return isPaperSize(stored)
            ? stored
            : isPaperSize(plan.layout.export?.paper)
              ? plan.layout.export.paper
              : 'a4';
    });
    const [previewLoading, setPreviewLoading] = useState(false);
    const [exportingPaper, setExportingPaper] = useState<PaperSize | null>(
        null,
    );
    const emergencyKinds = typePlan.emergency_pin_kinds ?? [];
    const emergencyPins = plan.pins.filter((pin) =>
        emergencyKinds.includes(pin.kind),
    );
    const previewAspect = useMemo(
        () => paperAspectRatio(selectedPaper, plan.layout),
        [plan.layout, selectedPaper],
    );
    const hasDraftOverPublished = Boolean(typePlan.draft && typePlan.published);

    useEffect(() => {
        window.localStorage.setItem(
            `site:${site.id}:emergency-paper`,
            selectedPaper,
        );
        setPreviewLoading(true);
        const timeout = window.setTimeout(() => setPreviewLoading(false), 140);
        return () => window.clearTimeout(timeout);
    }, [selectedPaper, site.id]);

    async function downloadEmergencyPlan(paper: PaperSize) {
        if (!ready) {
            toast.warning(
                'Add an assembly point and at least one emergency exit before exporting.',
            );
            return;
        }

        setExportingPaper(paper);
        try {
            const response = await fetch(
                `/sites/${site.id}/emergency-plan.pdf?paper=${paper}`,
                {
                    headers: { Accept: 'application/pdf' },
                },
            );
            if (!response.ok) {
                const message =
                    (await response.text()) ||
                    `Export failed with status ${response.status}.`;
                throw new Error(message);
            }
            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = filenameFromDisposition(
                response.headers.get('content-disposition'),
                `${site.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-emergency-plan-${paper}.pdf`,
            );
            document.body.append(anchor);
            anchor.click();
            anchor.remove();
            window.URL.revokeObjectURL(url);
            toast.success(
                `${PAPER_SIZES[paper].label} emergency plan exported.`,
            );
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Could not export the emergency plan.',
            );
        } finally {
            setExportingPaper(null);
        }
    }

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
                        {
                            label: 'Emergency pins',
                            value: emergencyPins.length,
                        },
                        { label: 'Contacts', value: contacts.length },
                        {
                            label: 'Paper',
                            value: PAPER_SIZES[selectedPaper].label,
                        },
                    ]}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            {(Object.keys(PAPER_SIZES) as PaperSize[]).map(
                                (paper) => (
                                    <Button
                                        key={paper}
                                        type="button"
                                        variant={
                                            selectedPaper === paper
                                                ? 'default'
                                                : 'outline'
                                        }
                                        disabled={exportingPaper !== null}
                                        className={
                                            selectedPaper === paper
                                                ? undefined
                                                : 'border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground'
                                        }
                                        onClick={() => setSelectedPaper(paper)}
                                        data-test={`emergency-paper-${paper}`}
                                    >
                                        {PAPER_SIZES[paper].label}
                                    </Button>
                                ),
                            )}
                            <Button
                                type="button"
                                disabled={!ready || exportingPaper !== null}
                                onClick={() =>
                                    downloadEmergencyPlan(selectedPaper)
                                }
                                data-test="emergency-plan-download"
                            >
                                <FileDown className="mr-2 h-4 w-4" />
                                {exportingPaper
                                    ? 'Exporting...'
                                    : `Export ${PAPER_SIZES[selectedPaper].label}`}
                            </Button>
                            {can.update && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                    onClick={() => {
                                        setBuilderMode('emergency');
                                        setBuilderFocus(
                                            ready
                                                ? undefined
                                                : 'assembly_point',
                                        );
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
                                Evacuation Map Preview
                            </CardTitle>
                            <div className="flex flex-wrap items-center justify-end gap-2">
                                {hasDraftOverPublished && (
                                    <Badge variant="secondary">
                                        Draft changes not published
                                    </Badge>
                                )}
                                <Badge variant={ready ? 'default' : 'outline'}>
                                    {ready
                                        ? 'Ready to export'
                                        : 'Needs assembly point and exit'}
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent>
                        <div className="rounded-lg border bg-muted p-4 shadow-inner">
                                <div
                                className="relative mx-auto max-h-[640px] max-w-full overflow-hidden rounded-md bg-background shadow-sm ring-1 ring-border"
                                    style={{ aspectRatio: previewAspect }}
                                    data-test="emergency-plan-preview-page"
                                >
                                    {previewLoading ? (
                                        <div className="absolute inset-0 space-y-3 p-6">
                                            <Skeleton className="h-10 w-1/2" />
                                            <Skeleton className="h-[70%] w-full" />
                                            <Skeleton className="h-8 w-2/3" />
                                        </div>
                                    ) : (
                                        <PlanThumbnail
                                            layout={plan.layout}
                                            pins={emergencyPins}
                                            taxonomy={typePlan.taxonomy ?? null}
                                            className="h-full rounded-none border-0"
                                        />
                                    )}
                                </div>
                            </div>
                            <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                                <span>
                                    Previewing{' '}
                                    {PAPER_SIZES[selectedPaper].label}; exports
                                    use the published house plan.
                                </span>
                                {hasDraftOverPublished && (
                                    <span>
                                        Publish draft changes before relying on
                                        this emergency plan.
                                    </span>
                                )}
                            </div>
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
                                    <div
                                        key={`${contact.name}-${index}`}
                                        className="rounded-md border p-3 text-sm"
                                    >
                                        <div className="font-medium">
                                            {contact.name}
                                        </div>
                                        {contact.role && (
                                            <div className="text-muted-foreground">
                                                {contact.role}
                                            </div>
                                        )}
                                        {contact.phone && (
                                            <div className="mt-1 font-mono text-base">
                                                {contact.phone}
                                            </div>
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
                                        <li
                                            key={procedure}
                                            className="flex gap-2"
                                        >
                                            <span className="font-medium">
                                                {index + 1}.
                                            </span>
                                            <span>{procedure}</span>
                                        </li>
                                    ))}
                                </ol>
                            </CardContent>
                        </Card>

                        {legend.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Legend
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2 text-sm">
                                    {legend.map((item) => (
                                        <div
                                            key={item.kind}
                                            className="flex items-center justify-between rounded-md border p-2"
                                        >
                                            <span>{item.label}</span>
                                            <Badge variant="secondary">
                                                {item.count}
                                            </Badge>
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>
                        )}

                        {support_notes && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Support Notes
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="text-sm whitespace-pre-wrap text-muted-foreground">
                                    {support_notes}
                                </CardContent>
                            </Card>
                        )}

                        {footer && (
                            <p className="px-1 text-xs text-muted-foreground">
                                {footer}
                            </p>
                        )}
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
