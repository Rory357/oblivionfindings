import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import type { AlertActionKey } from '@/components/control-room/alert-record/alert-actions';
import type { ControlRoomRowAction } from '@/components/control-room/control-room-row-actions';
import {
    ArrowUpCircle,
    ArrowUpRight,
    Bell,
    BellOff,
    Check,
    Copy,
    ExternalLink,
    Eye,
    FileWarning,
    UserPlus,
} from 'lucide-react';

type ActionDependencies = {
    /** Show the alert in the preview beside the list. */
    select: (id: number) => void;
    /** Show the alert and open one of its action pop-ups. */
    openAction: (id: number, action: AlertActionKey) => void;
    post: (href: string) => void;
    visit: (href: string) => void;
    copy: (value: string) => void;
};

/**
 * One permission-aware item list for every Control Room alert row — the same
 * items on the kebab and on right-click. One-step, recorded actions (Claim,
 * Acknowledge) run straight away; anything that needs words or a review
 * opens its pop-up from the kit.
 */
export function buildControlRoomAlertRowActions(
    row: AlertWorklistRow,
    dependencies: ActionDependencies,
): ControlRoomRowAction[] {
    const reference = row.reference_number ?? `Alert ${row.id}`;
    const actions: ControlRoomRowAction[] = [
        {
            key: 'preview',
            label: 'Show in preview',
            icon: Eye,
            onSelect: () => dependencies.select(row.id),
        },
        {
            key: 'open',
            label: 'Open full record',
            icon: ArrowUpRight,
            onSelect: () => dependencies.visit(row.href),
        },
    ];

    if (row.actions.can_claim) {
        actions.push({
            key: 'claim',
            label: 'Claim alert',
            icon: UserPlus,
            onSelect: () =>
                dependencies.post(`/control-room/alerts/${row.id}/assign-to-me`),
        });
    }
    if (row.actions.can_acknowledge) {
        actions.push({
            key: 'acknowledge',
            label: 'Acknowledge',
            icon: Check,
            onSelect: () =>
                dependencies.post(`/control-room/alerts/${row.id}/acknowledge`),
        });
    }
    if (row.actions.can_escalate) {
        actions.push({
            key: 'escalate',
            label: 'Escalate…',
            icon: ArrowUpCircle,
            onSelect: () => dependencies.openAction(row.id, 'escalate'),
        });
    }
    if (row.actions.can_snooze) {
        actions.push({
            key: 'snooze',
            label: 'Snooze…',
            icon: BellOff,
            onSelect: () => dependencies.openAction(row.id, 'snooze'),
        });
    }
    if (row.actions.can_unsnooze) {
        actions.push({
            key: 'unsnooze',
            label: 'Bring back now',
            icon: Bell,
            onSelect: () =>
                dependencies.post(`/control-room/alerts/${row.id}/unsnooze`),
        });
    }
    if (row.actions.can_create_incident) {
        actions.push({
            key: 'incident',
            label: 'Report incident…',
            icon: FileWarning,
            onSelect: () => dependencies.openAction(row.id, 'report_incident'),
        });
    }
    if (row.actions.incident_href) {
        actions.push({
            key: 'open-incident',
            label: 'Open linked incident',
            icon: ExternalLink,
            onSelect: () => dependencies.visit(row.actions.incident_href!),
        });
    }
    if (row.actions.health_safety_href) {
        actions.push({
            key: 'open-health-safety',
            label: 'Open H&S event',
            icon: ExternalLink,
            onSelect: () => dependencies.visit(row.actions.health_safety_href!),
        });
    }
    if (row.actions.can_copy_reference) {
        actions.push({
            key: 'copy-reference',
            label: 'Copy reference',
            icon: Copy,
            onSelect: () => dependencies.copy(reference),
        });
    }

    return actions;
}
