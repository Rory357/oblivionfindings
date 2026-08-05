import { CommandCentrePage } from '@/components/command-centre/command-centre-page';
import {
    AlertWorkspaceDialog,
    type AlertWorkspaceDetail,
} from '@/components/control-room/alert-workspace-dialog';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Bell } from 'lucide-react';

/**
 * Deep-link / shareable alert view. The full alert surface is the
 * AlertWorkspaceDialog (opened over any Control Room list via ?alert=); this
 * thin shell renders the same modal for a direct /control-room/alerts/{id}
 * link. Closing returns to the validated invoking surface.
 */
export default function ControlRoomAlertShow(
    props: AlertWorkspaceDetail & { return_to: string },
) {
    const ref = props.alert.reference_number ?? `Alert ${props.alert.id}`;
    const returnTo = props.return_to;
    const cameFromTasks = returnTo.startsWith('/tasks');
    const alertHref = cameFromTasks
        ? `/control-room/alerts/${props.alert.id}?return_to=${encodeURIComponent(returnTo)}`
        : `/control-room/alerts/${props.alert.id}`;

    return (
        <AppLayout
            breadcrumbs={[
                cameFromTasks
                    ? { title: 'Tasks', href: returnTo }
                    : { title: 'Control Room', href: '/control-room' },
                ...(cameFromTasks
                    ? []
                    : [{ title: 'Alerts', href: '/control-room/alerts' }]),
                { title: ref, href: alertHref },
            ]}
        >
            <Head title={`Alert ${ref}`} />
            <div className="p-6">
                <CommandCentrePage
                    variant="compact"
                    current={alertHref}
                    icon={Bell}
                    title={ref}
                    description={
                        props.can.manage
                            ? 'Continue the alert response in its canonical Control Room workspace.'
                            : 'Review the alert response in read-only mode.'
                    }
                    status="Alert workspace"
                >
                    <Card>
                        <CardContent className="p-6 text-sm text-muted-foreground">
                            Alert details are open in the guided workspace.
                        </CardContent>
                    </Card>
                </CommandCentrePage>
            </div>
            <AlertWorkspaceDialog
                detail={props}
                open
                onClose={() => router.visit(returnTo)}
            />
        </AppLayout>
    );
}
