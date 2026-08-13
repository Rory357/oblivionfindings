import { PageHero, PageLayout } from '@/components/page';
import { PrivacyActionModal } from '@/components/privacy/privacy-action-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    Check,
    ClipboardCheck,
    Clock,
    Database,
    Eye,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';

type Props = {
    policies: any[];
};

export default function ReviewRetention({ policies }: Props) {
    const [executePolicyId, setExecutePolicyId] = useState<number | null>(null);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Privacy', href: '/privacy/dashboard' },
                { title: 'Retention Policies', href: '/privacy/retention' },
                { title: 'Review Data', href: '/privacy/retention/review' },
            ]}
        >
            <Head title="Review Data for Retention" />

            <PageLayout
                hero={
                    <PageHero
                        icon={ClipboardCheck}
                        title="Review Data for Retention"
                        description="Review data that may be due for archival or deletion"
                        stats={[{ label: 'Policies', value: policies.length }]}
                        actions={
                            <Link href="/privacy/retention">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    Back to Policies
                                </Button>
                            </Link>
                        }
                    />
                }
            >
                <div className="space-y-4">
                    {policies.length > 0 ? (
                        policies.map((policy: any) => (
                            <Card key={policy.id}>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        <div className="flex items-center gap-2">
                                            <Database className="h-5 w-5 text-primary" />
                                            {policy.policy_name}
                                        </div>
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="mb-4 flex flex-wrap gap-2">
                                        <Badge variant="outline">
                                            {policy.model_type}
                                        </Badge>
                                        <Badge
                                            variant="outline"
                                            className="border-status-info/30 bg-status-info-bg text-status-info"
                                        >
                                            <Clock className="mr-1 h-3 w-3" />
                                            {policy.retention_period_years} year
                                            retention
                                        </Badge>
                                        <Badge variant="outline">
                                            {policy.execution_state ===
                                            'approved'
                                                ? 'Approved'
                                                : policy.execution_state ===
                                                    'previewed'
                                                  ? 'Awaiting independent approval'
                                                  : 'Draft'}
                                        </Badge>
                                    </div>
                                    {policy.preview_snapshot && (
                                        <p className="text-sm text-muted-foreground">
                                            {
                                                policy.preview_snapshot
                                                    .eligible_count
                                            }{' '}
                                            eligible outcome(s) ·{' '}
                                            {
                                                policy.preview_snapshot
                                                    .exempt_count
                                            }{' '}
                                            protected by legal hold or
                                            active-case exemption
                                        </p>
                                    )}
                                    <div className="mt-4 flex flex-wrap gap-2">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    `/privacy/retention/${policy.id}/preview`,
                                                    {},
                                                )
                                            }
                                        >
                                            <Eye className="mr-2 h-4 w-4" />
                                            Create preview
                                        </Button>
                                        {policy.execution_state ===
                                            'previewed' && (
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        `/privacy/retention/${policy.id}/approve`,
                                                        {},
                                                    )
                                                }
                                            >
                                                <Check className="mr-2 h-4 w-4" />
                                                Approve preview
                                            </Button>
                                        )}
                                        {policy.execution_state ===
                                            'approved' && (
                                            <Button
                                                size="sm"
                                                variant="destructive"
                                                onClick={() =>
                                                    setExecutePolicyId(
                                                        policy.id,
                                                    )
                                                }
                                            >
                                                <Trash2 className="mr-2 h-4 w-4" />
                                                Execute approved retention
                                            </Button>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        ))
                    ) : (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No active retention policies found.
                        </div>
                    )}
                </div>
                {executePolicyId !== null && (
                    <PrivacyActionModal
                        kind="execute"
                        recordId={executePolicyId}
                        open
                        onClose={() => setExecutePolicyId(null)}
                    />
                )}
            </PageLayout>
        </AppLayout>
    );
}
