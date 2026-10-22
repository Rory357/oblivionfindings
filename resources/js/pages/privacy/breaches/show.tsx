import { PageHero, PageLayout } from '@/components/page';
import { PrivacyFieldDialog } from '@/components/privacy/privacy-field-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    CheckCircle,
    Shield,
    User,
} from 'lucide-react';
import { useState } from 'react';

type Props = {
    breach: any;
};

type BreachAction = 'notify-opc' | 'notify-subjects' | 'resolve';

// Both "notified" actions run DataBreachController::notifyPrivacyTeam, which
// emails and alerts everyone with privacy.reportBreaches plus the breach's
// discoverer and creator. Nothing clears the notified date afterwards.
const NOTIFIED_EFFECT =
    "The breach is marked as notified with today's date. Everyone who manages breaches, plus whoever found and logged this one, gets an email and an in-app notification. This can't be undone.";

export default function ShowDataBreach({ breach }: Props) {
    const [action, setAction] = useState<BreachAction | null>(null);
    const closeAction = () => setAction(null);
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
                return 'bg-status-info-bg text-status-info';
            case 'discovered':
                return 'bg-status-warning-bg text-status-warning';
            case 'contained':
                return 'bg-status-warning-bg text-status-warning';
            case 'resolved':
                return 'bg-status-success-bg text-status-success';
            case 'notified':
                return 'bg-primary/10 text-primary';
            default:
                return 'bg-muted text-foreground';
        }
    };

    const formatDate = (dateString: string) => {
        if (!dateString) return 'N/A';
        return new Date(dateString).toLocaleDateString('en-NZ', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    const authorityNotificationPending =
        breach.requires_authority_notification && !breach.authority_notified_at;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Privacy', href: '/privacy/dashboard' },
                { title: 'Data Breaches', href: '/privacy/breaches' },
                {
                    title: breach.breach_reference,
                    href: `/privacy/breaches/${breach.id}`,
                },
            ]}
        >
            <Head title={`Breach ${breach.breach_reference}`} />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/privacy/breaches"
                        backLabel="Back to List"
                        title={breach.breach_reference}
                        description={
                            statusLabels[breach.status] ?? breach.status
                        }
                    >
                        <div
                            className="flex flex-wrap gap-2"
                            data-test="privacy-breach-show"
                        >
                            <Badge
                                className={getStatusColor(breach.status)}
                                data-test="privacy-breach-status"
                            >
                                {statusLabels[breach.status] ?? breach.status}
                            </Badge>
                            {authorityNotificationPending && (
                                <Badge
                                    variant="outline"
                                    className="border-status-critical/30 bg-status-critical-bg text-status-critical"
                                >
                                    <AlertTriangle className="mr-1 h-3 w-3" />
                                    OPC notification pending
                                </Badge>
                            )}
                            {breach.authority_notified_at && (
                                <Badge
                                    variant="outline"
                                    className="border-status-success/30 bg-status-success-bg text-status-success"
                                >
                                    <CheckCircle className="mr-1 h-3 w-3" />
                                    OPC Notified
                                </Badge>
                            )}
                            {breach.subjects_notified_at && (
                                <Badge
                                    variant="outline"
                                    className="border-status-info/30 bg-status-info-bg text-status-info"
                                >
                                    <CheckCircle className="mr-1 h-3 w-3" />
                                    Subjects Notified
                                </Badge>
                            )}
                        </div>
                    </PageHero>
                }
            >
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
                                <span className="text-xs text-muted-foreground">
                                    Discovered
                                </span>
                                <p className="font-medium">
                                    {formatDate(breach.discovered_at)}
                                </p>
                            </div>
                            {breach.discovered_by && (
                                <div>
                                    <span className="text-xs text-muted-foreground">
                                        Discovered By
                                    </span>
                                    <p className="font-medium">
                                        {breach.discovered_by.name}
                                    </p>
                                </div>
                            )}
                            {breach.authority_notified_at && (
                                <div>
                                    <span className="text-xs text-muted-foreground">
                                        OPC Notified
                                    </span>
                                    <p className="font-medium">
                                        {formatDate(
                                            breach.authority_notified_at,
                                        )}
                                    </p>
                                    {breach.authority_reference && (
                                        <p className="text-xs text-muted-foreground">
                                            Ref: {breach.authority_reference}
                                        </p>
                                    )}
                                </div>
                            )}
                            {breach.subjects_notified_at && (
                                <div>
                                    <span className="text-xs text-muted-foreground">
                                        Subjects Notified
                                    </span>
                                    <p className="font-medium">
                                        {formatDate(
                                            breach.subjects_notified_at,
                                        )}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Method: {breach.notification_method}
                                    </p>
                                </div>
                            )}
                            {breach.resolved_at && (
                                <div>
                                    <span className="text-xs text-muted-foreground">
                                        Resolved
                                    </span>
                                    <p className="font-medium">
                                        {formatDate(breach.resolved_at)}
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <User className="h-5 w-5 text-status-info" />
                                Impact
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div>
                                <span className="text-xs text-muted-foreground">
                                    Individuals Affected
                                </span>
                                <p className="font-medium">
                                    {breach.approximate_individuals_affected
                                        ? `~${breach.approximate_individuals_affected.toLocaleString()}`
                                        : 'Unknown'}
                                </p>
                            </div>
                            <div>
                                <span className="text-xs text-muted-foreground">
                                    OPC Notification Required
                                </span>
                                <p className="font-medium">
                                    {breach.requires_authority_notification
                                        ? 'Yes'
                                        : 'No'}
                                </p>
                            </div>
                            <div>
                                <span className="text-xs text-muted-foreground">
                                    Subject Notification Required
                                </span>
                                <p className="font-medium">
                                    {breach.requires_subject_notification
                                        ? 'Yes'
                                        : 'No'}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Shield className="h-5 w-5 text-status-critical" />
                            Breach Details
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div>
                            <span className="text-xs text-muted-foreground">
                                Nature of Breach
                            </span>
                            <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">
                                {breach.nature_of_breach}
                            </p>
                        </div>
                        {breach.likely_consequences && (
                            <div>
                                <span className="text-xs text-muted-foreground">
                                    Likely Consequences
                                </span>
                                <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">
                                    {breach.likely_consequences}
                                </p>
                            </div>
                        )}
                        {breach.measures_taken && (
                            <div>
                                <span className="text-xs text-muted-foreground">
                                    Measures Taken
                                </span>
                                <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">
                                    {breach.measures_taken}
                                </p>
                            </div>
                        )}
                        {breach.resolution_notes && (
                            <div>
                                <span className="text-xs text-muted-foreground">
                                    Resolution Notes
                                </span>
                                <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">
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
                            {breach.requires_authority_notification &&
                                !breach.authority_notified_at && (
                                    <Button
                                        size="sm"
                                        variant="destructive"
                                        data-test="privacy-breach-notify-opc"
                                        onClick={() =>
                                            setAction('notify-opc')
                                        }
                                    >
                                        Record OPC Notification
                                    </Button>
                                )}
                            {breach.requires_subject_notification &&
                                !breach.subjects_notified_at && (
                                    <Button
                                        size="sm"
                                        data-test="privacy-breach-notify-subjects"
                                        onClick={() =>
                                            setAction('notify-subjects')
                                        }
                                    >
                                        Record Subject Notification
                                    </Button>
                                )}
                            <Button
                                size="sm"
                                variant="outline"
                                data-test="privacy-breach-resolve"
                                onClick={() => setAction('resolve')}
                            >
                                Mark Resolved
                            </Button>
                        </CardContent>
                    </Card>
                )}

                <PrivacyFieldDialog
                    open={action === 'notify-opc'}
                    onClose={closeAction}
                    title="Record that you've notified the Privacy Commissioner?"
                    description={
                        <>
                            <p>
                                Do this once you've reported the breach to the
                                Office of the Privacy Commissioner, for example
                                through NotifyUs. It doesn't send anything to
                                the Commissioner for you.
                            </p>
                            <p>{NOTIFIED_EFFECT}</p>
                        </>
                    }
                    fields={[
                        {
                            name: 'authority_reference',
                            label: "Privacy Commissioner's reference",
                            kind: 'text',
                            maxLength: 255,
                            hint: "Add it now if you have one. It can't be added later.",
                            placeholder: 'For example, the NotifyUs reference',
                        },
                    ]}
                    submitLabel="Record notification"
                    variant="default"
                    url={`/privacy/breaches/${breach.id}/notify-opc`}
                />
                <PrivacyFieldDialog
                    open={action === 'notify-subjects'}
                    onClose={closeAction}
                    title="Record that the affected people were told?"
                    description={
                        <>
                            <p>
                                Do this once you've told the people affected by
                                this breach. It doesn't contact them for you.
                            </p>
                            <p>{NOTIFIED_EFFECT}</p>
                        </>
                    }
                    fields={[
                        {
                            name: 'notification_method',
                            label: 'How you told them',
                            kind: 'text',
                            required: 'Say how you told the affected people.',
                            maxLength: 255,
                            placeholder: 'For example, letter and phone call',
                        },
                    ]}
                    submitLabel="Record notification"
                    variant="default"
                    url={`/privacy/breaches/${breach.id}/notify-subjects`}
                />
                <PrivacyFieldDialog
                    open={action === 'resolve'}
                    onClose={closeAction}
                    title="Mark this breach as resolved?"
                    description="The breach is closed with today's date and your notes, and the actions on this page are removed. No one is notified."
                    fields={[
                        {
                            name: 'resolution_notes',
                            label: 'Resolution notes',
                            kind: 'textarea',
                            required: 'Add resolution notes.',
                            placeholder:
                                "How the breach was contained and what's changed so it doesn't happen again",
                        },
                    ]}
                    submitLabel="Mark resolved"
                    variant="default"
                    url={`/privacy/breaches/${breach.id}/resolve`}
                />
            </PageLayout>
        </AppLayout>
    );
}
