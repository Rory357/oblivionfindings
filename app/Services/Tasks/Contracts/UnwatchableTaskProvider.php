<?php

namespace App\Services\Tasks\Contracts;

/**
 * A task source whose rows can be opened but never followed: no watcher row is created and the drawer offers no
 * "Follow". Used for private named work (a chemical exposure follow-up names a worker's exposure), where a follower
 * list would point at private health facts.
 */
interface UnwatchableTaskProvider {}
