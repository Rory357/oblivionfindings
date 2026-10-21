import { Field, SelectInput } from '@/components/wizard/primitives';

export type IntakePathway = {
    id: string;
    label: string;
    service_ids: string[];
    personal_income: boolean;
};
export const fundingSituations = [
    { value: 'not_explored', label: 'Not explored yet' },
    { value: 'need_help', label: 'Need help getting funding' },
    { value: 'underway', label: 'Application or assessment underway' },
    { value: 'arranged', label: 'Funding reportedly arranged' },
    { value: 'private', label: 'Considering private payment' },
    { value: 'unsure', label: 'Not sure yet' },
];
export const applicablePathways = (options: IntakePathway[], service: string) =>
    options.filter(
        (option) =>
            service === 'unsure' ||
            !option.service_ids.length ||
            option.service_ids.includes(service),
    );
export const reportedPathway = (
    options: IntakePathway[],
    service: string,
    id: string,
    minimal = false,
) =>
    minimal
        ? undefined
        : applicablePathways(options, service).find(
              (option) => option.id === id,
          );

export function IntakePathwaySelect({
    options,
    service,
    value,
    onChange,
    disabled = false,
    label = 'Funding pathway, if known',
}: {
    options: IntakePathway[];
    service: string;
    value: string;
    onChange: (value: string) => void;
    disabled?: boolean;
    label?: string;
}) {
    const choices = applicablePathways(options, service);
    return (
        <Field label={label}>
            <SelectInput
                disabled={disabled}
                ariaLabel={label}
                placeholder="Not sure yet"
                value={
                    choices.some((option) => option.id === value)
                        ? value
                        : '__unknown'
                }
                options={[
                    {
                        value: '__unknown',
                        label: 'Not sure yet / not applicable',
                    },
                    ...choices.map((option) => ({
                        value: option.id,
                        label:
                            option.label +
                            (option.personal_income
                                ? ' · Personal income support'
                                : ''),
                    })),
                ]}
                onChange={(value) =>
                    onChange(value === '__unknown' ? '' : value)
                }
            />
            <p className="text-subtle mt-2 text-muted-foreground">
                Optional. This helps direct the enquiry; it does not confirm
                eligibility or approval. Personal income support is separate
                from service funding.
            </p>
        </Field>
    );
}
