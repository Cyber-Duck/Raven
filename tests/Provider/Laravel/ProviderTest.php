<?php

namespace rcrowe\Raven\Tests\Provider\Laravel;

use PHPUnit_Framework_TestCase;
use Mockery as m;
use Illuminate\Foundation\Application;
use Illuminate\Config\Repository;
use Illuminate\Queue\QueueManager;
use Illuminate\Log\Writer;
use rcrowe\Raven\Provider\Laravel\RavenServiceProvider;
use rcrowe\Raven\Provider\Laravel\Log;
use rcrowe\Raven\Client;
use Monolog\Logger;

class ProviderTest extends PHPUnit_Framework_TestCase
{
    public function tearDown()
    {
        m::close();
    }

    public function testConfigLoaded()
    {
        $app = new Application;

        $config = m::mock('Illuminate\Config\Repository');
        $config->shouldReceive('package')
               ->once()
               ->with('rcrowe/raven', realpath(__DIR__.'/../../../').'/src/Provider/Laravel/config');
        $app['config'] = $config;

        $provider = new RavenServiceProvider($app);
        $provider->register();
    }

    public function testTransportBound()
    {
        $app = $this->getApplication();

        $provider = new RavenServiceProvider($app);
        $provider->register();

        $this->assertTrue($app->bound('log.raven.transport'));
        $this->assertInstanceOf('rcrowe\Raven\Transport\Guzzle', $app->make('log.raven.transport'));
    }

    public function testHandlerBound()
    {
        $app = $this->getApplication();

        $provider = new RavenServiceProvider($app);
        $provider->register();

        $this->assertTrue($app->bound('log.raven.handler'));
        $this->assertInstanceOf('rcrowe\Raven\Handler\Laravel', $app->make('log.raven.handler'));
    }

    public function testClientBound()
    {
        $app = $this->getApplication();

        $provider = new RavenServiceProvider($app);
        $provider->register();

        $client = $app->make('log.raven');

        $this->assertTrue($app->bound('log.raven'));
        $this->assertInstanceOf('rcrowe\Raven\Client', $client);

        $this->assertEquals('rcrowe-raven/'.Client::VERSION, $client->logger);
        $this->assertEquals('http://foo.com/api/store/', $client->servers[0]);
        $this->assertEquals('123', $client->public_key);
        $this->assertEquals('456', $client->secret_key);
        $this->assertEquals('789', $client->project);

        $this->assertEquals('production', $client->context->tags['laravel_environment']);
        $this->assertEquals(Application::VERSION, $client->context->tags['laravel_version']);

        $this->assertInstanceOf('rcrowe\Raven\Handler\Laravel', $client->getHandler());
    }

    public function testDisabled()
    {
        $app        = $this->getApplication();
        $app['log'] = new Writer(new Logger('test'));

        $provider = new RavenServiceProvider($app);
        $provider->register();
        $provider->boot();

        $this->assertInstanceOf('Illuminate\Log\Writer', $app['log']);

        try {
            $app['log']->getMonolog()->popHandler();
            $this->assertFalse(true);
        } catch (\LogicException $ex) {
            $this->assertTrue(true);
        }
    }

    public function testLogPatched()
    {
        $app        = $this->getApplication(true);
        $app['log'] = new Writer(new Logger('test'));

        $provider = new RavenServiceProvider($app);
        $provider->register();
        $provider->boot();

        $this->assertInstanceOf('rcrowe\Raven\Provider\Laravel\Log', $app['log']);
    }

    public function testRavenHandlerRegistered()
    {
        $app = $this->getApplication(true);

        $provider = new RavenServiceProvider($app);
        $provider->register();
        $provider->boot();

        $handler = $app['log']->getMonolog()->popHandler();

        $this->assertInstanceOf('Monolog\Handler\RavenHandler', $handler);
        $this->assertEquals($handler->getLevel(), Logger::CRITICAL);
    }

    protected function getApplication($enabled = false)
    {
        $app          = new Application;
        $app['env']   = 'production';
        $app['queue'] = new QueueManager($app);

        $config = new Repository(m::mock('Illuminate\Config\LoaderInterface'), 'production');

        $config->getLoader()->shouldReceive('addNamespace');
        $config->getLoader()->shouldReceive('cascadePackage')
            ->andReturnUsing(function ($env, $package, $group, $items) {
                return $items;
            });
        $config->getLoader()->shouldReceive('exists')->with('environments', 'raven')->andReturn(false);
        $config->getLoader()->shouldReceive('exists')->with('dsn', 'raven')->andReturn(false);
        $config->getLoader()->shouldReceive('exists')->with('enabled', 'raven')->andReturn(false);
        $config->getLoader()->shouldReceive('exists')->with('level', 'raven')->andReturn(false);
        $config->getLoader()->shouldReceive('load')->with('production', 'config', 'raven')->andReturn(
            array(
                'dsn'     => 'http://123:456@foo.com/789',
                'enabled' => $enabled,
                'level'   => 'critical',
            )
        );
        $app['config'] = $config;

        $logger = new Log(new Logger('test'));
        $app['log'] = $logger;

        return $app;
    }
}
