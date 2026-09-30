/* The witness for a controlled-medicine count or movement — P01 v2's approved
 * witness picker and PIN field (record-dialog.tsx `SecondPerson`, kind
 * "witness"), wording verbatim. PIN-1's server messages align to this at
 * build (review session, 30 Sep 2026).
 * Real Popover + Command picker, Input, Checkbox, Label, InputError. */
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { Check, ChevronDown, Search, Users } from 'lucide-react';
import { useState } from 'react';
import { HOUSES, type HouseKey, type PersonaId } from './data';
import { PIN, candidates, type Scenario } from './model';
import { DesignNote, Notice, Rich } from './ui';

export interface WitnessValue {
    id: string | null;
    pin: string;
}
export function WitnessField({
    persona,
    house,
    scenario,
    value,
    onChange,
    errors,
    purpose,
}: {
    persona: PersonaId;
    house: HouseKey;
    scenario: Scenario;
    value: WitnessValue;
    onChange: (v: WitnessValue) => void;
    errors: { witness?: string; pin?: string };
    purpose: string;
}) {
    const [open, setOpen] = useState(false);
    const cands = candidates(persona, house, scenario);
    const chosen = cands.find((c) => c.staff.id === value.id);
    const anyOk = cands.some((c) => c.ok);
    return (
        <section role="group" aria-labelledby="wf-l" className="space-y-3 rounded-xl border p-4">
            <p id="wf-l" className="flex items-center gap-2 text-sm font-semibold">
                <Users className="size-4" aria-hidden="true" />
                Witness — a second person watches {purpose} and confirms with their PIN
            </p>
            {!anyOk ? (
                <Notice tone="warning" title={`Nobody on shift at ${HOUSES[house]} can witness right now`}>
                    <Rich text="A witness must be a different person, clocked in at the house, with controlled-medicine witness competency and a witness PIN. Nobody on shift now meets all of them — see why next to each name below." />
                </Notice>
            ) : null}
            <div className="grid gap-3 sm:grid-cols-2">
                <div data-field="witness" className="space-y-1.5">
                    <Label htmlFor="w-witness" className="text-sm font-medium">
                        Witnessed by <span className="text-status-critical">*</span>
                    </Label>
                    <Popover open={open} onOpenChange={setOpen}>
                        <PopoverTrigger asChild>
                            <Button id="w-witness" type="button" variant="outline" role="combobox" aria-expanded={open} aria-invalid={!!errors.witness} className="w-full justify-between font-normal">
                                <span className="flex items-center gap-2 truncate">
                                    <Search className="size-4 text-muted-foreground" />
                                    {chosen?.staff.name ?? 'Choose a colleague on shift'}
                                </span>
                                <ChevronDown className="size-4 opacity-60" />
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent className="w-[400px] p-0" align="start">
                            <Command>
                                <CommandInput placeholder="Search colleagues on shift…" />
                                <CommandList>
                                    <CommandEmpty>No colleague on shift matches.</CommandEmpty>
                                    <CommandGroup heading={`On shift at ${HOUSES[house]} now`}>
                                        {cands.map((c) => (
                                            <CommandItem
                                                key={c.staff.id}
                                                value={c.staff.name}
                                                disabled={!c.ok}
                                                onSelect={() => {
                                                    onChange({ id: c.staff.id, pin: '' });
                                                    setOpen(false);
                                                }}
                                                className="items-start"
                                            >
                                                <span className="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/15 text-[10px] font-semibold text-primary">{c.staff.initials}</span>
                                                <span className="min-w-0">
                                                    <span className="block text-sm font-medium">
                                                        {c.staff.name}
                                                        {c.self ? ' (you)' : ''}
                                                    </span>
                                                    <span className={cn('block text-xs', c.ok ? 'text-muted-foreground' : 'text-status-critical')}>
                                                        {c.staff.role} · {c.ok ? c.why : `${c.why} — can’t be chosen`}
                                                    </span>
                                                </span>
                                                {value.id === c.staff.id ? <Check className="ml-auto size-4" /> : null}
                                            </CommandItem>
                                        ))}
                                    </CommandGroup>
                                </CommandList>
                                <p className="border-t px-3 py-2 text-xs text-muted-foreground">Only colleagues clocked in at {HOUSES[house]} now are listed. A witness also needs controlled-medicine witness competency and a witness PIN.</p>
                            </Command>
                        </PopoverContent>
                    </Popover>
                    <InputError message={errors.witness} />
                </div>
                <div data-field="pin" className="space-y-1.5">
                    <Label htmlFor="w-pin" className="text-sm font-medium">
                        {PIN.label} <span className="text-status-critical">*</span>
                    </Label>
                    <Input id="w-pin" type="password" inputMode="numeric" maxLength={6} autoComplete="off" value={value.pin} disabled={!chosen} aria-invalid={!!errors.pin} aria-describedby="w-pin-h" onChange={(e) => onChange({ ...value, pin: e.target.value.replace(/\D/g, '').slice(0, 6) })} />
                    {errors.pin ? (
                        <InputError message={errors.pin} />
                    ) : (
                        <p id="w-pin-h" className="text-caption">
                            {PIN.help}
                        </p>
                    )}
                </div>
            </div>
            <div className="space-y-1">
                <div className="flex items-center gap-2">
                    <Checkbox id="w-forgot" disabled checked={false} aria-describedby="w-forgot-h" />
                    <Label htmlFor="w-forgot" className="text-sm font-normal text-muted-foreground">
                        They’ve forgotten their PIN
                    </Label>
                </div>
                <p id="w-forgot-h" className="text-caption">
                    {PIN.forgottenNotAllowed}
                </p>
            </div>
            <DesignNote title="Mockup">PIN 000000 shows a wrong PIN; 999999 shows a locked PIN; any other 6 digits succeed. PINs are checked when you save (PIN-1 builds the check).</DesignNote>
        </section>
    );
}

/** Server-style PIN check for the mockup (P01 v2's approved messages). */
export function checkPin(name: string, pin: string): string | null {
    if (!/^\d{6}$/.test(pin)) return PIN.blank;
    if (pin === '000000') return PIN.incorrect(name);
    if (pin === '999999') return PIN.locked(name, '3:03 pm');
    return null;
}
