import { render, screen } from '@testing-library/react';
import { Activity } from 'lucide-react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';

import { HeroShell as HealthSafetyHeroShell } from '../../pages/health-safety/components/hs-hero-kit';
import {
    HeroCluster,
    HeroClusterTile,
    HeroShell,
    HeroStatusPill,
    HeroSummaryMetric,
    HeroSummaryStrip,
} from './hero-kit';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

describe('shared command-centre hero kit', () => {
    it('renders the neutral hero composition with readable status and metric text', () => {
        render(
            <HeroShell
                footer={
                    <HeroSummaryStrip>
                        <HeroSummaryMetric tone="warning">
                            Open now · 7
                        </HeroSummaryMetric>
                    </HeroSummaryStrip>
                }
            >
                <HeroStatusPill>Control Room · live</HeroStatusPill>
                <HeroCluster title="Now" icon={Activity} columns={2}>
                    <HeroClusterTile
                        label="SLA breached"
                        value="2"
                        caption="need action"
                        tone="critical"
                    />
                </HeroCluster>
            </HeroShell>,
        );

        expect(screen.getByText('Control Room · live')).toBeInTheDocument();
        expect(screen.getByText('SLA breached')).toBeInTheDocument();
        expect(screen.getByText('need action')).toBeInTheDocument();
        expect(screen.getByText('Open now · 7')).toBeInTheDocument();
    });

    it('keeps the existing H&S import as a re-export of the neutral primitive', () => {
        expect(HealthSafetyHeroShell).toBe(HeroShell);
    });
});
