/* eslint-disable no-restricted-syntax -- the break-glass grant cards, audit table, flagged/policy
   panels and countdown rings are custom-layout bordered surfaces (not Card/Button); the ring uses an
   inline conic-gradient of design tokens. All colours are semantic tokens. */
import { PageHero, type PageHeroBadge, type PageHeroStat } from '@/components/page';
import { EntityFilter, TabStrip, type RosterTabItem } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { RequestAccessDialog, type Approver, type ClientLite } from '@/pages/emergency/_request-dialog';
import { ReviewDialog, type ReviewRecord } from '@/pages/emergency/_review-dialog';
import { Head, router } from '@inertiajs/react';
import { Ban, Building2, Clock, Download, FileText, Fingerprint, History, MapPin, Pill, Plus, ShieldAlert, ShieldCheck, SlidersHorizontal, TriangleAlert, Zap } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { toast } from 'sonner';

type ActiveAccess = { id: number; client_id: number; client_name: string; site_name: string | null; reason: string; reason_category: string | null; cosign_label: string | null; granted_by: string | null; created_at: string | null; expires_at: string | null; can_revoke: boolean };
type AuditRow = { id: number; client_id: number; staff: string; client_name: string; site_name: string | null; reason: string; reason_category: string | null; minutes: number | null; created_at: string | null; expires_at: string | null; status: string; revoked_by: string | null; review_outcome: string | null; reviewed_by: string | null; incident_report_id: number | null; events: { action: string; detail: string | null; at: string | null }[] };
type Flagged = { type: string; key: string; severity: string; title: string; detail: string };
type Policy = { default_minutes: number; max_minutes: number; extend_minutes: number; auto_revoke: boolean; reason_required: boolean; repeat_threshold_count: number; repeat_window_days: number };
type Stats = { active: number; granted_week: number; awaiting_review: number; flagged: number };

type Props = {
    query: string;
    results: ClientLite[];
    activeAccesses: ActiveAccess[];
    auditLog: AuditRow[];
    flaggedSignals: Flagged[];
    approvers: Approver[];
    can_review: boolean;
    policy: Policy;
    stats: Stats;
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
    request_client: ClientLite | null;
    can_edit_policy: boolean;
    incidents_by_client: Record<number, { id: number; label: string; date: string | null }[]>;
};

const fmtLeft = (ms: number) => {
    if (ms <= 0) return 'Expired';
    const total = Math.floor(ms / 1000);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    return h > 0 ? `${h}h ${m}m` : `${m}:${String(s).padStart(2, '0')}`;
};
const fmtDateTime = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-NZ', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—');
const fmtMinutes = (m: number | null) => (m == null ? '—' : m < 60 ? `${m} min` : `${(m / 60) % 1 === 0 ? m / 60 : (m / 60).toFixed(1)} h`);

function csvCell(v: unknown): string { const s = v == null ? '' : String(v); return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s; }
function exportCsv(rows: AuditRow[]) {
    const head = ['Granted', 'Staff', 'Client', 'Site', 'Reason', 'Duration (min)', 'Expires', 'Status', 'Revoked by', 'Review', 'Reviewed by'];
    const lines = rows.map((r) => [fmtDateTime(r.created_at), r.staff, r.client_name, r.site_name, r.reason_category ?? r.reason, r.minutes, fmtDateTime(r.expires_at), r.status, r.revoked_by, r.review_outcome, r.reviewed_by].map(csvCell).join(','));
    const blob = new Blob([[head.join(','), ...lines].join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = `break-glass-audit-${new Date().toISOString().slice(0, 10)}.csv`; a.click(); URL.revokeObjectURL(url);
}

export default function EmergencyAccess({ query, results, activeAccesses, auditLog, flaggedSignals, approvers, can_review: canReview, policy, stats, sites, active_site: activeSite, site_brand_colour: brandColour, request_client: requestClient, can_edit_policy: canEditPolicy, incidents_by_client: incidentsByClient }: Props) {
    const [tab, setTab] = useState('active');
    const [siteFilter, setSiteFilter] = useState<number | null>(activeSite?.id ?? null);
    // Auto-open the wizard when deep-linked for a client that has no live grant yet.
    const alreadyGrantedForRequest = requestClient ? activeAccesses.some((a) => a.client_id === requestClient.id) : false;
    const [wizardOpen, setWizardOpen] = useState(() => !!requestClient && !alreadyGrantedForRequest);
    const [reviewRecord, setReviewRecord] = useState<ReviewRecord | null>(null);
    const [dismissTarget, setDismissTarget] = useState<Flagged | null>(null);
    const [dismissReason, setDismissReason] = useState('');
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => { const t = setInterval(() => setNow(Date.now()), 1000); return () => clearInterval(t); }, []);

    const onSite = (id: number | null) => { setSiteFilter(id); router.get('/emar/emergency-access', id ? { site_id: id } : {}, { preserveState: true, preserveScroll: true }); };
    const onSearch = (q: string) => router.get('/emar/emergency-access', { q, ...(siteFilter ? { site_id: siteFilter } : {}) }, { only: ['results', 'query'], preserveState: true, preserveScroll: true });
    const revoke = (a: ActiveAccess) => { if (confirm(`Revoke ${a.client_name}'s break-glass access?`)) router.delete(`/emar/clients/${a.client_id}/break-glass/${a.id}`, { preserveScroll: true }); };
    const extend = (a: ActiveAccess) => router.post(`/emar/clients/${a.client_id}/break-glass/${a.id}/extend`, {}, { preserveScroll: true });
    const submitDismiss = () => {
        if (!dismissTarget) return;
        router.post('/emar/break-glass-flags/dismiss', { type: dismissTarget.type, key: dismissTarget.key, reason: dismissReason || null }, {
            preserveScroll: true,
            onSuccess: () => { toast.success('Signal acknowledged'); setDismissTarget(null); setDismissReason(''); },
            onError: () => toast.error('Could not acknowledge signal'),
        });
    };

    const TABS: RosterTabItem[] = [
        { id: 'active', label: 'Active access', icon: ShieldAlert, tone: 'primary', badge: activeAccesses.length || undefined },
        { id: 'audit', label: 'Audit log', icon: History, tone: 'info', badge: auditLog.length || undefined },
        { id: 'flagged', label: 'Flagged', icon: TriangleAlert, tone: 'critical', badge: flaggedSignals.length || undefined },
        { id: 'policy', label: 'Policy & settings', icon: SlidersHorizontal, tone: 'primary' },
    ];
    const heroStats: PageHeroStat[] = [
        { label: 'Active now', value: stats.active, tone: stats.active > 0 ? 'warning' : 'neutral' },
        { label: `Granted · ${policy.repeat_window_days}d`, value: stats.granted_week },
        { label: 'Awaiting review', value: stats.awaiting_review, tone: stats.awaiting_review > 0 ? 'warning' : 'neutral' },
        { label: 'Flagged', value: stats.flagged, tone: stats.flagged > 0 ? 'critical' : 'neutral' },
    ];
    const heroBadges = useMemo<PageHeroBadge[]>(() => {
        const b: PageHeroBadge[] = [];
        if (stats.awaiting_review > 0) b.push({ icon: Clock, label: `${stats.awaiting_review} awaiting review`, tone: 'warning' });
        if (stats.flagged > 0) b.push({ icon: TriangleAlert, label: `${stats.flagged} flagged signal${stats.flagged === 1 ? '' : 's'}`, tone: 'critical' });
        if (policy.auto_revoke) b.push({ icon: ShieldCheck, label: 'Auto-revoke on', tone: 'success', dot: true });
        return b;
    }, [stats.awaiting_review, stats.flagged, policy.auto_revoke]);

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
                    badges={heroBadges}
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

                <TabStrip value={tab} onChange={setTab} items={TABS} ariaLabel="Emergency access views" />

                {tab === 'active' && (
                    <>
                        <div className="flex items-center gap-3">
                            <h2 className="text-base font-bold">Live grants</h2>
                            <span className="text-xs text-muted-foreground">{activeAccesses.length} active · auto-revoke on expiry</span>
                            <Button size="sm" variant="outline" className="ml-auto" onClick={() => setWizardOpen(true)}><Plus className="h-3.5 w-3.5" />New request</Button>
                        </div>
                        {activeAccesses.length === 0 ? <Empty text="No active break-glass grants." /> : (
                            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                {activeAccesses.map((a) => {
                                    const created = a.created_at ? new Date(a.created_at).getTime() : now;
                                    const expires = a.expires_at ? new Date(a.expires_at).getTime() : now;
                                    const total = Math.max(1, expires - created);
                                    const leftMs = expires - now;
                                    const pct = Math.max(0, Math.min(1, leftMs / total));
                                    const urgent = leftMs <= 600000 ? 'var(--status-critical)' : leftMs <= 1800000 ? 'var(--status-warning)' : 'var(--primary)';
                                    // Free-text detail when present; otherwise the eyebrow already names the category, so
                                    // fall back to the raw reason only for legacy rows that have no category.
                                    const reasonBody = a.reason && a.reason !== a.reason_category ? a.reason : a.reason_category ? null : a.reason;
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
                                                    <div className="mt-2 rounded-lg bg-muted/50 px-2.5 py-1.5">
                                                        {a.reason_category && <div className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">{a.reason_category}</div>}
                                                        {reasonBody && <div className="text-xs">{reasonBody}</div>}
                                                    </div>
                                                    <div className="mt-1.5 flex items-center gap-1 text-xs text-muted-foreground"><Fingerprint className="h-3 w-3" />{a.cosign_label ? `${a.cosign_label} · ` : ''}by {a.granted_by ?? 'Unknown'}</div>
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2 border-t px-4 py-2.5">
                                                <a href={`/emar/mar?client_id=${a.client_id}`} className="flex-1"><Button size="sm" className="w-full"><Pill className="h-3.5 w-3.5" />Open MAR</Button></a>
                                                {a.can_revoke && <Button size="sm" variant="outline" onClick={() => extend(a)}><Clock className="h-3.5 w-3.5" />Extend</Button>}
                                                {a.can_revoke && <Button size="sm" variant="outline" onClick={() => revoke(a)} aria-label={`Revoke ${a.client_name}'s access`} title="Revoke" className="border-status-critical/30 bg-status-critical-bg text-status-critical hover:bg-status-critical-bg/70 hover:text-status-critical"><Ban className="h-3.5 w-3.5" /></Button>}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </>
                )}

                {tab === 'audit' && (
                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <div className="flex items-center justify-between border-b bg-muted/40 px-4 py-3">
                            <div>
                                <span className="text-sm font-semibold">Break-glass audit log</span>
                                <p className="text-xs text-muted-foreground">Every activation — retained for audit, including revoked grants.</p>
                            </div>
                            <Button size="sm" variant="outline" onClick={() => exportCsv(auditLog)} disabled={auditLog.length === 0}><Download className="h-3.5 w-3.5" />Export CSV</Button>
                        </div>
                        {auditLog.length === 0 ? <Empty text="No break-glass activations recorded." /> : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[880px] text-sm">
                                    <thead><tr className="bg-muted/50 text-left text-[11px] uppercase tracking-wide text-muted-foreground"><th className="px-4 py-2.5">When</th><th className="px-4 py-2.5">Staff → client</th><th className="px-4 py-2.5">Reason</th><th className="px-4 py-2.5">Duration</th><th className="px-4 py-2.5">Status</th><th className="px-4 py-2.5">Review</th><th className="px-4 py-2.5" /></tr></thead>
                                    <tbody>
                                        {auditLog.map((r) => (
                                            <tr key={r.id} className="border-b last:border-b-0">
                                                <td className="px-4 py-3 text-muted-foreground">{fmtDateTime(r.created_at)}</td>
                                                <td className="px-4 py-3"><span className="font-medium">{r.staff}</span> <span className="text-muted-foreground">→ {r.client_name}{r.site_name ? ` · ${r.site_name}` : ''}</span></td>
                                                <td className="px-4 py-3 text-muted-foreground">{r.reason_category ?? r.reason}</td>
                                                <td className="px-4 py-3 tabular-nums text-muted-foreground">{fmtMinutes(r.minutes)}</td>
                                                <td className="px-4 py-3"><AuditStatus s={r.status} by={r.revoked_by} /></td>
                                                <td className="px-4 py-3"><ReviewState status={r.status} outcome={r.review_outcome} /></td>
                                                <td className="px-4 py-3 text-right">{canReview && r.status !== 'active' && <Button size="sm" variant="outline" onClick={() => setReviewRecord(r)}>Review</Button>}</td>
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
                            {flaggedSignals.map((f, i) => {
                                const crit = f.severity === 'critical';
                                const Icon = f.type === 'repeat' ? Zap : f.type === 'awaiting_review' ? FileText : TriangleAlert;
                                return (
                                    <div key={i} className={`flex items-start gap-3 rounded-2xl border border-l-4 bg-card p-4 shadow-sm ${crit ? 'border-l-status-critical' : 'border-l-status-warning'}`}>
                                        <span className={`grid h-9 w-9 shrink-0 place-items-center rounded-lg ${crit ? 'bg-status-critical-bg text-status-critical' : 'bg-status-warning-bg text-status-warning'}`}><Icon className="h-4 w-4" /></span>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-sm font-semibold">{f.title}</span>
                                                <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase ${crit ? 'bg-status-critical-bg text-status-critical' : 'bg-status-warning-bg text-status-warning'}`}>{f.severity}</span>
                                            </div>
                                            <p className="mt-1 text-sm text-muted-foreground">{f.detail}</p>
                                            <div className="mt-3 flex flex-wrap gap-2">
                                                <Button size="sm" variant="outline" onClick={() => setTab('audit')}>Review in audit log</Button>
                                                {canReview && <Button size="sm" variant="ghost" onClick={() => setDismissTarget(f)}>Acknowledge</Button>}
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )
                )}

                {tab === 'policy' && <PolicyEditor policy={policy} canEdit={canEditPolicy} />}
            </div>

            {wizardOpen && <RequestAccessDialog results={results} query={query} approvers={approvers} prefillClient={requestClient} onSearch={onSearch} onClose={() => setWizardOpen(false)} />}
            {reviewRecord && <ReviewDialog record={reviewRecord} incidents={incidentsByClient[reviewRecord.client_id] ?? []} onClose={() => setReviewRecord(null)} />}
            <Dialog open={!!dismissTarget} onOpenChange={(o) => { if (!o) { setDismissTarget(null); setDismissReason(''); } }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Acknowledge signal</DialogTitle>
                        <DialogDescription>{dismissTarget?.detail}</DialogDescription>
                    </DialogHeader>
                    <textarea
                        value={dismissReason}
                        onChange={(e) => setDismissReason(e.target.value)}
                        rows={3}
                        placeholder="Why is this acceptable? (optional — recorded against the acknowledgement)"
                        className="w-full rounded-lg border border-input bg-background p-2.5 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    />
                    <p className="text-xs text-muted-foreground">This hides the signal until newer break-glass activity appears.</p>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => { setDismissTarget(null); setDismissReason(''); }}>Cancel</Button>
                        <Button onClick={submitDismiss}>Acknowledge &amp; resolve</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function Empty({ text }: { text: string }) {
    return <div className="rounded-2xl border border-dashed bg-card px-5 py-12 text-center text-sm text-muted-foreground">{text}</div>;
}
function AuditStatus({ s, by }: { s: string; by: string | null }) {
    const cls = s === 'active' ? 'bg-status-success-bg text-status-success' : s === 'revoked' ? 'bg-status-critical-bg text-status-critical' : 'bg-muted text-muted-foreground';
    return <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize ${cls}`}><span className="h-1.5 w-1.5 rounded-full bg-current" />{s}{s === 'revoked' && by ? ` · ${by}` : ''}</span>;
}
function ReviewState({ status, outcome }: { status: string; outcome: string | null }) {
    if (status === 'active') return <span className="text-xs text-muted-foreground">—</span>;
    if (outcome === 'justified') return <span className="rounded-full bg-status-success-bg px-2 py-0.5 text-[11px] font-semibold text-status-success">Justified</span>;
    if (outcome === 'not_justified') return <span className="rounded-full bg-status-critical-bg px-2 py-0.5 text-[11px] font-semibold text-status-critical">Not justified</span>;
    return <span className="rounded-full bg-status-warning-bg px-2 py-0.5 text-[11px] font-semibold text-status-warning">Pending</span>;
}
function PolicyCard({ icon: Icon, title, children }: { icon: typeof Clock; title: string; children: React.ReactNode }) {
    return <div className="rounded-2xl border bg-card p-4 shadow-sm"><div className="mb-3 flex items-center gap-2 text-sm font-semibold"><span className="flex h-7 w-7 items-center justify-center rounded-lg bg-accent text-primary"><Icon className="h-4 w-4" /></span>{title}</div><div className="flex flex-col gap-1">{children}</div></div>;
}
function PolicyRow({ label, value, on }: { label: string; value: string; on?: boolean }) {
    return <div className="flex items-center justify-between border-b py-2 text-sm last:border-b-0"><span className="text-muted-foreground">{label}</span><span className={`font-medium ${on ? 'text-status-success' : ''}`}>{value}</span></div>;
}
function NumberField({ label, hint, value, onChange, disabled, suffix }: { label: string; hint?: string; value: number; onChange: (v: number) => void; disabled?: boolean; suffix?: string }) {
    return (
        <label className="flex items-center justify-between gap-3 border-b py-2.5 text-sm last:border-b-0">
            <span className="min-w-0">
                <span className="font-medium">{label}</span>
                {hint && <span className="block text-xs text-muted-foreground">{hint}</span>}
            </span>
            <span className="flex shrink-0 items-center gap-1.5">
                <Input type="number" min={1} value={value} disabled={disabled} onChange={(e) => onChange(Math.max(0, Math.round(Number(e.target.value) || 0)))} className="h-8 w-20 text-right tabular-nums" />
                {suffix && <span className="w-9 text-xs text-muted-foreground">{suffix}</span>}
            </span>
        </label>
    );
}
function TogglePill({ label, on, onChange, disabled }: { label: string; on: boolean; onChange: (v: boolean) => void; disabled?: boolean }) {
    return (
        <div className="flex items-center justify-between border-b py-2.5 text-sm last:border-b-0">
            <span className="font-medium">{label}</span>
            <button type="button" role="switch" aria-checked={on} aria-label={label} disabled={disabled} onClick={() => onChange(!on)} className={`relative h-6 w-[42px] shrink-0 rounded-full transition-colors ${on ? 'bg-status-success' : 'bg-muted-foreground/30'} ${disabled ? 'cursor-not-allowed opacity-50' : ''}`}>
                <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-card shadow-sm transition-all ${on ? 'left-[18px]' : 'left-0.5'}`} />
            </button>
        </div>
    );
}
function PolicyEditor({ policy, canEdit }: { policy: Policy; canEdit: boolean }) {
    const [form, setForm] = useState(policy);
    const [saving, setSaving] = useState(false);
    const dirty = useMemo(() => JSON.stringify(form) !== JSON.stringify(policy), [form, policy]);
    const defaultExceedsMax = form.default_minutes > form.max_minutes;
    const set = (patch: Partial<Policy>) => setForm((f) => ({ ...f, ...patch }));

    const save = () => {
        setSaving(true);
        router.put(
            '/emar/break-glass-policy',
            {
                default_minutes: form.default_minutes,
                max_minutes: form.max_minutes,
                extend_minutes: form.extend_minutes,
                reason_required: form.reason_required,
                repeat_threshold_count: form.repeat_threshold_count,
                repeat_window_days: form.repeat_window_days,
            },
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Break-glass policy updated'),
                onError: (e) => toast.error((Object.values(e)[0] as string) || 'Could not save policy'),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <PolicyCard icon={Clock} title="Access duration">
                <NumberField label="Default duration" hint="Pre-filled when access is requested." value={form.default_minutes} suffix="min" disabled={!canEdit} onChange={(v) => set({ default_minutes: v })} />
                <NumberField label="Maximum duration" hint="Hard cap, including extensions." value={form.max_minutes} suffix="min" disabled={!canEdit} onChange={(v) => set({ max_minutes: v })} />
                <NumberField label="Per-extension" hint="Added each time a grant is extended." value={form.extend_minutes} suffix="min" disabled={!canEdit} onChange={(v) => set({ extend_minutes: v })} />
                {defaultExceedsMax && <p className="pt-2 text-xs font-medium text-status-critical">Default duration cannot exceed the maximum.</p>}
            </PolicyCard>
            <PolicyCard icon={ShieldCheck} title="Oversight & flagging">
                <TogglePill label="Reason required" on={form.reason_required} disabled={!canEdit} onChange={(v) => set({ reason_required: v })} />
                <NumberField label="Flag repeat use after" hint="Activations by one staff member…" value={form.repeat_threshold_count} suffix="times" disabled={!canEdit} onChange={(v) => set({ repeat_threshold_count: v })} />
                <NumberField label="…within" value={form.repeat_window_days} suffix="days" disabled={!canEdit} onChange={(v) => set({ repeat_window_days: v })} />
            </PolicyCard>
            <PolicyCard icon={ShieldCheck} title="Always enforced">
                <PolicyRow label="Auto-revoke on expiry" value="On" on />
                <PolicyRow label="Append-only audit" value="Yes — retained" on />
                <PolicyRow label="Post-event review" value="Justified / not justified" on />
            </PolicyCard>
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border bg-muted/30 p-4 lg:col-span-2">
                <p className="text-xs text-muted-foreground">{canEdit ? 'Changes apply org-wide and take effect on the next grant, extension and flag check.' : 'Only administrators can change the break-glass policy.'}</p>
                {canEdit && (
                    <Button onClick={save} disabled={!dirty || saving || defaultExceedsMax}>
                        {saving ? 'Saving…' : 'Save policy'}
                    </Button>
                )}
            </div>
        </div>
    );
}
