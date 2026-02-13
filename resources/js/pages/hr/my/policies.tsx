import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { type BreadcrumbItem } from '@/types';
import { ShieldCheck, FileText, Eye } from 'lucide-react';

interface PolicyVersion {
    id: number;
    version_number: string;
    document_path: string | null;
    content_summary: string | null;
    effective_from: string;
}

interface Policy {
    id: number;
    title: string;
    description: string | null;
    category: string;
    versions: PolicyVersion[];
    my_attestation: {
        id: number;
        attested_at: string;
    } | null;
    is_attested: boolean;
}

interface Props {
    policies: Policy[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/my' },
    { title: 'My HR', href: '/hr/my' },
    { title: 'My Policies', href: '/hr/my/policies' },
];

export default function MyPolicies({ policies }: Props) {
    function handleAttest(policyId: number) {
        if (confirm('By clicking confirm, you attest that you have read and understood this policy.')) {
            router.post(`/hr/my/policies/${policyId}/attest`, {}, { preserveScroll: true });
        }
    }

    const attestedCount = policies.filter((p) => p.is_attested).length;
    const pendingCount = policies.filter((p) => !p.is_attested).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My Policies" />
            <div className="flex flex-col gap-6 p-6">
                <h1 className="text-2xl font-bold">My Policies</h1>

                {/* Summary */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Total Policies</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">{policies.length}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Attested</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold text-emerald-500">{attestedCount}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Pending Attestation</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold text-yellow-500">{pendingCount}</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Policies List */}
                <Card>
                    <CardHeader>
                        <CardTitle>Policies Requiring Attestation</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Policy</th>
                                    <th className="px-4 py-3 text-left font-medium">Category</th>
                                    <th className="px-4 py-3 text-left font-medium">Version</th>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                    <th className="px-4 py-3 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {policies.map((policy) => {
                                    const currentVersion = policy.versions[0];
                                    return (
                                    <tr key={policy.id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3">
                                            <p className="font-medium">{policy.title}</p>
                                            {policy.description && (
                                                <p className="text-xs text-muted-foreground">{policy.description}</p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge variant="outline">{policy.category}</Badge>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {currentVersion ? (
                                                <span>
                                                    v{currentVersion.version_number}
                                                </span>
                                            ) : (
                                                '\u2014'
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {policy.is_attested ? (
                                                <Badge variant="outline" className="border-emerald-500/30 text-emerald-400 bg-emerald-500/10">
                                                    <ShieldCheck className="mr-1 h-3 w-3" />
                                                    Attested
                                                </Badge>
                                            ) : (
                                                <Badge variant="outline" className="border-yellow-500/30 text-yellow-400 bg-yellow-500/10">
                                                    Pending
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex items-center justify-end gap-2">
                                                {currentVersion && (
                                                    <Dialog>
                                                        <DialogTrigger asChild>
                                                            <Button variant="outline" size="sm">
                                                                <Eye className="mr-1.5 h-4 w-4" />
                                                                View
                                                            </Button>
                                                        </DialogTrigger>
                                                        <DialogContent className="max-w-4xl h-[80vh]">
                                                            <DialogHeader>
                                                                <DialogTitle className="flex items-center gap-2">
                                                                    <FileText className="h-5 w-5" />
                                                                    {policy.title}
                                                                </DialogTitle>
                                                            </DialogHeader>
                                                            <div className="flex-1 h-full min-h-[60vh] bg-slate-100 rounded-lg overflow-hidden">
                                                                {currentVersion.document_path ? (
                                                                    <iframe
                                                                        src={`/hr/policies/${policy.id}/download`}
                                                                        className="w-full h-full"
                                                                        title={policy.title}
                                                                    />
                                                                ) : (
                                                                    <div className="p-6 overflow-auto">
                                                                        <div className="prose max-w-none">
                                                                            {currentVersion.content_summary || 'No content available.'}
                                                                        </div>
                                                                    </div>
                                                                )}
                                                            </div>
                                                            {!policy.is_attested && (
                                                                <div className="flex justify-end gap-2 pt-4 border-t">
                                                                    <Button
                                                                        variant="default"
                                                                        onClick={() => handleAttest(policy.id)}
                                                                    >
                                                                        <ShieldCheck className="mr-2 h-4 w-4" />
                                                                        I have read and attest to this policy
                                                                    </Button>
                                                                </div>
                                                            )}
                                                        </DialogContent>
                                                    </Dialog>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                    );
                                })}
                                {policies.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-8 text-center text-muted-foreground">
                                            No policies require your attestation.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
