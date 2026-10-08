import { expect, it, vi } from 'vitest';
import {
    medicationRecorded,
    onMedicationRecorded,
} from './medication-record-events';
it('refreshes only the recorded person and removes listeners when a chart closes', () => {
    const refresh = vi.fn();
    const other = vi.fn();
    const off = onMedicationRecorded(7, refresh);
    const offOther = onMedicationRecorded(8, other);
    medicationRecorded(7);
    expect(refresh).toHaveBeenCalledOnce();
    expect(other).not.toHaveBeenCalled();
    off();
    medicationRecorded(7);
    expect(refresh).toHaveBeenCalledOnce();
    offOther();
});
