/**
 * Onboard pop-up — opened from an approved Booking Request. Because intake
 * already created the client with the carried referral data, there is nothing
 * to re-key here: onboarding confirms the spawned booking, which surfaces it in
 * Approved Bookings, the Calendar and Stays. Posts to the existing confirm
 * endpoint; nothing navigates.
 */
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router } from '@inertiajs/react';
import { CalendarCheck, CheckCircle2, Sparkles } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { Avatar, fmtRange } from '../shared';
import type { RespiteRequestRow } from '../types';

export function OnboardModal({ request, onClose }: { request: RespiteRequestRow | null; onClose: () => void }) {
    const [processing, setProcessing] = useState(false);
    const [authority, setAuthority] = useState('self');
    const [authorityName, setAuthorityName] = useState('');
    const [authorityContact, setAuthorityContact] = useState('');
    const [capacityBasis, setCapacityBasis] = useState('has_capacity');
    const [formatProvided, setFormatProvided] = useState('written');
    const [codeProvided, setCodeProvided] = useState(false);
    const [consentToRespite, setConsentToRespite] = useState(false);
    const [advocateOffered, setAdvocateOffered] = useState(true);

    useEffect(() => {
        if (!request) return;
        setProcessing(false);
        setAuthority('self');
        setAuthorityName('');
        setAuthorityContact('');
        setCapacityBasis('has_capacity');
        setFormatProvided('written');
        setCodeProvided(false);
        setConsentToRespite(false);
        setAdvocateOffered(true);
    }, [request]);

    const submit = () => {
        if (!request?.bookingId || !codeProvided || !consentToRespite) return;
        setProcessing(true);
        router.post(
            `/respite/bookings/${request.bookingId}/confirm`,
            {
                consent_authority: authority,
                consent_authority_name: authorityName || null,
                consent_authority_contact: authorityContact || null,
                code_of_rights_provided: codeProvided,
                consent_to_respite: consentToRespite,
                consent_capacity_basis: capacityBasis,
                advocate_offered: advocateOffered,
                rights_format_provided: formatProvided,
            },
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

                        <div className="grid gap-3 rounded-xl border border-border p-3.5">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="onboard-authority">Who consents</Label>
                                    <select
                                        id="onboard-authority"
                                        className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                        value={authority}
                                        onChange={(event) => setAuthority(event.target.value)}
                                    >
                                        <option value="self">Self</option>
                                        <option value="activated_epoa_welfare">Activated EPOA welfare</option>
                                        <option value="welfare_guardian">Welfare guardian</option>
                                        <option value="parent_guardian">Parent / guardian</option>
                                        <option value="other">Other authority</option>
                                    </select>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="onboard-capacity">Capacity basis</Label>
                                    <select
                                        id="onboard-capacity"
                                        className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                        value={capacityBasis}
                                        onChange={(event) => setCapacityBasis(event.target.value)}
                                    >
                                        <option value="has_capacity">Has capacity</option>
                                        <option value="supported_decision_making">Supported decision-making</option>
                                        <option value="substitute_decision">Substitute decision-maker</option>
                                        <option value="best_interests">Best interests decision</option>
                                        <option value="not_recorded">Not recorded</option>
                                    </select>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="onboard-authority-name">Authority name</Label>
                                    <Input
                                        id="onboard-authority-name"
                                        value={authorityName}
                                        onChange={(event) => setAuthorityName(event.target.value)}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="onboard-authority-contact">Authority contact</Label>
                                    <Input
                                        id="onboard-authority-contact"
                                        value={authorityContact}
                                        onChange={(event) => setAuthorityContact(event.target.value)}
                                    />
                                </div>
                                <div className="grid gap-1.5 sm:col-span-2">
                                    <Label htmlFor="onboard-format">Rights format</Label>
                                    <select
                                        id="onboard-format"
                                        className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                        value={formatProvided}
                                        onChange={(event) => setFormatProvided(event.target.value)}
                                    >
                                        <option value="written">Written</option>
                                        <option value="easy_read">Easy read</option>
                                        <option value="verbal">Verbal</option>
                                        <option value="te_reo">Te reo Maori</option>
                                        <option value="translated">Translated</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={codeProvided} onChange={(event) => setCodeProvided(event.target.checked)} />
                                HDC Code of Rights provided
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={consentToRespite} onChange={(event) => setConsentToRespite(event.target.checked)} />
                                Informed consent to respite recorded
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={advocateOffered} onChange={(event) => setAdvocateOffered(event.target.checked)} />
                                Advocacy support offered
                            </label>
                        </div>

                        <div className="flex items-start gap-2 rounded-[10px] bg-status-success-bg p-3 text-[12.5px] text-status-success">
                            <CalendarCheck className="mt-0.5 h-4 w-4 shrink-0" />
                            <span>On confirm, the booking appears in Approved Bookings and the Calendar, ready to check in as a stay.</span>
                        </div>

                        <div className="flex justify-end gap-2">
                            <Button variant="outline" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button onClick={submit} disabled={processing || !request.bookingId || !codeProvided || !consentToRespite}>
                                <CheckCircle2 className="h-4 w-4" /> Confirm booking &amp; onboard
                            </Button>
                        </div>
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
