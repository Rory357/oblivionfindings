// Rate and bandwidth limits (spec section 7). Every limit reads the injected clock, so tests can
// move time on without waiting.

/** A token bucket: `rate` units a second, holding at most `capacity`. */
export class TokenBucket {
    constructor(rate, capacity, nowMs) {
        this.rate = rate;
        this.capacity = capacity;
        this.tokens = capacity;
        this.last = nowMs;
    }

    refill(nowMs) {
        if (nowMs > this.last) {
            this.tokens = Math.min(
                this.capacity,
                this.tokens + ((nowMs - this.last) / 1000) * this.rate,
            );
            this.last = nowMs;
        }
    }

    has(amount, nowMs) {
        this.refill(nowMs);
        return this.tokens >= amount;
    }

    take(amount) {
        this.tokens -= amount;
    }

    tryTake(amount, nowMs) {
        if (!this.has(amount, nowMs)) return false;
        this.take(amount);
        return true;
    }
}

/** A bandwidth bucket in kbit/s that holds half a second of traffic (spec 7, "Why these numbers"). */
export function bandwidthBucket(kbps, nowMs) {
    const bytesPerSecond = (kbps * 1000) / 8;
    return new TokenBucket(bytesPerSecond, bytesPerSecond / 2, nowMs);
}

/** Takes from every bucket only when all of them allow it. */
export function takeAll(buckets, amount, nowMs) {
    for (const bucket of buckets) if (!bucket.has(amount, nowMs)) return false;
    for (const bucket of buckets) bucket.take(amount);
    return true;
}

/** One bucket per key (a client IP), forgotten once full again and idle. */
export class KeyedBuckets {
    constructor(rate, capacity) {
        this.rate = rate;
        this.capacity = capacity;
        this.buckets = new Map();
    }

    tryTake(key, nowMs) {
        let bucket = this.buckets.get(key);
        if (!bucket) {
            bucket = new TokenBucket(this.rate, this.capacity, nowMs);
            this.buckets.set(key, bucket);
        }
        return bucket.tryTake(1, nowMs);
    }

    prune(nowMs) {
        for (const [key, bucket] of this.buckets) {
            bucket.refill(nowMs);
            if (bucket.tokens >= bucket.capacity) this.buckets.delete(key);
        }
    }
}

/**
 * Counts failures per key in a one-minute window. Reaching `limit` silences the key for
 * `silenceMs`. Used for TURN authentication failures and the control channel.
 */
export class FailureLimiter {
    constructor(limit, { windowMs = 60_000, silenceMs = 60_000 } = {}) {
        this.limit = limit;
        this.windowMs = windowMs;
        this.silenceMs = silenceMs;
        this.entries = new Map();
    }

    silenced(key, nowMs) {
        const entry = this.entries.get(key);
        return !!entry && entry.silencedUntil > nowMs;
    }

    /** Records one failure. Returns true when this failure silenced the key. */
    fail(key, nowMs) {
        let entry = this.entries.get(key);
        if (!entry || nowMs - entry.windowStart >= this.windowMs) {
            entry = {
                windowStart: nowMs,
                count: 0,
                silencedUntil: entry?.silencedUntil ?? 0,
            };
            this.entries.set(key, entry);
        }
        entry.count++;
        if (entry.count >= this.limit && entry.silencedUntil <= nowMs) {
            entry.silencedUntil = nowMs + this.silenceMs;
            entry.count = 0;
            entry.windowStart = nowMs;
            return true;
        }
        return false;
    }

    prune(nowMs) {
        for (const [key, entry] of this.entries) {
            if (
                entry.silencedUntil <= nowMs &&
                nowMs - entry.windowStart >= this.windowMs
            )
                this.entries.delete(key);
        }
    }
}

/** Lets one event per key through each window: keeps security log lines from flooding the disk. */
export class OncePer {
    constructor(windowMs) {
        this.windowMs = windowMs;
        this.seen = new Map();
    }

    allow(key, nowMs) {
        const last = this.seen.get(key);
        if (last !== undefined && nowMs - last < this.windowMs) return false;
        this.seen.set(key, nowMs);
        return true;
    }

    prune(nowMs) {
        for (const [key, last] of this.seen)
            if (nowMs - last >= this.windowMs) this.seen.delete(key);
    }
}
