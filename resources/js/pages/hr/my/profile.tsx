import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type BreadcrumbItem } from '@/types';

interface EmployeeProfile {
    id: number;
    employee_number: string | null;
    position_title: string;
    employment_type: string;
    start_date: string | null;
    end_date: string | null;
    is_active: boolean;
    personal_email: string | null;
    phone: string | null;
    home_address: string | null;
    emergency_contact_name: string | null;
    emergency_contact_phone: string | null;
    emergency_contact_relationship: string | null;
    user: { id: number; name: string; email: string; avatar?: string | null };
    primary_site: { id: number; name: string } | null;
}

interface Props {
    profile: EmployeeProfile | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/my' },
    { title: 'My HR', href: '/hr/my' },
    { title: 'My Profile', href: '/hr/my/profile' },
];

export default function MyProfile({ profile }: Props) {
    const form = useForm({
        personal_email: profile?.personal_email || '',
        phone: profile?.phone || '',
        home_address: profile?.home_address || '',
        emergency_contact_name: profile?.emergency_contact_name || '',
        emergency_contact_phone: profile?.emergency_contact_phone || '',
        emergency_contact_relationship: profile?.emergency_contact_relationship || '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.put('/hr/my/profile', { preserveScroll: true });
    }

    if (!profile) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="My Profile" />
                <div className="flex flex-col gap-6 p-6">
                    <h1 className="text-2xl font-bold">My Profile</h1>
                    <Card>
                        <CardContent className="py-8 text-center text-muted-foreground">
                            <p>No employee profile has been set up for your account yet.</p>
                            <p className="mt-1 text-sm">Please contact your HR administrator.</p>
                        </CardContent>
                    </Card>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My Profile" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center gap-4">
                    <Avatar className="h-16 w-16">
                        <AvatarImage src={profile.user.avatar ?? undefined} alt={profile.user.name} />
                        <AvatarFallback className="text-lg">
                            {profile.user.name.split(' ').map((n) => n[0]).join('').slice(0, 2).toUpperCase()}
                        </AvatarFallback>
                    </Avatar>
                    <div>
                        <h1 className="text-2xl font-bold">{profile.user.name}</h1>
                        <p className="text-sm text-muted-foreground">{profile.position_title}</p>
                    </div>
                </div>

                {/* Read-Only Employment Info */}
                <Card>
                    <CardHeader>
                        <CardTitle>Employment Information</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div>
                                <p className="text-sm text-muted-foreground">Name</p>
                                <p className="font-medium">{profile.user.name}</p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Work Email</p>
                                <p className="font-medium">{profile.user.email}</p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Employee Number</p>
                                <p className="font-medium">{profile.employee_number || '\u2014'}</p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Position</p>
                                <p className="font-medium">{profile.position_title}</p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Employment Type</p>
                                <Badge variant="outline" className="capitalize">
                                    {profile.employment_type.replace(/_/g, ' ')}
                                </Badge>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Status</p>
                                <Badge variant={profile.is_active ? 'default' : 'secondary'}>
                                    {profile.is_active ? 'Active' : 'Inactive'}
                                </Badge>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Start Date</p>
                                <p className="font-medium">{profile.start_date || '\u2014'}</p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Site</p>
                                <p className="font-medium">{profile.primary_site?.name || '\u2014'}</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Editable Personal Info */}
                <Card>
                    <CardHeader>
                        <CardTitle>Personal Information</CardTitle>
                        <p className="text-sm text-muted-foreground">
                            Update your personal contact details and emergency contacts.
                        </p>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-6">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="personal_email">Personal Email</Label>
                                    <Input
                                        id="personal_email"
                                        type="email"
                                        value={form.data.personal_email}
                                        onChange={(e) => form.setData('personal_email', e.target.value)}
                                        placeholder="your.email@example.com"
                                    />
                                    {form.errors.personal_email && (
                                        <p className="text-xs text-destructive">{form.errors.personal_email}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="phone">Phone</Label>
                                    <Input
                                        id="phone"
                                        type="tel"
                                        value={form.data.phone}
                                        onChange={(e) => form.setData('phone', e.target.value)}
                                        placeholder="+64 21 000 0000"
                                    />
                                    {form.errors.phone && (
                                        <p className="text-xs text-destructive">{form.errors.phone}</p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="home_address">Home Address</Label>
                                <Textarea
                                    id="home_address"
                                    rows={3}
                                    value={form.data.home_address}
                                    onChange={(e) => form.setData('home_address', e.target.value)}
                                    placeholder="Enter your home address..."
                                />
                                {form.errors.home_address && (
                                    <p className="text-xs text-destructive">{form.errors.home_address}</p>
                                )}
                            </div>

                            <div>
                                <h3 className="mb-3 text-sm font-semibold">Emergency Contact</h3>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="space-y-2">
                                        <Label htmlFor="emergency_contact_name">Name</Label>
                                        <Input
                                            id="emergency_contact_name"
                                            value={form.data.emergency_contact_name}
                                            onChange={(e) => form.setData('emergency_contact_name', e.target.value)}
                                            placeholder="Contact name"
                                        />
                                        {form.errors.emergency_contact_name && (
                                            <p className="text-xs text-destructive">{form.errors.emergency_contact_name}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="emergency_contact_phone">Phone</Label>
                                        <Input
                                            id="emergency_contact_phone"
                                            type="tel"
                                            value={form.data.emergency_contact_phone}
                                            onChange={(e) => form.setData('emergency_contact_phone', e.target.value)}
                                            placeholder="+64 21 000 0000"
                                        />
                                        {form.errors.emergency_contact_phone && (
                                            <p className="text-xs text-destructive">{form.errors.emergency_contact_phone}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="emergency_contact_relationship">Relationship</Label>
                                        <Input
                                            id="emergency_contact_relationship"
                                            value={form.data.emergency_contact_relationship}
                                            onChange={(e) => form.setData('emergency_contact_relationship', e.target.value)}
                                            placeholder="e.g. Spouse, Parent"
                                        />
                                        {form.errors.emergency_contact_relationship && (
                                            <p className="text-xs text-destructive">{form.errors.emergency_contact_relationship}</p>
                                        )}
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center gap-3">
                                <Button type="submit" disabled={form.processing}>
                                    {form.processing ? 'Saving...' : 'Save Changes'}
                                </Button>
                                {form.recentlySuccessful && (
                                    <span className="text-sm text-emerald-500">Saved successfully.</span>
                                )}
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
