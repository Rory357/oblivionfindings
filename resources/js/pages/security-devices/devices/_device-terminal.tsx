/**
 * A network device's Terminal tab (S&D rebuild Phase G, drawing pg-sdr-3b and pop-up 60): a
 * command line on the device, through the Site's hub, signed in with the saved login. IT admins
 * only (securityDevices.terminal.use). Every line typed is kept; it closes after 15 minutes without
 * typing. Pop-up 60 says all that before anything opens.
 */
import { PopupViewer } from '@/components/popup';
import { FactRows, Log, Note, QuietLine, Surface, type LogItem } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { seenTime } from '@/lib/security-devices/device-time';
import axios from 'axios';
import { SquareTerminal } from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { MainSide } from './_device-page-parts';
import type { TerminalTabData } from './_device-page-types';

const CLOSED_WORDS: Record<string, string> = {
    closed: 'You closed the terminal.',
    idle: 'Closed after 15 minutes without typing.',
    too_long: 'Closed after an hour. Open a new one to carry on.',
    hub_offline: 'The hub didn’t pick it up. It may be offline; try again in a minute.',
    host_key_changed: 'The device’s SSH key has changed, so no login was sent. Check it before trusting the new key.',
    host_key_untrusted: 'The device’s SSH key isn’t trusted yet, so no login was sent.',
    login_failed: 'The device didn’t accept the saved login.',
    unreachable: 'The hub couldn’t reach the device.',
    ended: 'The device ended the session.',
    hub_restarted: 'The hub restarted, so the terminal closed.',
};

type Live = { id: string; state: string; reason: string | null };

export function TerminalTab({ deviceId, deviceName, terminal, now }: { deviceId: number; deviceName: string; terminal: TerminalTabData; now: Date }) {
    const [asking, setAsking] = useState(false);
    const [live, setLive] = useState<Live | null>(terminal.session ? { id: terminal.session.id, state: terminal.session.state, reason: null } : null);
    const [lines, setLines] = useState<{ seq: number; text: string }[]>([]);
    const [typed, setTyped] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const after = useRef(0);
    const screen = useRef<HTMLPreElement | null>(null);

    const isLive = live !== null && ['requested', 'opening', 'open'].includes(live.state);

    // Read what the device sent back while the terminal is live.
    useEffect(() => {
        if (!live || !isLive) return;
        let stopped = false;
        let timer: ReturnType<typeof setTimeout> | null = null;
        const read = async () => {
            try {
                const response = await axios.get<{ state: string; reason: string | null; lines: { seq: number; text: string }[] }>(`/security-devices/terminal/${live.id}/output`, { params: { after: after.current } });
                if (stopped) return;
                if (response.data.lines.length > 0) {
                    after.current = response.data.lines[response.data.lines.length - 1].seq;
                    setLines((current) => [...current, ...response.data.lines].slice(-2000));
                }
                if (response.data.state !== live.state || response.data.reason !== live.reason) {
                    setLive({ id: live.id, state: response.data.state, reason: response.data.reason });
                }
            } catch {
                if (!stopped) setError('Lost touch with Oblivion. Trying again…');
            }
            if (!stopped) timer = setTimeout(read, 700);
        };
        void read();
        return () => {
            stopped = true;
            if (timer) clearTimeout(timer);
        };
    }, [live, isLive]);

    useEffect(() => {
        if (screen.current) screen.current.scrollTop = screen.current.scrollHeight;
    }, [lines]);

    const open = async () => {
        setBusy(true);
        setError(null);
        try {
            const response = await axios.post<{ session: string; state: string }>(`/security-devices/devices/${deviceId}/terminal`);
            after.current = 0;
            setLines([]);
            setLive({ id: response.data.session, state: response.data.state, reason: null });
            setAsking(false);
        } catch (failure) {
            const data = axios.isAxiosError(failure) ? failure.response?.data : undefined;
            setError((data?.errors?.terminal?.[0] as string | undefined) ?? 'The terminal didn’t open. Try again.');
        } finally {
            setBusy(false);
        }
    };

    const send = useCallback(
        async (body: { line?: string; interrupt?: boolean }) => {
            if (!live) return;
            try {
                await axios.post(`/security-devices/terminal/${live.id}/input`, body);
                setError(null);
            } catch (failure) {
                const data = axios.isAxiosError(failure) ? failure.response?.data : undefined;
                setError((data?.errors?.line?.[0] as string | undefined) ?? 'That line wasn’t sent. Try again.');
            }
        },
        [live],
    );

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const line = typed;
        setTyped('');
        void send({ line });
    };

    const close = async () => {
        if (!live) return;
        try {
            await axios.post(`/security-devices/terminal/${live.id}/close`);
        } finally {
            setLive({ ...live, state: 'closed', reason: 'closed' });
        }
    };

    const main = !terminal.ready && !isLive ? (
        <QuietLine title="Terminal" text={terminal.reason ?? 'The terminal isn’t available for this device.'} />
    ) : live ? (
        <Surface
            title="Terminal"
            sub={`Through ${terminal.hub ?? 'the Site hub'} · signed in with the saved login`}
            action={isLive ? { label: 'Close terminal', onClick: () => void close() } : { label: 'Open a new terminal', onClick: () => setAsking(true) }}
        >
            <div className="grid gap-2 px-[18px] pt-2 pb-4">
                <p className="font-mono text-[12px] text-muted-foreground">{terminal.host} · SSH</p>
                <pre
                    ref={screen}
                    aria-label={`${deviceName} terminal`}
                    aria-live="polite"
                    className="h-[420px] overflow-auto rounded-lg border border-border bg-muted p-3 font-mono text-[13px] leading-snug whitespace-pre-wrap"
                >
                    {live.state === 'requested' || live.state === 'opening' ? 'Opening through the hub…\n' : null}
                    {lines.map((line) => line.text).join('')}
                </pre>
                {isLive ? (
                    <form onSubmit={submit} className="flex items-center gap-2">
                        <Input
                            aria-label="Command"
                            className="font-mono"
                            value={typed}
                            maxLength={1024}
                            placeholder={live.state === 'open' ? 'Type a command and press Enter' : 'Waiting for the hub…'}
                            disabled={live.state !== 'open'}
                            onChange={(event) => setTyped(event.target.value)}
                            autoComplete="off"
                            spellCheck={false}
                        />
                        <Button type="submit" variant="outline" disabled={live.state !== 'open'}>
                            Send
                        </Button>
                        <Button type="button" variant="ghost" disabled={live.state !== 'open'} onClick={() => void send({ interrupt: true })}>
                            Ctrl+C
                        </Button>
                    </form>
                ) : (
                    <Note>{CLOSED_WORDS[live.reason ?? 'closed'] ?? 'The terminal closed.'}</Note>
                )}
                {error ? (
                    <Note tone="critical">
                        <span role="alert">{error}</span>
                    </Note>
                ) : null}
            </div>
        </Surface>
    ) : (
        <QuietLine title="Terminal" text="Type commands on this device, through the hub." action={{ label: 'Open terminal', onClick: () => setAsking(true) }} />
    );

    return (
        <>
            <MainSide
                main={main}
                side={
                    <>
                        <FactRows
                            title="About this terminal"
                            rows={[
                                ['Opens through', terminal.hub ?? 'The Site hub'],
                                ['Login', 'Saved login (stored encrypted)'],
                                ['Who can open it', 'IT admins'],
                                ['Recorded', 'Every command is kept below'],
                            ]}
                        />
                        <QuietLine title="Close it when you’re done" text="The connection closes by itself after 15 minutes without typing." />
                        {terminal.history.length > 0 ? (
                            <Log
                                title="Commands typed here"
                                limit={20}
                                items={terminal.history.flatMap((session): LogItem[] =>
                                    session.commands.length === 0
                                        ? [{ key: session.id, time: session.at ? seenTime(session.at, now) : '', tone: 'none' as const, title: `${session.who} opened a terminal`, sub: 'No commands typed' }]
                                        : session.commands.map((command, index) => ({
                                              key: `${session.id}-${index}`,
                                              time: index === 0 && session.at ? seenTime(session.at, now) : '',
                                              tone: 'info' as const,
                                              title: command,
                                              sub: index === 0 ? session.who : undefined,
                                          })),
                                )}
                            />
                        ) : null}
                    </>
                }
            />
            <PopupViewer
                open={asking}
                onClose={() => setAsking(false)}
                size="lg"
                icon={SquareTerminal}
                title="Open terminal"
                description={`Type commands on ${deviceName}, through the hub.`}
                context={{ title: deviceName, tag: 'Command line', note: `${terminal.host} · through ${terminal.hub ?? 'the Site hub'}` }}
                callout={{ tone: 'warning', content: 'Commands change the real device. A wrong one can take the house offline.' }}
                facts={[
                    ['Opens', 'As the Terminal tab on this device’s page'],
                    ['Through', terminal.hub ?? 'The Site hub'],
                    ['Signed in as', 'The saved login, stored encrypted in Oblivion'],
                    ['Kept', 'Every command you type is kept in this tab'],
                    ['Closes', 'By itself after 15 minutes without typing'],
                    ['Who can open it', 'IT admins'],
                ]}
                closeLabel="Cancel"
                action={
                    <Button type="button" onClick={() => void open()} disabled={busy || !terminal.ready}>
                        {busy ? 'Opening…' : 'Open terminal tab'}
                    </Button>
                }
            >
                {error && asking ? (
                    <Note tone="critical">
                        <span role="alert">{error}</span>
                    </Note>
                ) : null}
            </PopupViewer>
        </>
    );
}
