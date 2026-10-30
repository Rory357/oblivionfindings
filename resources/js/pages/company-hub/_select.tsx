import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

/** Shared Hub menus retain real draft values, including an explicit empty choice. */
export function HubSelect({
    id,
    label,
    value,
    onChange,
    options,
    disabled = false,
    placeholder = 'Choose…',
}: {
    id?: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: { value: string; label: string; disabled?: boolean }[];
    disabled?: boolean;
    placeholder?: string;
}) {
    return (
        <Select
            value={`choice:${value}`}
            onValueChange={(next) => onChange(next.slice('choice:'.length))}
            disabled={disabled}
        >
            <SelectTrigger
                id={id}
                aria-label={label}
                className="h-auto min-h-10 bg-card text-left [&>span]:line-clamp-none [&>span]:whitespace-normal"
            >
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent
                className="max-h-[min(22rem,var(--radix-select-content-available-height))] max-w-[min(32rem,var(--radix-select-content-available-width))]"
                collisionPadding={12}
            >
                {options.map((option) => (
                    <SelectItem
                        key={option.value}
                        value={`choice:${option.value}`}
                        disabled={option.disabled}
                        className="min-h-10 py-2 whitespace-normal focus:text-primary"
                    >
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
