<?php

declare(strict_types=1);

namespace App\Tests\Messaging;

use App\Messaging\AmqpBuildJobListener;
use App\Messaging\BuildJobHandler;
use App\Storage\JobWorkspace;
use PHPUnit\Framework\TestCase;

/**
 * listen() connects lazily -- it only reads AMQP_DSN once it's called, so a
 * missing/invalid DSN can be tested without a broker.
 */
final class AmqpBuildJobListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('AMQP_DSN');
    }

    /**
     * The handler is never reached -- every test fails while connecting -- so
     * its base directory does not have to exist.
     */
    private function listener(): AmqpBuildJobListener
    {
        return new AmqpBuildJobListener(new BuildJobHandler(new JobWorkspace('/tmp')));
    }

    public function testThrowsWhenAmqpDsnIsNotSet(): void
    {
        putenv('AMQP_DSN');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AMQP_DSN is not set.');

        $this->listener()->listen();
    }

    public function testThrowsWhenAmqpDsnIsEmpty(): void
    {
        putenv('AMQP_DSN=');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AMQP_DSN is not set.');

        $this->listener()->listen();
    }

    public function testThrowsWhenAmqpDsnIsMissingAHost(): void
    {
        // A DSN without "//" is the only shape parse_url() accepts without a
        // host -- any "//..." URL missing a host fails parse_url() itself
        // (caught by the "not a valid URL" check instead, tested elsewhere).
        putenv('AMQP_DSN=amqp:rabbitmq');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AMQP_DSN is missing a host.');

        $this->listener()->listen();
    }

    public function testThrowsWhenAmqpDsnIsMissingAPort(): void
    {
        putenv('AMQP_DSN=amqp://user:pass@rabbitmq/%2f');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AMQP_DSN is missing a port.');

        $this->listener()->listen();
    }

    public function testThrowsWhenAmqpDsnIsMissingAUser(): void
    {
        putenv('AMQP_DSN=amqp://rabbitmq:5672/%2f');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AMQP_DSN is missing a user.');

        $this->listener()->listen();
    }

    public function testThrowsWhenAmqpDsnIsMissingAPassword(): void
    {
        putenv('AMQP_DSN=amqp://user@rabbitmq:5672/%2f');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AMQP_DSN is missing a password.');

        $this->listener()->listen();
    }
}
