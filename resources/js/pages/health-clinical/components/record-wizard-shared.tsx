/* eslint-disable no-restricted-syntax -- Bespoke client-picker tiles + compact
 * clinical-card rail surfaces: custom layout on semantic design tokens, mirroring
 * the file-dropzone/add-client chrome; not shadcn Button/Card. */
/**
 * Shared building blocks for the three record wizards (Observation / Event / ABC):
 * the debounced client picker (step 1), the collapsed client chip (§8 — when a
 * wizard is launched from a client profile the picker is replaced by this), and
 * the live clinical card shown in the wizard rail once a client is chosen.
 */
import { Input } from '@/components/ui/input';
import { NEWS2_BAND_LABEL, type News2Band } from '@/lib/news2';
import { cn } from '@/lib/utils';
import {
    AlertTriangle,
    HeartPulse,
    Loader2,
    Search,
    ShieldAlert,
    Stethoscope,
    User,
    Workflow,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';

export type ClientResult = {
    id: number;
    name: string;
    preferred_name: string | null;
    nhi: string | null;
    site: string | null;
};

export function clientInitials(name: string): string {
    return (
        name
            .split(' ')
            .map((n) => n[0])
            .slice(0, 2)
            .join('')
            .toUpperCase() || '–'
    );
}

/* ------------------------------------------------------------------ */
/*  Client picker (debounced server search)                            */
/* ------------------------------------------------------------------ */

export function ClientPicker({
    value,
    onChange,
}: {
    value: ClientResult | null;
    onChange: (client: ClientResult | null) => void;
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<ClientResult[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        let active = true;
        setLoading(true);
        const handle = setTimeout(() => {
            fetch(
                `/health-clinical/clients/search?q=${encodeURIComponent(query)}`,
                {
                    headers: { Accept: 'application/json' },
                },
            )
                .then((r) => r.json())
                .then((data: { clients?: ClientResult[] }) => {
                    if (active) {
                        setResults(data.clients ?? []);
                        setLoading(false);
                    }
                })
                .catch(() => active && setLoading(false));
        }, 250);
        return () => {
            active = false;
            clearTimeout(handle);
        };
    }, [query]);

    if (value) {
        return <ClientChip client={value} onClear={() => onChange(null)} />;
    }

    return (
        <div className="flex flex-col gap-2.5">
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Search by name, preferred name or NHI…"
                    className="pl-9"
                    autoFocus
                />
            </div>
            <div className="flex items-center justify-between px-0.5 text-[11px] text-muted-foreground">
                <span>
                    {loading
                        ? 'Searching…'
                        : `${results.length} ${results.length === 1 ? 'match' : 'matches'}`}
                </span>
                {results.length >= 20 ? (
                    <span>Showing first 20 — refine to narrow</span>
                ) : null}
            </div>
            <div className="max-h-[280px] overflow-y-auto rounded-lg border border-border">
                {results.length === 0 && !loading ? (
                    <div className="px-4 py-10 text-center text-sm text-muted-foreground">
                        {query
                            ? 'No clients match that search.'
                            : 'Start typing to find a client.'}
                    </div>
                ) : (
                    <div className="divide-y">
                        {results.map((c) => (
                            <button
                                key={c.id}
                                type="button"
                                onClick={() => onChange(c)}
                                className="flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors hover:bg-muted/40"
                            >
                                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                                    {clientInitials(c.name)}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-medium">
                                        {c.name}
                                    </span>
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {[c.site, c.nhi]
                                            .filter(Boolean)
                                            .join(' · ') || 'No site'}
                                    </span>
                                </span>
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Collapsed client chip (§8 — profile entry point)                   */
/* ------------------------------------------------------------------ */

export function ClientChip({
    client,
    onClear,
}: {
    client: ClientResult;
    onClear?: () => void;
}) {
    return (
        <div className="flex items-center gap-3 rounded-lg border border-primary/30 bg-primary/5 px-3 py-2.5">
            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/15 text-xs font-semibold text-primary">
                {clientInitials(client.name)}
            </span>
            <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-semibold">
                    {client.name}
                </div>
                <div className="truncate text-xs text-muted-foreground">
                    {[client.site, client.nhi].filter(Boolean).join(' · ') ||
                        'No site'}
                </div>
            </div>
            {onClear ? (
                <button
                    type="button"
                    onClick={onClear}
                    aria-label="Change client"
                    className="shrink-0 rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                >
                    <X className="h-4 w-4" />
                </button>
            ) : null}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Live clinical card (wizard rail)                                   */
/* ------------------------------------------------------------------ */

type ClinicalCard = {
    allergies: string[];
    disabilities: string[];
    blood_type: string | null;
    baseline_vitals: {
        recorded_at: string;
        summary: string;
        news2_score: number | null;
        news2_band: News2Band | null;
        news2_band_label: string | null;
    } | null;
    active_protocols: { id: number; name: string; type: string }[];
};

const BAND_PILL: Record<News2Band, string> = {
    low: 'bg-status-success-bg text-status-success',
    low_medium: 'bg-primary/10 text-primary',
    medium: 'bg-status-warning-bg text-status-warning',
    high: 'bg-status-critical-bg text-status-critical',
};

export function ClinicalCardRail({ clientId }: { clientId: number | null }) {
    const [card, setCard] = useState<ClinicalCard | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!clientId) {
            setCard(null);
            return;
        }
        let active = true;
        setLoading(true);
        fetch(`/health-clinical/clients/${clientId}/clinical-card`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then((data: ClinicalCard) => active && setCard(data))
            .catch(() => active && setCard(null))
            .finally(() => active && setLoading(false));
        return () => {
            active = false;
        };
    }, [clientId]);

    if (!clientId) {
        return (
            <div className="rounded-lg border border-dashed border-sidebar-border bg-card/40 p-3 text-[11px] leading-relaxed text-muted-foreground">
                <Stethoscope className="mb-1 h-4 w-4 text-muted-foreground" />
                Allergies, baseline vitals &amp; active protocols load here once
                a client is chosen.
            </div>
        );
    }

    return (
        <div className="rounded-lg border border-sidebar-border bg-card/60 p-3 text-[12px]">
            <div className="mb-2 flex items-center gap-1.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                <HeartPulse className="h-3.5 w-3.5 text-primary" /> Clinical
                card
                {loading ? (
                    <Loader2 className="ml-auto h-3 w-3 animate-spin" />
                ) : null}
            </div>

            {/* Allergies */}
            <div className="mb-2.5">
                <div className="mb-1 flex items-center gap-1 text-[10.5px] font-semibold text-muted-foreground">
                    <ShieldAlert className="h-3 w-3 text-status-critical" />{' '}
                    Allergies
                </div>
                {card?.allergies?.length ? (
                    <div className="flex flex-wrap gap-1">
                        {card.allergies.map((a) => (
                            <span
                                key={a}
                                className="rounded-full bg-status-critical-bg px-2 py-0.5 text-[10.5px] font-medium text-status-critical"
                            >
                                {a}
                            </span>
                        ))}
                    </div>
                ) : (
                    <span className="text-[11px] text-muted-foreground">
                        None recorded
                    </span>
                )}
            </div>

            {/* Baseline vitals */}
            <div className="mb-2.5">
                <div className="mb-1 text-[10.5px] font-semibold text-muted-foreground">
                    Baseline vitals
                </div>
                {card?.baseline_vitals ? (
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span className="text-[11px] text-foreground">
                            {card.baseline_vitals.summary || '—'}
                        </span>
                        {card.baseline_vitals.news2_band ? (
                            <span
                                className={cn(
                                    'rounded-full px-1.5 py-0.5 text-[10px] font-semibold',
                                    BAND_PILL[card.baseline_vitals.news2_band],
                                )}
                            >
                                NEWS2 {card.baseline_vitals.news2_score} ·{' '}
                                {
                                    NEWS2_BAND_LABEL[
                                        card.baseline_vitals.news2_band
                                    ]
                                }
                            </span>
                        ) : null}
                    </div>
                ) : (
                    <span className="text-[11px] text-muted-foreground">
                        No vitals recorded yet
                    </span>
                )}
            </div>

            {/* Active protocols */}
            <div>
                <div className="mb-1 flex items-center gap-1 text-[10.5px] font-semibold text-muted-foreground">
                    <Workflow className="h-3 w-3 text-primary" /> Active
                    protocols
                </div>
                {card?.active_protocols?.length ? (
                    <ul className="space-y-0.5">
                        {card.active_protocols.slice(0, 4).map((p) => (
                            <li
                                key={p.id}
                                className="truncate text-[11px] text-foreground"
                            >
                                · {p.name}{' '}
                                <span className="text-muted-foreground">
                                    ({p.type})
                                </span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <span className="text-[11px] text-muted-foreground">
                        None active
                    </span>
                )}
            </div>
        </div>
    );
}

/* Re-exported icons used by the wizards (keeps a single import surface). */
export { AlertTriangle, User };
