export type SafetySourceAccess =
    | 'absent'
    | 'readable'
    | 'restricted'
    | 'unavailable';

export type SafetySourceReferences = {
    incident_access?: SafetySourceAccess | null;
    health_safety_access?: SafetySourceAccess | null;
    incident_reference: string | null;
    health_safety_reference: string | null;
};

export function safetySourceLabels(
    journey: SafetySourceReferences,
    includeAbsent = false,
): string[] {
    const label = (
        name: string,
        access: SafetySourceAccess | null | undefined,
        reference: string | null,
    ) => {
        if (access === 'restricted') return `${name} access restricted`;
        if (access === 'unavailable') return `${name} details unavailable`;
        if (access !== 'absent' && reference) return reference;
        return includeAbsent ? `${name} not started` : null;
    };
    return [
        label('Incident', journey.incident_access, journey.incident_reference),
        label(
            'H&S',
            journey.health_safety_access,
            journey.health_safety_reference,
        ),
    ].filter((value): value is string => value !== null);
}
