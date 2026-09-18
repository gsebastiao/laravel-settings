<?php

namespace Gsebastiao\LaravelSettings\Tests\Fixtures;

use Illuminate\Support\Collection;

/** Como um utilizador com o trait HasRoles do spatie/laravel-permission. */
class UserWithRoles extends User
{
    /** @var array<int, string> */
    public array $roleNames = [];

    public function getRoleNames(): Collection
    {
        return collect($this->roleNames);
    }
}
