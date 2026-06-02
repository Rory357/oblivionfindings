import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import axios from 'axios';
import {
    ArrowRightToLine,
    CalendarCog,
    CalendarPlus,
    ChartColumn,
    Check,
    ChefHat,
    ChevronDown,
    ChevronRight,
    CircleCheck,
    Clock,
    Copy,
    DollarSign,
    Eraser,
    Eye,
    History,
    Info,
    LayoutTemplate,
    Leaf,
    Lock,
    MoreVertical,
    Pencil,
    Plus,
    Printer,
    RotateCcw,
    ShieldAlert,
    ShieldCheck,
    ShoppingBag,
    Soup,
    StickyNote,
    ThumbsDown,
    Trash2,
    TriangleAlert,
    Users,
    Utensils,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { toast } from 'sonner';
import { announce } from './_announcer';
import {
    addDays,
    conflictsFor,
    dietaryMismatches,
    entryAllergenResidents,
    entryDisplayName,
    entryHasCriticalAllergen,
    entryTextureResidents,
    firstName,
    formatMoneyFromCents as money,
    hueStyle,
    mealCostCents,
    MEAL_SLOTS,
    residentRelation,
    SLOT_ICON,
    SLOT_LABEL,
    SLOT_TIME,
    toIsoDate,
    type IddsiLevel,
    type MealSlot,
    type PlanEntry,
    type RecipeFull,
    type RecipeMap,
    type Resident,
    type WeekTemplate,
} from './_helpers';

const DAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const DAY_FULL = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

function Avatar({ initials, hue, size = 26 }: { initials: string; hue: number; size?: number }) {
    return (
        <span className="inline-flex shrink-0 items-center justify-center rounded-full font-semibold" style={{ ...hueStyle(hue), width: size, height: size, fontSize: size * 0.4 }}>
            {initials}
        </span>
    );
}

function RecipeTagChip({ label }: { label: string }) {
    return <span className="rounded-full bg-muted px-1.5 py-px text-[10px] font-medium text-muted-foreground">{label}</span>;
}

/* ── Hover detail card ─────────────────────────────────────────────────── */
function MealHoverCard({ entry, residents, recipes, anchorRect }: { entry: PlanEntry; residents: Resident[]; recipes: RecipeMap; anchorRect: DOMRect }) {
    const isTakeaway = entry.source_type === 'takeaway';
    const isAdhoc = entry.source_type === 'ad_hoc';
    const recipe = entry.recipe_id != null ? recipes.get(entry.recipe_id) : null;
    const { hard, soft } = conflictsFor(entry, residents, recipes);
    const overridden = !!entry.allergen_override_at;
    const unresolved = hard.length > 0 && !overridden;
    const textureAssignees = entryTextureResidents(entry, residents);
    const dietMismatch = dietaryMismatches(entry, residents, recipes);
    const adHocAllergenAssignees = isAdhoc || isTakeaway ? entryAllergenResidents(entry, residents) : [];
    const cost = mealCostCents(entry, recipes);
    const dateLabel = new Date(entry.plan_date).toLocaleDateString('en-NZ', { weekday: 'long', day: 'numeric', month: 'short' });
    const involved = (entry.client_ids ?? []).map((id) => residents.find((r) => r.id === id)).filter(Boolean) as Resident[];

    const W = 280;
    let left = anchorRect.right + 10;
    if (left + W > window.innerWidth - 8) left = Math.max(8, anchorRect.left - W - 10);
    let top = Math.min(anchorRect.top, window.innerHeight - 300);
    top = Math.max(8, top);

    const SrcIcon = isTakeaway ? ShoppingBag : isAdhoc ? Utensils : ChefHat;
    const srcLabel = isTakeaway ? 'Takeaway' : isAdhoc ? 'Ad-hoc cook' : 'From recipe';

    return createPortal(
        <div className="animate-pop pointer-events-none fixed z-[110] w-[280px] overflow-hidden rounded-xl border border-border bg-popover shadow-float" style={{ left, top }}>
            <div className="flex items-start gap-2.5 border-b border-border px-3.5 py-3">
                <span className={cn('mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', isTakeaway ? 'bg-amberx-bg text-amberx' : 'bg-sites-bg text-sites-deep')}>
                    <SrcIcon className="h-4 w-4" />
                </span>
                <div className="min-w-0 flex-1">
                    <div className="text-[14px] font-semibold leading-tight text-foreground">{entryDisplayName(entry, recipes)}</div>
                    <div className="mt-0.5 flex items-center gap-1.5 text-[11.5px] text-muted-foreground">
                        <span>{srcLabel}</span>
                        {entry.served_at && (
                            <>
                                <span className="text-border">•</span>
                                <span className="inline-flex items-center gap-1 text-status-success"><CircleCheck className="h-3 w-3" /> Served {new Date(entry.served_at).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' })}</span>
                            </>
                        )}
                    </div>
                </div>
            </div>
            <div className="space-y-2 px-3.5 py-3 text-[12.5px]">
                <div className="flex items-center gap-2 text-muted-foreground">
                    <span className="text-foreground">{dateLabel}</span>
                    <span className="text-border">•</span>
                    <span>{SLOT_LABEL[entry.meal_slot]} · {SLOT_TIME[entry.meal_slot]}</span>
                </div>
                <div className="flex items-center gap-4 text-muted-foreground">
                    <span className="inline-flex items-center gap-1.5"><Users className="h-3.5 w-3.5" /> <span className="text-foreground">{entry.servings}</span> serves</span>
                    {cost > 0 && <span className="inline-flex items-center gap-1.5"><DollarSign className="h-3.5 w-3.5" /> <span className="tabular-nums text-foreground">{money(cost)}</span></span>}
                    {recipe && (recipe.prep_minutes || recipe.cook_minutes) ? (
                        <span className="inline-flex items-center gap-1.5"><Clock className="h-3.5 w-3.5" /> {(recipe.prep_minutes ?? 0) + (recipe.cook_minutes ?? 0)}m</span>
                    ) : null}
                </div>
                {entry.takeaway_reference && <div className="text-muted-foreground">Order {entry.takeaway_reference}</div>}
                {recipe && recipe.tags.length > 0 && <div className="flex flex-wrap gap-1 pt-0.5">{recipe.tags.map((t) => <RecipeTagChip key={t.id} label={t.label} />)}</div>}
                {involved.length > 0 && (
                    <div className="flex items-center gap-2 pt-0.5">
                        <div className="flex -space-x-1.5">{involved.slice(0, 6).map((r) => <Avatar key={r.id} initials={r.initials} hue={r.hue} size={22} />)}</div>
                        <span className="text-[11.5px] text-muted-foreground">{involved.length} resident{involved.length === 1 ? '' : 's'}</span>
                    </div>
                )}
                {unresolved && (
                    <div className="flex items-start gap-1.5 rounded-lg bg-status-critical-bg/70 px-2.5 py-1.5 text-[11.5px] font-medium text-status-critical">
                        <ShieldAlert className="mt-px h-3.5 w-3.5 shrink-0" />
                        <span>{entryHasCriticalAllergen(entry, residents, recipes) && <span className="mr-1 rounded-full bg-status-critical px-1 text-[8.5px] font-bold uppercase text-white">Critical</span>}Allergen conflict: {hard.map((h) => `${h.resident.name.split(' ')[0]} (${h.matches.join(', ')})`).join('; ')}</span>
                    </div>
                )}
                {overridden && (
                    <div className="flex items-start gap-1.5 rounded-lg bg-amberx-bg/70 px-2.5 py-1.5 text-[11.5px] font-medium text-amberx">
                        <Lock className="mt-px h-3.5 w-3.5 shrink-0" />
                        <span className="min-w-0">
                            {entry.allergen_override_reason ? (
                                <>
                                    &ldquo;{entry.allergen_override_reason}&rdquo;
                                    {entry.allergen_override_by && entry.allergen_override_at && (
                                        <span className="mt-0.5 block font-normal text-amberx/80">Approved by {entry.allergen_override_by.name} · {new Date(entry.allergen_override_at).toLocaleString('en-NZ')}</span>
                                    )}
                                </>
                            ) : (
                                'Override on file — separate portion plated'
                            )}
                        </span>
                    </div>
                )}
                {!unresolved && !overridden && soft.length > 0 && (
                    <div className="flex items-start gap-1.5 rounded-lg bg-status-warning-bg/70 px-2.5 py-1.5 text-[11.5px] font-medium text-status-warning">
                        <TriangleAlert className="mt-px h-3.5 w-3.5 shrink-0" />
                        <span>Dislikes: {soft.map((s) => s.resident.name.split(' ')[0]).join(', ')}</span>
                    </div>
                )}
                {textureAssignees.length > 0 && (
                    <div className="flex items-start gap-1.5 rounded-lg bg-status-warning-bg/70 px-2.5 py-1.5 text-[11.5px] font-medium text-status-warning">
                        <Soup className="mt-px h-3.5 w-3.5 shrink-0" />
                        <span>Texture-modified: {textureAssignees.map((r) => `${firstName(r.name)} IDDSI ${r.texture!.level} (${r.texture!.label})`).join('; ')}{textureAssignees.some((r) => r.fluids) ? ` · fluids: ${textureAssignees.filter((r) => r.fluids).map((r) => `${firstName(r.name)} ${r.fluids}`).join(', ')}` : ''}</span>
                    </div>
                )}
                {dietMismatch.length > 0 && (
                    <div className="flex items-start gap-1.5 rounded-lg bg-status-warning-bg/70 px-2.5 py-1.5 text-[11.5px] font-medium text-status-warning">
                        <Leaf className="mt-px h-3.5 w-3.5 shrink-0" />
                        <span>Diet check: {dietMismatch.map((m) => `${firstName(m.resident.name)} (${m.requirements.join(', ')})`).join('; ')}</span>
                    </div>
                )}
                {adHocAllergenAssignees.length > 0 && !unresolved && (
                    <div className="flex items-start gap-1.5 rounded-lg bg-status-warning-bg/70 px-2.5 py-1.5 text-[11.5px] font-medium text-status-warning">
                        <ShieldAlert className="mt-px h-3.5 w-3.5 shrink-0" />
                        <span>Check allergens: {adHocAllergenAssignees.map((r) => `${firstName(r.name)} (${r.allergens.join(', ')})`).join('; ')}</span>
                    </div>
                )}
                {entry.notes && (
                    <div className="flex items-start gap-1.5 border-t border-border pt-2 text-[11.5px] text-muted-foreground">
                        <StickyNote className="mt-px h-3.5 w-3.5 shrink-0" /> <span className="italic">{entry.notes}</span>
                    </div>
                )}
            </div>
            <div className="border-t border-border bg-muted/40 px-3.5 py-1.5 text-[10.5px] text-muted-foreground">Click to edit · right-click for options</div>
        </div>,
        document.body,
    );
}

/* ── Context menu ──────────────────────────────────────────────────────── */
type EntryAction = 'edit' | 'toggle-served' | 'move' | 'duplicate' | 'copy-next' | 'delete';
function MealContextMenu({ entry, recipes, pos, onClose, onAction }: { entry: PlanEntry; recipes: RecipeMap; pos: { x: number; y: number }; onClose: () => void; onAction: (a: EntryAction, e: PlanEntry) => void }) {
    const ref = useRef<HTMLDivElement>(null);
    useEffect(() => {
        function onDoc(e: MouseEvent) {
            if (ref.current && !ref.current.contains(e.target as Node)) onClose();
        }
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape') onClose();
        }
        document.addEventListener('mousedown', onDoc);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDoc);
            document.removeEventListener('keydown', onKey);
        };
    }, [onClose]);
    // Move focus into the menu on open so it's operable by keyboard.
    useEffect(() => {
        ref.current?.querySelector<HTMLElement>('[role="menuitem"]')?.focus();
    }, []);
    function onMenuKeyDown(e: React.KeyboardEvent) {
        const els = Array.from(ref.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []);
        if (els.length === 0) return;
        const idx = els.indexOf(document.activeElement as HTMLElement);
        if (e.key === 'ArrowDown') { e.preventDefault(); els[(idx + 1) % els.length]?.focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); els[(idx - 1 + els.length) % els.length]?.focus(); }
    }

    const left = Math.min(pos.x, window.innerWidth - 218);
    const top = Math.min(pos.y, window.innerHeight - 280);
    const served = !!entry.served_at;
    const items: ({ key: EntryAction; label: string; icon: LucideIcon; danger?: boolean } | { sep: true })[] = [
        { key: 'edit', label: 'Edit meal', icon: Pencil },
        { key: 'toggle-served', label: served ? 'Mark not served' : 'Mark as served', icon: served ? RotateCcw : CircleCheck },
        { key: 'move', label: 'Move to day/slot…', icon: CalendarCog },
        { key: 'duplicate', label: 'Duplicate', icon: Copy },
        { key: 'copy-next', label: 'Copy to next day', icon: ArrowRightToLine },
        { sep: true },
        { key: 'delete', label: 'Delete meal', icon: Trash2, danger: true },
    ];

    return createPortal(
        <div ref={ref} role="menu" aria-label={`Actions for ${entryDisplayName(entry, recipes)}`} onKeyDown={onMenuKeyDown} className="animate-pop fixed z-[130] w-[210px] overflow-hidden rounded-xl border border-border bg-popover p-1 shadow-float" style={{ left, top }}>
            <div className="truncate px-2.5 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{entryDisplayName(entry, recipes)}</div>
            {items.map((it, i) =>
                'sep' in it ? (
                    <div key={i} className="my-1 h-px bg-border" />
                ) : (
                    <button key={it.key} type="button" role="menuitem" onClick={() => { onAction(it.key, entry); onClose(); }} className={cn('flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-[13px] font-medium transition-colors focus-visible:outline-none focus-visible:bg-accent', it.danger ? 'text-status-critical hover:bg-status-critical-bg/60 focus-visible:bg-status-critical-bg/60' : 'text-foreground hover:bg-accent')}>
                        <it.icon className={cn('h-[15px] w-[15px]', it.danger ? 'text-status-critical' : 'text-muted-foreground')} aria-hidden="true" /> {it.label}
                    </button>
                ),
            )}
        </div>,
        document.body,
    );
}

/* ── Meal card ─────────────────────────────────────────────────────────── */
function MealCard({ entry, residents, recipes, focusResident, slot, dayLabel, onClick, onAction, draggable, canPlan }: { entry: PlanEntry; residents: Resident[]; recipes: RecipeMap; focusResident: Resident | null; slot: MealSlot; dayLabel: string; onClick: () => void; onAction: (a: EntryAction, e: PlanEntry) => void; draggable: boolean; canPlan: boolean }) {
    const isTakeaway = entry.source_type === 'takeaway';
    const isAdhoc = entry.source_type === 'ad_hoc';
    const { hard, soft } = conflictsFor(entry, residents, recipes);
    const overridden = !!entry.allergen_override_at;
    const unresolved = hard.length > 0 && !overridden;
    const served = !!entry.served_at;
    const name = entryDisplayName(entry, recipes);
    const cost = mealCostCents(entry, recipes);
    // Resident-aware advisory pills (P0-4/5/10) — surfaced from assigned residents.
    const textureAssignees = entryTextureResidents(entry, residents);
    const dietMismatch = dietaryMismatches(entry, residents, recipes);
    const adHocAllergenAssignees = isTakeaway || isAdhoc ? entryAllergenResidents(entry, residents) : [];

    const rel = focusResident ? residentRelation(entry, focusResident, recipes) : null;
    const dimmed = !!focusResident && !rel?.involved;
    const spotlightClash = rel && rel.involved ? rel.clash : null;

    let border = 'border-border bg-card hover:border-primary/40';
    let leftBar = 'before:bg-transparent';
    if (unresolved) {
        border = 'border-status-critical/40 bg-status-critical-bg/50 hover:border-status-critical/60';
        leftBar = 'before:bg-status-critical';
    } else if (overridden) {
        border = 'border-amberx/50 bg-amberx-bg/60 hover:border-amberx';
        leftBar = 'before:bg-amberx';
    } else if (isTakeaway) {
        border = 'border-amberx/40 bg-amberx-bg/50 hover:border-amberx';
    } else if (served) {
        border = 'border-status-success/30 bg-status-success-bg/40 hover:border-status-success/50';
    }

    const SrcIcon = isTakeaway ? ShoppingBag : isAdhoc ? Utensils : ChefHat;
    // Spoken name: meal, slot, day, serves + any safety state (P1-5).
    const cardLabel = `${name}, ${SLOT_LABEL[slot]} ${dayLabel}, ${entry.servings} serves`
        + (unresolved ? ', allergen conflict' : '')
        + (overridden ? ', allergen override on file' : '')
        + (textureAssignees.length ? ', texture-modified diet' : '')
        + (dietMismatch.length ? ', dietary requirement to confirm' : '')
        + (adHocAllergenAssignees.length ? ', check allergens' : '')
        + (served ? ', served' : '');
    const [hover, setHover] = useState(false);
    const [hoverRect, setHoverRect] = useState<DOMRect | null>(null);
    const [menu, setMenu] = useState<{ x: number; y: number } | null>(null);
    const cardRef = useRef<HTMLButtonElement>(null);
    const hoverTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    function openHover() {
        if (hoverTimer.current) clearTimeout(hoverTimer.current);
        hoverTimer.current = setTimeout(() => {
            if (cardRef.current) setHoverRect(cardRef.current.getBoundingClientRect());
            setHover(true);
        }, 240);
    }
    function closeHover() {
        if (hoverTimer.current) clearTimeout(hoverTimer.current);
        setHover(false);
    }
    useEffect(() => () => { if (hoverTimer.current) clearTimeout(hoverTimer.current); }, []);

    return (
        <div className="group/card relative">
            <button
                ref={cardRef}
                type="button"
                aria-label={cardLabel}
                draggable={draggable}
                onDragStart={(e) => {
                    closeHover();
                    e.dataTransfer.setData('text/plain', String(entry.id));
                    e.dataTransfer.effectAllowed = 'move';
                }}
                onClick={onClick}
                onMouseEnter={openHover}
                onMouseLeave={closeHover}
                onContextMenu={(e) => {
                    e.preventDefault();
                    closeHover();
                    setMenu({ x: e.clientX, y: e.clientY });
                }}
                className={cn(
                    'relative w-full overflow-hidden rounded-lg border px-2 py-1.5 text-left shadow-sm transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    draggable && 'cursor-grab active:cursor-grabbing',
                    'before:absolute before:inset-y-0 before:left-0 before:w-[3px] before:content-[""]',
                    leftBar,
                    border,
                    dimmed && 'opacity-35 saturate-50',
                    spotlightClash === 'allergen' && 'ring-2 ring-status-critical ring-offset-1',
                    spotlightClash === 'dislike' && 'ring-2 ring-status-warning ring-offset-1',
                )}
            >
                {rel && rel.involved && !spotlightClash && (
                    <span className="absolute right-1.5 top-1.5 z-10 flex h-4 w-4 items-center justify-center rounded-full bg-sites text-primary-foreground" aria-label="On this resident's plan">
                        <Check className="h-2.5 w-2.5" strokeWidth={3} />
                    </span>
                )}
                <div className="flex items-start gap-1.5 pl-1">
                    <SrcIcon className={cn('mt-0.5 h-3.5 w-3.5 shrink-0', isTakeaway ? 'text-amberx' : unresolved ? 'text-status-critical' : 'text-muted-foreground')} />
                    <span className="line-clamp-2 flex-1 pr-12 text-[12px] font-semibold leading-tight text-foreground">{name}</span>
                </div>
                <div className="mt-1 flex items-center justify-between pl-1 text-[10.5px] text-muted-foreground">
                    <span>{isTakeaway ? money(cost) : `${entry.servings} serves`}</span>
                    {served && <CircleCheck className="h-3 w-3 text-status-success" aria-hidden="true" />}
                </div>
                {(unresolved || overridden || isTakeaway || textureAssignees.length > 0 || dietMismatch.length > 0 || adHocAllergenAssignees.length > 0) && (
                    <div className="mt-1 flex flex-wrap gap-1 pl-1">
                        {unresolved && <span className="rounded-full bg-status-critical px-1.5 py-px text-[9px] font-bold uppercase tracking-wide text-white">Allergen</span>}
                        {overridden && <span className="rounded-full bg-amberx px-1.5 py-px text-[9px] font-bold uppercase tracking-wide text-white">Override</span>}
                        {isTakeaway && !unresolved && <span className="rounded-full border border-amberx/40 px-1.5 py-px text-[9px] font-semibold uppercase tracking-wide text-amberx">Takeaway</span>}
                        {textureAssignees.length > 0 && <span className="inline-flex items-center gap-0.5 rounded-full bg-status-warning px-1.5 py-px text-[9px] font-bold uppercase tracking-wide text-white"><Soup className="h-2.5 w-2.5" aria-hidden="true" />Texture</span>}
                        {dietMismatch.length > 0 && <span className="inline-flex items-center gap-0.5 rounded-full border border-status-warning/50 px-1.5 py-px text-[9px] font-semibold uppercase tracking-wide text-status-warning"><Leaf className="h-2.5 w-2.5" aria-hidden="true" />Diet check</span>}
                        {adHocAllergenAssignees.length > 0 && !unresolved && <span className="inline-flex items-center gap-0.5 rounded-full border border-status-warning/50 px-1.5 py-px text-[9px] font-semibold uppercase tracking-wide text-status-warning"><ShieldAlert className="h-2.5 w-2.5" aria-hidden="true" />Check allergens</span>}
                    </div>
                )}
            </button>

            {/* Quick-serve + overflow actions — siblings of the card button (no nested buttons). */}
            {canPlan && (
                <div className={cn('absolute right-1 top-1 z-20 flex items-center gap-0.5 transition-opacity', served ? 'opacity-100' : 'pointer-events-none opacity-0 group-hover/card:pointer-events-auto group-hover/card:opacity-100 focus-within:pointer-events-auto focus-within:opacity-100')}>
                    <button
                        type="button"
                        aria-label={served ? `Mark ${name} not served` : `Mark ${name} served`}
                        onClick={(e) => { e.stopPropagation(); onAction('toggle-served', entry); }}
                        className={cn('flex h-6 w-6 items-center justify-center rounded-full border shadow-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', served ? 'border-status-success bg-status-success text-white hover:opacity-90' : 'border-border bg-card text-muted-foreground hover:border-status-success hover:text-status-success')}
                    >
                        {served ? <RotateCcw className="h-3.5 w-3.5" /> : <CircleCheck className="h-3.5 w-3.5" />}
                    </button>
                    <button
                        type="button"
                        aria-label={`Actions for ${name}`}
                        aria-haspopup="menu"
                        onClick={(e) => { e.stopPropagation(); closeHover(); const r = e.currentTarget.getBoundingClientRect(); setMenu({ x: Math.min(r.left, window.innerWidth - 218), y: r.bottom + 2 }); }}
                        className="flex h-6 w-6 items-center justify-center rounded-full border border-border bg-card text-muted-foreground shadow-sm transition hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <MoreVertical className="h-3.5 w-3.5" />
                    </button>
                </div>
            )}
            {hover && !menu && hoverRect && <MealHoverCard entry={entry} residents={residents} recipes={recipes} anchorRect={hoverRect} />}
            {menu && <MealContextMenu entry={entry} recipes={recipes} pos={menu} onClose={() => setMenu(null)} onAction={onAction} />}
        </div>
    );
}

/* ── Resident chip + hover + edit ──────────────────────────────────────── */
function HoverRow({ icon: Icon, label, value, tone }: { icon: LucideIcon; label: string; value: string; tone?: string }) {
    return (
        <div className="flex items-start gap-2 text-[12px]">
            <Icon className={cn('mt-0.5 h-3.5 w-3.5 shrink-0', tone ?? 'text-muted-foreground')} />
            <span className="w-14 shrink-0 text-muted-foreground">{label}</span>
            <span className="flex-1 font-medium text-foreground">{value}</span>
        </div>
    );
}

function ResidentHoverCard({ resident, entries, recipes, anchorRect }: { resident: Resident; entries: PlanEntry[]; recipes: RecipeMap; anchorRect: DOMRect }) {
    let involved = 0;
    let clashes = 0;
    entries.forEach((e) => {
        const rel = residentRelation(e, resident, recipes);
        if (rel.involved) {
            involved += 1;
            if (rel.clash === 'allergen') clashes += 1;
        }
    });
    const W = 256;
    let left = anchorRect.left;
    if (left + W > window.innerWidth - 8) left = window.innerWidth - W - 8;
    let top = anchorRect.bottom + 8;
    if (top + 220 > window.innerHeight) top = Math.max(8, anchorRect.top - 228);

    return createPortal(
        <div className="animate-pop pointer-events-none fixed z-[110] w-[256px] overflow-hidden rounded-xl border border-border bg-popover shadow-float" style={{ left, top }}>
            <div className="flex items-center gap-2.5 border-b border-border px-3.5 py-3">
                <Avatar initials={resident.initials} hue={resident.hue} size={34} />
                <div className="min-w-0">
                    <div className="text-[14px] font-semibold leading-tight text-foreground">{resident.name}</div>
                    <div className="text-[11px] text-muted-foreground">{involved} meal{involved === 1 ? '' : 's'} this week</div>
                </div>
            </div>
            <div className="space-y-1.5 px-3.5 py-3">
                <HoverRow icon={ShieldAlert} label="Allergens" tone={resident.allergens.length ? 'text-status-critical' : 'text-muted-foreground'} value={resident.allergens.length ? resident.allergens.join(', ') : 'None'} />
                <HoverRow icon={Leaf} label="Dietary" tone="text-sites-deep" value={resident.dietary.length ? resident.dietary.join(', ') : 'None'} />
                {resident.dislikes.length > 0 && <HoverRow icon={ThumbsDown} label="Dislikes" value={resident.dislikes.join(', ')} />}
                {resident.texture && <HoverRow icon={Soup} label="Texture" tone="text-primary" value={`IDDSI ${resident.texture.level} · ${resident.texture.label}`} />}
                {resident.fluids && <HoverRow icon={Soup} label="Fluids" value={resident.fluids} />}
            </div>
            {clashes > 0 && (
                <div className="flex items-center gap-1.5 border-t border-border bg-status-critical-bg/50 px-3.5 py-2 text-[11.5px] font-medium text-status-critical">
                    <ShieldAlert className="h-3.5 w-3.5" /> {clashes} allergen clash{clashes === 1 ? '' : 'es'} in this week's plan
                </div>
            )}
            <div className="border-t border-border bg-muted/40 px-3.5 py-1.5 text-[10.5px] text-muted-foreground">Press Enter to spotlight · edit button to change diet</div>
        </div>,
        document.body,
    );
}

function ResidentChip({ resident, entries, recipes, selected, dimmed, onToggle, onEdit, canEdit }: { resident: Resident; entries: PlanEntry[]; recipes: RecipeMap; selected: boolean; dimmed: boolean; onToggle: () => void; onEdit: () => void; canEdit: boolean }) {
    const r = resident;
    const hasAllergens = r.allergens.length > 0;
    const [hover, setHover] = useState(false);
    const [hoverRect, setHoverRect] = useState<DOMRect | null>(null);
    const ref = useRef<HTMLDivElement>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const open = () => {
        if (timer.current) clearTimeout(timer.current);
        timer.current = setTimeout(() => {
            if (ref.current) setHoverRect(ref.current.getBoundingClientRect());
            setHover(true);
        }, 280);
    };
    const close = () => {
        if (timer.current) clearTimeout(timer.current);
        setHover(false);
    };
    useEffect(() => () => { if (timer.current) clearTimeout(timer.current); }, []);

    return (
        <div ref={ref} onMouseEnter={open} onMouseLeave={close} className={cn('group relative inline-flex items-center gap-1 rounded-full border py-1 pl-1 pr-2 transition-all', selected ? 'border-sites bg-sites-bg ring-1 ring-sites/40' : 'border-border bg-card hover:border-sites/50 hover:bg-sites-bg/40', dimmed && 'opacity-55')}>
            <button
                type="button"
                onClick={onToggle}
                aria-pressed={selected}
                aria-label={`${r.name}${hasAllergens ? `, allergens: ${r.allergens.join(', ')}` : ', no allergens'}${r.texture && r.texture.level < 7 ? `, IDDSI ${r.texture.level} ${r.texture.label}` : ''}${r.fluids ? `, fluids ${r.fluids}` : ''}. Press Enter to spotlight their meals.`}
                className="flex items-center gap-2 rounded-full text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                <Avatar initials={r.initials} hue={r.hue} size={26} />
                <span className="min-w-0">
                    <span className="block text-[12.5px] font-semibold leading-tight text-foreground">{r.name}</span>
                    <span className="flex items-center gap-1 text-[10.5px] leading-tight text-muted-foreground">
                        {hasAllergens ? (
                            <><ShieldAlert className="h-2.5 w-2.5 text-status-critical" /> {r.allergens.join(', ')}</>
                        ) : r.dietary.length ? (
                            <><Leaf className="h-2.5 w-2.5 text-sites-deep" /> {r.dietary.join(', ')}</>
                        ) : (
                            <>No allergens</>
                        )}
                    </span>
                    {r.texture && r.texture.level < 7 && (
                        <span className="mt-0.5 flex items-center gap-1 text-[10px] leading-tight text-primary"><Soup className="h-2.5 w-2.5" /> IDDSI {r.texture.level}</span>
                    )}
                </span>
            </button>
            {canEdit && (
                <button type="button" onClick={(e) => { e.stopPropagation(); close(); onEdit(); }} aria-label={`Edit ${r.name}'s dietary profile`} className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-muted-foreground opacity-70 transition-all hover:bg-card hover:text-foreground focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring group-hover:opacity-100">
                    <Pencil className="h-3.5 w-3.5" />
                </button>
            )}
            {hover && hoverRect && <ResidentHoverCard resident={r} entries={entries} recipes={recipes} anchorRect={hoverRect} />}
        </div>
    );
}

/* ── Resident dietary editor ───────────────────────────────────────────── */
function ResidentEditDialog({ siteId, resident, dietaryTags, iddsiLevels, onClose, onSaved }: { siteId: number; resident: Resident; dietaryTags: { id: number; label: string; kind: 'allergen' | 'dietary' }[]; iddsiLevels: IddsiLevel[]; onClose: () => void; onSaved: () => void }) {
    const allergenTags = dietaryTags.filter((t) => t.kind === 'allergen');
    const dietaryTagOpts = dietaryTags.filter((t) => t.kind === 'dietary');
    const [tagIds, setTagIds] = useState<number[]>([...resident.allergen_tag_ids, ...resident.dietary_tag_ids]);
    const [dislikes, setDislikes] = useState(resident.dislikes.join(', '));
    const [textureLevel, setTextureLevel] = useState<number>(resident.texture?.level ?? 7);
    const [fluids, setFluids] = useState(resident.fluids ?? '');
    const [saving, setSaving] = useState(false);

    function toggle(id: number) {
        setTagIds((cur) => (cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id]));
    }

    async function save() {
        setSaving(true);
        const lvl = iddsiLevels.find((l) => l.level === Number(textureLevel));
        try {
            await axios.put(`/sites/${siteId}/meal-planner/residents/${resident.id}`, {
                tag_ids: tagIds,
                dislikes: dislikes.split(',').map((s) => s.trim()).filter(Boolean),
                iddsi_level: lvl && lvl.level < 7 ? lvl.level : null,
                iddsi_label: lvl && lvl.level < 7 ? lvl.label : null,
                fluids: fluids.trim() || null,
            });
            toast.success('Resident dietary profile updated');
            onSaved();
            onClose();
        } catch {
            toast.error('Could not save profile');
        } finally {
            setSaving(false);
        }
    }

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2"><Pencil className="h-4 w-4 text-sites" /> Edit {resident.name}</DialogTitle>
                    <DialogDescription>Dietary profile used for live allergen checks across the meal plan.</DialogDescription>
                </DialogHeader>
                <div className="space-y-4">
                    <div className="flex items-start gap-2 rounded-lg border border-border bg-muted/40 p-2.5 text-xs text-muted-foreground">
                        <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                        <span>Allergen changes immediately re-check every planned meal for this resident.</span>
                    </div>
                    <div>
                        <Label className="mb-1.5 block">Allergens</Label>
                        <div className="flex flex-wrap gap-1.5">
                            {allergenTags.map((a) => {
                                const sel = tagIds.includes(a.id);
                                return (
                                    <button key={a.id} type="button" onClick={() => toggle(a.id)} className={cn('rounded-full border px-2.5 py-1 text-[12px] font-medium transition-colors', sel ? 'border-status-critical bg-status-critical-bg text-status-critical' : 'border-border bg-card text-muted-foreground hover:bg-accent')}>
                                        {sel && <Check className="mr-0.5 inline h-3 w-3" />}{a.label}
                                    </button>
                                );
                            })}
                            {allergenTags.length === 0 && <span className="text-xs text-muted-foreground">No allergen tags configured.</span>}
                        </div>
                    </div>
                    <div>
                        <Label className="mb-1.5 block">Dietary requirements</Label>
                        <div className="flex flex-wrap gap-1.5">
                            {dietaryTagOpts.map((d) => {
                                const sel = tagIds.includes(d.id);
                                return (
                                    <button key={d.id} type="button" onClick={() => toggle(d.id)} className={cn('rounded-full border px-2.5 py-1 text-[12px] font-medium transition-colors', sel ? 'border-sites bg-sites-bg text-sites-deep' : 'border-border bg-card text-muted-foreground hover:bg-accent')}>
                                        {sel && <Check className="mr-0.5 inline h-3 w-3" />}{d.label}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <Label>Texture (IDDSI)</Label>
                            <Select value={String(textureLevel)} onValueChange={(v) => setTextureLevel(Number(v))}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {iddsiLevels.map((l) => (
                                        <SelectItem key={l.level} value={String(l.level)}>Level {l.level} · {l.label}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label>Fluids</Label>
                            <Input value={fluids} onChange={(e) => setFluids(e.target.value)} placeholder="e.g. Mildly thick (L2)" />
                        </div>
                    </div>
                    <div>
                        <Label>Dislikes <span className="font-normal text-muted-foreground">(comma-separated)</span></Label>
                        <Input value={dislikes} onChange={(e) => setDislikes(e.target.value)} placeholder="e.g. Mushrooms, Olives" />
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>Cancel</Button>
                    <Button onClick={save} disabled={saving}><Check className="mr-1.5 h-4 w-4" /> Save profile</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/* ── Week actions menu ─────────────────────────────────────────────────── */
function WeekActionsMenu({ templates, onRepeatLast, onCopyNext, onApplyTemplate, onManage, onClear }: { templates: WeekTemplate[]; onRepeatLast: () => void; onCopyNext: () => void; onApplyTemplate: (t: WeekTemplate) => void; onManage: () => void; onClear: () => void }) {
    const [open, setOpen] = useState(false);
    const [sub, setSub] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    const btnRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);
    useEffect(() => {
        function onDoc(e: MouseEvent) {
            if (ref.current && !ref.current.contains(e.target as Node)) {
                setOpen(false);
                setSub(false);
            }
        }
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, []);
    useEffect(() => {
        if (open) menuRef.current?.querySelector<HTMLElement>('[role="menuitem"]')?.focus();
    }, [open]);
    function close(restoreFocus = true) {
        setOpen(false);
        setSub(false);
        if (restoreFocus) btnRef.current?.focus();
    }
    function onMenuKeyDown(e: React.KeyboardEvent) {
        if (e.key === 'Escape') { e.stopPropagation(); close(); return; }
        const els = Array.from(menuRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []);
        if (els.length === 0) return;
        const idx = els.indexOf(document.activeElement as HTMLElement);
        if (e.key === 'ArrowDown') { e.preventDefault(); els[(idx + 1) % els.length]?.focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); els[(idx - 1 + els.length) % els.length]?.focus(); }
    }
    const item = 'flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-[13px] font-medium transition-colors hover:bg-accent focus-visible:bg-accent focus-visible:outline-none';
    return (
        <div ref={ref} className="relative">
            <Button ref={btnRef} variant="outline" size="sm" aria-haspopup="menu" aria-expanded={open} onClick={() => setOpen((v) => !v)}>
                <CalendarCog className="mr-1.5 h-[15px] w-[15px]" /> Plan week <ChevronDown className="ml-1 h-3 w-3" />
            </Button>
            {open && (
                <div ref={menuRef} role="menu" aria-label="Plan week actions" onKeyDown={onMenuKeyDown} className="animate-pop absolute left-0 z-50 mt-1.5 w-[248px] overflow-hidden rounded-xl border border-border bg-popover p-1 shadow-float">
                    <button type="button" role="menuitem" onClick={() => { onRepeatLast(); close(false); }} className={item}><History className="h-[15px] w-[15px] text-muted-foreground" /> Repeat last week</button>
                    <button type="button" role="menuitem" onClick={() => { onCopyNext(); close(false); }} className={item}><ArrowRightToLine className="h-[15px] w-[15px] text-muted-foreground" /> Copy to next week</button>
                    <div className="relative">
                        <button type="button" role="menuitem" aria-expanded={sub} onClick={() => setSub((v) => !v)} className="flex w-full items-center justify-between gap-2.5 rounded-md px-2.5 py-2 text-left text-[13px] font-medium transition-colors hover:bg-accent focus-visible:bg-accent focus-visible:outline-none">
                            <span className="flex items-center gap-2.5"><LayoutTemplate className="h-[15px] w-[15px] text-muted-foreground" /> Apply a template</span>
                            {sub ? <ChevronDown className="h-3.5 w-3.5 text-muted-foreground" /> : <ChevronRight className="h-3.5 w-3.5 text-muted-foreground" />}
                        </button>
                        {sub && (
                            <div className="ml-3 border-l border-border pl-1">
                                {templates.length === 0 && <div className="px-2.5 py-1.5 text-[11.5px] text-muted-foreground">No templates yet.</div>}
                                {templates.map((t) => (
                                    <button key={t.id} type="button" role="menuitem" onClick={() => { onApplyTemplate(t); close(false); }} className="flex w-full flex-col rounded-md px-2.5 py-1.5 text-left transition-colors hover:bg-accent focus-visible:bg-accent focus-visible:outline-none">
                                        <span className="text-[12.5px] font-medium text-foreground">{t.name}</span>
                                        {t.description && <span className="text-[10.5px] text-muted-foreground">{t.description}</span>}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                    <div className="my-1 h-px bg-border" />
                    <button type="button" role="menuitem" onClick={() => { onManage(); close(false); }} className={item}><LayoutTemplate className="h-[15px] w-[15px] text-muted-foreground" /> Manage templates &amp; budget…</button>
                    <div className="my-1 h-px bg-border" />
                    <button type="button" role="menuitem" onClick={() => { onClear(); close(false); }} className="flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-[13px] font-medium text-status-critical transition-colors hover:bg-status-critical-bg/60 focus-visible:bg-status-critical-bg/60 focus-visible:outline-none"><Eraser className="h-[15px] w-[15px]" /> Clear this week</button>
                </div>
            )}
        </div>
    );
}

/* ── Spend report ──────────────────────────────────────────────────────── */
export function SpendReportDialog({ siteId, currentWeekCents, budgetCents, onClose }: { siteId: number; currentWeekCents: number; budgetCents: number | null; onClose: () => void }) {
    const [history, setHistory] = useState<{ label: string; cents: number; status: string }[]>([]);
    useEffect(() => {
        axios.get(`/sites/${siteId}/meal-shopping-lists`).then((res) => {
            const lists = (res.data.lists ?? []) as { covers_from: string; status: string; items?: { estimated_cost_cents: number | null }[] }[];
            const past = lists
                .filter((l) => l.status === 'received' || l.status === 'ordered')
                .slice(0, 6)
                .reverse()
                .map((l) => ({ label: new Date(l.covers_from).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' }), cents: (l.items ?? []).reduce((s, i) => s + (i.estimated_cost_cents ?? 0), 0), status: l.status }));
            setHistory(past);
        }).catch(() => setHistory([]));
    }, [siteId]);

    const weeks = [...history, { label: 'This week', cents: currentWeekCents, status: 'current' }];
    const max = Math.max(budgetCents ?? 0, ...weeks.map((w) => w.cents), 1);
    const avg = weeks.length ? Math.round(weeks.reduce((s, w) => s + w.cents, 0) / weeks.length) : 0;
    const overCount = budgetCents ? weeks.filter((w) => w.cents > budgetCents).length : 0;

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2"><ChartColumn className="h-4 w-4 text-primary" /> Food spend report</DialogTitle>
                    <DialogDescription>Weekly spend across recent shopping lists and the current plan.</DialogDescription>
                </DialogHeader>
                <div className="space-y-4">
                    <div className="grid grid-cols-3 gap-2">
                        <div className="rounded-xl border border-border bg-muted/40 px-3 py-2.5 text-center">
                            <div className="text-xl font-bold tabular-nums text-foreground">{money(avg)}</div>
                            <div className="text-[11px] font-medium text-muted-foreground">avg / week</div>
                        </div>
                        <div className="rounded-xl border border-border bg-muted/40 px-3 py-2.5 text-center">
                            <div className="text-xl font-bold tabular-nums text-foreground">{budgetCents ? money(budgetCents) : '—'}</div>
                            <div className="text-[11px] font-medium text-muted-foreground">weekly budget</div>
                        </div>
                        <div className={cn('rounded-xl border px-3 py-2.5 text-center', overCount ? 'border-status-warning/40 bg-status-warning-bg/50' : 'border-border bg-muted/40')}>
                            <div className={cn('text-xl font-bold tabular-nums', overCount ? 'text-status-warning' : 'text-foreground')}>{overCount}</div>
                            <div className="text-[11px] font-medium text-muted-foreground">weeks over</div>
                        </div>
                    </div>
                    <div className="rounded-xl border border-border p-4">
                        <div className="flex items-end justify-between gap-2" style={{ height: 160 }}>
                            {weeks.map((w, i) => {
                                const isOver = budgetCents != null && w.cents > budgetCents;
                                const h = Math.max(4, (w.cents / max) * 140);
                                return (
                                    <div key={i} className="flex flex-1 flex-col items-center gap-1.5">
                                        <span className="text-[10px] font-medium tabular-nums text-muted-foreground">{money(w.cents).replace('.00', '')}</span>
                                        <div className="flex w-full items-end justify-center" style={{ height: 140 }}>
                                            <div role="img" aria-label={`${w.label}: ${money(w.cents)}${isOver ? ', over budget' : ''}`} className={cn('w-full max-w-[34px] rounded-t-md', w.status === 'current' ? 'bg-primary' : isOver ? 'bg-status-warning' : 'bg-sites')} style={{ height: h }} />
                                        </div>
                                        <span className={cn('text-[10px]', w.status === 'current' ? 'font-semibold text-primary' : 'text-muted-foreground')}>{w.label}</span>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                    <div className="flex items-start gap-2 rounded-lg border border-border bg-muted/40 p-2.5 text-xs text-muted-foreground">
                        <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                        <span>Figures are estimated from shopping lists; received lists reflect actual spend.</span>
                    </div>
                </div>
                <DialogFooter><Button variant="outline" onClick={onClose}>Close</Button></DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/* ── Allergen overrides rollup (clickable audit, P2-5) ─────────────────── */
export function OverridesDialog({ entries, residents, recipes, onOpenEntry, onClose }: { entries: PlanEntry[]; residents: Resident[]; recipes: RecipeMap; onOpenEntry: (e: PlanEntry) => void; onClose: () => void }) {
    const overrides = entries.filter((e) => e.allergen_override_at).sort((a, b) => (a.plan_date < b.plan_date ? -1 : 1));
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2"><Lock className="h-4 w-4 text-amberx" /> Allergen overrides this week</DialogTitle>
                    <DialogDescription>Every meal where an allergen conflict was knowingly overridden. Click a row to open the meal.</DialogDescription>
                </DialogHeader>
                <div className="space-y-2">
                    {overrides.length === 0 && <div className="rounded-lg border border-dashed border-border px-3 py-6 text-center text-sm text-muted-foreground">No allergen overrides this week.</div>}
                    {overrides.map((e) => {
                        const { hard } = conflictsFor(e, residents, recipes);
                        const dateLabel = new Date(e.plan_date).toLocaleDateString('en-NZ', { weekday: 'short', day: 'numeric', month: 'short' });
                        return (
                            <button key={e.id} type="button" onClick={() => { onOpenEntry(e); onClose(); }} className="w-full rounded-lg border border-amberx/30 bg-amberx-bg/40 p-3 text-left transition-colors hover:bg-amberx-bg/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-[13.5px] font-semibold text-foreground">{entryDisplayName(e, recipes)}</span>
                                    <span className="shrink-0 text-[11.5px] text-muted-foreground">{dateLabel} · {SLOT_LABEL[e.meal_slot]}</span>
                                </div>
                                {hard.length > 0 && <div className="mt-0.5 text-[11.5px] font-medium text-status-critical">{hard.map((h) => `${firstName(h.resident.name)} (${h.matches.join(', ')})`).join('; ')}</div>}
                                {e.allergen_override_reason && <div className="mt-1 text-[12px] italic text-foreground">&ldquo;{e.allergen_override_reason}&rdquo;</div>}
                                {e.allergen_override_by && e.allergen_override_at && <div className="mt-0.5 text-[11px] text-muted-foreground">Approved by {e.allergen_override_by.name} · {new Date(e.allergen_override_at).toLocaleString('en-NZ')}</div>}
                            </button>
                        );
                    })}
                </div>
                <DialogFooter><Button variant="outline" onClick={onClose}>Close</Button></DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/* ── Kitchen sheet (branded print) ─────────────────────────────────────── */
function KitchenSheetPrintDoc({ weekStart, entries, residents, recipes, siteName, rangeLabel }: { weekStart: Date; entries: PlanEntry[]; residents: Resident[]; recipes: RecipeMap; siteName: string; rangeLabel: string }) {
    const days = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));
    const textureNotes = residents.filter((r) => r.texture && r.texture.level < 7).map((r) => `${r.name.split(' ')[0]}: IDDSI ${r.texture!.level} (${r.texture!.label})`);
    // Follow Settings → Branding instead of a hardcoded green (P2-1).
    const brand = (typeof window !== 'undefined' && getComputedStyle(document.documentElement).getPropertyValue('--primary').trim()) || '#1f7a4d';
    const brandTintBg = `color-mix(in oklch, ${brand} 12%, white)`;
    const brandDeep = `color-mix(in oklch, ${brand}, black 22%)`;

    return createPortal(
        <div className="mp-print-doc" style={{ fontFamily: "'Instrument Sans', sans-serif", color: '#1a1a2e' }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: `3px solid ${brand}`, paddingBottom: 12, marginBottom: 14 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                    <div style={{ width: 44, height: 44, borderRadius: 12, background: brand, display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff' }}><ChefHat className="h-6 w-6" /></div>
                    <div>
                        <div style={{ fontSize: 18, fontWeight: 700, lineHeight: 1.1 }}>Oblivion Findings</div>
                        <div style={{ fontSize: 11, color: '#6b6b80', textTransform: 'uppercase', letterSpacing: '0.04em' }}>Kitchen Sheet · {siteName}</div>
                    </div>
                </div>
                <div style={{ textAlign: 'right' }}>
                    <div style={{ fontSize: 18, fontWeight: 700, color: brand, whiteSpace: 'nowrap' }}>Weekly Cook Sheet</div>
                    <div style={{ fontSize: 12, color: '#6b6b80' }}>{rangeLabel}</div>
                </div>
            </div>
            {textureNotes.length > 0 && (
                <div style={{ background: '#fdeef0', border: '1px solid #f2c2c8', borderRadius: 8, padding: '8px 12px', marginBottom: 14, fontSize: 11.5 }}>
                    <strong style={{ color: '#b4232f' }}>Texture-modified diets:</strong> {textureNotes.join(' · ')}
                </div>
            )}
            {days.map((d, di) => {
                const dayEntries = entries.filter((e) => e.plan_date.slice(0, 10) === toIsoDate(d)).sort((a, b) => MEAL_SLOTS.indexOf(a.meal_slot) - MEAL_SLOTS.indexOf(b.meal_slot));
                return (
                    <div key={di} className="pg-break" style={{ marginBottom: 12 }}>
                        <div style={{ background: brandTintBg, borderRadius: 6, padding: '5px 10px', fontSize: 13, fontWeight: 700, color: brandDeep }}>{DAY_FULL[di]} · {d.getDate()}/{d.getMonth() + 1}</div>
                        {dayEntries.length === 0 ? (
                            <div style={{ padding: '6px 10px', fontSize: 12, color: '#9a9ab0', fontStyle: 'italic' }}>No meals planned</div>
                        ) : (
                            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
                                <tbody>
                                    {dayEntries.map((e) => {
                                        const { hard } = conflictsFor(e, residents, recipes);
                                        const overridden = !!e.allergen_override_at;
                                        const assigned = (e.client_ids ?? []).map((id) => residents.find((r) => r.id === id)).filter(Boolean) as Resident[];
                                        // Per-resident texture (IDDSI) + fluids so the paper matches the on-screen safety picture (P2-1).
                                        const careNotes = assigned
                                            .filter((r) => (r.texture && r.texture.level < 7) || r.fluids)
                                            .map((r) => `${r.name.split(' ')[0]}: ${[r.texture && r.texture.level < 7 ? `IDDSI ${r.texture.level} ${r.texture.label}` : '', r.fluids ? `fluids ${r.fluids}` : ''].filter(Boolean).join(', ')}`);
                                        const allergenNotes = assigned.filter((r) => r.allergens.length).map((r) => `${r.name.split(' ')[0]}: ${r.allergens.join(', ')}`);
                                        return (
                                            <tr key={e.id} style={{ borderBottom: '1px solid #e6e6ef' }}>
                                                <td style={{ padding: '6px 10px', width: 120, color: '#6b6b80', whiteSpace: 'nowrap', verticalAlign: 'top' }}>{SLOT_LABEL[e.meal_slot]} · {SLOT_TIME[e.meal_slot]}</td>
                                                <td style={{ padding: '6px 10px', fontWeight: 600, verticalAlign: 'top' }}>{entryDisplayName(e, recipes)}</td>
                                                <td style={{ padding: '6px 10px', width: 60, textAlign: 'right', verticalAlign: 'top' }}>{e.servings} serves</td>
                                                <td style={{ padding: '6px 10px', width: 220, fontSize: 10.5 }}>
                                                    {hard.length > 0 && <div style={{ color: '#b4232f', fontWeight: 600 }}>⚠ Allergen: {hard.map((h) => `${h.resident.name.split(' ')[0]} (${h.matches.join(', ')})`).join('; ')}</div>}
                                                    {overridden && e.allergen_override_reason && <div style={{ color: '#b4232f' }}>Override: &ldquo;{e.allergen_override_reason}&rdquo;{e.allergen_override_by ? ` — ${e.allergen_override_by.name}` : ''}</div>}
                                                    {allergenNotes.length > 0 && !hard.length && <div style={{ color: '#6b6b80' }}>Allergens — {allergenNotes.join(' · ')}</div>}
                                                    {careNotes.length > 0 && <div style={{ color: '#6b6b80' }}>{careNotes.join(' · ')}</div>}
                                                    {e.notes && <div style={{ color: '#6b6b80', fontStyle: 'italic' }}>{e.notes}</div>}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        )}
                    </div>
                );
            })}
            <div style={{ marginTop: 18, fontSize: 10, color: '#9a9ab0', borderTop: '1px solid #e6e6ef', paddingTop: 8 }}>Oblivion Findings Meal Planner · Always confirm allergens &amp; texture before serving</div>
        </div>,
        document.body,
    );
}

/* ── Keyboard-accessible "Move to day/slot…" (alternative to drag) ──────── */
function MoveMealDialog({ entry, recipes, weekStart, onClose, onMove }: { entry: PlanEntry; recipes: RecipeMap; weekStart: Date; onClose: () => void; onMove: (date: string, slot: MealSlot) => void }) {
    const days = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));
    const [date, setDate] = useState(entry.plan_date.slice(0, 10));
    const [slot, setSlot] = useState<MealSlot>(entry.meal_slot);
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2"><CalendarCog className="h-4 w-4 text-sites" /> Move meal</DialogTitle>
                    <DialogDescription>Move “{entryDisplayName(entry, recipes)}” to a different day and meal slot.</DialogDescription>
                </DialogHeader>
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <Label>Day</Label>
                        <Select value={date} onValueChange={setDate}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                {days.map((d, i) => <SelectItem key={i} value={toIsoDate(d)}>{DAY_FULL[i]} {d.getDate()}/{d.getMonth() + 1}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>
                    <div>
                        <Label>Meal slot</Label>
                        <Select value={slot} onValueChange={(v) => setSlot(v as MealSlot)}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>{MEAL_SLOTS.map((s) => <SelectItem key={s} value={s}>{SLOT_LABEL[s]}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>Cancel</Button>
                    <Button onClick={() => { onMove(date, slot); }}><ArrowRightToLine className="mr-1.5 h-4 w-4" /> Move meal</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/* ── Calendar grid (default export) ────────────────────────────────────── */
export type CalendarGridProps = {
    siteId: number;
    siteName: string;
    weekStart: Date;
    entries: PlanEntry[];
    residents: Resident[];
    recipes: RecipeFull[];
    recipeMap: RecipeMap;
    templates: WeekTemplate[];
    iddsiLevels: IddsiLevel[];
    dietaryTags: { id: number; label: string; kind: 'allergen' | 'dietary' }[];
    budgetCents: number | null;
    cookCostCents: number;
    takeawayCostCents: number;
    canPlan: boolean;
    weekLabel: string;
    rangeLabel: string;
    onCellClick: (date: string, slot: MealSlot) => void;
    onEntryClick: (entry: PlanEntry) => void;
    onChanged: () => void;
    onTemplatesChanged: () => void;
    onResidentSaved: () => void;
    onOpenSettings: () => void;
    onOpenSpend: () => void;
};

export default function CalendarGrid(props: CalendarGridProps) {
    const { siteId, siteName, weekStart, entries, residents, recipeMap, templates, iddsiLevels, dietaryTags, budgetCents, canPlan, rangeLabel } = props;
    const todayIso = toIsoDate(new Date());
    const [focusId, setFocusId] = useState<number | null>(null);
    const [dragOver, setDragOver] = useState<string | null>(null);
    const [editResident, setEditResident] = useState<Resident | null>(null);
    const [moveTarget, setMoveTarget] = useState<PlanEntry | null>(null);
    const [printOpen, setPrintOpen] = useState(false);
    const focusResident = focusId != null ? residents.find((r) => r.id === focusId) ?? null : null;

    const days = useMemo(() => Array.from({ length: 7 }, (_, i) => addDays(weekStart, i)), [weekStart]);
    const cellMap = useMemo(() => {
        const m = new Map<string, PlanEntry[]>();
        for (const e of entries) {
            const key = `${e.plan_date.slice(0, 10)}|${e.meal_slot}`;
            const arr = m.get(key) ?? [];
            arr.push(e);
            m.set(key, arr);
        }
        return m;
    }, [entries]);

    const dayStats = days.map((d) => {
        const di = toIsoDate(d);
        const dayEntries = entries.filter((e) => e.plan_date.slice(0, 10) === di);
        const cost = dayEntries.reduce((s, e) => s + mealCostCents(e, recipeMap), 0);
        return { iso: di, cost };
    });
    const maxDayCost = Math.max(1, ...dayStats.map((s) => s.cost));
    const coreFilled = days.reduce((acc, d) => {
        const di = toIsoDate(d);
        return acc + (['breakfast', 'lunch', 'dinner'] as MealSlot[]).filter((s) => cellMap.get(`${di}|${s}`)).length;
    }, 0);
    const fillPct = Math.round((coreFilled / 21) * 100);

    const weekTotal = dayStats.reduce((s, d) => s + d.cost, 0);
    const budget = budgetCents ?? 0;
    const budgetPct = budget ? Math.min(100, Math.round((weekTotal / budget) * 100)) : 0;
    const remaining = budget - weekTotal;
    const over = remaining < 0;
    const near = !over && budgetPct >= 85;
    const budgetBar = over ? 'bg-status-critical' : near ? 'bg-status-warning' : 'bg-status-success';
    const budgetText = over ? 'text-status-critical' : near ? 'text-status-warning' : 'text-status-success';

    const focusStats = useMemo(() => {
        if (!focusResident) return null;
        let involved = 0;
        let clashes = 0;
        entries.forEach((e) => {
            const rel = residentRelation(e, focusResident, recipeMap);
            if (rel.involved) {
                involved += 1;
                if (rel.clash === 'allergen') clashes += 1;
            }
        });
        return { involved, clashes };
    }, [focusResident, entries, recipeMap]);

    const weekHasMeals = entries.some((e) => days.some((d) => toIsoDate(d) === e.plan_date.slice(0, 10)));
    const weekMealCount = entries.filter((e) => days.some((d) => toIsoDate(d) === e.plan_date.slice(0, 10))).length;
    const [confirmBulk, setConfirmBulk] = useState<{ title: string; description: string; confirmLabel: string; onConfirm: () => void } | null>(null);

    function entryPayload(entry: PlanEntry, overrides: Partial<{ plan_date: string; meal_slot: MealSlot }> = {}) {
        return {
            plan_date: overrides.plan_date ?? entry.plan_date.slice(0, 10),
            meal_slot: overrides.meal_slot ?? entry.meal_slot,
            source_type: entry.source_type,
            recipe_id: entry.recipe_id,
            ad_hoc_name: entry.ad_hoc_name,
            takeaway_vendor: entry.takeaway_vendor,
            takeaway_cost_cents: entry.takeaway_cost_cents,
            takeaway_reference: entry.takeaway_reference,
            servings: entry.servings,
            notes: entry.notes,
            client_ids: entry.client_ids ?? [],
            allergen_override_reason: entry.allergen_override_reason ?? undefined,
        };
    }

    async function handleEntryAction(action: EntryAction, entry: PlanEntry) {
        try {
            if (action === 'edit') {
                props.onEntryClick(entry);
                return;
            }
            if (action === 'move') {
                setMoveTarget(entry);
                return;
            }
            if (action === 'toggle-served') {
                const path = entry.served_at ? 'unserve' : 'serve';
                await axios.post(`/sites/${siteId}/meal-plan/${entry.id}/${path}`);
                toast.success(entry.served_at ? 'Marked not served' : 'Served · stock updated');
            } else if (action === 'delete') {
                await axios.delete(`/sites/${siteId}/meal-plan/${entry.id}`);
                toast.success('Meal removed');
            } else if (action === 'duplicate') {
                await axios.post(`/sites/${siteId}/meal-plan`, entryPayload(entry));
                toast.success('Meal duplicated');
            } else if (action === 'copy-next') {
                const next = toIsoDate(addDays(new Date(entry.plan_date), 1));
                await axios.post(`/sites/${siteId}/meal-plan`, entryPayload(entry, { plan_date: next }));
                toast.success('Copied to next day');
            }
            props.onChanged();
        } catch (e) {
            const msg = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
            toast.error(msg || 'Action failed');
            announce(msg || 'Action failed');
        }
    }

    async function moveEntry(entryId: number, date: string, slot: MealSlot) {
        const entry = entries.find((e) => e.id === entryId);
        if (!entry) return;
        if (entry.plan_date.slice(0, 10) === date && entry.meal_slot === slot) return;
        try {
            await axios.put(`/sites/${siteId}/meal-plan/${entryId}`, entryPayload(entry, { plan_date: date, meal_slot: slot }));
            props.onChanged();
        } catch {
            toast.error('Could not move meal');
        }
    }

    async function copyWeek(fromOffset: number, toOffset: number, replace = false) {
        try {
            await axios.post(`/sites/${siteId}/meal-plan-week/copy`, { from_week: toIsoDate(addDays(weekStart, fromOffset)), to_week: toIsoDate(addDays(weekStart, toOffset)), replace });
            toast.success('Week copied');
            props.onChanged();
        } catch {
            toast.error('Could not copy week');
        }
    }

    async function clearWeek() {
        try {
            await axios.delete(`/sites/${siteId}/meal-plan-week/clear`, { params: { week: toIsoDate(weekStart) } });
            toast.success('Week cleared');
            announce('Week cleared');
            props.onChanged();
        } catch {
            toast.error('Could not clear week');
            announce('Could not clear week');
        }
    }

    async function applyTemplate(t: WeekTemplate, replace = true) {
        try {
            await axios.post(`/sites/${siteId}/meal-templates/${t.id}/apply`, { week: toIsoDate(weekStart), replace });
            toast.success(`Applied “${t.name}”`);
            props.onChanged();
        } catch {
            toast.error('Could not apply template');
        }
    }

    useEffect(() => {
        if (!printOpen) return;
        const t = setTimeout(() => {
            window.print();
            setPrintOpen(false);
        }, 120);
        return () => clearTimeout(t);
    }, [printOpen]);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                {canPlan ? (
                    <WeekActionsMenu
                        templates={templates}
                        onRepeatLast={() => setConfirmBulk({ title: 'Repeat last week?', description: `Copy last week's plan into ${rangeLabel}. This adds to the ${weekMealCount} meal${weekMealCount === 1 ? '' : 's'} already planned this week.`, confirmLabel: 'Repeat week', onConfirm: () => copyWeek(-7, 0) })}
                        onCopyNext={() => copyWeek(0, 7)}
                        onApplyTemplate={(t) => setConfirmBulk({ title: 'Replace this week?', description: `Replace this week's ${weekMealCount} meal${weekMealCount === 1 ? '' : 's'} with “${t.name}” (${t.meals.length} meal${t.meals.length === 1 ? '' : 's'}). This can't be undone.`, confirmLabel: 'Replace week', onConfirm: () => applyTemplate(t, true) })}
                        onManage={props.onOpenSettings}
                        onClear={() => setConfirmBulk({ title: 'Clear this week?', description: `Clear all ${weekMealCount} planned meal${weekMealCount === 1 ? '' : 's'} for ${rangeLabel}? This can't be undone.`, confirmLabel: 'Clear week', onConfirm: clearWeek })}
                    />
                ) : (
                    <span />
                )}
                <div className="flex items-center gap-2">
                    <Button variant="outline" size="sm" onClick={props.onOpenSpend}><ChartColumn className="mr-1.5 h-[15px] w-[15px]" /> Spend report</Button>
                    <Button variant="outline" size="sm" onClick={() => setPrintOpen(true)}><Printer className="mr-1.5 h-[15px] w-[15px]" /> Kitchen sheet</Button>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-3 lg:grid-cols-[1fr_auto]">
                <div className="flex items-center gap-5 rounded-xl border border-border bg-card px-4 py-3 shadow-sm">
                    <div className="shrink-0">
                        <div className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Plan completeness</div>
                        <div className="mt-0.5 flex items-baseline gap-1.5">
                            <span className="text-2xl font-bold tabular-nums text-foreground">{fillPct}%</span>
                            <span className="text-[12px] text-muted-foreground">{coreFilled}/21 core meals</span>
                        </div>
                    </div>
                    <div className="h-9 w-px bg-border" />
                    <div className="flex flex-1 items-end justify-between gap-2">
                        {dayStats.map((s, i) => {
                            const isToday = s.iso === todayIso;
                            return (
                                <div key={i} className="flex flex-1 flex-col items-center gap-1.5">
                                    <div className="flex h-12 w-full items-end justify-center">
                                        <div role="img" aria-label={`${DAY_LABELS[i]}: ${money(s.cost)}`} className={cn('w-full max-w-[26px] rounded-t-md transition-all', isToday ? 'bg-sites' : 'bg-sites/40')} style={{ height: `${Math.max(6, (s.cost / maxDayCost) * 100)}%` }} title={`${DAY_LABELS[i]}: ${money(s.cost)}`} />
                                    </div>
                                    <span className={cn('text-[10.5px] font-medium', isToday ? 'text-sites-deep' : 'text-muted-foreground')}>{DAY_LABELS[i]}</span>
                                </div>
                            );
                        })}
                    </div>
                </div>
                <div className="flex min-w-[280px] flex-col justify-center gap-2 rounded-xl border border-border bg-card px-4 py-3 shadow-sm">
                    <div className="flex items-center justify-between gap-2">
                        <span className="inline-flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground"><DollarSign className="h-3.5 w-3.5" /> Food budget</span>
                        {budgetCents != null && <span className={cn('text-[11px] font-semibold', budgetText)}>{budgetPct}% used</span>}
                    </div>
                    {budgetCents == null ? (
                        <>
                            <div className="flex items-baseline gap-1.5">
                                <span className="text-2xl font-bold tabular-nums text-foreground">{money(weekTotal)}</span>
                                <span className="text-[12px] text-muted-foreground">planned this week</span>
                            </div>
                            <button type="button" onClick={props.onOpenSettings} className="inline-flex w-fit items-center gap-1.5 rounded-md border border-dashed border-border px-2.5 py-1 text-[12px] font-medium text-muted-foreground transition-colors hover:border-primary/50 hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                <Info className="h-3.5 w-3.5" /> Set a weekly budget
                            </button>
                        </>
                    ) : (
                        <>
                            <div className="flex items-baseline gap-1.5">
                                <span className="text-2xl font-bold tabular-nums text-foreground">{money(weekTotal)}</span>
                                <span className="text-[12px] text-muted-foreground">of {money(budget)} planned</span>
                            </div>
                            <div role="progressbar" aria-valuenow={budgetPct} aria-valuemin={0} aria-valuemax={100} aria-label={`Budget ${budgetPct}% used, ${money(Math.max(0, remaining))} remaining`} className="relative h-2.5 w-full overflow-hidden rounded-full bg-muted">
                                <div className={cn('h-full rounded-full transition-all', budgetBar)} style={{ width: `${Math.max(4, budgetPct)}%` }} />
                            </div>
                            <div className={cn('flex items-center gap-1.5 text-[12px] font-medium', budgetText)}>
                                {over ? <TriangleAlert className="h-3.5 w-3.5" /> : near ? <Info className="h-3.5 w-3.5" /> : <CircleCheck className="h-3.5 w-3.5" />}
                                {over ? `${money(-remaining)} over budget` : `${money(remaining)} left this week`}
                            </div>
                        </>
                    )}
                    {(props.cookCostCents > 0 || props.takeawayCostCents > 0) && (
                        <div className="text-[11.5px] text-muted-foreground">{money(props.cookCostCents)} cooked · {money(props.takeawayCostCents)} takeaway</div>
                    )}
                </div>
            </div>

            {residents.length > 0 && (
                <div className="flex flex-col gap-2.5 rounded-xl border border-border bg-card px-4 py-3 shadow-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="inline-flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                            <Users className="h-3.5 w-3.5" /> Residents
                            <span className="font-normal normal-case tracking-normal text-muted-foreground/80">— tap to spotlight their meals</span>
                        </span>
                        {focusResident && (
                            <button type="button" onClick={() => setFocusId(null)} className="inline-flex items-center gap-1 rounded-full border border-border px-2.5 py-1 text-[11.5px] font-medium text-muted-foreground transition-colors hover:bg-accent"><X className="h-3 w-3" /> Clear spotlight</button>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {residents.map((r) => (
                            <ResidentChip key={r.id} resident={r} entries={entries} recipes={recipeMap} selected={focusId === r.id} dimmed={focusId != null && focusId !== r.id} onToggle={() => setFocusId(focusId === r.id ? null : r.id)} onEdit={() => setEditResident(r)} canEdit={canPlan} />
                        ))}
                    </div>
                    {focusResident && focusStats && (
                        <div className="flex flex-wrap items-center gap-2 rounded-lg bg-sites-bg/50 px-3 py-2 text-[12.5px] text-foreground">
                            <Eye className="h-3.5 w-3.5 text-sites-deep" />
                            Spotlighting <strong>{focusResident.name}</strong> ·<span className="font-medium">{focusStats.involved} meal{focusStats.involved === 1 ? '' : 's'}</span> this week
                            {focusStats.clashes > 0 ? (
                                <span className="inline-flex items-center gap-1 font-semibold text-status-critical"><ShieldAlert className="h-3.5 w-3.5" /> {focusStats.clashes} allergen clash{focusStats.clashes === 1 ? '' : 'es'}</span>
                            ) : (
                                <span className="inline-flex items-center gap-1 font-medium text-status-success"><ShieldCheck className="h-3.5 w-3.5" /> all safe</span>
                            )}
                        </div>
                    )}
                </div>
            )}

            {residents.length === 0 && (
                <div className="flex items-center gap-2 rounded-xl border border-border bg-muted/30 px-4 py-2.5 text-[12.5px] font-medium text-muted-foreground">
                    <ShieldAlert className="h-4 w-4 shrink-0 text-status-warning" aria-hidden="true" />
                    No residents linked — allergen &amp; texture checks are paused for this house.
                </div>
            )}

            {!weekHasMeals && (
                <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-border bg-card px-6 py-10 text-center">
                    <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-sites-bg text-sites-deep"><CalendarPlus className="h-7 w-7" /></span>
                    <div>
                        <div className="text-[15px] font-semibold text-foreground">No meals planned this week</div>
                        <div className="mt-0.5 text-[13px] text-muted-foreground">Start from a template, repeat last week, or add meals to the grid below.</div>
                    </div>
                    {canPlan && (
                        <div className="flex flex-wrap justify-center gap-2">
                            {templates[0] && <Button size="sm" onClick={() => applyTemplate(templates[0], true)}><LayoutTemplate className="mr-1.5 h-[15px] w-[15px]" /> Apply “{templates[0].name}”</Button>}
                            <Button size="sm" variant="outline" onClick={() => copyWeek(-7, 0)}><History className="mr-1.5 h-[15px] w-[15px]" /> Repeat last week</Button>
                        </div>
                    )}
                </div>
            )}

            <div className="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                <div className="nice-scroll overflow-x-auto">
                    <div className="min-w-[940px]">
                        <div className="grid grid-cols-[132px_repeat(7,1fr)] border-b border-border bg-muted/40">
                            <div className="px-3 py-2.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Meal</div>
                            {days.map((d, i) => {
                                const di = toIsoDate(d);
                                const isToday = di === todayIso;
                                return (
                                    <div key={i} className={cn('border-l border-border px-2 py-2 text-center', isToday && 'bg-sites-bg/50')}>
                                        <div className="flex items-center justify-center gap-1.5">
                                            <span className={cn('text-[12px] font-semibold', isToday ? 'text-sites-deep' : 'text-foreground')}>{DAY_LABELS[i]}</span>
                                            {isToday && <span className="rounded-full bg-sites px-1.5 py-px text-[9px] font-bold uppercase text-white">Today</span>}
                                        </div>
                                        <div className="mt-0.5 flex items-center justify-center gap-1.5 text-[10.5px] text-muted-foreground">
                                            <span>{d.getDate()}/{d.getMonth() + 1}</span>
                                            <span className="text-border">•</span>
                                            <span className="tabular-nums">{money(dayStats[i].cost)}</span>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                        {MEAL_SLOTS.map((slot, si) => {
                            const SlotIcon = SLOT_ICON[slot];
                            return (
                                <div key={slot} className={cn('grid grid-cols-[132px_repeat(7,1fr)] border-b border-border last:border-b-0', si % 2 === 1 && 'bg-muted/20')}>
                                    <div className="flex items-center gap-2 border-r border-border px-3 py-2">
                                        <SlotIcon className="h-[15px] w-[15px] shrink-0 text-muted-foreground" />
                                        <div className="min-w-0">
                                            <div className="text-[11.5px] font-semibold leading-tight text-foreground">{SLOT_LABEL[slot]}</div>
                                            <div className="text-[10px] text-muted-foreground">{SLOT_TIME[slot]}</div>
                                        </div>
                                    </div>
                                    {days.map((d, di2) => {
                                        const di = toIsoDate(d);
                                        const isToday = di === todayIso;
                                        const cellEntries = cellMap.get(`${di}|${slot}`) ?? [];
                                        const dropKey = `${di}|${slot}`;
                                        const isDropTarget = dragOver === dropKey;
                                        return (
                                            <div
                                                key={di2}
                                                onDragOver={(e) => {
                                                    if (!canPlan) return;
                                                    e.preventDefault();
                                                    e.dataTransfer.dropEffect = 'move';
                                                    setDragOver(dropKey);
                                                }}
                                                onDragLeave={(e) => {
                                                    if (dragOver === dropKey && !e.currentTarget.contains(e.relatedTarget as Node)) setDragOver(null);
                                                }}
                                                onDrop={(e) => {
                                                    e.preventDefault();
                                                    const id = Number(e.dataTransfer.getData('text/plain'));
                                                    setDragOver(null);
                                                    if (id && canPlan) moveEntry(id, di, slot);
                                                }}
                                                className={cn('group relative min-h-[78px] border-l border-border p-1.5 transition-colors', isToday && 'bg-sites-bg/30', isDropTarget && 'bg-primary/10 ring-2 ring-inset ring-primary/50')}
                                            >
                                                <div className="space-y-1.5">
                                                    {cellEntries.map((e) => (
                                                        <MealCard key={e.id} entry={e} residents={residents} recipes={recipeMap} focusResident={focusResident} slot={slot} dayLabel={DAY_FULL[di2]} onClick={() => props.onEntryClick(e)} onAction={handleEntryAction} draggable={canPlan} canPlan={canPlan} />
                                                    ))}
                                                </div>
                                                {canPlan && (
                                                    <button type="button" onClick={() => props.onCellClick(di, slot)} aria-label={`Add ${SLOT_LABEL[slot]} for ${DAY_FULL[di2]} ${d.getDate()}/${d.getMonth() + 1}`} className={cn('mt-1.5 flex min-h-6 w-full items-center justify-center gap-1 rounded-lg border border-dashed border-border py-1 text-[11px] font-medium text-muted-foreground transition-all hover:border-primary/50 hover:bg-primary/5 hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', cellEntries.length === 0 ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 focus-visible:opacity-100')}>
                                                        <Plus className="h-3 w-3" /> Add
                                                    </button>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-1 text-[11.5px] text-muted-foreground">
                <span className="font-medium text-foreground">Legend</span>
                <span className="inline-flex items-center gap-1.5"><ChefHat className="h-3.5 w-3.5" /> From recipe</span>
                <span className="inline-flex items-center gap-1.5"><Utensils className="h-3.5 w-3.5" /> Ad-hoc cook</span>
                <span className="inline-flex items-center gap-1.5"><ShoppingBag className="h-3.5 w-3.5 text-amberx" /> Takeaway</span>
                <span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-full bg-status-critical" /> Allergen conflict</span>
                <span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-full bg-amberx" /> Override on file</span>
                <span className="inline-flex items-center gap-1.5"><CircleCheck className="h-3.5 w-3.5 text-status-success" /> Served</span>
            </div>

            {editResident && (
                <ResidentEditDialog siteId={siteId} resident={editResident} dietaryTags={dietaryTags} iddsiLevels={iddsiLevels} onClose={() => setEditResident(null)} onSaved={props.onResidentSaved} />
            )}
            {moveTarget && (
                <MoveMealDialog
                    entry={moveTarget}
                    recipes={recipeMap}
                    weekStart={weekStart}
                    onClose={() => setMoveTarget(null)}
                    onMove={(date, slot) => { moveEntry(moveTarget.id, date, slot); setMoveTarget(null); }}
                />
            )}
            {confirmBulk && (
                <Dialog open onOpenChange={(o) => !o && setConfirmBulk(null)}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2"><TriangleAlert className="h-4 w-4 text-status-critical" /> {confirmBulk.title}</DialogTitle>
                            <DialogDescription>{confirmBulk.description}</DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setConfirmBulk(null)}>Cancel</Button>
                            <Button className="bg-status-critical text-white hover:bg-status-critical/90 focus-visible:ring-status-critical" onClick={() => { confirmBulk.onConfirm(); setConfirmBulk(null); }}>{confirmBulk.confirmLabel}</Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}
            {printOpen && <KitchenSheetPrintDoc weekStart={weekStart} entries={entries} residents={residents} recipes={recipeMap} siteName={siteName} rangeLabel={rangeLabel} />}
        </div>
    );
}
