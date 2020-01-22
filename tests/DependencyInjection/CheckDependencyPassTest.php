<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\DependencyInjection;

use Kcs\MessengerExtra\DependencyInjection\Compiler\CheckDependencyPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Transport\TransportInterface;

class CheckDependencyPassTest extends TestCase
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
    public function testProcessShouldNotThrowOnInvalidUrls(string $url): void
    {
        $this->expectNotToPerformAssertions();

        $container = new ContainerBuilder();
        $container
            ->register('kcs.messenger.test_transport', TransportInterface::class)
            ->addArgument($url)
            ->addTag('messenger.receiver')
        ;

        $pass = new CheckDependencyPass();
        $pass->process($container);
    }
}
