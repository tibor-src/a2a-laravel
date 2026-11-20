<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TiborSrc\A2A\Exceptions\A2AException;
use TiborSrc\A2A\Laravel\A2AManager;
use TiborSrc\A2A\Laravel\Support\Values;
use TiborSrc\A2A\Protocol;
use TiborSrc\A2A\Types\StreamResponse;
use TiborSrc\A2A\Types\SubscribeToTaskRequest;
use TiborSrc\A2A\Types\Task;

final readonly class A2AController
{
    public function __construct(
        private A2AManager $a2a,
    ) {}

    public function handle(Request $request): SymfonyResponse
    {
        $psr = $this->toPsr($request);
        $prefix = $request->route()?->getPrefix();
        $psr = $this->stripPrefix($psr, is_string($prefix) && $prefix !== '' ? $prefix : Values::string(config('a2a.path'), ''));

        return $this->subscribe($psr) ?? $this->fromPsr($this->a2a->application()->handle($psr));
    }

    private function subscribe(ServerRequestInterface $request): ?StreamedResponse
    {
        $target = $this->subscribeTarget($request);

        if ($target === null) {
            return null;
        }

        [$taskId, $jsonRpc, $jsonRpcId] = $target;
        $handler = $this->a2a->handler();

        try {
            $version = $request->getHeaderLine(Protocol::VERSION_HEADER);
            $handler->assertVersion($version === '' ? null : $version);
            $extensionsHeader = $request->getHeaderLine(Protocol::EXTENSIONS_HEADER);
            $handler->assertExtensions($extensionsHeader === '' ? [] : array_map(trim(...), explode(',', $extensionsHeader)));
        } catch (A2AException) {
            return null;
        }

        $task = $this->a2a->store()->find($taskId);

        if (! $task instanceof Task || $task->status->state->isTerminal() || $handler->agentCard()->capabilities->streaming !== true) {
            return null;
        }

        $manager = $this->a2a;

        return new StreamedResponse(function () use ($manager, $taskId, $jsonRpc, $jsonRpcId): void {
            // @codeCoverageIgnoreStart
            if (PHP_SAPI === 'cli-server') {
                ignore_user_abort(true);

                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }

            // @codeCoverageIgnoreEnd

            $manager->handler()->subscribeToTask(
                new SubscribeToTaskRequest($taskId),
                function (StreamResponse $event) use ($jsonRpc, $jsonRpcId): void {
                    $data = $event->toArray();

                    if ($jsonRpc) {
                        $data = [
                            'jsonrpc' => '2.0',
                            'id' => $jsonRpcId,
                            'result' => $data,
                        ];
                    }

                    echo 'data: '.json_encode($data, JSON_THROW_ON_ERROR)."\n\n";
                    flush();
                },
                static fn (): bool => connection_aborted() === 0,
            );
        }, 200, [
            'Content-Type' => Protocol::SSE_CONTENT_TYPE,
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @return array{0: string, 1: bool, 2: mixed}|null
     */
    private function subscribeTarget(ServerRequestInterface $request): ?array
    {
        $path = $request->getUri()->getPath();
        $path = $path === '' ? '/' : $path;

        $body = (string) $request->getBody();
        $request->getBody()->rewind();
        $payload = $body === '' ? [] : json_decode($body, true);
        $jsonRpc = is_array($payload) && ($payload['jsonrpc'] ?? null) === '2.0';
        $jsonRpcId = is_array($payload) ? ($payload['id'] ?? null) : null;
        $taskId = null;

        if ($jsonRpc && ($payload['method'] ?? null) === 'SubscribeToTask') {
            $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
            $taskId = is_string($params['id'] ?? null) ? $params['id'] : (is_string($params['task_id'] ?? null) ? $params['task_id'] : null);
        }

        if (preg_match('#^/tasks/([^/]+):subscribe$#', $path, $matches) === 1 && in_array($request->getMethod(), ['GET', 'POST'], true)) {
            $taskId = urldecode($matches[1]);
            $jsonRpc = false;
        }

        if (! is_string($taskId) || $taskId === '') {
            return null;
        }

        return [$taskId, $jsonRpc, $jsonRpcId];
    }

    private function toPsr(Request $request): ServerRequestInterface
    {
        $factory = new Psr17Factory;

        return new PsrHttpFactory($factory, $factory, $factory, $factory)->createRequest($request);
    }

    private function fromPsr(ResponseInterface $response): Response
    {
        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return new Response((string) $response->getBody(), $response->getStatusCode(), $headers);
    }

    private function stripPrefix(ServerRequestInterface $request, string $prefix): ServerRequestInterface
    {
        $prefix = '/'.trim($prefix, '/');

        if ($prefix === '/') {
            return $request;
        }

        $path = $request->getUri()->getPath();

        if ($path === $prefix) {
            return $request->withUri($request->getUri()->withPath('/'));
        }

        if (str_starts_with($path, $prefix.'/')) {
            $stripped = substr($path, strlen($prefix));

            return $request->withUri($request->getUri()->withPath($stripped === '' ? '/' : $stripped));
        }

        return $request;
    }
}
