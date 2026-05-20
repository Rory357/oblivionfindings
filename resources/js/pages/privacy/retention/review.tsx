import { PageHero, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Head, Link } from '@inertiajs/react';
import { ClipboardCheck, Clock, Database } from 'lucide-react';

type Props = {
    policies: any[];
};

export default function ReviewRetention({ policies }: Props) {
    return (
        <AppLayout breadcrumbs={[
            { title: 'Privacy & GDPR', href: '/privacy/dashboard' },
            { title: 'Retention Policies', href: '/privacy/retention' },
            { title: 'Review Data', href: '/privacy/retention/review' },
        ]}>
            <Head title="Review Data for Retention" />

            <PageLayout
                hero={
                    <PageHero
                        icon={ClipboardCheck}
                        title="Review Data for Retention"
                        description="Review data that may be due for archival or deletion"
                        stats={[
                            { label: 'Policies', value: policies.length },
                        ]}
                        actions={
                            <Link href="/privacy/retention">
                                <Button size="sm" variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground">
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
                                    <div className="flex flex-wrap gap-2 mb-4">
                                        <Badge variant="outline">
                                            {policy.model_type}
                                        </Badge>
                                        <Badge variant="outline" className="border-status-info/30 bg-status-info-bg text-status-info">
                                            <Clock className="mr-1 h-3 w-3" />
                                            {policy.retention_period_years} year retention
                                        </Badge>
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        Data review functionality will be implemented here.
                                        This will show records approaching their retention period.
                                    </p>
                                </CardContent>
                            </Card>
                        ))
                    ) : (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No active retention policies found.
                        </div>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
