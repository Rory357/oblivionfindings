import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Construction } from 'lucide-react';

export default function ControlRoomPlaceholder({ feature = 'Feature' }: { feature?: string }) {
    return (
        <AppLayout>
            <Head title={`${feature} — Control Room`} />
            <div className="flex min-h-[60vh] flex-col items-center justify-center text-center">
                <div className="rounded-full bg-primary/10 p-6">
                    <Construction className="h-12 w-12 text-primary" />
                </div>
                <h2 className="mt-6 text-2xl font-semibold">{feature}</h2>
                <p className="mt-2 max-w-md text-muted-foreground">
                    This Control Room feature is coming soon. We're building a fully-featured real-time monitoring and communication hub.
                </p>
            </div>
        </AppLayout>
    );
}
