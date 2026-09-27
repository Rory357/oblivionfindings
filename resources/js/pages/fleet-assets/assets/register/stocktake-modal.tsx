import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { WizardShell } from '@/components/wizard/shell';
import {
    Check,
    ClipboardCheck,
    Download,
    MapPin,
    ScanLine,
    Undo2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    api,
    download,
    type Option,
    REGISTER,
    RegisterError,
    stamp,
} from './api';
import { ErrorNotice, SearchPicker } from './controls';
import { QRReader } from './qr-reader';

type Entry = {
    key: string;
    asset_id: number | null;
    name: string;
    asset_tag: string | null;
    serial_number: string | null;
    room: string | null;
    site_room_id: number | null;
    expected: boolean;
    result: 'pending' | 'found' | 'missing' | 'review';
    note: string;
    source: string | null;
    actor: string | null;
    observed_at: string | null;
    changed: boolean;
    asset_status?: string;
};
export type Count = {
    id: number;
    title: string;
    site_id: number;
    site_room_id: number | null;
    version: number;
    status: 'draft' | 'completed';
    scope: {
        site: string;
        room: string | null;
        counter: string;
        rooms: Option[];
    };
    entries: Entry[];
    activity: {
        id: string;
        action: string;
        actor: string;
        at: string;
        name?: string;
        source?: string;
    }[];
    counted_at: string;
    updated_at: string;
    completed_at: string | null;
    review_note: string | null;
    follow_up_user_id: number | null;
    follow_up_name: string | null;
};
const steps = [
    {
        key: 'location',
        label: 'Choose a room',
        blurb: 'Set your checklist',
        icon: MapPin,
    },
    {
        key: 'count',
        label: 'Scan assets',
        blurb: 'Find each item',
        icon: ScanLine,
    },
    {
        key: 'review',
        label: 'Review & finish',
        blurb: 'Check the differences',
        icon: ClipboardCheck,
    },
];

export function StocktakeModal({
    initial,
    sites,
    staff,
    canCount,
    initialSite,
    initialRoom,
    selected,
    onClose,
}: {
    initial: Count | null;
    sites: Option[];
    staff: Option[];
    canCount: boolean;
    initialSite: string;
    initialRoom: string;
    selected: number[];
    onClose: () => void;
}) {
    const [count, setCount] = useState(initial);
    const [step, setStep] = useState(
        initial ? (initial.status === 'completed' ? 2 : 1) : 0,
    );
    const [site, setSite] = useState(initialSite);
    const [room, setRoom] = useState(initialRoom);
    const [wholeSite, setWholeSite] = useState(false);
    const [rooms, setRooms] = useState<Option[]>([]);
    const [title, setTitle] = useState('');
    const [checklist, setChecklist] = useState<{
        total: number;
        assets: { id: number; name: string; asset_tag: string | null }[];
    } | null>(null);
    const [onlySelected, setOnlySelected] = useState(false);
    const [busy, setBusy] = useState(false);
    const lock = useRef(false);
    const [error, setError] = useState('');
    const [conflict, setConflict] = useState(false);
    const [retry, setRetry] = useState<(() => void) | undefined>();
    const [payload, setPayload] = useState('');
    const [notice, setNotice] = useState('');
    const [exception, setException] = useState<{
        state: string;
        entry?: Entry;
        source: string;
    } | null>(null);
    const [unknownNote, setUnknownNote] = useState('');
    const [filter, setFilter] = useState('pending');
    const [find, setFind] = useState('');
    const [reviewNote, setReviewNote] = useState(initial?.review_note || '');
    const [owner, setOwner] = useState(
        initial?.follow_up_user_id ? String(initial.follow_up_user_id) : '',
    );
    const [checkedRooms, setCheckedRooms] = useState<number[]>([]);
    const [emptyChecked, setEmptyChecked] = useState(false);
    const [ack, setAck] = useState(false);
    const [viewer, setViewer] = useState('results');
    const [exporting, setExporting] = useState('');
    const scanner = useRef<HTMLInputElement>(null);
    const requestId = useRef(crypto.randomUUID());
    const completed = count?.status === 'completed';
    const writable = canCount && !completed;
    const pending =
        count?.entries.filter((e) => e.result === 'pending').length || 0;
    const found =
        count?.entries.filter((e) => e.result === 'found').length || 0;
    const differences =
        count?.entries.filter(
            (e) => e.result === 'missing' || !e.expected || e.changed,
        ) || [];
    const roomsToCheck =
        count?.scope.rooms.filter(
            (r) =>
                !count.entries.some(
                    (e) =>
                        e.expected &&
                        e.site_room_id === r.id &&
                        e.result === 'found',
                ),
        ) || [];
    useEffect(() => {
        if (!site) {
            setRooms([]);
            return;
        }
        const controller = new AbortController();
        void api<Option[]>(
            `/rooms?site_id=${site}`,
            'GET',
            undefined,
            controller.signal,
        )
            .then(setRooms)
            .catch((e) => {
                if (e.name !== 'AbortError') setError(e.message);
            });
        return () => controller.abort();
    }, [site]);

    useEffect(() => {
        if (count || !site || (!wholeSite && !room)) {
            setChecklist(null);
            return;
        }
        const controller = new AbortController();
        const params = new URLSearchParams({
            site_id: site,
            ...(wholeSite ? {} : { site_room_id: room }),
        });
        if (onlySelected)
            selected.forEach((id) => params.append('asset_ids[]', String(id)));
        setChecklist(null);
        void api<{
            total: number;
            assets: { id: number; name: string; asset_tag: string | null }[];
        }>(
            `/stocktake-checklist?${params}`,
            'GET',
            undefined,
            controller.signal,
        )
            .then(setChecklist)
            .catch((e) => {
                if (e.name !== 'AbortError') setError(e.message);
            });
        return () => controller.abort();
    }, [count, site, room, wholeSite, onlySelected, selected]);

    async function reload() {
        if (!count) return;
        try {
            const saved = await api<Count>(`/stocktakes/${count.id}`);
            setCount(saved);
            setError('');
            setConflict(false);
            setRetry(undefined);
        } catch (e) {
            setError((e as Error).message);
        }
    }
    async function command(
        body: Record<string, unknown>,
        fixed?: Record<string, unknown>,
    ): Promise<boolean> {
        if (!count || lock.current) return false;
        const data = fixed || {
            ...body,
            version: count.version,
            command_id: crypto.randomUUID(),
        };
        lock.current = true;
        setBusy(true);
        setError('');
        setRetry(undefined);
        try {
            const saved = await api<Count>(
                `/stocktakes/${count.id}`,
                'PATCH',
                data,
            );
            setCount(saved);
            setNotice('Saved');
            setConflict(false);
            if (saved.status === 'completed') {
                setStep(2);
                setViewer('results');
            }
            return true;
        } catch (e) {
            const err = e as RegisterError;
            setError(err.message);
            setConflict(err.status === 409);
            if (err.status === 422 && body.action === 'finish') {
                try {
                    setCount(await api<Count>(`/stocktakes/${count.id}`));
                } catch {
                    /* Keep the submitted review and original error available. */
                }
            }
            if (!err.status || err.status >= 500)
                setRetry(() => () => void command(body, data));
            return false;
        } finally {
            lock.current = false;
            setBusy(false);
        }
    }
    async function start() {
        if (lock.current) return;
        lock.current = true;
        setBusy(true);
        setError('');
        try {
            const saved = await api<Count>('/stocktakes', 'POST', {
                request_id: requestId.current,
                site_id: Number(site),
                site_room_id: !wholeSite && room ? Number(room) : null,
                title,
                ...(onlySelected ? { asset_ids: selected } : {}),
            });
            setCount(saved);
            setStep(1);
            const url = new URL(location.href);
            url.searchParams.set('stocktake', String(saved.id));
            history.replaceState(history.state, '', url);
            setNotice('Checklist saved. Scan your first asset.');
        } catch (e) {
            setError((e as Error).message);
        } finally {
            lock.current = false;
            setBusy(false);
        }
    }
    async function scan(value: string, source: string) {
        if (!count || lock.current || exception || !writable || !value.trim())
            return;
        lock.current = true;
        setBusy(true);
        setError('');
        setNotice('Looking up label…');
        try {
            const resolved = await api<{ state: string; entry?: Entry }>(
                `/stocktakes/${count.id}/resolve`,
                'POST',
                { payload: value },
            );
            lock.current = false;
            setBusy(false);
            if (resolved.state === 'unknown' || resolved.state === 'extra') {
                setException({ ...resolved, source });
                setNotice('Review this label before continuing.');
                return;
            }
            const entry = resolved.entry!;
            const success = await command({
                action: resolved.state === 'duplicate' ? 'duplicate' : 'found',
                key: entry.key,
                source,
            });
            if (success) {
                setNotice(
                    resolved.state === 'duplicate'
                        ? `${entry.name} was already found. Total unchanged.`
                        : `${entry.name} marked found.`,
                );
                setPayload('');
                scanner.current?.focus();
            }
        } catch (e) {
            setError((e as Error).message);
            setNotice('Label was not recorded.');
        } finally {
            lock.current = false;
            setBusy(false);
        }
    }
    async function exportFile(format: string) {
        if (!count) return;
        setExporting(format);
        setError('');
        try {
            await download(
                `${REGISTER}/stocktakes/${count.id}/export/${format}`,
                `stocktake-${count.id}.${format}`,
            );
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setExporting('');
        }
    }
    function close() {
        if (busy) return;
        if (
            count &&
            !completed &&
            (reviewNote !== (count.review_note || '') ||
                owner !== String(count.follow_up_user_id || ''))
        ) {
            void command({
                action: 'review',
                review_note: reviewNote,
                follow_up_user_id: owner ? Number(owner) : null,
            }).then((saved) => {
                if (saved) onClose();
            });
        } else onClose();
    }
    const filtered =
        count?.entries.filter(
            (entry) =>
                (completed ||
                    filter === 'all' ||
                    (filter === 'differences'
                        ? differences.includes(entry)
                        : entry.result === filter)) &&
                `${entry.name} ${entry.asset_tag || ''} ${entry.serial_number || ''}`
                    .toLowerCase()
                    .includes(find.toLowerCase()),
        ) || [];
    function rows(entries: Entry[], editable = false) {
        return (
            <div className="space-y-2">
                {entries.map((entry) => (
                    <Card
                        unstyled
                        key={entry.key}
                        className="rounded-xl border bg-card p-3"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <strong className="text-sm">
                                        {entry.name}
                                    </strong>
                                    <StatusBadge
                                        variant={
                                            entry.result === 'found'
                                                ? 'success'
                                                : entry.result === 'pending'
                                                  ? 'neutral'
                                                  : 'warning'
                                        }
                                    >
                                        {entry.result === 'missing'
                                            ? 'Not found'
                                            : entry.result === 'pending'
                                              ? 'Not checked'
                                              : entry.result === 'review'
                                                ? 'Needs review'
                                                : 'Found'}
                                    </StatusBadge>
                                    {!entry.expected && (
                                        <StatusBadge variant="warning">
                                            Extra item
                                        </StatusBadge>
                                    )}
                                    {entry.changed && (
                                        <StatusBadge variant="warning">
                                            Assignment changed
                                        </StatusBadge>
                                    )}
                                </div>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {entry.asset_tag || 'No tag'} ·{' '}
                                    {entry.room || 'No assigned room'}
                                    {entry.observed_at
                                        ? ` · ${entry.source} · ${stamp(entry.observed_at)}`
                                        : ''}
                                </p>
                            </div>
                            {editable && writable && (
                                <div className="flex gap-2">
                                    {entry.result !== 'found' && (
                                        <Button
                                            className="min-h-11"
                                            variant="outline"
                                            disabled={busy || conflict}
                                            onClick={() =>
                                                void command({
                                                    action: 'found',
                                                    key: entry.key,
                                                    source: 'Manual',
                                                })
                                            }
                                        >
                                            <Check />
                                            Found
                                        </Button>
                                    )}
                                    {entry.expected &&
                                        entry.result !== 'missing' && (
                                            <Button
                                                className="min-h-11"
                                                variant="outline"
                                                disabled={busy || conflict}
                                                onClick={() =>
                                                    void command({
                                                        action: 'missing',
                                                        key: entry.key,
                                                        source: 'Manual',
                                                    })
                                                }
                                            >
                                                Not found
                                            </Button>
                                        )}
                                    {entry.result !== 'pending' && (
                                        <Button
                                            className="min-h-11"
                                            variant="ghost"
                                            aria-label={`Undo answer for ${entry.name}`}
                                            disabled={busy || conflict}
                                            onClick={() =>
                                                void command({
                                                    action: 'undo',
                                                    key: entry.key,
                                                })
                                            }
                                        >
                                            <Undo2 />
                                        </Button>
                                    )}
                                </div>
                            )}
                        </div>
                        {entry.note && (
                            <p className="mt-2 text-sm">{entry.note}</p>
                        )}
                        {editable && writable && (
                            <details className="mt-2 text-xs text-muted-foreground">
                                <summary className="cursor-pointer">
                                    Add an observation note
                                </summary>
                                <EntryNote
                                    value={entry.note}
                                    disabled={
                                        busy ||
                                        conflict ||
                                        entry.result === 'pending'
                                    }
                                    onSave={(note) =>
                                        void command({
                                            action: 'note',
                                            key: entry.key,
                                            source: entry.source || 'Manual',
                                            note,
                                        })
                                    }
                                />
                            </details>
                        )}
                    </Card>
                ))}
                {!entries.length && (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        {editable && filter === 'pending' && !completed
                            ? 'No unchecked items. Review the count when you are ready.'
                            : 'No items in this view.'}
                    </p>
                )}
            </div>
        );
    }
    const reviewFields = (
        <div className="space-y-4">
            <label className="block text-sm font-semibold">
                Review note
                <Textarea
                    className="mt-2"
                    placeholder="Explain the differences and what needs to happen next…"
                    value={reviewNote}
                    onChange={(e) => setReviewNote(e.target.value)}
                    disabled={!writable || busy}
                />
            </label>
            <SearchPicker
                label="Follow-up owner"
                options={staff}
                value={owner}
                onChange={setOwner}
                empty="Choose who will follow up"
                disabled={!writable || busy}
            />
            {roomsToCheck.map((r) => (
                <label
                    key={r.id}
                    className="flex min-h-11 items-center gap-3 rounded-lg border p-3 text-sm"
                >
                    <input
                        type="checkbox"
                        checked={checkedRooms.includes(r.id)}
                        onChange={(e) =>
                            setCheckedRooms(
                                e.target.checked
                                    ? [...checkedRooms, r.id]
                                    : checkedRooms.filter((id) => id !== r.id),
                            )
                        }
                    />
                    I checked {r.name}; no expected assets were found there.
                </label>
            ))}
            {!count?.entries.length && (
                <label className="flex min-h-11 items-center gap-3 text-sm">
                    <input
                        type="checkbox"
                        checked={emptyChecked}
                        onChange={(e) => setEmptyChecked(e.target.checked)}
                    />
                    I checked this location and confirm this empty count.
                </label>
            )}
            {count?.entries.some((e) => e.changed) && (
                <label className="flex min-h-11 items-center gap-3 text-sm">
                    <input
                        type="checkbox"
                        checked={ack}
                        onChange={(e) => setAck(e.target.checked)}
                    />
                    I reviewed the assignment changes shown above.
                </label>
            )}
        </div>
    );
    return (
        <WizardShell
            open
            onClose={close}
            title={completed ? `Stocktake ST-${count.id}` : 'Stocktake'}
            description="Count the assets physically present, then review any differences."
            railIcon={ClipboardCheck}
            railTitle="Stocktake"
            railSub={
                count
                    ? `ST-${count.id} · ${count.scope.site}`
                    : 'Start with one location'
            }
            steps={
                completed
                    ? [
                          {
                              key: 'results',
                              label: 'Results',
                              blurb: 'Completed checklist',
                              icon: ClipboardCheck,
                          },
                          {
                              key: 'followups',
                              label: 'Follow-ups',
                              blurb: 'Differences and review',
                              icon: MapPin,
                          },
                          {
                              key: 'activity',
                              label: 'Activity',
                              blurb: 'Who recorded each answer',
                              icon: ScanLine,
                          },
                      ]
                    : steps.map((s, i) => ({
                          ...s,
                          disabled:
                              busy || (!!count && i === 0) || (!count && i > 0),
                      }))
            }
            stepIndex={
                completed
                    ? ['results', 'followups', 'activity'].indexOf(viewer)
                    : step
            }
            onStepClick={(i) => {
                if (completed)
                    setViewer(['results', 'followups', 'activity'][i]);
                else if (count && i > 0 && !busy && !exception) setStep(i);
            }}
            headerLabel={completed ? `${count.title} · Completed` : undefined}
            sequential={!completed}
            pct={
                count?.entries.length
                    ? Math.round(
                          ((count.entries.length - pending) /
                              count.entries.length) *
                              100,
                      )
                    : null
            }
            pctLabel="Checked"
            maxWidth="min(94vw, 1220px)"
            maxHeight="min(94vh, 960px)"
            railExtra={
                count && (
                    <div className="mt-4 space-y-2 rounded-xl border p-3 text-sm">
                        <strong>{count.scope.room || 'Whole site'}</strong>
                        <p>{count.entries.length} items on this count</p>
                        <p className="text-xs text-muted-foreground">
                            Counted by {count.scope.counter}
                        </p>
                    </div>
                )
            }
            footerStart={
                <span className="hidden text-xs text-muted-foreground sm:inline">
                    {busy
                        ? 'Saving…'
                        : count
                          ? `Saved ${stamp(count.updated_at)}`
                          : 'Your checklist is saved when you start.'}
                </span>
            }
            footerEnd={
                completed ? (
                    <>
                        <Button
                            variant="outline"
                            onClick={() => void exportFile('pdf')}
                            aria-label="Export PDF"
                            disabled={!!exporting}
                        >
                            <Download />
                            {exporting === 'pdf' ? (
                                'Preparing…'
                            ) : (
                                <>
                                    <span className="hidden sm:inline">
                                        Export{' '}
                                    </span>
                                    PDF
                                </>
                            )}
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => void exportFile('xlsx')}
                            aria-label="Export Excel"
                            disabled={!!exporting}
                        >
                            <Download />
                            {exporting === 'xlsx' ? (
                                'Preparing…'
                            ) : (
                                <>
                                    <span className="hidden sm:inline">
                                        Export{' '}
                                    </span>
                                    Excel
                                </>
                            )}
                        </Button>
                        <Button onClick={close}>Close</Button>
                    </>
                ) : (
                    <>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={close}
                        >
                            {count ? 'Save & close' : 'Cancel'}
                        </Button>
                        {step === 0 ? (
                            <Button
                                disabled={
                                    !site ||
                                    (!wholeSite && !room) ||
                                    busy ||
                                    !canCount
                                }
                                onClick={() => void start()}
                            >
                                Start count
                            </Button>
                        ) : step === 1 ? (
                            <Button
                                disabled={busy || !!exception}
                                onClick={() => setStep(2)}
                            >
                                Review count
                                {pending ? ` (${pending} unchecked)` : ''}
                            </Button>
                        ) : (
                            <Button
                                disabled={
                                    busy || pending > 0 || !writable || conflict
                                }
                                onClick={() =>
                                    void command({
                                        action: 'finish',
                                        review_note: reviewNote,
                                        follow_up_user_id: owner
                                            ? Number(owner)
                                            : null,
                                        confirmed_room_ids: checkedRooms,
                                        confirm_empty: emptyChecked,
                                        acknowledge_changes: ack,
                                    })
                                }
                            >
                                Finish stocktake
                            </Button>
                        )}
                    </>
                )
            }
        >
            <div className="asset-stocktake-body space-y-5">
                {count && (
                    <nav
                        aria-label={
                            completed ? 'Report sections' : 'Stocktake steps'
                        }
                        className="flex flex-wrap gap-2 sm:hidden"
                    >
                        {completed ? (
                            ['results', 'followups', 'activity'].map(
                                (section, index) => (
                                    <Button
                                        key={section}
                                        size="sm"
                                        variant={
                                            viewer === section
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() => setViewer(section)}
                                    >
                                        {
                                            [
                                                'Results',
                                                'Follow-ups',
                                                'Activity',
                                            ][index]
                                        }
                                    </Button>
                                ),
                            )
                        ) : (
                            <>
                                <Button
                                    size="sm"
                                    variant={step === 1 ? 'default' : 'outline'}
                                    disabled={busy}
                                    onClick={() => setStep(1)}
                                >
                                    Scan assets
                                </Button>
                                <Button
                                    size="sm"
                                    variant={step === 2 ? 'default' : 'outline'}
                                    disabled={busy || !!exception}
                                    onClick={() => setStep(2)}
                                >
                                    Review count
                                </Button>
                            </>
                        )}
                    </nav>
                )}
                <ErrorNotice message={error} retry={retry} />
                {conflict && (
                    <Button variant="outline" onClick={() => void reload()}>
                        Reload saved count
                    </Button>
                )}
                {step === 0 && !count ? (
                    <div className="asset-count-setup space-y-5">
                        <div>
                            <span className="asset-eyebrow">
                                1 / Choose location
                            </span>
                            <h2 className="text-xl font-semibold">
                                Where are you counting?
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Start with one room. The checklist will include
                                its assigned assets.
                            </p>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <SearchPicker
                                label="Site"
                                options={sites}
                                value={site}
                                onChange={(v) => {
                                    setSite(v);
                                    setRoom('');
                                }}
                                empty="Choose a site"
                            />
                            <SearchPicker
                                label="Room"
                                options={rooms}
                                value={room}
                                onChange={setRoom}
                                empty={
                                    wholeSite
                                        ? 'Every room at this site'
                                        : 'Choose a room'
                                }
                                disabled={!site || wholeSite}
                            />
                        </div>
                        <div className="asset-count-checklist-summary">
                            <ScanLine className="size-7" />
                            <div>
                                <h3 className="text-section-title">
                                    {checklist
                                        ? `${checklist.total} items to check`
                                        : site && (wholeSite || room)
                                          ? 'Preparing your checklist…'
                                          : 'Choose a room to see its checklist'}
                                </h3>
                                <p>
                                    Scan QR labels with a USB scanner or camera.
                                    You can also mark items manually.
                                </p>
                            </div>
                        </div>
                        <details className="rounded-xl border p-4">
                            <summary className="cursor-pointer text-sm font-semibold">
                                Count name and other options
                            </summary>
                            <div className="mt-4 space-y-4">
                                <label className="flex min-h-11 items-center gap-3 rounded-xl border p-3 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={wholeSite}
                                        onChange={(e) =>
                                            setWholeSite(e.target.checked)
                                        }
                                    />
                                    Count the whole site instead
                                </label>
                                {wholeSite && site && (
                                    <div className="rounded-xl bg-muted p-4 text-sm">
                                        A whole-site count includes every
                                        assigned asset and a check for rooms
                                        where nothing is found. Choose a room
                                        above for a shorter count.
                                    </div>
                                )}
                                <label className="block text-sm">
                                    Title (optional)
                                    <Input
                                        value={title}
                                        maxLength={160}
                                        onChange={(e) =>
                                            setTitle(e.target.value)
                                        }
                                        className="mt-2 h-11"
                                        placeholder="For example: September equipment check"
                                    />
                                </label>
                                {selected.length > 0 && (
                                    <label className="flex min-h-11 items-center gap-3 text-sm">
                                        <input
                                            type="checkbox"
                                            checked={onlySelected}
                                            onChange={(e) =>
                                                setOnlySelected(
                                                    e.target.checked,
                                                )
                                            }
                                        />
                                        Only the {selected.length} assets
                                        selected in inventory
                                    </label>
                                )}
                                <p className="text-xs text-muted-foreground">
                                    The person signed in is recorded as the
                                    counter. Each observation is timestamped
                                    when saved.
                                </p>
                            </div>
                        </details>
                        {checklist && (
                            <details className="rounded-xl border bg-muted/50 p-4">
                                <summary className="cursor-pointer text-sm font-semibold text-primary">
                                    {checklist.total} items in this count ·
                                    Preview checklist
                                </summary>
                                <ul className="mt-4 space-y-3">
                                    {checklist.assets?.map((asset) => (
                                        <li
                                            key={asset.id}
                                            className="flex items-center gap-3 text-sm"
                                        >
                                            <ClipboardCheck className="size-4 text-primary" />
                                            <span>
                                                {asset.name}
                                                <small className="ml-2 text-muted-foreground">
                                                    {asset.asset_tag ||
                                                        'No tag'}
                                                </small>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                {checklist.total > 8 && (
                                    <p className="mt-3 text-xs text-muted-foreground">
                                        First 8 shown. All {checklist.total}{' '}
                                        assets will be included.
                                    </p>
                                )}
                            </details>
                        )}
                    </div>
                ) : count && !completed && step === 1 ? (
                    <>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="text-xl font-semibold">
                                    Scan what is in{' '}
                                    {count.scope.room || count.scope.site}
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    A matching label marks the asset Found. Mark
                                    anything you cannot find after checking the
                                    room.
                                </p>
                            </div>
                            <StatusBadge variant={pending ? 'info' : 'success'}>
                                {pending} left to check
                            </StatusBadge>
                        </div>
                        <div className="rounded-2xl border border-primary/30 bg-primary/5 p-4">
                            <form
                                className="flex gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    void scan(payload, 'Keyboard / scanner');
                                }}
                            >
                                <Input
                                    ref={scanner}
                                    autoFocus
                                    aria-label="Scan asset QR code"
                                    value={payload}
                                    onChange={(e) => setPayload(e.target.value)}
                                    placeholder="Scan a QR label or enter its printed tag"
                                    className="h-12 bg-background"
                                    disabled={
                                        !writable ||
                                        busy ||
                                        !!exception ||
                                        conflict
                                    }
                                />
                                <Button
                                    className="h-12"
                                    disabled={
                                        !payload.trim() ||
                                        busy ||
                                        !!exception ||
                                        !writable ||
                                        conflict
                                    }
                                >
                                    <ScanLine />
                                    Scan
                                </Button>
                            </form>
                            <p
                                role="status"
                                aria-live="polite"
                                className="my-3 text-sm font-medium"
                            >
                                {notice ||
                                    'USB scanner ready. You can also use a camera or QR image.'}
                            </p>
                            <QRReader
                                paused={busy}
                                enabled={writable && !exception && !conflict}
                                onRead={(value, source) =>
                                    void scan(value, source)
                                }
                            />
                        </div>
                        {exception && (
                            <div className="space-y-3 rounded-xl border border-status-warning/40 bg-status-warning-bg p-4">
                                <h3 className="font-semibold">
                                    {exception.state === 'unknown'
                                        ? 'This label could not be matched'
                                        : `${exception.entry!.name} is outside this checklist`}
                                </h3>
                                <p className="text-sm">
                                    {exception.state === 'unknown'
                                        ? 'Check the printed tag. You can skip this label or keep an observation for review.'
                                        : `Assigned room: ${exception.entry!.room || 'Not recorded'}. ${exception.entry!.asset_status === 'retired' ? 'This asset is retired. ' : ''}Only add it if it is physically here.`}
                                </p>
                                <Textarea
                                    aria-label="Scan exception note"
                                    placeholder="What did you find?"
                                    value={unknownNote}
                                    onChange={(e) =>
                                        setUnknownNote(e.target.value)
                                    }
                                />
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            setException(null);
                                            setUnknownNote('');
                                            setPayload('');
                                            setNotice('Label skipped.');
                                        }}
                                    >
                                        Skip label
                                    </Button>
                                    <Button
                                        disabled={
                                            busy ||
                                            (exception.state === 'unknown' &&
                                                !unknownNote.trim())
                                        }
                                        onClick={() => {
                                            void command(
                                                exception.state === 'unknown'
                                                    ? {
                                                          action: 'unknown',
                                                          source: exception.source,
                                                          note: unknownNote,
                                                      }
                                                    : {
                                                          action: 'found',
                                                          asset_id:
                                                              exception.entry!
                                                                  .asset_id,
                                                          key: exception.entry!
                                                              .key,
                                                          confirm_extra: true,
                                                          source: exception.source,
                                                          note: unknownNote,
                                                      },
                                            ).then((saved) => {
                                                if (saved) {
                                                    setException(null);
                                                    setUnknownNote('');
                                                    setPayload('');
                                                }
                                            });
                                        }}
                                    >
                                        {exception.state === 'unknown'
                                            ? 'Keep for review'
                                            : 'Confirm physically here'}
                                    </Button>
                                </div>
                            </div>
                        )}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant={
                                    filter === 'pending'
                                        ? 'secondary'
                                        : 'outline'
                                }
                                onClick={() => setFilter('pending')}
                            >
                                Not checked ({pending})
                            </Button>
                            <Button
                                variant={
                                    filter === 'found' ? 'secondary' : 'outline'
                                }
                                onClick={() => setFilter('found')}
                            >
                                Found ({found})
                            </Button>
                            <Button
                                variant={
                                    filter === 'all' ? 'secondary' : 'outline'
                                }
                                onClick={() => setFilter('all')}
                            >
                                All ({count.entries.length})
                            </Button>
                            <Input
                                className="h-10 sm:ml-auto sm:w-56"
                                placeholder="Find a name, tag or serial…"
                                aria-label="Find item in checklist"
                                value={find}
                                onChange={(e) => setFind(e.target.value)}
                            />
                        </div>
                        {rows(filtered, true)}
                    </>
                ) : count && !completed ? (
                    <>
                        <h2 className="text-xl font-semibold">
                            Review before you finish
                        </h2>
                        <div className="grid grid-cols-3 gap-3">
                            {[
                                [found, 'Found'],
                                [pending, 'Not checked'],
                                [differences.length, 'Differences'],
                            ].map(([value, label]) => (
                                <div
                                    key={label}
                                    className="rounded-xl border p-4"
                                >
                                    <strong className="text-2xl">
                                        {value}
                                    </strong>
                                    <p className="text-xs text-muted-foreground">
                                        {label}
                                    </p>
                                </div>
                            ))}
                        </div>
                        {pending > 0 && (
                            <div className="rounded-xl bg-status-warning-bg p-4 text-sm">
                                {pending} items still need an answer.{' '}
                                <Button
                                    variant="link"
                                    onClick={() => {
                                        setStep(1);
                                        setFilter('pending');
                                    }}
                                >
                                    Go to unchecked items
                                </Button>
                            </div>
                        )}
                        <h3 className="font-semibold">
                            Differences to follow up
                        </h3>
                        {rows(differences)}
                        {reviewFields}
                        <p className="text-xs text-muted-foreground">
                            Finishing freezes this report. Assignment and
                            custody changes are made separately from the asset
                            profile.
                        </p>
                    </>
                ) : (
                    count && (
                        <>
                            <div>
                                <h2 className="text-xl font-semibold">
                                    {count.title}
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {count.scope.site} ·{' '}
                                    {count.scope.room || 'Whole site'} ·
                                    Completed {stamp(count.completed_at)}
                                </p>
                            </div>
                            {viewer === 'results' ? (
                                <>
                                    <p className="text-sm">
                                        {found} found ·{' '}
                                        {count.entries.length - found} other
                                        outcomes · {count.entries.length} total
                                        items
                                    </p>
                                    {rows(count.entries)}
                                </>
                            ) : viewer === 'followups' ? (
                                <>
                                    <p className="rounded-xl bg-muted p-4 text-sm">
                                        {count.review_note ||
                                            'No differences requiring follow-up.'}
                                        {count.follow_up_name && (
                                            <>
                                                <br />
                                                <strong>
                                                    Follow-up owner:{' '}
                                                    {count.follow_up_name}
                                                </strong>
                                            </>
                                        )}
                                    </p>
                                    {rows(differences)}
                                </>
                            ) : (
                                <ol className="space-y-3">
                                    {count.activity.map((event) => (
                                        <li
                                            key={event.id}
                                            className="rounded-xl border p-3 text-sm"
                                        >
                                            <strong>
                                                {event.action === 'missing'
                                                    ? 'Marked not found'
                                                    : event.action}{' '}
                                                {event.name
                                                    ? `· ${event.name}`
                                                    : ''}
                                            </strong>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {event.actor} ·{' '}
                                                {event.source || 'Stocktake'} ·{' '}
                                                {stamp(event.at)}
                                            </p>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </>
                    )
                )}
            </div>
        </WizardShell>
    );
}

function EntryNote({
    value,
    disabled,
    onSave,
}: {
    value: string;
    disabled: boolean;
    onSave: (note: string) => void;
}) {
    const [note, setNote] = useState(value);
    return (
        <div className="mt-2 flex gap-2">
            <Input
                aria-label="Observation note"
                value={note}
                maxLength={2000}
                onChange={(e) => setNote(e.target.value)}
                disabled={disabled}
            />
            <Button
                variant="outline"
                disabled={disabled || note === value}
                onClick={() => onSave(note)}
            >
                Save note
            </Button>
        </div>
    );
}
