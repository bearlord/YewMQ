<?php

namespace App;

use DI\DependencyException;
use DI\NotFoundException;
use Yew\Core\Exception\ConfigException;
use Yew\Plugins\Actor\ActorPlugin;
use Yew\Plugins\Amqp\AmqpConsumerPlugin;
use Yew\Plugins\Amqp\AmqpPlugin;
use Yew\Plugins\CircuitBreaker\CircuitBreakerPlugin;
use Yew\Plugins\Database\DatabasePlugin;
use Yew\Plugins\RateLimit\RateLimitPlugin;
use Yew\Plugins\Scheduled\ScheduledPlugin;
use Yew\Plugins\Topic\TopicPlugin;

class Application
{

    /**
     * @throws NotFoundException
     * @throws \ReflectionException
     * @throws DependencyException
     * @throws ConfigException
     */
    public static function main()
    {
        $app = new \Yew\Framework\Application();

        $app->addPlugin(new DatabasePlugin());

        $app->addPlugin(new RateLimitPlugin());

        $app->addPlugin(new CircuitBreakerPlugin());

        $app->addPlugin(new ScheduledPlugin());

        $app->run(Application::class);
    }
}