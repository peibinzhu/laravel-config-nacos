<?php

declare(strict_types=1);

namespace PeibinLaravel\ConfigNacos;

use PeibinLaravel\Nacos\Application;
use PeibinLaravel\Nacos\Config as NacosConfig;

class NacosClientFactory
{
    public function __invoke()
    {
        $config = config('config_center.drivers.nacos.client', []);
        if (empty($config)) {
            return app(Application::class);
        }

        if (!empty($config['uri'])) {
            $baseUri = $config['uri'];
        } else {
            $baseUri = sprintf('http://%s:%d', $config['host'] ?? '127.0.0.1', $config['port'] ?? 8848);
        }

        return new Application(
            new NacosConfig([
                'base_uri'      => $baseUri,
                'username'      => $config['username'] ?? null,
                'password'      => $config['password'] ?? null,
                'guzzle_config' => $config['guzzle']['config'] ?? null,
            ])
        );
    }
}
