import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Link } from '@inertiajs/react';
import { Bell, ClockAlert } from 'lucide-react';
import { time, type Person, type ResponseAlert } from './model';
export const overdue = (alert: ResponseAlert, checkedAt: string) =>
    !!alert.dueAt && Date.parse(alert.dueAt) < Date.parse(checkedAt);
export function ResponseSummary({
    alerts,
    checkedAt,
}: {
    alerts: ResponseAlert[];
    checkedAt: string;
}) {
    return (
        <div className="space-y-2" aria-label="Active Control Room responses">
            {alerts.map((alert) => (
                <div className="space-y-2 rounded-md border p-3" key={alert.id}>
                    <div className="flex flex-wrap items-center gap-2">
                        <Bell className="size-4" />
                        <strong className="text-sm">{alert.reference}</strong>
                        <Severity alert={alert} />
                        {overdue(alert, checkedAt) && (
                            <StatusBadge variant="critical">
                                <ClockAlert className="size-3" />
                                Overdue
                            </StatusBadge>
                        )}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {alert.owner ?? 'Unassigned'} · Due {time(alert.dueAt)}
                    </p>
                    <Button asChild variant="outline" size="sm">
                        <Link href={alert.href}>{alert.nextAction}</Link>
                    </Button>
                </div>
            ))}
        </div>
    );
}
function Severity({ alert }: { alert: ResponseAlert }) {
    return (
        <StatusBadge
            variant={
                ['critical', 'high'].includes(alert.severity)
                    ? 'critical'
                    : alert.severity === 'medium'
                      ? 'warning'
                      : 'neutral'
            }
        >
            {alert.severity.charAt(0).toUpperCase() + alert.severity.slice(1)}
        </StatusBadge>
    );
}
export function Responses({
    alerts,
    people,
    canRead,
    checkedAt,
}: {
    alerts: ResponseAlert[];
    people: Person[];
    canRead: boolean;
    checkedAt: string;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Original Control Room responses</CardTitle>
                <CardDescription>
                    Open the original response for its next action, escalation,
                    notification delivery and response history.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {!canRead ? (
                    <p>
                        Response evidence is unavailable with your current
                        permissions.
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Response / severity</TableHead>
                                <TableHead>Person</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Owner / due</TableHead>
                                <TableHead>Action</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {alerts.map((alert) => (
                                <TableRow key={alert.id}>
                                    <TableCell>
                                        <div className="mb-1 flex items-center gap-2">
                                            <strong>{alert.reference}</strong>
                                            <Severity alert={alert} />
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {alert.type.replaceAll('_', ' ')} ·{' '}
                                            {time(alert.triggeredAt)}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        {
                                            people.find(
                                                (p) => p.id === alert.personId,
                                            )?.name
                                        }
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge status={alert.status} />
                                    </TableCell>
                                    <TableCell>
                                        {alert.owner ?? 'Unassigned'}
                                        <div className="text-xs text-muted-foreground">
                                            {time(alert.dueAt)}
                                        </div>
                                        {overdue(alert, checkedAt) && (
                                            <StatusBadge variant="critical">
                                                <ClockAlert className="size-3" />
                                                Overdue
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <Button asChild variant="outline">
                                            <Link href={alert.href}>
                                                {alert.nextAction}
                                            </Link>
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                            {!alerts.length && (
                                <TableRow>
                                    <TableCell colSpan={5}>
                                        No readable active responses match these
                                        filters. Clear the response status or
                                        person filters to broaden this view.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}
