<?php

declare(strict_types=1);

namespace PeibinLaravel\ConfigNacos;

use Illuminate\Support\ServiceProvider;
use PeibinLaravel\ConfigCenter\Contracts\Client as ClientContract;
use PeibinLaravel\Utils\Providers\RegisterProviderConfig;

class ConfigNacosServiceProvider extends ServiceProvider
{
    use RegisterProviderConfig;

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
