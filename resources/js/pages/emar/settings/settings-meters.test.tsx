import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { hasSettingsMeters, SettingsMeters } from './_meters';
import type { Pending } from './_model';
import type { Built } from './_nav';
import type { MedicineRule } from './_rules';
import type { WitnessPinStaffRow } from './_sections';
import type { RoundTemplate } from './_templates';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children }: { children: ReactNode }) => <a>{children}</a>,
}));

const everything: Built = {
    rules: ['overview', 'medicines', 'safety'],
    rounds: ['overview', 'templates', 'timing'],
    staff: ['overview', 'competency', 'exemptions', 'pins', 'status'],
    history: ['decide', 'changes'],
};
const pending = (state: Pending['state'], key: string): Pending => ({
    group: 'safety',
    key,
    view: 'rules',
    section: 'safety',
    label: key,
    state,
    until: '',
});
const rule = (id: number, active: boolean, overlaps: number[] = []) =>
    ({ id, active, overlaps }) as unknown as MedicineRule;
const template = (id: number, status: string, site_id: number | null) =>
    ({ id, status, site_id }) as unknown as RoundTemplate;
const pin = (id: number, status: string) =>
    ({ id, status }) as unknown as WitnessPinStaffRow;

afterEach(cleanup);

const meters = (built: Built, go = vi.fn()) =>
    render(
        <SettingsMeters
            built={built}
            view="rules"
            sec="overview"
            go={go}
            pending={[
                pending('nc', 'a'),
                pending('nc', 'b'),
                pending('default', 'c'),
            ]}
            rules={[rule(1, true, [2]), rule(2, true, [1]), rule(3, false)]}
            templates={[
                template(1, 'active', 3),
                template(2, 'active', 4),
                template(3, 'active', 4),
                template(4, 'paused', 3),
            ]}
            pins={[
                pin(1, 'set'),
                pin(2, 'set'),
                pin(3, 'set'),
                pin(4, 'locked'),
                pin(5, 'not_set'),
                pin(6, 'reset'),
            ]}
        />,
    );

describe('Settings header meters', () => {
    it('counts what is still to decide and how much was never configured', () => {
        meters(everything);
        expect(screen.getByText('3')).toBeInTheDocument();
        // The short form: the long one truncated at 1280 and at 200 %.
        expect(screen.getByText('2 not configured')).toBeInTheDocument();
        expect(screen.getByText('2 active')).toBeInTheDocument();
        expect(screen.getByText('1 paused · 2 overlap')).toBeInTheDocument();
        expect(screen.getByText('3 active')).toBeInTheDocument();
        expect(screen.getByText('1 paused · 2 houses')).toBeInTheDocument();
    });

    it.each([
        [[], 'Nothing to decide'],
        [[pending('default', 'a')], 'All are defaults'],
        [[pending('nc', 'a'), pending('nc', 'b')], '2 not configured'],
    ])('says what “still to decide” is made of (%#)', (list, caption) => {
        render(
            <SettingsMeters
                built={{ history: ['decide'] }}
                view="history"
                sec="decide"
                go={vi.fn()}
                pending={list}
                rules={[]}
                templates={[]}
                pins={[]}
            />,
        );
        expect(screen.getByText(caption)).toBeInTheDocument();
    });

    it('shows witness PINs as a share of staff, as a donut', () => {
        meters(everything);
        expect(screen.getByText('50%')).toBeInTheDocument();
        expect(screen.getByText(/3 of 6 set/)).toBeInTheDocument();
        expect(screen.getByText(/1 locked · 2 to set/)).toBeInTheDocument();
    });

    it('makes every meter a link to where its number lives', () => {
        const go = vi.fn();
        meters(everything, go);
        for (const [name, view, sec] of [
            ['View 3 settings still to decide', 'history', 'decide'],
            ['View medicine rules, 2 active', 'rules', 'medicines'],
            ['View round templates, 3 active', 'rounds', 'templates'],
            ['View witness PIN status, 3 of 6 set', 'staff', 'status'],
        ]) {
            fireEvent.click(screen.getByRole('button', { name }));
            expect(go).toHaveBeenLastCalledWith(view, sec);
        }
    });

    it('shows only the meters whose view is open to this person', () => {
        // A house's PIN resetter reaches PINs and PIN status, nothing else.
        const resetter: Built = { staff: ['pins', 'status'] };
        expect(hasSettingsMeters(resetter)).toBe(true);
        meters(resetter);
        expect(screen.getAllByRole('button')).toHaveLength(1);
        expect(
            screen.getByRole('button', {
                name: 'View witness PIN status, 3 of 6 set',
            }),
        ).toBeInTheDocument();
        // A template-only manager keeps the round templates meter; someone
        // with no Settings view has none. No on-call meter without the
        // Alerts & access view.
        expect(hasSettingsMeters({ rounds: ['templates'] })).toBe(true);
        expect(hasSettingsMeters({})).toBe(false);
        expect(screen.queryByText(/on-call/i)).toBeNull();
    });

    it('counts houses with an on-call contact as a donut, linked to On-call contacts (B2 C4)', () => {
        const go = vi.fn();
        render(
            <SettingsMeters
                built={{ alerts: ['overview', 'oncall'] }}
                view="alerts"
                sec="overview"
                go={go}
                pending={[]}
                rules={[]}
                templates={[]}
                pins={[]}
                oncall={[
                    { configured: true },
                    { configured: false },
                    { configured: false },
                ]}
            />,
        );
        const meter = screen.getByRole('button', {
            name: 'View on-call contacts, 1 of 3 set',
        });
        expect(meter).toHaveTextContent('1 of 3 set');
        expect(meter).toHaveTextContent('2 not configured');
        meter.click();
        expect(go).toHaveBeenCalledWith('alerts', 'oncall');
    });
});
