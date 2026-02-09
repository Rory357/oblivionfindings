import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { ArrowLeft, History } from 'lucide-react';

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
                { title: 'Credentials', href: `/sites/${site.id}/credentials` },
                { title: 'Audit', href: '#' },
            ]}
        >
            <Head title={`Credential Audit - ${credential.label}`} />

            <div className="m-4 max-w-5xl space-y-4">
                <div className="flex items-center justify-between">
                    <div>
                        <Button asChild variant="ghost" size="sm" className="mb-2">
                            <Link href={`/sites/${site.id}/credentials`}>
                                <ArrowLeft className="w-4 h-4 mr-1" />
                                Back
                            </Link>
                        </Button>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <History className="w-5 h-5" />
                            Credential Audit
                        </h1>
                        <p className="text-sm text-slate-400">{credential.label}</p>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Audit Entries ({logs.data.length})</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {logs.data.length === 0 ? (
                            <div className="py-8 text-center text-slate-400">No audit entries.</div>
                        ) : (
                            <div className="space-y-2">
                                {logs.data.map((log) => (
                                    <div key={log.id} className="flex items-center justify-between rounded-lg border border-slate-700 p-3">
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <Badge variant="outline">{log.action}</Badge>
                                                <span className="text-sm text-slate-300">{log.user?.name ?? 'Unknown User'}</span>
                                            </div>
                                            <div className="mt-1 text-xs text-slate-500">
                                                {new Date(log.created_at).toLocaleString()}
                                            </div>
                                        </div>
                                        <div className="text-right text-xs text-slate-500">
                                            <div>{log.ip_address || 'No IP'}</div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
