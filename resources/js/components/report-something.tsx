/**
 * "Report something" from anywhere (board G4-2, approved by Stephan 1 Nov 2026; decision 9 renamed the top bar's
 * button): the top bar on every page and the staff pages' More sheet open the one H&S chooser, with "A person was
 * hurt, or nearly hurt" already chosen, so one more click carries on as before. Each choice opens its own form and
 * comes back to where the person started where that form can (My Day; people who can't open the H&S Overview also
 * come back to My Day).
 */
import {
    launcherWorkflows,
    ReportLauncher,
} from '@/pages/health-safety/components/report-launcher';
import { reportPageFor } from '@/pages/health-safety/components/report-pages';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';

type Can = Parameters<typeof launcherWorkflows>[0];

/** True when the person may use at least one choice (exported for the callers' own buttons). */
export function useCanReportSomething(): boolean {
    const can = usePage<SharedData>().props.auth?.can as unknown as Can;
    return launcherWorkflows(can).length > 0;
}

/**
 * Where a chosen form comes back to: My Day when the person is on My Day, or can't open the H&S Overview (support
 * workers); otherwise each form's own default (exported for tests).
 */
export function reportReturnTo(path: string, can: Can): string | undefined {
    const onMyDay = path === '/my-day' || path.startsWith('/my-day?');
    const canOpenOverview = Boolean(
        (can as { hazards?: { view?: boolean } } | undefined)?.hazards?.view,
    );
    return onMyDay || !canOpenOverview ? '/my-day' : undefined;
}

/** The page a chosen form opens (exported for tests). */
export function reportDestination(
    key: string,
    returnTo: string | undefined,
): string {
    if (key === 'incident') {
        return returnTo === '/my-day'
            ? '/incidents/create?return_to=%2Fmy-day'
            : '/incidents/create';
    }
    // The hazard form opens in its register; the rest have their own report pages.
    return reportPageFor(key, returnTo) ?? '/compliance/hazards';
}

export function ReportSomethingDialog({
    open,
    onClose,
    onIncident,
}: {
    open: boolean;
    onClose: () => void;
    /** A page with its own incident link (My Day keeps its shift) passes it here. */
    onIncident?: () => void;
}) {
    const page = usePage<SharedData>();
    const can = page.props.auth?.can as unknown as Can;
    const choose = (key: string) => {
        onClose();
        if (key === 'incident' && onIncident) {
            onIncident();
            return;
        }
        router.visit(reportDestination(key, reportReturnTo(page.url, can)));
    };
    return <ReportLauncher open={open} onClose={onClose} onWorkflow={choose} />;
}

export default ReportSomethingDialog;
