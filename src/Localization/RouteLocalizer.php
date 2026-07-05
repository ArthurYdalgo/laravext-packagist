<?php

namespace Laravext\Localization;

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