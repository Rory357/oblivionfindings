<?php

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\FinFixedAsset;
use App\Models\User;

class FinFixedAssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('finance.assets.view');
    }

    public function view(User $user, FinFixedAsset $fixedAsset): bool
    {
        return $user->canDo('finance.assets.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('finance.assets.manage');
    }

    public function update(User $user, FinFixedAsset $fixedAsset): bool
    {
        return $user->canDo('finance.assets.manage');
    }

    public function dispose(User $user, FinFixedAsset $fixedAsset): bool
    {
        return $user->canDo('finance.assets.manage');
    }
}
