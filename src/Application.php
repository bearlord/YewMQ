<?php

namespace App;

use DI\DependencyException;
use DI\NotFoundException;
use Yew\Core\Exception\ConfigException;
use Yew\Plugins\CircuitBreaker\CircuitBreakerPlugin;
use Yew\Plugins\Database\DatabasePlugin;
use Yew\Plugins\Mqtt\Connection\MqttConnectionPlugin;
use Yew\Plugins\RateLimit\RateLimitPlugin;
use Yew\Plugins\Scheduled\ScheduledPlugin;

class Application
{

    /**
     * @throws NotFoundException
     * @throws \ReflectionException
     * @throws DependencyException
     * @throws ConfigException
     */
    public static function main(): void
    {
        $app = new \Yew\Framework\Application();

        $app->addPlugin(new DatabasePlugin());

        $app->addPlugin(new RateLimitPlugin());

        $app->addPlugin(new CircuitBreakerPlugin());

        $app->addPlugin(new ScheduledPlugin());

        $app->addPlugin(new MqttConnectionPlugin());

        $app->run(Application::class);
    }
}