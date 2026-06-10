import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { PageHero, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Search, ShieldCheck } from 'lucide-react';

type BreadcrumbItem = { title: string; href: string };

type Attestation = {
    id: number;
    policy: { id: number; title: string };
    policy_version: { version_number: string };
    user: { id: number; name: string };
    attested_at: string;
};

type Props = {
    attestations: {
        data: Attestation[];
        links: any[];
    };
    filters: {
        policy_id: string | number | null;
        q: string | null;
    };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Policies', href: '/hr/policies' },
    { title: 'Attestations', href: '/hr/policies/attestations' },
];

const formatDateTime = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          });
};

export default function PolicyAttestations({ attestations, filters }: Props) {
    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/hr/policies/attestations',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Policy Attestations" />

            <PageLayout
                hero={
                    <PageHero
                        icon={ShieldCheck}
                        title="Policy Attestations"
                        description="Track staff acknowledgement and attestation of organisational policies."
                        stats={[
                            { label: 'Attestations', value: attestations.data.length },
                        ]}
                        actions={
                            <Link
                                href="/hr/policies"
                                className="rounded-md border border-primary-foreground/30 bg-primary-foreground/10 px-3 py-2 text-xs text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20"
                            >
                                Back to Policies
                            </Link>
                        }
                    />
                }
            >
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Search
                            </Label>
                            <div className="relative">
                                <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search by staff name or policy title..."
                                    value={filters.q || ''}
                                    onChange={(e) =>
                                        onFilter({ q: e.target.value })
                                    }
                                    className="pl-9"
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Policy</TableHead>
                                    <TableHead>Version</TableHead>
                                    <TableHead>Staff Member</TableHead>
                                    <TableHead>Attested At</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {attestations.data.map((att) => (
                                    <TableRow key={att.id}>
                                        <TableCell>
                                            <Link
                                                href={`/hr/policies/${att.policy.id}`}
                                                className="font-medium text-status-info hover:underline"
                                            >
                                                {att.policy.title}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="outline">
                                                v
                                                {
                                                    att.policy_version
                                                        .version_number
                                                }
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="font-medium">
                                            {att.user.name}
                                        </TableCell>
                                        <TableCell>
                                            {formatDateTime(att.attested_at)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!attestations.data.length && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={4}
                                            className="py-8 text-center text-sm text-muted-foreground"
                                        >
                                            No attestations found.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {attestations?.links?.length ? (
                    <LaravelPagination links={attestations.links} />
                ) : null}
            </PageLayout>
        </AppLayout>
    );
}
