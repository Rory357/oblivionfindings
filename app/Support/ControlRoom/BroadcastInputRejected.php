<?php

namespace App\Support\ControlRoom;

/** Pure parsing rejection only; never emitted by persistence or delivery owners. */
final class BroadcastInputRejected extends \InvalidArgumentException {}
