<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Server\Models;

use Illuminate\Database\Eloquent\Model;

final class A2APushConfig extends Model
{
    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $table = 'a2a_push_notification_configs';

    #[\Override]
    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    #[\Override]
    protected $fillable = [
        'id',
        'task_id',
        'payload',
    ];

    /**
     * @return array<string, mixed>
     */
    public function payloadArray(): array
    {
        $payload = $this->getAttribute('payload');

        return is_array($payload) ? $this->stringKeyed($payload) : [];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, mixed>
     */
    private function stringKeyed(array $value): array
    {
        $out = [];

        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }
}
