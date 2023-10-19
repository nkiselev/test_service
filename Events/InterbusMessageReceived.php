<?php

namespace LaravelInterbus\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Interop\Queue\Message;

class InterbusMessageReceived
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Message $message)
    {
        //
    }

}
