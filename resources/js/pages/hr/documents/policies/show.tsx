import { NewVersionWizard } from '@/components/hr/policy-wizards';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
    FilePlus2,
    FileText,
    History,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';

type BreadcrumbItem = { title: string; href: string };

type PolicyVersion = {
    id: number;
    version_number: string;
    document_path?: string | null;
    effective_from: string;
    content_summary: string | null;
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
    current_version: PolicyVersion | null;
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
        { title: 'Policies', href: '/hr/documents/policies' },
        { title: policy.title, href: `/hr/documents/policies/${policy.id}` },
    ];

    const [attesting, setAttesting] = useState(false);
    const [attestBusy, setAttestBusy] = useState(false);
    const [versionWizard, setVersionWizard] = useState(false);
    const [deletingVersion, setDeletingVersion] = useState<PolicyVersion | null>(null);
    const [deleteBusy, setDeleteBusy] = useState(false);

    const nextVersion =
        policy.versions.reduce(
            (max, v) => Math.max(max, Number(v.version_number) || 0),
            0,
        ) + 1;

    const confirmAttest = () => {
        setAttestBusy(true);
        router.post(
            `/hr/documents/policies/${policy.id}/attest`,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setAttestBusy(false);
                    setAttesting(false);
                },
            },
        );
    };

    const confirmDeleteVersion = () => {
        if (!deletingVersion) return;
        setDeleteBusy(true);
        router.delete(
            `/hr/documents/policies/${policy.id}/versions/${deletingVersion.id}`,
            {
                preserveScroll: true,
                onFinish: () => {
                    setDeleteBusy(false);
                    setDeletingVersion(null);
                },
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={policy.title} />

            <PageLayout
                hero={
                    <PageHero category="hr"
                        variant="compact"
                        backHref="/hr/documents/policies"
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
                                {policy.current_version && (
                                    <Badge variant="outline">
                                        v
                                        {policy.current_version.version_number}
                                    </Badge>
                                )}
                            </span>
                        }
                        actions={
                            <>
                                {policy.current_version?.document_path && (
                                    <>
                                        <Link
                                            href={`/hr/documents/policies/${policy.id}/download`}
                                            target="_blank"
                                        >
                                            <Button size="sm" variant="outline">
                                                <Eye className="mr-1.5 h-4 w-4" />
                                                View Document
                                            </Button>
                                        </Link>
                                        <Link
                                            href={`/hr/documents/policies/${policy.id}/download`}
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
                                    <>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setVersionWizard(true)}
                                        >
                                            <FilePlus2 className="mr-1.5 h-4 w-4" />
                                            New Version
                                        </Button>
                                        <Link
                                            href={`/hr/documents/policies/${policy.id}/edit`}
                                        >
                                            <Button size="sm" variant="outline">
                                                <Edit className="mr-1.5 h-4 w-4" />
                                                Edit Policy
                                            </Button>
                                        </Link>
                                    </>
                                )}
                                {can.attest &&
                                    policy.requires_attestation && (
                                        <Button
                                            size="sm"
                                            onClick={() => setAttesting(true)}
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

                {policy.current_version?.content_summary && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <FileText className="h-5 w-5 text-status-info" />
                                Current Version (v
                                {policy.current_version.version_number})
                            </CardTitle>
                            <div className="text-xs text-muted-foreground">
                                Effective from{' '}
                                {formatDate(
                                    policy.current_version.effective_from,
                                )}
                            </div>
                        </CardHeader>
                        <CardContent>
                            {/* Plain-text summary — rendered as text (not HTML) to
                                avoid XSS; the authoritative policy is the PDF. */}
                            <p className="whitespace-pre-wrap text-sm text-foreground">
                                {policy.current_version.content_summary}
                            </p>
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
                                    {can.manage && (
                                        <TableHead className="w-16"></TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {policy.versions.map((version) => (
                                    <TableRow key={version.id}>
                                        <TableCell className="font-medium">
                                            v{version.version_number}
                                            {policy.current_version?.id ===
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
                                            {version.content_summary ||
                                                'No summary provided'}
                                        </TableCell>
                                        <TableCell>
                                            {formatDate(version.created_at)}
                                        </TableCell>
                                        {can.manage && (
                                            <TableCell className="text-right">
                                                {policy.current_version?.id !==
                                                    version.id && (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="h-7 w-7 text-muted-foreground hover:text-status-critical"
                                                        aria-label={`Delete version ${version.version_number}`}
                                                        onClick={() =>
                                                            setDeletingVersion(
                                                                version,
                                                            )
                                                        }
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </Button>
                                                )}
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                                {!policy.versions.length && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={can.manage ? 5 : 4}
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
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <ShieldCheck className="h-5 w-5 text-status-warning" />
                                Recent Attestations
                            </CardTitle>
                            <Link
                                href={`/hr/documents/policies/attestations?policy_id=${policy.id}`}
                                className="text-xs text-primary hover:underline"
                            >
                                View all attestations
                            </Link>
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

            {versionWizard ? (
                <NewVersionWizard
                    policyId={policy.id}
                    policyTitle={policy.title}
                    nextVersion={nextVersion}
                    onClose={() => setVersionWizard(false)}
                />
            ) : null}

            <Dialog
                open={attesting}
                onOpenChange={(open) => {
                    if (!open) setAttesting(false);
                }}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Attest to this policy</DialogTitle>
                        <DialogDescription>
                            By confirming, you attest that you have read and
                            understood “{policy.title}”
                            {policy.current_version
                                ? ` (v${policy.current_version.version_number})`
                                : ''}
                            . Your sign-off is recorded with a timestamp for the
                            audit trail.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setAttesting(false)}
                            disabled={attestBusy}
                        >
                            Cancel
                        </Button>
                        <Button onClick={confirmAttest} disabled={attestBusy}>
                            <ShieldCheck className="mr-1.5 h-4 w-4" />
                            {attestBusy ? 'Recording…' : 'I attest'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={deletingVersion !== null}
                onOpenChange={(open) => {
                    if (!open) setDeletingVersion(null);
                }}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Delete version</DialogTitle>
                        <DialogDescription>
                            Deleting v{deletingVersion?.version_number} removes
                            this version and its stored PDF permanently. The
                            current version cannot be deleted.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setDeletingVersion(null)}
                            disabled={deleteBusy}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={confirmDeleteVersion}
                            disabled={deleteBusy}
                        >
                            <Trash2 className="mr-1.5 h-4 w-4" />
                            {deleteBusy ? 'Deleting…' : 'Delete version'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
