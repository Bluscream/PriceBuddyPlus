<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SearchWatch;
use App\Models\User;

class SearchWatchPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SearchWatch $watch): bool
    {
        return $this->owns($user, $watch);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SearchWatch $watch): bool
    {
        return $this->owns($user, $watch);
    }

    public function delete(User $user, SearchWatch $watch): bool
    {
        return $this->owns($user, $watch);
    }

    public function restore(User $user, SearchWatch $watch): bool
    {
        return $this->owns($user, $watch);
    }

    public function forceDelete(User $user, SearchWatch $watch): bool
    {
        return $this->owns($user, $watch);
    }

    private function owns(User $user, SearchWatch $watch): bool
    {
        return $watch->user_id === $user->getKey();
    }
}
