<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

class ActiveUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier)
    {
        $user = parent::retrieveById($identifier);

        return $this->isActive($user) ? $user : null;
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token)
    {
        $user = parent::retrieveByToken($identifier, $token);

        return $this->isActive($user) ? $user : null;
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials)
    {
        $user = parent::retrieveByCredentials($credentials);

        return $this->isActive($user) ? $user : null;
    }

    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials)
    {
        return $this->isActive($user) && parent::validateCredentials($user, $credentials);
    }

    private function isActive(?Authenticatable $user): bool
    {
        return $user !== null && $user->status === 'active';
    }
}
