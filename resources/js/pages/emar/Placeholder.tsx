import PageShell from '@/components/page-shell';
import { Card, CardContent } from '@/components/ui/card';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Construction } from 'lucide-react';

type Props = {
    feature: string;
};

export default function EmarPlaceholder({ feature }: Props) {
    return (
        <AppLayout>
            <Head title={`eMAR — ${feature}`} />
            <PageHero variant="compact" title={feature} description="This feature is part of the eMAR module." backHref="/emar" />
            <PageShell>
                <Card>
                    <CardContent className="flex flex-col items-center justify-center py-16">
                        <Construction className="mb-4 h-12 w-12 text-muted-foreground/30" />
                        <h2 className="text-lg font-semibold text-muted-foreground">Coming Soon</h2>
                        <p className="mt-1 max-w-sm text-center text-sm text-muted-foreground/80">
                            The <strong>{feature}</strong> feature is being developed as part of the eMAR module.
                            This will provide comprehensive electronic medication administration capabilities.
                        </p>
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
