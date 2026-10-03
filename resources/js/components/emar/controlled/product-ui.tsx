import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import {
    EntityContextMenu,
    EntityKebab,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import {
    EntityTable,
    type EntityTableColumn,
    type EntityTableIdentity,
} from '@/components/lists/entity-table';
import { WitnessPinInput } from '@/components/medications/witness-pin-input';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    formatDateOnly,
    formatDateTime,
    WORKER_TIMEZONE,
} from '@/lib/datetime';
import { cn } from '@/lib/utils';
import {
    AlertTriangle,
    Check,
    ChevronsUpDown,
    ShieldCheck,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import type {
    ControlledMedicine,
    ControlledProductPayload,
    ControlledWitness,
} from './product-types';

export function Notice({
    title,
    children,
    critical = false,
}: {
    title: string;
    children?: ReactNode;
    critical?: boolean;
}) {
    return (
        <Card
            role="status"
            className={cn(
                'flex gap-3 p-4',
                critical
                    ? 'border-status-critical/30 bg-status-critical-bg text-status-critical-foreground'
                    : 'border-status-warning/30 bg-status-warning-bg text-status-warning-foreground',
            )}
        >
            <AlertTriangle className="mt-0.5 size-5 shrink-0" />
            <div className="min-w-0">
                <p className="text-sm font-semibold">{title}</p>
                {children ? (
                    <div className="mt-1 text-sm">{children}</div>
                ) : null}
            </div>
        </Card>
    );
}
export function Field({
    name,
    label,
    value,
    onChange,
    error,
    required = false,
    multiline = false,
    numeric = false,
    hint,
    disabled = false,
}: {
    name: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    required?: boolean;
    multiline?: boolean;
    numeric?: boolean;
    hint?: string;
    disabled?: boolean;
}) {
    const props = {
        id: `controlled-${name}`,
        value,
        disabled,
        onChange: (
            event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>,
        ) => onChange(event.target.value),
        'aria-invalid': !!error,
        'aria-describedby': `controlled-${name}-help`,
        className: 'frontline-tap frontline-focus',
    };
    return (
        <div className="space-y-1.5">
            <Label htmlFor={props.id}>
                {label}
                {required ? (
                    <span className="text-status-critical"> *</span>
                ) : null}
            </Label>
            {multiline ? (
                <Textarea {...props} rows={3} maxLength={5000} />
            ) : (
                <Input
                    {...props}
                    type={numeric ? 'number' : 'text'}
                    inputMode={numeric ? 'decimal' : undefined}
                    min={numeric ? 0 : undefined}
                    step={numeric ? 'any' : undefined}
                    maxLength={500}
                />
            )}
            <div id={`controlled-${name}-help`}>
                {error ? (
                    <p
                        role="alert"
                        className="text-caption text-status-critical"
                    >
                        {error}
                    </p>
                ) : hint ? (
                    <p className="text-caption">{hint}</p>
                ) : null}
            </div>
        </div>
    );
}
export function Picker({
    id,
    label,
    value,
    options,
    onChange,
    error,
    required = false,
    placeholder = 'Choose a record',
}: {
    id: string;
    label: string;
    value: string;
    options: {
        id: string;
        name: string;
        description?: string;
        disabled?: string | null;
    }[];
    onChange: (value: string) => void;
    error?: string;
    required?: boolean;
    placeholder?: string;
}) {
    const [open, setOpen] = useState(false);
    const current = options.find((option) => option.id === value);
    return (
        <div className="space-y-1.5">
            <Label htmlFor={`controlled-${id}`}>
                {label}
                {required ? (
                    <span className="text-status-critical"> *</span>
                ) : null}
            </Label>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        id={`controlled-${id}`}
                        type="button"
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        aria-invalid={!!error}
                        aria-describedby={`controlled-${id}-error`}
                        className="frontline-tap frontline-focus w-full justify-between text-left whitespace-normal"
                    >
                        <span>{current?.name ?? placeholder}</span>
                        <ChevronsUpDown className="size-4 shrink-0" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    className="w-[min(88vw,420px)] p-0"
                    align="start"
                    onEscapeKeyDown={(event) => event.stopPropagation()}
                >
                    <Command>
                        <CommandInput
                            placeholder={`Search ${label.toLowerCase()}`}
                        />
                        <CommandList>
                            <CommandEmpty>No matching records.</CommandEmpty>
                            <CommandGroup>
                                {options.map((option) => (
                                    <CommandItem
                                        key={option.id}
                                        value={`${option.name} ${option.description ?? ''} ${option.id}`}
                                        disabled={!!option.disabled}
                                        onSelect={() => {
                                            onChange(option.id);
                                            setOpen(false);
                                        }}
                                        className="frontline-tap frontline-focus items-start"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p>{option.name}</p>
                                            {option.disabled ||
                                            option.description ? (
                                                <p className="text-caption whitespace-normal">
                                                    {option.disabled ??
                                                        option.description}
                                                </p>
                                            ) : null}
                                        </div>
                                        {value === option.id ? (
                                            <Check className="size-4 shrink-0" />
                                        ) : null}
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
            {error ? (
                <p
                    id={`controlled-${id}-error`}
                    role="alert"
                    className="text-caption text-status-critical"
                >
                    {error}
                </p>
            ) : null}
        </div>
    );
}
export function Tiles<K extends string>({
    name,
    label,
    value,
    options,
    onChange,
    error,
}: {
    name: string;
    label: string;
    value: K | '';
    options: {
        key: K;
        label: string;
        description: string;
        disabled?: string | null;
    }[];
    onChange: (value: K) => void;
    error?: string;
}) {
    return (
        <fieldset className="space-y-2">
            <legend id={`controlled-${name}`} className="text-sm font-medium">
                {label} <span className="text-status-critical">*</span>
            </legend>
            <div className="grid gap-2 sm:grid-cols-2">
                {options.map((option) => (
                    <Button
                        key={option.key}
                        type="button"
                        variant="outline"
                        aria-pressed={value === option.key}
                        aria-describedby={
                            option.disabled
                                ? `${name}-${option.key}-reason`
                                : undefined
                        }
                        disabled={!!option.disabled}
                        onClick={() => onChange(option.key)}
                        className={cn(
                            'h-auto min-h-16 justify-start p-3 text-left whitespace-normal',
                            value === option.key &&
                                'border-primary bg-primary/10',
                        )}
                    >
                        <div>
                            <span className="block font-semibold">
                                {option.label}
                            </span>
                            <span
                                id={`${name}-${option.key}-reason`}
                                className="text-caption block font-normal"
                            >
                                {option.disabled ?? option.description}
                            </span>
                        </div>
                    </Button>
                ))}
            </div>
            {error ? (
                <p role="alert" className="text-caption text-status-critical">
                    {error}
                </p>
            ) : null}
        </fieldset>
    );
}
export interface WitnessValue {
    id: string;
    pin: string;
}
export const emptyWitness = (): WitnessValue => ({ id: '', pin: '' });
export function witnessErrors(
    value: WitnessValue,
    candidates: ControlledWitness[],
    prefix = '',
): Record<string, string> {
    const key = prefix ? `${prefix}_` : '';
    const selected = candidates.find(
        (candidate) => String(candidate.id) === value.id,
    );
    if (!selected?.eligible || selected.pin_status !== 'set')
        return {
            [`${key}witnessed_by`]:
                'Choose a different eligible colleague with a witness PIN.',
        };
    if (!/^\d{6}$/.test(value.pin))
        return { [`${key}witness_credential`]: 'Enter their 6-digit PIN.' };
    return {};
}
export function WitnessField({
    value,
    onChange,
    candidates,
    errors,
    prefix = '',
    excludeId,
}: {
    value: WitnessValue;
    onChange: (value: WitnessValue) => void;
    candidates: ControlledWitness[];
    errors: Record<string, string>;
    prefix?: string;
    excludeId?: string;
}) {
    const key = prefix ? `${prefix}_` : '';
    const available = candidates.filter(
        (candidate) => String(candidate.id) !== excludeId,
    );
    return (
        <div className="space-y-4">
            <Picker
                id={`${key}witnessed_by`}
                label={prefix ? 'Second witness' : 'Witness'}
                required
                value={value.id}
                onChange={(id) => onChange({ id, pin: '' })}
                options={available.map((candidate) => ({
                    id: String(candidate.id),
                    name: candidate.name,
                    description: candidate.eligible
                        ? 'Eligible at this house · PIN set'
                        : (candidate.reason ?? 'Not eligible'),
                    disabled:
                        !candidate.eligible || candidate.pin_status !== 'set'
                            ? (candidate.reason ??
                              'Witness PIN is not available')
                            : null,
                }))}
                error={errors[`${key}witnessed_by`]}
                placeholder="Choose the colleague at the cupboard"
            />
            {available.every(
                (candidate) =>
                    !candidate.eligible || candidate.pin_status !== 'set',
            ) ? (
                <Notice title="Nobody available can witness">
                    Ask the house lead to arrange an eligible colleague.
                    Controlled counts and register changes always need a
                    witness.
                </Notice>
            ) : null}
            <WitnessPinInput
                className="[&_input]:min-h-[44px]"
                id={`controlled-${key}witness_credential`}
                value={value.pin}
                onChange={(pin) => onChange({ ...value, pin })}
                label={
                    prefix
                        ? 'Second witness’s 6-digit PIN'
                        : 'Witness’s 6-digit PIN'
                }
                atCupboard
                disabled={!value.id}
                error={errors[`${key}witness_credential`]}
            />
            <p className="text-caption">
                A forgotten PIN cannot be used for controlled medicines. The
                witness must use their own PIN.
            </p>
        </div>
    );
}
export function MedicineContext({
    medicine,
}: {
    medicine: ControlledMedicine;
}) {
    return (
        <Card className="flex flex-row gap-3 border-primary/30 bg-primary/5 p-3">
            <ShieldCheck className="mt-0.5 size-5 shrink-0 text-primary" />
            <div>
                <p className="text-sm font-semibold">
                    {medicine.name} · {medicine.client_name}
                </p>
                <p className="text-caption">
                    {medicine.site_name} · register balance{' '}
                    {quantity(medicine.balance, medicine.unit)}
                </p>
            </div>
        </Card>
    );
}
export function LocalDateTimeField({
    name,
    label,
    value,
    onChange,
    error,
}: {
    name: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
}) {
    const [day = '', time = ''] = value.split('T');
    return (
        <fieldset className="space-y-2">
            <legend className="text-sm font-medium">
                {label} <span className="text-status-critical">*</span>
            </legend>
            <div className="grid gap-3 sm:grid-cols-2">
                <DatePicker
                    id={`controlled-${name}`}
                    label={`${label} date`}
                    value={day}
                    onChange={(date) => onChange(`${date}T${time}`)}
                    invalid={!!error}
                />
                <TimePicker
                    id={`controlled-${name}-time`}
                    label={`${label} time`}
                    value={time}
                    onChange={(clock) => onChange(`${day}T${clock}`)}
                    invalid={!!error}
                />
            </div>
            <p className="text-caption">
                {day ? formatDateOnly(day) : 'Choose a date'}
                {time ? ` · ${time}` : ''} · {WORKER_TIMEZONE}
            </p>
            {error ? (
                <p role="alert" className="text-caption text-status-critical">
                    {error}
                </p>
            ) : null}
        </fieldset>
    );
}
export function RecordList<T>({
    rows,
    identity,
    columns,
    actionsFor,
    rowKey,
    onOpen,
    emptyTitle = 'Nothing here yet',
    emptyDescription,
}: {
    rows: T[];
    identity: (row: T) => EntityTableIdentity;
    columns: EntityTableColumn<T>[];
    actionsFor: (row: T) => MenuItem[];
    rowKey: (row: T) => number | string;
    onOpen: (row: T) => void;
    emptyTitle?: string;
    emptyDescription?: string;
}) {
    const context = useEntityContextMenu<T>();
    if (!rows.length)
        return (
            <EmptyState
                icon={ShieldCheck}
                title={emptyTitle}
                description={emptyDescription}
            />
        );
    return (
        <>
            <div className="hidden md:block">
                <EntityTable
                    rows={rows}
                    rowKey={rowKey}
                    identity={identity}
                    columns={columns}
                    actionsFor={actionsFor}
                    onOpen={onOpen}
                    onRowContextMenu={context.open}
                    minWidth={760}
                    rowHeight="content"
                />
            </div>
            <ul className="grid gap-3 md:hidden">
                {rows.map((row) => {
                    const record = identity(row);
                    const Icon = record.icon ?? ShieldCheck;
                    return (
                        <li
                            key={rowKey(row)}
                            onContextMenu={(event) => context.open(event, row)}
                        >
                            <Card className="gap-3 p-4">
                                <div className="flex items-start gap-3">
                                    <Icon className="mt-1 size-5 text-primary" />
                                    <div className="min-w-0 flex-1">
                                        <Button
                                            variant="link"
                                            className="frontline-tap frontline-focus h-auto justify-start p-0 text-left whitespace-normal"
                                            onClick={() => onOpen(row)}
                                        >
                                            {record.name}
                                        </Button>
                                        <p className="text-caption">
                                            {record.subline}
                                        </p>
                                    </div>
                                    <EntityKebab
                                        frontline
                                        actions={actionsFor(row)}
                                        label={`Actions for ${record.name}`}
                                    />
                                </div>
                                <dl className="grid gap-2">
                                    {columns.map((column) => (
                                        <div
                                            key={column.key}
                                            className="flex flex-wrap justify-between gap-2 text-sm"
                                        >
                                            <dt className="text-muted-foreground">
                                                {column.label}
                                            </dt>
                                            <dd className="min-w-0">
                                                {column.cell(row)}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </Card>
                        </li>
                    );
                })}
            </ul>
            {context.ctx ? (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    title={identity(context.ctx.record).name}
                    icon={ShieldCheck}
                    items={actionsFor(context.ctx.record)}
                    onClose={context.close}
                />
            ) : null}
        </>
    );
}
export function StateBadge({ status }: { status: string }) {
    const known: Record<string, [string, StatusVariant]> = {
        open: ['Open', 'warning'],
        under_review: ['With a manager', 'info'],
        resolved: ['Resolved', 'neutral'],
        closed: ['Closed', 'neutral'],
        investigating: ['Investigating', 'warning'],
        awaiting_close: ['Waiting for a manager', 'info'],
        reported: ['Investigating', 'warning'],
        pending: ['Waiting', 'warning'],
        waiting: ['Waiting for a manager', 'warning'],
        approved: ['Approved', 'info'],
        declined: ['Declined', 'neutral'],
        cancelled: ['Cancelled', 'neutral'],
        on_my_way: ['On my way', 'info'],
        cant_come: ['Can’t come now', 'neutral'],
        signed_off: ['Signed off', 'success'],
    };
    const [label, tone] = known[status] ?? [
        status.replace(/_/g, ' '),
        'neutral',
    ];
    return <StatusBadge variant={tone}>{label}</StatusBadge>;
}
export function quantity(value: number | null, unit: string): string {
    return value === null
        ? 'Not configured'
        : `${new Intl.NumberFormat('en-NZ', { maximumFractionDigits: 2 }).format(value)} ${unit || 'units'}`;
}
export function medicineFor(
    payload: ControlledProductPayload,
    id: number,
): ControlledMedicine | undefined {
    return payload.medicines.find((medicine) => medicine.id === id);
}
export function medicineLabel(
    payload: ControlledProductPayload,
    id: number,
): string {
    const medicine = medicineFor(payload, id);
    return medicine
        ? `${medicine.name} · ${medicine.client_name}`
        : 'Medicine record unavailable';
}
export function focusErrors(errors: Record<string, string>): void {
    const first = Object.keys(errors)[0];
    if (first)
        requestAnimationFrame(() => {
            const element = document.getElementById(`controlled-${first}`);
            element?.focus();
            element?.scrollIntoView({ block: 'center' });
        });
}
export const dateTime = (value: string | null | undefined): string =>
    formatDateTime(value);
