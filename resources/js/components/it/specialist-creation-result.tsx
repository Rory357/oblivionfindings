import { Button } from '@/components/ui/button';
import { WizardSuccessPane } from '@/components/wizard/shell';
import { Link } from '@inertiajs/react';

type Workspace = 'problems' | 'changes' | 'major-incidents';
export type SpecialistCreation =
    | { id: number; reference: string; workspace: Workspace }
    | 'unconfirmed';

export function specialistCreationFrom(
    props: Record<string, unknown>,
    workspace: Workspace,
    actorId: number,
): SpecialistCreation {
    const flash = props.flash as
        | {
              it_ticket?: {
                  specialist_id?: unknown;
                  reference?: unknown;
                  workspace?: unknown;
                  actor_user_id?: unknown;
              };
          }
        | undefined;
    const receipt = flash?.it_ticket;
    if (
        receipt?.workspace !== workspace ||
        receipt.actor_user_id !== actorId ||
        typeof receipt.specialist_id !== 'number' ||
        !Number.isSafeInteger(receipt.specialist_id) ||
        receipt.specialist_id < 1 ||
        typeof receipt.reference !== 'string' ||
        receipt.reference.length === 0
    )
        return 'unconfirmed';
    return {
        id: receipt.specialist_id,
        reference: receipt.reference,
        workspace,
    };
}

export function SpecialistCreationResult({
    result,
    onClose,
    onAnother,
}: {
    result: SpecialistCreation;
    onClose: () => void;
    onAnother: () => void;
}) {
    if (result === 'unconfirmed')
        return (
            <section role="alert" className="space-y-4 p-6">
                <h2 className="text-lg font-semibold">
                    Check the register before trying again
                </h2>
                <p className="text-sm text-muted-foreground">
                    The response did not identify the created record. Return to
                    the register and check for your entry before submitting
                    another.
                </p>
                <Button onClick={onClose}>Return to register</Button>
            </section>
        );
    return (
        <WizardSuccessPane
            title={
                result.workspace === 'major-incidents'
                    ? 'Major incident declared'
                    : result.workspace === 'changes'
                      ? 'Change opened'
                      : 'Problem investigation opened'
            }
            blurb={`${result.reference} is saved. Open the record to continue the work.`}
            actions={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Done
                    </Button>
                    <Button variant="outline" onClick={onAnother}>
                        Add another
                    </Button>
                    <Button asChild>
                        <Link href={`/it/${result.workspace}/${result.id}`}>
                            Open record
                        </Link>
                    </Button>
                </>
            }
        />
    );
}
