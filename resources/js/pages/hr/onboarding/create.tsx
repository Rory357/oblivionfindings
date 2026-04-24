import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type BreadcrumbItem } from '@/types';
import { ArrowLeft, UserPlus } from 'lucide-react';

interface Employee {
    id: number;
    name: string;
    email?: string;
    position_title?: string;
}

interface Props {
    employees: Employee[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Onboarding', href: '/hr/onboarding' },
    { title: 'Create Checklist', href: '/hr/onboarding/create' },
];

export default function CreateOnboarding({ employees }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        employee_profile_id: '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post('/hr/onboarding');
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Onboarding Checklist" />
            <div className="flex flex-col gap-6 p-6 max-w-2xl mx-auto">
                <div className="flex items-center gap-4">
                    <Link href="/hr/onboarding">
                        <Button variant="outline" size="icon">
                            <ArrowLeft className="h-4 w-4" />
                        </Button>
                    </Link>
                    <h1 className="text-2xl font-bold">Create Onboarding Checklist</h1>
                </div>

                {employees.length === 0 ? (
                    <Card>
                        <CardContent className="p-8 text-center">
                            <UserPlus className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                            <h3 className="text-lg font-medium mb-2">No eligible employees</h3>
                            <p className="text-sm text-muted-foreground mb-4">
                                All active employees already have onboarding checklists in progress, 
                                or no employee profiles have been set up yet.
                            </p>
                            <Link href="/hr/onboarding">
                                <Button variant="outline">Back to Onboarding</Button>
                            </Link>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle>Select Employee</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-6">
                                <div className="space-y-2">
                                    <Label htmlFor="employee_profile_id">Employee</Label>
                                    <Select
                                        value={data.employee_profile_id}
                                        onValueChange={(value) => setData('employee_profile_id', value)}
                                    >
                                        <SelectTrigger id="employee_profile_id">
                                            <SelectValue placeholder="Select an employee..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {employees.map((employee) => (
                                                <SelectItem key={employee.id} value={String(employee.id)}>
                                                    <div className="flex flex-col items-start">
                                                        <span className="font-medium">{employee.name}</span>
                                                        <span className="text-xs text-muted-foreground">
                                                            {employee.position_title || 'No position'} • {employee.email || 'No email'}
                                                        </span>
                                                    </div>
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.employee_profile_id && (
                                        <p className="text-sm text-status-critical">{errors.employee_profile_id}</p>
                                    )}
                                </div>

                                <div className="flex items-center gap-3 justify-end">
                                    <Link href="/hr/onboarding">
                                        <Button type="button" variant="outline">Cancel</Button>
                                    </Link>
                                    <Button type="submit" disabled={processing}>
                                        Create Checklist
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
