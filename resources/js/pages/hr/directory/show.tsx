import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Mail, Phone, MapPin, Calendar, Briefcase, Users, Heart, Flame } from 'lucide-react';

interface Employee {
    id: number;
    name: string;
    full_name: string;
    email: string | null;
    phone: string | null;
    position_title: string | null;
    department: string | null;
    team: string | null;
    site: string | null;
    profile_photo_path: string | null;
    bio: string | null;
    start_date: string | null;
    is_first_aider: boolean;
    is_fire_warden: boolean;
}

interface Props {
    employee: Employee;
}

function getInitials(name: string): string {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

const breadcrumbs = [
    { title: 'HR', href: '/hr' },
    { title: 'Directory', href: '/hr/directory' },
    { title: 'Profile', href: '#' },
];

export default function DirectoryShow({ employee }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${employee.name} - Directory`} />

            <PageShell>
                <PageHeader
                    title={employee.name}
                    backHref="/hr/directory"
                    backLabel="Back to Directory"
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Profile Card */}
                    <Card className="lg:col-span-1">
                        <CardContent className="flex flex-col items-center p-6 text-center">
                            {/* Photo */}
                            {employee.profile_photo_path ? (
                                <img
                                    src={`/storage/${employee.profile_photo_path}`}
                                    alt={employee.name}
                                    className="mb-4 h-32 w-32 rounded-full object-cover"
                                />
                            ) : (
                                <div className="mb-4 flex h-32 w-32 items-center justify-center rounded-full bg-primary/10 text-3xl font-semibold text-primary">
                                    {getInitials(employee.name)}
                                </div>
                            )}

                            <h2 className="text-xl font-semibold">{employee.name}</h2>
                            {employee.full_name !== employee.name && (
                                <p className="text-sm text-muted-foreground">({employee.full_name})</p>
                            )}

                            {employee.position_title && (
                                <p className="mt-1 text-muted-foreground">{employee.position_title}</p>
                            )}

                            {/* Badges */}
                            <div className="mt-3 flex flex-wrap justify-center gap-2">
                                {employee.department && (
                                    <Badge variant="secondary">{employee.department}</Badge>
                                )}
                                {employee.is_first_aider && (
                                    <Badge variant="outline" className="border-emerald-500/30 text-emerald-400 bg-emerald-500/10">
                                        <Heart className="mr-1 h-3 w-3" />
                                        First Aider
                                    </Badge>
                                )}
                                {employee.is_fire_warden && (
                                    <Badge variant="outline" className="border-orange-500/30 text-orange-400 bg-orange-500/10">
                                        <Flame className="mr-1 h-3 w-3" />
                                        Fire Warden
                                    </Badge>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Details */}
                    <div className="space-y-6 lg:col-span-2">
                        {/* Contact Information */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Contact Information</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <dl className="grid gap-4 sm:grid-cols-2">
                                    {employee.email && (
                                        <div className="flex items-start gap-3">
                                            <Mail className="mt-0.5 h-4 w-4 text-muted-foreground" />
                                            <div>
                                                <dt className="text-xs font-medium text-muted-foreground">Email</dt>
                                                <dd>
                                                    <a href={`mailto:${employee.email}`} className="text-sm hover:underline">
                                                        {employee.email}
                                                    </a>
                                                </dd>
                                            </div>
                                        </div>
                                    )}
                                    {employee.phone && (
                                        <div className="flex items-start gap-3">
                                            <Phone className="mt-0.5 h-4 w-4 text-muted-foreground" />
                                            <div>
                                                <dt className="text-xs font-medium text-muted-foreground">Phone</dt>
                                                <dd>
                                                    <a href={`tel:${employee.phone}`} className="text-sm hover:underline">
                                                        {employee.phone}
                                                    </a>
                                                </dd>
                                            </div>
                                        </div>
                                    )}
                                    {employee.site && (
                                        <div className="flex items-start gap-3">
                                            <MapPin className="mt-0.5 h-4 w-4 text-muted-foreground" />
                                            <div>
                                                <dt className="text-xs font-medium text-muted-foreground">Site</dt>
                                                <dd className="text-sm">{employee.site}</dd>
                                            </div>
                                        </div>
                                    )}
                                    {employee.start_date && (
                                        <div className="flex items-start gap-3">
                                            <Calendar className="mt-0.5 h-4 w-4 text-muted-foreground" />
                                            <div>
                                                <dt className="text-xs font-medium text-muted-foreground">Start Date</dt>
                                                <dd className="text-sm">{employee.start_date}</dd>
                                            </div>
                                        </div>
                                    )}
                                </dl>
                            </CardContent>
                        </Card>

                        {/* Organisation */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Organisation</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <dl className="grid gap-4 sm:grid-cols-2">
                                    {employee.position_title && (
                                        <div className="flex items-start gap-3">
                                            <Briefcase className="mt-0.5 h-4 w-4 text-muted-foreground" />
                                            <div>
                                                <dt className="text-xs font-medium text-muted-foreground">Position</dt>
                                                <dd className="text-sm">{employee.position_title}</dd>
                                            </div>
                                        </div>
                                    )}
                                    {employee.department && (
                                        <div className="flex items-start gap-3">
                                            <Users className="mt-0.5 h-4 w-4 text-muted-foreground" />
                                            <div>
                                                <dt className="text-xs font-medium text-muted-foreground">Department</dt>
                                                <dd className="text-sm">{employee.department}</dd>
                                            </div>
                                        </div>
                                    )}
                                    {employee.team && (
                                        <div className="flex items-start gap-3">
                                            <Users className="mt-0.5 h-4 w-4 text-muted-foreground" />
                                            <div>
                                                <dt className="text-xs font-medium text-muted-foreground">Team</dt>
                                                <dd className="text-sm">{employee.team}</dd>
                                            </div>
                                        </div>
                                    )}
                                </dl>
                            </CardContent>
                        </Card>

                        {/* Bio */}
                        {employee.bio && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>About</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-sm leading-relaxed text-muted-foreground whitespace-pre-line">
                                        {employee.bio}
                                    </p>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
