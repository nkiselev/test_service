<?php

namespace LaravelInterbus\Commands;

use Carbon\Carbon;
use LaravelInterbus\Commands\Traits\RateLimiterTrait;
use LaravelInterbus\Events\InterbusMessageReceived;
use LaravelInterbus\InterbusServiceProvider;
use LaravelInterbus\Jobs\HandleInterbusMessageJob;
use LaravelInterbus\Topic\TopicMode;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Str;
use Interop\Queue\Consumer;
use Interop\Queue\Context;
use Interop\Queue\Topic;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\VarDumper\Caster\ReflectionCaster;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\ServerDumper;
use Throwable;

class InterbusConsumeCommand extends Command
{
    use RateLimiterTrait;

    protected $signature = 'interbus:consume
                                {topic : Which topic to read}
                                {--offset= : This is a consumer offset <offset>:<partition>}
                                {--msg-poll-timeout=1 : Timeout in seconds to wait next message}
                                {--once : Stop consumer after one message was received or waiting was timed out}
                                {--ignore-from=* : Ignore messages from such producers}
                                {--accept-from=* : Accept messages from such producers}
                                {--ignore-all : Ignore all messages from any producer}
                                {--ignore-own : Ignore messages produced by the service}
                                {--throughput=0/0 : Messages consumption throughput 0/0 - unlimited. 1/2 - one message per two seconds}
                                {--ignore-until= : Ignore all events before this point of time}
                                {--mode=async : Mode (sync / async)}
                                {--async-connection-destination= : custom connection of the queue where to send async message }
                                {--async-queue-destination= : custom queue where to send async message}';

    protected $description = 'Interbus consumer';

    private bool $shouldStop = false;
    private int $consumed = 0;
    private int $ignored = 0;
    private string $serviceId;
    private string $topicName;
    private ?Closure $dumper = null;

    private function shouldWork(): bool
    {
        return !$this->shouldStop;
    }

    public function __construct()
    {
        parent::__construct();
        $this->serviceId = Str::snake((string)InterbusServiceProvider::getSourceIdentifier());
    }

    /**
     * @param Context $context
     * @param EventDispatcher $eventDispatcher
     * @param BusDispatcher $busDispatcher
     * @return int
     */
    public function handle(Context $context, EventDispatcher $eventDispatcher, BusDispatcher $busDispatcher): int
    {
        $this->setUpSignals();
        $this->topicName = (string)$this->argument('topic');
        $topic = $context->createTopic($this->topicName);
        $consumer = $context->createConsumer($topic);

        $mode = $this->option('mode');
        if ($mode === null) {
            $mode = TopicMode::ASYNC;
        }

        if ($this->option('ignore-all')) {
            $ignore = ['*'];
        } else {
            $ignore = array_filter((array)$this->option('ignore-from'));
            if ($this->option('ignore-own')) {
                $ignore = array_merge([$this->serviceId], $ignore);
            }
            $ignore = array_unique(array_map('\Illuminate\Support\Str::snake', $ignore));
        }
        $accept = array_map('\Illuminate\Support\Str::snake', array_filter((array)$this->option('accept-from')));
        $accept = array_diff($accept, [$this->serviceId]);

        if (!$accept && '*' === ($ignore[0] ?? null)) {
            $this->error('All the messages will be rejected');

            return self::INVALID;
        }

        if ($offset = $this->option('offset')) {
            $this->applyOffset($consumer, $topic, $offset);
        }

        $this->info('-- Start listening --');

        $once = (bool)$this->option('once');

        $msgPollTimeout = max(intval($this->option('msg-poll-timeout')), .2) * 1000;
        /** @var Carbon|null $until */
        $until = null;
        if ($untilOption = $this->option('ignore-until')) {
            $until = Carbon::parse($untilOption);
        }

        $throughput = $this->option('throughput') ?: '';

        $rateLimiter = $this->getRateLimiter($topic->getTopicName(), $throughput);

        $asyncConnectionDestination = $this->option('async-connection-destination');
        $asyncQueueDestination = $this->option('async-queue-destination');

        while ($this->shouldWork()) {
            try {
                try {
                    $message = $rateLimiter(fn() => $consumer->receive($msgPollTimeout));
                    if (is_null($message)) {
                        $this->info('Message polling timed out', OutputInterface::VERBOSITY_DEBUG);
                        continue;
                    }
                } catch (Throwable $exception) {
                    $this->error($exception->getMessage());
                    continue;
                }
                ++$this->consumed;

                $messageId = $message->getMessageId() ?? $message->getHeader('event_id');
                $messageTime = $message->getTimestamp() ?? $message->getHeader('event_time');
                if ($messageTime) {
                    $messageTime = Carbon::parse($messageTime)->toDateTimeString();
                    if ($until && $until->greaterThan($messageTime)) {
                        $this->info(
                            sprintf("-- Message is before %s --", $until),
                            OutputInterface::VERBOSITY_VERBOSE
                        );
                        $consumer->reject($message);
                        continue;
                    }
                }

                $this->info(
                    sprintf("-- Received new message [ID: %s, Timestamp: %s] --", $messageId, $messageTime),
                    OutputInterface::VERBOSITY_VERBOSE
                );

                $eventSource = $message->getHeader('event_source') ?? $message->getHeader('source');

                $sourceId = Str::snake($eventSource ?? '');
                if (!in_array($sourceId, $accept) && (('*' === ($ignore[0] ?? null)) || in_array($sourceId, $ignore))) {
                    $this->info(
                        'Messages from "' . $eventSource . '" is in ignore list',
                        OutputInterface::VERBOSITY_VERBOSE
                    );
                    ++$this->ignored;
                    $consumer->reject($message);

                    continue;
                }

                if ($message->getHeader('event_addressee', $this->serviceId) !== $this->serviceId) {
                    $this->info(
                        "-- Message dropped because it was addressed not to me [ID: $messageId, Timestamp: $messageTime] --",
                        OutputInterface::VERBOSITY_VERBOSE
                    );
                    $consumer->reject($message);
                    continue;
                }
                $replyTo = $message->getReplyTo();
                $this->info(sprintf(
                    "-- Message accepted [ID: %s, Timestamp: %s%s] --",
                    $messageId,
                    $messageTime,
                    isset($replyTo) ? ', ReplyTo: ' . $replyTo : ''
                ));

                if ($dumper = $this->getDebugDumper()) {
                    $dumper($message);
                } else {
                    $this->info(
                        sprintf("-- Message body [Raw: %s] --", $message->getBody()),
                        OutputInterface::VERBOSITY_VERY_VERBOSE
                    );
                }

                $event = new InterbusMessageReceived($message);
                $handleJob = new HandleInterbusMessageJob($event);
                $eventDispatcher->dispatch($event);

                if ($mode === TopicMode::SYNC) {
                    $busDispatcher->dispatchSync($handleJob);
                } else {
                    if ($asyncConnectionDestination) {
                        $handleJob->onConnection($asyncConnectionDestination);
                    }

                    if ($asyncQueueDestination) {
                        $handleJob->onQueue($asyncQueueDestination);
                    }

                    $busDispatcher->dispatch($handleJob);
                }
                $this->info("-- Message dispatched to queue [ID: $messageId, Timestamp: $messageTime] --");

                $consumer->acknowledge($message);
            } finally {
                if ($once) {
                    $this->shouldStop = true;
                }
            }
        }
        $this->info("-- Gracefully stopped --");

        return self::SUCCESS;
    }

    public function line($string, $style = null, $verbosity = null)
    {
        $timestamp = $this->getTimestamp();
        $line = sprintf(
            '[%s] (%s) %s',
            $timestamp,
            $this->serviceId . ':' . $this->topicName,
            $string
        );
        parent::line($line, $style, $verbosity);
    }

    protected function applyOffset(Consumer $consumer, Topic $topic, string $offset): void
    {
        if (
            !str_contains($offset, ':') ||
            !method_exists($topic, 'setPartition') ||
            !method_exists($consumer, 'setOffset')
        ) {
            $this->warn("-- Ignoring offset $offset. Either offset is in wrong format or the queue driver does not support offsets --");

            return;
        }

        [$offset, $partition] = explode(':', $offset);
        $this->info("-- Set offset to $offset on partition $partition --");

        $topic->setPartition((int)$partition);
        $consumer->setOffset((int)$offset);
    }

    /**
     * @return string
     */
    private function getTimestamp(): string
    {
        return Carbon::now()->format("Y-m-d H:i:s");
    }

    private function verbosityCarousel(): void
    {
        static $values = [
            OutputInterface::VERBOSITY_NORMAL,
            OutputInterface::VERBOSITY_VERBOSE,
            OutputInterface::VERBOSITY_VERY_VERBOSE,
            OutputInterface::VERBOSITY_DEBUG
        ];
        static $count;
        $count ??= count($values) - 1;
        $current = array_search($this->output->getVerbosity(), $values);
        if (false === $current) {
            $new = 0;
        } elseif ($current === $count) {
            $new = 0;
        } else {
            $new = $current + 1;
        }
        $this->info(sprintf('Verbosity changed from "%s" to "%s"',
                $this->getVerbosityName($values[$current] ?? 0),
                $this->getVerbosityName($values[$new]))
        );
        $this->output->setVerbosity($values[$new]);
    }

    private function getVerbosityName(int $verbosity): string
    {
        return match ($verbosity) {
            OutputInterface::VERBOSITY_NORMAL => 'NORMAL',
            OutputInterface::VERBOSITY_VERBOSE => 'VERBOSE',
            OutputInterface::VERBOSITY_VERY_VERBOSE => 'VERY VERBOSE',
            OutputInterface::VERBOSITY_DEBUG => 'DEBUG',
            default => 'UNKNOWN'
        };
    }

    private function setUpSignals(): void
    {
        pcntl_async_signals(true);
        $this->shouldStop = false;
        $stop = fn() => $this->shouldStop = true;
        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGTERM, $stop);
        if (defined('SIGPWR')) {
            pcntl_signal(SIGPWR, $stop);
        }
        $startTime = $this->getTimestamp();
        pcntl_signal(
            SIGUSR2,
            fn() => fprintf(
                STDERR,
                'Hey! Don\'t touch my tralala! I am alive and Im working, unlike you! Since I woke up at %s I have consumed and processed %d of messages, %d of them were ignored' . PHP_EOL,
                $startTime,
                $this->consumed,
                $this->ignored
            )
        );
        pcntl_signal(SIGUSR1, fn() => $this->verbosityCarousel());
    }

    /**
     * @return Closure|null
     */
    protected function getDebugDumper(): ?Closure
    {
        if (!$this->dumper && $this->output->isDebug()) {
            $dumper = new ServerDumper('127.0.0.1:9912');
            $cloner = new VarCloner();
            $cloner->addCasters(ReflectionCaster::UNSET_CLOSURE_FILE_INFO);
            $this->dumper = fn($var) => $dumper->dump($cloner->cloneVar($var));
        }

        return $this->dumper;
    }
}
