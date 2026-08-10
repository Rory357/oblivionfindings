import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';

interface EmployeeOption {
    id: number;
    name: string | null;
    employee_number: string | null;
}

interface Props {
    employees: EmployeeOption[];
    categories: string[];
}

export default function UploadDocument({ employees, categories }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Documents', href: '/hr/documents' },
        { title: 'Upload', href: '/hr/documents/upload' },
    ];

    const { data, setData, post, processing, errors } = useForm({
        employee_profile_id: '',
        title: '',
        category: categories[0] || 'other',
        is_restricted: false,
        file: null as File | null,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/hr/documents');
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Upload HR Document" />
            <PageLayout
                hero={
                    <PageHero
                        category="hr"
                        variant="compact"
                        backHref="/hr/documents"
                        title="Upload HR Document"
                        description="Attach a document file to an employee profile."
                    />
                }
            >
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Document Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label>Employee</Label>
                                <Select
                                    value={
                                        data.employee_profile_id || '__none__'
                                    }
                                    onValueChange={(value) =>
                                        setData(
                                            'employee_profile_id',
                                            value === '__none__' ? '' : value,
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select employee" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">
                                            Select employee
                                        </SelectItem>
                                        {employees.map((employee) => (
                                            <SelectItem
                                                key={employee.id}
                                                value={String(employee.id)}
                                            >
                                                {employee.name ||
                                                    `Employee #${employee.id}`}
                                                {employee.employee_number
                                                    ? ` (${employee.employee_number})`
                                                    : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.employee_profile_id && (
                                    <p className="text-sm text-destructive">
                                        {errors.employee_profile_id}
                                    </p>
                                )}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="title">Title</Label>
                                    <Input
                                        id="title"
                                        value={data.title}
                                        onChange={(e) =>
                                            setData('title', e.target.value)
                                        }
                                        placeholder="e.g. Signed employment agreement"
                                    />
                                    {errors.title && (
                                        <p className="text-sm text-destructive">
                                            {errors.title}
                                        </p>
                                    )}
                                </div>
                                <div className="space-y-2">
                                    <Label>Category</Label>
                                    <Select
                                        value={data.category}
                                        onValueChange={(value) =>
                                            setData('category', value)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select category" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {categories.map((category) => (
                                                <SelectItem
                                                    key={category}
                                                    value={category}
                                                >
                                                    {category.replace('_', ' ')}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.category && (
                                        <p className="text-sm text-destructive">
                                            {errors.category}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="file">File</Label>
                                <Input
                                    id="file"
                                    type="file"
                                    onChange={(e) =>
                                        setData(
                                            'file',
                                            e.target.files?.[0] ?? null,
                                        )
                                    }
                                />
                                {errors.file && (
                                    <p className="text-sm text-destructive">
                                        {errors.file}
                                    </p>
                                )}
                            </div>

                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="is_restricted"
                                    checked={data.is_restricted}
                                    onCheckedChange={(value) =>
                                        setData('is_restricted', Boolean(value))
                                    }
                                />
                                <Label
                                    htmlFor="is_restricted"
                                    className="font-normal"
                                >
                                    Restrict access to HR managers
                                </Label>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Uploading...' : 'Upload Document'}
                        </Button>
                        <Button type="button" variant="outline" asChild>
                            <Link href="/hr/documents">Cancel</Link>
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
