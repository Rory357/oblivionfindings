<?php

namespace App\Policies;

use App\Models\ClientNote;
use App\Models\User;

class ClientNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('progress_notes.viewAny');
    }

    public function view(User $user, ClientNote $note): bool
    {
        return $user->canDo('progress_notes.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->canDo('progress_notes.create');
    }

    public function update(User $user, ClientNote $note): bool
    {
        return $user->canDo('progress_notes.update');
    }

    public function delete(User $user, ClientNote $note): bool
    {
        return $user->canDo('progress_notes.update');
    }
}
