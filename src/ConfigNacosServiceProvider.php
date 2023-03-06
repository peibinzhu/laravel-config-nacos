<?php

declare(strict_types=1);

namespace PeibinLaravel\ConfigNacos;

use Illuminate\Support\ServiceProvider;
use PeibinLaravel\ConfigCenter\Contracts\Client as ClientContract;
use PeibinLaravel\ProviderConfig\Contracts\ProviderConfigInterface;

class ConfigNacosServiceProvider extends ServiceProvider implements ProviderConfigInterface
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                ClientContract::class => Client::class,
                NacosClient::class    => NacosClientFactory::class,
            ],
        ];
    }
}
