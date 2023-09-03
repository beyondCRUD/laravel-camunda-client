<?php

namespace BeyondCRUD\LaravelCamundaClient\Http;

use BeyondCRUD\LaravelCamundaClient\Data\ExternalTaskData;
use BeyondCRUD\LaravelCamundaClient\Exceptions\CamundaException;
use BeyondCRUD\LaravelCamundaClient\Exceptions\UnexpectedResponseException;

class ExternalTaskClient extends CamundaClient
{
    protected static array $subscribers = [];

    public static function subscribers(): array
    {
        return self::$subscribers;
    }

    public static function subscribe(string $topic, string|array $job): void
    {
        self::$subscribers[$topic] = [
            'topicName' => $topic,
            'job' => is_string($job) ? $job : $job['job'] ?? null,
            'lockDuration' => $job['lockDuration'] ?? 600_000,
        ];
    }

    /**
     * @return ExternalTaskData[]
     */
    public static function getByProcessInstanceId(string $id): array
    {
        $response = self::make()->get("external-task?processInstanceId=$id");

        $data = [];
        if ($response->successful()) {
            foreach ($response->json() as $task) {
                $data[] = new ExternalTaskData(...$task);
            }
        }

        return $data;
    }

    /**
     * @return ExternalTaskData[]
     */
    public static function fetchAndLock(string $workerId, array $topics, int $maxTasks = 10): array
    {
        $payload = ['workerId' => $workerId, 'maxTasks' => $maxTasks, 'topics' => $topics];
        $response = self::make()->post('external-task/fetchAndLock', $payload);

        if ($response->successful()) {
            $data = [];
            foreach ($response->json() as $raw) {
                $data[] = ExternalTaskData::fromArray($raw);
            }

            return $data;
        }

        throw new CamundaException($response->json('message'));
    }

    public static function complete(
        string $id,
        string $workerId,
        array $variables = [],
        array $localVariables = []
    ): bool {
        $payload = compact('workerId');
        if ($variables) {
            $payload['variables'] = $variables;
        }
        if ($localVariables) {
            $payload['localVariables'] = $localVariables;
        }
        $url = "external-task/$id/complete";
        $response = self::make()->post($url, $payload);
        $isSuccessful = $response->status() === 204;

        if (! $isSuccessful) {
            throw (new UnexpectedResponseException)->for($url, $payload, $response->json());
        }

        return $isSuccessful;
    }

    public static function fail(
        string $id,
        string $workerId,
        string $errorMessage = 'Does not compute',
        int $retryTimeout = 60000,
    ): bool {

        $payload = compact('workerId', 'errorMessage', 'retryTimeout');
        $url = "external-task/$id/failure";
        $response = self::make()->post($url, $payload);
        $isSuccessful = $response->status() === 204;

        if (! $isSuccessful) {
            throw (new UnexpectedResponseException)->for($url, $payload, $response->json());
        }

        return $isSuccessful;
    }

    public static function unlock(string $id): bool
    {
        $response = self::make()->post("external-task/$id/unlock");

        return $response->status() === 204;
    }
}
