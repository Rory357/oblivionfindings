import { usePage } from '@inertiajs/react';

import {
    emarBreadcrumbs,
    emarRecordBreadcrumbs,
    type EmarBreadcrumb,
    type EmarNavigationPermissions,
} from '@/lib/emar-navigation';
import type { BreadcrumbItem } from '@/types';

/**
 * The Home-rooted Medication trail for the current page
 * (`emarBreadcrumbs` in lib/emar-navigation.ts): Home › Medication › hub ›
 * view, each crumb a page this viewer can open.
 */
export function useEmarBreadcrumbs(): BreadcrumbItem[] {
    const page = usePage<{ auth?: { can?: EmarNavigationPermissions } }>();
    return emarBreadcrumbs(page.url, page.props.auth?.can);
}

/** The trail for a person's medication record (`emarRecordBreadcrumbs`). */
export function useEmarRecordBreadcrumbs(
    person: EmarBreadcrumb,
): BreadcrumbItem[] {
    const page = usePage<{ auth?: { can?: EmarNavigationPermissions } }>();
    return emarRecordBreadcrumbs(page.props.auth?.can, person);
}
