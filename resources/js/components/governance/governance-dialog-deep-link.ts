import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * Deep links for register dialogs that replaced routed Create/Edit pages.
 * `/governance/<register>/create` now redirects to `?create=1` on the index
 * and `/<record>/edit` to `?edit=1` on the show page; the page opens its
 * WizardShell dialog when the flag is present and the viewer is allowed.
 */
export function hasDialogFlag(url: string, flag: string): boolean {
    const query = url.split('#')[0]?.split('?')[1] ?? '';
    const value = new URLSearchParams(query).get(flag);
    return value === '1' || value === 'true';
}

/** Drop the flag from the address bar so refresh/back does not reopen it. */
export function withoutDialogFlag(url: string, flag: string): string {
    const [pathAndQuery, hash] = url.split('#');
    const [path, query = ''] = (pathAndQuery ?? '').split('?');
    const params = new URLSearchParams(query);
    params.delete(flag);
    const qs = params.toString();
    return `${path}${qs ? `?${qs}` : ''}${hash ? `#${hash}` : ''}`;
}

export function useDialogDeepLink(
    flag: 'create' | 'edit',
    allowed: boolean,
): [boolean, (open: boolean) => void] {
    const page = usePage();
    const [open, setOpen] = useState(
        () => allowed && hasDialogFlag(page.url, flag),
    );

    useEffect(() => {
        if (!hasDialogFlag(page.url, flag)) return;
        router.replace({
            url: withoutDialogFlag(page.url, flag),
            preserveScroll: true,
            preserveState: true,
        });
        // Only the initial visit carries the flag.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return [open, setOpen];
}

/**
 * `back()->with('error')` still fires Inertia's onSuccess — success panes
 * must check the flash before claiming the save worked.
 */
export function pageHasFlashError(page: unknown): boolean {
    const flash = (page as { props?: { flash?: { error?: unknown } } } | null)
        ?.props?.flash;
    return Boolean(flash?.error);
}

/** Map server validation keys to the wizard step that owns the field. */
export function firstErrorStep<K extends string>(
    errors: Record<string, string | undefined>,
    fieldSteps: Record<string, K>,
    fallback: K,
): K | null {
    const first = Object.keys(errors).find((key) => errors[key]);
    if (!first) return null;
    const root = first.split('.')[0] ?? first;
    return fieldSteps[first] ?? fieldSteps[root] ?? fallback;
}
