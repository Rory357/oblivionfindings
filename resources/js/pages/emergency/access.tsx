/* eslint-disable no-restricted-syntax -- the break-glass grant cards, audit table, flagged/policy
   panels and countdown rings are custom-layout bordered surfaces (not Card/Button); the ring uses an
   inline conic-gradient of design tokens. All colours are semantic tokens. */
import { PageHero, type PageHeroStat } from '@/components/page';
import { EntityFilter, TabStrip, type RosterTabItem } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { RequestAccessDialog, type ClientLite } from '@/pages/emergency/_request-dialog';
import { Head, router } from '@inertiajs/react';
import { Ban, Building2, Clock, Download, Fingerprint, History, MapPin, Pill, Plus, ShieldAlert, ShieldCheck, SlidersHorizontal, TriangleAlert } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type ActiveAccess = { id: number; client_id: number; client_name: string; site_name: string | null; reason: string; granted_by: string | null; created_at: string | null; expires_at: string | null; can_revoke: boolean };
type AuditRow = { id: number; staff: string; client_name: string; site_name: string | null; reason: string; created_at: string | null; expires_at: string | null; status: string; revoked_by: string | null };
type Flagged = { type: string; severity: string; title: string; detail: string };
type Policy = { default_minutes: number; max_minutes: number; auto_revoke: boolean; reason_required: boolean };
type Stats = { active: number; granted_week: number; revoked_week: number; flagged: number };

type Props = {
    query: string;
    results: ClientLite[];
    activeAccesses: ActiveAccess[];
    auditLog: AuditRow[];
    flaggedSignals: Flagged[];
    policy: Policy;
    stats: Stats;
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
};

const fmtLeft = (ms: number) => { if (ms <= 0) return 'Expired'; const m = Math.floor(ms / 60000); const s = Math.floor((ms % 60000) / 1000); return `${m}:${String(s).padStart(2, '0')}`; };
const fmtDateTime = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-NZ', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—');
const initials = (n: string) => n.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || '?';

function csvCell(v: unknown): string { const s = v == null ? '' : String(v); return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s; }
function exportCsv(rows: AuditRow[]) {
    const head = ['Granted', 'Staff', 'Client', 'Site', 'Reason', 'Expires', 'Status', 'Revoked by'];
    const lines = rows.map((r) => [fmtDateTime(r.created_at), r.staff, r.client_name, r.site_name, r.reason, fmtDateTime(r.expires_at), r.status, r.revoked_by].map(csvCell).join(','));
    const blob = new Blob([[head.join(','), ...lines].join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = `break-glass-audit-${new Date().toISOString().slice(0, 10)}.csv`; a.click(); URL.revokeObjectURL(url);
}

export default function EmergencyAccess({ query, results, activeAccesses, auditLog, flaggedSignals, policy, stats, sites, active_site: activeSite, site_brand_colour: brandColour }: Props) {
    const [tab, setTab] = useState('active');
    const [siteFilter, setSiteFilter] = useState<number | null>(activeSite?.id ?? null);
    const [wizardOpen, setWizardOpen] = useState(false);
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => { const t = setInterval(() => setNow(Date.now()), 1000); return () => clearInterval(t); }, []);

    const onSite = (id: number | null) => { setSiteFilter(id); router.get('/emar/emergency-access', id ? { site_id: id } : {}, { preserveState: true, preserveScroll: true }); };
    const onSearch = (q: string) => router.get('/emar/emergency-access', { q, ...(siteFilter ? { site_id: siteFilter } : {}) }, { only: ['results', 'query'], preserveState: true, preserveScroll: true });
    const revoke = (a: ActiveAccess) => { if (confirm(`Revoke ${a.client_name}'s break-glass access?`)) router.delete(`/emar/clients/${a.client_id}/break-glass/${a.id}`, { preserveScroll: true }); };

    const TABS: RosterTabItem[] = [
        { id: 'active', label: 'Active access', icon: ShieldAlert, tone: 'primary', badge: activeAccesses.length || undefined },
        { id: 'audit', label: 'Audit log', icon: History, tone: 'info', badge: auditLog.length || undefined },
        { id: 'flagged', label: 'Flagged', icon: TriangleAlert, tone: 'critical', badge: flaggedSignals.length || undefined },
        { id: 'policy', label: 'Policy & settings', icon: SlidersHorizontal, tone: 'primary' },
    ];
    const heroStats: PageHeroStat[] = [
        { label: 'Active now', value: stats.active, tone: stats.active > 0 ? 'warning' : 'neutral' },
        { label: 'Granted · 7d', value: stats.granted_week },
        { label: 'Revoked · 7d', value: stats.revoked_week },
        { label: 'Flagged', value: stats.flagged, tone: stats.flagged > 0 ? 'critical' : 'neutral' },
    ];

    return (
        <AppLayout breadcrumbs={[{ title: 'eMAR', href: '/emar' }, { title: 'Emergency Access', href: '/emar/emergency-access' }]}>
            <Head title="eMAR - Emergency Access" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={ShieldAlert}
                    title={
                        <span>
                            <span className="flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-wide text-primary-foreground/80">
                                <span aria-hidden className="relative inline-flex h-2 w-2">
                                    <span className="absolute inset-0 animate-ping rounded-full bg-status-success/70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                </span>
                                Live · break-glass monitoring
                            </span>
                            <span className="mt-1 block text-[26px] font-bold leading-tight">
                                Emergency access for{' '}
                                <span className="border-b-2 border-primary-foreground/40">{activeSite?.name ?? 'your services'}</span>
                            </span>
                        </span>
                    }
                    description="Temporary, time-limited medication access for clients you are not assigned to. Every activation needs a reason, expires automatically, and is logged for audit."
                    stats={heroStats}
                    meta={[
                        { icon: Clock, label: `${policy.default_minutes} min default · ${Math.round(policy.max_minutes / 60)} h max` },
                        { icon: Building2, label: `${sites.length} site${sites.length === 1 ? '' : 's'} covered` },
                        { icon: History, label: 'Append-only audit · retained' },
                    ]}
                    actions={
                        <>
                            <Button className="bg-primary-foreground text-primary hover:bg-primary-foreground/90" onClick={() => setWizardOpen(true)}><Plus className="h-4 w-4" />Request emergency access</Button>
                            <Button variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20" onClick={() => exportCsv(auditLog)} disabled={auditLog.length === 0}><Download className="h-4 w-4" />Export audit</Button>
                        </>
                    }
                    footer={
                        sites.length > 0 ? (
                            <div className="flex items-center justify-end py-3">
                                <EntityFilter label="Site" allLabel="All sites" items={sites} value={siteFilter} onChange={onSite} onDark />
                            </div>
                        ) : undefined
                    }
                />

                {stats.flagged > 0 && (
                    <div className="flex items-center justify-between gap-3 rounded-xl border border-status-critical/30 bg-status-critical-bg/60 px-4 py-3">
                        <span className="flex items-center gap-2 text-sm font-medium text-status-critical"><TriangleAlert className="h-4 w-4" />{stats.flagged} misuse signal{stats.flagged === 1 ? '' : 's'} flagged — review break-glass usage.</span>
                        <Button size="sm" variant="outline" onClick={() => setTab('flagged')}>Review</Button>
                    </div>
                )}

                <TabStrip value={tab} onChange={setTab} items={TABS} ariaLabel="Emergency access views" />

                {tab === 'active' && (
                    activeAccesses.length === 0 ? <Empty text="No active break-glass grants." /> : (
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {activeAccesses.map((a) => {
                                const created = a.created_at ? new Date(a.created_at).getTime() : now;
                                const expires = a.expires_at ? new Date(a.expires_at).getTime() : now;
                                const total = Math.max(1, expires - created);
                                const leftMs = expires - now;
                                const pct = Math.max(0, Math.min(1, leftMs / total));
                                const urgent = leftMs <= 600000 ? 'var(--status-critical)' : leftMs <= 1800000 ? 'var(--status-warning)' : 'var(--primary)';
                                return (
                                    <div key={a.id} className={`overflow-hidden rounded-2xl border bg-card shadow-sm ${leftMs <= 600000 ? 'border-status-critical/50' : ''}`}>
                                        <div className="h-1" style={{ background: urgent }} />
                                        <div className="flex items-start gap-3 p-4">
                                            <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full" style={{ background: `conic-gradient(${urgent} ${pct * 360}deg, var(--muted) 0)` }}>
                                                <span className="flex h-11 w-11 items-center justify-center rounded-full bg-card text-[11px] font-bold tabular-nums">{Math.round(pct * 100)}%</span>
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="truncate font-semibold">{a.client_name}</span>
                                                    <span className="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs font-semibold tabular-nums" style={{ color: urgent }}>{fmtLeft(leftMs)} left</span>
                                                </div>
                                                <div className="flex items-center gap-1 text-xs text-muted-foreground"><MapPin className="h-3 w-3" />{a.site_name ?? '—'} · granted {fmtDateTime(a.created_at)}</div>
                                                <div className="mt-2 rounded-lg bg-muted/50 px-2.5 py-1.5 text-xs"><span className="font-medium">{a.reason}</span></div>
                                                <div className="mt-1.5 flex items-center gap-1 text-xs text-muted-foreground"><Fingerprint className="h-3 w-3" />by {a.granted_by ?? 'Unknown'}</div>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2 border-t px-4 py-2.5">
                                            <a href={`/emar/mar?client_id=${a.client_id}`}><Button size="sm"><Pill className="h-3.5 w-3.5" />Open MAR</Button></a>
                                            {a.can_revoke && <Button size="sm" variant="ghost" onClick={() => revoke(a)} title="Revoke"><Ban className="h-3.5 w-3.5 text-status-critical" /></Button>}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )
                )}

                {tab === 'audit' && (
                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <div className="flex items-center justify-between border-b bg-muted/40 px-4 py-3">
                            <span className="text-sm font-semibold">Break-glass audit log</span>
                            <Button size="sm" variant="outline" onClick={() => exportCsv(auditLog)} disabled={auditLog.length === 0}><Download className="h-3.5 w-3.5" />Export CSV</Button>
                        </div>
                        {auditLog.length === 0 ? <Empty text="No break-glass activations recorded." /> : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[820px] text-sm">
                                    <thead><tr className="bg-muted/50 text-left text-[11px] uppercase tracking-wide text-muted-foreground"><th className="px-4 py-2.5">When</th><th className="px-4 py-2.5">Staff → client</th><th className="px-4 py-2.5">Reason</th><th className="px-4 py-2.5">Expires</th><th className="px-4 py-2.5">Status</th></tr></thead>
                                    <tbody>
                                        {auditLog.map((r) => (
                                            <tr key={r.id} className="border-b last:border-b-0">
                                                <td className="px-4 py-3 text-muted-foreground">{fmtDateTime(r.created_at)}</td>
                                                <td className="px-4 py-3"><span className="font-medium">{r.staff}</span> <span className="text-muted-foreground">→ {r.client_name}{r.site_name ? ` · ${r.site_name}` : ''}</span></td>
                                                <td className="px-4 py-3 text-muted-foreground">{r.reason}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{fmtDateTime(r.expires_at)}</td>
                                                <td className="px-4 py-3"><AuditStatus s={r.status} by={r.revoked_by} /></td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}

                {tab === 'flagged' && (
                    flaggedSignals.length === 0 ? <Empty text="No misuse signals — break-glass usage looks healthy." /> : (
                        <div className="flex flex-col gap-3">
                            {flaggedSignals.map((f, i) => (
                                <div key={i} className="rounded-2xl border-l-4 border-l-status-critical border bg-card p-4 shadow-sm">
                                    <div className="flex items-center gap-2"><TriangleAlert className="h-4 w-4 text-status-critical" /><span className="text-sm font-semibold">{f.title}</span><span className="rounded-full bg-status-critical-bg px-2 py-0.5 text-[10px] font-semibold text-status-critical">{f.severity}</span></div>
                                    <div className="mt-1.5 text-sm text-muted-foreground">{f.detail}</div>
                                    <div className="mt-3"><Button size="sm" variant="outline" onClick={() => setTab('audit')}>Review in audit log</Button></div>
                                </div>
                            ))}
                        </div>
                    )
                )}

                {tab === 'policy' && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <PolicyCard icon={Clock} title="Access duration">
                            <PolicyRow label="Default duration" value={`${policy.default_minutes} minutes`} />
                            <PolicyRow label="Maximum duration" value={`${Math.round(policy.max_minutes / 60)} hours`} />
                            <PolicyRow label="Auto-revoke on expiry" value={policy.auto_revoke ? 'On' : 'Off'} on={policy.auto_revoke} />
                        </PolicyCard>
                        <PolicyCard icon={ShieldCheck} title="Authorisation & oversight">
                            <PolicyRow label="Reason required" value={policy.reason_required ? 'Yes' : 'No'} on={policy.reason_required} />
                            <PolicyRow label="Append-only audit" value="Yes — retained" on />
                            <PolicyRow label="Repeat-misuse flagging" value="≥ 4 activations / 7 days" on />
                        </PolicyCard>
                        <div className="rounded-2xl border bg-muted/30 p-4 text-xs text-muted-foreground lg:col-span-2">Policy is enforced server-side (duration cap, auto-expiry, append-only audit). Editable policy controls and co-sign / post-event review are a planned governance follow-up.</div>
                    </div>
                )}
            </div>

            {wizardOpen && <RequestAccessDialog results={results} query={query} onSearch={onSearch} onClose={() => setWizardOpen(false)} />}
        </AppLayout>
    );
}

function Empty({ text }: { text: string }) {
    return <div className="rounded-2xl border border-dashed bg-card px-5 py-12 text-center text-sm text-muted-foreground">{text}</div>;
}
function AuditStatus({ s, by }: { s: string; by: string | null }) {
    const cls = s === 'active' ? 'bg-status-success-bg text-status-success' : s === 'revoked' ? 'bg-status-critical-bg text-status-critical' : 'bg-muted text-muted-foreground';
    return <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize ${cls}`}>{s}{s === 'revoked' && by ? ` · ${by}` : ''}</span>;
}
function PolicyCard({ icon: Icon, title, children }: { icon: typeof Clock; title: string; children: React.ReactNode }) {
    return <div className="rounded-2xl border bg-card p-4 shadow-sm"><div className="mb-3 flex items-center gap-2 text-sm font-semibold"><span className="flex h-7 w-7 items-center justify-center rounded-lg bg-accent text-primary"><Icon className="h-4 w-4" /></span>{title}</div><div className="flex flex-col gap-1">{children}</div></div>;
}
function PolicyRow({ label, value, on }: { label: string; value: string; on?: boolean }) {
    return <div className="flex items-center justify-between border-b py-2 text-sm last:border-b-0"><span className="text-muted-foreground">{label}</span><span className={`font-medium ${on ? 'text-status-success' : ''}`}>{value}</span></div>;
}
