/* eMAR P01 — the shared pieces of the recording dialog (approved P01 v2,
 * `src/ui.tsx`), built on the app's primitives: notices on the Alert
 * primitive with the fixed status pairs, the person's identity, the
 * allergy status and match line (a real match is always the critical
 * surface, Q11), the medicine card and the blocked-reason panel (NF-07). */
import { EntityChip, PersonDisc } from '@/components/lists/entity-cells';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';
import {
    AlertCircle,
    AlertOctagon,
    AlertTriangle,
    CalendarDays,
    CheckCircle2,
    Hand,
    Home,
    Info,
    MessageSquare,
    Settings2,
    ShieldCheck,
    UserCheck,
    type LucideIcon,
} from 'lucide-react';
import { Fragment, type ReactNode } from 'react';
import { blockCopy, competencyCopy, NOT_CONFIGURED, STILL_LINE, type BlockCopy } from './copy';
import type { Block, CompetencyState, DoseRequirements } from './types';

/* ───────────── "Not configured" (P00 pattern) ───────────── */
export function NotConfigured({ label = 'Not configured' }: { label?: string }) {
    return (
        <StatusBadge variant="neutral" className="rounded-[8px] align-middle" title="Organisation value not decided yet">
            <Settings2 className="size-3" aria-hidden="true" />
            {label}
        </StatusBadge>
    );
}

/** Renders {NC} as the Not configured chip and **x** as bold. */
export function Rich({ text }: { text: string }) {
    const parts = text.split(/(\{NC\}|\*\*[^*]+\*\*)/g);
    return (
        <>
            {parts.map((part, i) =>
                part === NOT_CONFIGURED ? (
                    <NotConfigured key={i} />
                ) : part.startsWith('**') && part.endsWith('**') ? (
                    <strong key={i} className="font-semibold">
                        {part.slice(2, -2)}
                    </strong>
                ) : (
                    <Fragment key={i}>{part}</Fragment>
                ),
            )}
        </>
    );
}

/* ───────────── notices (Alert primitive, fixed status pairs) ───────────── */
export type Tone = 'info' | 'warning' | 'critical' | 'success' | 'neutral';
const TONE: Record<Tone, { cls: string; icon: LucideIcon }> = {
    info: { cls: 'border-status-info/30 bg-status-info-bg text-status-info', icon: Info },
    warning: { cls: 'border-status-warning/30 bg-status-warning-bg text-status-warning', icon: AlertTriangle },
    critical: { cls: 'border-status-critical/30 bg-status-critical-bg text-status-critical', icon: AlertOctagon },
    success: { cls: 'border-status-success/30 bg-status-success-bg text-status-success', icon: CheckCircle2 },
    neutral: { cls: 'border-border bg-muted/60 text-foreground', icon: Info },
};

export function Notice({
    tone,
    title,
    children,
    actions,
    icon,
    live,
    className,
}: {
    tone: Tone;
    title: ReactNode;
    children?: ReactNode;
    actions?: ReactNode;
    icon?: LucideIcon;
    live?: 'alert' | 'status' | 'note';
    className?: string;
}) {
    const t = TONE[tone];
    const Icon = icon ?? t.icon;
    return (
        <Alert role={live ?? (tone === 'critical' ? 'alert' : 'note')} className={cn(t.cls, className)}>
            <Icon />
            <AlertTitle className="line-clamp-none font-semibold">{title}</AlertTitle>
            {children || actions ? (
                <AlertDescription className="text-foreground/85">
                    {children}
                    {actions ? <div className="mt-2 flex flex-wrap gap-2">{actions}</div> : null}
                </AlertDescription>
            ) : null}
        </Alert>
    );
}

/* ───────────── support mode (P00 vocabulary) ───────────── */
export const SUPPORT: Record<DoseRequirements['support'], { label: string; desc: string; icon: LucideIcon }> = {
    administer: { label: 'Administer', desc: 'Staff give the medicine', icon: Hand },
    assist: { label: 'Assist', desc: 'Staff help, the person takes it', icon: Hand },
    prompt: { label: 'Prompt', desc: 'Staff remind, the person takes it', icon: MessageSquare },
    independent: { label: 'Independent', desc: 'The person manages it', icon: UserCheck },
};

export function SupportChip({ support }: { support: DoseRequirements['support'] }) {
    const s = SUPPORT[support];
    return (
        <span title={s.desc}>
            <EntityChip icon={s.icon}>{s.label}</EntityChip>
        </span>
    );
}

/* ───────────── the person ───────────── */
function PersonMark({ req, size }: { req: DoseRequirements; size: number }) {
    if (req.person.photo_url) {
        return (
            <img
                src={req.person.photo_url}
                alt={`Photo of ${req.person.preferred_name} on file`}
                className="shrink-0 rounded-full border border-border object-cover"
                style={{ width: size, height: size }}
            />
        );
    }
    return <PersonDisc name={req.person.legal_name} size={size} />;
}

const bornLabel = (iso: string | null) =>
    iso
        ? new Date(`${iso}T12:00:00Z`).toLocaleDateString('en-NZ', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' })
        : null;

export function IdentityHeader({ req, compact = false }: { req: DoseRequirements; compact?: boolean }) {
    const p = req.person;
    if (compact) {
        return (
            <section aria-label="Person" className="flex items-center gap-3 rounded-xl border bg-card px-3 py-2.5">
                <PersonMark req={req} size={36} />
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-baseline gap-x-2">
                        <span className="text-[15px] font-semibold">{p.preferred_name}</span>
                        <span className="text-caption">{p.legal_name}</span>
                    </div>
                    <div className="text-caption">
                        {p.house ?? 'House not recorded'}
                        {p.photo_url ? ' · photo on file' : ' · No photo on file'}
                    </div>
                </div>
                <SupportChip support={req.support} />
            </section>
        );
    }
    const born = bornLabel(p.born);
    return (
        <section aria-label="Person" className="flex gap-4 rounded-xl border bg-card p-4">
            <PersonMark req={req} size={64} />
            <div className="min-w-0 flex-1 space-y-2">
                <div className="flex flex-wrap items-baseline gap-x-2.5">
                    <span className="text-section-title">{p.preferred_name}</span>
                    <span className="text-subtle">Legal name: {p.legal_name}</span>
                </div>
                <div className="flex flex-wrap gap-1.5">
                    {p.house ? <EntityChip icon={Home}>{p.house}</EntityChip> : null}
                    {born ? <EntityChip>Born {born}</EntityChip> : null}
                    {p.nhi ? <EntityChip>NHI {p.nhi}</EntityChip> : null}
                </div>
                <dl className="grid gap-1.5 text-sm sm:grid-cols-[150px_1fr]">
                    <dt className="text-muted-foreground">Identity</dt>
                    <dd>
                        {p.photo_url ? (
                            'Compare with the photo on file.'
                        ) : (
                            <>
                                No photo on file. Check identity using: <NotConfigured />
                            </>
                        )}
                    </dd>
                    <dt className="text-muted-foreground">Support for this medicine</dt>
                    <dd className="flex flex-wrap items-center gap-2">
                        <SupportChip support={req.support} />
                        <span className="text-subtle">{SUPPORT[req.support].desc}</span>
                    </dd>
                </dl>
            </div>
        </section>
    );
}

/* ───────────── allergies (EM-07; P00 v5 wording) ───────────── */
export function AllergyNotice({ req, compact = false }: { req: DoseRequirements; compact?: boolean }) {
    const p = req.person.preferred_name;
    const a = req.allergy;
    if (a.status === 'recorded') {
        return (
            <Notice tone="critical" title={`Allergies: ${a.list.join(' · ')}`}>
                Check the label against the allergy list before giving.{compact ? '' : ' From the allergy register and the health profile.'}
            </Notice>
        );
    }
    if (a.status === 'none_recorded') {
        return (
            <Notice tone="warning" title={`No allergies recorded for ${p}`}>
                Check the health profile before giving. This doesn’t mean {p} has none.
            </Notice>
        );
    }
    return (
        <Notice tone="warning" icon={AlertCircle} live="alert" title={`Allergy record couldn’t be loaded for ${p}`}>
            Check the health profile before giving. Don’t assume {p} has none.
        </Notice>
    );
}

export function MatchLine({ req }: { req: DoseRequirements }) {
    const m = req.allergy.match;
    if (!m) return null;
    const source = m.source === 'profile' || m.source === 'health_profile' ? 'health profile' : 'allergy register';
    return (
        <p role="note" className="flex items-start gap-2 rounded-lg border border-status-critical/30 bg-status-critical-bg px-3 py-2 text-sm text-status-critical">
            <AlertOctagon className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>
                <strong>{req.order.name}</strong> — matches recorded {m.allergen ?? ''} allergy ({source})
            </span>
        </p>
    );
}

/* ───────────── key–value rows ───────────── */
export function KV({ rows }: { rows: [ReactNode, ReactNode][] }) {
    return (
        <dl className="grid gap-x-4 gap-y-1.5 text-sm sm:grid-cols-[150px_1fr]">
            {rows.map(([k, v], i) => (
                <Fragment key={i}>
                    <dt className="text-muted-foreground">{k}</dt>
                    <dd className="min-w-0">{v}</dd>
                </Fragment>
            ))}
        </dl>
    );
}

/* ───────────── roster evidence (no eligible witness) ─────────────
 * Who is on shift and whether each can confirm this dose — never why not:
 * a colleague's competency isn't the recorder's to read (P01 C1 review). */
export function RosterEvidence({ req }: { req: DoseRequirements }) {
    const rows = req.second_person.candidates;
    const checked = new Date(req.checked_at);
    const at = checked.toLocaleTimeString('en-NZ', { hour: 'numeric', minute: '2-digit', timeZone: 'Pacific/Auckland' });
    const zone = new Intl.DateTimeFormat('en-NZ', { timeZone: 'Pacific/Auckland', timeZoneName: 'short' }).formatToParts(checked).find((p) => p.type === 'timeZoneName')?.value ?? 'NZ time';
    return (
        <section aria-label="Roster evidence" className="rounded-lg border bg-card text-sm">
            <header className="flex items-center gap-2 border-b px-3 py-2 font-semibold">
                <CalendarDays className="size-4 text-muted-foreground" aria-hidden="true" />
                On shift at {req.person.house ?? 'this house'} now · checked {at} {zone}
            </header>
            {rows.length ? (
                <ul className="divide-y">
                    {rows.map((c) => (
                        <li key={c.id} className="grid gap-1 px-3 py-2 sm:grid-cols-[1.2fr_2fr]">
                            <span className="font-medium">{c.name}</span>
                            <span className={cn('text-sm', c.can_confirm ? 'text-status-success' : 'text-status-critical')}>
                                {c.can_confirm ? '✓ Can witness' : '✕ Can’t confirm this dose'}
                            </span>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="px-3 py-2 text-subtle">Nobody else is clocked in at this house now.</p>
            )}
        </section>
    );
}

/* ───────────── blocked panel (NF-07: why, next step, what can still be recorded) ───────────── */
export function BlockedPanel({
    copy,
    req,
    action,
    extra,
}: {
    copy: BlockCopy;
    /** The full answer; absent when nothing can be recorded (only the reason is sent). */
    req?: DoseRequirements;
    action?: ReactNode;
    extra?: ReactNode;
}) {
    const tone = copy.tone === 'critical' ? TONE.critical : TONE.warning;
    const still = copy.still === 'none' || copy.still === 'note' ? null : STILL_LINE[copy.still];
    return (
        <section role="group" aria-label={copy.title} className={cn('space-y-2.5 rounded-xl border p-4', tone.cls)}>
            <h3 className="flex items-center gap-2 text-[14px] font-semibold">
                {copy.safety ? <AlertOctagon className="size-4" aria-hidden="true" /> : <ShieldCheck className="size-4" aria-hidden="true" />}
                {copy.title}
            </h3>
            {copy.safety && req ? <MatchLine req={req} /> : null}
            <p className="text-sm text-foreground/90">
                <Rich text={copy.text} />
            </p>
            {copy.roster && req ? <RosterEvidence req={req} /> : null}
            <ul className="list-disc space-y-1 pl-5 text-sm text-foreground/90">
                {copy.next.map((n) => (
                    <li key={n}>
                        <Rich text={n} />
                    </li>
                ))}
            </ul>
            {still ? (
                <p className="flex items-center gap-2 text-sm font-medium text-foreground">
                    <CheckCircle2 className="size-4 text-status-success" aria-hidden="true" />
                    {still}
                </p>
            ) : copy.stillText ? (
                <p className="text-sm font-semibold text-foreground">{copy.stillText}</p>
            ) : null}
            {extra}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-caption">Checked again when you save.</span>
                {action}
            </div>
        </section>
    );
}

/**
 * The one block on "given" that applies, strongest first: a safety or
 * witness block, then competency. (When nothing can be recorded at all the
 * dialog shows BlockedDoseDialog instead.)
 */
export function activeBlock(req: DoseRequirements): { copy: BlockCopy; block: Block | null; competency: CompetencyState | null } | null {
    if (req.block_given) return { copy: blockCopy(req.block_given, req), block: req.block_given, competency: null };
    const competency = competencyCopy(req.competency.state, req);
    return competency ? { copy: competency, block: null, competency: req.competency.state } : null;
}
