<?php
namespace App\Helpers;

use App\Exceptions\CookieNotSetException;

class Cookie
{
    /**
     * @param string $name The name of the cookie.
     * @param string $value the value of the cookie.
     * @param int    $exp [optional] <p>
     * This sets the expiration date. use integer to determine the seconds from now.
     * <h2 style="color: #cc0000;">Do not enter epoch value</h2>
     * </p>
     * @param string $path The path on the server in which the cookie will be available on.
     * @param string $domain The (sub)domain that the cookie is available to.
     *
     * @see https://www.php.net/manual/en/function.setcookie.php
     */
    public static function setCookie($name, $value, $exp = 86400, $path = '/', $domain = ''): void
    {
        setcookie(
            $name,
            $value,
            time() + $exp,
            $path,
            $domain
        );
    }
    
    /**
     * Checks if a cookie exists
     *
     * @param string $cookie_name The name of the cookie.
     *
     * @return bool
     */
    public static function hasCookie($cookie_name): bool
    {
        return isset($_COOKIE[$cookie_name]);
    }
    
    /**
     * Gets a cookie from the global $_COOKIE
     *
     * @param string $cookie_name The name of the cookie.
     *
     * @return mixed
     */
    public static function getCookie($cookie_name)
    {
        return $_COOKIE[$cookie_name];
    }
}
