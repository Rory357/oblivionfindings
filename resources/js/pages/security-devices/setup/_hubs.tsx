/**
 * Setup › Hubs & networks › Hubs (drawing pg-sdr-15p): a card for each hub, a banner when a hub's update failed and
 * it went back, what its update report says, and how hub updates work. Hub updates wait for the test hub
 * (Stephan, 3 Nov 2026: "Wait for the test hub").
 */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { NextStep } from '@/components/security-devices/device-blocks';
import { FactRows, KnowList, PlaceCards, QuietLine, SideBySide } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';
import { toast } from 'sonner';
import { LineText, ToneLine, matches } from './_parts';
import type { SetupHubs as SetupHubsData, Tone } from './_types';

const JSON_HEADERS = { headers: { Accept: 'application/json' } };
const BADGE: Record<Tone, 'success' | 'warning' | 'critical' | 'info' | 'neutral'> = { success: 'success', warning: 'warning', critical: 'critical', info: 'info', neutral: 'neutral' };
const RING = { success: 'success', warning: 'warning', critical: 'critical', info: 'info', neutral: 'none' } as const;

export function SetupHubs({
    data,
    query,
    now,
    onOpenHub,
    onAddHub,
}: {
    data: SetupHubsData;
    query: string;
    now: Date;
    onOpenHub: (hubId: number) => void;
    onAddHub: () => void;
}) {
    const [asking, setAsking] = useState<'try' | 'hold' | null>(null);
    const [busy, setBusy] = useState(false);

    if (data.locked) {
        return <QuietLine title="Hubs" text="Hubs show to people who look after connected services or approve hubs." />;
    }

    const cards = data.cards.filter((card) => matches(query, card.name, card.sub, ...card.lines.map((line) => line.text)));
    const reload = () => router.reload({ only: ['hubs'] });
    const send = (request: Promise<unknown>, done: string) => {
        setBusy(true);
        request
            .then(() => {
                toast.success(done);
                setAsking(null);
                reload();
            })
            .catch(() => toast.error('That didn’t go through. Check the hub panel before trying again.'))
            .finally(() => setBusy(false));
    };
    const banner = data.banner;

    return (
        <div className="space-y-5">
            {banner ? (
                <NextStep
                    tone="warning"
                    label={banner.label}
                    title={banner.title}
                    sub={<LineText line={{ text: banner.text, at: banner.at }} now={now} />}
                    then={banner.then}
                    actions={
                        <>
                            {banner.can.try_again ? (
                                <Button size="sm" onClick={() => setAsking('try')}>
                                    Try again
                                </Button>
                            ) : null}
                            {data.can.hold && !data.held ? (
                                <Button size="sm" variant="outline" onClick={() => setAsking('hold')}>
                                    Hold updates
                                </Button>
                            ) : null}
                            <Button size="sm" variant="ghost" onClick={() => onOpenHub(banner.hub_id)}>
                                Open hub
                            </Button>
                        </>
                    }
                />
            ) : null}

            {data.cards.length === 0 ? (
                <QuietLine
                    title="Hubs"
                    text="No hubs yet. A hub is a small program on a computer or Raspberry Pi at a Site; it checks your devices and sends results to Oblivion."
                    action={data.can.add ? { label: 'Add a hub', onClick: onAddHub } : null}
                />
            ) : (
                <PlaceCards
                    title="Hubs"
                    sub={data.held ? 'Updates are on hold for every hub' : 'Click a hub’s menu to open it'}
                    action={data.can.add ? { label: 'Add a hub', onClick: onAddHub } : null}
                    cols={2}
                    items={cards.map((card) => ({
                        id: card.id,
                        name: card.name,
                        sub: card.sub,
                        flag: (
                            <StatusBadge variant={BADGE[card.flag.tone]} size="sm">
                                {card.flag.text}
                            </StatusBadge>
                        ),
                        facts: card.lines.map((line) => ({ tone: RING[line.tone], text: <LineText line={line} now={now} /> })),
                        menu: [{ label: 'Open hub', onClick: () => onOpenHub(card.id) }],
                    }))}
                />
            )}
            {data.cards.length > 0 && cards.length === 0 ? <QuietLine text="No hub matches your search." /> : null}

            <SideBySide>
                <KnowList title="How hub updates work" items={data.how.map((line) => ({ tone: RING[line.tone], text: <LineText line={line} now={now} /> }))} />
                {data.for_it ? (
                    <FactRows title="For IT" sub="From the hub’s update report" rows={data.for_it.map(([label, value]) => [label, value])} />
                ) : (
                    <div className="grid content-start gap-3">
                        {data.held && data.can.hold ? (
                            <div className="flex flex-wrap items-center gap-3">
                                <ToneLine line={{ tone: 'warning', text: 'Updates are on hold for every hub' }} now={now} />
                                <Button size="sm" variant="outline" onClick={() => setAsking('hold')}>
                                    Let updates go
                                </Button>
                            </div>
                        ) : null}
                    </div>
                )}
            </SideBySide>

            <ConfirmDialog
                open={asking === 'try'}
                onClose={() => setAsking(null)}
                processing={busy}
                variant="default"
                title="Try the update again now?"
                description="The hub looks for the update the next time it checks in, instead of waiting for tonight. While it restarts, its devices show Can’t check for a few minutes."
                confirmText="Try again"
                onConfirm={() => banner && send(axios.post(`/security-devices/hubs/${banner.hub_id}/check-update`, {}, JSON_HEADERS), 'Asked. The hub tries the next time it checks in.')}
            />
            <ConfirmDialog
                open={asking === 'hold'}
                onClose={() => setAsking(null)}
                processing={busy}
                variant="default"
                title={data.held ? 'Let hub updates go?' : 'Hold updates for every hub?'}
                description={
                    data.held
                        ? 'Hubs go back to updating overnight in their windows. The others still wait for a test-first hub to have a new version working.'
                        : 'No hub updates until you let updates go, including a retry. Hubs keep working on the version they have.'
                }
                confirmText={data.held ? 'Let updates go' : 'Hold updates'}
                onConfirm={() =>
                    send(axios.put('/security-devices/hubs/updates/hold', { held: !data.held }, JSON_HEADERS), data.held ? 'Updates let go.' : 'Updates held for every hub.')
                }
            />
        </div>
    );
}
