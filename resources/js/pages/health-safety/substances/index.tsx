import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Head, Link, router } from '@inertiajs/react';
import { FlaskConical, ShieldAlert, FileText, Warehouse } from 'lucide-react';

type Substance = {
    id: number;
    name: string;
    hsno_classification: string | null;
    physical_form: string | null;
    hazard_pictograms: string[] | null;
    status: string;
    sds_count: number;
    storage_locations_count: number;
    is_controlled_substance: boolean;
};

type Props = {
    filters: {
        q: string;
        status: string | null;
        physical_form: string | null;
        is_controlled: string | null;
    };
    stats: {
        total_substances: number;
        controlled_substances: number;
        active_sds: number;
        storage_locations: number;
    };
    substances: {
        data: Substance[];
        links: Array<{ label: string; url: string | null; active: boolean }>;
    };
};

const statusColor = (status: string) => {
    switch (status) {
        case 'active':
            return 'bg-green-100 text-green-800';
        case 'inactive':
            return 'bg-slate-100 text-slate-800';
        case 'pending_review':
            return 'bg-amber-100 text-amber-800';
        case 'restricted':
            return 'bg-red-100 text-red-800';
        default:
            return 'bg-slate-100 text-slate-800';
    }
};

const pictogramLabels: Record<string, string> = {
    GHS01: 'Explosive',
    GHS02: 'Flammable',
    GHS03: 'Oxidising',
    GHS04: 'Compressed Gas',
    GHS05: 'Corrosive',
    GHS06: 'Toxic',
    GHS07: 'Harmful',
    GHS08: 'Health Hazard',
    GHS09: 'Environment',
};

export default function SubstancesIndex({ filters, stats, substances }: Props) {
    const ANY = '__any__';

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/health-safety/substances', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Chemical Register', href: '/health-safety/substances' },
            ]}
        >
            <Head title="Chemical Register" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Chemical Register</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Manage hazardous substances, SDS documents, and storage locations
                        </div>
                    </div>
                    <Link href="/health-safety/substances/create">
                        <Button size="sm">Add Substance</Button>
                    </Link>
                </div>

                {/* Stats Row */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-blue-50 p-2">
                                <FlaskConical className="h-5 w-5 text-blue-600" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold">{stats.total_substances}</div>
                                <div className="text-xs text-slate-500">Total Substances</div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-red-50 p-2">
                                <ShieldAlert className="h-5 w-5 text-red-600" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold">{stats.controlled_substances}</div>
                                <div className="text-xs text-slate-500">Controlled Substances</div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-green-50 p-2">
                                <FileText className="h-5 w-5 text-green-600" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold">{stats.active_sds}</div>
                                <div className="text-xs text-slate-500">Active SDS</div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-amber-50 p-2">
                                <Warehouse className="h-5 w-5 text-amber-600" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold">{stats.storage_locations}</div>
                                <div className="text-xs text-slate-500">Storage Locations</div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                        <div>
                            <Label className="text-xs text-slate-500">Search</Label>
                            <Input
                                placeholder="Name or HSNO number"
                                value={filters.q || ''}
                                onChange={(e) => onFilter({ q: e.target.value })}
                            />
                        </div>
                        <div>
                            <Label className="text-xs text-slate-500">Status</Label>
                            <Select
                                value={filters.status ?? ANY}
                                onValueChange={(v) => onFilter({ status: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Status" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    <SelectItem value="active">Active</SelectItem>
                                    <SelectItem value="inactive">Inactive</SelectItem>
                                    <SelectItem value="pending_review">Pending Review</SelectItem>
                                    <SelectItem value="restricted">Restricted</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs text-slate-500">Physical Form</Label>
                            <Select
                                value={filters.physical_form ?? ANY}
                                onValueChange={(v) => onFilter({ physical_form: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Form" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    <SelectItem value="solid">Solid</SelectItem>
                                    <SelectItem value="liquid">Liquid</SelectItem>
                                    <SelectItem value="gas">Gas</SelectItem>
                                    <SelectItem value="aerosol">Aerosol</SelectItem>
                                    <SelectItem value="powder">Powder</SelectItem>
                                    <SelectItem value="paste">Paste</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs text-slate-500">Controlled</Label>
                            <Select
                                value={filters.is_controlled ?? ANY}
                                onValueChange={(v) => onFilter({ is_controlled: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Controlled" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    <SelectItem value="yes">Yes</SelectItem>
                                    <SelectItem value="no">No</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                {/* Substances Table */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-slate-500">
                                        <th className="pb-2 pr-4 font-medium">Name</th>
                                        <th className="pb-2 pr-4 font-medium">HSNO Classification</th>
                                        <th className="pb-2 pr-4 font-medium">Physical Form</th>
                                        <th className="pb-2 pr-4 font-medium">Hazard Pictograms</th>
                                        <th className="pb-2 pr-4 font-medium">Status</th>
                                        <th className="pb-2 pr-4 font-medium">SDS</th>
                                        <th className="pb-2 pr-4 font-medium">Locations</th>
                                        <th className="pb-2 font-medium"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {substances.data.map((s) => (
                                        <tr key={s.id} className="border-b last:border-0">
                                            <td className="py-2 pr-4">
                                                <div className="font-medium">{s.name}</div>
                                                {s.is_controlled_substance && (
                                                    <Badge variant="destructive" className="mt-1 text-[10px]">
                                                        Controlled
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="py-2 pr-4 text-xs">{s.hsno_classification ?? '-'}</td>
                                            <td className="py-2 pr-4 capitalize">{s.physical_form ?? '-'}</td>
                                            <td className="py-2 pr-4">
                                                <div className="flex flex-wrap gap-1">
                                                    {(s.hazard_pictograms ?? []).map((p) => (
                                                        <Badge key={p} variant="outline" className="text-[10px]">
                                                            {pictogramLabels[p] ?? p}
                                                        </Badge>
                                                    ))}
                                                    {!(s.hazard_pictograms ?? []).length && '-'}
                                                </div>
                                            </td>
                                            <td className="py-2 pr-4">
                                                <Badge className={statusColor(s.status)}>{s.status}</Badge>
                                            </td>
                                            <td className="py-2 pr-4">{s.sds_count}</td>
                                            <td className="py-2 pr-4">{s.storage_locations_count}</td>
                                            <td className="py-2">
                                                <Link
                                                    href={`/health-safety/substances/${s.id}`}
                                                    className="rounded-md border px-3 py-1.5 text-xs hover:bg-muted"
                                                >
                                                    View
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {!substances.data.length && (
                                <div className="py-4 text-center text-sm text-slate-500">
                                    No substances found.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {substances?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {substances.links.map((l) => (
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
