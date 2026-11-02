/**
 * Pop-up 87, the fire roll call (S&D rebuild drawing sdr-87): a side panel listing who may be at a
 * Site now, each saying where that came from, ticked off as they're found. Ticks stay on this
 * screen only. Leaving isn't recorded (doors open from inside), so the panel says so plainly.
 * Only staff matched at that Site are named; residents aren't listed from their fobs (D19).
 */
import { PopupCallout, PopupFooter } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ErrorState } from '@/components/ui/error-state';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { SkeletonCard } from '@/components/ui/skeleton-card';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatTime } from '@/lib/datetime';
import { seenTime } from '@/lib/security-devices/device-time';
import axios from 'axios';
import { DoorOpen, HardHat, IdCard, RefreshCw, type LucideIcon } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { Option } from '@/pages/security-devices/access/_types';
import { VehicleSearchSelect } from '@/components/fleet-assets/vehicle-workspace/search-select';

type RollPerson = { key: string; name: string; hint: { text: string; times: Record<string, string | null | undefined> } };

export type RollCallData = {
    site: { id: number; name: string };
    emergency: 'normal' | 'lockdown' | 'evacuation';
    doors: number;
    unlocked: number;
    sections: { key: string; title: string; icon: string; people: RollPerson[] }[];
    as_of: string;
};

const ICONS: Record<string, LucideIcon> = { 'id-card': IdCard, 'door-open': DoorOpen, 'hard-hat': HardHat };

/** "{from}", "{until}", "{clock}" and "{badge}" become NZ times. */
function hintText(hint: RollPerson['hint']): string {
    return hint.text.replace(/\{(\w+)\}/g, (_, key: string) => {
        const at = hint.times[key];
        return at ? (key === 'from' || key === 'until' ? seenTime(at) : formatTime(at)) : '—';
    });
}

export function RollCall({ open, sites, initialSiteId, onClose }: { open: boolean; sites: Option[]; initialSiteId: string | null; onClose: () => void }) {
    const [site, setSite] = useState<string>('');
    const [data, setData] = useState<RollCallData | null>(null);
    const [failed, setFailed] = useState(false);
    const [loading, setLoading] = useState(false);
    const [ticked, setTicked] = useState<Set<string>>(new Set());

    // Only as it opens: a page refresh while it's open must never clear the ticks.
    const wasOpen = useRef(false);
    useEffect(() => {
        if (open && !wasOpen.current) {
            setSite(initialSiteId && sites.some((option) => option.value === initialSiteId) ? initialSiteId : (sites[0]?.value ?? ''));
            setTicked(new Set());
        }
        wasOpen.current = open;
    }, [open, initialSiteId, sites]);

    const load = useCallback(async (siteId: string) => {
        if (!siteId) return;
        setLoading(true);
        setFailed(false);
        try {
            const { data: next } = await axios.get<RollCallData>(`/security-devices/access/sites/${siteId}/roll-call`, { headers: { Accept: 'application/json' } });
            setData(next);
        } catch {
            setFailed(true);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        if (open && site) {
            setData(null);
            setTicked(new Set());
            void load(site);
        }
    }, [open, site, load]);

    const everyone = useMemo(() => data?.sections.flatMap((section) => section.people.map((person) => person.key)) ?? [], [data]);
    const left = everyone.filter((key) => !ticked.has(key)).length;
    const siteName = data?.site.name ?? sites.find((option) => option.value === site)?.label ?? '…';

    return (
        <Sheet open={open} onOpenChange={(next) => !next && onClose()}>
            <SheetContent side="right" closeLabel="Close roll call" closeButtonClassName="text-band-foreground opacity-90" className="w-full gap-0 bg-card p-0 sm:max-w-[640px]" data-testid="sd-access-roll-call">
                <header className="eh-header shrink-0 text-band-foreground" style={{ borderRadius: 0 }}>
                    <div className="relative z-[1] px-5 pt-[18px] pr-14 pb-4">
                        <p className="text-xs font-semibold tracking-wide text-band-foreground/80 uppercase">Roll call · {siteName}</p>
                        <SheetTitle className="mt-1 text-section-title text-band-foreground">Fire roll call</SheetTitle>
                        <SheetDescription className="mt-0.5 text-sm text-band-foreground/85">Tick each person as they’re found safe at the assembly point</SheetDescription>
                        {data ? (
                            <div className="mt-2.5 flex flex-wrap gap-1.5">
                                <StatusBadge variant={left > 0 ? 'warning' : 'success'}>{left > 0 ? `${left} not ticked yet` : 'Everyone ticked'}</StatusBadge>
                                {data.emergency !== 'normal' ? (
                                    <StatusBadge variant="critical">{data.emergency === 'evacuation' ? 'Evacuation on' : 'Lockdown on'}</StatusBadge>
                                ) : null}
                            </div>
                        ) : null}
                    </div>
                </header>
                <div className="grid min-h-0 flex-1 content-start gap-4 overflow-y-auto px-5 py-4 scrollbar-pretty">
                    {sites.length > 1 ? (
                        <div className="grid gap-1.5">
                            <span className="text-sm font-semibold">Site</span>
                            <VehicleSearchSelect label="Site" value={site} options={sites} onChange={setSite} />
                        </div>
                    ) : null}
                    {failed ? (
                        <ErrorState title="The roll call couldn’t load" message="Check your connection, then try again." onRetry={() => void load(site)} />
                    ) : !data ? (
                        <SkeletonCard rows={4} />
                    ) : (
                        <>
                            <PopupCallout tone="warning">
                                {left > 0 ? <b className="font-semibold">{left} not ticked yet. </b> : null}
                                Leaving isn’t recorded (doors open from inside with a button), so someone listed may have gone earlier. Check by phone.
                            </PopupCallout>
                            <dl className="grid grid-cols-[minmax(0,9rem)_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-sm">
                                <dt className="text-muted-foreground">Listed at</dt>
                                <dd>{formatTime(data.as_of)} · refresh to see anyone who came in since</dd>
                                <dt className="text-muted-foreground">Doors</dt>
                                <dd>{data.doors === 0 ? 'None in UniFi Access' : data.unlocked === data.doors ? `All ${data.doors} unlocked` : `${data.unlocked} of ${data.doors} unlocked`}</dd>
                            </dl>
                            {data.sections.map((section) => {
                                const Icon = ICONS[section.icon] ?? IdCard;
                                return (
                                    <section key={section.key} aria-label={section.title} className="grid gap-2.5 border-t border-border pt-4">
                                        <h3 className="flex items-center gap-2 text-sm font-semibold">
                                            <Icon aria-hidden="true" className="size-4 text-muted-foreground" />
                                            {section.title}
                                        </h3>
                                        {section.people.length === 0 ? (
                                            <p className="text-subtle">Nobody listed.</p>
                                        ) : (
                                            section.people.map((person) => (
                                                <div key={person.key} className="flex items-start gap-2.5">
                                                    <Checkbox
                                                        id={`sd-roll-${person.key}`}
                                                        checked={ticked.has(person.key)}
                                                        onCheckedChange={(next) =>
                                                            setTicked((now) => {
                                                                const copy = new Set(now);
                                                                if (next === true) copy.add(person.key);
                                                                else copy.delete(person.key);
                                                                return copy;
                                                            })
                                                        }
                                                        className="mt-0.5"
                                                    />
                                                    <div className="min-w-0">
                                                        <label htmlFor={`sd-roll-${person.key}`} className="block text-sm font-medium">
                                                            {person.name}
                                                        </label>
                                                        <p className="text-caption">{hintText(person.hint)}</p>
                                                    </div>
                                                </div>
                                            ))
                                        )}
                                    </section>
                                );
                            })}
                            <p className="text-caption border-t border-border pt-3">
                                Residents aren’t listed from their fobs: check them against the house’s own list. Ticks stay on this screen only and aren’t saved.
                            </p>
                        </>
                    )}
                </div>
                <PopupFooter
                    start={
                        <Button type="button" variant="ghost" onClick={() => void load(site)} disabled={loading || !site}>
                            <RefreshCw aria-hidden="true" />
                            Refresh
                        </Button>
                    }
                >
                    <Button type="button" variant="outline" onClick={onClose}>
                        Close
                    </Button>
                </PopupFooter>
            </SheetContent>
        </Sheet>
    );
}
