<?php

namespace App\Policies;

use App\Models\Filter;
use App\Models\User;

class FilterPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Filter $filter): bool
    {
        return $user->id === $filter->user_id;
    }

    public function update(User $user, Filter $filter): bool
    {
        return $user->id === $filter->user_id;
    }

    public function delete(User $user, Filter $filter): bool
    {
        return $user->id === $filter->user_id;
    }

    public function publish(User $user, Filter $filter): bool
    {
        return $user->id === $filter->user_id;
    }
}
