import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { RespiteBookingRow, ServiceAgreementSummary } from '../types';

const CONSENT_AUTHORITIES = [
    ['self', 'Self'],
    ['activated_epoa_welfare', 'Activated EPOA welfare'],
    ['welfare_guardian', 'Welfare guardian'],
    ['parent_guardian', 'Parent / guardian'],
    ['other', 'Other authority'],
] as const;

const CAPACITY_BASES = [
    ['has_capacity', 'Has capacity'],
    ['supported_decision_making', 'Supported decision-making'],
    ['substitute_decision', 'Substitute decision-maker'],
    ['best_interests', 'Best interests decision'],
    ['not_recorded', 'Not recorded'],
] as const;

const FORMAT_OPTIONS = [
    ['written', 'Written'],
    ['easy_read', 'Easy read'],
    ['verbal', 'Verbal'],
    ['te_reo', 'Te reo Maori'],
    ['translated', 'Translated'],
    ['other', 'Other'],
] as const;

export function ConfirmBookingModal({
    booking,
    serviceAgreements,
    onClose,
}: {
    booking: RespiteBookingRow | null;
    serviceAgreements: (ServiceAgreementSummary & { clientId: number })[];
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);
    const [serviceAgreementId, setServiceAgreementId] = useState('__none');
    const [authority, setAuthority] = useState('self');
    const [authorityName, setAuthorityName] = useState('');
    const [authorityContact, setAuthorityContact] = useState('');
    const [codeProvided, setCodeProvided] = useState(false);
    const [consentToRespite, setConsentToRespite] = useState(false);
    const [advocateOffered, setAdvocateOffered] = useState(true);
    const [capacityBasis, setCapacityBasis] = useState('has_capacity');
    const [formatProvided, setFormatProvided] = useState('written');
    const [overrideReason, setOverrideReason] = useState('');

    const agreements = useMemo(
        () =>
            booking?.clientId
                ? serviceAgreements.filter(
                      (agreement) => agreement.clientId === booking.clientId,
                  )
                : [],
        [booking?.clientId, serviceAgreements],
    );

    useEffect(() => {
        if (!booking) return;
        setServiceAgreementId(
            booking.serviceAgreement?.id
                ? String(booking.serviceAgreement.id)
                : '__none',
        );
        setAuthority(booking.consentAuthority ?? 'self');
        setAuthorityName(booking.consentAuthorityName ?? '');
        setAuthorityContact(booking.consentAuthorityContact ?? '');
        setCodeProvided(booking.codeOfRightsProvided);
        setConsentToRespite(booking.consentToRespite);
        setAdvocateOffered(booking.advocateOffered ?? true);
        setCapacityBasis(booking.consentCapacityBasis ?? 'has_capacity');
        setFormatProvided(booking.rightsFormatProvided ?? 'written');
        setOverrideReason('');
        setProcessing(false);
    }, [booking]);

    const canSubmit =
        !!booking &&
        !!authority &&
        codeProvided &&
        consentToRespite &&
        !!capacityBasis &&
        !!formatProvided;

    const submit = () => {
        if (!booking || !canSubmit) return;
        setProcessing(true);
        router.post(
            `/respite/bookings/${booking.id}/confirm`,
            {
                service_agreement_id:
                    serviceAgreementId === '__none'
                        ? null
                        : Number(serviceAgreementId),
                consent_authority: authority,
                consent_authority_name: authorityName || null,
                consent_authority_contact: authorityContact || null,
                code_of_rights_provided: codeProvided,
                consent_to_respite: consentToRespite,
                consent_capacity_basis: capacityBasis,
                advocate_offered: advocateOffered,
                rights_format_provided: formatProvided,
                readiness_override_reason: overrideReason || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={booking != null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-xl">
                {booking ? (
                    <>
                        <div>
                            <DialogTitle className="text-left text-lg">
                                Confirm {booking.client}
                            </DialogTitle>
                            <DialogDescription className="text-left">
                                Capture consent authority, rights, advocate offer and agreement before confirmation.
                            </DialogDescription>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label>Service agreement</Label>
                                <Select
                                    value={serviceAgreementId}
                                    onValueChange={setServiceAgreementId}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none">
                                            No linked agreement
                                        </SelectItem>
                                        {agreements.map((agreement) => (
                                            <SelectItem
                                                key={agreement.id}
                                                value={String(agreement.id)}
                                            >
                                                {agreement.referenceNumber ??
                                                    agreement.title ??
                                                    `Agreement #${agreement.id}`}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-1.5">
                                <Label>Who consents</Label>
                                <Select
                                    value={authority}
                                    onValueChange={setAuthority}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CONSENT_AUTHORITIES.map(
                                            ([value, label]) => (
                                                <SelectItem
                                                    key={value}
                                                    value={value}
                                                >
                                                    {label}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="respite-authority-name">
                                    Authority name
                                </Label>
                                <Input
                                    id="respite-authority-name"
                                    value={authorityName}
                                    onChange={(event) =>
                                        setAuthorityName(event.target.value)
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="respite-authority-contact">
                                    Authority contact
                                </Label>
                                <Input
                                    id="respite-authority-contact"
                                    value={authorityContact}
                                    onChange={(event) =>
                                        setAuthorityContact(event.target.value)
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label>Capacity basis</Label>
                                <Select
                                    value={capacityBasis}
                                    onValueChange={setCapacityBasis}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CAPACITY_BASES.map(([value, label]) => (
                                            <SelectItem key={value} value={value}>
                                                {label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-1.5">
                                <Label>Rights format</Label>
                                <Select
                                    value={formatProvided}
                                    onValueChange={setFormatProvided}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {FORMAT_OPTIONS.map(([value, label]) => (
                                            <SelectItem key={value} value={value}>
                                                {label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="grid gap-2 rounded-[10px] border border-border p-3 text-sm">
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={codeProvided}
                                    onChange={(event) =>
                                        setCodeProvided(event.target.checked)
                                    }
                                />
                                HDC Code of Rights provided
                            </label>
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={consentToRespite}
                                    onChange={(event) =>
                                        setConsentToRespite(
                                            event.target.checked,
                                        )
                                    }
                                />
                                Informed consent to respite recorded
                            </label>
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={advocateOffered}
                                    onChange={(event) =>
                                        setAdvocateOffered(event.target.checked)
                                    }
                                />
                                Advocacy support offered
                            </label>
                        </div>

                        {booking.readiness < 100 ? (
                            <div className="grid gap-1.5">
                                <Label htmlFor="respite-readiness-override">
                                    Readiness override reason
                                </Label>
                                <Textarea
                                    id="respite-readiness-override"
                                    value={overrideReason}
                                    onChange={(event) =>
                                        setOverrideReason(event.target.value)
                                    }
                                    rows={2}
                                />
                            </div>
                        ) : null}

                        <div className="flex justify-end gap-2">
                            <Button variant="outline" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button
                                onClick={submit}
                                disabled={processing || !canSubmit}
                            >
                                <CheckCircle2 className="h-4 w-4" />
                                Confirm booking
                            </Button>
                        </div>
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
