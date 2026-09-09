<?php

declare(strict_types=1);

namespace App\Messaging;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Listens for build jobs on RabbitMQ and hands each one to BuildJobHandler.
 *
 * The broker imports its topology from publish/docker/rabbitmq-config/definitions.json
 * at boot, so this listener never declares the queue or an exchange -- it just
 * consumes from the queue by name.
 */
final class AmqpBuildJobListener
{
    private const QUEUE_BUILDS = 'q.builds';

    public function __construct(private readonly BuildJobHandler $handler)
    {
    }

    public function listen(): void
    {
        $conn = $this->amqpConnect();
        $ch = $conn->channel();

        // Process one message at a time -- fine at this scale, and keeps
        // acking simple (no reordering of in-flight messages to reason about).
        $ch->basic_qos(null, 1, false);

        $ch->basic_consume(
            self::QUEUE_BUILDS,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $msg): void {
                error_log(sprintf('[INFO] received build job: %s', $msg->getBody()));

                // Acked either way: no dead-letter queue and no retry counter
                // yet, so requeuing a job that can never succeed would
                // redeliver it forever. The log line carries the reason.
                try {
                    $this->handler->handle($msg->getBody());
                } catch (\Throwable $e) {
                    error_log(sprintf('[ERROR] build job failed: %s', $e->getMessage()));
                } finally {
                    $msg->ack();
                }
            },
        );

        error_log(sprintf('[INFO] worker started, listening on queue %s', self::QUEUE_BUILDS));

        while ($ch->is_consuming()) {
            $ch->wait();
        }

        $ch->close();
        $conn->close();
    }

    /**
     * Open a connection to RabbitMQ from the AMQP_DSN environment variable.
     *
     * Same parsing logic as publish's AmqpBuildQueue::amqpConnect() -- duplicated
     * rather than shared since these are separate deployable services.
     */
    private function amqpConnect(): AMQPStreamConnection
    {
        $dsn = getenv('AMQP_DSN');
        if ($dsn === false || $dsn === '') {
            throw new \RuntimeException('AMQP_DSN is not set.');
        }

        // parse_url($dsn) will result in the following list of key-value pairs
        // [
        //     "scheme" => "amqp",
        //     "host" => "rabbitmq",
        //     "port" => 5672,
        //     "user" => "app",
        //     "pass" => "secret",
        //     "path" => "/%2f",
        //   ]
        $p = parse_url($dsn);
        if ($p === false) {
            throw new \RuntimeException('AMQP_DSN is not a valid URL.');
        }
        $host = $p['host'] ?? throw new \RuntimeException('AMQP_DSN is missing a host.');
        $port = (int) ($p['port'] ?? throw new \RuntimeException('AMQP_DSN is missing a port.'));
        $user = $p['user'] ?? throw new \RuntimeException('AMQP_DSN is missing a user.');
        $pass = $p['pass'] ?? throw new \RuntimeException('AMQP_DSN is missing a password.');
        $vhost = isset($p['path']) && $p['path'] !== '' && $p['path'] !== '/'
            ? rawurldecode(ltrim($p['path'], '/'))
            : '/';

        $hb = getenv('AMQP_HEARTBEAT');
        $heartbeat = $hb === false ? 10 : (int) $hb;

        $rw = $heartbeat > 0 ? $heartbeat * 2 + 2 : 30;

        return new AMQPStreamConnection(
            $host, $port, $user, $pass, $vhost,
            false, 'AMQPLAIN', null, 'en_US',
            3.0, $rw, null, true, $heartbeat
        );
    }
}
