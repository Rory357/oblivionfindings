import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export function HubBrowseSelect({
    label,
    value,
    onChange,
    options,
    disabled = false,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: Array<{ value: string; label: string }>;
    disabled?: boolean;
}) {
    return (
        <Select value={value} onValueChange={onChange} disabled={disabled}>
            <SelectTrigger aria-label={label} className="hub-browse-select">
                <SelectValue />
            </SelectTrigger>
            <SelectContent className="hub-browse-select-menu">
                {options.map((option) => (
                    <SelectItem
                        key={option.value}
                        value={option.value}
                        className="focus:text-primary"
                    >
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
