<?php

namespace LaravelInterbus;

final class InterbusColumnsManifest
{
    private static $manifest = array ();

    public static function getManifest(): array
    {
        return self::$manifest;
    }

    public static function getTableColumns(string $table): array
    {
        return self::$manifest[$table] ?? [];
    }
}