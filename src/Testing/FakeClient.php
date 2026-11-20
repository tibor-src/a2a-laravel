<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Testing;

use TiborSrc\A2A\Client\Client;
use TiborSrc\A2A\Id;
use TiborSrc\A2A\Types\AgentCard;
use TiborSrc\A2A\Types\CancelTaskRequest;
use TiborSrc\A2A\Types\DeleteTaskPushNotificationConfigRequest;
use TiborSrc\A2A\Types\GetExtendedAgentCardRequest;
use TiborSrc\A2A\Types\GetTaskPushNotificationConfigRequest;
use TiborSrc\A2A\Types\GetTaskRequest;
use TiborSrc\A2A\Types\ListTaskPushNotificationConfigsRequest;
use TiborSrc\A2A\Types\ListTaskPushNotificationConfigsResponse;
use TiborSrc\A2A\Types\ListTasksRequest;
use TiborSrc\A2A\Types\ListTasksResponse;
use TiborSrc\A2A\Types\Message;
use TiborSrc\A2A\Types\Part;
use TiborSrc\A2A\Types\Role;
use TiborSrc\A2A\Types\SendMessageRequest;
use TiborSrc\A2A\Types\SendMessageResponse;
use TiborSrc\A2A\Types\StreamResponse;
use TiborSrc\A2A\Types\SubscribeToTaskRequest;
use TiborSrc\A2A\Types\Task;
use TiborSrc\A2A\Types\TaskPushNotificationConfig;

final readonly class FakeClient implements Client
{
    public function __construct(
        private A2AFake $fake,
        private string $url,
    ) {}

    public function sendMessage(SendMessageRequest $request): SendMessageResponse
    {
        $this->fake->record($this->url, $request);

        return $this->fake->responseFor($this->url, $request);
    }

    public function sendStreamingMessage(SendMessageRequest $request): array
    {
        $response = $this->sendMessage($request);
        $events = [];

        if ($response->message instanceof Message) {
            $events[] = StreamResponse::fromEvent($response->message);
        }

        if ($response->task instanceof Task) {
            $events[] = StreamResponse::fromEvent($response->task);
        }

        return $events;
    }

    public function getTask(GetTaskRequest $request): Task
    {
        $response = $this->fake->responseFor($this->url, new SendMessageRequest(
            new Message(Id::uuid(), Role::User, [Part::text('get')]),
        ));

        if ($response->task instanceof Task) {
            return new Task(
                $request->id,
                $response->task->status,
                $response->task->contextId,
                $response->task->artifacts,
                $response->task->history,
                $response->task->metadata,
            );
        }

        throw new \RuntimeException('Fake A2A client has no task.');
    }

    public function listTasks(ListTasksRequest $request): ListTasksResponse
    {
        unset($request);

        return new ListTasksResponse([], '', 50, 0);
    }

    public function cancelTask(CancelTaskRequest $request): Task
    {
        return $this->getTask(new GetTaskRequest($request->id));
    }

    public function subscribeToTask(SubscribeToTaskRequest $request): array
    {
        unset($request);

        return [];
    }

    public function createTaskPushNotificationConfig(TaskPushNotificationConfig $config): TaskPushNotificationConfig
    {
        return $config;
    }

    public function getTaskPushNotificationConfig(GetTaskPushNotificationConfigRequest $request): TaskPushNotificationConfig
    {
        unset($request);

        return new TaskPushNotificationConfig($this->url);
    }

    public function listTaskPushNotificationConfigs(ListTaskPushNotificationConfigsRequest $request): ListTaskPushNotificationConfigsResponse
    {
        unset($request);

        return new ListTaskPushNotificationConfigsResponse([]);
    }

    public function deleteTaskPushNotificationConfig(DeleteTaskPushNotificationConfigRequest $request): void
    {
        unset($request);
    }

    public function getExtendedAgentCard(GetExtendedAgentCardRequest $request): AgentCard
    {
        unset($request);

        return $this->fake->card($this->url);
    }
}
