<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\DependencyInjection;

use Kcs\MessengerExtra\DependencyInjection\Compiler\RegisterDoctrineEventsPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Transport\TransportInterface;

class RegisterDoctrineEventsTest extends TestCase
{
    public function provideNonDoctrineUrl(): iterable
    {
        yield ['null:'];
        yield ['sync://'];
        yield ['/just_a_path'];
        yield ['not_an_url'];
    }

    /**
     * @dataProvider provideNonDoctrineUrl
     */
    public function testProcessShouldDoNothingOnInvalidUrl(string $url): void
    {
        $container = new ContainerBuilder();
        $def = $container
            ->register('kcs.messenger.test_transport', TransportInterface::class)
            ->addArgument($url)
            ->addTag('messenger.receiver')
        ;

        $pass = new RegisterDoctrineEventsPass();
        $pass->process($container);

        self::assertCount(0, $tags = $def->getTag('doctrine.event_listener'));
    }

    public function testProcessShouldAddEventListenerTagToDbalTransports(): void
    {
        $container = new ContainerBuilder();
        $def = $container
            ->register('kcs.messenger.test_transport', TransportInterface::class)
            ->addArgument('doctrine://connection_name/test')
            ->addTag('messenger.receiver')
        ;

        $pass = new RegisterDoctrineEventsPass();
        $pass->process($container);

        self::assertCount(1, $tags = $def->getTag('doctrine.event_listener'));
        self::assertEquals([
            'event' => 'postGenerateSchema',
            'connection' => 'connection_name',
        ], $tags[0]);
    }

    public function testProcessShouldResolveMessengerDsnEnvVar(): void
    {
        \putenv('TEST_MESSENGER_EXTRA_DSN=doctrine://env_connection/test');

        $container = new ContainerBuilder();
        $def = $container
            ->register('kcs.messenger.test_transport', TransportInterface::class)
            ->addArgument('%env(TEST_MESSENGER_EXTRA_DSN)%')
            ->addTag('messenger.receiver')
        ;

        $pass = new RegisterDoctrineEventsPass();
        $pass->process($container);

        self::assertCount(1, $tags = $def->getTag('doctrine.event_listener'));
        self::assertEquals([
            'event' => 'postGenerateSchema',
            'connection' => 'env_connection',
        ], $tags[0]);

        \putenv('TEST_MESSENGER_EXTRA_DSN=');
    }
}
