import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { governanceStatusColor } from '@/lib/governance-status';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { BookOpen, CheckCircle, Shield } from 'lucide-react';

interface Attestation {
    id: number;
    user: { id: number; name: string };
    version: number;
    attested_at: string;
    notes: string | null;
}

interface Policy {
    id: number;
    title: string;
    category: string;
    description: string | null;
    content: string;
    version: number;
    status: string;
    effective_date: string;
    review_date: string;
    requires_attestation: boolean;
    approved_by_user: { name: string } | null;
    approved_at: string | null;
    attestations: Attestation[];
}

interface Props extends PageProps {
    policy: Policy;
    attestationStats: { total_required: number; completed: number };
    canEdit: boolean;
}

export default function PolicyShow({
    auth,
    policy,
    attestationStats,
    canEdit,
}: Props) {
    const attestForm = useForm({ acknowledged: false, notes: '' });

    const handleAttest = (e: React.FormEvent) => {
        e.preventDefault();
        attestForm.post(`/governance/policies/${policy.id}/attest`);
    };

    const handleApprove = () => {
        router.post(`/governance/policies/${policy.id}/approve`);
    };

    const getStatusColor = (status: string) => governanceStatusColor(status);

    return (
        <AppLayout>
            <Head title={policy.title} />
            <PageLayout
                hero={
                    <PageHero
                        category="governance"
                        backHref="/governance/policies"
                        icon={BookOpen}
                        title={
                            <span
                                className="flex flex-wrap items-center gap-3"
                                dusk="policy-heading"
                            >
                                {policy.title}
                                <Badge variant="outline">
                                    v{policy.version}
                                </Badge>
                                <Badge
                                    className={cn(
                                        'text-xs',
                                        getStatusColor(policy.status),
                                    )}
                                >
                                    {policy.status.replace('_', ' ')}
                                </Badge>
                            </span>
                        }
                        description={`${policy.category} policy`}
                        stats={[
                            {
                                label: 'Status',
                                value: policy.status.replace('_', ' '),
                            },
                            { label: 'Version', value: `v${policy.version}` },
                            {
                                label: 'Attestations',
                                value: `${attestationStats.completed}/${attestationStats.total_required}`,
                            },
                            {
                                label: 'Review',
                                value: new Date(
                                    policy.review_date,
                                ).toLocaleDateString('en-NZ'),
                            },
                        ]}
                        actions={
                            <div className="flex gap-2">
                                {canEdit && policy.status === 'draft' && (
                                    <Button
                                        onClick={handleApprove}
                                        variant="default"
                                    >
                                        <Shield className="mr-2 h-4 w-4" />{' '}
                                        Approve
                                    </Button>
                                )}
                                {canEdit && (
                                    <Link
                                        href={`/governance/policies/${policy.id}/edit`}
                                    >
                                        <Button variant="outline">Edit</Button>
                                    </Link>
                                )}
                            </div>
                        }
                    />
                }
            >
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Policy Content</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div
                                    className="prose max-w-none"
                                    dangerouslySetInnerHTML={{
                                        __html: policy.content,
                                    }}
                                />
                            </CardContent>
                        </Card>

                        {policy.requires_attestation && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Your Attestation</CardTitle>
                                    <CardDescription>
                                        Acknowledge that you have read and
                                        understood this policy
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <form
                                        onSubmit={handleAttest}
                                        className="space-y-4"
                                    >
                                        <div className="flex items-center gap-2">
                                            <Checkbox
                                                id="acknowledged"
                                                checked={
                                                    attestForm.data.acknowledged
                                                }
                                                onCheckedChange={(val) =>
                                                    attestForm.setData(
                                                        'acknowledged',
                                                        val === true,
                                                    )
                                                }
                                            />
                                            <label
                                                htmlFor="acknowledged"
                                                className="text-sm font-medium"
                                            >
                                                I have read and understood this
                                                policy (v{policy.version})
                                            </label>
                                        </div>
                                        <Textarea
                                            placeholder="Optional notes..."
                                            value={attestForm.data.notes}
                                            onChange={(e) =>
                                                attestForm.setData(
                                                    'notes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <Button
                                            type="submit"
                                            disabled={
                                                !attestForm.data.acknowledged ||
                                                attestForm.processing
                                            }
                                        >
                                            <CheckCircle className="mr-2 h-4 w-4" />{' '}
                                            Submit Attestation
                                        </Button>
                                    </form>
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Details</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Version
                                    </span>
                                    <span>{policy.version}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Effective Date
                                    </span>
                                    <span>
                                        {new Date(
                                            policy.effective_date,
                                        ).toLocaleDateString('en-NZ')}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Review Date
                                    </span>
                                    <span>
                                        {new Date(
                                            policy.review_date,
                                        ).toLocaleDateString('en-NZ')}
                                    </span>
                                </div>
                                {policy.approved_by_user && (
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            Approved By
                                        </span>
                                        <span>
                                            {policy.approved_by_user.name}
                                        </span>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {policy.requires_attestation && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Attestation Progress</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="mb-3 text-center">
                                        <span className="text-3xl font-bold">
                                            {attestationStats.completed}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {' '}
                                            / {attestationStats.total_required}
                                        </span>
                                    </div>
                                    <div className="h-2 w-full rounded-full bg-muted">
                                        <div
                                            className="h-2 rounded-full bg-status-success transition-all"
                                            style={{
                                                width: `${attestationStats.total_required > 0 ? (attestationStats.completed / attestationStats.total_required) * 100 : 0}%`,
                                            }}
                                        />
                                    </div>
                                    {policy.attestations.length > 0 && (
                                        <div className="mt-4 space-y-2">
                                            {policy.attestations.map((att) => (
                                                <div
                                                    key={att.id}
                                                    className="flex items-center gap-2 text-sm"
                                                >
                                                    <CheckCircle className="h-4 w-4 text-status-success" />
                                                    <span>{att.user.name}</span>
                                                    <span className="ml-auto text-muted-foreground">
                                                        {new Date(
                                                            att.attested_at,
                                                        ).toLocaleDateString(
                                                            'en-NZ',
                                                        )}
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
