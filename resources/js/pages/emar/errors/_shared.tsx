import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import { Check, ChevronsUpDown } from 'lucide-react';
import { useState } from 'react';

export type Person = { id: number; name: string };
export type Entry = {
    id: number;
    kind: string;
    text: string | null;
    data: Record<string, unknown>;
    by: string;
    at: string;
};
export type ErrorAction = {
    id: number;
    description: string;
    owner: Person;
    due_at: string;
    completed_at: string | null;
    completion_note: string | null;
};
export type ErrorRecord = {
    id: number;
    ref: string;
    error_type: string;
    severity: string;
    stage: string;
    status: string;
    reached_client: string | null;
    harm_level: string | null;
    summary: string;
    description: string | null;
    immediate_action: string | null;
    contributing_factors: string | null;
    review_notes: string | null;
    outcome: string | null;
    preventive_actions: string | null;
    close_note: string | null;
    occurred_at: string | null;
    reported_at: string | null;
    closed_at: string | null;
    triage_due_at: string | null;
    investigation_due_at: string | null;
    client_id: number;
    client: { id: number; first_name: string; last_name: string };
    site_name: string;
    medication: { id: number; name: string } | null;
    owner: Person | null;
    reported_by_user: Person;
    incident: {
        id: number;
        ref: string;
        status: string;
        ready_to_close: boolean;
        can_close: boolean;
    } | null;
    mar_url: string | null;
    entries: Entry[];
    actions: ErrorAction[];
    close_blockers: string[];
    can_close: boolean;
    can_reopen: boolean;
    attachments: {
        id: number;
        file_name: string;
        mime_type: string;
        file_size: number;
        download_url: string;
        uploaded_at: string | null;
    }[];
};
export const TYPES = [
    ['wrong_medication', 'Wrong medicine'],
    ['wrong_client', 'Wrong person'],
    ['wrong_dose', 'Wrong amount'],
    ['wrong_time', 'Wrong time'],
    ['wrong_route', 'Wrong route'],
    ['omission', 'Dose missed'],
    ['unauthorised', 'Not authorised'],
    ['documentation', 'Recording error'],
    ['other', 'Other'],
] as const;
export const REACH = [
    ['no', 'Didn’t reach the person — a near miss'],
    ['yes', 'Reached the person'],
    ['unknown', 'Not sure yet'],
] as const;
export const HARMS = [
    ['none', 'No harm'],
    ['minor', 'Minor or temporary harm'],
    ['moderate', 'Moderate harm'],
    ['severe', 'Severe or permanent harm'],
    ['death', 'Death'],
    ['unknown', 'Harm not known yet'],
] as const;
export const labelFor = (
    options: readonly (readonly [string, string])[],
    value: string | null,
) => options.find(([key]) => key === value)?.[1] ?? 'Not recorded';
export const personName = (e: ErrorRecord) =>
    `${e.client.first_name} ${e.client.last_name}`;
export const STAGES: Record<string, string> = {
    triage: 'To triage',
    investigating: 'Investigating',
    actions: 'Actions',
    closed: 'Closed',
};
export function Stage({ value }: { value: string }) {
    return (
        <StatusBadge
            variant={
                value === 'triage'
                    ? 'warning'
                    : value === 'closed'
                      ? 'neutral'
                      : 'info'
            }
        >
            {STAGES[value] ?? value}
        </StatusBadge>
    );
}
export function Picker({
    label,
    value,
    options,
    onChange,
    disabled,
}: {
    label: string;
    value: number | null;
    options: Person[];
    onChange: (id: number) => void;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    aria-label={label}
                    disabled={disabled}
                    className="frontline-tap w-full justify-between whitespace-normal text-left"
                >
                    {options.find((o) => o.id === value)?.name ??
                        `Choose ${label.toLowerCase()}`}
                    <ChevronsUpDown className="size-4 shrink-0" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-[min(90vw,380px)] p-0">
                <Command>
                    <CommandInput
                        placeholder={`Search ${label.toLowerCase()}`}
                    />
                    <CommandList>
                        <CommandEmpty>No matches</CommandEmpty>
                        {options.map((option) => (
                            <CommandItem
                                key={option.id}
                                value={`${option.name} ${option.id}`}
                                onSelect={() => {
                                    onChange(option.id);
                                    setOpen(false);
                                }}
                            >
                                <Check
                                    className={
                                        value === option.id
                                            ? 'size-4'
                                            : 'size-4 opacity-0'
                                    }
                                />
                                {option.name}
                            </CommandItem>
                        ))}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
export function Choices({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string;
    options: readonly (readonly [string, string])[];
    onChange: (v: string) => void;
}) {
    return (
        <fieldset className="flex flex-col gap-2">
            <legend className="mb-2 text-sm font-medium">{label}</legend>
            <div className="grid gap-2 sm:grid-cols-2">
                {options.map(([key, text]) => (
                    <Button
                        type="button"
                        key={key}
                        className="frontline-tap h-auto justify-start whitespace-normal text-left"
                        variant={value === key ? 'default' : 'outline'}
                        aria-pressed={value === key}
                        onClick={() => onChange(key)}
                    >
                        {text}
                    </Button>
                ))}
            </div>
        </fieldset>
    );
}
export function nzLocal(iso?: string | null) {
    if (!iso) return '';
    const parts = new Intl.DateTimeFormat('en-NZ', {
        timeZone: 'Pacific/Auckland',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(new Date(iso));
    const get = (type: string) =>
        parts.find((p) => p.type === type)?.value ?? '';
    return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
}
export function reportToken() {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map((n) => n.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
