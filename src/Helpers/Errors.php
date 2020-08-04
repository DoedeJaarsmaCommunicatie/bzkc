<?php
namespace App\Helpers;

class Errors
{
    /**
     * Checks if WP_DEBUG is enabled.
     *
     * @return bool
     */
    public static function isDebug(): bool
    {
        return defined('WP_DEBUG') && WP_DEBUG;
    }
    
    /**
     * Checks if debug is displayed in HTML
     *
     * @return bool
     */
    public static function canShowDebug(): bool
    {
        return defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY;
    }
    
    /**
     * Checks if WP_SCRIPT_DEBUG is enabled.
     *
     * @return bool
     */
    public static function isScriptDebug(): bool
    {
        return defined('WP_SCRIPT_DEBUG') && WP_SCRIPT_DEBUG;
    }
}
