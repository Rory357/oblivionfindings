import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Award, CheckCircle2, Search, ShieldCheck, XCircle } from 'lucide-react';

type QualificationRequirement = {
    id: number;
    qualification_name: string;
    is_mandatory: boolean;
    match_status: 'met' | 'partial' | 'unmet';
    matched_workers: number;
    total_workers: number;
    client: { id: number; first_name: string; last_name: string } | null;
};

type Props = {
    requirements: {
        data: QualificationRequirement[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        q?: string;
    };
};

const MATCH_CONFIG: Record<string, { variant: 'default' | 'secondary' | 'destructive' | 'outline'; icon: typeof CheckCircle2; label: string }> = {
    met: { variant: 'default', icon: CheckCircle2, label: 'Met' },
    partial: { variant: 'secondary', icon: AlertTriangle, label: 'Partial' },
    unmet: { variant: 'destructive', icon: XCircle, label: 'Unmet' },
};

export default function QualificationsIndex({ requirements = { data: [], links: [], current_page: 1, last_page: 1, total: 0 }, filters = {} as any }: Props) {
    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/qualifications', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title="Qualifications" />
            <PageHero
                icon={Award}
                title="Qualifications"
                description="Manage qualification requirements by client and check worker compliance."
                stats={[
                    { label: 'Requirements', value: requirements?.total ?? 0 },
                    {
                        label: 'Met',
                        value: (requirements?.data ?? []).filter((r) => r.match_status === 'met').length,
                    },
                    {
                        label: 'Unmet',
                        value: (requirements?.data ?? []).filter((r) => r.match_status === 'unmet').length,
                    },
                ]}
            />
            <PageShell>
                {/* Search */}
                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search qualifications..."
                            className="h-9 pl-8 text-sm"
                            defaultValue={filters?.q ?? ''}
                            onChange={(e) => updateFilters('q', e.target.value || null)}
                        />
                    </div>
                </div>

                {/* List */}
                <div className="mt-4 space-y-2">
                    {(requirements?.data ?? []).length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <Award className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">No Qualification Requirements</h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">Qualification requirements will appear here once configured.</p>
                            </CardContent>
                        </Card>
                    )}
                    {(requirements?.data ?? []).map((req) => {
                        const match = MATCH_CONFIG[req.match_status] ?? MATCH_CONFIG.unmet;
                        const MatchIcon = match.icon;
                        return (
                            <Card key={req.id} className="transition-all hover:border-border hover:shadow-sm">
                                <CardContent className="flex items-center gap-4 p-4">
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary dark:bg-primary/40 dark:text-primary/70">
                                        <ShieldCheck className="h-5 w-5" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm font-semibold">
                                                {req.qualification_name}
                                            </span>
                                            <Badge variant={req.is_mandatory ? 'destructive' : 'outline'} className="h-4 px-1.5 text-[9px]">
                                                {req.is_mandatory ? 'Mandatory' : 'Optional'}
                                            </Badge>
                                            <Badge variant={match.variant} className="h-4 gap-0.5 px-1.5 text-[9px]">
                                                <MatchIcon className="h-2.5 w-2.5" /> {match.label}
                                            </Badge>
                                        </div>
                                        <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                            {req.client && (
                                                <span>{req.client.first_name} {req.client.last_name}</span>
                                            )}
                                            <span>{req.matched_workers}/{req.total_workers} workers qualified</span>
                                        </div>
                                    </div>
                                    <div className="shrink-0 text-xs text-muted-foreground">
                                        Configure from client or shift workflows
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                {/* Pagination */}
                {(requirements?.last_page ?? 1) > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {(requirements?.links ?? []).map((link: any, i: number) => (
                            <Button
                                key={i}
                                size="sm"
                                variant={link.active ? 'default' : 'outline'}
                                className="h-7 min-w-[28px] px-2 text-xs"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
