import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { SafetyHandoverLenses } from './safety-handover-lenses';

describe('SafetyHandoverLenses', () => {
    it('renders subordinate pressed-state filters in a narrow-width scroll strip', () => {
        const onSelect = vi.fn();
        render(
            <SafetyHandoverLenses
                lenses={[
                    {
                        key: 'attention',
                        label: 'Needs attention',
                        help: 'Requires an operational or governance action.',
                        count: 12,
                    },
                    {
                        key: 'complete',
                        label: 'Complete',
                        help: 'Both ownership paths are complete.',
                        count: 44,
                    },
                ]}
                activeLens="attention"
                onSelect={onSelect}
            />,
        );

        expect(screen.getByTestId('safety-handover-lenses')).toHaveClass(
            'overflow-x-auto',
        );
        expect(
            screen.getByRole('button', { name: /Needs attention.*12/ }),
        ).toHaveAttribute('aria-pressed', 'true');
        expect(
            screen.getByRole('button', { name: /Complete.*44/ }),
        ).toHaveAttribute('title', 'Both ownership paths are complete.');

        fireEvent.click(screen.getByRole('button', { name: /Complete.*44/ }));
        expect(onSelect).toHaveBeenCalledWith('complete');
    });
});
