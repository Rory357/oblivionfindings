import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/react';

type Props = {
    clients: Array<{ id: number; name: string; relation: string }>;
};

export default function PortalIndex({ clients }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Portal', href: '/portal' }]}>
            <Head title="Portal" />

            <div className="space-y-4">
                <div className="text-sm text-slate-500">
                    Select a client to view their timeline, medical details, and ask questions.
                </div>

                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {clients.map((c) => (
                        <Card key={c.id}>
                            <CardHeader>
                                <CardTitle className="text-base">{c.name}</CardTitle>
                                <div className="text-xs text-slate-500">Access: {c.relation}</div>
                            </CardHeader>
                            <CardContent>
                                <Button asChild>
                                    <Link href={`/portal/clients/${c.id}`}>Open</Link>
                                </Button>
                            </CardContent>
                        </Card>
                    ))}

                    {!clients.length && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">No linked clients</CardTitle>
                            </CardHeader>
                            <CardContent className="text-sm text-slate-500">
                                Ask your provider to link your account to a client.
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
