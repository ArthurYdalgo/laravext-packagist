<?php

namespace Laravext\Localization;

use Illuminate\Support\Str;
use Laravext\Router;

class RouteLocalizer
{
    /**
     * Generates the localized route name.
     */
    public function generateRouteName($locale, $uri, $original_name, $cache_content)
    {
        return $original_name ? "{$locale}.{$original_name}" : null;
    }
}