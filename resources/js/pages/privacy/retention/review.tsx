import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Head, Link } from '@inertiajs/react';
import { Database, Clock } from 'lucide-react';

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

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Review Data for Retention</h1>
                        <div className="mt-1 text-sm text-muted-foreground">
                            Review data that may be due for archival or deletion
                        </div>
                    </div>
                    <Link href="/privacy/retention" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                        Back to Policies
                    </Link>
                </div>

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
                                        <Badge variant="outline" className="border-blue-200 bg-blue-50 text-blue-700">
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
            </div>
        </AppLayout>
    );
}
