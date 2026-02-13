import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Head, Link, router } from '@inertiajs/react';
import { ShieldCheck, Search } from 'lucide-react';

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
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

export default function PolicyAttestations({ attestations, filters }: Props) {
    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/hr/policies/attestations', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Policy Attestations" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-lg font-semibold">
                            <ShieldCheck className="h-5 w-5 text-amber-500" />
                            Policy Attestations
                        </h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Track staff acknowledgement and attestation of organisational policies
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Link href="/hr/policies" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                            Back to Policies
                        </Link>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <Label className="text-xs text-slate-500">Search</Label>
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
                                <Input
                                    placeholder="Search by staff name or policy title..."
                                    value={filters.q || ''}
                                    onChange={(e) => onFilter({ q: e.target.value })}
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
                                                className="font-medium text-blue-600 hover:underline"
                                            >
                                                {att.policy.title}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="outline">
                                                v{att.policy_version.version_number}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="font-medium">{att.user.name}</TableCell>
                                        <TableCell>{formatDateTime(att.attested_at)}</TableCell>
                                    </TableRow>
                                ))}
                                {!attestations.data.length && (
                                    <TableRow>
                                        <TableCell colSpan={4} className="py-8 text-center text-sm text-slate-500">
                                            No attestations found.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {attestations?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {attestations.links.map((l: any) => (
                            <button
                                key={l.label}
                                disabled={!l.url}
                                className={`rounded-md border px-3 py-2 text-xs ${l.active ? 'bg-muted' : 'hover:bg-muted'}`}
                                onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>
        </AppLayout>
    );
}
