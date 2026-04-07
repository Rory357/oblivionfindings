import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { cn } from '@/lib/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Calendar,
    Car,
    CheckCircle,
    ClipboardCheck,
    Clock,
    MapPin,
    User,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { formatDate, formatDateTime, formatDistance } from '@/lib/fleet-utils';


type Props = {
    booking: {
        id: number;
        asset: { id: number; name: string; asset_tag?: string; registration_number?: string } | null;
        user: { id: number; name: string; email?: string } | null;
        purpose: string | null;
        destination: string | null;
        starts_at: string | null;
        ends_at: string | null;
        status: string;
        odometer_out: number | null;
        odometer_in: number | null;
        condition_on_return: string | null;
        return_notes: string | null;
        rejection_reason: string | null;
        approved_by_user_id: number | null;
        checked_out_at: string | null;
        returned_at: string | null;
        created_at: string | null;
        passengers: number | null;
        notes: string | null;
    };
};

const statusBannerColors: Record<string, string> = {
    pending: 'bg-amber-50 border-amber-200 text-amber-900 dark:bg-amber-950/30 dark:border-amber-800 dark:text-amber-200',
    approved: 'bg-blue-50 border-blue-200 text-blue-900 dark:bg-blue-950/30 dark:border-blue-800 dark:text-blue-200',
    checked_out: 'bg-purple-50 border-purple-200 text-purple-900 dark:bg-purple-950/30 dark:border-purple-800 dark:text-purple-200',
    returned: 'bg-slate-50 border-slate-200 text-slate-900 dark:bg-slate-950/30 dark:border-slate-800 dark:text-slate-200',
    rejected: 'bg-red-50 border-red-200 text-red-900 dark:bg-red-950/30 dark:border-red-800 dark:text-red-200',
    cancelled: 'bg-gray-50 border-gray-200 text-gray-900 dark:bg-gray-950/30 dark:border-gray-800 dark:text-gray-200',
};

const statusSteps = ['pending', 'approved', 'checked_out', 'returned'];

export default function BookingShow({ booking }: Props) {
    const b = booking ?? {} as Props['booking'];
    const checkoutForm = useForm({ odometer_out: '' });
    const returnForm = useForm({ odometer_in: '', condition_on_return: '', return_notes: '' });
    const [showCancelDialog, setShowCancelDialog] = useState(false);
    const [showRejectDialog, setShowRejectDialog] = useState(false);
    const [rejectionReason, setRejectionReason] = useState('');

    const currentStepIndex = statusSteps.indexOf(b.status ?? '');

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Bookings', href: '/fleet-assets/bookings' },
                { title: `Booking #${b.id ?? ''}`, href: '#' },
            ]}
        >
            <Head title={`Booking #${b.id ?? ''}`} />
            <PageShell>
                <PageHeader
                    title={`Booking #${b.id ?? ''}`}
                    backHref="/fleet-assets/bookings"
                    backLabel="Back to Bookings"
                />

                {/* Status Banner */}
                <div className={cn('rounded-lg border px-5 py-4', statusBannerColors[b.status ?? ''] ?? statusBannerColors.pending)}>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <Badge className="text-sm capitalize">{(b.status ?? '').replace(/_/g, ' ')}</Badge>
                            <span className="font-medium">Booking #{b.id}</span>
                        </div>
                        <span className="text-sm opacity-80">
                            {b.created_at ? `Created ${formatDate(b.created_at)}` : ''}
                        </span>
                    </div>
                </div>

                {/* Status Timeline */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="flex items-center justify-between">
                            {statusSteps.map((step, i) => (
                                <div key={step} className="flex flex-1 items-center">
                                    <div className="flex flex-col items-center gap-1.5">
                                        <div className={cn(
                                            'flex h-10 w-10 items-center justify-center rounded-full text-sm font-medium transition-all',
                                            i <= currentStepIndex
                                                ? 'bg-primary text-primary-foreground shadow-sm'
                                                : 'bg-muted text-muted-foreground'
                                        )}>
                                            {i < currentStepIndex ? (
                                                <CheckCircle className="h-5 w-5" />
                                            ) : (
                                                i + 1
                                            )}
                                        </div>
                                        <span className={cn('text-xs capitalize', i <= currentStepIndex ? 'font-semibold' : 'text-muted-foreground')}>
                                            {step.replace(/_/g, ' ')}
                                        </span>
                                    </div>
                                    {i < statusSteps.length - 1 && (
                                        <div className={cn('mx-2 h-0.5 flex-1', i < currentStepIndex ? 'bg-primary' : 'bg-muted')} />
                                    )}
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {/* 2-Column: Details + Vehicle Info */}
                <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                    {/* Booking Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Booking Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-3 text-sm">
                                <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                    <User className="h-4 w-4 text-muted-foreground" />
                                    <div className="flex-1">
                                        <dt className="text-xs text-muted-foreground">Requested By</dt>
                                        <dd className="font-medium">{b.user?.name ?? '---'}</dd>
                                    </div>
                                </div>
                                <div className="rounded-md bg-muted/40 p-3">
                                    <dt className="text-xs text-muted-foreground">Purpose</dt>
                                    <dd className="mt-1 font-medium">{b.purpose ?? '---'}</dd>
                                </div>
                                {b.destination && (
                                    <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                        <MapPin className="h-4 w-4 text-muted-foreground" />
                                        <div className="flex-1">
                                            <dt className="text-xs text-muted-foreground">Destination</dt>
                                            <dd className="font-medium">{b.destination}</dd>
                                        </div>
                                    </div>
                                )}
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                        <Calendar className="h-4 w-4 text-muted-foreground" />
                                        <div>
                                            <dt className="text-xs text-muted-foreground">Start</dt>
                                            <dd className="font-medium">{b.starts_at ? formatDateTime(b.starts_at) : '---'}</dd>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                        <Calendar className="h-4 w-4 text-muted-foreground" />
                                        <div>
                                            <dt className="text-xs text-muted-foreground">End</dt>
                                            <dd className="font-medium">{b.ends_at ? formatDateTime(b.ends_at) : '---'}</dd>
                                        </div>
                                    </div>
                                </div>
                                {b.passengers != null && (
                                    <div className="rounded-md bg-muted/40 p-3">
                                        <dt className="text-xs text-muted-foreground">Passengers</dt>
                                        <dd className="mt-1 font-medium">{b.passengers}</dd>
                                    </div>
                                )}
                                {b.notes && (
                                    <div className="rounded-md bg-muted/40 p-3">
                                        <dt className="text-xs text-muted-foreground">Notes</dt>
                                        <dd className="mt-1">{b.notes}</dd>
                                    </div>
                                )}
                                {b.rejection_reason && (
                                    <div className="rounded-md border border-red-200 bg-red-50 p-3 dark:border-red-800 dark:bg-red-950/30">
                                        <dt className="text-xs text-red-600 dark:text-red-400">Rejection Reason</dt>
                                        <dd className="mt-1 font-medium text-red-800 dark:text-red-300">{b.rejection_reason}</dd>
                                    </div>
                                )}
                            </dl>
                        </CardContent>
                    </Card>

                    {/* Vehicle Info + Odometer */}
                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Car className="h-4 w-4" />
                                    Vehicle Information
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {b.asset ? (
                                    <Link href={`/fleet-assets/vehicles/${b.asset.id}`} className="block rounded-lg border p-4 transition-all duration-200 hover:bg-muted/50 hover:border-primary/30 hover:shadow-lg hover:-translate-y-0.5">
                                        <div className="flex items-center gap-3">
                                            <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                                <Car className="h-6 w-6" />
                                            </div>
                                            <div>
                                                <div className="font-semibold">{b.asset.name}</div>
                                                {b.asset.asset_tag && <div className="text-xs text-muted-foreground">{b.asset.asset_tag}</div>}
                                                {b.asset.registration_number && <div className="text-xs text-muted-foreground">Rego: {b.asset.registration_number}</div>}
                                            </div>
                                        </div>
                                    </Link>
                                ) : (
                                    <p className="text-sm text-muted-foreground">No vehicle assigned</p>
                                )}
                            </CardContent>
                        </Card>

                        {/* Odometer Readings */}
                        {(b.odometer_out != null || b.odometer_in != null) && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base">Odometer</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="rounded-md bg-muted/40 p-3 text-center">
                                            <div className="text-xs text-muted-foreground">Out</div>
                                            <div className="mt-1 text-lg font-bold">{b.odometer_out != null ? `${formatDistance((b.odometer_out))}` : '---'}</div>
                                        </div>
                                        <div className="rounded-md bg-muted/40 p-3 text-center">
                                            <div className="text-xs text-muted-foreground">In</div>
                                            <div className="mt-1 text-lg font-bold">{b.odometer_in != null ? `${formatDistance((b.odometer_in))}` : '---'}</div>
                                        </div>
                                    </div>
                                    {b.odometer_out != null && b.odometer_in != null && (
                                        <div className="mt-3 rounded-md bg-primary/5 border border-primary/20 p-2 text-center">
                                            <span className="text-xs text-muted-foreground">Distance: </span>
                                            <span className="font-semibold text-primary">{formatDistance((b.odometer_in - b.odometer_out))}</span>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* Return Info */}
                        {b.condition_on_return && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base">Return Details</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2 text-sm">
                                    <div>
                                        <span className="text-muted-foreground">Condition: </span>
                                        <span className="font-medium">{b.condition_on_return}</span>
                                    </div>
                                    {b.return_notes && (
                                        <div>
                                            <span className="text-muted-foreground">Notes: </span>
                                            <span>{b.return_notes}</span>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>

                {/* Action Buttons - More Prominent */}
                <div className="space-y-4">
                    {/* Pending: Approve / Reject */}
                    {b.status === 'pending' && (
                        <Card className="border-2 border-amber-200 dark:border-amber-800">
                            <CardContent className="flex flex-col gap-4 pt-6 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h3 className="font-semibold">Awaiting Approval</h3>
                                    <p className="text-sm text-muted-foreground">Review this booking request and approve or reject it.</p>
                                </div>
                                <div className="flex gap-3">
                                    <Button
                                        size="lg"
                                        onClick={() => router.post(`/fleet-assets/bookings/${b.id}/approve`)}
                                        className="shadow-sm"
                                    >
                                        <CheckCircle className="mr-2 h-5 w-5" />
                                        Approve
                                    </Button>
                                    <Button
                                        size="lg"
                                        variant="destructive"
                                        onClick={() => setShowRejectDialog(true)}
                                        className="shadow-sm"
                                    >
                                        <XCircle className="mr-2 h-5 w-5" />
                                        Reject
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Approved: Checkout */}
                    {b.status === 'approved' && (
                        <Card className="border-2 border-blue-200 dark:border-blue-800">
                            <CardHeader>
                                <CardTitle className="text-base">Checkout Vehicle</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-400">
                                    <ClipboardCheck className="h-4 w-4 shrink-0" />
                                    <span>Complete a pre-trip inspection before checkout.</span>
                                    <Link
                                        href={`/fleet-assets/inspections/create?asset_id=${b.asset?.id ?? ''}`}
                                        className="ml-auto whitespace-nowrap font-medium text-primary underline hover:no-underline"
                                    >
                                        Start Inspection
                                    </Link>
                                </div>
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        checkoutForm.post(`/fleet-assets/bookings/${b.id}/checkout`);
                                    }}
                                    className="flex items-end gap-3"
                                >
                                    <div className="flex-1">
                                        <label className="text-sm font-medium">Odometer Reading</label>
                                        <Input
                                            type="number"
                                            value={checkoutForm.data.odometer_out}
                                            onChange={(e) => checkoutForm.setData('odometer_out', e.target.value)}
                                            placeholder="Current km"
                                        />
                                    </div>
                                    <Button type="submit" size="lg" disabled={checkoutForm.processing || !checkoutForm.data.odometer_out}>
                                        Checkout
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>
                    )}

                    {/* Checked Out: Return */}
                    {b.status === 'checked_out' && (
                        <Card className="border-2 border-purple-200 dark:border-purple-800">
                            <CardHeader>
                                <CardTitle className="text-base">Return Vehicle</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-400">
                                    <ClipboardCheck className="h-4 w-4 shrink-0" />
                                    <span>Complete a post-trip inspection before returning the vehicle.</span>
                                    <Link
                                        href={`/fleet-assets/inspections/create?asset_id=${b.asset?.id ?? ''}&type=post-trip&booking_id=${b.id}`}
                                        className="ml-auto whitespace-nowrap font-medium text-primary underline hover:no-underline"
                                    >
                                        Start Post-Trip Inspection
                                    </Link>
                                </div>
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        returnForm.post(`/fleet-assets/bookings/${b.id}/return`);
                                    }}
                                    className="grid gap-3 sm:grid-cols-2"
                                >
                                    <div>
                                        <label className="text-sm font-medium">Odometer Reading</label>
                                        <Input
                                            type="number"
                                            value={returnForm.data.odometer_in}
                                            onChange={(e) => returnForm.setData('odometer_in', e.target.value)}
                                            placeholder="Current km"
                                        />
                                    </div>
                                    <div>
                                        <label className="text-sm font-medium">Condition on Return</label>
                                        <Input
                                            value={returnForm.data.condition_on_return}
                                            onChange={(e) => returnForm.setData('condition_on_return', e.target.value)}
                                            placeholder="Vehicle condition"
                                        />
                                    </div>
                                    <div className="sm:col-span-2">
                                        <label className="text-sm font-medium">Return Notes</label>
                                        <textarea
                                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                            rows={2}
                                            value={returnForm.data.return_notes}
                                            onChange={(e) => returnForm.setData('return_notes', e.target.value)}
                                            placeholder="Any additional notes..."
                                        />
                                    </div>
                                    <div className="sm:col-span-2">
                                        <Button type="submit" size="lg" disabled={returnForm.processing}>
                                            Return Vehicle
                                        </Button>
                                    </div>
                                </form>
                            </CardContent>
                        </Card>
                    )}

                    {/* Cancel (available for pending, approved, checked_out) */}
                    {['pending', 'approved', 'checked_out'].includes(b.status ?? '') && (
                        <Button
                            variant="outline"
                            onClick={() => setShowCancelDialog(true)}
                        >
                            Cancel Booking
                        </Button>
                    )}
                </div>

                <ConfirmDialog
                    open={showCancelDialog}
                    onClose={() => setShowCancelDialog(false)}
                    onConfirm={() => router.post(`/fleet-assets/bookings/${b.id}/cancel`)}
                    title="Cancel Booking"
                    description="Are you sure you want to cancel this booking? This action cannot be undone."
                    confirmText="Cancel Booking"
                />
                <AlertDialog open={showRejectDialog} onOpenChange={(isOpen) => { if (!isOpen) setShowRejectDialog(false); }}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Reject Booking</AlertDialogTitle>
                            <AlertDialogDescription>Provide a reason for rejecting this booking request.</AlertDialogDescription>
                        </AlertDialogHeader>
                        <textarea
                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            rows={3}
                            placeholder="Reason for rejection..."
                            value={rejectionReason}
                            onChange={(e) => setRejectionReason(e.target.value)}
                        />
                        <AlertDialogFooter>
                            <AlertDialogCancel onClick={() => setShowRejectDialog(false)}>Cancel</AlertDialogCancel>
                            <AlertDialogAction
                                disabled={!rejectionReason.trim()}
                                onClick={() => {
                                    router.post(`/fleet-assets/bookings/${b.id}/reject`, { rejection_reason: rejectionReason });
                                    setShowRejectDialog(false);
                                }}
                                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                            >
                                Reject
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </PageShell>
        </AppLayout>
    );
}
