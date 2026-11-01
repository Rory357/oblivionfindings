/**
 * Choose or change a safety review's owner (G3: decision 3 and Q2, boards approved by Stephan 1 Nov 2026).
 * POST /health-safety/events/{id}/owner. A review with no owner gets its first owner without a reason; changing an
 * owner always needs one (10+ characters). The people offered are exactly the server's `eligible_owners` (people who
 * manage health and safety at the review's Site and can open it) — the server checks again.
 */
import { useForm } from '@inertiajs/react';
import { UserRoundCheck } from 'lucide-react';
import { useEffect } from 'react';

import { HazardStaffPicker } from '@/components/health-safety/hazard-staff-picker';
import { PopupField, PopupForm } from '@/components/popup';
import { Textarea } from '@/components/ui/textarea';

export function ReviewOwnerDialog({
    open,
    onClose,
    eventId,
    reference,
    title,
    siteName,
    currentOwner,
    candidates,
}: {
    open: boolean;
    onClose: () => void;
    eventId: number;
    reference: string;
    title: string;
    siteName: string | null;
    currentOwner: { id: number; name: string } | null;
    candidates: Array<{ id: number; name: string }>;
}) {
    const first = currentOwner === null;
    const form = useForm<{ owner_user_id: string; reason: string }>({
        owner_user_id: '',
        reason: '',
    });

    useEffect(() => {
        if (!open) form.reset();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const options = candidates
        .filter((person) => person.id !== currentOwner?.id)
        .map((person) => ({ value: String(person.id), label: person.name }));
    const reasonShort = !first && form.data.reason.trim().length < 10;

    const submit = () =>
        form.post(`/health-safety/events/${eventId}/owner`, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            icon={UserRoundCheck}
            title={first ? 'Choose the owner' : 'Change the owner'}
            description={
                first
                    ? 'The owner sees this safety review through to closing. It can’t close without one.'
                    : 'The old and new owners are told. The change and its reason are kept in History.'
            }
            context={{
                title: `${title} · ${reference}`,
                tag: siteName ?? undefined,
                note: first ? 'No owner yet.' : `Owner now: ${currentOwner?.name}.`,
            }}
            submitLabel={first ? 'Choose owner' : 'Change owner'}
            processingLabel="Saving…"
            processing={form.processing}
            submitDisabled={!form.data.owner_user_id || reasonShort}
            onSubmit={submit}
            dirty={form.isDirty}
            draftName="this owner change"
            error={(form.errors as Record<string, string | undefined>).event}
            testId="review-owner-dialog"
        >
            <PopupField
                label={first ? 'Owner' : 'New owner'}
                htmlFor="review-owner"
                required
                hint={
                    options.length
                        ? `People who manage health and safety at ${siteName ?? 'this site'} and can open this safety review.`
                        : `No one else who manages health and safety at ${siteName ?? 'this site'} can own it.`
                }
                error={form.errors.owner_user_id}
            >
                <HazardStaffPicker
                    id="review-owner"
                    value={form.data.owner_user_id}
                    onChange={(value) => form.setData('owner_user_id', value)}
                    options={options}
                    placeholder="Choose a person"
                    disabled={!options.length}
                    searchLabel="Search people"
                    listLabel="People who can own this safety review"
                    emptyLabel="No one matches this search."
                />
            </PopupField>
            <PopupField
                label={first ? 'Why this person (optional)' : 'Why the change?'}
                htmlFor="review-owner-reason"
                required={!first}
                hint={first ? undefined : 'Needed, at least 10 characters. For example: “Jordan is on leave until 16 Nov.”'}
                error={form.errors.reason}
            >
                <Textarea
                    id="review-owner-reason"
                    rows={3}
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                />
            </PopupField>
        </PopupForm>
    );
}
