import { Link } from '@inertiajs/react';
import { ArrowRight, Layers } from 'lucide-react';

import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';

import { CockpitCardStatus, isCardStatusKnown } from './CockpitCardStatus';

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

/**
 * The day-to-day areas shown here for context. Privacy, incidents,
 * safeguarding and spending sit in Board assurance instead, so no card
 * appears twice on Home.
 */
export const OPERATIONAL_KEYS = [
    'client_safety',
    'operational_safety',
    'workforce',
    'control_room',
    'it_cyber',
    'fleet_assets',
    'hs_backbone',
];

const TONE_VALUE: Record<string, string> = {
    default: 'text-foreground',
    critical: 'text-status-critical',
    warning: 'text-status-warning',
    muted: 'text-muted-foreground',
};

function SignalCard({ card }: { card: CockpitCard }) {
    return (
        <li className="flex flex-col gap-3 rounded-lg border border-border bg-card p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-sm font-semibold text-foreground">
                        {card.title}
                    </p>
                    <p className="text-caption">{card.description}</p>
                </div>
                <CockpitCardStatus status={card.status} />
            </div>
            <dl className="grid grid-cols-2 gap-2">
                {card.metrics.map((m) => (
                    <div key={m.label} className="rounded-md bg-muted/60 p-2">
                        <dt className="text-caption">{m.label}</dt>
                        <dd
                            className={cn(
                                'mt-0.5 text-sm font-semibold tabular-nums',
                                TONE_VALUE[m.tone] ?? TONE_VALUE.default,
                            )}
                        >
                            {m.value}
                        </dd>
                    </div>
                ))}
            </dl>
            <Link
                href={card.href}
                className="inline-flex items-center gap-1 self-start text-xs font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                Open {card.title.toLowerCase()}
                <ArrowRight className="size-3.5" aria-hidden="true" />
            </Link>
        </li>
    );
}

/**
 * Collapsed by default: care safety, staff, the control room, IT, vehicles
 * and health and safety — context for meeting managers, with every figure
 * named in plain words. Not shown to ordinary members.
 */
export function OperationalSignalsAccordion({
    cardsByKey,
}: OperationalSignalsAccordionProps) {
    const available = OPERATIONAL_KEYS.map((k) => cardsByKey[k]).filter(
        Boolean,
    ) as CockpitCard[];
    if (available.length === 0) return null;

    const criticals = available.filter((c) => c.status === 'critical').length;
    const warnings = available.filter((c) => c.status === 'warning').length;
    const unavailable = available.filter(
        (c) => !isCardStatusKnown(c.status),
    ).length;
    // "No concerns" only when every area actually reported and none alert.
    const allClear = criticals === 0 && warnings === 0 && unavailable === 0;

    return (
        <Card data-dusk="cockpit-operational-signals">
            <Accordion type="single" collapsible defaultValue="">
                <AccordionItem value="ops" className="border-0">
                    <CardHeader>
                        <AccordionTrigger className="px-0 hover:no-underline">
                            <div className="flex w-full flex-wrap items-center gap-3">
                                <div className="rounded-md bg-muted p-2">
                                    <Layers
                                        className="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                </div>
                                <div className="min-w-0 text-left">
                                    <CardTitle className="text-section-title">
                                        Service, safety and people
                                    </CardTitle>
                                    <CardDescription>
                                        Care safety, staff, the control room,
                                        IT, vehicles and health and safety —
                                        figures for this month.
                                    </CardDescription>
                                </div>
                                <div className="ml-auto flex flex-wrap items-center gap-2">
                                    {criticals > 0 && (
                                        <StatusBadge variant="critical">
                                            {criticals} need action
                                        </StatusBadge>
                                    )}
                                    {warnings > 0 && (
                                        <StatusBadge variant="warning">
                                            {warnings} to watch
                                        </StatusBadge>
                                    )}
                                    {unavailable > 0 && (
                                        <StatusBadge variant="neutral">
                                            {unavailable} not available
                                        </StatusBadge>
                                    )}
                                    {allClear && (
                                        <StatusBadge variant="success">
                                            No concerns
                                        </StatusBadge>
                                    )}
                                </div>
                            </div>
                        </AccordionTrigger>
                    </CardHeader>
                    <AccordionContent>
                        <CardContent className="pt-3">
                            <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                {available.map((card) => (
                                    <SignalCard key={card.key} card={card} />
                                ))}
                            </ul>
                        </CardContent>
                    </AccordionContent>
                </AccordionItem>
            </Accordion>
        </Card>
    );
}

export default OperationalSignalsAccordion;
