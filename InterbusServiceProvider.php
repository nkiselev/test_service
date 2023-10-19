<?php

namespace LaravelInterbus;

use LaravelInterbus\Commands\DispatchLocalModelChangedCommand;
use LaravelInterbus\Commands\DispatchRawEventCommand;
use LaravelInterbus\Commands\DumpProtoCommand;
use LaravelInterbus\Commands\HandleKafkaTriggerCommand;
use LaravelInterbus\Commands\InterbusConsumeCommand;
use LaravelInterbus\Commands\InterbusManifestCommand;
use LaravelInterbus\Commands\SetupAwsLambdaKafkaTrigger;
use LaravelInterbus\Commands\MigrateKafkaConsumerFromSupervisiorToAwsTrigger;
use LaravelInterbus\Contracts\DummySentrySerializableInterface;
use LaravelInterbus\Contracts\Intentions\IntentionReplyEvent;
use LaravelInterbus\Contracts\InterbusEvent;
use LaravelInterbus\Events\Intentions\CommandIntentionReceivedEvent;
use LaravelInterbus\Events\RemoteModelChanged;
use LaravelInterbus\Events\RemoteModelCreated;
use LaravelInterbus\Events\RemoteModelDeleted;
use LaravelInterbus\Listeners\Intentions\CommandIntentionReceivedListener;
use LaravelInterbus\Listeners\Intentions\CommandProcessedListener;
use LaravelInterbus\Listeners\Intentions\IntentionStateListener;
use LaravelInterbus\Listeners\InterbusOutgoingListener;
use LaravelInterbus\Listeners\InterbusOutgoingListenerSync;
use LaravelInterbus\Listeners\RemoteModelChangesListener;
use Composer\InstalledVersions;
use Enqueue\Dbal\DbalContext;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class InterbusServiceProvider extends ServiceProvider
{
    private static bool $sentryAliased = false;
    public static ?string $source = null;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/interbus.php', 'interbus');
        $this->mergeConfigFrom(__DIR__ . '/config/disk.php', 'filesystems.disks');

        $interBusConfig = $this->app->get('config')->get('interbus');
        $defaultConnection = $interBusConfig['connections']['default'];

        $this->app->singleton(
            Context::class,
            static function (Application $app) use ($interBusConfig, $defaultConnection) {
                $factoryClass = $interBusConfig['connections'][$defaultConnection]['factory'];

                if (!class_exists($factoryClass)) {
                    Log::alert("[\Interop\Queue\Context] Class $factoryClass configured as Interbus default connection, but it does not exist. Check if the proper driver installed via composer.");
                    return null;
                }

                if (!is_subclass_of($factoryClass,ConnectionFactory::class)) {
                    Log::alert("[\Interop\Queue\Context] Class $factoryClass configured as Interbus default connection, but it does not interface \Interop\Queue\ConnectionFactory");
                    return null;
                }

                if ($app->bound($factoryClass)) {
                    return $app->get($factoryClass)->createContext();
                }

                /** @var ConnectionFactory $factory */
                $factory = new $factoryClass($interBusConfig['connections'][$defaultConnection]['config']);

                return $factory->createContext();
            }
        );

        $this->app->extend(Context::class, static function (?Context $context) {
            if ($context instanceof DbalContext) {
                $context->createDataBaseTable();
            }

            return $context;
        });

        $this->app->extend(PackageManifest::class, static function (PackageManifest $manifest) {
            $packageManifest = new \LaravelInterbus\PackageManifest(
                $manifest->files,
                $manifest->basePath,
                $manifest->manifestPath
            );
            $packageManifest->manifest = $manifest->manifest;

            return $packageManifest;
        });
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function boot(): void
    {
        /**
         * @https://laravel.com/docs/8.x/packages#commands
         */
        if ($this->app->runningInConsole()) {
            $this->commands([
                InterbusConsumeCommand::class,
            ]);
        }

        $this->app->terminating(fn(?Context $context) => $context?->close());
    }

    public static function getSourceIdentifier(): mixed
    {
        return self::$source ?? config('interbus.service_id') ?? config('app.name');
    }
}
