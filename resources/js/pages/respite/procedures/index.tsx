import { PageHero, PageLayout } from '@/components/page';
import RespiteSubnav from '@/components/respite-subnav';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { BookOpen, Plus } from 'lucide-react';

type Props = {
    templates: any;
};

export default function RespiteProceduresIndex({ templates }: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Respite', href: '/respite' },
                { title: 'Procedures', href: '/respite/procedures' },
            ]}
        >
            <Head title="Respite Procedures" />

            <PageLayout
                hero={
                    <PageHero
                        icon={BookOpen}
                        title="Procedure Templates"
                        description="Define procedure steps for respite workflows."
                        stats={[
                            {
                                label: 'Templates',
                                value: templates.data.length,
                            },
                        ]}
                        actions={
                            <Link href="/respite/procedures/create">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New Template
                                </Button>
                            </Link>
                        }
                    />
                }
            >
                <RespiteSubnav />

                <div className="space-y-2">
                    {templates.data.map((t: any) => (
                        <Card key={t.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    {t.name} (v{t.version})
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="text-sm text-muted-foreground">
                                <Link
                                    href={`/respite/procedures/${t.id}`}
                                    className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                >
                                    View
                                </Link>
                            </CardContent>
                        </Card>
                    ))}
                    {!templates.data.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No procedure templates found.
                        </div>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
