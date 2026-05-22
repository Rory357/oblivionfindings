import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { User, UserCircle, UserPlus, Users } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import InputError from '@/components/input-error';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'System', href: '/system/access' },
    { title: 'Users', href: '/system/users' },
    { title: 'Create', href: '/system/users/create' },
];

type Client = {
    id: number;
    first_name: string;
    last_name: string;
    nhi_number?: string | null;
};

type Role = {
    id: number;
    name: string;
    label: string;
    level: number;
    type: 'system' | 'custom';
};

type Props = {
    clients: Client[];
    roles: Role[];
    can: {
        createStaff: boolean;
        createClient: boolean;
    };
};

export default function CreateUser({ clients, roles, can }: Props) {
    const { url } = usePage();
    const urlParams = new URLSearchParams(url.split('?')[1]);
    const typeParam = urlParams.get('type') as 'staff' | 'client' | 'next_of_kin' | null;
    
    // Determine allowed types based on permissions
    const allowedTypes = [
        can.createStaff ? 'staff' : null,
        can.createClient ? 'client' : null,
        can.createClient ? 'next_of_kin' : null, // Next of kin requires client permission
    ].filter(Boolean) as Array<'staff' | 'client' | 'next_of_kin'>;
    
    // Default to first allowed type if URL param not allowed
    const defaultType = allowedTypes[0] || 'staff';
    const initialType = typeParam && allowedTypes.includes(typeParam as any) 
        ? typeParam as 'staff' | 'client' | 'next_of_kin'
        : defaultType;
    
    const [userType, setUserType] = useState<'staff' | 'client' | 'next_of_kin'>(initialType);

    const form = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        user_type: initialType,
        role_ids: [] as number[],
        // Staff specific
        'staff.job_title': '',
        'staff.department': '',
        'staff.employee_id': '',
        // Client specific
        'client.nhi_number': '',
        'client.first_name': '',
        'client.last_name': '',
        'client.date_of_birth': '',
        // Next of Kin specific
        'next_of_kin.client_id': '',
        'next_of_kin.relationship': '',
        'next_of_kin.is_primary_contact': false,
        'next_of_kin.is_emergency_contact': true,
    });

    const setNestedData = (key: string, value: unknown) => {
        (form.setData as unknown as (k: string, v: unknown) => void)(key, value);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/system/users');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create User" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/system/users"
                        icon={UserPlus}
                        title="Create User"
                        description="Create a new user account and assign their organization role."
                    />
                }
            >
                <form onSubmit={handleSubmit} className="space-y-6 max-w-3xl">
                    {/* User Type Selection */}
                    <Card>
                        <CardHeader>
                            <CardTitle>User Type</CardTitle>
                            <CardDescription>
                                Select the type of user you want to create.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className={`grid gap-4 ${
                                [can.createStaff, can.createClient, can.createClient].filter(Boolean).length === 3 
                                    ? 'grid-cols-3' 
                                    : [can.createStaff, can.createClient, can.createClient].filter(Boolean).length === 2 
                                        ? 'grid-cols-2' 
                                        : 'grid-cols-1'
                            }`}>
                                {can.createStaff && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => {
                                            setUserType('staff');
                                            form.setData('user_type', 'staff');
                                        }}
                                        className={`h-auto flex-col justify-between whitespace-normal rounded-md border-2 p-4 transition-colors ${
                                            userType === 'staff'
                                                ? 'border-primary bg-primary/5'
                                                : 'border-muted bg-transparent hover:bg-muted'
                                        }`}
                                    >
                                        <Users className="mb-3 h-6 w-6" />
                                        <div className="text-center">
                                            <div className="font-medium">Staff</div>
                                            <div className="text-xs text-muted-foreground">
                                                Organization employee
                                            </div>
                                        </div>
                                    </Button>
                                )}
                                {can.createClient && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => {
                                            setUserType('client');
                                            form.setData('user_type', 'client');
                                        }}
                                        className={`h-auto flex-col justify-between whitespace-normal rounded-md border-2 p-4 transition-colors ${
                                            userType === 'client'
                                                ? 'border-primary bg-primary/5'
                                                : 'border-muted bg-transparent hover:bg-muted'
                                        }`}
                                    >
                                        <User className="mb-3 h-6 w-6" />
                                        <div className="text-center">
                                            <div className="font-medium">Client</div>
                                            <div className="text-xs text-muted-foreground">
                                                Service recipient
                                            </div>
                                        </div>
                                    </Button>
                                )}
                                {can.createClient && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => {
                                            setUserType('next_of_kin');
                                            form.setData('user_type', 'next_of_kin');
                                        }}
                                        className={`h-auto flex-col justify-between whitespace-normal rounded-md border-2 p-4 transition-colors ${
                                            userType === 'next_of_kin'
                                                ? 'border-primary bg-primary/5'
                                                : 'border-muted bg-transparent hover:bg-muted'
                                        }`}
                                    >
                                        <UserCircle className="mb-3 h-6 w-6" />
                                        <div className="text-center">
                                            <div className="font-medium">Next of Kin</div>
                                            <div className="text-xs text-muted-foreground">
                                                Family member
                                            </div>
                                        </div>
                                    </Button>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Basic Information */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Basic Information</CardTitle>
                            <CardDescription>
                                Enter the user&apos;s account details.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="name">Full Name</Label>
                                    <Input
                                        id="name"
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                        placeholder="John Doe"
                                    />
                                    <InputError message={form.errors.name} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="email">Email Address</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        value={form.data.email}
                                        onChange={(e) => form.setData('email', e.target.value)}
                                        placeholder="john@example.com"
                                    />
                                    <InputError message={form.errors.email} />
                                </div>
                            </div>
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="password">Password</Label>
                                    <Input
                                        id="password"
                                        type="password"
                                        value={form.data.password}
                                        onChange={(e) => form.setData('password', e.target.value)}
                                    />
                                    <InputError message={form.errors.password} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="password_confirmation">Confirm Password</Label>
                                    <Input
                                        id="password_confirmation"
                                        type="password"
                                        value={form.data.password_confirmation}
                                        onChange={(e) =>
                                            form.setData('password_confirmation', e.target.value)
                                        }
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Type-Specific Information */}
                    {userType === 'staff' && (
                        <>
                            <Card>
                                <CardHeader>
                                    <CardTitle>Employment Details</CardTitle>
                                    <CardDescription>
                                        Enter the staff member&apos;s employment information.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="employee_id">Employee ID</Label>
                                            <Input
                                                id="employee_id"
                                                value={form.data['staff.employee_id']}
                                                onChange={(e) =>
                                                    setNestedData('staff.employee_id', e.target.value)
                                                }
                                                placeholder="EMP001"
                                            />
                                            <InputError message={form.errors['staff.employee_id']} />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="job_title">Job Title *</Label>
                                            <Input
                                                id="job_title"
                                                value={form.data['staff.job_title']}
                                                onChange={(e) =>
                                                    setNestedData('staff.job_title', e.target.value)
                                                }
                                                placeholder="Support Worker"
                                            />
                                            <InputError message={form.errors['staff.job_title']} />
                                        </div>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="department">Department</Label>
                                        <Input
                                            id="department"
                                            value={form.data['staff.department']}
                                            onChange={(e) =>
                                                setNestedData('staff.department', e.target.value)
                                            }
                                            placeholder="Clinical Services"
                                        />
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Role Assignment</CardTitle>
                                    <CardDescription>
                                        Assign roles to this staff member. Defaults to Support Worker if none selected.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-4">
                                        <div className="space-y-2">
                                            <Label>Select Roles</Label>
                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                {roles
                                                    .filter((r) => r.type === 'system')
                                                    .map((role) => {
                                                        const checked = form.data.role_ids.includes(role.id);
                                                        return (
                                                            <label
                                                                key={role.id}
                                                                className={`flex items-center gap-3 p-3 rounded-md border cursor-pointer transition-colors ${
                                                                    checked
                                                                        ? 'border-primary bg-primary/5'
                                                                        : 'border-muted hover:bg-muted'
                                                                }`}
                                                            >
                                                                <input
                                                                    type="checkbox"
                                                                    className="h-4 w-4"
                                                                    checked={checked}
                                                                    onChange={(e) => {
                                                                        const next = e.target.checked
                                                                            ? [...form.data.role_ids, role.id]
                                                                            : form.data.role_ids.filter((id) => id !== role.id);
                                                                        form.setData('role_ids', next);
                                                                    }}
                                                                />
                                                                <div className="flex-1">
                                                                    <div className="font-medium text-sm">{role.label}</div>
                                                                    <div className="text-xs text-muted-foreground">
                                                                        Level {role.level}
                                                                    </div>
                                                                </div>
                                                            </label>
                                                        );
                                                    })}
                                            </div>
                                        </div>
                                        {roles.some((r) => r.type === 'custom') && (
                                            <div className="space-y-2 pt-2">
                                                <Label>Custom Roles</Label>
                                                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                    {roles
                                                        .filter((r) => r.type === 'custom')
                                                        .map((role) => {
                                                            const checked = form.data.role_ids.includes(role.id);
                                                            return (
                                                                <label
                                                                    key={role.id}
                                                                    className={`flex items-center gap-3 p-3 rounded-md border cursor-pointer transition-colors ${
                                                                        checked
                                                                            ? 'border-primary bg-primary/5'
                                                                            : 'border-muted hover:bg-muted'
                                                                    }`}
                                                                >
                                                                    <input
                                                                        type="checkbox"
                                                                        className="h-4 w-4"
                                                                        checked={checked}
                                                                        onChange={(e) => {
                                                                            const next = e.target.checked
                                                                                ? [...form.data.role_ids, role.id]
                                                                                : form.data.role_ids.filter((id) => id !== role.id);
                                                                            form.setData('role_ids', next);
                                                                        }}
                                                                    />
                                                                    <div className="flex-1">
                                                                        <div className="font-medium text-sm">{role.label}</div>
                                                                        <div className="text-xs text-muted-foreground">
                                                                            Level {role.level}
                                                                        </div>
                                                                    </div>
                                                                </label>
                                                            );
                                                        })}
                                                </div>
                                            </div>
                                        )}
                                        <InputError message={form.errors.role_ids} />
                                    </div>
                                </CardContent>
                            </Card>
                        </>
                    )}

                    {userType === 'client' && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Client Information</CardTitle>
                                <CardDescription>
                                    Enter the client&apos;s personal information.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="nhi_number">NHI Number *</Label>
                                    <Input
                                        id="nhi_number"
                                        value={form.data['client.nhi_number']}
                                        onChange={(e) =>
                                            setNestedData('client.nhi_number', e.target.value.toUpperCase())
                                        }
                                        placeholder="ABC1234"
                                        maxLength={10}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        3 letters followed by 4 numbers (e.g., ABC1234)
                                    </p>
                                    <InputError message={form.errors['client.nhi_number']} />
                                </div>
                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="first_name">First Name *</Label>
                                        <Input
                                            id="first_name"
                                            value={form.data['client.first_name']}
                                            onChange={(e) =>
                                                setNestedData('client.first_name', e.target.value)
                                            }
                                        />
                                        <InputError message={form.errors['client.first_name']} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="last_name">Last Name *</Label>
                                        <Input
                                            id="last_name"
                                            value={form.data['client.last_name']}
                                            onChange={(e) =>
                                                setNestedData('client.last_name', e.target.value)
                                            }
                                        />
                                        <InputError message={form.errors['client.last_name']} />
                                    </div>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="date_of_birth">Date of Birth</Label>
                                    <Input
                                        id="date_of_birth"
                                        type="date"
                                        value={form.data['client.date_of_birth']}
                                        onChange={(e) =>
                                            setNestedData('client.date_of_birth', e.target.value)
                                        }
                                    />
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {userType === 'next_of_kin' && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Next of Kin Information</CardTitle>
                                <CardDescription>
                                    Link this next of kin to a client and specify their relationship.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="nok_client">Linked Client *</Label>
                                    <Select
                                        value={form.data['next_of_kin.client_id']}
                                        onValueChange={(value) =>
                                            setNestedData('next_of_kin.client_id', value)
                                        }
                                    >
                                        <SelectTrigger id="nok_client" className={form.errors['next_of_kin.client_id'] ? 'border-status-critical/30' : ''}>
                                            <SelectValue placeholder="Select a client..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {clients.map((client) => (
                                                <SelectItem key={client.id} value={String(client.id)}>
                                                    {client.first_name} {client.last_name}
                                                    {client.nhi_number ? ` (NHI: ${client.nhi_number})` : ''}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={form.errors['next_of_kin.client_id']} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="nok_relationship">Relationship *</Label>
                                    <Select
                                        value={form.data['next_of_kin.relationship']}
                                        onValueChange={(value) =>
                                            setNestedData('next_of_kin.relationship', value)
                                        }
                                    >
                                        <SelectTrigger id="nok_relationship" className={form.errors['next_of_kin.relationship'] ? 'border-status-critical/30' : ''}>
                                            <SelectValue placeholder="Select relationship..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="parent">Parent</SelectItem>
                                            <SelectItem value="sibling">Sibling</SelectItem>
                                            <SelectItem value="spouse">Spouse</SelectItem>
                                            <SelectItem value="child">Child</SelectItem>
                                            <SelectItem value="grandparent">Grandparent</SelectItem>
                                            <SelectItem value="grandchild">Grandchild</SelectItem>
                                            <SelectItem value="aunt_uncle">Aunt/Uncle</SelectItem>
                                            <SelectItem value="niece_nephew">Niece/Nephew</SelectItem>
                                            <SelectItem value="cousin">Cousin</SelectItem>
                                            <SelectItem value="guardian">Legal Guardian</SelectItem>
                                            <SelectItem value="friend">Friend</SelectItem>
                                            <SelectItem value="other">Other</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError message={form.errors['next_of_kin.relationship']} />
                                </div>
                                <div className="flex flex-col gap-3 pt-2">
                                    <div className="flex items-center space-x-2">
                                        <Checkbox
                                            id="nok_primary"
                                            checked={form.data['next_of_kin.is_primary_contact']}
                                            onCheckedChange={(checked) =>
                                                setNestedData('next_of_kin.is_primary_contact', checked as boolean)
                                            }
                                        />
                                        <Label htmlFor="nok_primary" className="text-sm font-normal">
                                            Primary contact
                                        </Label>
                                    </div>
                                    <div className="flex items-center space-x-2">
                                        <Checkbox
                                            id="nok_emergency"
                                            checked={form.data['next_of_kin.is_emergency_contact']}
                                            onCheckedChange={(checked) =>
                                                setNestedData('next_of_kin.is_emergency_contact', checked as boolean)
                                            }
                                        />
                                        <Label htmlFor="nok_emergency" className="text-sm font-normal">
                                            Emergency contact
                                        </Label>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Submit */}
                    <div className="flex items-center justify-end gap-4">
                        <Link href="/system/users">
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={form.processing}>
                            Create User
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
