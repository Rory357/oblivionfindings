/* eslint-disable no-restricted-syntax -- The ABC entry cards + A·B·C columns are
 * bespoke layout surfaces on semantic design tokens, not generic Card content. */
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    HealthClinicalShell,
    RegisterStatStrip,
    type HealthClinicalKpis,
} from '@/pages/health-clinical/components/health-clinical-shell';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { Brain, Clock, Filter, ShieldAlert, X } from 'lucide-react';
import { useState } from 'react';

type PaginatedData<T> = {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
};

type AbcEntry = {
    id: number;
    occurred_at: string | null;
    setting: string | null;
    antecedent: string;
    behaviour: string;
    consequence: string;
    behaviour_tags: string[];
    behaviour_function: string | null;
    behaviour_function_label: string | null;
    intensity: string;
    duration_seconds: number | null;
    harm_occurred: boolean;
    escalated: boolean;
    requires_followup: boolean;
    followup_completed: boolean;
    client: { id: number; first_name: string; last_name: string; site: string | null } | null;
    recorder: { id: number; name: string } | null;
};

type Stats = {
    total_7d: number;
    total_30d: number;
    escalated_30d: number;
    harm_30d: number;
    function_breakdown: Record<string, number>;
    intensity_mix: Record<string, number>;
};

type SelectOption = { value: string; label: string };
type FilterOptions = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    functions: Array<{ value: string; label: string; description?: string }>;
    intensities: SelectOption[];
};
type Filters = { client_id?: string; behaviour_function?: string; intensity?: string; site_id?: string; date_from?: string; date_to?: string };
type Props = {
    entries: PaginatedData<AbcEntry>;
    stats: Stats;
    filters: Filters;
    filter_options: FilterOptions;
    kpis: HealthClinicalKpis;
    tab_counts?: Record<string, number>;
};

const ALL_SENTINEL = '__all__';

const INTENSITY_TONE: Record<string, string> = {
    low: 'bg-status-success-bg text-status-success',
    medium: 'bg-status-warning-bg text-status-warning',
    high: 'bg-status-critical-bg text-status-critical',
};

function formatNzDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export default function BehaviourRegister({ entries, stats, filters, filter_options, kpis, tab_counts }: Props) {
    const [local, setLocal] = useState<Filters>({
        client_id: filters.client_id ?? '',
        behaviour_function: filters.behaviour_function ?? '',
        intensity: filters.intensity ?? '',
        site_id: filters.site_id ?? '',
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
    });

    const apply = () => {
        const clean = Object.fromEntries(Object.entries(local).filter(([, v]) => v !== '' && v !== undefined));
        router.get('/health-clinical/behaviour', clean, { preserveState: true, replace: true });
    };
    const clear = () => {
        setLocal({});
        router.get('/health-clinical/behaviour', {}, { preserveState: true, replace: true });
    };
    const hasFilters = Object.values(local).some((v) => v !== '' && v !== undefined);
    const clientName = (e: AbcEntry) => (e.client ? `${e.client.first_name} ${e.client.last_name}`.trim() : 'No client');

    return (
        <HealthClinicalShell activeTab="behaviour" kpis={kpis} tabCounts={tab_counts}>
            <RegisterStatStrip
                stats={[
                    { label: 'ABC · 7d', value: stats.total_7d },
                    { label: '30d', value: stats.total_30d },
                    { label: 'Escalated', value: stats.escalated_30d, tone: stats.escalated_30d > 0 ? 'warning' : 'default' },
                    { label: 'Harm', value: stats.harm_30d, tone: stats.harm_30d > 0 ? 'critical' : 'default' },
                ]}
            />

            <Card>
                <CardHeader className="pb-3">
                    <CardTitle className="flex items-center gap-2 text-sm">
                        <Filter className="h-4 w-4" /> Filters
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        <FilterSelect label="Client" value={local.client_id} onChange={(v) => setLocal((c) => ({ ...c, client_id: v }))} placeholder="All clients"
                            options={filter_options.clients.map((c) => ({ value: String(c.id), label: `${c.first_name} ${c.last_name}` }))} />
                        <FilterSelect label="Function" value={local.behaviour_function} onChange={(v) => setLocal((c) => ({ ...c, behaviour_function: v }))} placeholder="Any function"
                            options={filter_options.functions.map((f) => ({ value: f.value, label: f.label }))} />
                        <FilterSelect label="Intensity" value={local.intensity} onChange={(v) => setLocal((c) => ({ ...c, intensity: v }))} placeholder="Any intensity" options={filter_options.intensities} />
                        <div>
                            <Label className="text-xs">From</Label>
                            <Input type="date" className="h-8 text-xs" value={local.date_from ?? ''} onChange={(e) => setLocal((c) => ({ ...c, date_from: e.target.value }))} />
                        </div>
                        <div>
                            <Label className="text-xs">To</Label>
                            <Input type="date" className="h-8 text-xs" value={local.date_to ?? ''} onChange={(e) => setLocal((c) => ({ ...c, date_to: e.target.value }))} />
                        </div>
                        <div className="flex items-end gap-2">
                            <Button size="sm" onClick={apply}>Apply</Button>
                            {hasFilters && (
                                <Button size="sm" variant="ghost" onClick={clear} className="gap-1"><X className="h-3 w-3" /> Clear</Button>
                            )}
                        </div>
                    </div>
                </CardContent>
            </Card>

            {entries.data.length === 0 ? (
                <Card>
                    <CardContent className="p-12 text-center">
                        <Brain className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                        <p className="font-medium text-muted-foreground">No ABC entries here</p>
                        <p className="mt-1 text-sm text-muted-foreground/70">Use “Record ABC” above to chart a behaviour, or adjust the filters.</p>
                    </CardContent>
                </Card>
            ) : (
                <div className="flex flex-col gap-3">
                    {entries.data.map((e) => (
                        <div key={e.id} className="rounded-xl border border-border bg-card p-4 shadow-sm">
                            <div className="mb-3 flex flex-wrap items-center gap-2">
                                {e.client ? (
                                    <Link href={`/operations/clients/${e.client.id}`} className="text-sm font-semibold text-status-info hover:underline">{clientName(e)}</Link>
                                ) : (
                                    <span className="text-sm font-semibold">{clientName(e)}</span>
                                )}
                                <span className="text-xs text-muted-foreground">{e.client?.site ?? '—'}</span>
                                <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-medium capitalize', INTENSITY_TONE[e.intensity] ?? INTENSITY_TONE.low)}>
                                    {e.intensity} intensity
                                </span>
                                {e.behaviour_function_label ? (
                                    <Badge variant="outline" className="border-primary/30 text-[10px] text-primary">{e.behaviour_function_label}</Badge>
                                ) : null}
                                {e.harm_occurred ? <Badge variant="outline" className="border-status-critical/40 text-[10px] text-status-critical"><ShieldAlert className="mr-0.5 h-3 w-3" />Harm</Badge> : null}
                                {e.escalated ? <Badge variant="outline" className="border-status-warning/40 text-[10px] text-status-warning">Escalated</Badge> : null}
                                {e.requires_followup && !e.followup_completed ? <Badge variant="outline" className="border-status-warning/40 text-[10px] text-status-warning">Follow-up due</Badge> : null}
                                <span className="ml-auto inline-flex items-center gap-1 text-[11px] text-muted-foreground">
                                    <Clock className="h-3 w-3" />{formatNzDate(e.occurred_at)}
                                </span>
                            </div>
                            <div className="grid gap-2.5 sm:grid-cols-3">
                                <AbcCol letter="A" label="Antecedent" text={e.antecedent} />
                                <AbcCol letter="B" label="Behaviour" text={e.behaviour} />
                                <AbcCol letter="C" label="Consequence" text={e.consequence} />
                            </div>
                            {(e.setting || e.recorder || e.duration_seconds) ? (
                                <div className="mt-2.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-muted-foreground">
                                    {e.setting ? <span>Setting: {e.setting}</span> : null}
                                    {e.duration_seconds ? <span>Duration: {e.duration_seconds}s</span> : null}
                                    {e.recorder ? <span>Recorded by {e.recorder.name}</span> : null}
                                </div>
                            ) : null}
                        </div>
                    ))}
                </div>
            )}

            {entries.last_page > 1 ? (
                <div className="flex items-center justify-between px-1">
                    <p className="text-xs text-muted-foreground">Page {entries.current_page} of {entries.last_page} ({entries.total} total)</p>
                    <div className="flex gap-1">
                        {entries.links.map((link, i) => (
                            <Button key={i} variant={link.active ? 'default' : 'outline'} size="sm" className="h-7 min-w-[28px] px-2 text-xs" disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                dangerouslySetInnerHTML={{ __html: link.label }} />
                        ))}
                    </div>
                </div>
            ) : null}
        </HealthClinicalShell>
    );
}

function AbcCol({ letter, label, text }: { letter: string; label: string; text: string }) {
    return (
        <div className="rounded-lg border border-border bg-muted/20 p-3">
            <div className="mb-1 flex items-center gap-1.5">
                <span className="grid h-5 w-5 place-items-center rounded-md bg-primary/10 text-[11px] font-bold text-primary">{letter}</span>
                <span className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">{label}</span>
            </div>
            <p className="text-[13px] whitespace-pre-wrap text-foreground">{text}</p>
        </div>
    );
}

function FilterSelect({ label, value, onChange, placeholder, options }: { label: string; value?: string; onChange: (v: string) => void; placeholder: string; options: SelectOption[] }) {
    return (
        <div>
            <Label className="text-xs">{label}</Label>
            <Select value={value || ALL_SENTINEL} onValueChange={(v) => onChange(v === ALL_SENTINEL ? '' : v)}>
                <SelectTrigger className="h-8 text-xs">
                    <SelectValue placeholder={placeholder} />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL_SENTINEL}>{placeholder}</SelectItem>
                    {options.map((o) => (
                        <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
