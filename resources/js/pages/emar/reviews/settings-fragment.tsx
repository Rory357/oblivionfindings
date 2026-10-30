import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Repeat } from 'lucide-react';
import { cadenceLabel } from './model';

/** P11 owns persistence, permission and reviewed-state changes for this setting. */
export function ReviewCadenceSettingsFragment({
    months,
    reviewed,
    disabled,
    onChange,
}: {
    months: number;
    reviewed: boolean;
    disabled: boolean;
    onChange: (months: number) => void;
}) {
    return (
        <Card className="gap-4 p-4" data-settings-group="review_cadence">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-start gap-3">
                    <Repeat
                        className="text-primary mt-1 size-5 shrink-0"
                        aria-hidden="true"
                    />
                    <div>
                        <h3 className="text-section-title">
                            Regular medication reviews
                        </h3>
                        <p className="text-subtle mt-1">
                            The organisation default, unless the person has
                            their own interval.
                        </p>
                    </div>
                </div>
                <StatusBadge variant={reviewed ? 'success' : 'warning'}>
                    {reviewed ? 'Reviewed' : 'Not reviewed'}
                </StatusBadge>
            </div>
            <div className="max-w-sm space-y-1.5">
                <Label htmlFor="review-default-months">
                    Default interval in calendar months
                </Label>
                <Select
                    value={String(months)}
                    onValueChange={(value) => onChange(Number(value))}
                    disabled={disabled}
                >
                    <SelectTrigger
                        id="review-default-months"
                        className="w-full"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {Array.from(
                            { length: 12 },
                            (_, index) => index + 1,
                        ).map((value) => (
                            <SelectItem key={value} value={String(value)}>
                                {cadenceLabel(value)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            <p className="text-caption">
                The existing 3-month default starts as Not reviewed. A clinical
                lead reviews the interval through Settings. Saving an outcome
                books the next regular review; changing this setting does not
                erase earlier dates or move an already booked review.
            </p>
        </Card>
    );
}
export default ReviewCadenceSettingsFragment;
