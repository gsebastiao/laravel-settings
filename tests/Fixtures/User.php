<?php

namespace Gsebastiao\LaravelSettings\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** Utilizador de teste — nunca é gravado na base de dados. */
class User extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    public static function withId(int|string $id): static
    {
        $user = new static();

        if (is_string($id)) {
            $user->setKeyType('string')->setIncrementing(false);
        }

        $user->setAttribute('id', $id);
        $user->exists = true;

        return $user;
    }
}
