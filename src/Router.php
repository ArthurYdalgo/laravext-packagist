<?php

namespace Laravext;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use SplFileInfo;
use Illuminate\Support\Str;

class Router
{
    public static $convention_extensions = ['jsx', 'tsx', 'js', 'ts', 'vue'];

    /**
     * Parses the directory and recursively parses the children directories to return a routing tree.
     */
    public static function parseDirectory($directory_path, $root, $parent_conventions = [])
    {
        $root = self::trimEndingSlash(self::replaceReverseSlashes($root));
        $directory_path = self::trimEndingSlash(self::replaceReverseSlashes($directory_path));

        if (! File::isDirectory($directory_path)) {
            return [
                'name' => null,
                'path' => null,
                'relative_path' => null,
                'conventions' => [],
                'is_directory_a_group' => false,
                'is_directory_group_also_a_segment' => false,
                'children' => [],
            ];
        }

        $name = str($directory_path)->replaceFirst($root, '')->explode('/')->last();
        $is_directory_a_group = preg_match('/\([\w-]+\)$/', $name) || preg_match('/\(\([\w-]+\)\)$/', $name);
        $is_directory_group_also_a_segment = (bool) preg_match('/\(\([\w-]+\)\)$/', $name);

        $conventions = array_merge($parent_conventions, self::parseDirectoryConventions($directory_path, $root));

        $children_directories = [];

        foreach (File::directories($directory_path) as $child_directory) {
            $cascated_conventions = $is_directory_a_group ? $conventions : $parent_conventions;

            if ($is_directory_a_group) {
                unset($cascated_conventions['page']);
            }

            $parsed_children_directory = self::parseDirectory($child_directory, $root, $cascated_conventions);

            $children_directories[] = $parsed_children_directory;
        }

        $relative_path = self::generateRelativePath($directory_path, $root);

        return [
            'name' =>  str($directory_path)->replaceFirst($root, '')->explode('/')->last(),
            'path' => $directory_path,
            'relative_path' => $relative_path,
            'conventions' => $conventions,
            'is_directory_a_group' => $is_directory_a_group,
            'is_directory_group_also_a_segment' => $is_directory_group_also_a_segment,
            'children' => $children_directories,
        ];
    }

    /**
     * Parses a directory to verify and apply Next.js-style file conventions.
     */
    public static function parseDirectoryConventions($directory_path, $root)
    {
        $root = self::replaceReverseSlashes($root);
        $directory_path = self::replaceReverseSlashes($directory_path);

        $root = self::trimEndingSlash($root);
        $directory_path = self::trimEndingSlash($directory_path);

        $files = File::files($directory_path);
        $convention_patterns = self::generateFileConventionPatterns();

        $conventions = [];
        $relative_path = self::generateRelativePath($directory_path, $root);

        foreach ($files as $file) {
            foreach ($convention_patterns as $convention => $pattern) {
                if (preg_match($pattern, $file->getFilename())) {
                    $conventions[$convention] = collect([$relative_path, $file->getFilename()])->filter()->implode('/');
                    break;
                }
            }

            if (preg_match('/loading\.html$/', $file->getFilename())) {
                $conventions['server_skeleton'] = File::get($file->getPathname());
            }
        }

        return $conventions;
    }

    /**
     * Recursively parses directories and caches the resulting routing tree.
     */
    public static function getNexusDirectories($nexus_directory, $cached = true, $cache_driver = 'file')
    {
        $cache_key = self::generateRoutingTreeCacheKey($nexus_directory);

        if (! $cached) {
            Cache::store($cache_driver)->forget($cache_key);
        }

        return Cache::store($cache_driver)->rememberForever($cache_key, function () use ($nexus_directory) {
            return self::parseDirectory($nexus_directory, $nexus_directory);
        });
    }

    /**
     * Generates route segments from a relative path, handling parenthesis groupings.
     */
    public static function generateRouteSegments($relative_path, $router_is_case_sensitive = null)
    {
        $router_is_case_sensitive ??= config('laravext.router_is_case_sensitive', false);
        
        return str($relative_path)->when(! $router_is_case_sensitive, function ($str) {
            return $str->lower();
        })->explode('/')->filter(function ($segment) {
            return (! preg_match('/\([\w-]+\)$/', $segment) || preg_match('/\(\([\w-]+\)\)$/', $segment));
        })->map(function ($segment) {
            if (preg_match('/\(\([\w-]+\)\)$/', $segment)) {
                return str($segment)->replaceFirst("((", "")->replaceLast("))", "");
            }
            return $segment;
        });
    }

    /**
     * Translates the URI based on an exact match from the language files.
     * Returns null if no explicit translation is found.
     */
    public static function translateUriSegments($uri, $locale, $translation_file)
    {
        // Always allow the root URI to be localized without needing an explicit translation
        if (empty($uri) || $uri === '/') {
            return $uri;
        }

        $full_translation_key = "{$translation_file}.{$uri}";
        $translated_full = trans($full_translation_key, [], $locale);

        // If an exact match is found in the lang array, return it
        if ($translated_full !== $full_translation_key) {
            return $translated_full;
        }

        // Return null to signal that this route should NOT be localized for this language
        return null;
    }

    /**
     * Defines the Nexus routes, recursively defining any children Nexus routes.
     */
    public static function laravextNexusRoutes(&$router, $directory, $uri, $root_view = null, ...$parameters)
    {
        $router_route_name_is_enabled = config('laravext.router_route_naming_is_enabled', true);
        $router_cache_driver = config('laravext.router_cache_driver', 'file');

        $page = $directory['conventions']['page'] ?? null;

        if ($page) {
            $segments = self::generateRouteSegments($directory['relative_path']);
            $route_uri = $segments->implode('/');
            $base_uri = $uri ? self::trimStartingSlash($uri) : null;

            if (! $base_uri || ($base_uri && str($route_uri)->startsWith($base_uri))) {
                $name = $router_route_name_is_enabled ? $segments->map(function ($segment) {
                    return str($segment)->remove(["{", "}", "?"]);
                })->join('.') : null;

                $cache_content = [
                    'server_skeleton' => $directory['conventions']['server_skeleton'] ?? null,
                    'middleware' => $directory['conventions']['middleware'] ?? null,
                    'layout' => $directory['conventions']['layout'] ?? null,
                    'error' => $directory['conventions']['error'] ?? null,
                    'page' => $page,
                    'uri' => $uri,
                    'root_view' => $root_view
                ];

                if ($route_uri == '') {
                    $route_uri = '/';
                }

                // Protect against named parameter overwrites in PHP 8+ unpacking
                $macro_parameters = array_merge($parameters, [
                    'server_skeleton' => $cache_content['server_skeleton'],
                    'middleware'      => $cache_content['middleware'],
                    'layout'          => $cache_content['layout'],
                    'error'           => $cache_content['error'],
                ]);

                // Route::nexus handles base, localizations, and returns the Proxy (or standard route)
                $route_proxy = $router->nexus(
                    $route_uri,
                    $page,
                    $root_view,
                    ...$macro_parameters
                );

                // Apply initial cache across all generated routes
                if (method_exists($route_proxy, 'cacheData')) {
                    $route_proxy->cacheData($router_cache_driver, $cache_content);
                } else {
                    $cache_content['uri'] = $route_uri;
                    Cache::store($router_cache_driver)->put("laravext-uri:{$route_uri}-cache", $cache_content);
                }

                // Apply route names (Proxy will dynamically cascade translated names)
                if ($name) {
                    $route_proxy->name($name);
                }
            }
        }

        foreach ($directory['children'] as $child_directory) {
            self::laravextNexusRoutes($router, $child_directory, $uri, $root_view, ...$parameters);
        }
    }

    /**
     * Defines a group of routes containing Nexus routes.
     */
    public static function laravextRouteGroup(&$router, $uri, $nexus_directory, $route_group_attributes = [], $root_view = null, ...$parameters)
    {
        $router_cache_driver = config('laravext.router_cache_driver', 'file');
        $router_cache_is_enabled = config('laravext.router_cache_is_enabled', true);

        $nexus_directories = self::getNexusDirectories($nexus_directory, $router_cache_is_enabled, $router_cache_driver);

        return $router->group($route_group_attributes, function () use ($uri, $router, $root_view, $nexus_directories, $parameters) {
            self::laravextNexusRoutes($router, $nexus_directories, $uri, $root_view, ...$parameters);
        });
    }

    // Helpers

    public static function generateRoutingTreeCacheKey($nexus_directory)
    {
        $version = self::version();

        return str("laravext-router-routing-tree")->when($version, function ($key, $version) {
            return $key->append(":{$version}");
        })->toString();
    }

    public static function version()
    {
        if (config('laravext.version')) {
            return config('laravext.version');
        }
        if (config('app.asset_url')) {
            return md5(config('app.asset_url'));
        }
        if (file_exists($manifest = public_path('mix-manifest.json'))) {
            return md5_file($manifest);
        }
        if (file_exists($manifest = public_path('build/manifest.json'))) {
            return md5_file($manifest);
        }
        
        return null;
    }

    public static function trimEndingSlash($path)
    {
        return Str::endsWith($path, '/') ? Str::replaceLast('/', '', $path) : $path;
    }

    public static function trimStartingSlash($path)
    {
        return Str::startsWith($path, '/') ? Str::replaceFirst('/', '', $path) : $path;
    }

    public static function trimSurroundingSlashes($path)
    {
        return self::trimStartingSlash(self::trimEndingSlash($path));
    }

    public static function replaceReverseSlashes($path)
    {
        return Str::replace('\\', '/', $path);
    }

    public static function generateRelativePath($directory_path, $root)
    {
        $relative_path = str($directory_path)->replaceFirst($root, '')->toString();
        return str(self::trimStartingSlash($relative_path))->explode('/')->filter()->implode('/');
    }

    public static function generateFileConventionPatterns()
    {
        $file_extensions = config('laravext.file_extensions', self::$convention_extensions);

        $extension_patterns = collect($file_extensions)->map(function ($extension) {
            return "\.{$extension}";
        })->implode('|');

        return [
            'layout' => "/layout({$extension_patterns})$/",
            'middleware' => "/middleware({$extension_patterns})$/",
            'error' => "/error({$extension_patterns})$/",
            'page' => "/page({$extension_patterns})$/",
        ];
    }
}