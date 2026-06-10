import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    CheckCircle,
    Download,
    Edit,
    Eye,
    FileText,
    History,
    ShieldCheck,
} from 'lucide-react';

type BreadcrumbItem = { title: string; href: string };

type PolicyVersion = {
    id: number;
    version_number: string;
    document_path?: string | null;
    effective_from: string;
    content: string;
    change_summary: string | null;
    created_at: string;
};

type Attestation = {
    id: number;
    user: { id: number; name: string };
    policy_version: { version_number: string };
    attested_at: string;
};

type Policy = {
    id: number;
    title: string;
    slug: string;
    category: string;
    is_active: boolean;
    requires_attestation: boolean;
    description: string | null;
    currentVersion: PolicyVersion | null;
    versions: PolicyVersion[];
    attestations: Attestation[];
};

type Props = {
    policy: Policy;
    attestationStats: {
        total: number;
        requires: number;
    };
    can: {
        manage: boolean;
        attest: boolean;
    };
};

const formatDate = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
};

const formatDateTime = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          });
};

const getCategoryColor = (category: string) => {
    const colors: Record<string, string> = {
        employment: 'bg-status-info-bg text-status-info border-status-info/30',
        health_and_safety:
            'bg-status-success-bg text-status-success border-status-success/30',
        safeguarding: 'bg-primary/10 text-primary border-primary',
        data_protection:
            'bg-status-warning-bg text-status-warning border-status-warning/30',
        conduct:
            'bg-status-critical-bg text-status-critical border-status-critical/30',
        leave: 'bg-status-info-bg text-status-info border-status-info/30',
        training: 'bg-primary/10 text-primary border-primary',
        general: 'bg-muted text-foreground border-border',
    };
    return colors[category] || 'bg-muted text-foreground border-border';
};

export default function PolicyShow({ policy, attestationStats, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Policies', href: '/hr/policies' },
        { title: policy.title, href: `/hr/policies/${policy.id}` },
    ];

    const handleAttest = () => {
        if (
            confirm(
                'By clicking confirm, you attest that you have read and understood this policy.',
            )
        ) {
            router.post(`/hr/policies/${policy.id}/attest`);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={policy.title} />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/hr/policies"
                        title={
                            <span className="flex items-center gap-2">
                                <FileText className="h-5 w-5 text-muted-foreground" />
                                {policy.title}
                            </span>
                        }
                        description={
                            <span className="flex flex-wrap gap-2">
                                <Badge
                                    className={getCategoryColor(
                                        policy.category,
                                    )}
                                >
                                    {policy.category.replace(/_/g, ' ')}
                                </Badge>
                                {policy.is_active ? (
                                    <Badge className="border-status-success/30 bg-status-success-bg text-status-success">
                                        <CheckCircle className="mr-1 h-3 w-3" />
                                        Active
                                    </Badge>
                                ) : (
                                    <Badge
                                        variant="outline"
                                        className="text-muted-foreground"
                                    >
                                        Inactive
                                    </Badge>
                                )}
                                {policy.requires_attestation && (
                                    <Badge
                                        variant="outline"
                                        className="border-status-warning/30 bg-status-warning-bg text-status-warning"
                                    >
                                        <ShieldCheck className="mr-1 h-3 w-3" />
                                        Attestation Required
                                    </Badge>
                                )}
                                {policy.currentVersion && (
                                    <Badge variant="outline">
                                        v
                                        {policy.currentVersion.version_number}
                                    </Badge>
                                )}
                            </span>
                        }
                        actions={
                            <>
                                {policy.currentVersion?.document_path && (
                                    <>
                                        <Link
                                            href={`/hr/policies/${policy.id}/download`}
                                            target="_blank"
                                        >
                                            <Button size="sm" variant="outline">
                                                <Eye className="mr-1.5 h-4 w-4" />
                                                View Document
                                            </Button>
                                        </Link>
                                        <Link
                                            href={`/hr/policies/${policy.id}/download`}
                                            download
                                        >
                                            <Button size="sm" variant="outline">
                                                <Download className="mr-1.5 h-4 w-4" />
                                                Download
                                            </Button>
                                        </Link>
                                    </>
                                )}
                                {can.manage && (
                                    <Link
                                        href={`/hr/policies/${policy.id}/edit`}
                                    >
                                        <Button size="sm" variant="outline">
                                            <Edit className="mr-1.5 h-4 w-4" />
                                            Edit Policy
                                        </Button>
                                    </Link>
                                )}
                                {can.attest &&
                                    policy.requires_attestation && (
                                        <Button
                                            size="sm"
                                            onClick={handleAttest}
                                        >
                                            <ShieldCheck className="mr-1.5 h-4 w-4" />
                                            I Attest
                                        </Button>
                                    )}
                            </>
                        }
                    />
                }
            >
                {policy.description && (
                    <Card>
                        <CardContent className="pt-6">
                            <div className="text-sm text-muted-foreground">
                                {policy.description}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {policy.requires_attestation && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    Attestations Completed
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">
                                    {attestationStats.total}
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    Required
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-center gap-2">
                                    <div className="text-2xl font-bold">
                                        {attestationStats.requires}
                                    </div>
                                    {attestationStats.total <
                                        attestationStats.requires && (
                                        <span className="text-sm text-status-warning">
                                            (
                                            {attestationStats.requires -
                                                attestationStats.total}{' '}
                                            outstanding)
                                        </span>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {policy.currentVersion && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <FileText className="h-5 w-5 text-status-info" />
                                Current Version (v
                                {policy.currentVersion.version_number})
                            </CardTitle>
                            <div className="text-xs text-muted-foreground">
                                Effective from{' '}
                                {formatDate(
                                    policy.currentVersion.effective_from,
                                )}
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div
                                className="prose prose-sm max-w-none text-foreground"
                                dangerouslySetInnerHTML={{
                                    __html: policy.currentVersion.content,
                                }}
                            />
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <History className="h-5 w-5 text-primary" />
                            Version History
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Version</TableHead>
                                    <TableHead>Effective From</TableHead>
                                    <TableHead>Changes</TableHead>
                                    <TableHead>Created</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {policy.versions.map((version) => (
                                    <TableRow key={version.id}>
                                        <TableCell className="font-medium">
                                            v{version.version_number}
                                            {policy.currentVersion?.id ===
                                                version.id && (
                                                <Badge className="ml-2 border-status-success/30 bg-status-success-bg text-status-success">
                                                    Current
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {formatDate(version.effective_from)}
                                        </TableCell>
                                        <TableCell className="max-w-xs truncate text-sm text-muted-foreground">
                                            {version.change_summary ||
                                                'No summary provided'}
                                        </TableCell>
                                        <TableCell>
                                            {formatDate(version.created_at)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!policy.versions.length && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={4}
                                            className="py-6 text-center text-sm text-muted-foreground"
                                        >
                                            No version history available.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {policy.attestations.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <ShieldCheck className="h-5 w-5 text-status-warning" />
                                Recent Attestations
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Staff Member</TableHead>
                                        <TableHead>Version</TableHead>
                                        <TableHead>Attested At</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {policy.attestations.map((att) => (
                                        <TableRow key={att.id}>
                                            <TableCell className="font-medium">
                                                {att.user.name}
                                            </TableCell>
                                            <TableCell>
                                                v
                                                {
                                                    att.policy_version
                                                        .version_number
                                                }
                                            </TableCell>
                                            <TableCell>
                                                {formatDateTime(
                                                    att.attested_at,
                                                )}
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
