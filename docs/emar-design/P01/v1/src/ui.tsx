/* Shared P01 compositions. Every one is built from the app's real primitives
 * (Alert, StatusBadge, EntityChip, Button, Card, FilePreviewDialog) with
 * semantic tokens only; safety surfaces use the fixed status pairs. */
import { FilePreviewDialog, type PreviewFile } from '@/components/files/file-preview-dialog';
import { EntityChip, PersonDisc } from '@/components/lists/entity-cells';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';
import {
    AlertCircle,
    AlertOctagon,
    AlertTriangle,
    CalendarDays,
    Check,
    CheckCircle2,
    Clock3,
    EyeOff as EyeOffIcon,
    Hand,
    HelpCircle,
    History,
    Home,
    ImageOff,
    Info,
    Loader2,
    LogOut,
    MessageSquare,
    PauseCircle,
    Repeat,
    Save,
    Settings2,
    ShieldCheck,
    UserCheck,
    X,
    XCircle,
    type LucideIcon,
} from 'lucide-react';
import { Fragment, useState, type ReactNode } from 'react';
import {
    BLOCKS,
    type BlockKey,
    type DoseState,
    STATE,
    STILL,
    fill,
    rosterFor,
    type Scenario,
} from './contract';
import { PEOPLE, type Dose, type MedPhoto, type Support } from './data';

/* ───────────── "Not configured" (P00 pattern) ───────────── */
export function NotConfigured({ label = 'Not configured' }: { label?: string }) {
    return (
        <StatusBadge variant="neutral" className="rounded-[8px] align-middle" title="Organisation value not decided yet">
            <Settings2 className="size-3" />
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
                part === '{NC}' ? (
                    <NotConfigured key={i} />
                ) : part.startsWith('**') ? (
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

/* ───────────── dose state badge ───────────── */
const STATE_ICON: Partial<Record<DoseState, LucideIcon>> = {
    notdue: Clock3,
    due: Clock3,
    late: AlertTriangle,
    given: Check,
    prompted: MessageSquare,
    assisted: Hand,
    selfmanaged: UserCheck,
    reoffered: Repeat,
    refused: X,
    withheld: PauseCircle,
    away: LogOut,
    queued: Save,
    rejected: XCircle,
    uncertain: HelpCircle,
};
export function DoseBadge({ state, size = 'md' }: { state: DoseState; size?: 'sm' | 'md' }) {
    const s = STATE[state];
    const Icon = STATE_ICON[state];
    return (
        <StatusBadge variant={s.variant} size={size} className="rounded-[8px] font-semibold">
            {state === 'sending' ? <Loader2 className="size-3 animate-spin motion-reduce:animate-none" aria-hidden="true" /> : Icon ? <Icon className="size-3" aria-hidden="true" /> : null}
            {s.label}
        </StatusBadge>
    );
}

/* ───────────── support mode (P00 SUPPORT) ───────────── */
export const SUPPORT: Record<Support, { label: string; desc: string; icon: LucideIcon }> = {
    administer: { label: 'Administer', desc: 'Staff give the medicine', icon: Hand },
    assist: { label: 'Assist', desc: 'Staff help, the person takes it', icon: Hand },
    prompt: { label: 'Prompt', desc: 'Staff remind, the person takes it', icon: MessageSquare },
    independent: { label: 'Independent', desc: 'The person manages it', icon: UserCheck },
};
export function SupportChip({ support }: { support: Support }) {
    const s = SUPPORT[support];
    return (
        <span title={s.desc}>
            <EntityChip icon={s.icon}>{s.label}</EntityChip>
        </span>
    );
}

/* ───────────── person photo (private, synthetic) ───────────── */
export function PersonMark({ pid, size = 48 }: { pid: string; size?: number }) {
    const p = PEOPLE[pid];
    if (!p.photo) return <PersonDisc name={p.legal} size={size} />;
    return (
        <span
            className="relative block shrink-0 overflow-hidden rounded-full border border-border bg-primary/10"
            style={{ width: size, height: size }}
            aria-label={`Photo of ${p.pref} on file (synthetic placeholder)`}
            role="img"
        >
            <svg viewBox="0 0 64 64" className="size-full" aria-hidden="true">
                <circle cx="32" cy="25" r="11" className="fill-primary/40" />
                <path d="M10 62c2-12 11-18 22-18s20 6 22 18z" className="fill-primary/40" />
            </svg>
        </span>
    );
}

/* ───────────── identity header (P00 contract, restyled) ───────────── */
export function IdentityHeader({
    pid,
    support,
    compact = false,
}: {
    pid: string;
    support?: Support;
    compact?: boolean;
}) {
    const p = PEOPLE[pid];
    if (compact) {
        return (
            <section aria-label="Person" className="flex items-center gap-3 rounded-xl border bg-card px-3 py-2.5">
                <PersonMark pid={pid} size={36} />
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-baseline gap-x-2">
                        <span className="text-[15px] font-semibold">{p.pref}</span>
                        <span className="text-caption">{p.legal}</span>
                    </div>
                    <div className="text-caption">
                        {p.house}
                        {p.photo ? ' · photo on file (private)' : ' · No photo on file'}
                    </div>
                </div>
                {support ? <SupportChip support={support} /> : null}
            </section>
        );
    }
    return (
        <section aria-label="Person" className="flex gap-4 rounded-xl border bg-card p-4">
            <PersonMark pid={pid} size={64} />
            <div className="min-w-0 flex-1 space-y-2">
                <div className="flex flex-wrap items-baseline gap-x-2.5">
                    <span className="text-section-title">{p.pref}</span>
                    <span className="text-subtle">Legal name: {p.legal}</span>
                </div>
                <div className="flex flex-wrap gap-1.5">
                    <EntityChip icon={Home}>{p.house}</EntityChip>
                    <EntityChip>Born {p.born}</EntityChip>
                    <EntityChip>NHI {p.nhi} (test)</EntityChip>
                </div>
                <dl className="grid gap-1.5 text-sm sm:grid-cols-[150px_1fr]">
                    <dt className="text-muted-foreground">Identity</dt>
                    <dd>
                        {p.photo ? (
                            'Compare with the photo on file. The photo is private: only people who can record for ' + p.pref + ' see it.'
                        ) : (
                            <>
                                No photo on file. Check identity using: <NotConfigured />
                            </>
                        )}
                    </dd>
                    {support ? (
                        <>
                            <dt className="text-muted-foreground">Support for this medicine</dt>
                            <dd className="flex flex-wrap items-center gap-2">
                                <SupportChip support={support} />
                                <span className="text-subtle">{SUPPORT[support].desc} · from the support plan, reviewed 12 January 2026</span>
                            </dd>
                        </>
                    ) : null}
                    {p.prefs ? (
                        <>
                            <dt className="text-muted-foreground">Preferences</dt>
                            <dd>{p.prefs}</dd>
                        </>
                    ) : null}
                </dl>
            </div>
        </section>
    );
}

/* ───────────── medicine photo (staff photos — Stephan, 29 Sep) ───────────── */
export function MedicinePhoto({ photo, med }: { photo: MedPhoto; med: string }) {
    const [open, setOpen] = useState<PreviewFile | null>(null);
    if (photo.state === 'none') {
        return (
            <div className="flex w-full items-start gap-3 rounded-lg border border-dashed border-status-warning/50 bg-status-warning-bg/50 p-3">
                <span className="flex size-14 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <ImageOff className="size-5" aria-hidden="true" />
                </span>
                <div className="text-sm">
                    <p className="font-semibold text-status-warning">No photo — check the label</p>
                    <p className="text-subtle">A photo can be added when stock is received. It’s optional and never blocks recording.</p>
                </div>
            </div>
        );
    }
    const changed = photo.state === 'changed';
    return (
        <div className={cn('flex w-full items-start gap-3 rounded-lg border p-3', changed ? 'border-status-warning/50 bg-status-warning-bg/50' : 'bg-muted/30')}>
            {/* eslint-disable-next-line no-restricted-syntax -- an image thumbnail is the trigger that opens the shared FilePreviewDialog */}
            <button
                type="button"
                onClick={() =>
                    setOpen({
                        id: photo.file ?? med,
                        name: `${med} — photo of the supplied pack`,
                        filename: photo.file,
                        mime: 'image/png',
                        source: `Taken ${photo.taken} · ${photo.pack} · synthetic placeholder`,
                        previewUrl: `/photos/${photo.file}`,
                        downloadUrl: `/photos/${photo.file}`,
                    })
                }
                className="group relative block size-16 shrink-0 overflow-hidden rounded-lg border bg-card outline-none focus-visible:ring-2 focus-visible:ring-ring"
                aria-label={`View the photo of ${med} (taken ${photo.taken})`}
            >
                <img src={`./photos/${photo.file}`} alt="" className={cn('size-full object-cover', changed && 'opacity-60 grayscale')} />
            </button>
            <div className="min-w-0 text-sm">
                {changed ? (
                    <p className="flex items-center gap-1.5 font-semibold text-status-warning">
                        <AlertTriangle className="size-3.5" aria-hidden="true" /> Pack or brand changed — check the label
                    </p>
                ) : (
                    <p className="font-semibold">Photo of the supplied pack</p>
                )}
                <p className="text-subtle">
                    Taken {photo.taken} · {photo.pack}
                    {changed ? ` · ${photo.nowPack}` : ''}
                </p>
                <p className="text-subtle">Check the label — the picture is a guide only.</p>
            </div>
            <FilePreviewDialog file={open} onClose={() => setOpen(null)} />
        </div>
    );
}

/* ───────────── allergy status (EM-07; P00 v5 wording) ───────────── */
export function AllergyNotice({ pid, compact = false }: { pid: string; compact?: boolean }) {
    const p = PEOPLE[pid];
    const a = p.allergy;
    if (a.status === 'recorded')
        return (
            <Notice tone="critical" title={`Allergies: ${a.list}`}>
                Check the label against the allergy list before giving.{compact ? '' : ` ${a.source} · ${a.reviewed}.`}
            </Notice>
        );
    if (a.status === 'nkda')
        return (
            <Notice tone="neutral" icon={CheckCircle2} title="No known drug allergies">
                {compact ? null : a.source}
            </Notice>
        );
    if (a.status === 'none')
        return (
            <Notice tone="warning" title={`No allergies recorded for ${p.pref}`}>
                Check the health profile before giving. This doesn’t mean {p.pref} has none.
            </Notice>
        );
    return (
        <Notice tone="warning" icon={AlertCircle} live="alert" title={`Allergy record couldn’t be loaded for ${p.pref}`}>
            Check the health profile before giving. Don’t assume {p.pref} has none.
        </Notice>
    );
}
export function MatchLine({ med, allergen, source }: { med: string; allergen: string; source: string }) {
    return (
        <p role="note" className="flex items-start gap-2 rounded-lg border border-status-critical/30 bg-status-critical-bg px-3 py-2 text-sm text-status-critical">
            <AlertOctagon className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>
                <strong>{med}</strong> — matches recorded {allergen} allergy ({source})
            </span>
        </p>
    );
}

/* ───────────── roster evidence (Stephan: roster + clock-ins) ───────────── */
export function RosterEvidence({ scenario, who = 'you' }: { scenario: Scenario; who?: 'you' | 'requester' }) {
    const rows = rosterFor(scenario);
    return (
        <section aria-label="Roster evidence" className="rounded-lg border bg-card text-sm">
            <header className="flex items-center gap-2 border-b px-3 py-2 font-semibold">
                <CalendarDays className="size-4 text-muted-foreground" aria-hidden="true" />
                Roster and clock-ins · Kōwhai House · checked 9:12 am NZDT
            </header>
            <ul className="divide-y">
                {rows.map((s) => {
                    const reasons = [
                        !s.onShiftNow ? (s.now.startsWith('not') ? 'not on shift yet' : 'not on shift now') : '',
                        s.onShiftNow && !s.witnessCd ? 'no witness competency' : '',
                        s.onShiftNow && s.pin !== 'set' ? 'no witness PIN set' : '',
                    ].filter(Boolean);
                    return (
                        <li key={s.id} className="grid gap-1 px-3 py-2 sm:grid-cols-[1.1fr_1.4fr_1.6fr]">
                            <span className="font-medium">
                                {s.name}
                                {s.self ? <span className="text-subtle"> ({who === 'you' ? 'you' : 'asking'})</span> : null}
                            </span>
                            <span className="text-subtle">
                                {s.shift} · {s.now}
                            </span>
                            <span className={cn('text-sm', s.self ? 'text-muted-foreground' : reasons.length ? 'text-status-critical' : 'text-status-success')}>
                                {s.self ? 'The witness must be someone else' : reasons.length ? `✕ ${reasons.join(' · ')}` : '✓ Can witness'}
                            </span>
                        </li>
                    );
                })}
            </ul>
            <footer className="flex items-start gap-2 border-t px-3 py-2 text-subtle">
                <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                {scenario === 'cdNoWitness' || scenario === 'alone'
                    ? 'Nobody else on shift can witness right now. Next witness-eligible staff member: Jordan Tipene from 3:00 pm.'
                    : 'Daniel Ahn and Jordan Tipene can witness now.'}
            </footer>
        </section>
    );
}

/* ───────────── blocked panel (NF-07: why, next step, what can still be recorded) ───────────── */
export function BlockedPanel({
    block,
    pid,
    med,
    scenario,
    action,
    extra,
}: {
    block: BlockKey;
    pid: string;
    med: string;
    scenario: Scenario;
    action?: ReactNode;
    extra?: ReactNode;
}) {
    const b = BLOCKS[block];
    const p = PEOPLE[pid].pref;
    const still = STILL[b.still] !== undefined ? STILL[b.still] : fill(b.still, p, med);
    const tone = b.tone === 'critical' ? TONE.critical : TONE.warning;
    return (
        <section role="group" aria-label={fill(b.title, p, med)} className={cn('space-y-2.5 rounded-xl border p-4', tone.cls)}>
            <h3 className="flex items-center gap-2 text-[14px] font-semibold">
                {b.safety ? <AlertOctagon className="size-4" aria-hidden="true" /> : <ShieldCheck className="size-4" aria-hidden="true" />}
                {fill(b.title, p, med)}
            </h3>
            {b.match ? <MatchLine med="Amoxicillin" allergen="penicillin" source="health profile" /> : null}
            <p className="text-sm text-foreground/90">
                <Rich text={fill(b.text, p, med)} />
            </p>
            {b.roster ? <RosterEvidence scenario={scenario} /> : null}
            <ul className="list-disc space-y-1 pl-5 text-sm text-foreground/90">
                {b.next.map((n) => (
                    <li key={n}>
                        <Rich text={fill(n, p, med)} />
                    </li>
                ))}
            </ul>
            {still ? (
                <p className="flex items-center gap-2 text-sm font-medium text-foreground">
                    <CheckCircle2 className="size-4 text-status-success" aria-hidden="true" />
                    {still}
                </p>
            ) : b.stillText ? (
                <p className="text-sm font-semibold text-foreground">{fill(b.stillText, p, med)}</p>
            ) : null}
            {extra}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-caption">Checked again when you save.</span>
                {action}
            </div>
        </section>
    );
}

/* ───────────── medicine card (instructions, support, covert, rules — EM-25) ───────────── */
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

/* ───────────── tile picker (POPUP_STYLE_GUIDE "Type picker") ───────────── */
export interface Tile {
    key: string;
    label: string;
    description: string;
    icon: LucideIcon;
    disabled?: string | null;
}
export function TilePicker({
    tiles,
    value,
    onChange,
    labelledBy,
    describedBy,
    invalid,
}: {
    tiles: Tile[];
    value: string | null;
    onChange: (k: string) => void;
    labelledBy: string;
    describedBy?: string;
    invalid?: boolean;
}) {
    return (
        <div role="group" aria-labelledby={labelledBy} aria-describedby={describedBy} className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            {tiles.map((t) => {
                const Icon = t.icon;
                const active = value === t.key;
                return (
                    // eslint-disable-next-line no-restricted-syntax -- the tile picker markup is the POPUP_STYLE_GUIDE "Type picker" pattern verbatim
                    <button
                        key={t.key}
                        type="button"
                        data-tile={t.key}
                        aria-pressed={active}
                        aria-disabled={t.disabled ? true : undefined}
                        aria-invalid={invalid || undefined}
                        onClick={() => !t.disabled && onChange(t.key)}
                        className={cn(
                            'group frontline-tap flex items-start gap-2 rounded-xl border bg-card/40 p-3 text-left transition-all',
                            'focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                            t.disabled ? 'cursor-not-allowed border-border opacity-80' : 'hover:border-primary/50 hover:bg-card',
                            active ? 'border-primary bg-primary/10 ring-1 ring-primary/40' : invalid ? 'border-status-critical/60' : 'border-border',
                        )}
                    >
                        <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                            <Icon className={cn('h-4 w-4', active ? 'text-primary' : 'text-muted-foreground')} />
                        </span>
                        <span className="min-w-0">
                            <span className="block text-sm font-medium">{t.label}</span>
                            <span className="block text-xs text-muted-foreground">{t.description}</span>
                            {t.disabled ? (
                                <span className="mt-1 flex items-start gap-1 text-xs font-semibold text-status-critical">
                                    <XCircle className="mt-0.5 size-3 shrink-0" aria-hidden="true" /> {t.disabled}
                                </span>
                            ) : null}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

/* ───────────── design-note / mockup annotations (never product UI) ───────────── */
export function DesignNote({ title = 'Design note', children }: { title?: string; children: ReactNode }) {
    return (
        <aside className="rounded-xl border border-dashed border-border bg-card/60 p-3 text-sm text-muted-foreground" aria-label={title}>
            <p className="mb-1 text-[10px] font-bold tracking-[0.08em] uppercase">{title}</p>
            <div className="space-y-1">{children}</div>
        </aside>
    );
}

/* Chips under a medicine name: support mode, controlled, covert, amount · instructions. */
export function EntityChipRow({ d }: { d: Dose }) {
    return (
        <span className="flex flex-wrap items-center gap-1.5">
            <SupportChip support={d.support} />
            {d.cd ? <EntityChip icon={ShieldCheck}>Controlled</EntityChip> : null}
            {d.covert ? <EntityChip icon={EyeOffIcon}>Covert plan</EntityChip> : null}
            {d.rules?.length ? <EntityChip icon={Settings2}>Rules apply</EntityChip> : null}
            <span className="text-[12px] text-muted-foreground">
                {d.amount.range ? `${d.amount.range[0]}–${d.amount.range[1]} ${d.amount.plural}` : `${d.amount.n} ${d.amount.n <= 1 ? d.amount.unit : d.amount.plural}`} · {d.instructions}
            </span>
        </span>
    );
}

/* Small helpers */
export const initials = (n: string) =>
    n
        .split(/\s+/)
        .map((x) => x[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
export function OrderLine({ d }: { d: Dose }) {
    return d.order.verified ? (
        <span>
            Verified {d.order.on} by {d.order.by} · prescribed by {d.order.prescriber}
        </span>
    ) : (
        <StatusBadge variant="warning" className="rounded-[8px]">
            <History className="size-3" /> Waiting to be checked
        </StatusBadge>
    );
}
export function HelpButton({ onClick, children }: { onClick: () => void; children: ReactNode }) {
    return (
        <Button type="button" variant="outline" size="sm" className="frontline-tap" onClick={onClick}>
            <HelpCircle className="size-4" /> {children}
        </Button>
    );
}
