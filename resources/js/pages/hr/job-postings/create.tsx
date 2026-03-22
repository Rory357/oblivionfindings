import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import { type BreadcrumbItem } from '@/types';
import { ArrowLeft } from 'lucide-react';

type Position = {
    id: number;
    title: string;
    department: string | null;
};

type Posting = {
    id: number;
    title: string;
    position_id: number | null;
    department: string | null;
    location: string | null;
    employment_type: string;
    description: string;
    requirements: string | null;
    salary_range_min: number | null;
    salary_range_max: number | null;
    show_salary: boolean;
    closes_at: string | null;
};

type Props = {
    positions: Position[];
    posting?: Posting;
};

export default function CreateJobPosting({ positions, posting }: Props) {
    const isEditing = !!posting;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Job Postings', href: '/hr/job-postings' },
        { title: isEditing ? 'Edit Posting' : 'Create Posting', href: '#' },
    ];

    const { data, setData, post, put, processing, errors } = useForm({
        title: posting?.title || '',
        position_id: posting?.position_id?.toString() || '',
        department: posting?.department || '',
        location: posting?.location || '',
        employment_type: posting?.employment_type || 'full_time',
        description: posting?.description || '',
        requirements: posting?.requirements || '',
        salary_range_min: posting?.salary_range_min?.toString() || '',
        salary_range_max: posting?.salary_range_max?.toString() || '',
        show_salary: posting?.show_salary || false,
        closes_at: posting?.closes_at || '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (isEditing) {
            put(`/hr/job-postings/${posting!.id}`);
        } else {
            post('/hr/job-postings');
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={isEditing ? 'Edit Job Posting' : 'Create Job Posting'} />
            <div className="flex flex-col gap-6 p-6 max-w-2xl mx-auto">
                <div className="flex items-center gap-3">
                    <Button variant="ghost" size="sm" onClick={() => window.history.back()}>
                        <ArrowLeft className="h-4 w-4" />
                    </Button>
                    <h1 className="text-2xl font-bold">{isEditing ? 'Edit Job Posting' : 'Create Job Posting'}</h1>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Posting Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label htmlFor="title">Title *</Label>
                                <Input
                                    id="title"
                                    value={data.title}
                                    onChange={(e) => setData('title', e.target.value)}
                                    className="mt-1"
                                />
                                {errors.title && <p className="text-sm text-destructive mt-1">{errors.title}</p>}
                            </div>

                            <div>
                                <Label htmlFor="position_id">Linked Position</Label>
                                <Select value={data.position_id} onValueChange={(v) => setData('position_id', v)}>
                                    <SelectTrigger className="mt-1">
                                        <SelectValue placeholder="Select position (optional)" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {positions.map((p) => (
                                            <SelectItem key={p.id} value={p.id.toString()}>
                                                {p.title} {p.department ? `(${p.department})` : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label htmlFor="department">Department</Label>
                                    <Input
                                        id="department"
                                        value={data.department}
                                        onChange={(e) => setData('department', e.target.value)}
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="location">Location</Label>
                                    <Input
                                        id="location"
                                        value={data.location}
                                        onChange={(e) => setData('location', e.target.value)}
                                        className="mt-1"
                                    />
                                </div>
                            </div>

                            <div>
                                <Label htmlFor="employment_type">Employment Type *</Label>
                                <Select value={data.employment_type} onValueChange={(v) => setData('employment_type', v)}>
                                    <SelectTrigger className="mt-1">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="full_time">Full Time</SelectItem>
                                        <SelectItem value="part_time">Part Time</SelectItem>
                                        <SelectItem value="casual">Casual</SelectItem>
                                        <SelectItem value="fixed_term">Fixed Term</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label htmlFor="description">Description *</Label>
                                <Textarea
                                    id="description"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    rows={8}
                                    className="mt-1"
                                />
                                {errors.description && <p className="text-sm text-destructive mt-1">{errors.description}</p>}
                            </div>

                            <div>
                                <Label htmlFor="requirements">Requirements</Label>
                                <Textarea
                                    id="requirements"
                                    value={data.requirements}
                                    onChange={(e) => setData('requirements', e.target.value)}
                                    rows={5}
                                    className="mt-1"
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Salary & Closing</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label htmlFor="salary_range_min">Salary Range Min</Label>
                                    <Input
                                        id="salary_range_min"
                                        type="number"
                                        step="0.01"
                                        value={data.salary_range_min}
                                        onChange={(e) => setData('salary_range_min', e.target.value)}
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="salary_range_max">Salary Range Max</Label>
                                    <Input
                                        id="salary_range_max"
                                        type="number"
                                        step="0.01"
                                        value={data.salary_range_max}
                                        onChange={(e) => setData('salary_range_max', e.target.value)}
                                        className="mt-1"
                                    />
                                </div>
                            </div>

                            <div className="flex items-center gap-3">
                                <Switch
                                    id="show_salary"
                                    checked={data.show_salary}
                                    onCheckedChange={(v) => setData('show_salary', v)}
                                />
                                <Label htmlFor="show_salary">Show salary on public listing</Label>
                            </div>

                            <div>
                                <Label htmlFor="closes_at">Closing Date</Label>
                                <Input
                                    id="closes_at"
                                    type="date"
                                    value={data.closes_at}
                                    onChange={(e) => setData('closes_at', e.target.value)}
                                    className="mt-1"
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-3">
                        <Button type="button" variant="outline" onClick={() => window.history.back()}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {isEditing ? 'Update Posting' : 'Create Posting'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
