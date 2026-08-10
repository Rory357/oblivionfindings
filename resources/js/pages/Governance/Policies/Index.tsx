import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyList } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { governanceStatusColor } from '@/lib/governance-status';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { BookOpen, Eye, Plus } from 'lucide-react';

interface Policy {
    id: number;
    title: string;
    category: string;
    version: number;
    status: string;
    effective_date: string;
    review_date: string;
    requires_attestation: boolean;
    attestations_count: number;
}

interface Props extends PageProps {
    policies: {
        data: Policy[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    categories: Array<{ value: string; label: string }>;
}

export default function PolicyIndex({ auth, policies, categories }: Props) {
    const getStatusColor = (status: string) => governanceStatusColor(status);

    const getCategoryLabel = (value: string) =>
        categories.find((c) => c.value === value)?.label ?? value;

    const activeCount = policies.data.filter(
        (p) => p.status === 'active',
    ).length;
    const needsAttestation = policies.data.filter(
        (p) => p.requires_attestation,
    ).length;

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Policies', href: '/governance/policies' },
            ]}
        >
            <Head title="Governance Policies" />
            <PageLayout
                hero={
                    <PageHero
                        icon={BookOpen}
                        title="Governance Policies"
                        description="Board policies, procedures, and attestation tracking"
                        stats={[
                            { label: 'Total', value: policies.data.length },
                            { label: 'Active', value: activeCount },
                            { label: 'Attestation', value: needsAttestation },
                        ]}
                        actions={
                            <Link href="/governance/policies/create">
                                <Button size="sm">
                                    <Plus className="mr-2 h-4 w-4" /> New Policy
                                </Button>
                            </Link>
                        }
                    />
                }
            >
                <div className="grid gap-4">
                    {policies.data.map((policy) => (
                        <Card key={policy.id}>
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between">
                                    <div className="flex-1">
                                        <div className="flex items-center gap-3">
                                            <BookOpen className="h-5 w-5 text-status-info" />
                                            <Link
                                                href={`/governance/policies/${policy.id}`}
                                                className="text-lg font-medium hover:text-status-info"
                                            >
                                                {policy.title}
                                            </Link>
                                            <Badge variant="outline">
                                                v{policy.version}
                                            </Badge>
                                            <Badge
                                                className={cn(
                                                    'text-xs',
                                                    getStatusColor(
                                                        policy.status,
                                                    ),
                                                )}
                                            >
                                                {policy.status.replace(
                                                    '_',
                                                    ' ',
                                                )}
                                            </Badge>
                                        </div>
                                        <div className="mt-2 flex items-center gap-4 text-sm text-muted-foreground">
                                            <span>
                                                {getCategoryLabel(
                                                    policy.category,
                                                )}
                                            </span>
                                            <span>
                                                Review:{' '}
                                                {new Date(
                                                    policy.review_date,
                                                ).toLocaleDateString('en-NZ', {
                                                    year: 'numeric',
                                                    month: 'short',
                                                    day: 'numeric',
                                                })}
                                            </span>
                                            {policy.requires_attestation && (
                                                <span>
                                                    {policy.attestations_count}{' '}
                                                    attestation(s)
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                    <Link
                                        href={`/governance/policies/${policy.id}`}
                                    >
                                        <Button variant="ghost" size="sm">
                                            <Eye className="h-4 w-4" />
                                        </Button>
                                    </Link>
                                </div>
                            </CardContent>
                        </Card>
                    ))}

                    {policies.data.length === 0 && (
                        <EmptyList
                            icon={BookOpen}
                            itemName="policy"
                            itemNamePlural="policies"
                            createHref="/governance/policies/create"
                            createLabel="Create policy"
                            variant="compact"
                        />
                    )}
                </div>

                {policies.links && policies.links.length > 3 && (
                    <div className="mt-6 flex justify-center gap-1">
                        {policies.links.map((link, i) => (
                            <Link
                                key={i}
                                href={link.url || '#'}
                                className={cn(
                                    'rounded px-3 py-1 text-sm',
                                    link.active
                                        ? 'bg-status-info text-white'
                                        : 'bg-card text-muted-foreground hover:bg-muted',
                                    !link.url &&
                                        'pointer-events-none opacity-50',
                                )}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
