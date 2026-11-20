<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Doubles\AuthUser;
use TiborSrc\A2A\Laravel\A2AManager;
use TiborSrc\A2A\Laravel\Auth\SecuritySchemeMapper;
use TiborSrc\A2A\Laravel\Http\AuthenticateA2A;

it('maps bearer security schemes and skill scopes', function (): void {
    $mapped = (new SecuritySchemeMapper)->bearer(['echo' => ['agents:echo']]);

    expect($mapped['schemes'])->toHaveKey('bearer')
        ->and($mapped['requirements'][0]->schemes['bearer'])->toContain('agents:echo');
});

it('includes security schemes on the card when auth is enabled', function (): void {
    config(['a2a.auth.enabled' => true, 'a2a.auth.abilities' => ['echo' => ['agents:echo']]]);
    $card = $this->app->make(A2AManager::class)->card();

    expect($card->securitySchemes)->toHaveKey('bearer');
});

it('allows requests when a2a auth is disabled', function (): void {
    $middleware = $this->app->make(AuthenticateA2A::class);
    $response = $middleware->handle(Request::create('/', 'POST'), fn (): Response => new Response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('rejects unauthenticated requests when auth is enabled', function (): void {
    config(['a2a.auth.enabled' => true, 'a2a.auth.guard' => 'web']);
    $middleware = $this->app->make(AuthenticateA2A::class);

    expect(fn () => $middleware->handle(Request::create('/', 'POST'), fn (): Response => new Response('ok')))
        ->toThrow(HttpException::class);
});

it('enforces per-skill abilities', function (): void {
    config([
        'a2a.auth.enabled' => true,
        'a2a.auth.guard' => 'web',
        'a2a.auth.abilities' => ['echo' => ['agents:echo']],
    ]);
    $this->be(new AuthUser(abilities: ['other']), 'web');
    $middleware = $this->app->make(AuthenticateA2A::class);
    $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
        'params' => ['metadata' => ['skillId' => 'echo']],
    ], JSON_THROW_ON_ERROR));

    expect(fn () => $middleware->handle($request, fn (): Response => new Response('ok')))
        ->toThrow(HttpException::class);
});

it('skips non-string abilities and users without tokenCan', function (): void {
    config([
        'a2a.auth.enabled' => true,
        'a2a.auth.guard' => 'web',
        'a2a.auth.abilities' => ['echo' => [1, 'agents:echo']],
    ]);
    $this->be(new class implements Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): mixed
        {
            return 1;
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
    }, 'web');
    $middleware = $this->app->make(AuthenticateA2A::class);
    $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
        'params' => ['metadata' => ['skillId' => 'echo']],
    ], JSON_THROW_ON_ERROR));

    expect(fn () => $middleware->handle($request, fn (): Response => new Response('ok')))
        ->toThrow(HttpException::class);
});

it('allows a matching skill ability', function (): void {
    config([
        'a2a.auth.enabled' => true,
        'a2a.auth.guard' => 'web',
        'a2a.auth.abilities' => ['echo' => ['agents:echo']],
    ]);
    $this->be(new AuthUser(abilities: ['agents:echo']), 'web');
    $middleware = $this->app->make(AuthenticateA2A::class);
    $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
        'metadata' => ['skillId' => 'echo'],
    ], JSON_THROW_ON_ERROR));
    $response = $middleware->handle($request, fn (): Response => new Response('ok'));

    expect($response->getContent())->toBe('ok');
});
