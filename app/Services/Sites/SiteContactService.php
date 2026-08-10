<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteContact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SiteContactService
{
    /**
     * Replace the Site's contact list from the full Site editor.
     *
     * @param  array<int, array<string, mixed>>  $contacts
     */
    public function sync(Site $site, array $contacts): void
    {
        DB::transaction(function () use ($site, $contacts): void {
            $this->lockSite($site);

            $existing = SiteContact::query()
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (SiteContact $contact): int => (int) $contact->id);

            $normalized = $this->normalizeBatch($contacts, 'contacts');
            $this->assertBatchIntegrity($normalized, $existing, 'contacts');

            if ($normalized->contains(fn (array $contact): bool => $contact['is_primary'])) {
                SiteContact::query()
                    ->where('site_id', $site->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $keepIds = [];
            foreach ($normalized as $contact) {
                $id = $contact['id'];
                $payload = $this->payload($contact);

                if ($id !== null) {
                    /** @var SiteContact $current */
                    $current = $existing->get($id);
                    $current->update($payload);
                    $keepIds[] = (int) $current->id;

                    continue;
                }

                $created = SiteContact::query()->create([
                    'site_id' => $site->id,
                    ...$payload,
                ]);
                $keepIds[] = (int) $created->id;
            }

            $delete = SiteContact::query()->where('site_id', $site->id);
            $keepIds === []
                ? $delete->delete()
                : $delete->whereNotIn('id', $keepIds)->delete();
        });
    }

    /**
     * Add or refresh contacts from the resumable Site onboarding flow without
     * deleting contacts already maintained in Site Profile.
     *
     * @param  array<int, array<string, mixed>>  $contacts
     */
    public function upsertBatch(Site $site, array $contacts): void
    {
        DB::transaction(function () use ($site, $contacts): void {
            $this->lockSite($site);

            $existing = SiteContact::query()
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->get();
            $normalized = $this->normalizeBatch($contacts, 'data.contacts');
            $this->assertBatchIntegrity($normalized, collect(), 'data.contacts', false);

            if ($normalized->contains(fn (array $contact): bool => $contact['is_primary'])) {
                SiteContact::query()
                    ->where('site_id', $site->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            foreach ($normalized as $contact) {
                $match = $existing->first(fn (SiteContact $current): bool => $this->identity((string) $current->type, (string) $current->name)
                    === $this->identity($contact['type'], $contact['name'])
                );

                if ($match) {
                    $match->update($this->payload($contact));

                    continue;
                }

                SiteContact::query()->create([
                    'site_id' => $site->id,
                    ...$this->payload($contact),
                ]);
            }
        });
    }

    /** @param array<string, mixed> $attributes */
    public function create(Site $site, array $attributes): SiteContact
    {
        return DB::transaction(function () use ($site, $attributes): SiteContact {
            $this->lockSite($site);
            $contact = $this->normalize($attributes, 'contact');
            $this->assertUniqueIdentity($site, $contact);

            if ($contact['is_primary']) {
                SiteContact::query()
                    ->where('site_id', $site->id)
                    ->where('is_primary', true)
                    ->lockForUpdate()
                    ->update(['is_primary' => false]);
            }

            return SiteContact::query()->create([
                'site_id' => $site->id,
                ...$this->payload($contact),
            ]);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Site $site, int $contactId, array $attributes): SiteContact
    {
        return DB::transaction(function () use ($site, $contactId, $attributes): SiteContact {
            $this->lockSite($site);
            $current = SiteContact::query()
                ->where('site_id', $site->id)
                ->whereKey($contactId)
                ->lockForUpdate()
                ->firstOrFail();
            $contact = $this->normalize($attributes, 'contact');
            $this->assertUniqueIdentity($site, $contact, (int) $current->id);

            if ($contact['is_primary']) {
                SiteContact::query()
                    ->where('site_id', $site->id)
                    ->whereKeyNot($current->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $current->update($this->payload($contact));

            return $current->refresh();
        });
    }

    public function delete(Site $site, int $contactId): SiteContact
    {
        return DB::transaction(function () use ($site, $contactId): SiteContact {
            $this->lockSite($site);
            $contact = SiteContact::query()
                ->where('site_id', $site->id)
                ->whereKey($contactId)
                ->lockForUpdate()
                ->firstOrFail();
            $contact->delete();

            return $contact;
        });
    }

    private function lockSite(Site $site): void
    {
        Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<int, array<string, mixed>>  $contacts
     * @return Collection<int, array{id: int|null, type: string, name: string, role: string|null, phone: string|null, email: string|null, is_primary: bool, notes: string|null}>
     */
    private function normalizeBatch(array $contacts, string $path): Collection
    {
        return collect($contacts)
            ->values()
            ->map(fn (array $contact, int $index): array => $this->normalize($contact, "{$path}.{$index}"));
    }

    /**
     * @param  array<string, mixed>  $contact
     * @return array{id: int|null, type: string, name: string, role: string|null, phone: string|null, email: string|null, is_primary: bool, notes: string|null}
     */
    private function normalize(array $contact, string $path): array
    {
        $type = Str::snake(trim((string) ($contact['type'] ?? 'other')));
        $type = $type !== '' ? $type : 'other';
        if (! in_array($type, SiteContact::TYPES, true)) {
            throw ValidationException::withMessages([
                "{$path}.type" => 'Choose a recognised Site contact type.',
            ]);
        }

        $name = trim((string) ($contact['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages([
                "{$path}.name" => 'Enter the contact name.',
            ]);
        }

        return [
            'id' => filled($contact['id'] ?? null) ? (int) $contact['id'] : null,
            'type' => $type,
            'name' => $name,
            'role' => $this->nullableTrimmed($contact['role'] ?? null),
            'phone' => $this->nullableTrimmed($contact['phone'] ?? null),
            'email' => $this->nullableTrimmed($contact['email'] ?? null),
            'is_primary' => (bool) ($contact['is_primary'] ?? false),
            'notes' => $this->nullableTrimmed($contact['notes'] ?? null),
        ];
    }

    /**
     * @param  Collection<int, array{id: int|null, type: string, name: string, role: string|null, phone: string|null, email: string|null, is_primary: bool, notes: string|null}>  $contacts
     * @param  Collection<int, SiteContact>  $existing
     */
    private function assertBatchIntegrity(
        Collection $contacts,
        Collection $existing,
        string $path,
        bool $validateIds = true,
    ): void {
        if ($contacts->where('is_primary', true)->count() > 1) {
            throw ValidationException::withMessages([
                $path => 'Choose only one primary Site contact.',
            ]);
        }

        $identities = [];
        $ids = [];
        foreach ($contacts as $index => $contact) {
            $identity = $this->identity($contact['type'], $contact['name']);
            if (isset($identities[$identity])) {
                throw ValidationException::withMessages([
                    "{$path}.{$index}.name" => 'This contact type and name is already in the Site contact list.',
                ]);
            }
            $identities[$identity] = true;

            if ($contact['id'] === null) {
                continue;
            }
            if (isset($ids[$contact['id']])) {
                throw ValidationException::withMessages([
                    "{$path}.{$index}.id" => 'The same Site contact cannot be submitted twice.',
                ]);
            }
            $ids[$contact['id']] = true;

            if ($validateIds && ! $existing->has($contact['id'])) {
                throw ValidationException::withMessages([
                    "{$path}.{$index}.id" => 'The selected contact does not belong to this Site.',
                ]);
            }
        }
    }

    /** @param array{id: int|null, type: string, name: string, role: string|null, phone: string|null, email: string|null, is_primary: bool, notes: string|null} $contact */
    private function assertUniqueIdentity(Site $site, array $contact, ?int $exceptId = null): void
    {
        $duplicate = SiteContact::query()
            ->where('site_id', $site->id)
            ->where('type', $contact['type'])
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($contact['name'])])
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->lockForUpdate()
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => 'This contact type and name is already in the Site contact list.',
            ]);
        }
    }

    /**
     * @param  array{id: int|null, type: string, name: string, role: string|null, phone: string|null, email: string|null, is_primary: bool, notes: string|null}  $contact
     * @return array{type: string, name: string, role: string|null, phone: string|null, email: string|null, is_primary: bool, notes: string|null}
     */
    private function payload(array $contact): array
    {
        return [
            'type' => $contact['type'],
            'name' => $contact['name'],
            'role' => $contact['role'],
            'phone' => $contact['phone'],
            'email' => $contact['email'],
            'is_primary' => $contact['is_primary'],
            'notes' => $contact['notes'],
        ];
    }

    private function identity(string $type, string $name): string
    {
        return mb_strtolower(Str::snake(trim($type)).'|'.trim($name));
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
