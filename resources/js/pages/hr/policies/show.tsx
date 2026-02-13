import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Head, Link, router } from '@inertiajs/react';
import { FileText, CheckCircle, ShieldCheck, Clock, History, Edit, Eye, Download } from 'lucide-react';

type BreadcrumbItem = { title: string; href: string };

type PolicyVersion = {
    id: number;
    version_number: string;
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
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
};

const formatDateTime = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const getCategoryColor = (category: string) => {
    const colors: Record<string, string> = {
        'employment': 'bg-blue-100 text-blue-800 border-blue-200',
        'health_and_safety': 'bg-green-100 text-green-800 border-green-200',
        'safeguarding': 'bg-purple-100 text-purple-800 border-purple-200',
        'data_protection': 'bg-amber-100 text-amber-800 border-amber-200',
        'conduct': 'bg-red-100 text-red-800 border-red-200',
        'leave': 'bg-teal-100 text-teal-800 border-teal-200',
        'training': 'bg-indigo-100 text-indigo-800 border-indigo-200',
        'general': 'bg-slate-100 text-slate-800 border-slate-200',
    };
    return colors[category] || 'bg-slate-100 text-slate-800 border-slate-200';
};

export default function PolicyShow({ policy, attestationStats, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Policies', href: '/hr/policies' },
        { title: policy.title, href: `/hr/policies/${policy.id}` },
    ];

    const handleAttest = () => {
        if (confirm('By clicking confirm, you attest that you have read and understood this policy.')) {
            router.post(`/hr/policies/${policy.id}/attest`);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={policy.title} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-lg font-semibold">
                            <FileText className="h-5 w-5 text-slate-500" />
                            {policy.title}
                        </h1>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge className={getCategoryColor(policy.category)}>
                                {policy.category.replace(/_/g, ' ')}
                            </Badge>
                            {policy.is_active ? (
                                <Badge className="bg-green-100 text-green-800 border-green-200">
                                    <CheckCircle className="mr-1 h-3 w-3" />
                                    Active
                                </Badge>
                            ) : (
                                <Badge variant="outline" className="text-slate-500">Inactive</Badge>
                            )}
                            {policy.requires_attestation && (
                                <Badge variant="outline" className="border-amber-200 bg-amber-50 text-amber-700">
                                    <ShieldCheck className="mr-1 h-3 w-3" />
                                    Attestation Required
                                </Badge>
                            )}
                            {policy.currentVersion && (
                                <Badge variant="outline">
                                    v{policy.currentVersion.version_number}
                                </Badge>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link href="/hr/policies" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                            Back to list
                        </Link>
                        {policy.currentVersion?.document_path && (
                            <>
                                <Link href={`/hr/policies/${policy.id}/download`} target="_blank">
                                    <Button size="sm" variant="outline">
                                        <Eye className="mr-1.5 h-4 w-4" />
                                        View Document
                                    </Button>
                                </Link>
                                <Link href={`/hr/policies/${policy.id}/download`} download>
                                    <Button size="sm" variant="outline">
                                        <Download className="mr-1.5 h-4 w-4" />
                                        Download
                                    </Button>
                                </Link>
                            </>
                        )}
                        {can.manage && (
                            <Link href={`/hr/policies/${policy.id}/edit`}>
                                <Button size="sm" variant="outline">
                                    <Edit className="mr-1.5 h-4 w-4" />
                                    Edit Policy
                                </Button>
                            </Link>
                        )}
                        {can.attest && policy.requires_attestation && (
                            <Button size="sm" onClick={handleAttest}>
                                <ShieldCheck className="mr-1.5 h-4 w-4" />
                                I Attest
                            </Button>
                        )}
                    </div>
                </div>

                {policy.description && (
                    <Card>
                        <CardContent className="pt-6">
                            <div className="text-sm text-slate-600">{policy.description}</div>
                        </CardContent>
                    </Card>
                )}

                {policy.requires_attestation && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-slate-500">Attestations Completed</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{attestationStats.total}</div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-slate-500">Required</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-center gap-2">
                                    <div className="text-2xl font-bold">{attestationStats.requires}</div>
                                    {attestationStats.total < attestationStats.requires && (
                                        <span className="text-sm text-amber-600">
                                            ({attestationStats.requires - attestationStats.total} outstanding)
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
                                <FileText className="h-5 w-5 text-blue-500" />
                                Current Version (v{policy.currentVersion.version_number})
                            </CardTitle>
                            <div className="text-xs text-slate-500">
                                Effective from {formatDate(policy.currentVersion.effective_from)}
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div
                                className="prose prose-sm max-w-none text-slate-700"
                                dangerouslySetInnerHTML={{ __html: policy.currentVersion.content }}
                            />
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <History className="h-5 w-5 text-purple-500" />
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
                                            {policy.currentVersion?.id === version.id && (
                                                <Badge className="ml-2 bg-green-100 text-green-800 border-green-200">
                                                    Current
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>{formatDate(version.effective_from)}</TableCell>
                                        <TableCell className="max-w-xs truncate text-sm text-slate-600">
                                            {version.change_summary || 'No summary provided'}
                                        </TableCell>
                                        <TableCell>{formatDate(version.created_at)}</TableCell>
                                    </TableRow>
                                ))}
                                {!policy.versions.length && (
                                    <TableRow>
                                        <TableCell colSpan={4} className="py-6 text-center text-sm text-slate-500">
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
                                <ShieldCheck className="h-5 w-5 text-amber-500" />
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
                                            <TableCell className="font-medium">{att.user.name}</TableCell>
                                            <TableCell>v{att.policy_version.version_number}</TableCell>
                                            <TableCell>{formatDateTime(att.attested_at)}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
