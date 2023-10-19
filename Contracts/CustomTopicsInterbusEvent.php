<?php

namespace LaravelInterbus\Contracts;

interface CustomTopicsInterbusEvent
{
    public function interbusTopics(): array;
}