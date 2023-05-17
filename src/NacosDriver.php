<?php

declare(strict_types=1);

namespace PeibinLaravel\ConfigNacos;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;
use PeibinLaravel\ConfigCenter\AbstractDriver;
use PeibinLaravel\ConfigCenter\Events\ConfigUpdated;
use PeibinLaravel\ConfigNacos\Contracts\ClientInterface;

class NacosDriver extends AbstractDriver
{
    protected string $driverName = 'nacos';

    public function __construct(protected Container $container)
    {
        parent::__construct($container);
        $this->client = $container->get(ClientInterface::class);
    }

    public function createMessageFetcherLoop(): void
    {
        if (!method_exists($this->client, 'longPull')) {
            parent::createMessageFetcherLoop();
            return;
        }

        retry(INF, function () {
            $this->client->longPull(function ($config) {
                $this->syncConfig($config);
            });
        }, 1);
    }

    protected function updateConfig(array $config)
    {
        $root = $this->config->get('config_center.drivers.nacos.default_key');
        $mergeMode = $this->config->get('config_center.drivers.nacos.merge_mode');
        foreach ($config ?? [] as $key => $conf) {
            if (is_int($key)) {
                $key = $root;
            }

            if (is_array($conf) && $mergeMode === Constants::CONFIG_MERGE_APPEND) {
                $conf = static::merge($this->config->get($key, []), $conf);
            }

            $prevConf = $this->config->get($key);
            $this->config->set($key, $conf);
            $this->event(new ConfigUpdated($key, $conf, $prevConf));
            $this->logger->debug(sprintf('Config [%s] is updated.', $key));
        }
    }

    protected static function merge(array $array1, array $array2, bool $unique = true): array
    {
        $isAssoc = Arr::isAssoc($array1 ?: $array2);
        if ($isAssoc) {
            foreach ($array2 as $key => $value) {
                if (is_array($value)) {
                    $array1[$key] = static::merge($array1[$key] ?? [], $value, $unique);
                } else {
                    $array1[$key] = $value;
                }
            }
        } else {
            foreach ($array2 as $key => $value) {
                if ($unique && in_array($value, $array1, true)) {
                    continue;
                }
                $array1[] = $value;
            }

            $array1 = array_values($array1);
        }
        return $array1;
    }
}
