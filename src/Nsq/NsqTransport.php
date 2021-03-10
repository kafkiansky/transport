<?php

/**
 * AMQP transport implementation.
 *
 * @author  Konstantin  Grachev <me@grachevko.ru>
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 0);

namespace ServiceBus\Transport\Nsq;

use function Amp\call;
use Amp\Promise;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ServiceBus\Transport\Common\Package\OutboundPackage;
use ServiceBus\Transport\Common\Queue;
use ServiceBus\Transport\Common\QueueBind;
use ServiceBus\Transport\Common\Topic;
use ServiceBus\Transport\Common\TopicBind;
use ServiceBus\Transport\Common\Transport;

/**
 *
 */
final class NsqTransport implements Transport
{
    /**
     * @var NsqTransportConnectionConfiguration
     */
    private $config;

    /**
     * @psalm-var array<string, \ServiceBus\Transport\Nsq\NsqConsumer>
     *
     * @var NsqConsumer[]
     */
    private $consumers = [];

    /**
     * @var NsqPublisher|null
     */
    private $publisher;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(NsqTransportConnectionConfiguration $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @codeCoverageIgnore
     */
    public function createTopic(Topic $topic, TopicBind ...$binds): Promise
    {
        return call(static function ()
        {
        });
    }

    /**
     * @codeCoverageIgnore
     */
    public function createQueue(Queue $queue, QueueBind ...$binds): Promise
    {
        return call(static function ()
        {
        });
    }

    public function consume(callable $onMessage, Queue ...$queues): Promise
    {
        return call(
            function () use ($queues, $onMessage): \Generator
            {
                yield $this->connect();

                /** @var \ServiceBus\Transport\Nsq\NsqChannel $channel */
                foreach ($queues as $channel)
                {
                    $this->logger->debug('Starting a subscription to the "{channelName}" channel', [
                        'host'        => $this->config->host,
                        'port'        => $this->config->port,
                        'channelName' => $channel->name,
                    ]);

                    $consumer = new NsqConsumer($channel, $this->config, $this->logger);

                    $promise = $consumer->listen($onMessage);

                    $promise->onResolve(
                        function (?\Throwable $throwable) use ($channel, $consumer): void
                        {
                            if ($throwable !== null)
                            {
                                throw $throwable;
                            }

                            $this->consumers[$channel->name] = $consumer;
                        }
                    );
                }
            }
        );
    }

    public function stop(): Promise
    {
        return $this->disconnect();
    }

    public function send(OutboundPackage ...$outboundPackages): Promise
    {
        return call(
            function () use ($outboundPackages): \Generator
            {
                if (\count($outboundPackages) === 0)
                {
                    return;
                }

                if ($this->publisher === null)
                {
                    $this->publisher = new NsqPublisher($this->config, $this->logger);
                }

                if (\count($outboundPackages) === 1)
                {
                    yield $this->publisher->publish($outboundPackages[\array_key_first($outboundPackages)]);

                    return;
                }

                yield $this->publisher->publishBulk(...$outboundPackages);
            }
        );
    }

    public function connect(): Promise
    {
        return call(static function ()
        {
        });
    }

    public function disconnect(): Promise
    {
        return call(
            function (): \Generator
            {
                if ($this->publisher !== null)
                {
                    $this->publisher->disconnect();
                }

                $promises = [];

                foreach ($this->consumers as $consumer)
                {
                    $promises[] = $consumer->stop();
                }

                yield $promises;
            }
        );
    }
}