/**
 * Onboard pop-up — opened from an approved Booking Request. Because intake
 * already created the client with the carried referral data, there is nothing
 * to re-key here: onboarding confirms the spawned booking, which surfaces it in
 * Approved Bookings, the Calendar and Stays. Posts to the existing confirm
 * endpoint; nothing navigates.
 */
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { CalendarCheck, CheckCircle2, Sparkles } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { Avatar, fmtRange } from '../shared';
import type { RespiteRequestRow } from '../types';

export function OnboardModal({ request, onClose }: { request: RespiteRequestRow | null; onClose: () => void }) {
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        if (!request?.bookingId) return;
        setProcessing(true);
        router.post(
            `/respite/bookings/${request.bookingId}/confirm`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const rows: [string, ReactNode][] = request
        ? [
              ['Dates', `${fmtRange(request.start, request.end)}${request.nights != null ? ` · ${request.nights} nights` : ''}`],
              ['Funding', request.funding ?? '—'],
              ['Home', request.site ?? '—'],
              ['From', [request.ref, request.referralRef].filter(Boolean).join(' · ')],
          ]
        : [];

    return (
        <Dialog open={request != null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                {request ? (
                    <>
                        <div className="flex items-center gap-3">
                            <Avatar name={request.client} className="h-11 w-11 text-sm" />
                            <div>
                                <DialogTitle className="text-left text-lg">Onboard {request.client}</DialogTitle>
                                <DialogDescription className="text-left">Confirm the booking to complete intake.</DialogDescription>
                            </div>
                        </div>

                        {request.referralRef ? (
                            <div className="flex items-start gap-2 rounded-[10px] bg-status-info-bg p-3 text-[12.5px] text-status-info">
                                <Sparkles className="mt-0.5 h-4 w-4 shrink-0" />
                                <span>
                                    Prefilled from referral <strong>{request.referralRef}</strong> — the client record already carries this intake, so
                                    nothing is re-keyed.
                                </span>
                            </div>
                        ) : null}

                        <dl className="rounded-xl border border-border px-3.5">
                            {rows.map(([k, v], i) => (
                                <div
                                    key={i}
                                    className={`flex justify-between gap-4 py-2 text-[13px] ${i < rows.length - 1 ? 'border-b border-border/60' : ''}`}
                                >
                                    <dt className="text-muted-foreground">{k}</dt>
                                    <dd className="text-right font-medium">{v}</dd>
                                </div>
                            ))}
                        </dl>

                        <div className="flex items-start gap-2 rounded-[10px] bg-status-success-bg p-3 text-[12.5px] text-status-success">
                            <CalendarCheck className="mt-0.5 h-4 w-4 shrink-0" />
                            <span>On confirm, the booking appears in Approved Bookings and the Calendar, ready to check in as a stay.</span>
                        </div>

                        <div className="flex justify-end gap-2">
                            <Button variant="outline" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button onClick={submit} disabled={processing || !request.bookingId}>
                                <CheckCircle2 className="h-4 w-4" /> Confirm booking &amp; onboard
                            </Button>
                        </div>
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
