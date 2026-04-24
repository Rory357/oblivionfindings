import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { type BreadcrumbItem } from '@/types';
import { ArrowLeft } from 'lucide-react';

type ParentPosition = {
    id: number;
    title: string;
    code: string;
};

type PositionData = {
    id: number;
    title: string;
    code: string;
    department: string | null;
    team: string | null;
    description: string | null;
    requirements: string | null;
    employment_type: string;
    fte: number;
    headcount_budget: number;
    reports_to_position_id: number | null;
    is_active: boolean;
};

type Props = {
    position: PositionData;
    parentPositions: ParentPosition[];
    departments: Array<{ id: number; name: string }>;
};

export default function EditPosition({ position, parentPositions, departments }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Positions', href: '/hr/positions' },
        { title: position.title, href: `/hr/positions/${position.id}` },
        { title: 'Edit', href: `/hr/positions/${position.id}/edit` },
    ];

    const { data, setData, put, processing, errors } = useForm({
        title: position.title,
        code: position.code,
        department: position.department ?? '',
        team: position.team ?? '',
        description: position.description ?? '',
        requirements: position.requirements ?? '',
        employment_type: position.employment_type,
        fte: String(position.fte),
        headcount_budget: String(position.headcount_budget),
        reports_to_position_id: position.reports_to_position_id ? String(position.reports_to_position_id) : '',
        is_active: position.is_active,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/hr/positions/${position.id}`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${position.title}`} />
            <div className="flex flex-col gap-6 p-6 max-w-2xl mx-auto">
                <div className="flex items-center gap-4">
                    <Link href={`/hr/positions/${position.id}`}>
                        <Button variant="outline" size="icon">
                            <ArrowLeft className="h-4 w-4" />
                        </Button>
                    </Link>
                    <h1 className="text-2xl font-bold">Edit Position</h1>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Position Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-6">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="title">
                                        Title <span className="text-status-critical">*</span>
                                    </Label>
                                    <Input
                                        id="title"
                                        value={data.title}
                                        onChange={(e) => setData('title', e.target.value)}
                                        placeholder="e.g. Senior Support Worker"
                                        className={errors.title ? 'border-status-critical/30' : ''}
                                    />
                                    {errors.title && (
                                        <p className="text-sm text-status-critical">{errors.title}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="code">
                                        Code <span className="text-status-critical">*</span>
                                    </Label>
                                    <Input
                                        id="code"
                                        value={data.code}
                                        onChange={(e) => setData('code', e.target.value)}
                                        placeholder="e.g. SSW-001"
                                        className={errors.code ? 'border-status-critical/30' : ''}
                                    />
                                    {errors.code && (
                                        <p className="text-sm text-status-critical">{errors.code}</p>
                                    )}
                                </div>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label>Department</Label>
                                    <Select value={data.department || '__none__'} onValueChange={(v) => setData('department', v === '__none__' ? '' : v)}>
                                        <SelectTrigger><SelectValue placeholder="Select department" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">No department</SelectItem>
                                            {departments.map((d) => (
                                                <SelectItem key={d.id} value={d.name}>{d.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.department && (
                                        <p className="text-sm text-status-critical">{errors.department}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="team">Team</Label>
                                    <Input
                                        id="team"
                                        value={data.team}
                                        onChange={(e) => setData('team', e.target.value)}
                                        placeholder="e.g. Team A"
                                    />
                                    {errors.team && (
                                        <p className="text-sm text-status-critical">{errors.team}</p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="Describe the role and responsibilities..."
                                    rows={4}
                                />
                                {errors.description && (
                                    <p className="text-sm text-status-critical">{errors.description}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="requirements">Requirements</Label>
                                <Textarea
                                    id="requirements"
                                    value={data.requirements}
                                    onChange={(e) => setData('requirements', e.target.value)}
                                    placeholder="List qualifications, experience and skills required..."
                                    rows={4}
                                />
                                {errors.requirements && (
                                    <p className="text-sm text-status-critical">{errors.requirements}</p>
                                )}
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="employment_type">
                                        Employment Type <span className="text-status-critical">*</span>
                                    </Label>
                                    <Select
                                        value={data.employment_type}
                                        onValueChange={(value) => setData('employment_type', value)}
                                    >
                                        <SelectTrigger id="employment_type" className={errors.employment_type ? 'border-status-critical/30' : ''}>
                                            <SelectValue placeholder="Select type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="full_time">Full Time</SelectItem>
                                            <SelectItem value="part_time">Part Time</SelectItem>
                                            <SelectItem value="casual">Casual</SelectItem>
                                            <SelectItem value="fixed_term">Fixed Term</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.employment_type && (
                                        <p className="text-sm text-status-critical">{errors.employment_type}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="fte">
                                        FTE <span className="text-status-critical">*</span>
                                    </Label>
                                    <Input
                                        id="fte"
                                        type="number"
                                        min="0.01"
                                        max="1.00"
                                        step="0.01"
                                        value={data.fte}
                                        onChange={(e) => setData('fte', e.target.value)}
                                        className={errors.fte ? 'border-status-critical/30' : ''}
                                    />
                                    {errors.fte && (
                                        <p className="text-sm text-status-critical">{errors.fte}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="headcount_budget">
                                        Headcount Budget <span className="text-status-critical">*</span>
                                    </Label>
                                    <Input
                                        id="headcount_budget"
                                        type="number"
                                        min="1"
                                        max="999"
                                        step="1"
                                        value={data.headcount_budget}
                                        onChange={(e) => setData('headcount_budget', e.target.value)}
                                        className={errors.headcount_budget ? 'border-status-critical/30' : ''}
                                    />
                                    {errors.headcount_budget && (
                                        <p className="text-sm text-status-critical">{errors.headcount_budget}</p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="reports_to_position_id">Reports To</Label>
                                <Select
                                    value={data.reports_to_position_id}
                                    onValueChange={(value) => setData('reports_to_position_id', value === 'none' ? '' : value)}
                                >
                                    <SelectTrigger id="reports_to_position_id">
                                        <SelectValue placeholder="Select parent position (optional)" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">None</SelectItem>
                                        {parentPositions.map((pos) => (
                                            <SelectItem key={pos.id} value={String(pos.id)}>
                                                {pos.title} ({pos.code})
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.reports_to_position_id && (
                                    <p className="text-sm text-status-critical">{errors.reports_to_position_id}</p>
                                )}
                            </div>

                            <div className="flex items-center space-x-2">
                                <Checkbox
                                    id="is_active"
                                    checked={data.is_active}
                                    onCheckedChange={(checked) => setData('is_active', checked === true)}
                                />
                                <Label htmlFor="is_active" className="cursor-pointer">
                                    Position is active
                                </Label>
                            </div>

                            <div className="flex items-center justify-end gap-3">
                                <Link href={`/hr/positions/${position.id}`}>
                                    <Button type="button" variant="outline">Cancel</Button>
                                </Link>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Saving...' : 'Save Changes'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
