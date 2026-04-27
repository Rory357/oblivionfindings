import { router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import {
    type PropsWithChildren,
    type TouchEvent,
    useRef,
    useState,
} from 'react';

import { cn } from '@/lib/utils';

const THRESHOLD = 72;

export default function PullToRefresh({ children }: PropsWithChildren) {
    const startY = useRef<number | null>(null);
    const [pull, setPull] = useState(0);
    const [refreshing, setRefreshing] = useState(false);

    const onTouchStart = (event: TouchEvent<HTMLDivElement>) => {
        if (window.scrollY > 0 || refreshing) return;
        startY.current = event.touches[0]?.clientY ?? null;
    };

    const onTouchMove = (event: TouchEvent<HTMLDivElement>) => {
        if (startY.current === null || window.scrollY > 0 || refreshing) {
            return;
        }

        const y = event.touches[0]?.clientY ?? startY.current;
        const delta = Math.max(0, y - startY.current);
        setPull(Math.min(THRESHOLD + 24, delta / 1.8));
    };

    const onTouchEnd = () => {
        if (pull >= THRESHOLD) {
            setRefreshing(true);
            router.reload({
                preserveScroll: true,
                onFinish: () => {
                    setRefreshing(false);
                    setPull(0);
                    startY.current = null;
                },
            });
            return;
        }

        setPull(0);
        startY.current = null;
    };

    return (
        <div
            onTouchStart={onTouchStart}
            onTouchMove={onTouchMove}
            onTouchEnd={onTouchEnd}
            className="relative min-h-full"
        >
            <div
                aria-hidden
                className={cn(
                    'pointer-events-none absolute inset-x-0 top-0 z-20 flex -translate-y-full justify-center transition-transform duration-150 lg:hidden',
                    (pull > 0 || refreshing) && 'translate-y-2',
                )}
            >
                <div className="inline-flex items-center gap-2 rounded-full border bg-background/95 px-3 py-1.5 text-xs font-medium shadow-sm">
                    <RefreshCw
                        className={cn(
                            'h-3.5 w-3.5',
                            (refreshing || pull >= THRESHOLD) && 'animate-spin',
                        )}
                    />
                    {refreshing
                        ? 'Refreshing'
                        : pull >= THRESHOLD
                          ? 'Release to refresh'
                          : 'Pull to refresh'}
                </div>
            </div>

            <div
                style={{
                    transform:
                        pull > 0 && !refreshing
                            ? `translateY(${Math.min(pull, THRESHOLD)}px)`
                            : undefined,
                }}
                className="transition-transform duration-150"
            >
                {children}
            </div>
        </div>
    );
}
