import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Check, ChevronsUpDown, Loader2, Plus, Search } from 'lucide-react';
import {
    createContext,
    useCallback,
    useContext,
    useId,
    useMemo,
    useState,
    type ReactNode,
} from 'react';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import { VehicleSearchSelect } from './search-select';
import type { CatalogueKind, Person } from './types';

/**
 * Example presets shown in the pickers. They are suggestions, not policy:
 * only choices someone adds are stored (FleetCatalogueService).
 */
const PRESETS: Record<CatalogueKind, string[]> = {
    service_type: [
        'Routine service',
        'Oil & filter change',
        'Tyres & alignment',
        'Brake inspection',
        'Accessibility lift service',
        'Battery & electrical',
        'Air conditioning',
        'Safety inspection',
    ],
    document_type: [
        'Insurance policy',
        'Warranty',
        'Ownership agreement',
        'Purchase agreement',
        'Lease agreement',
        'Registration',
        'Inspection certificate',
        'Service report',
        'Invoice',
        'Vehicle manual',
        'Other supporting document',
    ],
    reminder_title: [
        'Service booking follow-up',
        'Insurance renewal',
        'Registration renewal',
        'Evidence review',
        'Inspection follow-up',
        'Mileage check',
    ],
    vehicle_body_type: [
        'Passenger van',
        'Car',
        'Minibus',
        'Utility vehicle',
        'Wheelchair accessible van',
    ],
    vehicle_manufacturer: ['Toyota', 'Ford', 'Hyundai', 'Mercedes-Benz'],
    vehicle_model: ['Hiace', 'Transit', 'Staria', 'Sprinter'],
    vehicle_use_purpose: [
        'Community transport',
        'Client outings',
        'Staff travel',
    ],
    ownership_arrangement: [
        'Owned',
        'Leased',
        'Hired',
        'Loan vehicle',
        'Donated',
    ],
    interval_months: ['1', '3', '6', '12', '24'],
    interval_km: ['5000', '10000', '15000', '20000'],
    reminder_days_before: ['0', '3', '7', '14', '30'],
    reminder_km_before: ['250', '500', '1000', '2000'],
    repeat_months: ['0', '1', '3', '6', '12'],
    seats: ['2', '5', '7', '8', '10', '12'],
};

const NUMERIC: Partial<Record<CatalogueKind, string>> = {
    interval_months: 'months',
    interval_km: 'km',
    reminder_days_before: 'days',
    reminder_km_before: 'km',
    repeat_months: 'months',
    seats: 'seats',
};

type Entry = { id: number; label: string };
type CatalogueState = {
    entries: Record<CatalogueKind, Entry[]>;
    canAdd: boolean;
    add: (kind: CatalogueKind, entry: Entry) => void;
};

const CatalogueContext = createContext<CatalogueState | null>(null);

export function CatalogueProvider({
    entries: initial,
    canAdd,
    children,
}: {
    entries: Record<CatalogueKind, Entry[]>;
    canAdd: boolean;
    children: ReactNode;
}) {
    const [added, setAdded] = useState<Partial<Record<CatalogueKind, Entry[]>>>(
        {},
    );
    const add = useCallback((kind: CatalogueKind, entry: Entry) => {
        setAdded((current) => ({
            ...current,
            [kind]: [...(current[kind] ?? []), entry],
        }));
    }, []);
    const entries = useMemo(() => {
        const merged = { ...initial };
        (Object.keys(added) as CatalogueKind[]).forEach((kind) => {
            merged[kind] = [...(initial[kind] ?? []), ...(added[kind] ?? [])];
        });
        return merged;
    }, [initial, added]);

    return (
        <CatalogueContext.Provider value={{ entries, canAdd, add }}>
            {children}
        </CatalogueContext.Provider>
    );
}

const normalise = (value: string) =>
    value.trim().replace(/\s+/g, ' ').toLowerCase();

export function formatChoice(kind: CatalogueKind, value: string): string {
    const unit = NUMERIC[kind];
    if (!unit || value === '') return value;
    if (kind === 'repeat_months' && value === '0') return 'One-off';
    const number = Number(value);
    // "1 month", "1 day", "1 seat"; km never takes a plural.
    const label = number === 1 && unit !== 'km' ? unit.replace(/s$/, '') : unit;

    return `${Number.isFinite(number) ? number.toLocaleString('en-NZ') : value} ${label}`;
}

/**
 * A searchable choice with explicit "Add new". The chosen label is stored on
 * the owning record; additions are saved centrally for everyone.
 */
export function CataloguePicker({
    id,
    kind,
    label,
    value,
    onChange,
    optional,
    invalid,
    describedBy,
    disabled,
}: {
    id?: string;
    kind: CatalogueKind;
    label: string;
    value: string;
    onChange: (value: string) => void;
    optional?: boolean;
    invalid?: boolean;
    describedBy?: string;
    disabled?: boolean;
}) {
    const catalogue = useContext(CatalogueContext);
    const generatedId = useId();
    const hintId = `${generatedId}-hint`;
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const command = useVehicleRecordCommand(isJsonObject);
    const unit = NUMERIC[kind];
    const options = useMemo(() => {
        const saved = (catalogue?.entries[kind] ?? []).map(
            (entry) => entry.label,
        );
        const all = [...PRESETS[kind], ...saved, ...(value ? [value] : [])];
        const seen = new Set<string>();
        const unique = all.filter((option) => {
            const key = normalise(option);
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        });
        return unit ? unique.sort((a, b) => Number(a) - Number(b)) : unique;
    }, [catalogue, kind, value, unit]);
    const typed = query.trim().replace(/\s+/g, ' ');
    const typedValid = unit
        ? /^\d+$/.test(typed) &&
          Number(typed) >= 1 &&
          Number(typed) <= 1_000_000
        : typed.length >= 1 && typed.length <= 80;
    const exists = options.some(
        (option) => normalise(option) === normalise(typed),
    );
    const canAdd = !!catalogue?.canAdd && typedValid && !exists;

    const addTyped = async () => {
        const result = await command.submit(`/fleet-assets/catalogue/${kind}`, {
            label: typed,
        });
        const entry =
            result && isJsonObject(result.entry) ? result.entry : null;
        if (
            entry &&
            typeof entry.id === 'number' &&
            typeof entry.label === 'string'
        ) {
            catalogue?.add(kind, { id: entry.id, label: entry.label });
            onChange(entry.label);
            setOpen(false);
            setQuery('');
        }
    };

    return (
        <div className="grid gap-1">
            <Popover
                open={open}
                onOpenChange={(next) => {
                    setOpen(next);
                    if (!next) setQuery('');
                }}
            >
                <PopoverTrigger asChild>
                    <Button
                        id={id ?? generatedId}
                        type="button"
                        variant="outline"
                        role="combobox"
                        aria-label={optional ? `${label} (optional)` : label}
                        aria-expanded={open}
                        aria-invalid={invalid}
                        aria-describedby={[describedBy, hintId]
                            .filter(Boolean)
                            .join(' ')}
                        disabled={disabled}
                        className="w-full justify-between font-normal"
                    >
                        <Search className="size-4 shrink-0 text-muted-foreground" />
                        <span className="min-w-0 flex-1 truncate text-left">
                            {value ? (
                                formatChoice(kind, value)
                            ) : (
                                <span className="text-muted-foreground">
                                    Choose or add custom…
                                </span>
                            )}
                        </span>
                        <ChevronsUpDown className="size-4 shrink-0 text-muted-foreground" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    className="w-[var(--radix-popover-trigger-width)] min-w-64 p-0"
                    align="start"
                >
                    <Command>
                        <CommandInput
                            placeholder={
                                unit
                                    ? 'Search or enter a whole number…'
                                    : 'Search or enter a custom name…'
                            }
                            value={query}
                            onValueChange={setQuery}
                        />
                        <CommandList>
                            <CommandEmpty>No matching options.</CommandEmpty>
                            <CommandGroup>
                                {options.map((option) => (
                                    <CommandItem
                                        key={option}
                                        value={`${option} ${formatChoice(kind, option)}`}
                                        onSelect={() => {
                                            onChange(option);
                                            setOpen(false);
                                            setQuery('');
                                        }}
                                    >
                                        <span className="min-w-0 flex-1">
                                            {formatChoice(kind, option)}
                                        </span>
                                        {normalise(value) ===
                                            normalise(option) && (
                                            <Check className="size-4 text-primary" />
                                        )}
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        </CommandList>
                    </Command>
                    {canAdd && (
                        <div className="border-t p-2">
                            <Button
                                type="button"
                                variant="ghost"
                                className="w-full justify-start text-primary"
                                disabled={command.processing}
                                onClick={addTyped}
                            >
                                {command.processing ? (
                                    <Loader2 className="size-4 animate-spin" />
                                ) : (
                                    <Plus className="size-4" />
                                )}
                                Add new: {formatChoice(kind, typed)}
                            </Button>
                        </div>
                    )}
                    {command.message && (
                        <p
                            role="alert"
                            className="border-t p-2 text-xs text-destructive"
                        >
                            {command.errors.label ??
                                'This option could not be saved. Retry or choose an existing option.'}
                        </p>
                    )}
                </PopoverContent>
            </Popover>
            <p id={hintId} className="text-caption">
                {!catalogue?.canAdd
                    ? 'Choose an existing option. Adding options needs fleet or document management access.'
                    : unit
                      ? 'Presets are examples; confirm the value that applies to this vehicle.'
                      : 'New options are saved for everyone once added.'}
            </p>
        </div>
    );
}

/** People at the vehicle's site, searchable by name. */
export function PersonPicker({
    id,
    label,
    value,
    people,
    onChange,
    invalid,
    describedBy,
    disabled,
}: {
    id?: string;
    label: string;
    value: number | null;
    people: Person[];
    onChange: (id: number | null) => void;
    invalid?: boolean;
    describedBy?: string;
    disabled?: boolean;
}) {
    return (
        <VehicleSearchSelect
            id={id}
            label={label}
            value={value ? String(value) : ''}
            options={people.map((person) => ({
                value: String(person.id),
                label: person.name,
            }))}
            onChange={(next) => onChange(next ? Number(next) : null)}
            invalid={invalid}
            describedBy={describedBy}
            disabled={disabled}
        />
    );
}
