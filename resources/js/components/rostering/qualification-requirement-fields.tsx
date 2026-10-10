import { RecordPicker } from '@/components/people-locations/record-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type QualificationOption = {
    id: number;
    code: string;
    name: string;
    check_type: string;
};
export type QualificationMapping = {
    status: 'configured' | 'unmapped' | 'missing' | 'inactive' | 'unsupported';
    requirement_id: number | null;
    label: string | null;
    code: string | null;
    check_type: string | null;
};
export type HouseQualificationValues = {
    hr_compliance_requirement_id: number | null;
    applicability_mode: 'all_workers' | 'minimum_staff' | null;
    minimum_qualified_staff: number | string | null;
};
export type HouseQualificationOptions = {
    mapping_options: QualificationOption[];
    house_qualification_approach:
        | 'per_requirement'
        | 'all_workers'
        | 'minimum_staff';
    new_requirement_defaults: Pick<
        HouseQualificationValues,
        'applicability_mode'
    > & { minimum_qualified_staff: null };
};
export function newHouseQualification(
    options?: HouseQualificationOptions,
): HouseQualificationValues {
    return {
        hr_compliance_requirement_id: null,
        applicability_mode:
            options?.new_requirement_defaults.applicability_mode ?? null,
        minimum_qualified_staff: null,
    };
}
/** Copy exact choices, including legacy blanks; defaults apply only to new rows. */
export function copyHouseQualification(
    row: Partial<HouseQualificationValues>,
): HouseQualificationValues {
    return {
        hr_compliance_requirement_id: row.hr_compliance_requirement_id ?? null,
        applicability_mode: row.applicability_mode ?? null,
        minimum_qualified_staff: row.minimum_qualified_staff ?? null,
    };
}
export function houseQualificationErrors(
    row: HouseQualificationValues,
): Record<string, string> {
    if (!row.applicability_mode)
        return { applicability_mode: 'Choose who needs this qualification.' };
    if (
        row.applicability_mode === 'minimum_staff' &&
        (!/^\d+$/.test(String(row.minimum_qualified_staff ?? '')) ||
            Number(row.minimum_qualified_staff) < 1 ||
            Number(row.minimum_qualified_staff) > 4294967295)
    )
        return {
            minimum_qualified_staff:
                'Enter a positive whole number of qualified workers.',
        };
    return {};
}
export function houseQualificationSummary(
    row: Partial<HouseQualificationValues>,
): string {
    if (row.applicability_mode === 'all_workers') return 'Every worker';
    if (
        row.applicability_mode === 'minimum_staff' &&
        Object.keys(houseQualificationErrors(copyHouseQualification(row)))
            .length === 0
    )
        return `At least ${row.minimum_qualified_staff} qualified ${Number(row.minimum_qualified_staff) === 1 ? 'worker' : 'workers'} throughout the duty`;
    return 'Who needs this qualification has not been set';
}
export function qualificationMappingLabel(
    mapping?: QualificationMapping | null,
): string {
    if (!mapping || mapping.status === 'unmapped')
        return 'Not linked to a recognised qualification';
    if (mapping.status !== 'configured')
        return `${mapping.label ?? 'Linked qualification'} — unavailable for checks`;
    return mapping.label ?? 'Recognised qualification linked';
}
export function QualificationMappingField({
    value,
    options,
    onChange,
    mapping,
    error,
    label = 'Recognised qualification',
    disabled = false,
}: {
    value: number | null;
    options: QualificationOption[];
    onChange: (id: number | null) => void;
    mapping?: QualificationMapping | null;
    error?: string;
    label?: string;
    disabled?: boolean;
}) {
    const missing =
        value !== null && !options.some((option) => option.id === value);
    return (
        <div className="min-w-0 space-y-2 [&_button[role=combobox]]:min-h-11">
            <p className="text-sm font-medium">{label}</p>
            <RecordPicker
                label={label}
                value={value === null ? 'unmapped' : String(value)}
                disabled={disabled}
                options={[
                    {
                        value: 'unmapped',
                        label: 'Not linked yet',
                        description: 'Requires review when this is mandatory.',
                    },
                    ...(missing
                        ? [
                              {
                                  value: String(value),
                                  label:
                                      mapping?.label ??
                                      'Previously linked qualification',
                                  description:
                                      'Unavailable for checks. Choose a current qualification.',
                              },
                          ]
                        : []),
                    ...options.map((option) => ({
                        value: String(option.id),
                        label: option.name,
                        description: option.code,
                    })),
                ]}
                onChange={(next) =>
                    onChange(next === 'unmapped' ? null : Number(next))
                }
            />
            <p className="text-xs text-muted-foreground">
                Link the exact HR qualification used to check staff records. A
                matching name alone does not establish a link.
            </p>
            {missing ? (
                <p role="status" className="text-sm text-status-warning">
                    The existing link is unavailable. It remains recorded until
                    you choose a replacement.
                </p>
            ) : null}
            {error ? (
                <p role="alert" className="text-sm text-status-critical">
                    {error}
                </p>
            ) : null}
        </div>
    );
}
export function HouseQualificationFields({
    id,
    value,
    options,
    onChange,
    errors = {},
    mapping,
    disabled = false,
}: {
    id: string;
    value: HouseQualificationValues;
    options: QualificationOption[];
    onChange: (patch: Partial<HouseQualificationValues>) => void;
    errors?: Record<string, string | undefined>;
    mapping?: QualificationMapping | null;
    disabled?: boolean;
}) {
    return (
        <div className="min-w-0 space-y-4">
            <QualificationMappingField
                value={value.hr_compliance_requirement_id}
                options={options}
                mapping={mapping}
                onChange={(next) =>
                    onChange({ hr_compliance_requirement_id: next })
                }
                error={errors.hr_compliance_requirement_id}
                disabled={disabled}
            />
            <div className="space-y-2">
                <Label htmlFor={`${id}-applicability`}>
                    Who needs this qualification?
                </Label>
                <Select
                    value={value.applicability_mode ?? ''}
                    disabled={disabled}
                    onValueChange={(next: 'all_workers' | 'minimum_staff') =>
                        onChange({
                            applicability_mode: next,
                            minimum_qualified_staff:
                                next === 'all_workers'
                                    ? null
                                    : value.minimum_qualified_staff,
                        })
                    }
                >
                    <SelectTrigger
                        id={`${id}-applicability`}
                        className="min-h-11"
                        aria-invalid={!!errors.applicability_mode}
                    >
                        <SelectValue placeholder="Choose for this requirement" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all_workers">
                            Every worker
                        </SelectItem>
                        <SelectItem value="minimum_staff">
                            Minimum number of qualified workers
                        </SelectItem>
                    </SelectContent>
                </Select>
                {errors.applicability_mode ? (
                    <p role="alert" className="text-sm text-status-critical">
                        {errors.applicability_mode}
                    </p>
                ) : null}
            </div>
            {value.applicability_mode === 'minimum_staff' ? (
                <div className="space-y-2">
                    <Label htmlFor={`${id}-minimum`}>
                        Minimum qualified workers
                    </Label>
                    <Input
                        id={`${id}-minimum`}
                        type="number"
                        min={1}
                        step={1}
                        inputMode="numeric"
                        disabled={disabled}
                        className="min-h-11"
                        value={value.minimum_qualified_staff ?? ''}
                        aria-invalid={!!errors.minimum_qualified_staff}
                        aria-describedby={`${id}-minimum-help`}
                        onChange={(event) =>
                            onChange({
                                minimum_qualified_staff:
                                    event.target.value === ''
                                        ? null
                                        : event.target.value,
                            })
                        }
                    />
                    <p
                        id={`${id}-minimum-help`}
                        className="text-xs text-muted-foreground"
                    >
                        This many distinct qualified workers must cover the
                        whole duty. You can fill the roster in stages; mandatory
                        gaps must be resolved before publication.
                    </p>
                    {errors.minimum_qualified_staff ? (
                        <p
                            role="alert"
                            className="text-sm text-status-critical"
                        >
                            {errors.minimum_qualified_staff}
                        </p>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
