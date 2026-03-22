import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type BreadcrumbItem } from '@/types';
import { useState } from 'react';

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
    todayCheckIn: { id: number; mood: string; energy_level: number | null; check_in_date: string } | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/my' },
    { title: 'My HR', href: '/hr/my' },
];

const moods = [
    { value: 'great', emoji: '😄', label: 'Great' },
    { value: 'good', emoji: '🙂', label: 'Good' },
    { value: 'okay', emoji: '😐', label: 'Okay' },
    { value: 'struggling', emoji: '😟', label: 'Struggling' },
    { value: 'bad', emoji: '😢', label: 'Bad' },
];

export default function MyHrIndex({ profile, pendingLeave, leaveBalances, complianceSummary, policiesDue, todayCheckIn }: Props) {
    const [selectedMood, setSelectedMood] = useState<string>('');

    const checkInForm = useForm({
        mood: '',
        energy_level: '' as string | number,
        workload_rating: '' as string | number,
        notes: '',
    });

    const handleCheckIn = (e: React.FormEvent) => {
        e.preventDefault();
        checkInForm.post('/hr/my/check-in', {
            preserveScroll: true,
        });
    };

    const selectMood = (mood: string) => {
        setSelectedMood(mood);
        checkInForm.setData('mood', mood);
    };

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
                                <p className="text-sm text-muted-foreground">No profile set up yet.</p>
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
                                            <span className="font-medium">{b.remaining_hours}h left</span>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">No leave balances.</p>
                            )}
                            <Link href="/hr/my/leave">
                                <Button variant="outline" size="sm" className="mt-2">My Leave</Button>
                            </Link>
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
                </div>

                {/* Daily Check-in Card */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Daily Check-in</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {todayCheckIn ? (
                            <div className="flex items-center gap-3">
                                <span className="text-3xl">
                                    {moods.find((m) => m.value === todayCheckIn.mood)?.emoji || '?'}
                                </span>
                                <div>
                                    <p className="font-medium capitalize">{todayCheckIn.mood}</p>
                                    <p className="text-sm text-muted-foreground">Already checked in today</p>
                                </div>
                            </div>
                        ) : (
                            <form onSubmit={handleCheckIn} className="space-y-4">
                                <div>
                                    <Label className="mb-2 block">How are you feeling today?</Label>
                                    <div className="flex gap-3">
                                        {moods.map((mood) => (
                                            <button
                                                key={mood.value}
                                                type="button"
                                                onClick={() => selectMood(mood.value)}
                                                className={`flex flex-col items-center gap-1 rounded-lg border-2 p-3 transition-colors ${
                                                    selectedMood === mood.value
                                                        ? 'border-primary bg-primary/5'
                                                        : 'border-transparent hover:border-muted-foreground/20'
                                                }`}
                                            >
                                                <span className="text-2xl">{mood.emoji}</span>
                                                <span className="text-xs text-muted-foreground">{mood.label}</span>
                                            </button>
                                        ))}
                                    </div>
                                    {checkInForm.errors.mood && (
                                        <p className="text-sm text-destructive mt-1">{checkInForm.errors.mood}</p>
                                    )}
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <Label className="mb-2 block">Energy Level (1-5)</Label>
                                        <div className="flex gap-2">
                                            {[1, 2, 3, 4, 5].map((level) => (
                                                <button
                                                    key={level}
                                                    type="button"
                                                    onClick={() => checkInForm.setData('energy_level', level)}
                                                    className={`h-9 w-9 rounded-md border text-sm font-medium transition-colors ${
                                                        checkInForm.data.energy_level === level
                                                            ? 'border-primary bg-primary text-primary-foreground'
                                                            : 'hover:border-muted-foreground/40'
                                                    }`}
                                                >
                                                    {level}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                    <div>
                                        <Label className="mb-2 block">Workload Rating (1-5)</Label>
                                        <div className="flex gap-2">
                                            {[1, 2, 3, 4, 5].map((level) => (
                                                <button
                                                    key={level}
                                                    type="button"
                                                    onClick={() => checkInForm.setData('workload_rating', level)}
                                                    className={`h-9 w-9 rounded-md border text-sm font-medium transition-colors ${
                                                        checkInForm.data.workload_rating === level
                                                            ? 'border-primary bg-primary text-primary-foreground'
                                                            : 'hover:border-muted-foreground/40'
                                                    }`}
                                                >
                                                    {level}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <Label htmlFor="checkin-notes">Notes (optional)</Label>
                                    <Textarea
                                        id="checkin-notes"
                                        value={checkInForm.data.notes}
                                        onChange={(e) => checkInForm.setData('notes', e.target.value)}
                                        rows={2}
                                        maxLength={500}
                                        placeholder="Anything on your mind..."
                                        className="mt-1"
                                    />
                                </div>

                                <Button type="submit" disabled={!selectedMood || checkInForm.processing} size="sm">
                                    Submit Check-in
                                </Button>
                            </form>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
