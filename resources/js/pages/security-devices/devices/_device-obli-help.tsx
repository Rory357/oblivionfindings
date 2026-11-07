/**
 * A computer's Obli help tab (drawing pg-sdr-9d, S&D Phase I): the newest help session's
 * conversation and steps, every session on this PC (newest first, each opening pop-up 96), what
 * Obli may do here, and any one-off request waiting for IT (pop-up 95). Pictures open only
 * through pop-up 94, with a reason, and every view is logged.
 */
import { EntityContextMenu, useEntityContextMenu, type MenuItem } from '@/components/lists';
import {
    FixRequestPopup,
    ObliConversation,
    ObliSessionPopup,
    ObliSteps,
    pictureFoot,
} from '@/components/security-devices/obli-fix-popups';
import { FactRows, Log, Note, QuietLine, Surface } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import { seenTime } from '@/lib/security-devices/device-time';
import type { ObliHelpTabData, ObliSessionSummary } from '@/lib/security-devices/obli-fixes';
import { router } from '@inertiajs/react';
import { Bot, ExternalLink, ShieldQuestion } from 'lucide-react';
import { useState } from 'react';
import { logTime, MainSide } from './_device-page-parts';

function fromUrl(name: string): string | null {
    if (typeof window === 'undefined') return null;
    return new URLSearchParams(window.location.search).get(name);
}

export function ObliHelpTab({ data, now }: { data: ObliHelpTabData; now: Date }) {
    const [session, setSession] = useState<string | null>(() => fromUrl('session'));
    const [request, setRequest] = useState<number | null>(() => {
        const value = fromUrl('request');
        return value && /^\d+$/.test(value) ? Number(value) : null;
    });
    const menu = useEntityContextMenu<ObliSessionSummary>();
    const latest = data.latest;

    const menuFor = (row: ObliSessionSummary): MenuItem[] => [
        { label: 'Open the record', icon: Bot, onClick: () => setSession(row.uuid) },
        ...(latest && latest.uuid === row.uuid && latest.ticket ? [{ label: 'Open as IT ticket', icon: ExternalLink, onClick: () => router.visit(latest.ticket!.href) }] : []),
    ];

    return (
        <div className="grid gap-5" data-testid="sd-obli-help">
            {data.requests.length > 0 ? (
                <Note icon={ShieldQuestion}>
                    <b className="font-semibold">
                        {data.requests.length === 1 ? 'Obli is waiting for IT to approve a one-off fix.' : `Obli is waiting for IT to approve ${data.requests.length} one-off fixes.`}
                    </b>{' '}
                    {data.requests.map((item, index) => (
                        <span key={item.id}>
                            {index > 0 ? ' · ' : ''}
                            {/* eslint-disable-next-line no-restricted-syntax -- the kit's quiet text action (.sg-act), not a form button. */}
                            <button type="button" className="sg-act ml-0" onClick={() => setRequest(item.id)}>
                                {`Review ${item.who}’s request (${seenTime(item.asked_at, now)})`}
                            </button>
                        </span>
                    ))}
                </Note>
            ) : null}
            <MainSide
                main={
                    latest ? (
                        <>
                            <Surface
                                title={latest.title}
                                sub={
                                    <span className="inline-flex flex-wrap items-center gap-2">
                                        <StatusBadge variant={latest.badge.tone}>{latest.badge.label}</StatusBadge>
                                        <span>
                                            {latest.who} · {seenTime(latest.at, now)}
                                        </span>
                                    </span>
                                }
                                action={{ label: 'Open the record', onClick: () => setSession(latest.uuid), ariaLabel: `Open the record: ${latest.title}` }}
                            >
                                <div className="px-[18px] pt-1 pb-4">
                                    <ObliConversation record={latest} now={now} />
                                </div>
                            </Surface>
                            <Surface title="What Obli did" sub="Each step, and whether a screen picture was kept to check it">
                                <div className="grid gap-2 px-[18px] pb-4">
                                    <ObliSteps record={latest} now={now} />
                                    <p className="text-caption">{pictureFoot(latest)}</p>
                                </div>
                            </Surface>
                        </>
                    ) : (
                        <QuietLine title="Obli help" text="Nobody has asked Obli for help on this PC in the last 30 days." />
                    )
                }
                side={
                    <>
                        {data.sessions.length > 0 ? (
                            <Log
                                title="Help on this PC"
                                sub="Last 30 days"
                                limit={50}
                                items={data.sessions.map((row) => ({
                                    key: row.uuid,
                                    time: logTime(row.at, now),
                                    tone: row.ring,
                                    title: row.title,
                                    sub: row.sub,
                                    action: { label: 'Open', onClick: () => setSession(row.uuid), ariaLabel: `Open: ${row.title}` },
                                    menu: menuFor(row),
                                    onContextMenu: (event) => menu.open(event, row),
                                }))}
                            />
                        ) : null}
                        <FactRows title="What Obli may do here" rows={data.may} />
                        <QuietLine text="Obli can’t help at the Windows sign-in screen or “Allow changes” prompts; Windows doesn’t let any app use them." />
                    </>
                }
            />
            {menu.ctx ? <EntityContextMenu x={menu.ctx.x} y={menu.ctx.y} icon={Bot} title={menu.ctx.record.title} items={menuFor(menu.ctx.record)} onClose={menu.close} /> : null}
            <ObliSessionPopup open={session !== null} uuid={session} initial={latest} now={now} onClose={() => setSession(null)} />
            <FixRequestPopup
                open={request !== null}
                requestId={request}
                now={now}
                onClose={() => {
                    setRequest(null);
                    router.reload({ only: ['obli'] });
                }}
            />
        </div>
    );
}
