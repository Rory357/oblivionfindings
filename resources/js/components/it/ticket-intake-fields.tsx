import { Field, InfoCard, SelectInput } from '@/components/hr/wizard';
import { MapPin } from 'lucide-react';

export type TicketImpact = 'individual' | 'team' | 'site' | 'organization';
export type TicketUrgency = 'low' | 'normal' | 'high' | 'critical';
export type TicketPriority = 'low' | 'normal' | 'high' | 'urgent';

/** Values are supplied by the canonical server policy, never recalculated here. */
export interface TicketIntakePolicy {
    matrix_version: number;
    priority_matrix: Record<
        TicketImpact,
        Record<TicketUrgency, TicketPriority>
    >;
}

export const TICKET_IMPACT_OPTIONS: { value: TicketImpact; label: string }[] = [
    { value: 'individual', label: 'One person' },
    { value: 'team', label: 'Several people or a team' },
    { value: 'site', label: 'A whole Site' },
    { value: 'organization', label: 'Several Sites or the whole organisation' },
];

export const TICKET_URGENCY_OPTIONS: { value: TicketUrgency; label: string }[] =
    [
        { value: 'low', label: 'When time allows' },
        { value: 'normal', label: 'Can keep working with a workaround' },
        { value: 'high', label: 'Work is blocked' },
        { value: 'critical', label: 'Stops essential support right now' },
    ];

export function TicketSiteField({
    value,
    onChange,
    sites,
    error,
}: {
    value: string;
    onChange: (value: string) => void;
    sites: { id: number; name: string }[];
    error?: string;
}) {
    return (
        <>
            <Field label="Affected Site" required error={error}>
                <SelectInput
                    value={value}
                    onChange={onChange}
                    ariaLabel="Affected Site"
                    placeholder="Choose the affected Site"
                    options={[
                        {
                            value: 'unassigned',
                            label: 'Choose the affected Site',
                        },
                        ...sites.map((site) => ({
                            value: String(site.id),
                            label: site.name,
                        })),
                    ]}
                />
            </Field>
            {sites.length === 0 && (
                <InfoCard icon={MapPin} tone="warn">
                    No approved Site is available for this request. Ask your
                    manager to review your Site access.
                </InfoCard>
            )}
        </>
    );
}

export function TicketImpactUrgencyFields({
    impact,
    urgency,
    onImpactChange,
    onUrgencyChange,
    errors,
}: {
    impact: TicketImpact;
    urgency: TicketUrgency;
    onImpactChange: (value: TicketImpact) => void;
    onUrgencyChange: (value: TicketUrgency) => void;
    errors: Record<string, string>;
}) {
    return (
        <>
            <Field label="Who is affected?" required error={errors.impact}>
                <SelectInput
                    value={impact}
                    onChange={(value) => onImpactChange(value as TicketImpact)}
                    ariaLabel="Who is affected?"
                    placeholder="Choose who is affected"
                    options={TICKET_IMPACT_OPTIONS}
                />
            </Field>
            <Field label="How urgent is it?" required error={errors.urgency}>
                <SelectInput
                    value={urgency}
                    onChange={(value) =>
                        onUrgencyChange(value as TicketUrgency)
                    }
                    ariaLabel="How urgent is it?"
                    placeholder="Choose how urgent it is"
                    options={TICKET_URGENCY_OPTIONS}
                />
            </Field>
        </>
    );
}
