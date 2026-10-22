import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';
import {
    ContactReviewFields,
    contactDraft,
    contactReviewPayload,
    contactReviewValid,
    type ContactProfile,
} from '../contact-review-fields';

export function ProfileContactPreferencesCard({
    id,
    profile,
    canEdit,
}: {
    id: number;
    profile?: ContactProfile;
    canEdit: boolean;
}) {
    const [editing, setEditing] = useState(false);
    if (!profile) return null;
    const preferences = profile.preferences;
    return (
        <>
            <Card id="person-contact-details" className="mb-5">
                <CardHeader className="flex-row flex-wrap items-center justify-between gap-3">
                    <CardTitle className="text-section-title">
                        Contact details and instructions
                    </CardTitle>
                    {canEdit && (
                        <Button
                            variant="outline"
                            size="sm"
                            className="frontline-tap"
                            onClick={() => setEditing(true)}
                        >
                            Review contact details
                        </Button>
                    )}
                </CardHeader>
                <CardContent className="space-y-4">
                    {!profile.review_current && (
                        <p className="text-sm font-medium" role="status">
                            {preferences
                                ? 'Contact details have changed since the last review. Check the instructions with the person before contacting them.'
                                : 'Safe-contact instructions have not been reviewed. Check with the person before using these details.'}
                        </p>
                    )}
                    {preferences?.channel === 'none' && (
                        <p className="text-sm font-medium">
                            No direct contact requested.
                        </p>
                    )}
                    <dl className="grid gap-4 sm:grid-cols-2">
                        {Object.entries({
                            Phone: profile.phone,
                            Email: profile.email,
                            'Preferred channel': preferences
                                ? {
                                      phone: 'Phone',
                                      email: 'Email',
                                      none: 'No direct contact',
                                  }[preferences.channel]
                                : null,
                            Messages: preferences
                                ? preferences.leave_message
                                    ? 'Messages are permitted'
                                    : 'Do not leave a message'
                                : null,
                            'Safe times and instructions':
                                preferences?.safe_time,
                            'Communication needs': preferences?.communication,
                        }).map(([label, value]) => (
                            <div key={label} className="min-w-0">
                                <dt className="text-caption text-muted-foreground">
                                    {label}
                                </dt>
                                <dd className="mt-1 text-sm break-words whitespace-pre-wrap">
                                    {value || 'Not yet recorded'}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </CardContent>
            </Card>
            {editing && (
                <ContactPreferencesDialog
                    id={id}
                    profile={profile}
                    onClose={() => setEditing(false)}
                />
            )}
        </>
    );
}
function ContactPreferencesDialog({
    id,
    profile,
    onClose,
}: {
    id: number;
    profile: ContactProfile;
    onClose: () => void;
}) {
    const [draft, setDraft] = useState(() => contactDraft(profile));
    const [busy, setBusy] = useState(false);
    const [blocked, setBlocked] = useState(false);
    const [error, setError] = useState('');
    const dirty =
        JSON.stringify(draft) !== JSON.stringify(contactDraft(profile));
    const leave = useSettingsLeaveConfirmation(
        dirty,
        'Discard contact review?',
        'Your unsaved contact review will be discarded. A save already sent may still finish; reload the profile to check its result.',
        undefined,
        busy,
    );
    async function save() {
        if (busy || blocked || !contactReviewValid(draft)) return;
        setBusy(true);
        setError('');
        try {
            await axios.put(`/operations/clients/${id}/contact-preferences`, {
                fingerprint: profile.fingerprint,
                review: contactReviewPayload(draft),
            });
            leave.preserveAndRun(() =>
                router.reload({ only: ['client'], preserveScroll: true }),
            );
            onClose();
        } catch (caught) {
            const status = axios.isAxiosError(caught)
                ? caught.response?.status
                : undefined;
            setBlocked(
                !status || status >= 500 || [403, 404, 409].includes(status),
            );
            setError(
                axios.isAxiosError(caught)
                    ? (caught.response?.data?.message ??
                          'The save result is uncertain. Reload the profile to check before trying again.')
                    : 'The save result is uncertain. Reload the profile to check.',
            );
        } finally {
            setBusy(false);
        }
    }
    return (
        <>
            {leave.confirmation}
            <Dialog
                open
                onOpenChange={(open) => {
                    if (!open && !busy) leave.request(onClose);
                }}
            >
                <DialogContent
                    className="scrollbar-pretty max-h-[88vh] overflow-y-auto"
                    style={{
                        width: 'min(92vw, 720px)',
                        maxWidth: 'min(92vw, 720px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Review contact details</DialogTitle>
                        <DialogDescription>
                            Save the person’s current details and agreed contact
                            instructions together.
                        </DialogDescription>
                    </DialogHeader>
                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}
                    <fieldset disabled={busy || blocked}>
                        <ContactReviewFields
                            draft={draft}
                            onChange={setDraft}
                        />
                    </fieldset>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => leave.request(onClose)}
                        >
                            Cancel
                        </Button>
                        {blocked ? (
                            <Button
                                onClick={() =>
                                    leave.request(() => router.reload())
                                }
                            >
                                Reload profile
                            </Button>
                        ) : (
                            <Button
                                disabled={busy || !contactReviewValid(draft)}
                                onClick={save}
                            >
                                {busy ? 'Saving…' : 'Save contact review'}
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
