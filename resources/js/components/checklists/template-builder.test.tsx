import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    ChecklistConfigProvider,
    type ChecklistConfig,
} from '@/components/checklists/context';
import { TemplateBuilderModal } from '@/components/checklists/template-builder';

const mocks = vi.hoisted(() => ({
    reload: vi.fn(),
    props: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', async () => ({
    ...(await vi.importActual('@inertiajs/react')),
    router: { reload: mocks.reload, delete: vi.fn() },
    usePage: () => ({ props: mocks.props }),
}));

const config: ChecklistConfig = {
    categories: [],
    categoryMap: {},
    freqLabels: { weekly: 'Weekly' },
    typeLabels: {},
    today: '2026-11-07',
    can: { view: true, manageTemplates: true, schedule: true, run: true },
    scope: { mode: 'org' },
    assignableUsers: [],
    openRun: vi.fn(),
    openBuilder: vi.fn(),
};

function renderBuilder(target: 'new' | number) {
    return render(
        <ChecklistConfigProvider value={config}>
            <TemplateBuilderModal target={target} onClose={vi.fn()} />
        </ChecklistConfigProvider>,
    );
}

/** Radix's missing-title (console.error) and missing-description (console.warn) warnings. */
function radixDialogWarnings(): string[] {
    return [
        ...vi.mocked(console.error).mock.calls,
        ...vi.mocked(console.warn).mock.calls,
    ]
        .map((call) => call.map(String).join(' '))
        .filter((message) => /DialogTitle|Missing `Description`/.test(message));
}

describe('TemplateBuilderModal', () => {
    beforeEach(() => {
        mocks.reload.mockReset();
        mocks.props = {};
        vi.spyOn(console, 'error').mockImplementation(() => {});
        vi.spyOn(console, 'warn').mockImplementation(() => {});
    });

    afterEach(() => {
        vi.mocked(console.error).mockRestore();
        vi.mocked(console.warn).mockRestore();
    });

    it('names the editor while an existing template is still loading', () => {
        renderBuilder(41);

        expect(
            screen.getByRole('dialog', { name: 'Edit checklist template' }),
        ).toHaveAccessibleDescription('Loading template…');
        expect(mocks.reload).toHaveBeenCalledWith(
            expect.objectContaining({ data: { template: 41 } }),
        );
        expect(radixDialogWarnings()).toEqual([]);
    });

    it('names the editor for a new template', () => {
        renderBuilder('new');

        expect(
            screen.getByRole('dialog', { name: 'New checklist template' }),
        ).toBeInTheDocument();
        expect(radixDialogWarnings()).toEqual([]);
    });
});
