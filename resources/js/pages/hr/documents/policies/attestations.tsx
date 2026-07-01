import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { BookOpen, Search, ShieldCheck, X } from 'lucide-react';

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
        links: { url: string | null; label: string; active: boolean }[];
        total?: number;
    };
    filters: {
        policy_id: string | number | null;
        q: string | null;
    };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Policies', href: '/hr/documents/policies' },
    { title: 'Attestations', href: '/hr/documents/policies/attestations' },
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
            '/hr/documents/policies/attestations',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    const filteredPolicyTitle = filters.policy_id
        ? (attestations.data.find(
              (a) => String(a.policy.id) === String(filters.policy_id),
          )?.policy.title ?? `Policy #${filters.policy_id}`)
        : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Policy Attestations" />

            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={ShieldCheck}
                        title="Policy Attestations"
                        description="Track staff acknowledgement and attestation of organisational policies."
                        stats={[
                            {
                                label: 'Attestations recorded',
                                value: attestations.total ?? attestations.data.length,
                            },
                        ]}
                        actions={
                            <Link href="/hr/documents/policies">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <BookOpen className="mr-1.5 h-4 w-4" />
                                    Policy Library
                                </Button>
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
                        {filters.policy_id ? (
                            <div className="flex items-end">
                                <Badge
                                    variant="outline"
                                    className="flex items-center gap-1.5 border-primary/30 bg-primary/10 text-primary"
                                >
                                    <ShieldCheck className="h-3 w-3" />
                                    {filteredPolicyTitle}
                                    {/* eslint-disable-next-line no-restricted-syntax -- tiny inline clear-chip control, a full Button breaks the badge layout */}
                                    <button
                                        type="button"
                                        aria-label="Clear policy filter"
                                        onClick={() => onFilter({ policy_id: null })}
                                        className="ml-0.5 rounded hover:text-status-critical"
                                    >
                                        <X className="h-3 w-3" />
                                    </button>
                                </Badge>
                            </div>
                        ) : null}
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
                                                href={`/hr/documents/policies/${att.policy.id}`}
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
