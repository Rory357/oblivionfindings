import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const injectionSites = [
    { value: 'left_arm', label: 'Left arm' },
    { value: 'right_arm', label: 'Right arm' },
    { value: 'left_thigh', label: 'Left thigh' },
    { value: 'right_thigh', label: 'Right thigh' },
    { value: 'abdomen_left', label: 'Abdomen left' },
    { value: 'abdomen_right', label: 'Abdomen right' },
];

interface Props {
    form: Record<string, unknown>;
    errors: Record<string, string>;
    onChange: (field: string, value: unknown) => void;
}

export default function InsulinAdminFields({ form, errors, onChange }: Props) {
    return (
        <div className="space-y-3 rounded-md border border-status-info/30 bg-status-info-bg p-3">
            <div className="text-sm font-medium text-status-info">
                Insulin Administration
            </div>

            <div>
                <Label htmlFor="blood_glucose_level">
                    Pre-administration BGL (mmol/L)
                </Label>
                <Input
                    id="blood_glucose_level"
                    type="number"
                    step="0.1"
                    min="0"
                    value={(form.blood_glucose_level as string | number) ?? ''}
                    onChange={(e) =>
                        onChange('blood_glucose_level', e.target.value || null)
                    }
                    placeholder="e.g., 8.5"
                />
                {errors.blood_glucose_level && (
                    <p className="mt-1 text-xs text-status-critical">
                        {errors.blood_glucose_level}
                    </p>
                )}
            </div>

            <div>
                <Label htmlFor="insulin_units_given">Insulin Units Given</Label>
                <Input
                    id="insulin_units_given"
                    type="number"
                    step="0.5"
                    min="0"
                    value={(form.insulin_units_given as string | number) ?? ''}
                    onChange={(e) =>
                        onChange('insulin_units_given', e.target.value || null)
                    }
                    placeholder="e.g., 10"
                />
                {errors.insulin_units_given && (
                    <p className="mt-1 text-xs text-status-critical">
                        {errors.insulin_units_given}
                    </p>
                )}
            </div>

            <div>
                <Label htmlFor="injection_site">Injection Site</Label>
                <Select
                    value={(form.injection_site as string) ?? ''}
                    onValueChange={(value) =>
                        onChange('injection_site', value || null)
                    }
                >
                    <SelectTrigger>
                        <SelectValue placeholder="Select injection site..." />
                    </SelectTrigger>
                    <SelectContent>
                        {injectionSites.map((site) => (
                            <SelectItem key={site.value} value={site.value}>
                                {site.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {errors.injection_site && (
                    <p className="mt-1 text-xs text-status-critical">
                        {errors.injection_site}
                    </p>
                )}
            </div>

            <p className="text-xs text-status-info">
                Rotate injection sites to prevent lipodystrophy.
            </p>
        </div>
    );
}
