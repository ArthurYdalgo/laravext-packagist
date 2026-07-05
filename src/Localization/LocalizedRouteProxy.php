<?php

namespace Laravext\Localization;

use Illuminate\Support\Facades\Cache;

class LocalizedRouteProxy
{
    protected $base_route;
    protected $localized_routes;
    protected $localizer;
    protected $cache_content;

    public function __construct($base_route, $localized_routes, $localizer, $cache_content = [])
    {
        $this->base_route = $base_route;
        $this->localized_routes = $localized_routes;
        $this->localizer = $localizer;
        $this->cache_content = $cache_content;
    }

    /**
     * Seeds the initial Laravext page cache for all routes.
     */
    public function cacheData($driver, $content)
    {
        $this->updateCacheForRoute($this->base_route->uri(), $content, $driver);

        foreach ($this->localized_routes as $route) {
            $this->updateCacheForRoute($route->uri(), $content, $driver);
        }

        return $this;
    }

    /**
     * Intercepts ->name() to apply localized names and update the cache.
     */
    public function name($name)
    {
        $this->base_route->name($name);
        $this->updateCacheForRoute($this->base_route->uri(), ['name' => $name]);

        foreach ($this->localized_routes as $locale => $route) {
            $localized_name = $this->localizer->generateRouteName($locale, $route->uri(), $name, $this->cache_content);
            
            if ($localized_name) {
                $route->name($localized_name);
                $this->updateCacheForRoute($route->uri(), ['name' => $localized_name]);
            }
        }

        return $this;
    }

    /**
     * Forwards all other route chaining methods (middleware, where, etc.) to ALL routes.
     */
    public function __call($method, $parameters)
    {
        $this->base_route->{$method}(...$parameters);

        foreach ($this->localized_routes as $route) {
            $route->{$method}(...$parameters);
        }

        return $this;
    }

    protected function updateCacheForRoute($uri, $merge_data, $driver = null)
    {
        $driver = $driver ?? config('laravext.router_cache_driver', 'file');
        $cache_key = "laravext-uri:{$uri}-cache";
        
        $existing = Cache::store($driver)->get($cache_key) ?: [];
        Cache::store($driver)->put($cache_key, array_merge($existing, $merge_data));
    }
}