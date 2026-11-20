<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Ai;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasSkills;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Skills\Skill;
use Stringable;
use TiborSrc\A2A\Laravel\Auth\SecuritySchemeMapper;
use TiborSrc\A2A\Laravel\Support\Values;
use TiborSrc\A2A\Protocol;
use TiborSrc\A2A\Types\AgentCapabilities;
use TiborSrc\A2A\Types\AgentCard;
use TiborSrc\A2A\Types\AgentInterface;
use TiborSrc\A2A\Types\AgentSkill;
use TiborSrc\A2A\Types\SecurityRequirement;
use TiborSrc\A2A\Types\SecurityScheme;

final readonly class AgentCardFactory
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    public function fromConfig(?string $baseUrl = null): AgentCard
    {
        $url = $this->url($baseUrl);
        $security = $this->security();

        return new AgentCard(
            name: Values::string($this->config->get('a2a.name', 'Laravel A2A Agent'), 'Laravel A2A Agent'),
            description: Values::string($this->config->get('a2a.description', 'A2A agent served from Laravel.'), 'A2A agent served from Laravel.'),
            supportedInterfaces: [
                new AgentInterface($url, Protocol::JSON_RPC_BINDING, Protocol::VERSION),
                new AgentInterface($url, Protocol::HTTP_JSON_BINDING, Protocol::VERSION),
            ],
            version: Values::string($this->config->get('a2a.version', '0.1.0'), '0.1.0'),
            capabilities: new AgentCapabilities(
                streaming: $this->config->get('a2a.capabilities.streaming') !== false,
                pushNotifications: $this->config->get('a2a.capabilities.push_notifications') !== false,
                extendedAgentCard: $this->config->get('a2a.capabilities.extended_agent_card') !== false,
            ),
            defaultInputModes: ['text/plain', 'application/json', 'image/png', 'image/jpeg', 'audio/mpeg'],
            defaultOutputModes: ['text/plain', 'application/json'],
            skills: [
                new AgentSkill('laravel', 'Laravel', 'Default Laravel A2A skill.', ['laravel', 'a2a']),
            ],
            securitySchemes: $security['schemes'],
            securityRequirements: $security['requirements'],
        );
    }

    public function fromAgent(Agent $agent, ?string $baseUrl = null): AgentCard
    {
        $card = $this->fromConfig($baseUrl);
        $skills = $this->skills($agent);

        return new AgentCard(
            name: class_basename($agent::class),
            description: $agent->instructions() instanceof Stringable ? (string) $agent->instructions() : $agent->instructions(),
            supportedInterfaces: $card->supportedInterfaces,
            version: $card->version,
            capabilities: $card->capabilities,
            defaultInputModes: $card->defaultInputModes,
            defaultOutputModes: $card->defaultOutputModes,
            skills: $skills === [] ? $card->skills : $skills,
            securitySchemes: $card->securitySchemes,
            securityRequirements: $card->securityRequirements,
        );
    }

    /**
     * @return list<AgentSkill>
     */
    private function skills(Agent $agent): array
    {
        $skills = [];

        if ($agent instanceof HasTools) {
            foreach ($agent->tools() as $tool) {
                if (! $tool instanceof Tool) {
                    continue;
                }

                $id = class_basename($tool::class);
                $raw = $tool->description();
                $skills[] = new AgentSkill(
                    $id,
                    $id,
                    $raw instanceof Stringable ? (string) $raw : $raw,
                    ['tool', 'laravel-ai'],
                );
            }
        }

        if ($agent instanceof HasSkills) {
            foreach ($agent->skills() as $skill) {
                if (! $skill instanceof Skill) {
                    continue;
                }

                $raw = $skill->description;
                $skills[] = new AgentSkill(
                    $skill->name,
                    $skill->name,
                    $raw instanceof Stringable ? (string) $raw : $raw,
                    ['skill', 'laravel-ai'],
                );
            }
        }

        return $skills;
    }

    /**
     * @return array{schemes: array<string, SecurityScheme>, requirements: list<SecurityRequirement>}
     */
    private function security(): array
    {
        if ($this->config->get('a2a.auth.enabled') !== true) {
            return ['schemes' => [], 'requirements' => []];
        }

        $abilities = $this->config->get('a2a.auth.abilities', []);
        $mapped = [];

        if (is_array($abilities)) {
            foreach ($abilities as $skill => $scopes) {
                if (! is_string($skill) || ! is_array($scopes)) {
                    continue;
                }

                $mapped[$skill] = array_values(array_filter($scopes, is_string(...)));
            }
        }

        return (new SecuritySchemeMapper)->bearer($mapped);
    }

    private function url(?string $baseUrl): string
    {
        if (is_string($baseUrl) && $baseUrl !== '') {
            return $baseUrl;
        }

        $configured = $this->config->get('a2a.base_url');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        $path = trim(Values::string($this->config->get('a2a.path', '')), '/');

        return $path === '' ? 'http://127.0.0.1:9999' : 'http://127.0.0.1:9999/'.$path;
    }
}
