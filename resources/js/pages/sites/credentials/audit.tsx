import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Head } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';

type Site = {
    id: number;
    name: string;
};

type Credential = {
    id: number;
    label: string;
};

type Log = {
    id: number;
    action: string;
    ip_address?: string | null;
    user_agent?: string | null;
    created_at: string;
    user?: {
        id: number;
        name: string;
    } | null;
};

type Props = {
    site: Site;
    credential: Credential;
    logs: {
        data: Log[];
    };
};

export default function CredentialAudit({ site, credential, logs }: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Credentials', href: `/vendors?site_id=${site.id}&tab=credentials` },
                { title: 'Audit', href: '#' },
            ]}
        >
            <Head title={`Credential Audit - ${credential.label}`} />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref={`/vendors?site_id=${site.id}&tab=credentials`}
                        title="Credential Audit"
                        description={credential.label}
                    />
                }
            >
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Audit Entries ({logs.data.length})</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {logs.data.length === 0 ? (
                            <div className="py-8 text-center text-muted-foreground">No audit entries.</div>
                        ) : (
                            <div className="space-y-2">
                                {logs.data.map((log) => (
                                    <div key={log.id} className="flex items-center justify-between rounded-lg border p-3 hover:bg-muted/50">
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <Badge variant="outline">{log.action}</Badge>
                                                <span className="text-sm text-muted-foreground">{log.user?.name ?? 'Unknown User'}</span>
                                            </div>
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                {new Date(log.created_at).toLocaleString()}
                                            </div>
                                        </div>
                                        <div className="text-right text-xs text-muted-foreground">
                                            <div>{log.ip_address || 'No IP'}</div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
