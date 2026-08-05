import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { JourneyReferenceStrip } from './journey-reference-strip';
import { taskStateLabel, type TaskItem } from './types';

describe('universal tasks incident journey', () => {
    it('shows only truthful official references with their owning modules', () => {
        render(
            <JourneyReferenceStrip
                journey={{
                    key: 'incident-41',
                    source: 'control_room',
                    occurred_at: '2026-07-15T08:00:00+12:00',
                    references: {
                        control_room: 'CRA-2026-0041',
                        incident: 'INC-2026-0041',
                        health_safety: 'HSE-2026-0041',
                    },
                    person: { id: 7, name: 'Aroha Rangi' },
                    site: { id: 9, name: 'North House' },
                }}
            />,
        );

        const strip = screen.getByLabelText('Journey references');
        expect(strip).toHaveTextContent('Control RoomCRA-2026-0041');
        expect(strip).toHaveTextContent('IncidentINC-2026-0041');
        expect(strip).toHaveTextContent('H&SHSE-2026-0041');
        expect(strip).not.toHaveTextContent('incident-41');
    });

    it('does not invent a journey when no official references exist', () => {
        const { container } = render(<JourneyReferenceStrip journey={null} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('uses the truthful display state before the raw module status', () => {
        const item = {
            status: 'completed',
            displayState: 'Awaiting independent verification',
        } as TaskItem;

        expect(taskStateLabel(item)).toBe('Awaiting independent verification');
        expect(taskStateLabel({ ...item, displayState: null })).toBe(
            'Completed',
        );
    });
});
