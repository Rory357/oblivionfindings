/* Shared dialog helpers for P07b: the witness field (the REAL PIN-1
 * WitnessPinInput, now on main, and a Select that lists ineligible colleagues
 * disabled, with the reason — Main, Q8), focus-return, the synthetic clock
 * stamp and the required marker. */
import InputError from '@/components/input-error';
import { WitnessPinInput } from '@/components/medications/witness-pin-input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { NOW_LABEL } from './clock';
import type { House } from './data';
import { witnessesAt, witnessProblem } from './model';

export const cantSaveOffline = 'You’re offline, so this can’t be saved yet. Nothing is lost — keep this open and save when you reconnect.';
export const focusFirst = (x: Record<string, string>) => window.setTimeout(() => document.getElementById(Object.keys(x)[0])?.focus(), 50);
export const restore = (rf?: () => HTMLElement | null) => (ev: Event) => {
    const el = rf?.();
    if (el) {
        ev.preventDefault();
        el.focus();
    }
};
export const stamp = () => `Mon 28 Sep, ${NOW_LABEL}`;
export const Req = () => <span className="text-status-critical">*</span>;

/** One witness: who (eligible colleagues at the house) and their PIN, typed at the cupboard. */
export function WitnessField({ id, house, recorder, exclude = [], who, pin, onWho, onPin, errors, label = 'Witness' }: { id: string; house: House; recorder: string; exclude?: string[]; who: string; pin: string; onWho: (v: string) => void; onPin: (v: string) => void; errors: Record<string, string>; label?: string }) {
    const options = witnessesAt(house).filter((w) => !exclude.includes(w.name));
    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-1.5">
                <Label htmlFor={`${id}-who`}>
                    {label} <Req />
                </Label>
                <Select value={who || undefined} onValueChange={onWho}>
                    <SelectTrigger id={`${id}-who`} className="w-full" aria-invalid={!!errors[`${id}-who`]}>
                        <SelectValue placeholder="Someone on shift at the cupboard" />
                    </SelectTrigger>
                    <SelectContent>
                        {options.map((w) => {
                            const problem = witnessProblem(w, recorder);
                            return (
                                <SelectItem key={w.name} value={w.name} disabled={!!problem}>
                                    {w.name}
                                    {problem ? ` — ${problem}` : ''}
                                </SelectItem>
                            );
                        })}
                    </SelectContent>
                </Select>
                <InputError message={errors[`${id}-who`]} />
            </div>
            <WitnessPinInput id={`${id}-pin`} value={pin} onChange={onPin} atCupboard error={errors[`${id}-pin`]} />
        </div>
    );
}
export function witnessErrors(id: string, who: string, pin: string): Record<string, string> {
    const x: Record<string, string> = {};
    if (!who) x[`${id}-who`] = 'Choose who is witnessing.';
    if (!/^\d{6}$/.test(pin)) x[`${id}-pin`] = 'Enter their 6-digit witness PIN.';
    return x;
}
