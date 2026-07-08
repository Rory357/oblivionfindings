import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Props {
    form: Record<string, unknown>;
    errors: Record<string, string>;
    onChange: (field: string, value: unknown) => void;
}

export default function InhalerAdminFields({ form, errors, onChange }: Props) {
    return (
        <div className="space-y-3 rounded-md border border-primary bg-primary/10 p-3">
            <div className="text-sm font-medium text-primary">Inhaler Administration</div>

            <div className="flex items-center space-x-2">
                <Checkbox
                    id="inhaler_technique_observed"
                    checked={!!form.inhaler_technique_observed}
                    onCheckedChange={(checked) => onChange('inhaler_technique_observed', checked ? true : false)}
                />
                <Label htmlFor="inhaler_technique_observed" className="cursor-pointer">
                    Correct technique observed
                </Label>
            </div>
            {errors.inhaler_technique_observed && (
                <p className="text-xs text-status-critical">{errors.inhaler_technique_observed}</p>
            )}

            <div className="flex items-center space-x-2">
                <Checkbox
                    id="spacer_used"
                    checked={!!form.spacer_used}
                    onCheckedChange={(checked) => onChange('spacer_used', checked ? true : false)}
                />
                <Label htmlFor="spacer_used" className="cursor-pointer">
                    Spacer device used
                </Label>
            </div>
            {errors.spacer_used && (
                <p className="text-xs text-status-critical">{errors.spacer_used}</p>
            )}

            <div>
                <Label htmlFor="peak_flow_before">Peak flow before (L/min)</Label>
                <Input
                    id="peak_flow_before"
                    type="number"
                    min="0"
                    value={(form.peak_flow_before as string | number) ?? ''}
                    onChange={(e) => onChange('peak_flow_before', e.target.value || null)}
                    placeholder="Optional"
                />
                {errors.peak_flow_before && (
                    <p className="mt-1 text-xs text-status-critical">{errors.peak_flow_before}</p>
                )}
            </div>

            <div>
                <Label htmlFor="peak_flow_after">Peak flow after (L/min)</Label>
                <Input
                    id="peak_flow_after"
                    type="number"
                    min="0"
                    value={(form.peak_flow_after as string | number) ?? ''}
                    onChange={(e) => onChange('peak_flow_after', e.target.value || null)}
                    placeholder="Optional"
                />
                {errors.peak_flow_after && (
                    <p className="mt-1 text-xs text-status-critical">{errors.peak_flow_after}</p>
                )}
            </div>
        </div>
    );
}
