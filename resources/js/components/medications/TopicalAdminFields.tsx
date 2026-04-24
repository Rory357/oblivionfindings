import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Props {
    form: Record<string, unknown>;
    errors: Record<string, string>;
    onChange: (field: string, value: unknown) => void;
}

export default function TopicalAdminFields({ form, errors, onChange }: Props) {
    return (
        <div className="space-y-3 rounded-md border border-status-success/30 bg-status-success-bg p-3">
            <div className="text-sm font-medium text-status-success">Topical Application</div>

            <div>
                <Label htmlFor="topical_area">Where was the medication applied?</Label>
                <Input
                    id="topical_area"
                    value={(form.topical_area as string) ?? ''}
                    onChange={(e) => onChange('topical_area', e.target.value || null)}
                    placeholder="e.g., Left forearm, Lower back"
                />
                {errors.topical_area && (
                    <p className="mt-1 text-xs text-status-critical">{errors.topical_area}</p>
                )}
            </div>

            <div>
                <Label htmlFor="topical_skin_condition">Describe skin condition at application site</Label>
                <Input
                    id="topical_skin_condition"
                    value={(form.topical_skin_condition as string) ?? ''}
                    onChange={(e) => onChange('topical_skin_condition', e.target.value || null)}
                    placeholder="e.g., Intact, Dry, Red, Broken"
                />
                {errors.topical_skin_condition && (
                    <p className="mt-1 text-xs text-status-critical">{errors.topical_skin_condition}</p>
                )}
            </div>
        </div>
    );
}
