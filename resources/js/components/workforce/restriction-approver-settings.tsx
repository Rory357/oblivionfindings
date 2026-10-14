import { SettingsModal } from '@/components/settings/settings-modal';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { ShieldCheck, Users } from 'lucide-react';
import { useId, useState } from 'react';
import { approverCommand, type ApproverRead } from './restriction-approver-contract';

export type ApproverReview = Readonly<{
    actorId: number;
    readGeneration: string;
    expectedRevision: string | null;
    selectedIds: readonly number[];
    reason: string;
}>;

/** Presentation only. Mount after the current authority/writer/recovery contract
 * is verified. The owner must bind this review before hashing or dispatching. */
export function RestrictionApproverSettings({
    actorId,
    draftActorId,
    read,
    selectedIds,
    reason,
    busy,
    unresolved,
    message,
    onSelectionChange,
    onReasonChange,
    onSave,
    onCheckSave,
}: {
    actorId: number;
    draftActorId: number;
    read: ApproverRead | null;
    selectedIds: readonly number[];
    reason: string;
    busy: boolean;
    unresolved: boolean;
    message?: string;
    onSelectionChange: (ids: readonly number[]) => void;
    onReasonChange: (reason: string) => void;
    onSave: (review: ApproverReview) => void;
    onCheckSave: () => void;
}) {
    const [editor, setEditor] = useState<{ actorId: number; open: boolean }>({ actorId, open: false });
    const [review, setReview] = useState<ApproverReview | null>(null);
    const [reviewWithdrawn, setReviewWithdrawn] = useState(false);
    const [search, setSearch] = useState({ actorId, text: '' });
    const id = useId();
    const available = draftActorId === actorId && read?.actor_id === actorId && read.can_configure &&
        read.state !== 'unavailable';
    const context = `${actorId}:${draftActorId}:${Boolean(available)}:${read?.read_generation ?? ''}:${read?.revision ?? ''}`;
    const [reviewContext, setReviewContext] = useState(context);
    const contextChanged = reviewContext !== context;
    // Adjust only when the observed context changes. Permanently withdraw the
    // review, including when an old frame later reappears; keep the draft intact.
    if (contextChanged) {
        setReviewContext(context);
        if (review !== null) {
            setReview(null);
            setReviewWithdrawn(review.actorId === actorId);
        }
    }

    // Conceal previous-account names, selections and modal content immediately.
    if (!available || !read) {
        return <SettingsNotice>
            The complete approver list is unavailable. Check your access and reload
            these settings before making changes.
        </SettingsNotice>;
    }

    const editing = editor.actorId === actorId && editor.open;
    const query = search.actorId === actorId ? search.text : '';

    const candidateIds = new Set(read.candidate_options.map((person) => person.id));
    const selectedSet = new Set(selectedIds);
    const invalidSelection = selectedIds.some((personId) => !candidateIds.has(personId));
    const dirty = selectedIds.length !== read.authorized_user_ids.length ||
        read.authorized_user_ids.some((personId) => !selectedSet.has(personId));
    const needsReview = dirty || read.state === 'pending_setup';
    const names = new Map([
        ...read.selected_users.map((person) => [person.id, person.name] as const),
        ...read.candidate_options.map((person) => [person.id, person.name] as const),
    ]);
    const candidates = read.candidate_options.filter((person) =>
        person.name.toLocaleLowerCase('en-NZ').includes(query.toLocaleLowerCase('en-NZ')),
    );
    const activeReview = !contextChanged && review?.actorId === actorId &&
        review.readGeneration === read.read_generation &&
        review.expectedRevision === read.revision ? review : null;
    const changedRead = reviewWithdrawn;
    const command = approverCommand(
        '00000000-0000-4000-8000-000000000000', read.revision, selectedIds, reason,
    );
    const locked = busy || unresolved;

    function choose(personId: number, checked: boolean) {
        if (locked || activeReview) return;
        onSelectionChange(checked
            ? [...new Set([...selectedIds, personId])].sort((a, b) => a - b)
            : selectedIds.filter((selected) => selected !== personId));
    }

    function reviewChanges() {
        if (locked || invalidSelection || !command || !needsReview) return;
        setReviewWithdrawn(false);
        setReview(Object.freeze({
            actorId,
            readGeneration: read!.read_generation,
            expectedRevision: read!.revision,
            selectedIds: Object.freeze([...command.authorized_user_ids]),
            reason: command.reason,
        }));
    }

    const addsPeople = activeReview?.selectedIds.some((personId) =>
        !read.authorized_user_ids.includes(personId));

    return <div className="space-y-4">
        {message ? <SettingsNotice>{message}</SettingsNotice> : null}
        {unresolved ? <SettingsNotice>
            The last save has not been confirmed. Checking it will not send the
            change again.
            <Button variant="outline" className="mt-2" disabled={busy} onClick={onCheckSave}>
                {busy ? 'Checking save…' : 'Check last save'}
            </Button>
        </SettingsNotice> : null}
        <ReviewCard icon={ShieldCheck} title="Work restriction approvers">
            <ReviewRow label="Saved list" value={read.state === 'pending_setup'
                ? 'Not set up — no named approvers'
                : read.selected_users.length === 0
                  ? 'No named approvers'
                  : read.selected_users.map((person) => person.name).join(', ')} />
            <p className="text-caption mt-2 text-muted-foreground">
                Choose people individually. Managing this list does not grant
                permission to approve restrictions or read injury records.
            </p>
            <Button variant="link" onClick={() => {
                setEditor({ actorId, open: true });
                if (review?.actorId !== actorId) setReview(null);
            }}>
                Review approvers ↗
            </Button>
        </ReviewCard>
        {editing ? <SettingsModal
            width={720}
            title={activeReview ? 'Review approver changes' : 'Choose work restriction approvers'}
            description={activeReview
                ? 'Check the complete replacement list and reason before saving.'
                : 'Your choices stay in the draft until you review and save them.'}
            onClose={() => { if (!busy) { setEditor({ actorId, open: false }); setReview(null); } }}
            footer={activeReview ? <>
                <Button variant="outline" disabled={locked} onClick={() => setReview(null)}>Back to choices</Button>
                <Button
                    variant={addsPeople ? 'destructive' : 'default'}
                    disabled={locked || invalidSelection}
                    onClick={() => { if (!locked && !invalidSelection) onSave(activeReview); }}
                >{busy ? 'Saving…' : 'Save approver list'}</Button>
            </> : <>
                <Button variant="outline" disabled={busy} onClick={() => setEditor({ actorId, open: false })}>Close</Button>
                <Button disabled={locked || invalidSelection || !command || !needsReview} onClick={reviewChanges}>
                    Review changes
                </Button>
            </>}
        >
            {activeReview ? <>
                {addsPeople ? <SettingsNotice role="note">
                    This adds people to the approval list. Each person still needs
                    the separate approval permission and access to the relevant record.
                </SettingsNotice> : null}
                <ReviewCard icon={Users} title="New complete list">
                    {activeReview.selectedIds.length ? <ul className="space-y-2">
                        {activeReview.selectedIds.map((personId) => <li key={personId}>{names.get(personId)}</li>)}
                    </ul> : <p>No named approvers. Nobody will be authorised by this list.</p>}
                    <ReviewRow label="Reason" value={activeReview.reason} />
                </ReviewCard>
            </> : <>
                {changedRead ? <SettingsNotice>
                    The saved settings or available people changed. Your draft is
                    retained; review it against the current list before saving.
                </SettingsNotice> : null}
                <div className="space-y-2">
                    <h3 className="text-section-title flex items-center gap-2"><Users className="size-4" />Selected people</h3>
                    {!selectedIds.length ? <p className="text-subtle">No people selected.</p> : <ul className="divide-y rounded-md border">
                        {selectedIds.map((personId) => <li key={personId} className="flex items-center justify-between gap-3 p-3">
                            <div className="min-w-0 break-words">
                                {names.get(personId) ?? 'Selection no longer available'}
                                {!candidateIds.has(personId) ? <p className="text-caption text-muted-foreground">
                                    No longer selectable. Remove this person before saving.
                                </p> : null}
                            </div>
                            <Button variant="outline" disabled={locked} onClick={() => choose(personId, false)}>Remove</Button>
                        </li>)}
                    </ul>}
                </div>
                <div className="space-y-2">
                    <Label htmlFor={`${id}-search`}>Find people to add</Label>
                    <Input id={`${id}-search`} value={query} onChange={(event) => setSearch({ actorId, text: event.target.value })} />
                    <ul className="max-h-64 overflow-y-auto divide-y rounded-md border">
                        {candidates.map((person) => <li key={person.id}>
                            <label className="flex cursor-pointer items-center gap-3 p-3">
                                <Checkbox checked={selectedSet.has(person.id)} disabled={locked}
                                    onCheckedChange={(checked) => choose(person.id, checked === true)} />
                                <span className="min-w-0 break-words">{person.name}</span>
                            </label>
                        </li>)}
                        {!candidates.length ? <li className="text-subtle p-3">{query ? 'No matching people.' : 'No people are currently selectable.'}</li> : null}
                    </ul>
                </div>
                <div className="space-y-2">
                    <Label htmlFor={`${id}-reason`}>Reason for this change</Label>
                    <Textarea id={`${id}-reason`} value={reason} disabled={locked}
                        onChange={(event) => onReasonChange(event.target.value)} aria-describedby={`${id}-reason-hint`} />
                    <p id={`${id}-reason-hint`} className="text-caption text-muted-foreground">Required. Up to 2,000 characters.</p>
                    {reason && !command ? <p role="alert" className="text-caption">
                        Enter a visible reason of no more than 2,000 characters.
                    </p> : null}
                </div>
            </>}
        </SettingsModal> : null}
    </div>;
}
