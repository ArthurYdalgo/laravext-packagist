<?php

namespace Laravext;

use Closure;
use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Laravext\Router as LaravextRouter;
use SplFileInfo;

class LaravextServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        $this->registerConsoleCommands();
        
        $this->publishes([
            __DIR__ . '/../config/config.php' => config_path('laravext.php'),
        ], 'laravext-config');
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        // Automatically apply the package configuration
        $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'laravext');

        // Register the main class to use with the facade
        $this->app->singleton(ResponseFactory::class);

        $this->registerBladeDirectives();
        $this->registerRequestMacro();
        $this->registerRouterMacro();
    }

    protected function registerRequestMacro(): void
    {
        Request::macro('laravext', function () {
            return (bool) $this->header('X-Laravext');
        });
    }

    protected function registerConsoleCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            Commands\StartSsr::class,
            Commands\StopSsr::class,
        ]);
    }

    protected function registerBladeDirectives(): void
    {
        $this->callAfterResolving('blade.compiler', function ($blade) {
            $blade->directive('laravextScripts', [Directive::class, 'laravextScripts']);
            $blade->directive('nexus', [Directive::class, 'nexus']);
            $blade->directive('startNexus', [Directive::class, 'startNexus']);
            $blade->directive('endNexus', [Directive::class, 'endNexus']);
            $blade->directive('strand', [Directive::class, 'strand']);
            $blade->directive('startStrand', [Directive::class, 'startStrand']);
            $blade->directive('endStrand', [Directive::class, 'endStrand']);
        });
    }

    protected function registerRouterMacro(): void
    {
        Router::macro('nexus', function ($uri = '{nexusSlug?}', $action_or_page = null, $root_view = null, ...$parameters) {
            $custom_route_registration_method = $parameters['route_registration_method'] ?? config('laravext.route_registration_method');

            // Detect if it is a custom controller, closure, or array
            $is_custom_action = is_callable($action_or_page) || is_array($action_or_page) || (is_string($action_or_page) && class_exists($action_or_page));

            $action = $is_custom_action ? $action_or_page : function () use ($uri, $action_or_page, $root_view, $parameters) {
                if (isset($parameters['merge_with_existing_route']) && ! boolval($parameters['merge_with_existing_route'])) {
                    \Laravext\ResponseFactory::clearUriCache($uri);
                }
                
                return nexus($action_or_page)->rootView($root_view)->render();
            };

            $method = $custom_route_registration_method ?: 'match';
            $args = $custom_route_registration_method ? [$uri, $action] : [['GET', 'HEAD'], $uri, $action];

            // Register the base route (this serves as the default locale's route)
            $base_route = $this->{$method}(...$args);

            // Return standard route if localization is disabled
            if (! config('laravext.localization.enabled', false)) {
                return $base_route;
            }

            // Register localized overrides
            $locales = config('laravext.localization.locales', config('app.locales', [config('app.locale')]));
            $default_locale = config('laravext.localization.default_locale', config('app.locale', 'en'));
            $translation_file = config('laravext.localization.translation_file', 'routes');
            $add_prefix = config('laravext.localization.add_prefix_to_uri', false);

            $localizer_class = config('laravext.localization.route_localizer', \Laravext\Localization\RouteLocalizer::class);
            $localizer = app($localizer_class);

            $localized_routes = [];
            
            foreach ($locales as $locale) {
                // The base route handles the default locale. We skip it here.
                if ($locale === $default_locale) {
                    continue;
                }
                
                $translated_uri = \Laravext\Router::translateUriSegments($uri, $locale, $translation_file);

                // Handle non-default locales ONLY IF explicitly translated
                if ($translated_uri !== null) {
                    $localized_uri = $add_prefix ? "{$locale}/{$translated_uri}" : $translated_uri;
                    $localized_uri = \Laravext\Router::trimSurroundingSlashes($localized_uri);
                    
                    $args_localized = $custom_route_registration_method ? [$localized_uri, $action] : [['GET', 'HEAD'], $localized_uri, $action];
                    $localized_routes[$locale] = $this->{$method}(...$args_localized);
                }
            }

            // Wrap in the Proxy to handle chaining
            return new \Laravext\Localization\LocalizedRouteProxy($base_route, $localized_routes, $localizer, $parameters);
        });

        Router::macro('laravext', function ($uri = null, $route_group_attributes = [], $root_view = null, ...$parameters) {
            unset($route_group_attributes['prefix']);
            $nexus_directory = config('laravext.nexus_directory');

            LaravextRouter::laravextRouteGroup($this, $uri, $nexus_directory, $route_group_attributes, $root_view, ...$parameters);
        });
    }
}