<?php

namespace App\Services\Medication\Settings;

/**
 * A setting whose value is more than one option or one number (eMAR P11 B2:
 * who gets an alert, a house's extra people). The value is still one string
 * — canonical JSON — so drafts, "Review changes", the change history, "Keep
 * today's value" and "Put the earlier value back" all work unchanged; the
 * codec says which strings are valid, the words for them, and which changes
 * loosen a check.
 */
interface MedicationSettingCodec
{
    /** The kind the page reads to show and edit the value ("alert", "people"). */
    public function kind(): string;

    public function accepts(string $value): bool;

    /** A stored value as its canonical string, or the default when it can't be read. */
    public function normalise(mixed $value, string $default): string;

    public function format(string $value): string;

    public function loosens(string $from, string $to): bool;

    public function invalidMessage(string $label): string;

    /** @return array<string, mixed> Extra facts the page needs for this kind. */
    public function toClient(): array;
}
