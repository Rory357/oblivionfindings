/* eslint-disable no-restricted-syntax -- Compact monitoring stat cards + entry
 * lists are bespoke layout surfaces on semantic design tokens, not generic Cards. */
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
    type HealthClinicalKpis,
} from '@/pages/health-clinical/components/health-clinical-shell';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Droplet, Info, Moon, Toilet, Zap, type LucideIcon } from 'lucide-react';

type FluidRow = { id: number; occurred_at: string | null; direction: string | null; fluid_type: string | null; volume_ml: number | null; client_name: string };
type SeizureRow = { id: number; occurred_at: string | null; duration_seconds: number | null; seizure_type: string | null; escalated: boolean; client_name: string };
type SleepRow = { id: number; slept_at: string | null; hours_slept: number | null; quality: string | null; interruptions: number | null; client_name: string };
type BowelRow = { id: number; occurred_at: string | null; bristol_type: number | null; client_name: string };

type Rollup = {
    stats: {
        fluid_30d: number;
        fluid_intake_ml_7d: number;
        bowel_30d: number;
        seizures_30d: number;
        seizures_escalated_30d: number;
        sleep_avg_hours_7d: number;
    };
    recent_fluid: FluidRow[];
    recent_seizures: SeizureRow[];
    recent_sleep: SleepRow[];
    recent_bowel: BowelRow[];
};

type Props = {
    rollup: Rollup;
    filters: { client_id?: string };
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    kpis: HealthClinicalKpis;
    tab_counts?: Record<string, number>;
};

const ALL_SENTINEL = '__all__';

function fmt(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—';
}

function StatCard({ icon: Icon, label, value, caption, tone = 'default' }: { icon: LucideIcon; label: string; value: string | number; caption?: string; tone?: 'default' | 'critical' }) {
    return (
        <div className="rounded-xl border border-border bg-card p-4">
            <div className="flex items-center gap-2">
                <span className={cn('grid h-8 w-8 place-items-center rounded-lg', tone === 'critical' ? 'bg-status-critical-bg text-status-critical' : 'bg-primary/10 text-primary')}>
                    <Icon className="h-4 w-4" />
                </span>
                <span className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">{label}</span>
            </div>
            <p className="mt-2 text-2xl font-bold tabular-nums">{value}</p>
            {caption ? <p className="mt-0.5 text-xs text-muted-foreground">{caption}</p> : null}
        </div>
    );
}

export default function HealthMonitoring({ rollup, filters, clients, kpis, tab_counts }: Props) {
    const setClient = (v: string) => {
        const clean = v === ALL_SENTINEL ? {} : { client_id: v };
        router.get('/health-clinical/health-monitoring', clean, { preserveState: true, replace: true });
    };
    const s = rollup.stats;

    return (
        <HealthClinicalShell activeTab="health_monitoring" kpis={kpis} tabCounts={tab_counts}>
            <div className="flex flex-wrap items-center gap-3 rounded-xl border border-primary/25 bg-primary/5 px-4 py-3">
                <Info className="h-4 w-4 shrink-0 text-primary" />
                <p className="flex-1 text-[13px] text-foreground">
                    Rolls up fluid, bowel, seizure and sleep monitoring captured on each client's profile — surfaced here cross-client for nursing oversight.
                </p>
                <div className="flex items-center gap-2">
                    <Label className="text-xs text-muted-foreground">Client</Label>
                    <Select value={filters.client_id || ALL_SENTINEL} onValueChange={setClient}>
                        <SelectTrigger className="h-8 w-48 text-xs">
                            <SelectValue placeholder="All clients" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL_SENTINEL}>All clients</SelectItem>
                            {clients.map((c) => (
                                <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard icon={Droplet} label="Fluid (30d)" value={s.fluid_30d} caption={`${s.fluid_intake_ml_7d} ml intake · 7d`} />
                <StatCard icon={Toilet} label="Bowel (30d)" value={s.bowel_30d} caption="entries" />
                <StatCard icon={Zap} label="Seizures (30d)" value={s.seizures_30d} caption={s.seizures_escalated_30d > 0 ? `${s.seizures_escalated_30d} escalated` : 'none escalated'} tone={s.seizures_escalated_30d > 0 ? 'critical' : 'default'} />
                <StatCard icon={Moon} label="Sleep avg (7d)" value={`${s.sleep_avg_hours_7d}h`} caption="hours / night" />
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base"><Zap className="h-4 w-4 text-status-warning" /> Recent seizures</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {rollup.recent_seizures.length === 0 ? (
                            <p className="py-4 text-center text-sm text-muted-foreground">No seizures recorded.</p>
                        ) : (
                            <div className="divide-y">
                                {rollup.recent_seizures.map((e) => (
                                    <div key={e.id} className="flex items-center justify-between py-2 text-sm">
                                        <div>
                                            <span className="font-medium">{e.client_name}</span>
                                            <span className="ml-2 text-xs text-muted-foreground capitalize">{(e.seizure_type ?? '').replace(/_/g, ' ')}{e.duration_seconds ? ` · ${e.duration_seconds}s` : ''}</span>
                                            {e.escalated ? <Badge variant="outline" className="ml-2 border-status-critical/40 text-[10px] text-status-critical">Escalated</Badge> : null}
                                        </div>
                                        <span className="text-xs text-muted-foreground">{fmt(e.occurred_at)}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base"><Droplet className="h-4 w-4 text-status-info" /> Recent fluid</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {rollup.recent_fluid.length === 0 ? (
                            <p className="py-4 text-center text-sm text-muted-foreground">No fluid entries recorded.</p>
                        ) : (
                            <div className="divide-y">
                                {rollup.recent_fluid.map((e) => (
                                    <div key={e.id} className="flex items-center justify-between py-2 text-sm">
                                        <div>
                                            <span className="font-medium">{e.client_name}</span>
                                            <span className="ml-2 text-xs text-muted-foreground capitalize">{e.direction}{e.fluid_type ? ` · ${e.fluid_type}` : ''}{e.volume_ml ? ` · ${e.volume_ml}ml` : ''}</span>
                                        </div>
                                        <span className="text-xs text-muted-foreground">{fmt(e.occurred_at)}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base"><Moon className="h-4 w-4 text-primary" /> Recent sleep</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {rollup.recent_sleep.length === 0 ? (
                            <p className="py-4 text-center text-sm text-muted-foreground">No sleep entries recorded.</p>
                        ) : (
                            <div className="divide-y">
                                {rollup.recent_sleep.map((e) => (
                                    <div key={e.id} className="flex items-center justify-between py-2 text-sm">
                                        <div>
                                            <span className="font-medium">{e.client_name}</span>
                                            <span className="ml-2 text-xs text-muted-foreground capitalize">{e.hours_slept != null ? `${e.hours_slept}h` : ''}{e.quality ? ` · ${e.quality}` : ''}{e.interruptions ? ` · ${e.interruptions} woke` : ''}</span>
                                        </div>
                                        <span className="text-xs text-muted-foreground">{fmt(e.slept_at)}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base"><Toilet className="h-4 w-4 text-muted-foreground" /> Recent bowel</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {rollup.recent_bowel.length === 0 ? (
                            <p className="py-4 text-center text-sm text-muted-foreground">No bowel entries recorded.</p>
                        ) : (
                            <div className="divide-y">
                                {rollup.recent_bowel.map((e) => (
                                    <div key={e.id} className="flex items-center justify-between py-2 text-sm">
                                        <div>
                                            <span className="font-medium">{e.client_name}</span>
                                            <span className="ml-2 text-xs text-muted-foreground">{e.bristol_type ? `Bristol type ${e.bristol_type}` : ''}</span>
                                        </div>
                                        <span className="text-xs text-muted-foreground">{fmt(e.occurred_at)}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </HealthClinicalShell>
    );
}
