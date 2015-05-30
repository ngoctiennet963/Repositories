<?php namespace SebastianBerc\Repositories\Managers;

use Illuminate\Contracts\Container\Container as Application;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model as Eloquent;
use SebastianBerc\Repositories\Contracts\Repositorable;

/**
 * Class CacheRepositoryManager
 *
 * @author    Sebastian Berć <sebastian.berc@gmail.com>
 * @copyright Copyright (c) Sebastian Berć
 * @package   SebastianBerc\Repositories\Managers
 */
class CacheRepositoryManager implements Repositorable
{
    /**
     * Contains Laravel Application instance.
     *
     * @var Application
     */
    protected $app;

    /**
     * Contains the Eloquent model instance.
     *
     * @var Eloquent Model instance.
     */
    protected $instance;

    /**
     * Contains the Laravel CacheRepository instance.
     *
     * @var \Illuminate\Contracts\Cache\Repository
     */
    protected $cache;

    /**
     * Contains the lifetime of cache.
     *
     * @var int
     */
    protected $lifetime;

    /**
     * Create a new CacheRepositoryManager instance.
     *
     * @param Application $app
     * @param Eloquent    $modelInstance
     */
    public function __construct(Application $app, $modelInstance)
    {
        $this->app      = $app;
        $this->instance = $modelInstance;
        $this->cache    = $app->make('cache.store');
        $this->lifetime = $app->make('config')['cache.lifetime'] ?: 30;
    }

    /**
     * Return cache key for specified credentials.
     *
     * @param mixed $suffix
     *
     * @return string
     */
    protected function cacheKey($suffix = null)
    {
        $key = $this->instance->getTable();

        if (is_array($suffix)) {
            $key .= '.' . md5(serialize($suffix));
        }

        if (is_scalar($suffix)) {
            $key .= '.' . $suffix;
        }

        return $key;
    }

    /**
     * Call method on manager, and store results in cache.
     *
     * @param string $cacheKey
     * @param int    $lifetime
     * @param array  $arguments
     *
     * @return mixed
     */
    protected function store($cacheKey, $lifetime, array $arguments = [])
    {
        $method = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2)[1]["function"];

        return $this->cache->remember($cacheKey, $lifetime, function () use ($method, $arguments) {
            return call_user_func_array([$this->manager(), $method], $arguments);
        });
    }

    /**
     * Return a new instance of RepositoryManager.
     *
     * @return RepositoryManager
     */
    protected function manager()
    {
        return new RepositoryManager($this->app, $this->instance);
    }

    /**
     * Get all of the models from the database.
     *
     * @param string[] $columns
     *
     * @return Collection
     */
    public function all(array $columns = ['*'])
    {
        $cacheKey = ($columns == ['*'] ? $this->cacheKey() : $this->cacheKey($columns));

        if ($this->cache->has($cacheKey)) {
            return $this->cache->get($cacheKey);
        }

        return $this->store($cacheKey, $this->lifetime, func_get_args());
    }

    /**
     * Create a new basic where query clause on model.
     *
     * @param string|array $column
     * @param string       $operator
     * @param mixed        $value
     * @param string       $boolean
     *
     * @return Collection
     */
    public function where($column, $operator = '=', $value = null, $boolean = 'and', array $columns = ['*'])
    {
        $cacheKey = $this->cacheKey(compact('column', 'operator', 'value', 'boolean', 'columns'));

        if ($this->cache->has($cacheKey)) {
            return $this->cache->get($cacheKey);
        }

        return $this->store($cacheKey, $this->lifetime, func_get_args());
    }

    /**
     * Paginate the given query.
     *
     * @param int      $perPage
     * @param string[] $columns
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate($perPage = 15, array $columns = ['*'])
    {
        $cacheKey = $this->cacheKey("paginate.{$perPage}");

        if ($this->cache->has($cacheKey)) {
            return $this->cache->get($cacheKey);
        }

        return $this->store($cacheKey, $this->lifetime, func_get_args());
    }

    /**
     * Save a new model and return the instance.
     *
     * @param array $attributes
     *
     * @return Eloquent
     */
    public function create(array $attributes = [])
    {
        return $this->createOrUpdate(null, $attributes);
    }

    /**
     * Save or update the model in the database.
     *
     * @param mixed $identifier
     * @param array $attributes
     *
     * @return Eloquent|null
     */
    public function update($identifier, array $attributes = [])
    {
        return $this->createOrUpdate($identifier, $attributes);
    }

    /**
     * Create or update the model in the database.
     *
     * @param int|null $identifier
     * @param array    $attributes
     *
     * @return mixed
     */
    protected function createOrUpdate($identifier = null, array $attributes = [])
    {
        $instance = !is_null($identifier)
            ? $this->manager()->update($identifier, $attributes)
            : $this->manager()->create($attributes);

        $cacheKey = $this->cacheKey($instance->getKey());

        $this->cache->forget($cacheKey);

        return $this->cache->remember($cacheKey, $this->lifetime, function () use ($instance) {
            return $instance;
        });
    }

    /**
     * Delete the model from the database.
     *
     * @param int $identifier
     *
     * @return bool
     */
    public function delete($identifier)
    {
        $instance = $this->manager()->find($identifier);
        $cacheKey = $this->cacheKey($instance->getKey());

        $this->cache->forget($cacheKey);

        return $instance->delete();
    }

    /**
     * Find a model by its primary key.
     *
     * @param int      $identifier
     * @param string[] $columns
     *
     * @return Eloquent
     */
    public function find($identifier, array $columns = ['*'])
    {
        $cacheKey = $this->cacheKey($identifier);

        if ($this->cache->has($cacheKey)) {
            return $this->cache->get($cacheKey);
        }

        return $this->store($cacheKey, $this->lifetime, func_get_args());
    }

    /**
     * Find a model by its specified column and value.
     *
     * @param mixed    $column
     * @param mixed    $value
     * @param string[] $columns
     *
     * @return Eloquent
     */
    public function findBy($column, $value, array $columns = ['*'])
    {
        return $this->where([$column => $value], '=', null, 'and', $columns)->first();
    }

    /**
     * Find a model by its specified columns and values.
     *
     * @param array    $wheres
     * @param string[] $columns
     *
     * @return Eloquent
     */
    public function findWhere(array $wheres, array $columns = ['*'])
    {
        return $this->where($wheres, '=', null, 'and', $columns)->first();
    }
}
