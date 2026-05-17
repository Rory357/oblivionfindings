import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ChevronLeft, ChevronRight, Plus, ShoppingBag } from 'lucide-react';
import { useMemo } from 'react';
import { MEAL_SLOTS, SLOT_LABEL, formatMoneyFromCents, type MealSlot, type PlanEntry, addDays, toIsoDate } from './_helpers';

type Props = {
    weekStart: Date;
    onWeekChange: (delta: number) => void;
    entries: PlanEntry[];
    onCellClick: (date: string, slot: MealSlot) => void;
    onEntryClick: (entry: PlanEntry) => void;
};

const DAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

export default function CalendarGrid({ weekStart, onWeekChange, entries, onCellClick, onEntryClick }: Props) {
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

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <Button variant="outline" size="icon" onClick={() => onWeekChange(-7)}><ChevronLeft className="h-4 w-4" /></Button>
                    <Button variant="outline" size="icon" onClick={() => onWeekChange(7)}><ChevronRight className="h-4 w-4" /></Button>
                    <Button variant="ghost" onClick={() => onWeekChange(0)}>This week</Button>
                </div>
                <div className="text-sm text-muted-foreground">
                    {weekStart.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })} – {addDays(weekStart, 6).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' })}
                </div>
            </div>

            <div className="overflow-x-auto">
                <div className="min-w-[900px] rounded-md border">
                    <div className="grid grid-cols-8 border-b bg-muted/30">
                        <div className="px-2 py-2 text-xs font-medium text-muted-foreground">Meal</div>
                        {days.map((d, i) => (
                            <div key={i} className="px-2 py-2 text-center text-xs font-medium">
                                <div>{DAY_LABELS[i]}</div>
                                <div className="text-muted-foreground">{d.getDate()}/{d.getMonth() + 1}</div>
                            </div>
                        ))}
                    </div>
                    {MEAL_SLOTS.map((slot) => (
                        <div key={slot} className="grid grid-cols-8 border-b last:border-b-0">
                            <div className="border-r bg-muted/10 px-2 py-2 text-xs font-medium">{SLOT_LABEL[slot]}</div>
                            {days.map((d, i) => {
                                const dateIso = toIsoDate(d);
                                const cellEntries = cellMap.get(`${dateIso}|${slot}`) ?? [];
                                return (
                                    <div
                                        key={i}
                                        className="group relative min-h-[72px] border-r p-1 last:border-r-0 hover:bg-accent/30"
                                    >
                                        {cellEntries.map((e) => {
                                            const overridden = !!e.allergen_override_at;
                                            const isTakeaway = e.source_type === 'takeaway';
                                            const displayName = isTakeaway
                                                ? (e.takeaway_vendor ?? 'Takeaway')
                                                : (e.recipe?.name ?? e.ad_hoc_name ?? 'Meal');
                                            const baseColour = isTakeaway
                                                ? 'border-amber-300 bg-amber-50/60'
                                                : 'border-primary/30 bg-primary/5';
                                            return (
                                                <button
                                                    key={e.id}
                                                    type="button"
                                                    onClick={() => onEntryClick(e)}
                                                    title={overridden && e.allergen_override_reason ? `Allergen override: ${e.allergen_override_reason}` : undefined}
                                                    className={`mb-1 block w-full rounded-md border px-2 py-1 text-left text-xs hover:bg-primary/10 ${
                                                        overridden
                                                            ? 'border-l-4 border-l-red-500 border-y border-r border-red-200 bg-red-50/40'
                                                            : baseColour
                                                    }`}
                                                >
                                                    <div className="flex items-center gap-1 font-medium">
                                                        {isTakeaway && <ShoppingBag className="h-3 w-3 flex-none text-amber-700" />}
                                                        <span className="truncate">{displayName}</span>
                                                    </div>
                                                    <div className="text-muted-foreground">
                                                        {isTakeaway && e.takeaway_cost_cents != null
                                                            ? formatMoneyFromCents(e.takeaway_cost_cents)
                                                            : `${e.servings} servings`}
                                                    </div>
                                                    <div className="mt-1 flex flex-wrap gap-1">
                                                        {isTakeaway && <Badge variant="outline" className="border-amber-300 bg-amber-100 text-[10px] text-amber-900">Takeaway</Badge>}
                                                        {e.served_at && <Badge variant="outline" className="text-[10px]">Served</Badge>}
                                                        {overridden && <Badge variant="outline" className="border-red-300 bg-red-100 text-[10px] text-red-900">Override</Badge>}
                                                    </div>
                                                </button>
                                            );
                                        })}
                                        <button
                                            type="button"
                                            onClick={() => onCellClick(dateIso, slot)}
                                            className="invisible absolute bottom-1 right-1 rounded-full bg-primary/90 p-1 text-primary-foreground shadow group-hover:visible"
                                            aria-label="Add meal"
                                        >
                                            <Plus className="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                );
                            })}
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
