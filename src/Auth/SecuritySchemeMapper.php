<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Auth;

use TiborSrc\A2A\Types\HTTPAuthSecurityScheme;
use TiborSrc\A2A\Types\SecurityRequirement;
use TiborSrc\A2A\Types\SecurityScheme;

final class SecuritySchemeMapper
{
    /**
     * @param  array<string, list<string>>  $skillAbilities
     * @return array{schemes: array<string, SecurityScheme>, requirements: list<SecurityRequirement>}
     */
    public function bearer(array $skillAbilities = []): array
    {
        $schemes = [
            'bearer' => new SecurityScheme(
                httpAuthSecurityScheme: new HTTPAuthSecurityScheme('bearer', 'Laravel Sanctum or Passport bearer token.', 'sanctum'),
            ),
        ];

        $scopes = [];

        foreach ($skillAbilities as $abilities) {
            foreach ($abilities as $ability) {
                $scopes[] = $ability;
            }
        }

        $requirements = [
            new SecurityRequirement(['bearer' => array_values(array_unique($scopes))]),
        ];

        return [
            'schemes' => $schemes,
            'requirements' => $requirements,
        ];
    }
}
