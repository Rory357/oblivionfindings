import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useId, useState } from 'react';

const choices = [
    ['0', 'When the event starts'],
    ['60', '1 hour before'],
    ['1440', '1 day before'],
    ['2880', '2 days before'],
    ['10080', '1 week before'],
];

export function bookingCutoffLabel(value: string) {
    return (
        choices.find(([key]) => key === (value || '0'))?.[1] ??
        `${value} minutes before each date`
    );
}

export function EventBookingCutoff({
    value,
    onChange,
    disabled = false,
}: {
    value: string;
    onChange: (value: string) => void;
    disabled?: boolean;
}) {
    const id = useId();
    const [custom, setCustom] = useState(
        !choices.some(([key]) => key === (value || '0')),
    );
    const selected = custom ? 'custom' : value || '0';
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>Registration closes</Label>
            <Select
                value={selected}
                onValueChange={(next) => {
                    setCustom(next === 'custom');
                    onChange(next === 'custom' ? '120' : next);
                }}
                disabled={disabled}
            >
                <SelectTrigger id={id}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {choices.map(([key, label]) => (
                        <SelectItem key={key} value={key}>
                            {label}
                        </SelectItem>
                    ))}
                    <SelectItem value="custom">
                        Choose another lead time
                    </SelectItem>
                </SelectContent>
            </Select>
            {selected === 'custom' && (
                <Label className="grid gap-2">
                    Minutes before the start
                    <Input
                        type="number"
                        min={0}
                        max={43200}
                        step={1}
                        value={value}
                        disabled={disabled}
                        onChange={(e) => onChange(e.target.value)}
                    />
                </Label>
            )}
            <p className="text-xs leading-relaxed text-muted-foreground">
                The same lead time applies to each date. People with a place can
                still cancel after registration closes.
            </p>
        </div>
    );
}
