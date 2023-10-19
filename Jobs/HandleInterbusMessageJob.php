<?php

namespace LaravelInterbus\Jobs;

use LaravelInterbus\Contracts\InterbusEventWithMessageId;
use LaravelInterbus\Contracts\InterbusEventWithReplyTopic;
use LaravelInterbus\Events\EventType;
use LaravelInterbus\Events\InterbusMessageReceived;
use LaravelInterbus\Events\NonMatchingEventReceived;
use LaravelInterbus\Exceptions\OffloadedPayloadException;
use LaravelInterbus\Factories\Storage;
use LaravelInterbus\InterbusServiceProvider;
use LaravelInterbus\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Interop\Queue\Message;
use JsonException;
use Throwable;

use function is_array;
use function strlen;

class HandleInterbusMessageJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(private InterbusMessageReceived $interbusEvent)
    {
    }

    /**
     * @throws OffloadedPayloadException
     * @throws Throwable
     */
    public function handle(Dispatcher $dispatcher): void
    {
        $eventSource = $this->interbusEvent->message->getHeader('event_source')
            ?? $this->interbusEvent->message->getHeader('source');

        if ($eventSource && $eventSource === InterbusServiceProvider::getSourceIdentifier()) {
            return;
        }

        try {
            $eventType = $this->interbusEvent->message->getHeader('event_type');
            if ($eventType === EventType::TYPE_SERIALIZED_EVENT) {
                $this->processSerializedEvent($this->interbusEvent->message, $dispatcher);
            } elseif ($eventType === EventType::TYPE_PB_EVENT) {
                $this->processPBEvent($this->interbusEvent->message, $dispatcher);
            } else {
                $this->processGenericEvent($this->interbusEvent->message, $dispatcher);
            }
        } catch (Throwable $exception) {
            Log::error('Error while processing incoming interbus event', [
                'error' => $exception,
                'message_headers' => $this->interbusEvent->message->getHeaders(),
                'message_body' => $this->interbusEvent->message->getBody(),
            ]);

            throw $exception;
        }
    }

    /**
     * @throws OffloadedPayloadException
     * @throws JsonException
     */
    protected function processSerializedEvent(Message $message, Dispatcher $dispatcher): void
    {
        $eventClass = $message->getHeader('event_class');

        if (!class_exists($eventClass)) {
            return;
        }

        $payload = json_decode(
            $message->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if ($heavyPayloadHash = $message->getHeader('event_heavy_payload_hash')) {
            $offloadDisk = $message->getHeader('event_heavy_payload_disk') ?? config('interbus.offload.disk', 'interbus-offloads');

            try {
                // todo: event_custom_bucket is deprecated and will be removed in a future version
                $bucket = $message->getHeader('event_heavy_payload_bucket', $message->getHeader('event_custom_bucket'));
                $heavyPayload = Storage::make($offloadDisk, $bucket)->get("$heavyPayloadHash.json");
            } catch (Throwable $e) {
                throw new OffloadedPayloadException($heavyPayloadHash, $e);
            }

            if ($message->getHeader('event_compress')) {
                $heavyPayload = gzuncompress($heavyPayload);
            }

            $hp = json_decode($heavyPayload, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($hp)) {
                $payload += $hp;
            } else {
                Log::warning('empty heavy payload', [
                    'heavy_payload' => [
                        'hash' => $heavyPayloadHash,
                        'offloadDisk' => $offloadDisk,
                        'bucket' => $bucket,
                    ],
                ]);
            }
        }

        $messageId = $message->getMessageId() ?? $message->getHeader('event_id');
        $replyTopic = $message->getHeader('reply_topic');

        $this->dispatchEventWithPayload($eventClass, $payload, $messageId, $dispatcher, $replyTopic);
    }

    /**
     * @throws JsonException
     */
    protected function processGenericEvent(Message $message, Dispatcher $dispatcher): void
    {
        $eventName = $message->getHeader('event');

        $eventClass = rtrim(config('interbus.namespaces.generic_events'), '\\') . '\\' . Str::studly($eventName);

        $payload = json_decode(
            $message->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR
        ) ?? $message->getBody();

        if (class_exists($eventClass)) {
            $messageId = $message->getMessageId() ?? $message->getHeader('event_id');
            $replyTopic = $message->getHeader('reply_topic');
            $this->dispatchEventWithPayload($eventClass, $payload, $messageId, $dispatcher, $replyTopic);

            return;
        }
        $dispatcher->dispatch(new NonMatchingEventReceived($message->getHeaders(), $payload));
    }

    protected function dispatchEventWithPayload(string $eventClass, array $payload, ?string $messageId, Dispatcher $dispatcher, ?string $replyTopic = null): void
    {
        $serialized = 'O:' . strlen($eventClass) . ':"' . $eventClass . '"' . ltrim(serialize($payload), 'a');
        $event = unserialize($serialized, [
            'allowed_classes' => true,
        ]);

        if (isset($messageId) && $event instanceof InterbusEventWithMessageId) {
            $event->setInterbusMessageId($messageId);
        }

        if ($event instanceof InterbusEventWithReplyTopic) {
            $event->setReplyTopic($replyTopic);
        }

        $event->preventInterbusRebroadcast = true;
        $dispatcher->dispatch($event);
    }

    private function processPBEvent(Message $message, Dispatcher $dispatcher): void
    {
        $eventName = $message->getHeader('event');

        $class = Utils::getClassByProtoName($eventName);
        if (!$class) {
            Log::warning('Unknown event type', [
                'event' => $eventName,
            ]);
            return;
        }

        /** @var \Google\Protobuf\Internal\Message $event */
        $event = new $class();
        $event->mergeFromJsonString($message->getBody(), true);
        $dispatcher->dispatch($event);
    }
}