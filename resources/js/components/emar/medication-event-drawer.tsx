/* eslint-disable no-restricted-syntax -- the audit detail drawer is a read-only traceability surface
   (custom-layout rail + scroll-spy sections + cross-link chips, not Card/Button); all colours are tokens. */
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { SummaryRow } from '@/components/meds/wizard-shell';
import { WIZARD_FOOTER_CLASS, WIZARD_RAIL_CLASS } from '@/components/wizard/primitives';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import {
    AlertOctagon,
    AlertTriangle,
    ArrowRight,
    Check,
    ClipboardCheck,
    Download,
    Eye,
    FileText,
    Fingerprint,
    Flag,
    Link2,
    Lock,
    Package,
    Pencil,
    Pill,
    Shield,
    Trash2,
    User,
    Users,
    X,
    XCircle,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

export type AuditEvent = {
    id: string;
    event_type: string;
    timestamp: string;
    description: string;
    performed_by: string | null;
    witness: string | null;
    witness_required: boolean;
    client_id: number | null;
    client_name: string;
    details: Record<string, unknown>;
    category: string;
    source: string;
    site_id: number | null;
    site_name: string | null;
    outcome: string | null;
    flags: string[];
};

type Change = { field: string; from: string; to: string };
type Integrity = {
    backed: boolean;
    note: string | null;
    recorded_at: string | null;
    device: string | null;
    ip_address: string | null;
    edited: string | null;
    edit_count: number;
    fingerprint: string | null;
};

type Meta = { label: string; icon: LucideIcon; cls: string };
export const EVENT_META: Record<string, Meta> = {
    dose_administered: { label: 'Administered', icon: Check, cls: 'bg-status-success-bg text-status-success' },
    dose_refused: { label: 'Refused', icon: XCircle, cls: 'bg-status-warning-bg text-status-warning' },
    dose_missed: { label: 'Missed', icon: AlertTriangle, cls: 'bg-status-critical-bg text-status-critical' },
    omission: { label: 'Omission', icon: AlertTriangle, cls: 'bg-status-critical-bg text-status-critical' },
    medication_started: { label: 'Started', icon: Pill, cls: 'bg-status-info-bg text-status-info' },
    medication_ceased: { label: 'Ceased', icon: XCircle, cls: 'bg-muted text-muted-foreground' },
    medication_changed: { label: 'Changed', icon: Pencil, cls: 'bg-status-info-bg text-status-info' },
    cd_given: { label: 'CD given', icon: Lock, cls: 'bg-accent text-primary' },
    cd_received: { label: 'CD received', icon: Package, cls: 'bg-accent text-primary' },
    cd_wasted: { label: 'CD wasted', icon: Trash2, cls: 'bg-status-warning-bg text-status-warning' },
    cd_adjustment: { label: 'CD adjustment', icon: Pencil, cls: 'bg-accent text-primary' },
    cd_balance_check: { label: 'CD balance', icon: Shield, cls: 'bg-accent text-primary' },
    destruction: { label: 'Destroyed', icon: Trash2, cls: 'bg-status-warning-bg text-status-warning' },
    stock_received: { label: 'Stock received', icon: Package, cls: 'bg-status-success-bg text-status-success' },
    prescriber_order: { label: 'Prescriber order', icon: FileText, cls: 'bg-status-info-bg text-status-info' },
    review_completed: { label: 'Review', icon: ClipboardCheck, cls: 'bg-status-info-bg text-status-info' },
    medication_error: { label: 'Error', icon: AlertOctagon, cls: 'bg-status-critical-bg text-status-critical' },
};
export const eventMeta = (t: string): Meta => EVENT_META[t] ?? { label: t.replace(/_/g, ' '), icon: FileText, cls: 'bg-muted text-muted-foreground' };

export const FLAG_META: Record<string, { label: string; cls: string }> = {
    missing_witness: { label: 'No 2nd signature', cls: 'bg-status-critical-bg text-status-critical' },
    omission: { label: 'MAR omission', cls: 'bg-status-critical-bg text-status-critical' },
    no_actor: { label: 'Not attributed', cls: 'bg-status-warning-bg text-status-warning' },
    no_reason: { label: 'No reason code', cls: 'bg-status-warning-bg text-status-warning' },
};

const fmtDateTime = (iso: string) => new Date(iso).toLocaleString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
const CATEGORY_LINK: Record<string, { href: string; label: string }> = {
    doses: { href: '/emar/mar', label: 'MAR chart' },
    controlled: { href: '/emar/controlled', label: 'CD register' },
    clinical: { href: '/emar/reviews', label: 'Reviews' },
    stock: { href: '/emar/destructions', label: 'Destruction register' },
    errors: { href: '/emar/errors', label: 'Error register' },
};

const HIDDEN_DETAIL_KEYS = new Set(['changes', 'scheduled_for']);

/** The primary cross-link for an event (MAR chart / CD register / Reviews / …),
 *  shared by the drawer footer and the row context menu so both stay in sync. */
export const eventPrimaryLink = (event: AuditEvent): { href: string; label: string } =>
    CATEGORY_LINK[event.category] ?? { href: '/emar', label: 'eMAR' };

export function MedicationEventDrawer({ event, onClose, initialSection }: { event: AuditEvent; onClose: () => void; initialSection?: string }) {
    const meta = eventMeta(event.event_type);
    const Icon = meta.icon;
    const changes = (event.details?.changes as Change[] | undefined) ?? [];
    const detailRows = Object.entries(event.details ?? {}).filter(
        ([k, v]) => !HIDDEN_DETAIL_KEYS.has(k) && v !== null && v !== undefined && v !== '',
    );
    const link = CATEGORY_LINK[event.category];
    const primaryHref = event.category === 'doses' ? '/emar/mar' : link?.href ?? '/emar';
    const resolveHref = event.flags.includes('missing_witness') ? '/emar/controlled' : primaryHref;
    const isGap = event.flags.length > 0;

    const sections = [
        { key: 'what', label: 'What happened', icon: FileText },
        { key: 'people', label: 'People & sign-off', icon: Users },
        ...(changes.length > 0 ? [{ key: 'changes', label: 'Before → after', icon: Pencil }] : []),
        { key: 'integrity', label: 'Device & integrity', icon: Fingerprint },
        { key: 'linked', label: 'Linked records', icon: Link2 },
    ];

    const bodyRef = useRef<HTMLDivElement>(null);
    const sectionEls = useRef<Record<string, HTMLDivElement | null>>({});
    const [active, setActive] = useState(initialSection ?? 'what');
    const [integrity, setIntegrity] = useState<Integrity | null>(null);
    const [flagging, setFlagging] = useState(false);
    const [verifying, setVerifying] = useState(false);
    const [flash, setFlash] = useState<string | null>(null);
    const flashTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    useEffect(() => () => { if (flashTimer.current) clearTimeout(flashTimer.current); }, []);

    // Lazy-load the integrity panel from the AuditLog-backed endpoint.
    useEffect(() => {
        setIntegrity(null);
        let cancelled = false;
        fetch(`/emar/audit/event/${encodeURIComponent(event.id)}/integrity`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => { if (!cancelled) setIntegrity(d); })
            .catch(() => { if (!cancelled) setIntegrity(null); });
        return () => { cancelled = true; };
    }, [event.id]);

    // Scroll-spy: highlight the section nearest the top of the scroll container.
    useEffect(() => {
        const body = bodyRef.current;
        if (!body) return;
        const onScroll = () => {
            const top = body.scrollTop + 96;
            let cur = sections[0].key;
            for (const s of sections) {
                const el = sectionEls.current[s.key];
                if (el && el.offsetTop <= top) cur = s.key;
            }
            setActive(cur);
        };
        body.addEventListener('scroll', onScroll, { passive: true });
        return () => body.removeEventListener('scroll', onScroll);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [event.id, changes.length]);

    const goToSection = (key: string) => {
        const el = sectionEls.current[key];
        const body = bodyRef.current;
        // Scroll only when there's room — many events are short and fit without a
        // scrollbar — but always flash the target section so the click is visibly
        // acknowledged regardless.
        if (el && body && body.scrollHeight > body.clientHeight + 2) {
            body.scrollTo({ top: Math.max(0, el.offsetTop - 8), behavior: 'smooth' });
        }
        setActive(key);
        setFlash(key);
        if (flashTimer.current) clearTimeout(flashTimer.current);
        flashTimer.current = setTimeout(() => setFlash(null), 1300);
    };

    // When opened from a "Verify integrity" affordance, focus the integrity panel
    // on mount (the drawer is keyed per open, so this runs once per opening).
    useEffect(() => {
        if (initialSection && initialSection !== 'what') goToSection(initialSection);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const onFlag = () => {
        setFlagging(true);
        router.post(
            `/emar/audit/event/${encodeURIComponent(event.id)}/flag`,
            { flag: event.flags[0] ?? null, severity: 'minor' },
            {
                preserveScroll: true,
                onSuccess: () => { toast.success('Flagged for investigation'); onClose(); },
                onError: () => toast.error('Could not flag this record.'),
                onFinish: () => setFlagging(false),
            },
        );
    };

    // Read-only integrity re-check: focus the integrity panel and re-fetch its
    // tamper-evidence fingerprint from the append-only audit log (no mutation).
    const verifyIntegrity = () => {
        goToSection('integrity');
        setVerifying(true);
        setIntegrity(null);
        fetch(`/emar/audit/event/${encodeURIComponent(event.id)}/integrity`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((d: Integrity | null) => {
                setIntegrity(d);
                toast.success(d?.backed ? 'Integrity re-checked' : 'No integrity record for this event');
            })
            .catch(() => { setIntegrity(null); toast.error('Could not verify integrity'); })
            .finally(() => setVerifying(false));
    };

    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent
                className="overflow-hidden p-0 [&>button]:hidden"
                style={{ maxWidth: 'min(94vw, 1040px)', width: 'min(94vw, 1040px)' }}
            >
                <DialogTitle className="sr-only">{meta.label} — audit record</DialogTitle>
                <DialogDescription className="sr-only">{event.description}</DialogDescription>

                <div className="flex h-[min(90vh,820px)] min-h-0 overflow-hidden">
                    {/* ── Section rail ── */}
                    <aside className={WIZARD_RAIL_CLASS}>
                        <div className="mb-3 flex items-center gap-2.5">
                            <span className={cn('grid h-9 w-9 place-items-center rounded-lg', meta.cls)}>
                                <Icon className="h-5 w-5" />
                            </span>
                            <div className="min-w-0">
                                <div className="truncate text-sm font-bold leading-tight">{meta.label}</div>
                                <div className="truncate text-[11px] text-muted-foreground">{event.source} · {event.id}</div>
                            </div>
                        </div>

                        {sections.map((s) => {
                            const SIcon = s.icon;
                            const isActive = active === s.key;
                            return (
                                <button
                                    key={s.key}
                                    type="button"
                                    onClick={() => goToSection(s.key)}
                                    className={cn(
                                        'flex items-center gap-2.5 rounded-md p-2 text-left text-[13px] font-semibold transition-colors',
                                        isActive ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-sidebar-accent',
                                    )}
                                >
                                    <SIcon className="h-4 w-4 shrink-0" />
                                    {s.label}
                                </button>
                            );
                        })}

                        <div className="mt-auto pt-4">
                            <div className="flex items-center gap-2 rounded-lg bg-status-success-bg px-3 py-2 text-[11px] font-semibold text-status-success">
                                <Shield className="h-3.5 w-3.5 shrink-0" />
                                Append-only source record
                            </div>
                        </div>
                    </aside>

                    {/* ── Main column ── */}
                    <div className="flex min-h-0 min-w-0 flex-1 flex-col">
                        <header className="flex shrink-0 items-start justify-between gap-3 border-b border-border px-5 py-3.5">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className={cn('flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold', meta.cls)}>
                                        <Icon className="h-3.5 w-3.5" />{meta.label}
                                    </span>
                                    <span className="text-xs text-muted-foreground">{fmtDateTime(event.timestamp)}</span>
                                    {event.flags.map((f) => (
                                        <span key={f} className={cn('rounded-full px-2 py-0.5 text-[11px] font-semibold', FLAG_META[f]?.cls ?? 'bg-muted text-muted-foreground')}>
                                            {FLAG_META[f]?.label ?? f}
                                        </span>
                                    ))}
                                </div>
                                <h2 className="mt-1.5 text-[19px] font-bold leading-snug">{event.description}</h2>
                            </div>
                            <button type="button" onClick={onClose} aria-label="Close" className="grid h-8 w-8 shrink-0 place-items-center rounded-md text-muted-foreground hover:bg-muted">
                                <X className="h-5 w-5" />
                            </button>
                        </header>

                        <div ref={bodyRef} className="relative min-h-0 flex-1 overflow-y-auto overflow-x-hidden px-6 py-5">
                            {/* What happened */}
                            <Section refCb={(el) => (sectionEls.current.what = el)} flash={flash === 'what'} title="What happened">
                                {event.flags.includes('missing_witness') && (
                                    <div className="mb-3 rounded-lg border border-status-critical/30 bg-status-critical-bg/60 px-3 py-2 text-xs text-status-critical">
                                        Controlled-drug transaction without a recorded second signature — investigate and countersign in the CD register.
                                    </div>
                                )}
                                {event.flags.includes('omission') && (
                                    <div className="mb-3 rounded-lg border border-dashed border-status-critical/40 bg-status-critical-bg/50 px-3 py-2 text-xs text-status-critical">
                                        A scheduled dose was not recorded — a MAR omission must be reconciled with an outcome and reason.
                                    </div>
                                )}
                                <div className="rounded-lg border px-4">
                                    <SummaryRow label="Client" value={event.client_name} />
                                    {event.site_name && <SummaryRow label="Site" value={event.site_name} />}
                                    {event.outcome && <SummaryRow label="Outcome" value={String(event.outcome)} />}
                                    {detailRows.map(([k, v]) => <SummaryRow key={k} label={k.replace(/_/g, ' ')} value={String(v)} />)}
                                </div>
                            </Section>

                            {/* People & sign-off */}
                            <Section refCb={(el) => (sectionEls.current.people = el)} flash={flash === 'people'} title="People & sign-off">
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div className={cn('rounded-lg border px-3 py-2.5', event.performed_by ? '' : 'border-status-warning/40 bg-status-warning-bg/40')}>
                                        <div className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Performed by</div>
                                        <div className="text-sm font-medium">{event.performed_by ?? 'Not attributed to a staff member'}</div>
                                    </div>
                                    {event.witness_required && (
                                        <div className={cn('rounded-lg border px-3 py-2.5', event.witness ? 'border-status-success/40 bg-status-success-bg/40' : 'border-status-critical/40 bg-status-critical-bg/40')}>
                                            <div className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Witness (2nd signature)</div>
                                            <div className={cn('text-sm font-medium', event.witness ? '' : 'text-status-critical')}>{event.witness ?? 'Required — missing'}</div>
                                        </div>
                                    )}
                                </div>
                            </Section>

                            {/* Before → after */}
                            {changes.length > 0 && (
                                <Section refCb={(el) => (sectionEls.current.changes = el)} flash={flash === 'changes'} title="Before → after">
                                    <div className="flex flex-col gap-2">
                                        {changes.map((c, i) => (
                                            <div key={i} className="rounded-lg border px-3 py-2">
                                                <div className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{c.field}</div>
                                                <div className="mt-1 flex flex-wrap items-center gap-2 text-sm">
                                                    <span className="rounded bg-muted px-2 py-0.5 text-muted-foreground line-through">{c.from || '—'}</span>
                                                    <ArrowRight className="h-3.5 w-3.5 text-muted-foreground" />
                                                    <span className="rounded bg-status-success-bg px-2 py-0.5 font-medium text-status-success">{c.to || '—'}</span>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </Section>
                            )}

                            {/* Device & integrity */}
                            <Section refCb={(el) => (sectionEls.current.integrity = el)} flash={flash === 'integrity'} title="Device & integrity">
                                {integrity === null ? (
                                    <div className="rounded-lg border px-4 py-3 text-sm text-muted-foreground">Loading integrity…</div>
                                ) : !integrity.backed ? (
                                    <div className="rounded-lg border border-dashed px-4 py-3 text-sm text-muted-foreground">{integrity.note}</div>
                                ) : (
                                    <div className="rounded-lg border px-4">
                                        {integrity.recorded_at && <SummaryRow label="Recorded at" value={fmtDateTime(integrity.recorded_at)} />}
                                        <SummaryRow label="Device" value={integrity.device ?? 'Not captured'} />
                                        <SummaryRow label="IP address" value={integrity.ip_address ?? 'Not captured'} />
                                        <SummaryRow label="Edited since" value={integrity.edited ?? '—'} tone={integrity.edit_count > 0 ? undefined : 'success'} />
                                        {integrity.fingerprint && (
                                            <div className="flex items-baseline justify-between gap-4 py-2">
                                                <span className="text-[13px] text-muted-foreground">Content fingerprint</span>
                                                <span className="max-w-[60%] break-all text-right font-mono text-[11px] text-muted-foreground">{integrity.fingerprint}</span>
                                            </div>
                                        )}
                                    </div>
                                )}
                                <p className="mt-2 text-[11px] text-muted-foreground">
                                    SHA-256 fingerprint of the stored record — an integrity check over its current content, derived from the append-only audit log. Not a sealed hash-chain.
                                </p>
                            </Section>

                            {/* Linked records */}
                            <Section refCb={(el) => (sectionEls.current.linked = el)} flash={flash === 'linked'} title="Linked records">
                                <div className="flex flex-wrap gap-2">
                                    {link && <a href={link.href} className="rounded-full border px-3 py-1 text-xs font-medium text-primary hover:bg-accent">{link.label}</a>}
                                    {event.client_id && <a href={`/clients/${event.client_id}`} className="rounded-full border px-3 py-1 text-xs font-medium text-primary hover:bg-accent">Client profile</a>}
                                </div>
                            </Section>
                        </div>

                        {/* Footer Options bar — read-only / navigational actions only
                            (adapts the prn-detail-dialog pattern): Close · View client ·
                            Open on … · Verify integrity · Export event · Flag · Resolve gap. */}
                        <footer className={WIZARD_FOOTER_CLASS}>
                            <Button variant="outline" onClick={onClose}>Close</Button>
                            <div className="flex flex-wrap items-center justify-end gap-1.5">
                                {event.client_id ? (
                                    <Button variant="ghost" size="sm" onClick={() => router.visit(`/operations/clients/${event.client_id}/care`)}>
                                        <User className="h-4 w-4" />View client
                                    </Button>
                                ) : null}
                                <Button variant="ghost" size="sm" onClick={() => router.visit(primaryHref)}>
                                    <Eye className="h-4 w-4" />Open on {link?.label ?? 'eMAR'}
                                </Button>
                                <Button variant="ghost" size="sm" onClick={verifyIntegrity} disabled={verifying}>
                                    <Fingerprint className="h-4 w-4" />Verify integrity
                                </Button>
                                <a href={`/emar/audit/event/${encodeURIComponent(event.id)}/export`}>
                                    <Button variant="ghost" size="sm"><Download className="h-4 w-4" />Export event</Button>
                                </a>
                                {integrity?.backed ? (
                                    <Button variant="ghost" size="sm" onClick={onFlag} disabled={flagging}>
                                        <Flag className="h-4 w-4" />Flag for investigation
                                    </Button>
                                ) : null}
                                {isGap ? (
                                    <a href={resolveHref}><Button size="sm"><Check className="h-4 w-4" />Resolve gap</Button></a>
                                ) : null}
                            </div>
                        </footer>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function Section({ title, refCb, flash, children }: { title: string; refCb: (el: HTMLDivElement | null) => void; flash?: boolean; children: React.ReactNode }) {
    return (
        <div ref={refCb} className={cn('scroll-mt-4 border-t border-border/60 pt-5 transition-all duration-300 first:border-0 first:pt-0', flash && '-mx-3 rounded-lg bg-primary/10 px-3 ring-1 ring-primary/40 ring-inset')}>
            <div className={cn('mb-2 text-xs font-semibold uppercase tracking-wide transition-colors', flash ? 'text-primary' : 'text-muted-foreground')}>{title}</div>
            {children}
        </div>
    );
}
