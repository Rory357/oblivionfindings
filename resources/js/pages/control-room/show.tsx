import { AlertWorkspaceDialog, type AlertWorkspaceDetail } from '@/components/control-room/alert-workspace-dialog';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';

/**
 * Deep-link / shareable alert view. The full alert surface is the
 * AlertWorkspaceDialog (opened over any Control Room list via ?alert=); this
 * thin shell renders the same modal for a direct /control-room/alerts/{id}
 * link. Closing returns to the alerts list.
 */
export default function ControlRoomAlertShow(props: AlertWorkspaceDetail) {
    const ref = `CR-${props.alert.id}`;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Alerts', href: '/control-room/alerts' },
                { title: ref, href: `/control-room/alerts/${props.alert.id}` },
            ]}
        >
            <Head title={`Alert ${ref}`} />
            <AlertWorkspaceDialog detail={props} open onClose={() => router.visit('/control-room/alerts')} />
        </AppLayout>
    );
}
