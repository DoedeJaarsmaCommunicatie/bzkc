<?php
namespace App\Exceptions;

use Throwable;

class CookieNotSetException extends \Exception
{
    public function __construct($message, $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
    
    public function __toString()
    {
        $trace = $this->getTrace()[0];
        return sprintf(
            '[%1$s]: %4$s on line %2$s in function %3$s()',
            $trace['file'],
            $trace['line'],
            $trace['function'],
            $this->getMessage()
        );
    }
    
    public function throwWPError(): void
    {
        new \WP_Error($this->getCode(), $this->getMessage(), ['trace' => $this->getTrace()]);
    }
}
