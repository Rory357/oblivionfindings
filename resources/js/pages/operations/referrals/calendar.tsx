import {
    referralCalendarAdapter,
    referralFollowUpSeed,
} from '@/components/referrals/calendar-adapter';
import type {
    Permissions,
    ReferralSummary,
} from '@/components/referrals/types';
import { ReferralPicker } from '@/components/referrals/workspace/referral-picker';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import SiteCalendar, {
    type CreateSeed,
} from '@/pages/sites/calendar/SiteCalendar';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export default function ReferralCalendar({
    permissions,
}: {
    permissions: Permissions;
}) {
    const [seed, setSeed] = useState<CreateSeed | null>(null);
    const adapter = useMemo(
        () => referralCalendarAdapter(permissions, setSeed),
        [permissions],
    );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Referrals', href: '/operations/referrals' },
                { title: 'Calendar', href: '/operations/referrals/calendar' },
            ]}
        >
            <Head title="Referral calendar" />
            <SiteCalendar
                context="page"
                scope="global"
                canCreate={!!permissions['tasks.manage']}
                canManage={false}
                dataAdapter={adapter}
            />
            {seed && (
                <NewCalendarEntry seed={seed} onClose={() => setSeed(null)} />
            )}
        </AppLayout>
    );
}

/**
 * New calendar entry: pick the referral with the shared searchable selector,
 * then continue into that referral's own follow-up wizard, which confirms the
 * owner, safe contact and timing before anything is saved.
 */
function NewCalendarEntry({
    seed,
    onClose,
}: {
    seed: CreateSeed;
    onClose: () => void;
}) {
    const [referral, setReferral] = useState<ReferralSummary | null>(null);
    const [missing, setMissing] = useState(false);
    const [date] = useState(() => referralFollowUpSeed(seed));
    const proceed = () => {
        if (!referral) {
            setMissing(true);
            document.getElementById('calendar-entry-referral')?.focus();
            return;
        }
        router.visit(
            `/operations/referrals/${referral.reference}?${new URLSearchParams({ plan_at: date.toISOString() })}`,
        );
    };
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{
                    width: 'min(92vw, 720px)',
                    maxWidth: 'min(92vw, 720px)',
                }}
            >
                <DialogHeader>
                    <DialogTitle>New calendar entry</DialogTitle>
                    <DialogDescription>
                        Choose the person first, then review the date, owner and
                        safe contact in the referral’s follow-up wizard.
                        Selected time: {formatDateTimeLong(date.toISOString())}{' '}
                        (NZ time).
                    </DialogDescription>
                </DialogHeader>
                <Field
                    label="Referral"
                    required
                    htmlFor="calendar-entry-referral"
                    errorId="calendar-entry-referral-error"
                    hint="Search open referrals you can plan work for, by name or reference."
                    error={
                        missing && !referral
                            ? 'Choose the referral this entry is for.'
                            : undefined
                    }
                >
                    <ReferralPicker
                        id="calendar-entry-referral"
                        value={referral}
                        invalid={missing && !referral}
                        describedBy={
                            missing && !referral
                                ? 'calendar-entry-referral-error'
                                : undefined
                        }
                        onChange={(value) => {
                            setReferral(value);
                            setMissing(false);
                        }}
                    />
                </Field>
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={proceed}>Continue</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
