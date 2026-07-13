import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { ArrowRight, Layers } from 'lucide-react';
import { cn } from '@/lib/utils';

interface CockpitCard {
    key: string;
    title: string;
    description: string;
    status: string;
    metrics: Array<{ label: string; value: string; tone: string }>;
    highlights: string[];
    href: string;
}

interface OperationalSignalsAccordionProps {
    cardsByKey: Record<string, CockpitCard | undefined>;
}

const OPERATIONAL_KEYS = [
    'client_safety',
    'operational_safety',
    'workforce',
    'control_room',
    'it_cyber',
    'safeguarding',
    'fleet_assets',
    'hs_backbone',
];

const TONE_VALUE: Record<string, string> = {
    default: 'text-foreground',
    critical: 'text-status-critical',
    warning: 'text-status-warning',
    muted: 'text-muted-foreground',
};

const STATUS_BADGE: Record<string, string> = {
    critical: 'border-status-critical/30 bg-status-critical-bg text-status-critical',
    warning: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
    good: 'border-status-success/30 bg-status-success-bg text-status-success',
    unknown: 'border-border bg-muted text-muted-foreground',
};

function SignalCard({ card }: { card: CockpitCard }) {
    return (
        <Card unstyled className="space-y-3 rounded-lg border border-border bg-card p-4">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-sm font-semibold text-foreground">{card.title}</p>
                    <p className="text-xs text-muted-foreground">{card.description}</p>
                </div>
                <Badge className={cn('border text-[10px] uppercase', STATUS_BADGE[card.status] ?? STATUS_BADGE.unknown)}>
                    {card.status}
                </Badge>
            </div>
            <div className="grid grid-cols-2 gap-2">
                {card.metrics.slice(0, 4).map((m) => (
                    <div key={m.label} className="rounded-md bg-muted/60 p-2">
                        <p className="text-[10px] uppercase tracking-wide text-muted-foreground">{m.label}</p>
                        <p className={cn('mt-0.5 text-base font-semibold', TONE_VALUE[m.tone] ?? TONE_VALUE.default)}>
                            {m.value}
                        </p>
                    </div>
                ))}
            </div>
            <Button asChild size="sm" variant="ghost" className="w-full justify-between">
                <Link href={card.href}>
                    Open {card.title}
                    <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
                </Link>
            </Button>
        </Card>
    );
}

/**
 * Collapsed-by-default accordion preserving the original operational widgets
 * (client safety, workforce, control room, fleet, H&S backbone). These remain
 * accessible but no longer fight board priorities for attention.
 */
export function OperationalSignalsAccordion({ cardsByKey }: OperationalSignalsAccordionProps) {
    const available = OPERATIONAL_KEYS.map((k) => cardsByKey[k]).filter(Boolean) as CockpitCard[];
    if (available.length === 0) return null;

    const criticals = available.filter((c) => c.status === 'critical').length;
    const warnings = available.filter((c) => c.status === 'warning').length;

    return (
        <Card data-dusk="cockpit-operational-signals">
            <Accordion type="single" collapsible defaultValue="">
                <AccordionItem value="ops" className="border-0">
                    <CardHeader className="pb-0">
                        <AccordionTrigger className="px-0 hover:no-underline">
                            <div className="flex w-full items-center gap-3">
                                <div className="rounded-md bg-muted p-2">
                                    <Layers className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                                </div>
                                <div className="text-left">
                                    <CardTitle className="text-base">Operational Signals</CardTitle>
                                    <CardDescription>
                                        Service safety, workforce, controls, fleet and H&amp;S backbone — for context.
                                    </CardDescription>
                                </div>
                                <div className="ml-auto flex items-center gap-2">
                                    {criticals > 0 && (
                                        <Badge className="border border-status-critical/30 bg-status-critical-bg text-status-critical">
                                            {criticals} critical
                                        </Badge>
                                    )}
                                    {warnings > 0 && (
                                        <Badge className="border border-status-warning/30 bg-status-warning-bg text-status-warning">
                                            {warnings} warning
                                        </Badge>
                                    )}
                                    {criticals === 0 && warnings === 0 && (
                                        <Badge className="border border-status-success/30 bg-status-success-bg text-status-success">
                                            All clear
                                        </Badge>
                                    )}
                                </div>
                            </div>
                        </AccordionTrigger>
                    </CardHeader>
                    <AccordionContent>
                        <CardContent className="grid gap-3 pt-3 md:grid-cols-2 xl:grid-cols-3">
                            {available.map((card) => (
                                <SignalCard key={card.key} card={card} />
                            ))}
                        </CardContent>
                    </AccordionContent>
                </AccordionItem>
            </Accordion>
        </Card>
    );
}

export default OperationalSignalsAccordion;
