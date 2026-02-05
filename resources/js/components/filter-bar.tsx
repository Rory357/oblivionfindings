import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { RotateCcw, Search } from 'lucide-react';
import { ReactNode } from 'react';

export type FilterFieldType = 'search' | 'select' | 'date' | 'date-range' | 'custom';

export interface FilterField {
    type: FilterFieldType;
    key: string;
    label?: string;
    placeholder?: string;
    options?: Array<{ value: string; label: string }>;
    width?: 'auto' | 'sm' | 'md' | 'lg' | 'full';
    presets?: Array<{ value: string; label: string }>;
    component?: ReactNode;
}

interface FilterBarProps {
    fields: FilterField[];
    values: Record<string, any>;
    onChange: (key: string, value: any) => void;
    onReset?: () => void;
    isPending?: boolean;
    className?: string;
    showReset?: boolean;
}

const widthClasses = {
    auto: '',
    sm: 'w-32',
    md: 'w-44',
    lg: 'w-60',
    full: 'w-full',
};

export function FilterBar({
    fields,
    values,
    onChange,
    onReset,
    isPending = false,
    className,
    showReset = true,
}: FilterBarProps) {
    const handleReset = () => {
        if (onReset) {
            onReset();
        }
    };

    const ANY = '__any__';

    return (
        <div
            className={cn(
                'flex flex-wrap items-end gap-3 rounded-xl border bg-card p-4',
                className
            )}
        >
            {fields.map((field) => {
                const value = values[field.key];
                const widthClass = widthClasses[field.width ?? 'auto'];

                switch (field.type) {
                    case 'search':
                        return (
                            <div key={field.key} className={cn('min-w-[200px] flex-1', widthClass)}>
                                {field.label && (
                                    <Label className="text-xs text-muted-foreground">
                                        {field.label}
                                    </Label>
                                )}
                                <div className="relative mt-1">
                                    <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        placeholder={field.placeholder ?? 'Search...'}
                                        value={value || ''}
                                        onChange={(e) => onChange(field.key, e.target.value)}
                                        className="pl-9"
                                        disabled={isPending}
                                    />
                                </div>
                            </div>
                        );

                    case 'select':
                        return (
                            <div key={field.key} className={cn(widthClass)}>
                                {field.label && (
                                    <Label className="text-xs text-muted-foreground">
                                        {field.label}
                                    </Label>
                                )}
                                <Select
                                    value={value ?? ANY}
                                    onValueChange={(v) =>
                                        onChange(field.key, v === ANY ? null : v)
                                    }
                                    disabled={isPending}
                                >
                                    <SelectTrigger className="mt-1">
                                        <SelectValue placeholder={field.placeholder} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>
                                            {field.placeholder ?? 'Any'}
                                        </SelectItem>
                                        {field.options?.map((opt) => (
                                            <SelectItem key={opt.value} value={opt.value}>
                                                {opt.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        );

                    case 'date':
                        return (
                            <div key={field.key} className={cn(widthClass)}>
                                {field.label && (
                                    <Label className="text-xs text-muted-foreground">
                                        {field.label}
                                    </Label>
                                )}
                                <Input
                                    type="date"
                                    value={value || ''}
                                    onChange={(e) =>
                                        onChange(field.key, e.target.value || null)
                                    }
                                    className="mt-1"
                                    disabled={isPending}
                                />
                            </div>
                        );

                    case 'date-range':
                        return (
                            <div key={field.key} className="flex items-end gap-2">
                                <div className={cn(widthClass)}>
                                    {field.label && (
                                        <Label className="text-xs text-muted-foreground">
                                            {field.label} From
                                        </Label>
                                    )}
                                    <Input
                                        type="date"
                                        value={values[`${field.key}_from`] || ''}
                                        onChange={(e) =>
                                            onChange(
                                                `${field.key}_from`,
                                                e.target.value || null
                                            )
                                        }
                                        className="mt-1"
                                        disabled={isPending}
                                    />
                                </div>
                                <div className={cn(widthClass)}>
                                    <Label className="text-xs text-muted-foreground">To</Label>
                                    <Input
                                        type="date"
                                        value={values[`${field.key}_to`] || ''}
                                        onChange={(e) =>
                                            onChange(
                                                `${field.key}_to`,
                                                e.target.value || null
                                            )
                                        }
                                        className="mt-1"
                                        disabled={isPending}
                                    />
                                </div>
                            </div>
                        );

                    case 'custom':
                        return (
                            <div key={field.key} className={cn(widthClass)}>
                                {field.component}
                            </div>
                        );

                    default:
                        return null;
                }
            })}

            {showReset && onReset && (
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={handleReset}
                    disabled={isPending}
                    className="ml-auto"
                >
                    <RotateCcw className="mr-2 h-4 w-4" />
                    Reset
                </Button>
            )}
        </div>
    );
}

export default FilterBar;
