<?php

namespace Illuminate\Database\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Events\ModelPruningFinished;
use Illuminate\Database\Events\ModelPruningStarting;
use Illuminate\Database\Events\ModelsPruned;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Finder\Finder;

class PruneCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'model:prune
                                {--all : Find and prune all models, even outside of the app/Models directory}
                                {--model=* : Class names of the models to be pruned}
                                {--except=* : Class names of the models to be excluded from pruning}
                                {--chunk=1000 : The number of models to retrieve per chunk of models to be deleted}
                                {--pretend : Display the number of prunable records found instead of deleting them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune models that are no longer needed';

    /**
     * Execute the console command.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @return void
     */
    public function handle(Dispatcher $events)
    {
        $models = $this->models();

        if ($models->isEmpty()) {
            $this->components->info('No prunable models found.');

            return;
        }

        if ($this->option('pretend')) {
            $models->each(function ($model) {
                $this->pretendToPrune($model);
            });

            return;
        }

        $pruning = [];

        $events->listen(ModelsPruned::class, function ($event) use (&$pruning) {
            if (! in_array($event->model, $pruning)) {
                $pruning[] = $event->model;

                $this->newLine();

                $this->components->info(sprintf('Pruning [%s] records.', $event->model));
            }

            $this->components->twoColumnDetail($event->model, "{$event->count} records");
        });

        $events->dispatch(new ModelPruningStarting($models->all()));

        $models->each(function ($model) {
            $this->pruneModel($model);
        });

        $events->dispatch(new ModelPruningFinished($models->all()));

        $events->forget(ModelsPruned::class);
    }

    /**
     * Prune the given model.
     *
     * @param  string  $model
     * @return void
     */
    protected function pruneModel(string $model)
    {
        $instance = new $model;

        $chunkSize = property_exists($instance, 'prunableChunkSize')
            ? $instance->prunableChunkSize
            : $this->option('chunk');

        $total = $this->isPrunable($model)
            ? $instance->pruneAll($chunkSize)
            : 0;

        if ($total == 0) {
            $this->components->info("No prunable [$model] records found.");
        }
    }

    /**
     * Determine the models that should be pruned.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function models()
    {
        $models = $this->option('model');
        $except = $this->option('except');

        if (! empty($models) && ! empty($except)) {
            throw new InvalidArgumentException('The --model and --except options cannot be combined.');
        }

        if (! empty($models)) {
            return collect($models)->filter(function ($model) {
                return class_exists($model);
            })->values();
        }

        if ($this->option('all')) {
            return collect(get_declared_classes())
                ->when(! empty($except), function ($models) use ($except) {
                    return $models->reject(fn ($model) => in_array($model, $except));
                })
                ->filter(fn ($class) => $this->isPrunable($class))
                ->all();
        }

        return collect((new Finder)->in($this->getDefaultPath())->files()->name('*.php'))
            ->map(function ($model) {
                $namespace = $this->laravel->getNamespace();

                return $namespace.str_replace(
                    ['/', '.php'],
                    ['\\', ''],
                    Str::after($model->getRealPath(), realpath(app_path()).DIRECTORY_SEPARATOR)
                );
            })->when(! empty($except), function ($models) use ($except) {
                return $models->reject(fn ($model) => in_array($model, $except));
            })->filter(function ($model) {
                return class_exists($model);
            })->filter(function ($model) {
                return $this->isPrunable($model);
            })->values();
    }

    /**
     * Get the default path where models are located.
     *
     * @return string|string[]
     */
    protected function getDefaultPath()
    {
        return app_path('Models');
    }

    /**
     * Determine if the given model class is prunable.
     *
     * @param  string  $model
     * @return bool
     */
    protected function isPrunable($model)
    {
        $uses = class_uses_recursive($model);

        return in_array(Prunable::class, $uses) || in_array(MassPrunable::class, $uses);
    }

    /**
     * Display how many models will be pruned.
     *
     * @param  string  $model
     * @return void
     */
    protected function pretendToPrune($model)
    {
        $instance = new $model;

        $count = $instance->prunable()
            ->when(in_array(SoftDeletes::class, class_uses_recursive(get_class($instance))), function ($query) {
                $query->withTrashed();
            })->count();

        if ($count === 0) {
            $this->components->info("No prunable [$model] records found.");
        } else {
            $this->components->info("{$count} [{$model}] records will be pruned.");
        }
    }
}
