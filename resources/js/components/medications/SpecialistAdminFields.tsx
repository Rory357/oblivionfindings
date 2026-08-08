import InhalerAdminFields from './InhalerAdminFields';
import InjectableAdminFields from './InjectableAdminFields';
import InsulinAdminFields from './InsulinAdminFields';
import TopicalAdminFields from './TopicalAdminFields';

interface Medication {
    name: string;
    route?: string;
    form?: string;
}

interface Props {
    medication: Medication | null;
    form: Record<string, unknown>;
    errors: Record<string, string>;
    onChange: (field: string, value: unknown) => void;
}

export default function SpecialistAdminFields({
    medication,
    form,
    errors,
    onChange,
}: Props) {
    if (!medication) return null;

    const route = medication.route?.toLowerCase() ?? '';
    const medForm = medication.form?.toLowerCase() ?? '';
    const name = medication.name?.toLowerCase() ?? '';

    // Insulin detection
    if (
        name.includes('insulin') ||
        name.includes('novorapid') ||
        name.includes('lantus') ||
        name.includes('humalog')
    ) {
        return (
            <InsulinAdminFields
                form={form}
                errors={errors}
                onChange={onChange}
            />
        );
    }

    // Inhaler detection
    if (
        route === 'inhaled' ||
        medForm === 'inhaler' ||
        medForm === 'nebuliser'
    ) {
        return (
            <InhalerAdminFields
                form={form}
                errors={errors}
                onChange={onChange}
            />
        );
    }

    // Injectable detection (non-insulin)
    if (
        route === 'subcutaneous' ||
        route === 'intramuscular' ||
        medForm === 'injection'
    ) {
        return (
            <InjectableAdminFields
                form={form}
                errors={errors}
                onChange={onChange}
            />
        );
    }

    // Topical detection
    if (
        route === 'topical' ||
        route === 'transdermal' ||
        medForm === 'cream' ||
        medForm === 'ointment' ||
        medForm === 'gel' ||
        medForm === 'patch'
    ) {
        return (
            <TopicalAdminFields
                form={form}
                errors={errors}
                onChange={onChange}
            />
        );
    }

    return null;
}
