import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import type { ControlRoomRowAction } from '@/components/control-room/control-room-row-actions';
import {
    ArrowUpCircle,
    BellOff,
    Check,
    Copy,
    ExternalLink,
    Eye,
    MoveRight,
    ShieldAlert,
    UserPlus,
} from 'lucide-react';

type ActionDependencies = {
    openWorkspace: (id: number) => void;
    post: (href: string) => void;
    visit: (href: string) => void;
    copy: (value: string) => void;
};

/**
 * One permission-aware action model for every Control Room alert worklist.
 * Actions that require form input or lifecycle confirmation open the canonical
 * workspace instead of mutating the record directly.
 */
export function buildControlRoomAlertRowActions(
    row: AlertWorklistRow,
    dependencies: ActionDependencies,
): ControlRoomRowAction[] {
    const reference = row.reference_number ?? `Alert ${row.id}`;
    const actions: ControlRoomRowAction[] = [
        {
            key: 'open',
            label: row.next_action.label,
            icon: Eye,
            onSelect: () => dependencies.openWorkspace(row.id),
        },
    ];

    if (row.actions.can_claim) {
        actions.push({
            key: 'claim',
            label: 'Claim alert',
            icon: UserPlus,
            onSelect: () =>
                dependencies.post(
                    `/control-room/alerts/${row.id}/assign-to-me`,
                ),
        });
    }
    if (row.actions.can_acknowledge) {
        actions.push({
            key: 'acknowledge',
            label: 'Acknowledge alert',
            icon: Check,
            onSelect: () =>
                dependencies.post(`/control-room/alerts/${row.id}/acknowledge`),
        });
    }
    if (row.actions.can_move_queue) {
        actions.push({
            key: 'move',
            label: 'Move queue in workspace',
            icon: MoveRight,
            onSelect: () => dependencies.openWorkspace(row.id),
        });
    }
    if (row.actions.can_escalate) {
        actions.push({
            key: 'escalate',
            label: 'Escalate in workspace',
            icon: ArrowUpCircle,
            onSelect: () => dependencies.openWorkspace(row.id),
        });
    }
    if (row.actions.can_snooze || row.actions.can_unsnooze) {
        actions.push({
            key: row.actions.can_unsnooze ? 'unsnooze' : 'snooze',
            label: row.actions.can_unsnooze
                ? 'Unsnooze in workspace'
                : 'Snooze in workspace',
            icon: BellOff,
            onSelect: () => dependencies.openWorkspace(row.id),
        });
    }
    if (row.actions.can_create_incident) {
        actions.push({
            key: 'incident',
            label: 'Create incident in workspace',
            icon: ShieldAlert,
            onSelect: () => dependencies.openWorkspace(row.id),
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
