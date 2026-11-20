<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Http;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TiborSrc\A2A\Laravel\Support\Values;

final readonly class AuthenticateA2A
{
    public function __construct(
        private AuthFactory $auth,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('a2a.auth.enabled') !== true) {
            return $next($request);
        }

        $guard = Values::string(config('a2a.auth.guard', 'sanctum'), 'sanctum');
        $user = $this->auth->guard($guard)->user();

        if ($user === null) {
            abort(401, 'A2A authentication required.');
        }

        $skill = $this->skillId($request);
        $abilities = config('a2a.auth.abilities', []);
        $required = is_array($abilities) && is_string($skill) ? ($abilities[$skill] ?? []) : [];

        if (! is_array($required)) {
            $required = [];
        }

        foreach ($required as $ability) {
            if (! is_string($ability)) {
                continue;
            }

            if (! method_exists($user, 'tokenCan') || $user->tokenCan($ability) !== true) {
                abort(403, 'A2A skill ability denied.');
            }
        }

        return $next($request);
    }

    private function skillId(Request $request): ?string
    {
        $content = $request->getContent();

        if ($content === '') {
            $skill = $request->query('skillId');

            return is_string($skill) ? $skill : null;
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $params = is_array($decoded['params'] ?? null) ? $decoded['params'] : $decoded;
        $metadata = is_array($params['metadata'] ?? null) ? $params['metadata'] : [];
        $skill = $metadata['skillId'] ?? $params['skillId'] ?? null;

        return is_string($skill) ? $skill : null;
    }
}
