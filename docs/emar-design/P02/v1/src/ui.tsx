/* Shared P02 compositions — built from the app's real primitives with semantic
 * tokens only. P01's compositions (Notice, DoseBadge, PersonMark, SupportChip,
 * MedicinePhoto, DesignNote, NotConfigured, KV) are imported unchanged from
 * src/p01/ui.tsx so the wording and look match the approved P01 and P00. */
import { TierTwoTabs, type GroupedProfileNavTab } from '@/components/page/grouped-profile-nav';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';
import { AlertCircle, CheckCircle2, LockKeyhole, RefreshCw, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { FACTS, type PersonId } from './data';
import { PEOPLE } from './p01/data';
import { Notice } from './p01/ui';
import { useP02 } from './store';

/* ───────────── controlled-medicine concealment (EM-12 · P11 v4 wording) ───────────── */
export const CONCEALED = {
    name: 'Controlled medicine',
    subline: 'Details need controlled-medicine access',
    ask: 'The house lead or Rangi Parata can tell you more.',
};
export function ConcealedIdentity({ compact = false }: { compact?: boolean }) {
    return (
        <span className="flex min-w-0 items-start gap-2.5">
            <span className="mt-0.5 grid size-[30px] shrink-0 place-items-center rounded-[8px] bg-muted text-muted-foreground">
                <LockKeyhole className="size-4" aria-hidden="true" />
            </span>
            <span className="min-w-0">
                <span className="block text-[13px] font-semibold">{CONCEALED.name}</span>
                <span className="block text-[11.5px] text-muted-foreground">{compact ? CONCEALED.subline : `${CONCEALED.subline}. ${CONCEALED.ask}`}</span>
            </span>
        </span>
    );
}
/** listed: the row is shown redacted (counted in N of N); otherwise it is left out of the list. */
export function ConcealedCaption({ n, listed = true }: { n: number; listed?: boolean }) {
    if (!n) return null;
    return (
        <span className="inline-flex items-center gap-1">
            <LockKeyhole className="size-3" aria-hidden="true" /> {listed ? `${n} controlled — details hidden (needs controlled-medicine access)` : `${n} controlled not shown — needs controlled-medicine access`}
        </span>
    );
}

/* ───────────── allergy status (EM-07 wording from P00 v5 / P01 v1 + the review line) ───────────── */
export function useAllergy(pid: PersonId) {
    const s = useP02();
    const f = FACTS[pid];
    const live = s.reviewed[pid];
    const reviewed = live ?? f.reviewed;
    const nkda = f.allergy === 'nkda' || !!live?.nkda;
    const status = nkda ? 'nkda' : f.allergy;
    return { status, entries: f.entries, reviewed, f };
}
export function reviewLine(r: { by: string; on: string; how: string } | null) {
    return r ? `Reviewed ${r.on} by ${r.by} · ${r.how}` : 'Not reviewed — a house lead or clinical lead confirms the list';
}
export function AllergySummary({ pid, compact = false, action }: { pid: PersonId; compact?: boolean; action?: ReactNode }) {
    const a = useAllergy(pid);
    const p = PEOPLE[pid];
    const list = a.entries.map((e) => `${e.allergen}${e.severity ? ` (${e.severity.toLowerCase()})` : ''}`).join(' · ');
    const review = (
        <span className={cn('mt-1 flex items-center gap-1.5 text-[12.5px]', a.reviewed ? 'text-muted-foreground' : 'font-semibold text-status-warning')}>
            {a.reviewed ? <CheckCircle2 className="size-3.5" aria-hidden="true" /> : <AlertCircle className="size-3.5" aria-hidden="true" />}
            {reviewLine(a.reviewed)}
        </span>
    );
    if (a.status === 'recorded')
        return (
            <Notice tone="critical" title={`Allergies: ${list}`} actions={action}>
                Check the label against the allergy list before giving.
                {compact ? null : review}
            </Notice>
        );
    if (a.status === 'nkda')
        return (
            <Notice tone="neutral" icon={CheckCircle2} title="No known drug allergies" actions={action}>
                {a.reviewed ? `Recorded on the health profile by ${a.reviewed.by}, ${a.reviewed.on}.` : 'Recorded on the health profile.'}
            </Notice>
        );
    if (a.status === 'none')
        return (
            <Notice tone="warning" title={`No allergies recorded for ${p.pref}`} actions={action}>
                Check the health profile before giving. This doesn’t mean {p.pref} has none.
                {compact ? null : review}
            </Notice>
        );
    return (
        <Notice
            tone="warning"
            icon={AlertCircle}
            live="alert"
            title={`Allergy record couldn’t be loaded for ${p.pref}`}
            actions={
                compact ? action : (
                    <>
                        <Button size="sm" variant="outline" onClick={() => document.dispatchEvent(new CustomEvent('p02:retry-allergies'))}>
                            <RefreshCw className="size-4" /> Try again
                        </Button>
                        {action}
                    </>
                )
            }
        >
            Check the health profile before giving. Don’t assume {p.pref} has none.
        </Notice>
    );
}
/** Short allergy status for meters and chips. */
export function allergyShort(status: string, n: number) {
    return status === 'recorded' ? `${n} recorded` : status === 'nkda' ? 'None known' : status === 'none' ? 'Not recorded' : 'Unavailable';
}

/* ───────────── tier-2 strip rendered as real links (Fleet vehicle profile pattern) ───────────── */
export function SubTabs({ tabs, active, onTab, hrefOf, label }: { tabs: GroupedProfileNavTab[]; active: string; onTab: (k: string) => void; hrefOf: (k: string) => string; label: string }) {
    return (
        <TierTwoTabs
            tabs={tabs}
            activeTab={active}
            onTab={onTab}
            testIdPrefix="p02-record"
            ariaLabel={label}
            panelId="p02-record-panel"
            renderLink={(entry, className, inner, a11y) => (
                <a
                    key={entry.key}
                    href={hrefOf(entry.key)}
                    className={className}
                    {...a11y}
                    onClick={(event) => {
                        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
                        event.preventDefault();
                        onTab(entry.key);
                    }}
                >
                    {inner}
                </a>
            )}
        />
    );
}

/** A long identity subline that wraps inside EntityTable's truncating subline slot (P11 v3 review: let it wrap). */
export const Wrap = ({ children }: { children: ReactNode }) => <span className="block whitespace-normal">{children}</span>;

/* ───────────── a titled card section (Fleet record body cards) ───────────── */
export function SectionCard({ eyebrow, title, right, children, className, icon: Icon }: { eyebrow?: string; title: ReactNode; right?: ReactNode; children: ReactNode; className?: string; icon?: LucideIcon }) {
    return (
        <Card className={cn('gap-3 p-5', className)}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-start gap-3">
                    {Icon ? (
                        <span className="grid size-9 shrink-0 place-items-center rounded-[10px] bg-primary/10 text-primary">
                            <Icon className="size-4" aria-hidden="true" />
                        </span>
                    ) : null}
                    <div className="min-w-0">
                        {eyebrow ? <p className="text-[10px] font-semibold tracking-[0.08em] text-muted-foreground uppercase">{eyebrow}</p> : null}
                        <h2 className="text-section-title">{title}</h2>
                    </div>
                </div>
                {right ? <div className="flex flex-wrap items-center gap-2">{right}</div> : null}
            </div>
            {children}
        </Card>
    );
}

/** Small key/value facts row (Fleet "Open work / Next check / Latest mileage" footer). */
export function FactStrip({ items }: { items: { label: string; value: ReactNode; onClick?: () => void; aria?: string }[] }) {
    return (
        <div className="grid divide-y rounded-lg border sm:grid-cols-3 sm:divide-x sm:divide-y-0">
            {items.map((it) => {
                const body = (
                    <>
                        <span className="block text-[11px] text-muted-foreground">{it.label}</span>
                        <span className="block text-[13px] font-semibold">{it.value}</span>
                    </>
                );
                return it.onClick ? (
                    <Button key={it.label} variant="ghost" className="h-auto flex-col items-start justify-start rounded-none px-3 py-2.5 text-left" onClick={it.onClick} aria-label={it.aria}>
                        {body}
                    </Button>
                ) : (
                    <div key={it.label} className="px-3 py-2.5">
                        {body}
                    </div>
                );
            })}
        </div>
    );
}

export function Tone({ variant, children, icon: Icon }: { variant: 'success' | 'warning' | 'critical' | 'info' | 'neutral'; children: ReactNode; icon?: LucideIcon }) {
    return (
        <StatusBadge variant={variant} className="rounded-[8px] font-semibold">
            {Icon ? <Icon className="size-3" aria-hidden="true" /> : null}
            {children}
        </StatusBadge>
    );
}
