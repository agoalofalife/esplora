<?php

declare(strict_types=1);

namespace Esplora\Tracker;

use Esplora\Tracker\Contracts\Rule;
use Esplora\Tracker\Models\Goal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use Ramsey\Uuid\Rfc4122\UuidV4;

class Esplora
{
    use Conditionable;

    public const REDIS_PREFIX = 'esplore-';
    public const ID_SESSION = 'esplora.id';

    /**
     * @var Request
     */
    protected Request $request;

    /**
     * Visitor constructor.
     *
     * @param Request $request
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * @param Request $request
     *
     * @return bool
     */
    public function isNeedVisitWrite(Request $request): bool
    {
        return collect(config('esplora.rules'))
            ->map(fn (string $class)   => app()->make($class))
            ->map(fn (Rule $rule)      => $rule->passes($request))
            ->filter(fn (bool $result) => $result === false)
            ->isEmpty();
    }

    /**
     * @return UuidV4
     */
    public function loadVisitId(): UuidV4
    {
        return $this->request
            ->session()
            ->remember(Esplora::ID_SESSION, fn () => Str::orderedUuid());
    }

    /**
     * @return Connection
     */
    public function redis(): Connection
    {
        return Redis::connection(config('esplora.redis'));
    }

    /**
     * @param string $name
     * @param array  $parameters
     */
    public function goal(string $name, array $parameters = []): void
    {
        $this->saveAfterResponse(new Goal([
            'id'         => Str::orderedUuid(),
            'visitor_id' => $this->loadVisitId(),
            'name'       => $name,
            'parameters' => $parameters,
            'created_at' => now(),
        ]));
    }

    /**
     * @param Model $model
     */
    public function saveAfterResponse(Model $model): void
    {
        dispatch(function () use ($model) {
            if (config('esplora.filling', 'sync') === 'sync') {
                $model->save();

                return;
            }

            $key = Str::of(get_class($model))->classBasename()
                ->start(Esplora::REDIS_PREFIX)
                ->finish('_')
                ->finish(Str::uuid())
                ->slug();

            $this->redis()->set($key, $model->toJson());
        })->afterResponse();
    }

    /**
     * @param string $model
     *
     * @return int
     */
    public function importModelsForRedis(string $model): int
    {
        $redis = $this->redis();

        $patternForSearch = Str::of($model)
            ->classBasename()
            ->start(Esplora::REDIS_PREFIX)
            ->slug()
            ->finish('*');

        // get all keys
        $keys = collect($redis->keys($patternForSearch))
            ->map(fn ($key) => Str::of($key)->after(Esplora::REDIS_PREFIX)->start(Esplora::REDIS_PREFIX))
            ->toArray();

        if (count($keys) === 0) {
            return 0;
        }

        // get all values
        $values = collect()
            ->merge($redis->mGet($keys))
            ->map(fn (string $value) => json_decode($value, true, 512, JSON_THROW_ON_ERROR))
            ->map(fn (array $value)  => collect($value)->map(fn ($attr)  => is_array($attr) ? json_encode($attr, JSON_THROW_ON_ERROR) : $attr))
            ->toArray();

        // save mass records
        (new $model)->insert($values);

        // remove all values
        $redis->del($keys);

        return count($values);
    }
}
