import { Button } from '@/components/ui/button';
import { medicationReturnTo } from '@/lib/medication-navigation';
import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';

export function MedicationJourneyReturn() {
    const { url } = usePage();
    const href = medicationReturnTo(
        new URLSearchParams((url ?? '').split('?')[1]?.split('#')[0]).get(
            'return_to',
        ),
    );
    return href ? (
        <Button asChild size="sm" variant="outline">
            <Link href={href}>
                <ArrowLeft className="size-4" /> Return to previous task
            </Link>
        </Button>
    ) : null;
}
