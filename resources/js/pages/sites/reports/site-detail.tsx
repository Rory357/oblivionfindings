import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from '@/components/ui/table';
import { AlertTriangle, Clock, Shield, Key, ClipboardList } from 'lucide-react';

type Site = {
    id: number;
    name: string;
    type: string;
    region?: string;
    address?: string;
};

type AuditEntry = {
    id: number;
    auditable_type: string;
    auditable_id: number;
    event: string;
    created_at: string;
};

type Props = {
    site: Site;
    hazardStats: {
        open: number;
        closed: number;
        overdue: number;
        avg_time_to_close: number | null;
    };
    checklistStats: {
        total_runs: number;
        completed_runs: number;
        overdue_runs: number;
        completion_rate: number;
    };
    inspectionStats: {
        scheduled: number;
        completed: number;
        overdue: number;
    };
    credentialStats: {
        total: number;
        requiring_reauth: number;
    };
    recentAuditEntries: AuditEntry[];
};

export default function SiteDetailReport({
    site,
    hazardStats,
    checklistStats,
    inspectionStats,
    credentialStats,
    recentAuditEntries,
}: Props) {
    return (
        <AppLayout breadcrumbs={[
            { title: 'Reports', href: '/sites/reports' },
            { title: site.name, href: `/sites/reports/site/${site.id}` },
        ]}>
            <Head title={`Site Report - ${site.name}`} />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref={`/sites/${site.id}/reports`}
                        backLabel="Back to reports"
                        title={`Site Detail Report: ${site.name}`}
                        description={`${site.type === 'house' ? 'House' : site.type === 'facility' ? 'Facility' : 'Head Office'}${site.region ? ` - ${site.region}` : ''}`}
                    />
                }
            >
                {/* Hazard Stats */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base flex items-center gap-2">
                            <AlertTriangle className="w-4 h-4 text-status-warning" />
                            Hazards
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-4">
                            <div className="text-center p-3 rounded-lg border">
                                <div className="text-2xl font-bold text-status-warning">{hazardStats.open}</div>
                                <div className="text-sm text-muted-foreground">Open</div>
                            </div>
                            <div className="text-center p-3 rounded-lg border">
                                <div className="text-2xl font-bold text-status-success">{hazardStats.closed}</div>
                                <div className="text-sm text-muted-foreground">Closed</div>
                            </div>
                            <div className="text-center p-3 rounded-lg border">
                                <div className="text-2xl font-bold text-status-critical">{hazardStats.overdue}</div>
                                <div className="text-sm text-muted-foreground">Overdue</div>
                            </div>
                            <div className="text-center p-3 rounded-lg border">
                                <div className="text-2xl font-bold">
                                    {hazardStats.avg_time_to_close !== null ? `${hazardStats.avg_time_to_close}d` : 'N/A'}
                                </div>
                                <div className="text-sm text-muted-foreground">Avg Time to Close</div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Checklist Stats */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base flex items-center gap-2">
                            <ClipboardList className="w-4 h-4 text-status-info" />
                            Checklists
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-4">
                            <div className="text-center p-3 rounded-lg border">
                                <div className="text-2xl font-bold">{checklistStats.total_runs}</div>
                                <div className="text-sm text-muted-foreground">Total Runs</div>
                            </div>
                            <div className="text-center p-3 rounded-lg border">
                                <div className="text-2xl font-bold text-status-success">{checklistStats.completed_runs}</div>
                                <div className="text-sm text-muted-foreground">Completed</div>
                            </div>
                            <div className="text-center p-3 rounded-lg border">
                                <div className="text-2xl font-bold text-status-critical">{checklistStats.overdue_runs}</div>
                                <div className="text-sm text-muted-foreground">Overdue</div>
                            </div>
                            <div className="text-center p-3 rounded-lg border">
                                <div className="text-2xl font-bold text-status-info">{checklistStats.completion_rate}%</div>
                                <div className="text-sm text-muted-foreground">Completion Rate</div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Inspection & Credential Stats */}
                <div className="grid gap-4 sm:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base flex items-center gap-2">
                                <Shield className="w-4 h-4 text-primary" />
                                Inspections
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="text-center p-3 rounded-lg border">
                                    <div className="text-2xl font-bold">{inspectionStats.scheduled}</div>
                                    <div className="text-sm text-muted-foreground">Scheduled</div>
                                </div>
                                <div className="text-center p-3 rounded-lg border">
                                    <div className="text-2xl font-bold text-status-success">{inspectionStats.completed}</div>
                                    <div className="text-sm text-muted-foreground">Completed</div>
                                </div>
                                <div className="text-center p-3 rounded-lg border">
                                    <div className="text-2xl font-bold text-status-critical">{inspectionStats.overdue}</div>
                                    <div className="text-sm text-muted-foreground">Overdue</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base flex items-center gap-2">
                                <Key className="w-4 h-4 text-status-warning" />
                                Credentials
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="text-center p-3 rounded-lg border">
                                    <div className="text-2xl font-bold">{credentialStats.total}</div>
                                    <div className="text-sm text-muted-foreground">Total</div>
                                </div>
                                <div className="text-center p-3 rounded-lg border">
                                    <div className="text-2xl font-bold text-status-warning">{credentialStats.requiring_reauth}</div>
                                    <div className="text-sm text-muted-foreground">Requiring Reauth</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Recent Audit Entries */}
                {recentAuditEntries.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base flex items-center gap-2">
                                <Clock className="w-4 h-4 text-muted-foreground" />
                                Recent Audit Log
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Event</TableHead>
                                        <TableHead>Date</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {recentAuditEntries.map((entry) => (
                                        <TableRow key={entry.id}>
                                            <TableCell>
                                                <Badge variant="outline">
                                                    {entry.auditable_type?.split('\\').pop()}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>{entry.event}</TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {new Date(entry.created_at).toLocaleDateString()}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}
            </PageLayout>
        </AppLayout>
    );
}
