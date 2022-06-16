<?php

declare(strict_types=1);

namespace PeibinLaravel\ConfigNacos\Contracts;

use PeibinLaravel\ConfigCenter\Contracts\Client as ConfigCenterClient;

interface Client extends ConfigCenterClient
{
    /**
     * Listen to the configuration of the configuration center, and then update the configuration value.
     */
    public function longPull(callable $callback): void;
}
