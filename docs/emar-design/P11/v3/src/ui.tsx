/* P11 v3 — page compositions built only from the app’s real primitives:
 * Card + Label + Switch rows as in Fleet Settings (_maps.tsx), Switch + “On/Off”
 * word as in _notifications.tsx, Segmented from wizard/primitives, the sticky Card
 * save bar from _notifications.tsx, and a Popover + Command person picker. */
import { EntityContextMenu, type MenuItem } from '@/components/lists/entity-menu';
import { ListCaption } from '@/components/lists/list-caption';
import { Notice } from '@/pages/fleet-assets/settings/_ui';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Command, CommandEmpty, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { FieldErr, Segmented } from '@/components/wizard/primitives';
import { cn } from '@/lib/utils';
import { ArrowUpRight, Check, ChevronDown, Info, Search, type LucideIcon } from 'lucide-react';
import { ReviewCard } from '@/components/wizard/shell';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useStore } from './model';

export const Decided = ({ by }: { by: string }) => <StatusBadge variant="success" size="sm"><Check className="size-3" />{by}</StatusBadge>;
export const DefaultNotReviewed = () => <StatusBadge variant="warning" size="sm">Default — not yet reviewed</StatusBadge>;
export const NotConfigured = () => <StatusBadge variant="neutral" size="sm">Not configured</StatusBadge>;
export const Changed = () => <StatusBadge variant="info" size="sm">Changed — not saved</StatusBadge>;
/** Review state beside a value: decided by Stephan, set by someone, or still a default. */
export function Reviewed({ by, nc }: { by: string | null; nc?: boolean }) {
    if (nc) return <NotConfigured />;
    if (!by) return <DefaultNotReviewed />;
    if (by.startsWith('Stephan') || by.startsWith('Default chosen')) return <Decided by={by} />;
    return <StatusBadge variant="neutral" size="sm">Set by {by}</StatusBadge>;
}

/** On/off with the state spelled out (colour is never the only signal). */
export function OnOff({ id, checked, onChange, disabled, label }: { id: string; checked: boolean; onChange: (v: boolean) => void; disabled?: boolean; label?: string }) {
    return (
        <span className="inline-flex items-center gap-3">
            <Switch id={id} checked={checked} onCheckedChange={onChange} disabled={disabled} aria-label={label} />
            <span className="text-subtle w-7" aria-hidden="true">{checked ? 'On' : 'Off'}</span>
        </span>
    );
}

/** One setting: Fleet Settings’ Card row — label and help left, control right, anything that depends on it below. */
export function SettingRow({ id, label, help, control, children, summary, meta, dirty, error, errorId, hidden }: {
    id?: string; label: string; help?: ReactNode; control?: ReactNode; children?: ReactNode; summary?: ReactNode; meta?: ReactNode; dirty?: boolean; error?: string; errorId?: string; hidden?: boolean;
}) {
    if (hidden) return null;
    return (
        <Card className="gap-3 p-4" data-setting={id}>
            <div className="flex flex-row items-start justify-between gap-4">
                <div className="min-w-0">
                    <Label htmlFor={id} className="text-sm leading-snug">{label}</Label>
                    {help ? <p className="text-caption mt-1 max-w-[70ch]">{help}</p> : null}
                </div>
                {control ? <div className="shrink-0">{control}</div> : null}
            </div>
            {children ? <div className="space-y-2">{children}</div> : null}
            <FieldErr id={errorId}>{error}</FieldErr>
            {summary ? <p className="text-subtle flex items-start gap-1.5"><Info className="mt-0.5 size-3.5 shrink-0" /><span>{summary}</span></p> : null}
            {meta || dirty ? <div className="flex flex-wrap items-center gap-2">{meta}{dirty ? <Changed /> : null}</div> : null}
        </Card>
    );
}

export function NumberInput({ id, value, onChange, unit, disabled, error, min = 1, max, label }: { id: string; value: string; onChange: (v: string) => void; unit: string; disabled?: boolean; error?: string; min?: number; max?: number; label?: string }) {
    return (
        <span className="inline-flex items-center gap-2">
            <Input id={id} type="number" inputMode="numeric" min={min} max={max} value={value} disabled={disabled} aria-label={label} aria-invalid={!!error || undefined} aria-describedby={error ? `${id}-error` : undefined} onChange={(e) => onChange(e.target.value.trim())} className="h-9 w-24 tabular-nums" />
            <span className="text-subtle">{unit}</span>
        </span>
    );
}

export function Choice<T extends string>({ value, onChange, options, disabled }: { value: T; onChange: (v: T) => void; options: [T, string][]; disabled?: boolean }) {
    return <Segmented value={value} onChange={onChange} options={options.map(([v, l]) => ({ value: v, label: l, disabled }))} />;
}

/** Section card: ListCaption + content (LIST_STYLE_GUIDE §1 caption before every list or group). */
export function Section({ title, caption, right, children, id }: { title: ReactNode; caption?: ReactNode; right?: ReactNode; children: ReactNode; id?: string }) {
    return (
        <section className="space-y-3" aria-labelledby={id}>
            <div id={id}><ListCaption title={title} caption={caption} right={right} /></div>
            {children}
        </section>
    );
}

/** The real Alert description is a grid, so mixed text and <b> are wrapped in one span. */
export const Note = ({ children }: { children: ReactNode }) => <Notice role="note"><span>{children}</span></Notice>;
/** Fleet’s in-page status message after a save (focus moves here so it is announced). */
export function Flash() {
    const { flashMsg } = useStore();
    const ref = useRef<HTMLDivElement>(null);
    useEffect(() => { if (flashMsg) ref.current?.focus(); }, [flashMsg]);
    return flashMsg ? <div role="status" tabIndex={-1} ref={ref} className="outline-none"><Notice role="note"><span>{flashMsg}</span></Notice></div> : null;
}

/** Sticky save bar (Fleet _notifications.tsx): what changed, then Discard / Review changes. */
export function SaveBar({ count, onDiscard, onReview, readOnly }: { count: number; onDiscard: () => void; onReview: () => void; readOnly?: string }) {
    return (
        <Card className="sticky bottom-0 z-10 flex flex-row flex-wrap items-center justify-between gap-3 p-4" role="region" aria-label="Save changes">
            {readOnly ? (
                <p className="text-subtle">{readOnly}</p>
            ) : (
                <>
                    <div>
                        <p className="text-sm font-semibold">{count ? `${count} ${count === 1 ? 'change' : 'changes'} to review` : 'No unsaved changes'}</p>
                        <p className="text-caption mt-1">{count ? 'Nothing applies until you review and save. Your changes stay while you move between tabs and views.' : 'Changes apply only after you review and save them.'}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" disabled={!count} onClick={onDiscard}>Discard changes</Button>
                        <Button size="sm" disabled={!count} onClick={onReview} data-fk="review">Review changes</Button>
                    </div>
                </>
            )}
        </Card>
    );
}

/** Searchable record selector (POPUP guide): Popover + Command. People who can’t be chosen stay listed with the reason. */
export type PickItem = { id: string; name: string; sub: string; ok: boolean; why?: string };
export function RecordPicker({ id, label, value, items: people, onChange, error, required, foot, placeholder = 'Search and choose a person', search = 'Search by name…' }: { id: string; label: string; value: string; items: PickItem[]; onChange: (id: string) => void; error?: string; required?: boolean; foot?: string; placeholder?: string; search?: string }) {
    const [open, setOpen] = useState(false);
    const sel = people.find((x) => x.id === value);
    return (
        <div className="min-w-0">
            <Label id={`${id}-label`} htmlFor={id} className="mb-1.5 flex items-center gap-1.5">{label}{required ? <span className="text-status-critical">*</span> : null}</Label>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button id={id} variant="outline" role="combobox" aria-expanded={open} aria-invalid={!!error || undefined} aria-describedby={error ? `${id}-error` : undefined} className={cn('h-auto min-h-10 w-full justify-between py-2 text-left font-normal', !sel && 'text-muted-foreground')}>
                        <span className="flex min-w-0 items-center gap-2">
                            <Search className="size-4 shrink-0" />
                            {sel ? <span className="min-w-0"><span className="block truncate font-semibold text-foreground">{sel.name}</span><span className="text-caption block truncate">{sel.sub}</span></span> : placeholder}
                        </span>
                        <ChevronDown className="size-4 shrink-0 opacity-60" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-[--radix-popover-trigger-width] min-w-[360px] p-0" align="start">
                    <Command>
                        <CommandInput placeholder={search} />
                        <CommandList>
                            <CommandEmpty>No one matches. Check the spelling, or clear the search.</CommandEmpty>
                            {people.map((x) => (
                                <CommandItem key={x.id} value={`${x.name} ${x.sub}`} disabled={!x.ok} onSelect={() => { onChange(x.id); setOpen(false); }}>
                                    <Check className={cn('size-4', value === x.id ? 'opacity-100' : 'opacity-0')} />
                                    <span className="min-w-0">
                                        <span className="block font-medium">{x.name}</span>
                                        <span className="text-caption block">{x.sub}{x.ok ? '' : ` · can’t be chosen: ${x.why}`}</span>
                                    </span>
                                </CommandItem>
                            ))}
                        </CommandList>
                    </Command>
                    {foot ? <p className="text-caption border-t p-2">{foot}</p> : null}
                </PopoverContent>
            </Popover>
            <FieldErr id={`${id}-error`}>{error}</FieldErr>
        </div>
    );
}

/** One place per table for the right-click menu (the same items as the kebab). */
export function RowMenu<T>({ ctx, close, title, icon, items }: { ctx: { x: number; y: number; record: T } | null; close: () => void; title: (r: T) => string; icon?: LucideIcon; items: (r: T) => MenuItem[] }) {
    if (!ctx) return null;
    return <EntityContextMenu x={ctx.x} y={ctx.y} icon={icon} title={title(ctx.record)} items={items(ctx.record)} onClose={close} />;
}

/** A plain-language list of what someone can or can’t do (icon + words — colour is never the only signal). */
export function CanList({ items }: { items: { v: 'yes' | 'no' | 'part' | 'na'; head?: string; t: string }[] }) {
    const tone = { yes: 'success', no: 'critical', part: 'warning', na: 'neutral' } as const;
    const word = { yes: 'Yes', no: 'No', part: 'With conditions', na: 'Not checked' } as const;
    return (
        <ul className="divide-y divide-border rounded-xl border border-border">
            {items.map((a, i) => (
                <li key={i} className="flex items-start gap-3 p-3">
                    <StatusBadge variant={tone[a.v]} size="sm" className="mt-0.5 shrink-0">{word[a.v]}</StatusBadge>
                    <span className="min-w-0 text-[13px]">{a.head ? <><span className="block font-semibold">{a.head}</span><span className="text-subtle block">{a.t}</span></> : a.t}</span>
                </li>
            ))}
        </ul>
    );
}

export const KV = ({ rows }: { rows: [string, ReactNode][] }) => (
    <dl className="grid gap-x-6 gap-y-2 text-[13px] sm:grid-cols-[minmax(160px,240px)_1fr]">
        {rows.map(([k, v]) => (
            <div key={k} className="contents"><dt className="text-muted-foreground">{k}</dt><dd className="m-0">{v}</dd></div>
        ))}
    </dl>
);

/* ── v3: Fleet page structure ───────────────────────────────────────────────────────────────
 * Settings sit in titled groups (icon header + divided rows), two per line where they fit, instead of a
 * long stack of identical full-width cards. Each view opens with an Overview of ReviewCards (Fleet Tracking). */
/** Internal decision and package codes stay out of product copy (P00 help text carries a few). */
export const plain = (t?: string) => (t ?? '').replace(/\s*\((?:D\d+|NF-\d+|EM-\d+|P\d{2}[a-z]?)\)/g, '');
export function SettingGroup({ icon: Icon, title, caption, children, wide, id }: { icon: LucideIcon; title: string; caption?: string; children: ReactNode; wide?: boolean; id?: string }) {
    return (
        <Card className={cn('gap-0 overflow-hidden p-0 [&:not(:has([data-setting]))]:hidden', wide && 'lg:col-span-2')} data-group={id}>
            <div className="flex items-start gap-3 border-b border-border p-4">
                <span className="shrink-0 rounded-lg bg-primary/10 p-2 text-primary"><Icon className="size-4" /></span>
                <div className="min-w-0"><h3 className="text-sm font-semibold">{title}</h3>{caption ? <p className="text-caption mt-0.5">{caption}</p> : null}</div>
            </div>
            <div className="divide-y divide-border">{children}</div>
        </Card>
    );
}
/** One setting inside a group: label and a one-line hint left, control right; a choice or list below. */
export function GroupRow({ id, label, hint, control, children, state, error, errorId, hidden }: {
    id?: string; label: string; hint?: string; control?: ReactNode; children?: ReactNode; state?: 'default' | 'nc' | 'changed' | null; error?: string; errorId?: string; hidden?: boolean;
}) {
    if (hidden) return null;
    return (
        <div className="space-y-2 px-4 py-3" data-setting={id}>
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <Label htmlFor={id} className="text-[13px] leading-snug">{label}</Label>
                        {state === 'changed' ? <Changed /> : state === 'nc' ? <NotConfigured /> : state === 'default' ? <DefaultNotReviewed /> : null}
                    </div>
                    {hint ? <p className="text-caption mt-0.5">{plain(hint)}</p> : null}
                </div>
                {control ? <div className="shrink-0">{control}</div> : null}
            </div>
            {children ? <div className="space-y-2">{children}</div> : null}
            <FieldErr id={errorId}>{error}</FieldErr>
        </div>
    );
}
/** Two groups per line where they fit. `empty` shows only when search or the filter hides every setting. */
export const GroupGrid = ({ children, empty }: { children: ReactNode; empty?: ReactNode }) => (
    <>
        <div className="peer grid items-start gap-5 lg:grid-cols-2">{children}</div>
        {empty ? <div className="hidden peer-[:not(:has([data-setting]))]:block">{empty}</div> : null}
    </>
);
/** Overview (Fleet Tracking): caption + a two-column grid of ReviewCards, each with its current state and a way in. */
export type OverviewCard = { icon: LucideIcon; title: string; lines: ReactNode[]; badge?: ReactNode; cta: string; onClick: () => void };
export function Overview({ title, caption, cards: all, note, q = '' }: { title: string; caption: string; cards: OverviewCard[]; note?: ReactNode; q?: string }) {
    const cards = all.filter((c) => !q || [c.title, ...c.lines.map((l) => (typeof l === 'string' ? l : ''))].join(' ').toLowerCase().includes(q.toLowerCase()));
    return (
        <div className="space-y-5">
            <ListCaption title={title} caption={caption} />
            {cards.length ? null : <p className="text-subtle">Nothing here matches “{q}”.</p>}
            <div className="grid gap-5 lg:grid-cols-2">
                {cards.map((c) => (
                    <ReviewCard key={c.title} icon={c.icon} title={c.title}>
                        <div className="space-y-2">
                            {c.lines.map((l, i) => <p key={i} className="text-subtle">{l}</p>)}
                            {c.badge ? <div>{c.badge}</div> : null}
                            <Button variant="link" onClick={c.onClick}>{c.cta}{' '}<ArrowUpRight className="size-4" /></Button>
                        </div>
                    </ReviewCard>
                ))}
            </div>
            {note ? <Note>{note}</Note> : null}
        </div>
    );
}
