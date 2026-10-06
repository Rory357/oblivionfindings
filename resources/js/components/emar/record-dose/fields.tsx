/* eMAR P01 — the recording dialog's field pieces (approved P01 v2,
 * `record-dialog.tsx`): labelled field, note, amount given (as ordered /
 * less / more), and the second person by witness PIN. */
import InputError from '@/components/input-error';
import { WitnessPinInput } from '@/components/medications/witness-pin-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { TilePicker } from '@/components/wizard/primitives';
import {
    AlertOctagon,
    AlertTriangle,
    Check,
    ChevronDown,
    CircleAlert,
    OctagonAlert,
    Search,
    ShieldAlert,
    Users,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { onCallText } from './copy';
import { Notice, Rich } from './parts';
import type { Candidate, DoseRequirements, SecondPersonKind } from './types';

export type Errors = Record<string, string>;

export function Field({
    id,
    label,
    required,
    error,
    hint,
    children,
}: {
    id: string;
    label: string;
    required?: boolean;
    error?: string;
    hint?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div data-field={id} className="space-y-1.5">
            <Label htmlFor={`rd-${id}`} className="text-sm font-medium">
                {label}{' '}
                {required ? (
                    <span className="text-status-critical">*</span>
                ) : (
                    <span className="text-subtle">(optional)</span>
                )}
            </Label>
            {children}
            {error ? (
                <InputError message={error} />
            ) : hint ? (
                <p className="text-caption">{hint}</p>
            ) : null}
        </div>
    );
}

export function NoteField({
    value,
    onChange,
    error,
    placeholder,
    required,
}: {
    value: string;
    onChange: (v: string) => void;
    error?: string;
    placeholder: string;
    required?: boolean;
}) {
    return (
        <Field
            id="note"
            label={
                required
                    ? 'What happened (goes into the medication error report)'
                    : 'What happened'
            }
            required={required}
            error={error}
            hint="Saved with this dose and shown in the chart history."
        >
            <Textarea
                id="rd-note"
                rows={2}
                maxLength={1000}
                value={value}
                aria-invalid={!!error}
                placeholder={placeholder}
                onChange={(e) => onChange(e.target.value)}
            />
        </Field>
    );
}

export function ReasonSelect({
    id,
    label,
    value,
    onChange,
    options,
    error,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (v: string) => void;
    options: Record<string, string>;
    error?: string;
}) {
    return (
        <Field id={id} label={label} required error={error}>
            <Select value={value || undefined} onValueChange={onChange}>
                <SelectTrigger
                    id={`rd-${id}`}
                    aria-invalid={!!error}
                    className="frontline-tap w-full"
                >
                    <SelectValue placeholder="Choose a reason" />
                </SelectTrigger>
                <SelectContent>
                    {Object.entries(options).map(([key, words]) => (
                        <SelectItem key={key} value={key}>
                            {words}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </Field>
    );
}

/* ───────────── amount given (Stephan-approved, P00 v4/v5) ───────────── */
export type AmountMode = 'asOrdered' | 'less' | 'more';

export interface AmountState {
    mode: AmountMode;
    amount: number | null;
    reason: string;
    severity: string;
    immediate: string;
}

export const formatAmount = (n: number | null, unit: string | null): string => {
    if (n == null) return '—';
    const whole = Math.floor(n);
    const frac = n - whole;
    const words =
        frac === 0.5 ? `${whole ? whole : ''}½` : String(Number(n.toFixed(2)));
    const u = (unit ?? '').trim();
    if (!u) return words;
    const plural =
        n > 1 &&
        !u.endsWith('s') &&
        /^(tablet|capsule|caplet|drop|puff|sachet|patch|unit)$/i.test(u)
            ? `${u}s`
            : u;

    return `${words} ${plural}`;
};

const stepFor = (unit: string | null) =>
    /(tablet|caplet)/i.test(unit ?? '') ? 0.5 : 1;

function Stepper({
    value,
    onChange,
    unit,
    label,
    invalid,
}: {
    value: number | null;
    onChange: (n: number) => void;
    unit: string | null;
    label: string;
    invalid?: boolean;
}) {
    const step = stepFor(unit);
    return (
        <div className="flex items-center gap-2">
            <Button
                type="button"
                variant="outline"
                size="icon"
                className="frontline-tap"
                aria-label={`Less — ${label.toLowerCase()}`}
                onClick={() => onChange(Math.max(0, (value ?? 0) - step))}
            >
                −
            </Button>
            <Input
                id="rd-amount"
                inputMode="decimal"
                autoComplete="off"
                className="w-20 text-center"
                aria-invalid={invalid}
                aria-label={`${label}${unit ? `, in ${unit}` : ''}`}
                value={value == null ? '' : String(value)}
                onChange={(e) =>
                    onChange(Number(e.target.value.replace(/[^\d.]/g, '')) || 0)
                }
            />
            <Button
                type="button"
                variant="outline"
                size="icon"
                className="frontline-tap"
                aria-label={`More — ${label.toLowerCase()}`}
                onClick={() => onChange((value ?? 0) + step)}
            >
                +
            </Button>
            {unit ? (
                <span className="text-sm text-muted-foreground">
                    {unit}{' '}
                    <span className="text-caption">
                        (unit follows the order)
                    </span>
                </span>
            ) : null}
        </div>
    );
}

const SEVERITY_TILES = [
    {
        key: 'minor',
        label: 'Minor',
        description: 'No harm expected',
        icon: CircleAlert,
    },
    {
        key: 'moderate',
        label: 'Moderate',
        description: 'Needs watching or advice',
        icon: AlertTriangle,
    },
    {
        key: 'major',
        label: 'Major',
        description: 'Needs treatment or a visit',
        icon: ShieldAlert,
    },
    {
        key: 'critical',
        label: 'Critical',
        description: 'Emergency — call for help',
        icon: OctagonAlert,
    },
];

export function AmountField({
    req,
    value,
    onChange,
    errors,
    secondPerson,
    amountUnconfirmed,
    secondKind,
}: {
    req: DoseRequirements;
    value: AmountState;
    onChange: (patch: Partial<AmountState>) => void;
    errors: Errors;
    secondPerson: ReactNode;
    amountUnconfirmed: boolean;
    secondKind: SecondPersonKind | null;
}) {
    const ordered = req.order.dose_amount;
    const unit = req.order.dose_unit;
    const asOrderedText =
        req.order.dosage ??
        (ordered != null ? formatAmount(ordered, unit) : 'As ordered');
    // A smaller controlled dose is balanced in the stock's own unit; when the
    // order is counted in another unit the remainder can't be worked out here
    // (P0-2: refused until P07b's loss record).
    const cdNoLess =
        req.order.controlled && !(req.order.stock?.from_order ?? false);
    const back = (label: string) => (
        <Button
            type="button"
            variant="outline"
            className="frontline-tap"
            onClick={() => onChange({ mode: 'asOrdered', amount: ordered })}
        >
            {label}
        </Button>
    );

    if (value.mode === 'more') {
        return (
            <section
                role="group"
                aria-labelledby="rd-more-l"
                className="space-y-3 rounded-xl border border-status-critical/40 p-4"
            >
                <p
                    id="rd-more-l"
                    className="flex items-center gap-2 text-sm font-semibold text-status-critical"
                >
                    <AlertOctagon className="size-4" aria-hidden="true" /> More
                    than ordered was given
                </p>
                <Notice
                    tone="critical"
                    title="Only record this if it has already happened"
                >
                    The chart will show what was really given. Saving also
                    reports a medication error and creates one linked incident.
                    A colleague’s PIN is not authority for a larger dose.
                </Notice>
                <Field
                    id="amount"
                    label="Amount actually given"
                    required
                    error={errors.amount}
                    hint={`The order is ${asOrderedText}.`}
                >
                    <Stepper
                        value={value.amount}
                        onChange={(n) => onChange({ amount: n })}
                        unit={unit}
                        label="Amount actually given"
                        invalid={!!errors.amount}
                    />
                </Field>
                <div data-field="severity" className="space-y-2">
                    <Label id="rd-sev-l" className="text-sm font-medium">
                        How serious does it seem?{' '}
                        <span className="text-status-critical">*</span>
                    </Label>
                    <TilePicker
                        frontline
                        labelledBy="rd-sev-l"
                        invalid={!!errors.severity}
                        value={value.severity}
                        onChange={(k) => onChange({ severity: k })}
                        options={SEVERITY_TILES}
                    />
                    {errors.severity ? (
                        <InputError message={errors.severity} />
                    ) : (
                        <p className="text-caption">
                            Your first view — the house lead reviews it.
                        </p>
                    )}
                </div>
                <Field
                    id="immediate"
                    label="What did you do straight away?"
                    required
                    error={errors.immediate}
                >
                    <Textarea
                        id="rd-immediate"
                        rows={2}
                        value={value.immediate}
                        aria-invalid={!!errors.immediate}
                        placeholder="e.g. Rang the on-call contact. Staying with them."
                        onChange={(e) =>
                            onChange({ immediate: e.target.value })
                        }
                    />
                </Field>
                <div className="rounded-lg border bg-muted/40 p-3 text-sm">
                    <p className="font-medium">Now</p>
                    <ol className="mt-1 list-decimal space-y-0.5 pl-5">
                        <li>
                            Contact the prescriber or on-call contact:{' '}
                            <Rich text={onCallText(req)} />
                        </li>
                        <li>
                            Stay with the person and watch closely. Note what
                            you see.
                        </li>
                        <li>
                            The house lead and everyone rostered at{' '}
                            {req.person.house ?? 'the house'} are told when you
                            save, and see it until it’s resolved.
                        </li>
                    </ol>
                </div>
                {back('Back to the ordered amount')}
            </section>
        );
    }

    if (value.mode === 'less') {
        return (
            <section
                role="group"
                aria-labelledby="rd-less-l"
                className="space-y-3 rounded-xl border p-4"
            >
                <p id="rd-less-l" className="text-sm font-semibold">
                    Amount given — less than ordered
                </p>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field
                        id="amount"
                        label="Amount given"
                        required
                        error={errors.amount}
                        hint={`The order is ${asOrderedText}.`}
                    >
                        <Stepper
                            value={value.amount}
                            onChange={(n) => onChange({ amount: n })}
                            unit={unit}
                            label="Amount given"
                            invalid={!!errors.amount}
                        />
                    </Field>
                    <ReasonSelect
                        id="amountReason"
                        label="Why was it less?"
                        value={value.reason}
                        onChange={(v) => onChange({ reason: v })}
                        options={req.options.amount_reasons}
                        error={errors.amountReason}
                    />
                </div>
                {secondKind && secondKind !== 'amount' ? (
                    <p className="text-caption">
                        The{' '}
                        {secondKind === 'witness'
                            ? 'witness'
                            : 'person confirming the dose'}{' '}
                        above also confirms the different amount.
                    </p>
                ) : amountUnconfirmed ? (
                    <Notice
                        tone="warning"
                        icon={Users}
                        title="Nobody else on shift to confirm the amount"
                    >
                        It will still be recorded, marked “Not confirmed by a
                        second person”, and the house lead gets a follow-up —
                        the house lead and everyone rostered at{' '}
                        {req.person.house ?? 'the house'} see it until it’s
                        resolved. This never stops you recording what happened.
                    </Notice>
                ) : (
                    <div className="space-y-2">
                        <p className="text-sm font-medium">
                            A colleague on shift confirms the different amount
                        </p>
                        {secondPerson}
                    </div>
                )}
                <div className="flex flex-wrap gap-2">
                    {back('Use the ordered amount')}
                    <Button
                        type="button"
                        variant="outline"
                        className="frontline-tap"
                        onClick={() => onChange({ mode: 'more', amount: null })}
                    >
                        More than ordered was given
                    </Button>
                </div>
            </section>
        );
    }

    return (
        <div data-field="amount" className="space-y-1.5">
            <p id="rd-amt-l" className="text-sm font-medium">
                Amount given
            </p>
            <div
                role="group"
                aria-labelledby="rd-amt-l"
                className="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
            >
                <Check
                    className="size-4 text-status-success"
                    aria-hidden="true"
                />
                <strong>{asOrderedText}</strong>
                <span className="text-subtle">
                    as ordered{unit ? ' · the unit follows the order' : ''}
                </span>
            </div>
            {ordered != null && cdNoLess ? (
                <div className="space-y-1.5">
                    <Button
                        type="button"
                        variant="outline"
                        className="frontline-tap"
                        onClick={() => onChange({ mode: 'more', amount: null })}
                    >
                        More than ordered was given
                    </Button>
                    <p className="text-caption">
                        Less than ordered can’t be recorded here for this
                        controlled medicine: its dose and stock are counted in
                        different units. Record what was removed and report the
                        remainder through Controlled drugs › Loss.
                    </p>
                </div>
            ) : ordered != null ? (
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        className="frontline-tap"
                        onClick={() =>
                            onChange({ mode: 'less', amount: null, reason: '' })
                        }
                    >
                        Record a different amount
                    </Button>
                </div>
            ) : null}
        </div>
    );
}

/* ───────────── second person by witness PIN (Stephan option B) ───────────── */
export interface SecondState {
    id: number | null;
    pin: string;
    forgotten?: boolean;
    present?: boolean;
}

export function forgottenPinAvailable(
    req: DoseRequirements,
    kind: SecondPersonKind | null,
    colleagueId: number | null,
): boolean {
    return (
        (kind === 'amount'
            ? req.second_person.forgotten_pin_amount_allowed === true
            : req.second_person.forgotten_pin_allowed === true) &&
        (kind === 'rule' || kind === 'amount' || kind === 'cosigner') &&
        !req.order.controlled &&
        !req.order.witness_required &&
        req.second_person.candidates.some(
            (candidate) =>
                candidate.id === colleagueId && candidate.can_confirm,
        )
    );
}

const SECOND_TITLE: Record<SecondPersonKind, string> = {
    witness: 'Witness — a second person watches and confirms',
    rule: 'Second person confirms this dose',
    cosigner: 'Co-signer — a colleague confirms this dose',
    amount: 'Second person confirms the amount',
};
const SECOND_LABEL: Record<SecondPersonKind, string> = {
    witness: 'Witnessed by',
    rule: 'Confirmed by',
    cosigner: 'Co-signed by',
    amount: 'Confirmed by',
};

export function SecondPerson({
    kind,
    req,
    value,
    onChange,
    errors,
    nobody,
}: {
    kind: SecondPersonKind;
    req: DoseRequirements;
    value: SecondState;
    onChange: (v: SecondState) => void;
    errors: Errors;
    nobody: boolean;
}) {
    const [open, setOpen] = useState(false);
    const house = req.person.house ?? 'this house';
    const lead = req.house_lead?.name;
    if (nobody && kind === 'rule') {
        return (
            <Notice
                tone="warning"
                icon={Users}
                title="Nobody else on shift can confirm this dose"
            >
                The medication rule asks for a second person, but nobody else on
                shift at {house} has current competency and a witness PIN (from
                the roster). The dose will still be recorded, marked “Not
                confirmed by a second person”, and{' '}
                {lead ? `${lead} (house lead)` : 'the house lead'} gets a
                follow-up by the end of the next shift. The house lead and
                everyone rostered at {house} see it until it’s resolved. This
                never stops you recording what happened.
            </Notice>
        );
    }
    if (nobody) return null;

    const chosen: Candidate | undefined = req.second_person.candidates.find(
        (c) => c.id === value.id,
    );
    const usable = (c: Candidate) => c.can_confirm;
    const fallbackAvailable = forgottenPinAvailable(req, kind, value.id);
    const forgotten = fallbackAvailable && value.forgotten === true;
    return (
        <section
            role="group"
            aria-labelledby="rd-sp-l"
            className="space-y-3 rounded-xl border p-4"
        >
            <p
                id="rd-sp-l"
                className="flex items-center gap-2 text-sm font-semibold"
            >
                <Users className="size-4" aria-hidden="true" />
                {SECOND_TITLE[kind]}
            </p>
            {kind === 'rule'
                ? req.second_person.rule_sentences.map((s) => (
                      <p key={s} className="text-caption">
                          {s}
                      </p>
                  ))
                : null}
            {kind === 'cosigner' && req.competency.message ? (
                <p className="text-caption">{req.competency.message}</p>
            ) : null}
            <div className="grid gap-3 sm:grid-cols-2">
                <div data-field="second" className="space-y-1.5">
                    <Label htmlFor="rd-second" className="text-sm font-medium">
                        {SECOND_LABEL[kind]}{' '}
                        <span className="text-status-critical">*</span>
                    </Label>
                    <Popover open={open} onOpenChange={setOpen}>
                        <PopoverTrigger asChild>
                            <Button
                                id="rd-second"
                                type="button"
                                variant="outline"
                                role="combobox"
                                aria-expanded={open}
                                aria-invalid={!!errors.second}
                                className="frontline-tap w-full justify-between font-normal"
                            >
                                <span className="flex items-center gap-2 truncate">
                                    <Search
                                        className="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    {chosen?.name ??
                                        'Choose a colleague on shift'}
                                </span>
                                <ChevronDown
                                    className="size-4 opacity-60"
                                    aria-hidden="true"
                                />
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent
                            className="w-[min(360px,calc(100vw-32px))] p-0"
                            align="start"
                        >
                            <Command>
                                <CommandInput placeholder="Search colleagues on shift…" />
                                <CommandList>
                                    <CommandEmpty>
                                        No colleague on shift matches.
                                    </CommandEmpty>
                                    <CommandGroup
                                        heading={`On shift at ${house} now`}
                                    >
                                        {req.second_person.candidates.map(
                                            (c) => (
                                                <CommandItem
                                                    key={c.id}
                                                    value={c.name}
                                                    disabled={!usable(c)}
                                                    onSelect={() => {
                                                        onChange({
                                                            id: c.id,
                                                            pin: '',
                                                        });
                                                        setOpen(false);
                                                    }}
                                                    className="items-start"
                                                >
                                                    <span className="min-w-0">
                                                        <span className="block text-sm font-medium">
                                                            {c.name}
                                                        </span>
                                                        <span className="block text-xs text-muted-foreground">
                                                            {usable(c)
                                                                ? 'On shift now · can confirm'
                                                                : 'Can’t confirm this dose — can’t be chosen'}
                                                        </span>
                                                    </span>
                                                    {value.id === c.id ? (
                                                        <Check
                                                            className="ml-auto size-4"
                                                            aria-hidden="true"
                                                        />
                                                    ) : null}
                                                </CommandItem>
                                            ),
                                        )}
                                    </CommandGroup>
                                </CommandList>
                                <p className="border-t px-3 py-2 text-xs text-muted-foreground">
                                    Only colleagues clocked in at {house} now
                                    are listed.
                                    {kind === 'witness'
                                        ? ' A witness also needs controlled-medicine witness competency.'
                                        : ''}
                                </p>
                            </Command>
                        </PopoverContent>
                    </Popover>
                    <InputError message={errors.second} />
                </div>
                <div data-field="pin">
                    {!forgotten && (
                        <WitnessPinInput
                            id="rd-pin"
                            value={value.pin}
                            onChange={(pin) => onChange({ ...value, pin })}
                            label={
                                kind === 'witness'
                                    ? 'Witness’s 6-digit PIN'
                                    : 'Their 6-digit PIN'
                            }
                            atCupboard={kind === 'witness'}
                            error={errors.pin}
                        />
                    )}
                    {fallbackAvailable && (
                        <Button
                            type="button"
                            variant="link"
                            size="sm"
                            className="px-0"
                            onClick={() =>
                                onChange({
                                    ...value,
                                    pin: '',
                                    forgotten: !forgotten,
                                    present: false,
                                })
                            }
                        >
                            {forgotten
                                ? 'Use their PIN instead'
                                : 'They forgot their PIN'}
                        </Button>
                    )}
                </div>
            </div>
            {forgotten && (
                <Notice
                    tone="warning"
                    icon={Users}
                    title="Second person not yet verified"
                >
                    <p>
                        {chosen?.name} must sign in to their own account and
                        confirm within{' '}
                        {req.second_person.confirm_within_minutes ?? 30}{' '}
                        minutes. Until then, the chart shows confirmation
                        pending. A disagreement or no reply goes to the house
                        lead.
                    </p>
                    <div className="mt-3 flex items-start gap-2">
                        <Checkbox
                            id="rd-second-present"
                            checked={value.present === true}
                            onCheckedChange={(checked) =>
                                onChange({
                                    ...value,
                                    present: checked === true,
                                })
                            }
                            aria-invalid={!!errors.pin}
                        />
                        <Label htmlFor="rd-second-present">
                            I confirm {chosen?.name} was present for this dose.
                        </Label>
                    </div>
                    <InputError message={errors.pin} />
                </Notice>
            )}
        </section>
    );
}
