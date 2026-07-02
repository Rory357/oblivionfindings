import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { MyHrShell, type MyHrShellData } from '@/components/hr';
import { router } from '@inertiajs/react';
import { Eye, FileText, ShieldCheck } from 'lucide-react';

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
    attest_by: string | null;
    attest_overdue: boolean;
}

interface Props {
    myHr: MyHrShellData;
    policies: Policy[];
}

export default function MyPolicies({ myHr, policies }: Props) {
    function handleAttest(policyId: number) {
        if (
            confirm(
                'By clicking confirm, you attest that you have read and understood this policy.',
            )
        ) {
            router.post(
                `/hr/my/policies/${policyId}/attest`,
                {},
                { preserveScroll: true },
            );
        }
    }

    return (
        <MyHrShell active="policies" myHr={myHr} title="Policies · My HR">
            {/* Policies List */}
                <Card>
                    <CardHeader>
                        <CardTitle>Policies Requiring Attestation</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Policy
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Category
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Version
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {policies.map((policy) => {
                                    const currentVersion = policy.versions[0];
                                    return (
                                        <tr
                                            key={policy.id}
                                            className="hover:bg-muted/30"
                                        >
                                            <td className="px-4 py-3">
                                                <p className="font-medium">
                                                    {policy.title}
                                                </p>
                                                {policy.description && (
                                                    <p className="text-xs text-muted-foreground">
                                                        {policy.description}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge variant="outline">
                                                    {policy.category}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {currentVersion ? (
                                                    <span>
                                                        v
                                                        {
                                                            currentVersion.version_number
                                                        }
                                                    </span>
                                                ) : (
                                                    '\u2014'
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                {policy.is_attested ? (
                                                    <Badge
                                                        variant="outline"
                                                        className="border-status-success/30 bg-status-success-bg text-status-success-foreground"
                                                    >
                                                        <ShieldCheck className="mr-1 h-3 w-3" />
                                                        Attested
                                                    </Badge>
                                                ) : (
                                                    <div className="flex flex-col items-start gap-1">
                                                        <Badge
                                                            variant="outline"
                                                            className={
                                                                policy.attest_overdue
                                                                    ? 'border-status-critical/30 bg-status-critical-bg text-status-critical'
                                                                    : 'border-status-warning/30 bg-status-warning-bg text-status-warning-foreground'
                                                            }
                                                        >
                                                            {policy.attest_overdue
                                                                ? 'Overdue'
                                                                : 'Pending'}
                                                        </Badge>
                                                        {policy.attest_by && (
                                                            <span
                                                                className={`text-xs ${
                                                                    policy.attest_overdue
                                                                        ? 'font-medium text-status-critical'
                                                                        : 'text-muted-foreground'
                                                                }`}
                                                            >
                                                                Attest by{' '}
                                                                {new Date(
                                                                    policy.attest_by,
                                                                ).toLocaleDateString(
                                                                    'en-NZ',
                                                                    {
                                                                        day: 'numeric',
                                                                        month: 'short',
                                                                        year: 'numeric',
                                                                    },
                                                                )}
                                                            </span>
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    {currentVersion && (
                                                        <Dialog>
                                                            <DialogTrigger
                                                                asChild
                                                            >
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                >
                                                                    <Eye className="mr-1.5 h-4 w-4" />
                                                                    View
                                                                </Button>
                                                            </DialogTrigger>
                                                            <DialogContent className="h-[80vh] max-w-4xl">
                                                                <DialogHeader>
                                                                    <DialogTitle className="flex items-center gap-2">
                                                                        <FileText className="h-5 w-5" />
                                                                        {
                                                                            policy.title
                                                                        }
                                                                    </DialogTitle>
                                                                </DialogHeader>
                                                                <div className="h-full min-h-[60vh] flex-1 overflow-hidden rounded-lg bg-muted">
                                                                    {currentVersion.document_path ? (
                                                                        <iframe
                                                                            src={`/hr/documents/policies/${policy.id}/download`}
                                                                            className="h-full w-full"
                                                                            title={
                                                                                policy.title
                                                                            }
                                                                        />
                                                                    ) : (
                                                                        <div className="overflow-auto p-6">
                                                                            <div className="prose max-w-none">
                                                                                {currentVersion.content_summary ||
                                                                                    'No content available.'}
                                                                            </div>
                                                                        </div>
                                                                    )}
                                                                </div>
                                                                {!policy.is_attested && (
                                                                    <div className="flex justify-end gap-2 border-t pt-4">
                                                                        <Button
                                                                            variant="default"
                                                                            onClick={() =>
                                                                                handleAttest(
                                                                                    policy.id,
                                                                                )
                                                                            }
                                                                        >
                                                                            <ShieldCheck className="mr-2 h-4 w-4" />
                                                                            I
                                                                            have
                                                                            read
                                                                            and
                                                                            attest
                                                                            to
                                                                            this
                                                                            policy
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
                                        <td
                                            colSpan={5}
                                            className="px-4 py-8 text-center text-muted-foreground"
                                        >
                                            No policies require your
                                            attestation.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
        </MyHrShell>
    );
}
