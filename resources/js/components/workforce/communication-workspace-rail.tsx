import { PageHeaderRail } from '@/components/page';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { ArrowLeftRight, NotebookPen } from 'lucide-react';

type Workspace = 'handovers' | 'notes';

function flags(value: unknown): Record<string, unknown> {
    return value !== null && typeof value === 'object' && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : {};
}

/** Use published list capabilities; server role/site checks remain authoritative. */
export function communicationAccess(value: unknown, role?: unknown) {
    const can = flags(value);
    const shifts = flags(can.shifts);
    const handovers = flags(can.handovers);
    const timesheets = flags(can.timesheets);
    const rostering = flags(can.rostering);
    const analytics = flags(flags(can.hr).analytics);
    const manager =
        shifts.manageAny === true ||
        timesheets.manageAny === true ||
        timesheets.approve === true ||
        rostering.viewAny === true ||
        analytics.view === true;
    return {
        handovers:
            handovers.viewAny === true ||
            shifts.viewAny === true ||
            shifts.manageAny === true ||
            shifts.viewAssigned === true ||
            shifts.update === true,
        notes:
            shifts.viewAny === true &&
            manager &&
            role !== 'client' &&
            role !== 'next_of_kin',
    };
}

/** Keep canonical pages, independent permissions and browser history intact. */
export function CommunicationWorkspaceRail({
    active,
    week,
    site,
    disabled = false,
}: {
    active: Workspace;
    week: string;
    site: number | null;
    disabled?: boolean;
}) {
    const { auth } = usePage<SharedData>().props;
    const access = communicationAccess(auth?.can, auth?.user?.role);
    const items = [
        { key: 'handovers' as const, label: 'Handovers', icon: ArrowLeftRight },
        { key: 'notes' as const, label: 'Shift notes', icon: NotebookPen },
    ].filter((item) => item.key === active || access[item.key]);

    return (
        <fieldset disabled={disabled} className="w-full min-w-0">
            <PageHeaderRail
                items={items}
                value={active}
                showFind={false}
                ariaLabel="Handover workspace"
                onSelect={(next) => {
                    if (disabled || next === active || !access[next]) return;
                    const query = new URLSearchParams({ week });
                    if (site !== null) {
                        query.set(
                            next === 'notes' ? 'site_id' : 'site',
                            String(site),
                        );
                    }
                    router.visit(
                        `${next === 'notes' ? '/operations/shift-notes' : '/operations/handovers'}?${query}`,
                    );
                }}
            />
        </fieldset>
    );
}
