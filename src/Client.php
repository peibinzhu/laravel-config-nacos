<?php

declare(strict_types=1);

namespace PeibinLaravel\ConfigNacos;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use PeibinLaravel\Codec\Json;
use PeibinLaravel\Codec\Xml;
use PeibinLaravel\ConfigCenter\Events\ConfigPullFailed;
use PeibinLaravel\ConfigNacos\Contracts\ClientInterface;
use PeibinLaravel\Contracts\StdoutLoggerInterface;
use PeibinLaravel\Coordinator\Constants;
use PeibinLaravel\Coordinator\CoordinatorManager;
use PeibinLaravel\Coroutine\Coroutine;
use PeibinLaravel\Nacos\Application;
use Throwable;

class Client implements ClientInterface
{
    protected Repository $config;

    protected Application $client;

    protected StdoutLoggerInterface $logger;

    protected Dispatcher $dispatcher;

    public function __construct(protected Container $container)
    {
        $this->config = $container->get(Repository::class);
        $this->client = $container->get(NacosClient::class);
        $this->logger = $container->get(StdoutLoggerInterface::class);
        $this->dispatcher = $container->get(Dispatcher::class);
    }

    public function pull(): array
    {
        $listener = $this->config->get('config_center.drivers.nacos.listener_config', []);

        $config = [];
        foreach ($listener as $key => $item) {
            try {
                $dataId = $item['data_id'];
                $group = $item['group'];
                $tenant = $item['tenant'] ?? null;
                $type = $item['type'] ?? null;
                $response = $this->client->config->get($dataId, $group, $tenant);
                if ($response->getStatusCode() !== 200) {
                    $this->logger->error(sprintf('The config of %s read failed from Nacos.', $key));
                    continue;
                }
                $config[$key] = $this->decode((string)$response->getBody(), $type);
            } catch (Throwable $e) {
                $this->logger->error((string)$e);
                $this->dispatcher?->dispatch(new ConfigPullFailed($e, $key, $item));
            }
        }

        return $config;
    }

    public function longPull(callable $updatedCallback): void
    {
        $listener = $this->config->get('config_center.drivers.nacos.listener_config', []);
        foreach ($listener as $key => $item) {
            Coroutine::create(function () use ($key, $item, $updatedCallback) {
                $interval = 3;
                while (true) {
                    try {
                        $dataId = $item['data_id'];
                        $group = $item['group'];
                        $type = $item['type'] ?? null;
                        $contentMD5 = $item['contentMD5'] ?? null;
                        $tenant = $item['tenant'] ?? null;

                        try {
                            $response = $this->client->config->listener($dataId, $group, $contentMD5, $tenant);
                        } catch (Throwable $e) {
                            $message = sprintf('Failed to start monitoring nacos configuration: %s', (string)$e);
                            $this->logger->error($message);

                            if (CoordinatorManager::until(Constants::WORKER_EXIT)->yield($interval)) {
                                break;
                            }
                            continue;
                        }

                        $responseBody = (string)$response->getBody();
                        if (($statusCode = $response->getStatusCode()) !== 200) {
                            $message = sprintf('Failed to monitor nacos config: [%s]%s', $statusCode, $responseBody);
                            $this->logger->error($message);

                            if (CoordinatorManager::until(Constants::WORKER_EXIT)->yield($interval)) {
                                break;
                            }
                            continue;
                        }

                        if ($responseBody) {
                            $response = $this->client->config->get($dataId, $group, $tenant);
                            if ($response->getStatusCode() !== 200) {
                                $this->logger->error(sprintf('The config of %s read failed from Nacos.', $key));

                                if (CoordinatorManager::until(Constants::WORKER_EXIT)->yield($interval)) {
                                    break;
                                }
                                continue;
                            }

                            $content = (string)$response->getBody();
                            $config = $this->decode($content, $type);

                            $item['contentMD5'] = md5($content);
                            $this->config->set(['config_center.drivers.nacos.listener_config.' . $key => $item]);
                            $updatedCallback([$key => $config]);
                        }
                    } catch (Throwable $e) {
                        $this->logger->error((string)$e);
                        $this->dispatcher?->dispatch(new ConfigPullFailed($e, $key, $item));

                        if (CoordinatorManager::until(Constants::WORKER_EXIT)->yield($interval)) {
                            break;
                        }
                    }
                }
            });
        }
    }

    /**
     * @param string      $body
     * @param string|null $type
     * @return array|string
     */
    public function decode(string $body, ?string $type = null): array | string
    {
        $type = strtolower((string)$type);
        return match ($type) {
            'json' => Json::decode($body),
            'yml', 'yaml' => yaml_parse($body),
            'xml' => Xml::toArray($body),
            default => $body,
        };
    }
}
