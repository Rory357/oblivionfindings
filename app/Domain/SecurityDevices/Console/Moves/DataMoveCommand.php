<?php

namespace App\Domain\SecurityDevices\Console\Moves;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * S&D rebuild, "Moving today's data" (plan §Moving today's data, GOAL.md §1.5): the shared rules
 * for every move command.
 *
 * - A dry run by default. It prints counts and a short list, and changes nothing.
 * - `--apply` makes only the suggested changes, each one idempotent, inside a transaction and audited
 *   against the `--actor` who runs it.
 * - `--apply` refuses to run in production without `--force` (Laravel's usual pattern).
 * - Nothing here is scheduled or run by a migration. MASTER runs each move on the .com with
 *   Stephan's OK and a full backup first.
 * - Nothing is ever sent to a device or to UniFi.
 */
abstract class DataMoveCommand extends Command
{
    /** Most rows listed in one section of a dry run; the rest are counted. */
    protected const int LIST_LIMIT = 200;

    /**
     * A device's name for the output, or only its ID when it is (or ever was) with a resident or is a
     * care tracker: a resident's name is often in such a device's name ("Mere's pendant").
     */
    protected static function deviceLabel(Device $device): string
    {
        $withResident = DeviceKind::of($device) === DeviceKind::CareTracker
            || DeviceAssignment::query()->where('device_id', $device->id)->where('assignable_type', DeviceAssignment::TARGET_CLIENT)->exists();

        return $withResident ? 'Device #'.$device->id.' (with a resident, not named)' : (string) $device->name;
    }

    protected function applying(): bool
    {
        return (bool) $this->option('apply');
    }

    /**
     * The checks every `--apply` passes before it changes anything. Returns the actor the changes are
     * recorded against, or null (with the reason printed) when it may not run.
     */
    protected function actorForApply(): ?User
    {
        if ($this->laravel->environment('production') && ! $this->option('force')) {
            $this->components->error('This would change data on a live server. Take a full backup, get MASTER’s OK, then run it again with --force.');

            return null;
        }

        $actorId = $this->option('actor');
        if (! is_scalar($actorId) || preg_match('/^[1-9]\d*$/', (string) $actorId) !== 1) {
            $this->components->error('Say who is running this move with --actor=<user id>. Every change is recorded against that person.');

            return null;
        }

        $actor = User::query()->whereKey((int) $actorId)->whereNotNull('approved_at')->first();
        if (! $actor instanceof User) {
            $this->components->error('There is no approved user with that id.');

            return null;
        }

        // Model-level audit rows written by the code this move reuses are recorded against the same person.
        Auth::setUser($actor);

        return $actor;
    }

    protected function dryRunFooter(int $wouldChange, string $what): void
    {
        $this->newLine();
        if ($wouldChange === 0) {
            $this->components->info('Dry run: nothing changed, and there is nothing for --apply to do.');

            return;
        }
        $this->components->warn("Dry run: nothing changed. Run again with --apply --actor=<user id> to {$what}.");
    }

    /**
     * A section of the dry run: a heading, then at most LIST_LIMIT rows.
     *
     * @param  list<string>  $headers
     * @param  list<list<string|int|null>>  $rows
     */
    protected function section(string $title, array $headers, array $rows): void
    {
        $this->newLine();
        $this->line('<options=bold>'.$title.' ('.count($rows).')</>');
        if ($rows === []) {
            $this->line('  None.');

            return;
        }
        $this->table($headers, array_slice($rows, 0, self::LIST_LIMIT));
        if (count($rows) > self::LIST_LIMIT) {
            $this->line('  …and '.(count($rows) - self::LIST_LIMIT).' more.');
        }
    }
}
