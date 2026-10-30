import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';
import { Info, Search } from 'lucide-react';
import { useState } from 'react';
import { ADMINISTRATION_API, governanceRequest } from './types';

type Person = { id: number; name: string; current_staff: boolean };
type Preview = {
    person: { id: number; name: string };
    current_staff: boolean;
    ending: { staff: number; family: number };
    kept: number;
    owned_groups: { id: number; title: string; next_owner: string | null }[];
    callbacks: number;
    family_work: number;
    held: number;
    eligible_owners: { id: number; name: string }[];
    actionable: boolean;
};
type Summary = {
    memberships_ended: number;
    ownership_transferred: number;
    callbacks_reassigned: number;
    callbacks_returned: number;
    family_work_reassigned: number;
    family_work_returned: number;
};

export const OFFBOARDING_NOTICE =
    'End future directory, chat and calling access when employment or an approved care relationship ends. Reassign owned work and callbacks to eligible cover.';

/** Mockup M22 "Offboarding and expired relationships". */
export function Offboarding({ actorId }: { actorId: number }) {
    const [query, setQuery] = useState('');
    const [people, setPeople] = useState<Person[] | null>(null);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [owner, setOwner] = useState<'care_team' | 'person'>('care_team');
    const [ownerId, setOwnerId] = useState<string | undefined>(undefined);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [summary, setSummary] = useState<Summary | null>(null);

    async function search() {
        if (query.trim().length < 2 || busy) return;
        setBusy(true);
        setError('');
        try {
            const result = await governanceRequest<{ people: Person[] }>(
                actorId,
                `${ADMINISTRATION_API}/offboarding?q=${encodeURIComponent(query.trim())}`,
            );
            setPeople(result.people);
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            setBusy(false);
        }
    }

    async function choose(person: Person) {
        setBusy(true);
        setError('');
        setSummary(null);
        setOwner('care_team');
        setOwnerId(undefined);
        try {
            setPreview(
                await governanceRequest<Preview>(
                    actorId,
                    `${ADMINISTRATION_API}/offboarding/${person.id}`,
                ),
            );
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            setBusy(false);
        }
    }

    async function complete() {
        if (!preview || busy) return;
        setBusy(true);
        setError('');
        try {
            const result = await governanceRequest<{
                summary: Summary;
                preview: Preview;
            }>(
                actorId,
                `${ADMINISTRATION_API}/offboarding/${preview.person.id}`,
                {
                    method: 'POST',
                    body: JSON.stringify({
                        owner,
                        owner_id: owner === 'person' ? Number(ownerId) : null,
                    }),
                },
            );
            setSummary(result.summary);
            setPreview(result.preview);
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            setBusy(false);
        }
    }

    const work = preview ? preview.callbacks + preview.family_work : 0;

    return (
        <div className="grid gap-4">
            <Alert variant="info" role="note">
                <Info />
                <AlertDescription>{OFFBOARDING_NOTICE}</AlertDescription>
            </Alert>
            <p className="text-sm">
                Existing records retain their authorised audience and approved
                retention rules. No account removal silently deletes call
                metadata, care records or investigation evidence.
            </p>
            <div className="grid gap-2">
                <Label htmlFor="offboarding-person">Person</Label>
                <div className="flex gap-2">
                    <Input
                        id="offboarding-person"
                        value={query}
                        placeholder="Name of the staff member or relative"
                        disabled={busy}
                        onChange={(event) => setQuery(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                void search();
                            }
                        }}
                    />
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy || query.trim().length < 2}
                        onClick={() => void search()}
                    >
                        <Search className="size-4" />
                        Find
                    </Button>
                </div>
            </div>
            {people && people.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Nobody with Chat conversations or owned work matches that
                    name.
                </p>
            )}
            {people && people.length > 0 && (
                <ul aria-label="Matching people" className="grid gap-1">
                    {people.map((person) => (
                        <li key={person.id}>
                            {/* eslint-disable-next-line no-restricted-syntax -- selectable result tile with a two-part row layout */}
                            <button
                                type="button"
                                aria-pressed={preview?.person.id === person.id}
                                onClick={() => void choose(person)}
                                className={cn(
                                    'flex min-h-[44px] w-full items-center justify-between gap-3 rounded-md border px-3 py-2 text-left text-sm hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                    preview?.person.id === person.id &&
                                        'bg-primary-fill/10 text-primary',
                                )}
                            >
                                <span>{person.name}</span>
                                <StatusBadge
                                    variant={
                                        person.current_staff
                                            ? 'success'
                                            : 'neutral'
                                    }
                                >
                                    {person.current_staff
                                        ? 'Current staff'
                                        : 'Access ended'}
                                </StatusBadge>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {preview && (
                <section
                    aria-labelledby="offboarding-preview"
                    className="grid gap-3 rounded-lg border bg-muted/40 p-4 text-sm"
                >
                    <h3 id="offboarding-preview" className="text-section-title">
                        {preview.person.name}
                    </h3>
                    {!preview.actionable ? (
                        <p>
                            {preview.current_staff
                                ? `Still a current staff member. Their ${preview.kept} conversation${preview.kept === 1 ? '' : 's'} and owned work stay as they are until employment ends.`
                                : 'Nothing left to offboard. Expired access has already ended.'}
                        </p>
                    ) : (
                        <ul className="grid gap-1">
                            <li>
                                Staff conversations ending:{' '}
                                {preview.ending.staff}
                            </li>
                            <li>
                                Family conversations ending:{' '}
                                {preview.ending.family}
                            </li>
                            {preview.kept > 0 && (
                                <li>
                                    Conversations kept (access still current):{' '}
                                    {preview.kept}
                                </li>
                            )}
                            {preview.owned_groups.map((group) => (
                                <li key={group.id}>
                                    Owned group “{group.title}” passes to{' '}
                                    {group.next_owner ??
                                        'no eligible member; it stays without an owner'}
                                </li>
                            ))}
                            <li>Unresolved callbacks: {preview.callbacks}</li>
                            <li>
                                Family conversations they owned:{' '}
                                {preview.family_work}
                            </li>
                            {preview.held > 0 && (
                                <li>
                                    Held conversations keep their full record:{' '}
                                    {preview.held}
                                </li>
                            )}
                        </ul>
                    )}
                    {preview.actionable && work > 0 && (
                        <div className="grid gap-2">
                            <Label htmlFor="offboarding-owner">
                                Unresolved work owner
                            </Label>
                            <Select
                                value={owner}
                                onValueChange={(value) =>
                                    setOwner(value as 'care_team' | 'person')
                                }
                            >
                                <SelectTrigger id="offboarding-owner">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="care_team">
                                        Approved care-team cover
                                    </SelectItem>
                                    {preview.eligible_owners.length > 0 && (
                                        <SelectItem value="person">
                                            Authorised site lead
                                        </SelectItem>
                                    )}
                                </SelectContent>
                            </Select>
                            <p className="text-caption">
                                {owner === 'care_team'
                                    ? 'Work returns to the shared care-team queue for eligible cover to claim.'
                                    : 'Only people who can access all of this work are listed.'}
                            </p>
                            {owner === 'person' && (
                                <Select
                                    value={ownerId}
                                    onValueChange={setOwnerId}
                                >
                                    <SelectTrigger aria-label="Authorised site lead">
                                        <SelectValue placeholder="Choose a lead" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {preview.eligible_owners.map(
                                            (person) => (
                                                <SelectItem
                                                    key={person.id}
                                                    value={String(person.id)}
                                                >
                                                    {person.name}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            )}
                        </div>
                    )}
                    {summary && (
                        <p role="status" className="text-status-success">
                            Ended {summary.memberships_ended} membership
                            {summary.memberships_ended === 1 ? '' : 's'};{' '}
                            {summary.ownership_transferred} group owner
                            {summary.ownership_transferred === 1
                                ? ''
                                : 's'}{' '}
                            changed;{' '}
                            {summary.callbacks_reassigned +
                                summary.family_work_reassigned}{' '}
                            item(s) reassigned and{' '}
                            {summary.callbacks_returned +
                                summary.family_work_returned}{' '}
                            returned to the care team.
                        </p>
                    )}
                    {preview.actionable && (
                        <div className="flex justify-end">
                            <Button
                                variant="destructive"
                                disabled={
                                    busy || (owner === 'person' && !ownerId)
                                }
                                onClick={() => void complete()}
                            >
                                End expired access
                            </Button>
                        </div>
                    )}
                </section>
            )}
            {error && (
                <p role="alert" className="text-sm text-status-critical">
                    {error}
                </p>
            )}
        </div>
    );
}
