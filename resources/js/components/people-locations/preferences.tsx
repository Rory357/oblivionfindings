import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { type Person, type Preferences, type Workspace } from './model';
import { RecordPicker } from './record-picker';
import { checkResponse, csrfHeaders } from './request';

export function PreferencesPanel({
    workspace,
    selected,
    onSaved,
}: {
    workspace: Workspace;
    selected?: Person;
    onSaved: (preferences: Preferences) => void;
}) {
    const [saved, setSaved] = useState(workspace.preferences);
    const [draft, setDraft] = useState(saved.value),
        [review, setReview] = useState(false),
        [busy, setBusy] = useState(false),
        [message, setMessage] = useState(''),
        [error, setError] = useState('');
    const pending = useRef<AbortController | null>(null);
    useEffect(
        () => () => {
            pending.current?.abort();
            pending.current = null;
        },
        [],
    );
    const dirty = JSON.stringify(draft) !== JSON.stringify(saved.value);
    const save = async (reload = false) => {
        const abort = new AbortController();
        pending.current = abort;
        setBusy(true);
        setError('');
        setMessage('');
        const timeout = window.setTimeout(() => abort.abort(), 25000);
        try {
            const response = await fetch(
                '/operations/people-locations/preferences',
                {
                    method: reload ? 'GET' : 'PUT',
                    cache: 'no-store',
                    signal: abort.signal,
                    headers: csrfHeaders(),
                    ...(reload
                        ? {}
                        : {
                              body: JSON.stringify({
                                  ...draft,
                                  revision: saved.revision,
                              }),
                          }),
                },
            );
            await checkResponse(response);
            const next = (await response.json()) as Preferences;
            if (abort.signal.aborted || pending.current !== abort) return;
            setSaved(next);
            onSaved(next);
            setDraft(next.value);
            setReview(false);
            setMessage(
                reload
                    ? 'Saved preferences loaded.'
                    : 'Preferences saved. Defaults apply when you next open the workspace.',
            );
        } catch (e) {
            if (pending.current === abort)
                setError(
                    abort.signal.aborted
                        ? 'Save timed out. Reload saved preferences before retrying.'
                        : e instanceof Error
                          ? e.message
                          : 'Preferences could not be saved.',
                );
        } finally {
            window.clearTimeout(timeout);
            if (pending.current === abort) {
                pending.current = null;
                setBusy(false);
            }
        }
    };
    return (
        <div className="grid grid-cols-2 items-start gap-5">
            <Card>
                <CardHeader>
                    <CardTitle>Your workspace defaults</CardTitle>
                    <CardDescription>
                        Personal display preferences, saved to your account.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    {saved.siteUnavailable && (
                        <Alert>
                            <AlertDescription>
                                Your saved site is no longer accessible. All
                                permitted sites is used until you save a new
                                default.
                            </AlertDescription>
                        </Alert>
                    )}
                    <div className="space-y-2">
                        <Label>Default population</Label>
                        <Select
                            value={draft.population}
                            onValueChange={(population) => {
                                setDraft({ ...draft, population });
                                setReview(false);
                            }}
                            disabled={busy}
                        >
                            <SelectTrigger aria-label="Default population">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="both">
                                    Clients and staff
                                </SelectItem>
                                <SelectItem value="clients">Clients</SelectItem>
                                {workspace.staffAvailable && (
                                    <SelectItem value="staff">Staff</SelectItem>
                                )}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-2">
                        <Label>Default site</Label>
                        <RecordPicker
                            disabled={busy}
                            label="Default site"
                            value={draft.site}
                            onChange={(site) => {
                                setDraft({ ...draft, site });
                                setReview(false);
                            }}
                            options={[
                                { value: 'all', label: 'All permitted sites' },
                                ...saved.sites.map((site) => ({
                                    value: String(site.id),
                                    label: site.name,
                                    description: `Site ${site.id}`,
                                })),
                            ]}
                        />
                    </div>
                    <div className="space-y-2">
                        <Label>People layout</Label>
                        <Select
                            value={draft.peopleView}
                            onValueChange={(peopleView) => {
                                setDraft({
                                    ...draft,
                                    peopleView: peopleView as 'cards' | 'list',
                                });
                                setReview(false);
                            }}
                            disabled={busy}
                        >
                            <SelectTrigger aria-label="Default people layout">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="cards">Cards</SelectItem>
                                <SelectItem value="list">List</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <label className="flex items-center gap-3 text-sm">
                        <input
                            type="checkbox"
                            checked={draft.boundaries}
                            disabled={busy}
                            onChange={(e) => {
                                setDraft({
                                    ...draft,
                                    boundaries: e.target.checked,
                                });
                                setReview(false);
                            }}
                        />
                        Show permitted shared boundaries on the map
                    </label>
                    {review && (
                        <Alert>
                            <AlertDescription>
                                Save{' '}
                                {draft.population === 'both'
                                    ? 'clients and staff'
                                    : draft.population}
                                ,{' '}
                                {saved.sites.find(
                                    (s) => String(s.id) === draft.site,
                                )?.name ?? 'all permitted sites'}
                                , {draft.peopleView}, with boundaries{' '}
                                {draft.boundaries ? 'shown' : 'hidden'} as your
                                defaults?
                            </AlertDescription>
                        </Alert>
                    )}
                    {error && (
                        <Alert variant="destructive">
                            <AlertDescription>
                                {error}{' '}
                                <Button
                                    variant="link"
                                    disabled={busy}
                                    onClick={() => void save(true)}
                                >
                                    Reload saved preferences
                                </Button>
                            </AlertDescription>
                        </Alert>
                    )}
                    {message && (
                        <p role="status" className="text-sm">
                            {message}
                        </p>
                    )}
                    <div className="flex gap-2">
                        <Button
                            disabled={!dirty || busy}
                            onClick={() =>
                                review ? void save() : setReview(true)
                            }
                        >
                            {busy
                                ? 'Saving…'
                                : review
                                  ? 'Save preferences'
                                  : 'Review changes'}
                        </Button>
                        <Button
                            variant="outline"
                            disabled={!dirty || busy}
                            onClick={() => {
                                setDraft(saved.value);
                                setReview(false);
                                setError('');
                            }}
                        >
                            Cancel changes
                        </Button>
                    </div>
                </CardContent>
            </Card>
            <div className="space-y-5">
                <Card>
                    <CardHeader>
                        <CardTitle>Evidence and privacy</CardTitle>
                        <CardDescription>
                            Authority stays with the source record.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <p>
                            Client Location owns consent, source assignments,
                            retention, monitoring schedules and personal zones.
                            Safety sessions govern staff visibility.
                        </p>
                        <p>
                            Evidence is rechecked every 30 seconds while
                            visible. Hidden pages clear personal evidence.
                            Refresh reads stored observations; it does not
                            request a new device fix.
                        </p>
                        <p>
                            Map imagery: OpenStreetMap. Markers retain their
                            observation time. Unknown or stale evidence does not
                            establish a person's safety.
                        </p>
                        {selected && (
                            <Button asChild variant="outline">
                                <Link href={selected.profileUrl}>
                                    Open {selected.name}'s source settings
                                </Link>
                            </Button>
                        )}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Reports, boundaries and response</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <p>
                            History access:{' '}
                            {workspace.canViewHistory
                                ? 'permitted'
                                : 'not granted'}{' '}
                            · Export access:{' '}
                            {workspace.canExport ? 'permitted' : 'not granted'}.
                            Exports require a purpose and are audited.
                        </p>
                        <p>
                            Maps & Boundaries owns shared geometry. Displaying a
                            boundary does not activate personal monitoring.
                        </p>
                        <p>
                            Control Room owns response actions, escalation and
                            delivery history.{' '}
                            {workspace.canReadAlerts
                                ? 'You can open the original permitted responses.'
                                : 'Response details require separate permission.'}
                        </p>
                        <p>
                            Transport & Handover owns passenger journeys.
                            Reports cover the selected day within that passenger
                            window; vehicle return and handover remain separate.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
