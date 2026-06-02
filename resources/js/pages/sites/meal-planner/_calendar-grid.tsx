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
import {
    addDays,
    conflictsFor,
    entryDisplayName,
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
                                <span className="inline-flex items-center gap-1 text-status-success"><CircleCheck className="h-3 w-3" /> Served</span>
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
                        <span>Allergen conflict: {hard.map((h) => `${h.resident.name.split(' ')[0]} (${h.matches.join(', ')})`).join('; ')}</span>
                    </div>
                )}
                {overridden && (
                    <div className="flex items-start gap-1.5 rounded-lg bg-amberx-bg/70 px-2.5 py-1.5 text-[11.5px] font-medium text-amberx">
                        <Lock className="mt-px h-3.5 w-3.5 shrink-0" />
                        <span>Override on file — separate portion plated</span>
                    </div>
                )}
                {!unresolved && !overridden && soft.length > 0 && (
                    <div className="flex items-start gap-1.5 rounded-lg bg-status-warning-bg/70 px-2.5 py-1.5 text-[11.5px] font-medium text-status-warning">
                        <TriangleAlert className="mt-px h-3.5 w-3.5 shrink-0" />
                        <span>Dislikes: {soft.map((s) => s.resident.name.split(' ')[0]).join(', ')}</span>
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
type EntryAction = 'edit' | 'toggle-served' | 'duplicate' | 'copy-next' | 'delete';
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

    const left = Math.min(pos.x, window.innerWidth - 218);
    const top = Math.min(pos.y, window.innerHeight - 250);
    const served = !!entry.served_at;
    const items: ({ key: EntryAction; label: string; icon: LucideIcon; danger?: boolean } | { sep: true })[] = [
        { key: 'edit', label: 'Edit meal', icon: Pencil },
        { key: 'toggle-served', label: served ? 'Mark not served' : 'Mark as served', icon: served ? RotateCcw : CircleCheck },
        { key: 'duplicate', label: 'Duplicate', icon: Copy },
        { key: 'copy-next', label: 'Copy to next day', icon: ArrowRightToLine },
        { sep: true },
        { key: 'delete', label: 'Delete meal', icon: Trash2, danger: true },
    ];

    return createPortal(
        <div ref={ref} className="animate-pop fixed z-[130] w-[210px] overflow-hidden rounded-xl border border-border bg-popover p-1 shadow-float" style={{ left, top }}>
            <div className="truncate px-2.5 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{entryDisplayName(entry, recipes)}</div>
            {items.map((it, i) =>
                'sep' in it ? (
                    <div key={i} className="my-1 h-px bg-border" />
                ) : (
                    <button key={it.key} type="button" onClick={() => { onAction(it.key, entry); onClose(); }} className={cn('flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-[13px] font-medium transition-colors', it.danger ? 'text-status-critical hover:bg-status-critical-bg/60' : 'text-foreground hover:bg-accent')}>
                        <it.icon className={cn('h-[15px] w-[15px]', it.danger ? 'text-status-critical' : 'text-muted-foreground')} /> {it.label}
                    </button>
                ),
            )}
        </div>,
        document.body,
    );
}

/* ── Meal card ─────────────────────────────────────────────────────────── */
function MealCard({ entry, residents, recipes, focusResident, onClick, onAction, draggable }: { entry: PlanEntry; residents: Resident[]; recipes: RecipeMap; focusResident: Resident | null; onClick: () => void; onAction: (a: EntryAction, e: PlanEntry) => void; draggable: boolean }) {
    const isTakeaway = entry.source_type === 'takeaway';
    const isAdhoc = entry.source_type === 'ad_hoc';
    const { hard, soft } = conflictsFor(entry, residents, recipes);
    const overridden = !!entry.allergen_override_at;
    const unresolved = hard.length > 0 && !overridden;
    const served = !!entry.served_at;
    const name = entryDisplayName(entry, recipes);
    const cost = mealCostCents(entry, recipes);

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
    const [hover, setHover] = useState(false);
    const [menu, setMenu] = useState<{ x: number; y: number } | null>(null);
    const cardRef = useRef<HTMLButtonElement>(null);
    const hoverTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    function openHover() {
        if (hoverTimer.current) clearTimeout(hoverTimer.current);
        hoverTimer.current = setTimeout(() => setHover(true), 240);
    }
    function closeHover() {
        if (hoverTimer.current) clearTimeout(hoverTimer.current);
        setHover(false);
    }
    useEffect(() => () => { if (hoverTimer.current) clearTimeout(hoverTimer.current); }, []);

    return (
        <>
            <button
                ref={cardRef}
                type="button"
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
                    'group/card relative w-full overflow-hidden rounded-lg border px-2 py-1.5 text-left shadow-sm transition-all',
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
                    <span className="absolute right-1.5 top-1.5 z-10 flex h-4 w-4 items-center justify-center rounded-full bg-sites text-primary-foreground" title="On this resident's plan">
                        <Check className="h-2.5 w-2.5" strokeWidth={3} />
                    </span>
                )}
                <div className="flex items-start gap-1.5 pl-1">
                    <SrcIcon className={cn('mt-0.5 h-3.5 w-3.5 shrink-0', isTakeaway ? 'text-amberx' : unresolved ? 'text-status-critical' : 'text-muted-foreground')} />
                    <span className="line-clamp-2 flex-1 text-[12px] font-semibold leading-tight text-foreground">{name}</span>
                </div>
                <div className="mt-1 flex items-center justify-between pl-1 text-[10.5px] text-muted-foreground">
                    <span>{isTakeaway ? money(cost) : `${entry.servings} serves`}</span>
                    {served && <CircleCheck className="h-3 w-3 text-status-success" />}
                </div>
                {(unresolved || overridden || isTakeaway) && (
                    <div className="mt-1 flex flex-wrap gap-1 pl-1">
                        {unresolved && <span className="rounded-full bg-status-critical px-1.5 py-px text-[9px] font-bold uppercase tracking-wide text-white">Allergen</span>}
                        {overridden && <span className="rounded-full bg-amberx px-1.5 py-px text-[9px] font-bold uppercase tracking-wide text-white">Override</span>}
                        {isTakeaway && !unresolved && <span className="rounded-full border border-amberx/40 px-1.5 py-px text-[9px] font-semibold uppercase tracking-wide text-amberx">Takeaway</span>}
                    </div>
                )}
            </button>
            {hover && !menu && cardRef.current && <MealHoverCard entry={entry} residents={residents} recipes={recipes} anchorRect={cardRef.current.getBoundingClientRect()} />}
            {menu && <MealContextMenu entry={entry} recipes={recipes} pos={menu} onClose={() => setMenu(null)} onAction={onAction} />}
        </>
    );
}

/* ── Resident chip + hover + edit ──────────────────────────────────────── */
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

    const Row = ({ icon: Icon, label, value, tone }: { icon: LucideIcon; label: string; value: string; tone?: string }) => (
        <div className="flex items-start gap-2 text-[12px]">
            <Icon className={cn('mt-0.5 h-3.5 w-3.5 shrink-0', tone ?? 'text-muted-foreground')} />
            <span className="w-14 shrink-0 text-muted-foreground">{label}</span>
            <span className="flex-1 font-medium text-foreground">{value}</span>
        </div>
    );

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
                <Row icon={ShieldAlert} label="Allergens" tone={resident.allergens.length ? 'text-status-critical' : 'text-muted-foreground'} value={resident.allergens.length ? resident.allergens.join(', ') : 'None'} />
                <Row icon={Leaf} label="Dietary" tone="text-sites-deep" value={resident.dietary.length ? resident.dietary.join(', ') : 'None'} />
                {resident.dislikes.length > 0 && <Row icon={ThumbsDown} label="Dislikes" value={resident.dislikes.join(', ')} />}
                {resident.texture && <Row icon={Soup} label="Texture" tone="text-primary" value={`IDDSI ${resident.texture.level} · ${resident.texture.label}`} />}
                {resident.fluids && <Row icon={Soup} label="Fluids" value={resident.fluids} />}
            </div>
            {clashes > 0 && (
                <div className="flex items-center gap-1.5 border-t border-border bg-status-critical-bg/50 px-3.5 py-2 text-[11.5px] font-medium text-status-critical">
                    <ShieldAlert className="h-3.5 w-3.5" /> {clashes} allergen clash{clashes === 1 ? '' : 'es'} in this week's plan
                </div>
            )}
            <div className="border-t border-border bg-muted/40 px-3.5 py-1.5 text-[10.5px] text-muted-foreground">Click to spotlight · pencil to edit</div>
        </div>,
        document.body,
    );
}

function ResidentChip({ resident, entries, recipes, selected, dimmed, onToggle, onEdit, canEdit }: { resident: Resident; entries: PlanEntry[]; recipes: RecipeMap; selected: boolean; dimmed: boolean; onToggle: () => void; onEdit: () => void; canEdit: boolean }) {
    const r = resident;
    const hasAllergens = r.allergens.length > 0;
    const [hover, setHover] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const open = () => {
        if (timer.current) clearTimeout(timer.current);
        timer.current = setTimeout(() => setHover(true), 280);
    };
    const close = () => {
        if (timer.current) clearTimeout(timer.current);
        setHover(false);
    };
    useEffect(() => () => { if (timer.current) clearTimeout(timer.current); }, []);

    return (
        <div ref={ref} onMouseEnter={open} onMouseLeave={close} className={cn('group relative inline-flex items-center gap-1 rounded-full border py-1 pl-1 pr-2 transition-all', selected ? 'border-sites bg-sites-bg ring-1 ring-sites/40' : 'border-border bg-card hover:border-sites/50 hover:bg-sites-bg/40', dimmed && 'opacity-55')}>
            <button type="button" onClick={onToggle} className="flex items-center gap-2 text-left">
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
                <button type="button" onClick={(e) => { e.stopPropagation(); close(); onEdit(); }} aria-label={`Edit ${r.name}`} className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-muted-foreground opacity-0 transition-all hover:bg-card hover:text-foreground focus:opacity-100 group-hover:opacity-100">
                    <Pencil className="h-3.5 w-3.5" />
                </button>
            )}
            {hover && ref.current && <ResidentHoverCard resident={r} entries={entries} recipes={recipes} anchorRect={ref.current.getBoundingClientRect()} />}
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
    const item = 'flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-[13px] font-medium transition-colors hover:bg-accent';
    return (
        <div ref={ref} className="relative">
            <Button variant="outline" size="sm" onClick={() => setOpen((v) => !v)}>
                <CalendarCog className="mr-1.5 h-[15px] w-[15px]" /> Plan week <ChevronDown className="ml-1 h-3 w-3" />
            </Button>
            {open && (
                <div className="animate-pop absolute left-0 z-50 mt-1.5 w-[248px] overflow-hidden rounded-xl border border-border bg-popover p-1 shadow-float">
                    <button type="button" onClick={() => { onRepeatLast(); setOpen(false); }} className={item}><History className="h-[15px] w-[15px] text-muted-foreground" /> Repeat last week</button>
                    <button type="button" onClick={() => { onCopyNext(); setOpen(false); }} className={item}><ArrowRightToLine className="h-[15px] w-[15px] text-muted-foreground" /> Copy to next week</button>
                    <div className="relative">
                        <button type="button" onClick={() => setSub((v) => !v)} className="flex w-full items-center justify-between gap-2.5 rounded-md px-2.5 py-2 text-left text-[13px] font-medium transition-colors hover:bg-accent">
                            <span className="flex items-center gap-2.5"><LayoutTemplate className="h-[15px] w-[15px] text-muted-foreground" /> Apply a template</span>
                            {sub ? <ChevronDown className="h-3.5 w-3.5 text-muted-foreground" /> : <ChevronRight className="h-3.5 w-3.5 text-muted-foreground" />}
                        </button>
                        {sub && (
                            <div className="ml-3 border-l border-border pl-1">
                                {templates.length === 0 && <div className="px-2.5 py-1.5 text-[11.5px] text-muted-foreground">No templates yet.</div>}
                                {templates.map((t) => (
                                    <button key={t.id} type="button" onClick={() => { onApplyTemplate(t); setOpen(false); setSub(false); }} className="flex w-full flex-col rounded-md px-2.5 py-1.5 text-left transition-colors hover:bg-accent">
                                        <span className="text-[12.5px] font-medium text-foreground">{t.name}</span>
                                        {t.description && <span className="text-[10.5px] text-muted-foreground">{t.description}</span>}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                    <div className="my-1 h-px bg-border" />
                    <button type="button" onClick={() => { onManage(); setOpen(false); }} className={item}><LayoutTemplate className="h-[15px] w-[15px] text-muted-foreground" /> Manage templates &amp; budget…</button>
                    <div className="my-1 h-px bg-border" />
                    <button type="button" onClick={() => { onClear(); setOpen(false); }} className="flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-[13px] font-medium text-status-critical transition-colors hover:bg-status-critical-bg/60"><Eraser className="h-[15px] w-[15px]" /> Clear this week</button>
                </div>
            )}
        </div>
    );
}

/* ── Spend report ──────────────────────────────────────────────────────── */
function SpendReportDialog({ siteId, currentWeekCents, budgetCents, onClose }: { siteId: number; currentWeekCents: number; budgetCents: number | null; onClose: () => void }) {
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
                                            <div className={cn('w-full max-w-[34px] rounded-t-md', w.status === 'current' ? 'bg-primary' : isOver ? 'bg-status-warning' : 'bg-sites')} style={{ height: h }} />
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

/* ── Kitchen sheet (branded print) ─────────────────────────────────────── */
function KitchenSheetPrintDoc({ weekStart, entries, residents, recipes, siteName, rangeLabel }: { weekStart: Date; entries: PlanEntry[]; residents: Resident[]; recipes: RecipeMap; siteName: string; rangeLabel: string }) {
    const days = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));
    const textureNotes = residents.filter((r) => r.texture && r.texture.level < 7).map((r) => `${r.name.split(' ')[0]}: IDDSI ${r.texture!.level} (${r.texture!.label})`);

    return createPortal(
        <div className="mp-print-doc" style={{ fontFamily: "'Instrument Sans', sans-serif", color: '#1a1a2e' }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '3px solid #1f7a4d', paddingBottom: 12, marginBottom: 14 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                    <div style={{ width: 44, height: 44, borderRadius: 12, background: '#1f7a4d', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff' }}><ChefHat className="h-6 w-6" /></div>
                    <div>
                        <div style={{ fontSize: 18, fontWeight: 700, lineHeight: 1.1 }}>Oblivion Findings</div>
                        <div style={{ fontSize: 11, color: '#6b6b80', textTransform: 'uppercase', letterSpacing: '0.04em' }}>Kitchen Sheet · {siteName}</div>
                    </div>
                </div>
                <div style={{ textAlign: 'right' }}>
                    <div style={{ fontSize: 18, fontWeight: 700, color: '#1f7a4d', whiteSpace: 'nowrap' }}>Weekly Cook Sheet</div>
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
                        <div style={{ background: '#eef7f1', borderRadius: 6, padding: '5px 10px', fontSize: 13, fontWeight: 700, color: '#14502f' }}>{DAY_FULL[di]} · {d.getDate()}/{d.getMonth() + 1}</div>
                        {dayEntries.length === 0 ? (
                            <div style={{ padding: '6px 10px', fontSize: 12, color: '#9a9ab0', fontStyle: 'italic' }}>No meals planned</div>
                        ) : (
                            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
                                <tbody>
                                    {dayEntries.map((e) => {
                                        const { hard } = conflictsFor(e, residents, recipes);
                                        const overridden = !!e.allergen_override_at;
                                        return (
                                            <tr key={e.id} style={{ borderBottom: '1px solid #e6e6ef' }}>
                                                <td style={{ padding: '6px 10px', width: 120, color: '#6b6b80', whiteSpace: 'nowrap' }}>{SLOT_LABEL[e.meal_slot]} · {SLOT_TIME[e.meal_slot]}</td>
                                                <td style={{ padding: '6px 10px', fontWeight: 600 }}>{entryDisplayName(e, recipes)}</td>
                                                <td style={{ padding: '6px 10px', width: 70, textAlign: 'right' }}>{e.servings} serves</td>
                                                <td style={{ padding: '6px 10px', width: 180, fontSize: 11 }}>
                                                    {hard.length > 0 && <span style={{ color: '#b4232f', fontWeight: 600 }}>⚠ {hard.map((h) => `${h.resident.name.split(' ')[0]} (${h.matches.join(', ')})`).join('; ')}{overridden ? ' — override' : ''}</span>}
                                                    {e.notes && <span style={{ color: '#6b6b80' }}>{hard.length ? ' · ' : ''}{e.notes}</span>}
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
    canPlan: boolean;
    weekLabel: string;
    rangeLabel: string;
    onCellClick: (date: string, slot: MealSlot) => void;
    onEntryClick: (entry: PlanEntry) => void;
    onChanged: () => void;
    onTemplatesChanged: () => void;
    onResidentSaved: () => void;
    onOpenSettings: () => void;
};

export default function CalendarGrid(props: CalendarGridProps) {
    const { siteId, siteName, weekStart, entries, residents, recipeMap, templates, iddsiLevels, dietaryTags, budgetCents, canPlan, rangeLabel } = props;
    const todayIso = toIsoDate(new Date());
    const [focusId, setFocusId] = useState<number | null>(null);
    const [dragOver, setDragOver] = useState<string | null>(null);
    const [editResident, setEditResident] = useState<Resident | null>(null);
    const [spendOpen, setSpendOpen] = useState(false);
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
            props.onChanged();
        } catch {
            toast.error('Could not clear week');
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
                    <WeekActionsMenu templates={templates} onRepeatLast={() => copyWeek(-7, 0)} onCopyNext={() => copyWeek(0, 7)} onApplyTemplate={(t) => applyTemplate(t, true)} onManage={props.onOpenSettings} onClear={clearWeek} />
                ) : (
                    <span />
                )}
                <div className="flex items-center gap-2">
                    <Button variant="outline" size="sm" onClick={() => setSpendOpen(true)}><ChartColumn className="mr-1.5 h-[15px] w-[15px]" /> Spend report</Button>
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
                                        <div className={cn('w-full max-w-[26px] rounded-t-md transition-all', isToday ? 'bg-sites' : 'bg-sites/40')} style={{ height: `${Math.max(6, (s.cost / maxDayCost) * 100)}%` }} title={`${DAY_LABELS[i]}: ${money(s.cost)}`} />
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
                        <span className={cn('text-[11px] font-semibold', budgetText)}>{budget ? `${budgetPct}% used` : 'No budget set'}</span>
                    </div>
                    <div className="flex items-baseline gap-1.5">
                        <span className="text-2xl font-bold tabular-nums text-foreground">{money(weekTotal)}</span>
                        <span className="text-[12px] text-muted-foreground">of {money(budget)} planned</span>
                    </div>
                    <div className="relative h-2.5 w-full overflow-hidden rounded-full bg-muted">
                        <div className={cn('h-full rounded-full transition-all', budgetBar)} style={{ width: `${Math.max(budget ? 4 : 0, budgetPct)}%` }} />
                    </div>
                    <div className={cn('flex items-center gap-1.5 text-[12px] font-medium', budgetText)}>
                        {over ? <TriangleAlert className="h-3.5 w-3.5" /> : near ? <Info className="h-3.5 w-3.5" /> : <CircleCheck className="h-3.5 w-3.5" />}
                        {!budget ? 'Set a weekly budget in settings' : over ? `${money(-remaining)} over budget` : `${money(remaining)} left this week`}
                    </div>
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
                                                        <MealCard key={e.id} entry={e} residents={residents} recipes={recipeMap} focusResident={focusResident} onClick={() => props.onEntryClick(e)} onAction={handleEntryAction} draggable={canPlan} />
                                                    ))}
                                                </div>
                                                {canPlan && (
                                                    <button type="button" onClick={() => props.onCellClick(di, slot)} aria-label={`Add ${SLOT_LABEL[slot]}`} className={cn('mt-1.5 flex w-full items-center justify-center gap-1 rounded-lg border border-dashed border-border py-1 text-[11px] font-medium text-muted-foreground transition-all hover:border-primary/50 hover:bg-primary/5 hover:text-primary', cellEntries.length === 0 ? 'opacity-100' : 'opacity-0 group-hover:opacity-100')}>
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
            {spendOpen && <SpendReportDialog siteId={siteId} currentWeekCents={weekTotal} budgetCents={budgetCents} onClose={() => setSpendOpen(false)} />}
            {printOpen && <KitchenSheetPrintDoc weekStart={weekStart} entries={entries} residents={residents} recipes={recipeMap} siteName={siteName} rangeLabel={rangeLabel} />}
        </div>
    );
}
