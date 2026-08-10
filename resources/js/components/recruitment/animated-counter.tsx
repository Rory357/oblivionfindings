import { useEffect, useRef, useState } from 'react';

interface AnimatedCounterProps {
    value: number;
    duration?: number;
    prefix?: string;
    suffix?: string;
    decimals?: number;
}

export function AnimatedCounter({
    value,
    duration = 800,
    prefix = '',
    suffix = '',
    decimals = 0,
}: AnimatedCounterProps) {
    const [display, setDisplay] = useState(0);
    const frameRef = useRef<number>(0);
    const startRef = useRef<number>(0);

    useEffect(() => {
        if (value === 0) {
            setDisplay(0);
            return;
        }

        const start = performance.now();
        startRef.current = start;

        function animate(now: number) {
            const elapsed = now - (startRef.current ?? now);
            const progress = Math.min(elapsed / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            setDisplay(eased * value);

            if (progress < 1) {
                frameRef.current = requestAnimationFrame(animate);
            }
        }

        frameRef.current = requestAnimationFrame(animate);
        return () => {
            if (frameRef.current) cancelAnimationFrame(frameRef.current);
        };
    }, [value, duration]);

    const formatted =
        decimals > 0
            ? display.toFixed(decimals)
            : Math.round(display).toString();

    return (
        <span>
            {prefix}
            {formatted}
            {suffix}
        </span>
    );
}
