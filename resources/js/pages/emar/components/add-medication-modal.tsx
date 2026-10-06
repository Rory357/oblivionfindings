import { LegacyOrderLink } from '@/pages/emar/orders/_legacy-link';

export function AddMedicationModal({
    open,
    onClose,
    clients,
}: {
    open: boolean;
    onClose: () => void;
    clients: {
        id: number;
        name?: string;
        first_name?: string;
        last_name?: string;
    }[];
}) {
    return <LegacyOrderLink open={open} onClose={onClose} clients={clients} />;
}

export default AddMedicationModal;
