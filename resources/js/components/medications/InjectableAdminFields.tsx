import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

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

export default function InjectableAdminFields({ form, errors, onChange }: Props) {
    return (
        <div className="space-y-3 rounded-md border border-status-warning/30 bg-status-warning-bg p-3">
            <div className="text-sm font-medium text-status-warning">Injectable Administration</div>

            <div>
                <Label htmlFor="injection_site">Injection Site</Label>
                <Select
                    value={(form.injection_site as string) ?? ''}
                    onValueChange={(value) => onChange('injection_site', value || null)}
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
                    <p className="mt-1 text-xs text-status-critical">{errors.injection_site}</p>
                )}
            </div>

            <p className="text-xs text-status-warning">
                For subcutaneous/intramuscular injections. Rotate sites.
            </p>
        </div>
    );
}
