import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Hourglass } from 'lucide-react';

type Props = {
    /** Where the reader was going, so "Try again" reloads that page. */
    path: string;
};

/**
 * Shown instead of "500 Server Error" when a page load keeps meeting a save or a scheduled job that holds the rows it
 * reads (a lock clash). Nothing was changed; reading again a moment later normally works.
 */
export default function PageBusy({ path }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }]}>
            <Head title="This page is busy" />
            <div className="flex flex-1 items-center justify-center p-6">
                <EmptyState
                    icon={Hourglass}
                    title="This page is busy right now"
                    description="Someone or something is saving the records this page shows. Nothing has been changed. Try again in a moment."
                    action={
                        <Button size="sm" onClick={() => router.visit(path)}>
                            Try again
                        </Button>
                    }
                />
            </div>
        </AppLayout>
    );
}
