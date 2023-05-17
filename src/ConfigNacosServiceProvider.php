<?php

declare(strict_types=1);

namespace PeibinLaravel\ConfigNacos;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use PeibinLaravel\ConfigNacos\Contracts\ClientInterface;

class ConfigNacosServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $dependencies = [
            ClientInterface::class => Client::class,
            NacosClient::class     => NacosClientFactory::class,
        ];
        $this->registerDependencies($dependencies);
    }

    private function registerDependencies(array $dependencies)
    {
        $config = $this->app->get(Repository::class);
        foreach ($dependencies as $abstract => $concrete) {
            $concreteStr = is_string($concrete) ? $concrete : gettype($concrete);
            if (is_string($concrete) && method_exists($concrete, '__invoke')) {
                $concrete = function () use ($concrete) {
                    return $this->app->call($concrete . '@__invoke');
                };
            }
            $this->app->singleton($abstract, $concrete);
            $config->set(sprintf('dependencies.%s', $abstract), $concreteStr);
        }
    }
}
