import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import { usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

type ClientOption = { id: number; name: string };

function csrfToken(): string | null {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? null
    );
}

export default function GlobalQueryBar() {
    const page = usePage<any>();
    const auth = page.props?.auth;
    const can = auth?.can;
    const legacyRole = auth?.user?.role as string | null | undefined;
    const isPortalUser =
        legacyRole === 'client' || legacyRole === 'next_of_kin';

    const canAsk =
        !!can?.rag?.askAny || !!can?.rag?.askAssigned || !!can?.rag?.askSelf;

    const defaultClientId = useMemo(() => {
        const maybeClient = page.props?.client;
        if (maybeClient?.id) return String(maybeClient.id);
        return '';
    }, [page.props]);

    const [open, setOpen] = useState(false);
    const [clients, setClients] = useState<ClientOption[]>([]);
    const [clientId, setClientId] = useState<string>(defaultClientId);
    const [question, setQuestion] = useState('');
    const [loading, setLoading] = useState(false);
    const [answer, setAnswer] = useState<string | null>(null);
    const [sources, setSources] = useState<
        Array<{ filename?: string; file_id?: string; text?: string }>
    >([]);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        // keep in sync when navigating between clients
        setClientId(defaultClientId);
    }, [defaultClientId]);

    useEffect(() => {
        if (!open) return;

        // Load clients list on demand
        (async () => {
            try {
                const res = await fetch('/rag/clients', {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!res.ok) return;
                const json = await res.json();
                setClients(json.clients ?? []);
            } catch {
                // ignore
            }
        })();
    }, [open]);

    // Portal users should only have the per-client query UI on the client profile page.
    if (isPortalUser) {
        return null;
    }

    function fail(msg: string) {
        setError(msg);
        toast.error(msg);
    }

    async function submit() {
        setError(null);
        setAnswer(null);
        setSources([]);

        if (!canAsk) {
            fail('You do not have permission to ask questions.');
            return;
        }

        if (!clientId) {
            fail('Select a client first.');
            return;
        }

        if (!question.trim()) {
            fail('Enter a question.');
            return;
        }

        setLoading(true);
        try {
            const token = csrfToken();
            const res = await fetch('/rag/ask', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ client_id: Number(clientId), question }),
            });

            const json = await res.json().catch(() => ({}) as any);

            if (!res.ok) {
                const msg = json?.error ?? 'Unable to answer right now.';
                fail(msg);
                return;
            }

            setAnswer(json?.text ?? null);
            setSources(json?.sources ?? []);

            // Optional: toast success if you want feedback
            // toast.success('Answer generated');
        } catch {
            fail('Unable to answer right now.');
        } finally {
            setLoading(false);
        }
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(v) => {
                setOpen(v);
                if (v) {
                    setAnswer(null);
                    setSources([]);
                    setError(null);
                }
            }}
        >
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    className={cn(
                        'hidden h-9 w-[320px] justify-start gap-2 px-3 text-sm text-muted-foreground lg:flex',
                        !canAsk && 'opacity-60',
                    )}
                    disabled={!canAsk}
                >
                    <Search className="h-4 w-4 opacity-70" />
                    Ask about a client…
                </Button>
            </DialogTrigger>

            {/* Mobile trigger */}
            <DialogTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="group h-9 w-9 cursor-pointer lg:hidden"
                    disabled={!canAsk}
                >
                    <Search className="!size-5 opacity-80 group-hover:opacity-100" />
                </Button>
            </DialogTrigger>

            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Ask</DialogTitle>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div className="space-y-2">
                            <Label>Client</Label>
                            <Select
                                value={clientId}
                                onValueChange={setClientId}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select client" />
                                </SelectTrigger>
                                <SelectContent>
                                    {clients.map((c) => (
                                        <SelectItem
                                            key={c.id}
                                            value={String(c.id)}
                                        >
                                            {c.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-2">
                            <Label>Question</Label>
                            <Input
                                value={question}
                                onChange={(e) => setQuestion(e.target.value)}
                                placeholder="E.g. What meds are they on? Any recent incidents?"
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        submit();
                                    }
                                }}
                            />
                        </div>
                    </div>

                    {(answer || sources.length > 0) && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Answer
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="text-sm whitespace-pre-wrap">
                                    {answer ?? 'No answer returned.'}
                                </div>

                                {sources.length > 0 && (
                                    <>
                                        <Separator />
                                        <div className="text-xs font-medium text-muted-foreground">
                                            Sources
                                        </div>
                                        <div className="space-y-2">
                                            {sources.map((s, idx) => (
                                                <div
                                                    key={idx}
                                                    className="rounded-md border p-2 text-xs"
                                                >
                                                    <div className="text-muted-foreground">
                                                        {(s.filename ||
                                                            s.file_id) ??
                                                            'Source'}
                                                    </div>
                                                    <div className="mt-1 line-clamp-4 whitespace-pre-wrap">
                                                        {s.text}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </>
                                )}
                            </CardContent>
                        </Card>
                    )}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => setOpen(false)}
                    >
                        Close
                    </Button>
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={loading || !canAsk}
                    >
                        {loading ? 'Asking…' : 'Ask'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
