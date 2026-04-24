import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Clock, CheckCircle, User, Calendar, Shield } from 'lucide-react';

type Props = {
    breach: any;
};

export default function ShowDataBreach({ breach }: Props) {
    const statusLabels: Record<string, string> = {
        discovered: 'discovered',
        under_investigation: 'under investigation',
        contained: 'contained',
        notified: 'notified',
        resolved: 'resolved',
    };

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'under_investigation':
                return 'bg-blue-100 text-blue-800';
            case 'discovered':
                return 'bg-yellow-100 text-yellow-800';
            case 'contained':
                return 'bg-orange-100 text-orange-800';
            case 'resolved':
                return 'bg-green-100 text-green-800';
            case 'notified':
                return 'bg-primary/10 text-primary';
            default:
                return 'bg-muted text-foreground';
        }
    };

    const formatDate = (dateString: string) => {
        if (!dateString) return 'N/A';
        return new Date(dateString).toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    const hoursFromDiscovery = () => {
        const discovered = new Date(breach.discovered_at);
        const now = new Date();
        return Math.floor((now.getTime() - discovered.getTime()) / (1000 * 60 * 60));
    };

    const hours = hoursFromDiscovery();
    const icoDeadlineApproaching = breach.requires_authority_notification && !breach.authority_notified_at && hours < 72;
    const icoDeadlinePassed = breach.requires_authority_notification && !breach.authority_notified_at && hours >= 72;

    return (
        <AppLayout breadcrumbs={[
            { title: 'Privacy & GDPR', href: '/privacy/dashboard' },
            { title: 'Data Breaches', href: '/privacy/breaches' },
            { title: breach.breach_reference, href: `/privacy/breaches/${breach.id}` },
        ]}>
            <Head title={`Breach ${breach.breach_reference}`} />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <AlertTriangle className="h-5 w-5 text-red-500" />
                            {breach.breach_reference}
                        </h1>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge className={getStatusColor(breach.status)}>
                                {statusLabels[breach.status] ?? breach.status}
                            </Badge>
                            {icoDeadlinePassed && (
                                <Badge variant="outline" className="border-red-200 bg-red-50 text-red-700">
                                    <AlertTriangle className="mr-1 h-3 w-3" />
                                    ICO deadline exceeded ({hours}h since discovery)
                                </Badge>
                            )}
                            {icoDeadlineApproaching && (
                                <Badge variant="outline" className="border-orange-200 bg-orange-50 text-orange-700">
                                    <Clock className="mr-1 h-3 w-3" />
                                    {72 - hours}h until ICO deadline
                                </Badge>
                            )}
                            {breach.authority_notified_at && (
                                <Badge variant="outline" className="border-green-200 bg-green-50 text-green-700">
                                    <CheckCircle className="mr-1 h-3 w-3" />
                                    ICO Notified
                                </Badge>
                            )}
                            {breach.subjects_notified_at && (
                                <Badge variant="outline" className="border-blue-200 bg-blue-50 text-blue-700">
                                    <CheckCircle className="mr-1 h-3 w-3" />
                                    Subjects Notified
                                </Badge>
                            )}
                        </div>
                    </div>
                    <Link href="/privacy/breaches" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                        Back to List
                    </Link>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Calendar className="h-5 w-5 text-primary" />
                                Timeline
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div>
                                <span className="text-xs text-muted-foreground">Discovered</span>
                                <p className="font-medium">{formatDate(breach.discovered_at)}</p>
                            </div>
                            {breach.discovered_by && (
                                <div>
                                    <span className="text-xs text-muted-foreground">Discovered By</span>
                                    <p className="font-medium">{breach.discovered_by.name}</p>
                                </div>
                            )}
                            {breach.authority_notified_at && (
                                <div>
                                    <span className="text-xs text-muted-foreground">ICO Notified</span>
                                    <p className="font-medium">{formatDate(breach.authority_notified_at)}</p>
                                    {breach.authority_reference && (
                                        <p className="text-xs text-muted-foreground">Ref: {breach.authority_reference}</p>
                                    )}
                                </div>
                            )}
                            {breach.subjects_notified_at && (
                                <div>
                                    <span className="text-xs text-muted-foreground">Subjects Notified</span>
                                    <p className="font-medium">{formatDate(breach.subjects_notified_at)}</p>
                                    <p className="text-xs text-muted-foreground">Method: {breach.notification_method}</p>
                                </div>
                            )}
                            {breach.resolved_at && (
                                <div>
                                    <span className="text-xs text-muted-foreground">Resolved</span>
                                    <p className="font-medium">{formatDate(breach.resolved_at)}</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <User className="h-5 w-5 text-blue-500" />
                                Impact
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div>
                                <span className="text-xs text-muted-foreground">Individuals Affected</span>
                                <p className="font-medium">
                                    {breach.approximate_individuals_affected
                                        ? `~${breach.approximate_individuals_affected.toLocaleString()}`
                                        : 'Unknown'}
                                </p>
                            </div>
                            <div>
                                <span className="text-xs text-muted-foreground">ICO Notification Required</span>
                                <p className="font-medium">{breach.requires_authority_notification ? 'Yes' : 'No'}</p>
                            </div>
                            <div>
                                <span className="text-xs text-muted-foreground">Subject Notification Required</span>
                                <p className="font-medium">{breach.requires_subject_notification ? 'Yes' : 'No'}</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Shield className="h-5 w-5 text-red-500" />
                            Breach Details
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div>
                            <span className="text-xs text-muted-foreground">Nature of Breach</span>
                            <p className="text-sm text-muted-foreground whitespace-pre-wrap mt-1">
                                {breach.nature_of_breach}
                            </p>
                        </div>
                        {breach.likely_consequences && (
                            <div>
                                <span className="text-xs text-muted-foreground">Likely Consequences</span>
                                <p className="text-sm text-muted-foreground whitespace-pre-wrap mt-1">
                                    {breach.likely_consequences}
                                </p>
                            </div>
                        )}
                        {breach.measures_taken && (
                            <div>
                                <span className="text-xs text-muted-foreground">Measures Taken</span>
                                <p className="text-sm text-muted-foreground whitespace-pre-wrap mt-1">
                                    {breach.measures_taken}
                                </p>
                            </div>
                        )}
                        {breach.resolution_notes && (
                            <div>
                                <span className="text-xs text-muted-foreground">Resolution Notes</span>
                                <p className="text-sm text-muted-foreground whitespace-pre-wrap mt-1">
                                    {breach.resolution_notes}
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {breach.status !== 'resolved' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Actions</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            {breach.requires_authority_notification && !breach.authority_notified_at && (
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    onClick={() => {
                                        const reference = prompt('Enter ICO reference number (if available):');
                                        router.post(`/privacy/breaches/${breach.id}/notify-ico`, {
                                            authority_reference: reference || '',
                                        });
                                    }}
                                >
                                    Record ICO Notification
                                </Button>
                            )}
                            {breach.requires_subject_notification && !breach.subjects_notified_at && (
                                <Button
                                    size="sm"
                                    onClick={() => {
                                        const method = prompt('Enter notification method (e.g., email, letter):');
                                        if (method) {
                                            router.post(`/privacy/breaches/${breach.id}/notify-subjects`, {
                                                notification_method: method,
                                            });
                                        }
                                    }}
                                >
                                    Record Subject Notification
                                </Button>
                            )}
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                    const notes = prompt('Enter resolution notes:');
                                    if (notes) {
                                        router.post(`/privacy/breaches/${breach.id}/resolve`, {
                                            resolution_notes: notes,
                                        });
                                    }
                                }}
                            >
                                Mark Resolved
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
