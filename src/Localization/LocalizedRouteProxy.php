<?php

namespace Laravext\Localization;

use Illuminate\Support\Facades\Cache;

class LocalizedRouteProxy
{
    protected $baseRoute;
    protected $localizedRoutes;
    protected $localizer;
    protected $cacheContent;

    public function __construct($baseRoute, $localizedRoutes, $localizer, $cacheContent = [])
    {
        $this->baseRoute = $baseRoute;
        $this->localizedRoutes = $localizedRoutes;
        $this->localizer = $localizer;
        $this->cacheContent = $cacheContent;
    }

    /**
     * Seeds the initial Laravext page cache for all routes.
     */
    public function cacheData($driver, $content)
    {
        $this->updateCacheForRoute($this->baseRoute->uri(), $content, $driver);

        foreach ($this->localizedRoutes as $route) {
            $this->updateCacheForRoute($route->uri(), $content, $driver);
        }

        return $this;
    }

    /**
     * Intercepts ->name() to apply localized names and update the cache.
     */
    public function name($name)
    {
        $this->baseRoute->name($name);
        $this->updateCacheForRoute($this->baseRoute->uri(), ['name' => $name]);

        foreach ($this->localizedRoutes as $localeKey => $route) {
            // Strip out the '_redundant' suffix if it's the redundant default route
            $locale = explode('_', $localeKey)[0];
            
            $localizedName = $this->localizer->generateRouteName($locale, $route->uri(), $name, $this->cacheContent);
            
            if ($localizedName) {
                $route->name($localizedName);
                $this->updateCacheForRoute($route->uri(), ['name' => $localizedName]);
            }
        }

        return $this;
    }

    /**
     * Forwards all other route chaining methods (middleware, where, etc.) to ALL routes.
     */
    public function __call($method, $parameters)
    {
        $this->baseRoute->{$method}(...$parameters);

        foreach ($this->localizedRoutes as $route) {
            $route->{$method}(...$parameters);
        }

        return $this;
    }

    protected function updateCacheForRoute($uri, $mergeData, $driver = null)
    {
        $driver = $driver ?? config('laravext.router_cache_driver', 'file');
        $cacheKey = "laravext-uri:{$uri}-cache";
        
        $existing = Cache::store($driver)->get($cacheKey) ?: [];
        Cache::store($driver)->put($cacheKey, array_merge($existing, $mergeData));
    }
}