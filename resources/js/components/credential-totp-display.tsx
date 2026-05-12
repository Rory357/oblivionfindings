import { Button } from '@/components/ui/button';
import { Copy, Loader2, RotateCw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

type Props = {
    siteId: number;
    credentialId: number;
    onCopy?: () => void;
};

type TotpResponse = {
    code: string;
    seconds_remaining: number;
    period: number;
};

export function CredentialTotpDisplay({ siteId, credentialId, onCopy }: Props) {
    const [state, setState] = useState<TotpResponse | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const intervalRef = useRef<number | null>(null);

    const fetchCode = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const xsrf = decodeURIComponent(
                document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
            );
            const res = await fetch(
                `/sites/${siteId}/credentials/${credentialId}/totp/code`,
                {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': xsrf,
                    },
                },
            );
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }
            const data = (await res.json()) as TotpResponse;
            setState(data);
        } catch (e) {
            setError(
                e instanceof Error ? e.message : 'Could not fetch one-time code.',
            );
        } finally {
            setLoading(false);
        }
    }, [siteId, credentialId]);

    useEffect(() => {
        fetchCode();
        return () => {
            if (intervalRef.current !== null) {
                window.clearInterval(intervalRef.current);
            }
        };
    }, [fetchCode]);

    useEffect(() => {
        if (!state) return;
        if (intervalRef.current !== null) {
            window.clearInterval(intervalRef.current);
        }
        intervalRef.current = window.setInterval(() => {
            setState((prev) => {
                if (!prev) return prev;
                const next = prev.seconds_remaining - 1;
                if (next <= 0) {
                    fetchCode();
                    return prev;
                }
                return { ...prev, seconds_remaining: next };
            });
        }, 1000);
        return () => {
            if (intervalRef.current !== null) {
                window.clearInterval(intervalRef.current);
            }
        };
    }, [state?.code, fetchCode]);

    const handleCopy = async () => {
        if (!state) return;
        try {
            await navigator.clipboard.writeText(state.code);
            onCopy?.();
        } catch {
            // clipboard may be blocked; ignore silently
        }
    };

    if (error) {
        return (
            <div className="flex items-center gap-2">
                <span className="text-sm text-status-critical">{error}</span>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={fetchCode}
                    aria-label="Retry"
                >
                    <RotateCw className="h-4 w-4" />
                </Button>
            </div>
        );
    }

    if (loading && !state) {
        return (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="h-4 w-4 animate-spin" />
                Generating code…
            </div>
        );
    }

    if (!state) return null;

    const fraction = state.seconds_remaining / state.period;
    const radius = 10;
    const circumference = 2 * Math.PI * radius;
    const dashOffset = circumference * (1 - fraction);

    return (
        <div className="flex items-center gap-3">
            <span className="font-mono text-2xl tracking-widest tabular-nums">
                {state.code.slice(0, 3)} {state.code.slice(3)}
            </span>
            <svg
                width="28"
                height="28"
                viewBox="0 0 28 28"
                className="text-primary"
                aria-label={`${state.seconds_remaining} seconds remaining`}
            >
                <circle
                    cx="14"
                    cy="14"
                    r={radius}
                    fill="none"
                    stroke="currentColor"
                    strokeOpacity="0.15"
                    strokeWidth="3"
                />
                <circle
                    cx="14"
                    cy="14"
                    r={radius}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="3"
                    strokeLinecap="round"
                    strokeDasharray={circumference}
                    strokeDashoffset={dashOffset}
                    transform="rotate(-90 14 14)"
                />
            </svg>
            <span className="text-xs tabular-nums text-muted-foreground">
                {state.seconds_remaining}s
            </span>
            <Button
                variant="ghost"
                size="sm"
                onClick={handleCopy}
                aria-label="Copy code"
            >
                <Copy className="h-4 w-4" />
            </Button>
        </div>
    );
}
