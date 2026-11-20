<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Illuminate\Contracts\Auth\Authenticatable;

final class AuthUser implements Authenticatable
{
    /**
     * @param  list<string>  $abilities
     */
    public function __construct(
        public string $id = '1',
        public array $abilities = ['echo'],
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        unset($value);
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }

    public function tokenCan(string $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }
}
