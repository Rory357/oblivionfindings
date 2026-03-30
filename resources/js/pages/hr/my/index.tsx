import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem } from '@/types';
import { Clock, AlertTriangle } from 'lucide-react';

interface Props {
    profile: {
        id: number;
        position_title: string;
        employment_type: string;
        start_date: string | null;
        primary_site_id: number | null;
    } | null;
    pendingLeave: number;
    leaveBalances: Array<{ leave_type: string; entitlement_hours: number; taken_hours: number; remaining_hours: number }>;
    complianceSummary: { compliant: number; expiring_soon: number; expired: number; not_started: number };
    complianceStatuses: Array<{ id: number; status: string; requirement: { name: string; category: string } }>;
    policiesDue: number;
    pendingReviews: number;
    activeGoals: number;
    availableSurveys: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/my' },
    { title: 'My HR', href: '/hr/my' },
];

export default function MyHrIndex({ profile, pendingLeave, leaveBalances, complianceSummary, policiesDue, pendingReviews, activeGoals, availableSurveys }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My HR" />
            <div className="flex flex-col gap-6 p-6">
                <h1 className="text-2xl font-bold">My HR</h1>

                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">My Profile</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {profile ? (
                                <div>
                                    <p className="text-lg font-semibold">{profile.position_title}</p>
                                    <p className="text-sm text-muted-foreground">{profile.employment_type?.replace('_', ' ')}</p>
                                    <Link href="/hr/my/profile">
                                        <Button variant="outline" size="sm" className="mt-2">View Profile</Button>
                                    </Link>
                                </div>
                            ) : (
                                <div>
                                    <div className="flex items-center gap-2 text-sm text-amber-600 dark:text-amber-400">
                                        <AlertTriangle className="h-4 w-4 shrink-0" />
                                        <span>Profile not set up yet</span>
                                    </div>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Please contact your HR administrator or manager to have your employee profile created.
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Leave</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {pendingLeave > 0 && <Badge variant="secondary" className="mb-2">{pendingLeave} pending</Badge>}
                            {leaveBalances.length > 0 ? (
                                <div className="space-y-1">
                                    {leaveBalances.slice(0, 3).map((b, i) => (
                                        <div key={i} className="flex justify-between text-sm">
                                            <span className="capitalize">{b.leave_type.replace('_', ' ')}</span>
                                            <span className="font-medium">{b.remaining_hours ?? 0}h left</span>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">No leave balances recorded yet.</p>
                            )}
                            <div className="mt-2 flex gap-2">
                                <Link href="/hr/my/leave">
                                    <Button variant="outline" size="sm">My Leave</Button>
                                </Link>
                                <Link href="/hr/my/leave?action=request">
                                    <Button size="sm">Request Leave</Button>
                                </Link>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Compliance</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-1 text-sm">
                                <div className="flex justify-between">
                                    <span>Compliant</span>
                                    <Badge variant="default">{complianceSummary.compliant}</Badge>
                                </div>
                                {complianceSummary.expiring_soon > 0 && (
                                    <div className="flex justify-between">
                                        <span>Expiring Soon</span>
                                        <Badge variant="secondary">{complianceSummary.expiring_soon}</Badge>
                                    </div>
                                )}
                                {complianceSummary.expired > 0 && (
                                    <div className="flex justify-between">
                                        <span>Expired</span>
                                        <Badge variant="destructive">{complianceSummary.expired}</Badge>
                                    </div>
                                )}
                                {complianceSummary.not_started > 0 && (
                                    <div className="flex justify-between">
                                        <span>Not Started</span>
                                        <Badge variant="outline">{complianceSummary.not_started}</Badge>
                                    </div>
                                )}
                            </div>
                            <Link href="/hr/my/training">
                                <Button variant="outline" size="sm" className="mt-2">My Training</Button>
                            </Link>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Policies</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {policiesDue > 0 ? (
                                <p className="text-sm">
                                    <Badge variant="secondary">{policiesDue}</Badge> policies require your attestation
                                </p>
                            ) : (
                                <p className="text-sm text-muted-foreground">All policies attested.</p>
                            )}
                            <Link href="/hr/my/policies">
                                <Button variant="outline" size="sm" className="mt-2">My Policies</Button>
                            </Link>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Performance Reviews</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {pendingReviews > 0 ? (
                                <p className="text-sm">
                                    <Badge variant="secondary">{pendingReviews}</Badge> review{pendingReviews !== 1 ? 's' : ''} awaiting your input
                                </p>
                            ) : (
                                <p className="text-sm text-muted-foreground">No pending reviews.</p>
                            )}
                            <Link href="/hr/my/reviews">
                                <Button variant="outline" size="sm" className="mt-2">My Reviews</Button>
                            </Link>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Development Goals</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {activeGoals > 0 ? (
                                <p className="text-sm">
                                    <Badge variant="secondary">{activeGoals}</Badge> active goal{activeGoals !== 1 ? 's' : ''}
                                </p>
                            ) : (
                                <p className="text-sm text-muted-foreground">No active goals.</p>
                            )}
                            <Link href="/hr/my/goals">
                                <Button variant="outline" size="sm" className="mt-2">My Goals</Button>
                            </Link>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">My Time</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Clock className="h-4 w-4" />
                                <span>Track your hours and clock in/out</span>
                            </div>
                            <Link href="/hr/my/time">
                                <Button variant="outline" size="sm" className="mt-2">Clock In / Out</Button>
                            </Link>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Surveys</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {availableSurveys > 0 ? (
                                <p className="text-sm">
                                    <Badge variant="secondary">{availableSurveys}</Badge> survey{availableSurveys !== 1 ? 's' : ''} available
                                </p>
                            ) : (
                                <p className="text-sm text-muted-foreground">No open surveys.</p>
                            )}
                            <Link href="/hr/my/surveys">
                                <Button variant="outline" size="sm" className="mt-2">My Surveys</Button>
                            </Link>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
