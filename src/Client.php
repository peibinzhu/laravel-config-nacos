<?php

declare(strict_types=1);

namespace PeibinLaravel\ConfigNacos;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use PeibinLaravel\ConfigNacos\Contracts\Client as ClientContract;
use PeibinLaravel\Contracts\StdoutLoggerInterface;
use PeibinLaravel\Nacos\Application;
use PeibinLaravel\Utils\Codec\Json;
use PeibinLaravel\Utils\Codec\Xml;
use Swoole\Coroutine;

class Client implements ClientContract
{
    protected Application $client;

    protected StdoutLoggerInterface $logger;

    public function __construct(protected Container $container)
    {
        $this->config = $container->get(Repository::class);
        $this->client = $container->get(NacosClient::class);
        $this->logger = $container->get(StdoutLoggerInterface::class);
    }

    public function pull(): array
    {
        $listener = $this->config->get('config_center.drivers.nacos.listener_config', []);

        $config = [];
        try {
            foreach ($listener as $key => $item) {
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
            }
        } catch (\Throwable $e) {
            $message = "[{$e->getCode()}]{$e->getMessage()}[{$e->getFile()}:{$e->getLine()}]";
            $this->logger->error($message);
        }

        return $config;
    }

    public function longPull(callable $callback): void
    {
        $listener = $this->config->get('config_center.drivers.nacos.listener_config', []);
        foreach ($listener as $key => $item) {
            Coroutine::create(function () use ($key, $item, $callback) {
                while (true) {
                    $dataId = $item['data_id'];
                    $group = $item['group'];
                    $type = $item['type'] ?? null;
                    $contentMD5 = $item['contentMD5'] ?? null;
                    $tenant = $item['tenant'] ?? null;

                    try {
                        $response = $this->client->config->listener($dataId, $group, $contentMD5, $tenant);
                    } catch (\Throwable $e) {
                        $message = "[{$e->getCode()}]{$e->getMessage()}[{$e->getFile()}:{$e->getLine()}]";
                        $this->logger->error($message);

                        sleep(3);
                        continue;
                    }

                    $responseBody = (string)$response->getBody();
                    if (($statusCode = $response->getStatusCode()) !== 200) {
                        $this->logger->error(
                            sprintf(
                                'Failed to monitor nacos config: [%s]%s',
                                $statusCode,
                                $responseBody
                            )
                        );

                        sleep(3);
                        continue;
                    }

                    if ($responseBody) {
                        $response = $this->client->config->get($dataId, $group, $tenant);
                        if ($response->getStatusCode() !== 200) {
                            $this->logger->error(
                                sprintf('The config of %s read failed from Nacos.', $key)
                            );

                            sleep(3);
                            continue;
                        }

                        $content = (string)$response->getBody();
                        $item['contentMD5'] = md5($content);
                        $this->config->set(['config_center.drivers.nacos.listener_config.' . $key => $item]);
                        $config = $this->decode((string)$response->getBody(), $type);
                        $callback([$key => $config]);
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
