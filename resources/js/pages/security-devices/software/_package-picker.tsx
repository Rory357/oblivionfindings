import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import axios from 'axios';
import { Search } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
    type FormEvent,
} from 'react';
import {
    softwareBase,
    softwareIndexReply,
    type SoftwareIndex,
    type SoftwarePackage,
} from './_contracts';

export function SoftwarePackagePicker({
    actorId,
    deviceId,
    value,
    onChange,
    disabled = false,
}: {
    actorId?: number;
    deviceId: number;
    value: string;
    onChange: (uuid: string) => void;
    disabled?: boolean;
}) {
    const [page, setPage] = useState<SoftwareIndex | null>(null);
    const [selected, setSelected] = useState<SoftwarePackage | null>(null);
    const [search, setSearch] = useState('');
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState('');
    const active = useRef<AbortController | null>(null);
    const load = useCallback(
        async (href: string) => {
            active.current?.abort();
            const call = new AbortController();
            active.current = call;
            setBusy(true);
            setNotice('');
            setPage(null);
            try {
                if (!Number.isSafeInteger(actorId) || (actorId ?? 0) < 1)
                    throw Error('Account unavailable');
                const reply = await axios.get<unknown>(href, {
                    signal: call.signal,
                    timeout: 15000,
                    headers: { Accept: 'application/json' },
                });
                const next = softwareIndexReply(reply.data, actorId!, deviceId);
                if (
                    next.query.status !== 'published' ||
                    next.packages.some((item) => item.status !== 'published')
                )
                    throw Error('Catalogue filter changed');
                if (!call.signal.aborted) setPage(next);
            } catch {
                if (!call.signal.aborted)
                    setNotice(
                        'Approved software could not be loaded. Check the catalogue again before choosing a package.',
                    );
            } finally {
                if (!call.signal.aborted) setBusy(false);
            }
        },
        [actorId, deviceId],
    );
    const href = useCallback(
        (term: string) =>
            softwareBase +
            '?' +
            new URLSearchParams({
                expected_actor_id: String(actorId),
                device_id: String(deviceId),
                status: 'published',
                ...(term.trim() ? { search: term.trim() } : {}),
            }),
        [actorId, deviceId],
    );
    useEffect(() => {
        setSelected(null);
        void load(href(''));
        return () => active.current?.abort();
    }, [load, href]);
    function searchPackages(event: FormEvent) {
        event.preventDefault();
        if (!disabled && !busy) void load(href(search));
    }
    return (
        <section
            aria-label="Choose approved software"
            className="space-y-3 rounded-lg border p-3"
        >
            {selected?.uuid === value ? (
                <p className="text-subtle">
                    Selected:{' '}
                    <strong className="text-foreground">
                        {selected.name} · {selected.product_version}
                    </strong>{' '}
                    ·{' '}
                    {selected.architecture === 'amd64'
                        ? 'Windows x64'
                        : 'Windows ARM64'}
                </p>
            ) : value ? (
                <p className="text-subtle">
                    The previously selected release remains part of this
                    request.
                </p>
            ) : (
                <p className="text-subtle">
                    Choose an approved release. Its exact installer and version
                    will be checked again before installation.
                </p>
            )}
            <form onSubmit={searchPackages} className="flex gap-2">
                <Input
                    aria-label="Search approved software"
                    value={search}
                    maxLength={200}
                    disabled={disabled || busy}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Application or publisher"
                />
                <Button
                    type="submit"
                    variant="outline"
                    disabled={disabled || busy}
                >
                    <Search aria-hidden="true" />
                    <span className="sr-only">Search approved software</span>
                </Button>
            </form>
            {busy && (
                <p className="text-subtle" role="status">
                    Loading approved software…
                </p>
            )}
            {notice && (
                <Alert>
                    <AlertDescription>
                        {notice}{' '}
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={disabled || busy}
                            onClick={() => void load(href(search))}
                        >
                            Reload catalogue
                        </Button>
                    </AlertDescription>
                </Alert>
            )}
            {page && (
                <>
                    <ul className="divide-y">
                        {page.packages.map((item) => (
                            <li
                                key={item.uuid}
                                className="flex min-w-0 flex-wrap items-center gap-3 py-3"
                            >
                                <div className="min-w-0 flex-1 [overflow-wrap:anywhere]">
                                    <p className="font-medium">{item.name}</p>
                                    <p className="text-caption text-muted-foreground">
                                        {item.product_version} ·{' '}
                                        {item.publisher} ·{' '}
                                        {item.architecture === 'amd64'
                                            ? 'x64'
                                            : 'ARM64'}
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant={
                                        value === item.uuid
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                    disabled={disabled || busy}
                                    aria-pressed={value === item.uuid}
                                    onClick={() => {
                                        setSelected(item);
                                        onChange(item.uuid);
                                    }}
                                >
                                    {value === item.uuid
                                        ? 'Selected'
                                        : 'Choose'}
                                </Button>
                            </li>
                        ))}
                    </ul>
                    {page.packages.length === 0 && (
                        <p className="text-subtle">
                            No approved software matches this search.
                        </p>
                    )}
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-caption text-muted-foreground">
                            {page.pagination.shown} releases on this page
                        </p>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={
                                    disabled ||
                                    busy ||
                                    !page.pagination.previous_href
                                }
                                onClick={() =>
                                    page.pagination.previous_href &&
                                    void load(page.pagination.previous_href)
                                }
                            >
                                Previous
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={
                                    disabled ||
                                    busy ||
                                    !page.pagination.next_href
                                }
                                onClick={() =>
                                    page.pagination.next_href &&
                                    void load(page.pagination.next_href)
                                }
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                    {page.pagination.position_reset && (
                        <p className="text-subtle" role="status">
                            The catalogue changed. You are viewing its first
                            page.
                        </p>
                    )}
                </>
            )}
        </section>
    );
}
