import type { MapSnapshot, MapValues } from './_types';

export type MapErrors = Partial<Record<keyof MapValues, string>>;

export function mapConfigurationErrors(
    values: MapValues,
    credentials: MapSnapshot['credentials'],
): MapErrors {
    if (!values.google) return {};
    const errors: MapErrors = {};
    if (!values.project.trim())
        errors.project = 'Enter the Google Cloud project reference.';
    else if (
        !/^[a-zA-Z0-9 ._-]+$/.test(values.project) ||
        values.project.length > 100
    )
        errors.project =
            'Use the project ID or reference, not an API key or URL.';
    if (!credentials.browser)
        errors.google =
            'Add the restricted browser key in deployment, then reload the configuration.';
    if (!values.display)
        errors.display =
            'Google map display is required for Google results shown on maps.';
    if (
        (values.places || values.geocoding || values.routes) &&
        !credentials.server
    )
        errors.places =
            'Add the separate server key in deployment, or turn off address search, reverse geocoding and routing.';
    if (!values.restrictions_reviewed)
        errors.restrictions_reviewed =
            'Confirm the key restrictions for the selected APIs.';
    if (!values.terms_reviewed)
        errors.terms_reviewed =
            'Confirm the provider terms, privacy, billing and quota review.';
    return errors;
}

export function mapErrorStep(errors: MapErrors): number {
    if (errors.project || errors.google) return 0;
    if (errors.display || errors.places || errors.geocoding || errors.routes)
        return 1;
    return 3;
}

export function googleConsoleUrl(path: string, project: string): string {
    const url = new URL(path, 'https://console.cloud.google.com');
    if (project.trim()) url.searchParams.set('project', project.trim());
    return url.toString();
}
