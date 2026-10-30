import {
    TierTwoTabs,
    type GroupedProfileNavTab,
} from '@/components/page/grouped-profile-nav';
import { Button } from '@/components/ui/button';

// Modal and Notice moved to components/settings (eMAR P11 Q1) so every
// Settings page shares them.
export function Sections({
    tabs,
    value,
    onChange,
}: {
    tabs: GroupedProfileNavTab[];
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <TierTwoTabs
            tabs={tabs}
            activeTab={value}
            onTab={onChange}
            testIdPrefix="fleet-settings"
            ariaLabel="Settings sections"
            renderLink={(tab, className, inner, accessibility) => (
                <Button
                    variant="ghost"
                    key={tab.key}
                    className={className}
                    {...accessibility}
                    onClick={() => onChange(tab.key)}
                >
                    {inner}
                </Button>
            )}
        />
    );
}
export class SettingsError extends Error {
    constructor(
        message: string,
        public status: number,
        public latest?: unknown,
        public errors?: Record<string, string[]>,
    ) {
        super(message);
    }
}
export async function api<T>(
    path: string,
    method = 'GET',
    body?: unknown,
    signal?: AbortSignal,
): Promise<T> {
    const xsrf = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const csrf = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;
    const response = await fetch('/fleet-assets/settings/' + path, {
        method,
        signal,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
            ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
        },
        ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
    });
    const data = await response.json().catch(() => ({
        message:
            'The server could not complete this request. Your draft is retained.',
    }));
    if (!response.ok)
        throw new SettingsError(
            data.message ?? 'Could not save. Please retry.',
            response.status,
            data.latest,
            data.errors,
        );
    return data;
}
