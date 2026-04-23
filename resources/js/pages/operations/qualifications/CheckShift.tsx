import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, ShieldCheck, XCircle } from 'lucide-react';

type Shift = {
    id: number;
    starts_at: string | null;
    ends_at: string | null;
    staff?: { id: number; name: string } | null;
    client?: { id: number; first_name: string; last_name: string } | null;
};

type QualificationResult = {
    requirement: {
        id: number;
        qualification_name: string;
        qualification_type?: string | null;
        description?: string | null;
    };
    met: boolean;
    is_mandatory: boolean;
};

type Props = {
    shift: Shift;
    results: QualificationResult[];
    allMandatoryMet: boolean;
};

function formatDateTime(value: string | null): string {
    if (!value) return '-';
    return new Date(value).toLocaleString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function QualificationCheckShift({ shift, results = [], allMandatoryMet }: Props) {
    return (
        <AppLayout>
            <Head title={`Qualification Check #${shift.id}`} />
            <PageHeader
                title="Qualification Check"
                description="Confirm assigned worker credentials against client requirements."
                backHref={`/operations/shifts/${shift.id}`}
            />
            <PageShell>
                <div className="grid gap-4 lg:grid-cols-[320px_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Shift</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div>
                                <p className="text-xs text-muted-foreground">Client</p>
                                <p className="font-medium">
                                    {shift.client ? `${shift.client.first_name} ${shift.client.last_name}` : 'No client'}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">Worker</p>
                                <p className="font-medium">{shift.staff?.name ?? 'Unassigned'}</p>
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">Scheduled</p>
                                <p className="font-medium">{formatDateTime(shift.starts_at)} - {formatDateTime(shift.ends_at)}</p>
                            </div>
                            <Badge variant={allMandatoryMet ? 'default' : 'destructive'} className="gap-1">
                                {allMandatoryMet ? <CheckCircle2 className="h-3 w-3" /> : <AlertTriangle className="h-3 w-3" />}
                                {allMandatoryMet ? 'Mandatory requirements met' : 'Mandatory gaps found'}
                            </Badge>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <ShieldCheck className="h-4 w-4" />
                                Requirements
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {results.length === 0 && (
                                <div className="rounded-lg border border-dashed p-8 text-center">
                                    <ShieldCheck className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                                    <p className="text-sm font-medium">No requirements configured for this client.</p>
                                    <Link href="/operations/qualifications" className="mt-1 block text-xs text-muted-foreground hover:underline">
                                        Review qualification requirements
                                    </Link>
                                </div>
                            )}
                            {results.map((result) => {
                                const Icon = result.met ? CheckCircle2 : XCircle;
                                return (
                                    <div key={result.requirement.id} className="flex items-start gap-3 rounded-lg border p-3">
                                        <Icon className={result.met ? 'mt-0.5 h-5 w-5 text-emerald-600' : 'mt-0.5 h-5 w-5 text-red-600'} />
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <p className="font-medium">{result.requirement.qualification_name}</p>
                                                <Badge variant={result.is_mandatory ? 'destructive' : 'outline'} className="h-5 text-[10px]">
                                                    {result.is_mandatory ? 'Mandatory' : 'Optional'}
                                                </Badge>
                                                <Badge variant={result.met ? 'default' : 'secondary'} className="h-5 text-[10px]">
                                                    {result.met ? 'Met' : 'Missing'}
                                                </Badge>
                                            </div>
                                            {result.requirement.description && (
                                                <p className="mt-1 text-sm text-muted-foreground">{result.requirement.description}</p>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </CardContent>
                    </Card>
                </div>
            </PageShell>
        </AppLayout>
    );
}
