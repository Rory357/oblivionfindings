import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Clock } from 'lucide-react';

export default function AvailabilityIndex() {
    return (
        <AppLayout>
            <Head title="Staff Availability" />
            <PageHeader title="Staff Availability" description="View staff availability, time-off, and scheduling constraints for rostering." backHref="/operations" />
            <PageShell>
                <Card>
                    <CardContent className="flex flex-col items-center justify-center py-16">
                        <Clock className="mb-4 h-12 w-12 text-muted-foreground/30" />
                        <h2 className="text-lg font-semibold text-muted-foreground">Availability Overview</h2>
                        <p className="mt-1 max-w-sm text-center text-sm text-muted-foreground/80">
                            Staff availability data from HR will be displayed here to help with rostering and shift assignment.
                        </p>
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
