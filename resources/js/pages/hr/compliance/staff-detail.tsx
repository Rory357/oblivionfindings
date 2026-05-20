import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHero, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    ExternalLink,
    Shield,
    User,
    XCircle,
} from 'lucide-react';

interface ComplianceStatus {
    id: number;
    requirement_id: number;
    requirement_name: string;
    requirement_type: string;
    renewal_period_months: number | null;
    status: 'compliant' | 'expiring_soon' | 'expired' | 'not_started';
    expiry_date: string | null;
    completed_date: string | null;
    evidence_url: string | null;
    evidence_notes: string | null;
    is_mandatory: boolean;
}

interface Staff {
    id: number;
    name: string;
    email: string;
}

interface Summary {
    compliant: number;
    expiring_soon: number;
    expired: number;
    not_started: number;
}

interface Props {
    staff: Staff;
    complianceStatuses: ComplianceStatus[];
    summary: Summary;
}

const statusConfig: Record<
    string,
    {
        label: string;
        variant: 'default' | 'secondary' | 'destructive' | 'outline';
        icon: typeof CheckCircle2;
        color: string;
    }
> = {
    compliant: {
        label: 'Compliant',
        variant: 'default',
        icon: CheckCircle2,
        color: 'text-status-success',
    },
    expiring_soon: {
        label: 'Expiring Soon',
        variant: 'outline',
        icon: Clock,
        color: 'text-status-warning',
    },
    expired: {
        label: 'Expired',
        variant: 'destructive',
        icon: AlertTriangle,
        color: 'text-destructive',
    },
    not_started: {
        label: 'Not Started',
        variant: 'secondary',
        icon: XCircle,
        color: 'text-muted-foreground',
    },
};

export default function StaffComplianceDetail({
    staff,
    complianceStatuses,
    summary,
}: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr/people' },
        { title: 'Compliance', href: '/hr/compliance' },
        { title: staff.name, href: `/hr/compliance/staff/${staff.id}` },
    ];

    const total =
        summary.compliant +
        summary.expiring_soon +
        summary.expired +
        summary.not_started;
    const compliancePercent =
        total > 0 ? Math.round((summary.compliant / total) * 100) : 0;

    // Group by status for organized display
    const groupedStatuses = {
        expired: complianceStatuses.filter((s) => s.status === 'expired'),
        expiring_soon: complianceStatuses.filter(
            (s) => s.status === 'expiring_soon',
        ),
        not_started: complianceStatuses.filter(
            (s) => s.status === 'not_started',
        ),
        compliant: complianceStatuses.filter((s) => s.status === 'compliant'),
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Compliance - ${staff.name}`} />
            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/hr/compliance"
                        title={staff.name}
                        description={staff.email}
                        actions={
                            <span className="rounded-full bg-primary/10 px-3 py-1 text-sm font-medium text-primary">
                                {compliancePercent}% Compliant
                            </span>
                        }
                    />
                }
            >
                {/* Summary Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <Card>
                        <CardContent className="pt-6 text-center">
                            <Shield className="mx-auto h-6 w-6 text-primary" />
                            <p className="mt-2 text-2xl font-bold">
                                {compliancePercent}%
                            </p>
                            <p className="text-sm text-muted-foreground">
                                Overall
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6 text-center">
                            <CheckCircle2 className="mx-auto h-6 w-6 text-status-success" />
                            <p className="mt-2 text-2xl font-bold">
                                {summary.compliant}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                Compliant
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6 text-center">
                            <Clock className="mx-auto h-6 w-6 text-status-warning" />
                            <p className="mt-2 text-2xl font-bold">
                                {summary.expiring_soon}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                Expiring Soon
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6 text-center">
                            <AlertTriangle className="mx-auto h-6 w-6 text-destructive" />
                            <p className="mt-2 text-2xl font-bold">
                                {summary.expired}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                Expired
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6 text-center">
                            <XCircle className="mx-auto h-6 w-6 text-muted-foreground" />
                            <p className="mt-2 text-2xl font-bold">
                                {summary.not_started}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                Not Started
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Compliance Items */}
                {(
                    [
                        'expired',
                        'expiring_soon',
                        'not_started',
                        'compliant',
                    ] as const
                ).map((statusKey) => {
                    const items = groupedStatuses[statusKey];
                    if (items.length === 0) return null;
                    const config = statusConfig[statusKey];
                    const StatusIcon = config.icon;

                    return (
                        <Card key={statusKey}>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <StatusIcon
                                        className={`h-5 w-5 ${config.color}`}
                                    />
                                    {config.label} ({items.length})
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead className="border-b bg-muted/50">
                                        <tr>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Requirement
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Type
                                            </th>
                                            <th className="px-4 py-3 text-center font-medium">
                                                Priority
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Completed
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Expiry
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Renewal
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Evidence
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {items.map((item) => (
                                            <tr
                                                key={item.id}
                                                className="hover:bg-muted/30"
                                            >
                                                <td className="px-4 py-3">
                                                    <span className="font-medium">
                                                        {item.requirement_name}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Badge
                                                        variant="outline"
                                                        className="text-xs capitalize"
                                                    >
                                                        {item.requirement_type.replace(
                                                            '_',
                                                            ' ',
                                                        )}
                                                    </Badge>
                                                </td>
                                                <td className="px-4 py-3 text-center">
                                                    {item.is_mandatory ? (
                                                        <Badge
                                                            variant="default"
                                                            className="text-xs"
                                                        >
                                                            Required
                                                        </Badge>
                                                    ) : (
                                                        <Badge
                                                            variant="secondary"
                                                            className="text-xs"
                                                        >
                                                            Optional
                                                        </Badge>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {item.completed_date ||
                                                        '\u2014'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {item.expiry_date ? (
                                                        <span
                                                            className={
                                                                item.status ===
                                                                'expired'
                                                                    ? 'font-medium text-destructive'
                                                                    : item.status ===
                                                                        'expiring_soon'
                                                                      ? 'font-medium text-status-warning'
                                                                      : 'text-muted-foreground'
                                                            }
                                                        >
                                                            {item.expiry_date}
                                                        </span>
                                                    ) : (
                                                        '\u2014'
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {item.renewal_period_months
                                                        ? `${item.renewal_period_months} months`
                                                        : '\u2014'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {item.evidence_url ? (
                                                        <a
                                                            href={
                                                                item.evidence_url
                                                            }
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="inline-flex items-center gap-1 text-primary hover:underline"
                                                        >
                                                            View
                                                            <ExternalLink className="h-3 w-3" />
                                                        </a>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            \u2014
                                                        </span>
                                                    )}
                                                    {item.evidence_notes && (
                                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                                            {
                                                                item.evidence_notes
                                                            }
                                                        </p>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    );
                })}

                {complianceStatuses.length === 0 && (
                    <Card>
                        <CardContent className="py-8 text-center text-muted-foreground">
                            <Shield className="mx-auto mb-3 h-12 w-12 opacity-50" />
                            <p>
                                No compliance requirements assigned to this
                                staff member.
                            </p>
                        </CardContent>
                    </Card>
                )}
            </PageLayout>
        </AppLayout>
    );
}
