import { act, render, screen } from '@testing-library/react';
import { Users } from 'lucide-react';
import type React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { OpsStatCard } from '@/components/ops-stat-card';

import { clampCountUpProgress, StatTile } from './stat-tile';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children }: { children: React.ReactNode }) => children,
}));

describe('StatTile count-up bounds', () => {
    let callbacks: FrameRequestCallback[];

    beforeEach(() => {
        callbacks = [];
        vi.spyOn(performance, 'now').mockReturnValue(100);
        vi.stubGlobal(
            'requestAnimationFrame',
            vi.fn((callback: FrameRequestCallback) => {
                callbacks.push(callback);
                return callbacks.length;
            }),
        );
        vi.stubGlobal('cancelAnimationFrame', vi.fn());
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('clamps negative, zero, mid-range and over-duration timestamps', () => {
        expect(clampCountUpProgress(90, 100, 600)).toBe(0);
        expect(clampCountUpProgress(100, 100, 600)).toBe(0);
        expect(clampCountUpProgress(400, 100, 600)).toBe(0.5);
        expect(clampCountUpProgress(800, 100, 600)).toBe(1);
    });

    it('never renders a negative positive-count frame when RAF precedes the captured start', () => {
        render(<StatTile label="Total Users" value={10} />);

        act(() => callbacks.shift()?.(90));
        expect(screen.getByText('0')).toBeInTheDocument();

        act(() => callbacks.shift()?.(400));
        expect(screen.getByText('9')).toBeInTheDocument();

        act(() => callbacks.shift()?.(800));
        expect(screen.getByText('10')).toBeInTheDocument();
        expect(screen.queryByText(/^-\d/)).not.toBeInTheDocument();
    });

    it('renders the final value immediately when reduced motion is requested', () => {
        vi.stubGlobal(
            'matchMedia',
            vi.fn((query: string) => ({
                matches: query === '(prefers-reduced-motion: reduce)',
                media: query,
                onchange: null,
                addEventListener: vi.fn(),
                removeEventListener: vi.fn(),
                addListener: vi.fn(),
                removeListener: vi.fn(),
                dispatchEvent: vi.fn(),
            })),
        );

        render(<StatTile label="Active" value={78} />);

        expect(screen.getByText('78')).toBeInTheDocument();
        expect(requestAnimationFrame).not.toHaveBeenCalled();
    });

    it('keeps compatibility cards static when the owning page opts out of animation', () => {
        render(
            <OpsStatCard
                label="Total Users"
                value={83}
                icon={Users}
                staticValue
            />,
        );

        expect(screen.getByText('83')).toBeInTheDocument();
        expect(requestAnimationFrame).not.toHaveBeenCalled();
    });
});
