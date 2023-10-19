<?php

namespace LaravelInterbus;

use LaravelInterbus\Contracts\Intentions\InterbusCommandHandlesResponse;

class PackageManifest extends \Illuminate\Foundation\PackageManifest
{
    protected function write(array $manifest)
    {
        $manifest['laravel-interbus']['response_handlers'] = $this->getIntentionsWithHandlers();
        parent::write($manifest);
    }


    private function getIntentionsWithHandlers(): array
    {
        $path = $this->basePath . '/app/Jobs';
        if (!$this->files->exists($path)) {
            return [];
        }
        $jobs = $this->files->allFiles($path);
        $intentions = [];
        foreach ($jobs as $job) {
            $tokens = token_get_all($job->getContents());
            $intentionFQN = '';
            $count = count($tokens);
            for ($index = 0; $index < $count; $index++) {
                $token = $tokens[$index];
                if (T_NAMESPACE === $token[0]) {
                    $index += 2;
                    $intentionFQN = $tokens[$index][1];
                } elseif (T_CLASS === $token[0]) {
                    $index += 2;
                    $intentionFQN .= '\\' . $tokens[$index][1];
                    break;
                }
            }
            if (is_subclass_of($intentionFQN, InterbusCommandHandlesResponse::class)) {
                $intentions[] = $intentionFQN;
            }
        }

        return $intentions;
    }

}