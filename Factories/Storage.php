<?php

namespace LaravelInterbus\Factories;

use Illuminate\Contracts\Filesystem\Filesystem;

use function array_key_exists;

class Storage
{
    /** @noinspection MultipleReturnStatementsInspection */
    public static function make(?string $diskName = null, ?string $bucket = null): Filesystem
    {
        $offloadDisk = $diskName ?? config('interbus.offload.disk', 'interbus-offloads');
        if (null === $bucket) {
            $bucket = (string)config('interbus.offload.custom_bucket');
        }

        if ( ! $bucket) {
            return \Illuminate\Support\Facades\Storage::disk($offloadDisk);
        }

        $config = config("filesystems.disks.$offloadDisk");
        if ( ! array_key_exists('bucket', $config)) {
            return \Illuminate\Support\Facades\Storage::disk($offloadDisk);
        }

        if ($config['bucket'] === $bucket) {
            return \Illuminate\Support\Facades\Storage::disk($offloadDisk);
        }

        $config['bucket'] = $bucket;

        $custom_name = $offloadDisk . '_' . $bucket;

        config()->set("filesystems.disks.$custom_name", $config);

        return \Illuminate\Support\Facades\Storage::disk($custom_name);
    }
}