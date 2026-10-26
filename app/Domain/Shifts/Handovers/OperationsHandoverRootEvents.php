<?php

declare(strict_types=1);

namespace App\Domain\Shifts\Handovers;

use Illuminate\Contracts\Events\Dispatcher;

/** @internal Scoped interception; every ordinary listener remains delegated. */
final class OperationsHandoverRootEvents implements Dispatcher
{
    public function __construct(private readonly Dispatcher $original, private readonly \Closure $beforeDispatch, private readonly ?\Closure $afterDispatch = null) {}

    public function dispatch($event, $payload = [], $halt = false)
    {
        ($this->beforeDispatch)($event);
        try { return $this->original->dispatch($event, $payload, $halt); }
        finally { if ($this->afterDispatch !== null) { ($this->afterDispatch)($event); } }
    }

    public function until($event, $payload = [])
    {
        return $this->dispatch($event, $payload, true);
    }

    public function listen($events, $listener = null) { return $this->original->listen($events, $listener); }
    public function hasListeners($eventName) { return $this->original->hasListeners($eventName); }
    public function subscribe($subscriber) { return $this->original->subscribe($subscriber); }
    public function push($event, $payload = []) { return $this->original->push($event, $payload); }
    public function flush($event) { return $this->original->flush($event); }
    public function forget($event) { return $this->original->forget($event); }
    public function forgetPushed() { return $this->original->forgetPushed(); }
}
