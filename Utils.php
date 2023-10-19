<?php

namespace LaravelInterbus;

use LaravelInterbus\Traits\BroadcastsOnInterbus;
use Illuminate\Database\Eloquent\Model;

final class Utils
{
    private static array $interbusModels = [];

    public static function isInterbusModel(?Model $model): bool
    {
        if (is_null($model)) {
            return false;
        }
        $class = get_class($model);
        if (array_key_exists($class, self::$interbusModels)) {
            return self::$interbusModels[$class];
        }

        return self::$interbusModels[$class] = self::isInterbusClass($class);
    }

    public static function isInterbusClass(string $class): bool
    {
        if (!$class) {
            return false;
        }
        if (array_key_exists($class, self::$interbusModels)) {
            return self::$interbusModels[$class];
        }

        return self::$interbusModels[$class] = in_array(BroadcastsOnInterbus::class, class_uses_recursive($class));
    }

    public static function getClassByProtoName(string $protoName): ?string
    {
        $classes = config('interbus.protomap.protoToClass', []);
        if (!is_array($classes)) {
            return null;
        }

        if (array_key_exists($protoName, $classes)) {
            return $classes[$protoName];
        }

        return null;
    }

    public static function getProtoNameByClass(string $className): ?string
    {
        $classes = config('interbus.protomap.classToProto', []);
        if (!is_array($classes)) {
            return null;
        }

        if (array_key_exists($className, $classes)) {
            return $classes[$className];
        }

        return null;
    }
}