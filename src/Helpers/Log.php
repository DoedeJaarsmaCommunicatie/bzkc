<?php
namespace App\Helpers;

use Carbon\Carbon;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

class Log
{
    /**
     * Send a debug message to the log.
     *
     * @param string $message The message to log.
     * @param array  $context Additional context to log.
     *
     * @return void
     */
    public static function debug($message, $context = []): void
    {
        static::log('debug', $message, $context);
    }
    
    /**
     * Send an info message to the log.
     *
     * @param string $message The message to log.
     * @param array  $context Additional context to log.
     *
     * @return void
     */
    public static function info($message, $context = []): void
    {
        static::log('info', $message, $context);
    }
    
    /**
     * Send a notice message to the log.
     *
     * @param string $message The message to log.
     * @param array  $context Additional context to log.
     *
     * @return void
     */
    public static function notice($message, $context = []): void
    {
        static::log('notice', $message, $context);
    }
    
    /**
     * Send a warning message to the log.
     *
     * @param string $message The message to log.
     * @param array  $context Additional context to log.
     *
     * @return void
     */
    public static function warning($message, $context = []): void
    {
        static::log('warning', $message, $context);
    }
    
    /**
     * Send an error message to the log.
     *
     * @param string $message The message to log.
     * @param array  $context Additional context to log.
     *
     * @return void
     */
    public static function error($message, $context = []): void
    {
        static::log('error', $message, $context);
    }
    
    /**
     * Send a critical message to the log.
     *
     * @param string $message The message to log.
     * @param array  $context Additional context to log.
     *
     * @return void
     */
    public static function critical($message, $context = []): void
    {
        static::log('critical', $message, $context);
    }
    
    /**
     * Send a alert message to the log.
     *
     * @param string $message The message to log.
     * @param array  $context Additional context to log.
     *
     * @return void
     */
    public static function alert($message, $context = []): void
    {
        static::log('alert', $message, $context);
    }
    
    /**
     * Send a emergency message to the log.
     *
     * @param string $message The message to log.
     * @param array  $context Additional context to log.
     *
     * @return void
     */
    public static function emergency($message, $context = []): void
    {
        static::log('emergency', $message, $context);
    }
    
    /**
     * [Only for internal use]. Logs a message
     *
     * @param string $type The message type.
     * @param string $message the message to log.
     * @param array  $context the context to log.
     */
    protected static function log($type, $message, $context = []): void
    {
        $file = static::logFile();
        $logger = new Logger(Errors::isDebug() ? 'DEBUG' : 'PRODUCTION');
        
        try {
            $logger->pushHandler(new StreamHandler($file));
            $logger->pushProcessor(new PsrLogMessageProcessor(null, true));
        } catch (\Exception $e) {
            // Do nothing. Not even logging errors works now.
        }
        
        $logger->$type($message, $context);
    }
    
    private static function logDirectory(): string
    {
        $dir = wp_get_upload_dir()['basedir'] . '/logs';
        
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        return $dir;
    }
    
    private static function logFile(): string
    {
        $dir = static::logDirectory();
        $date = Carbon::today()->format('Y_m_d');
        return sprintf('%1$s/wired_%2$s.log', $dir, $date);
    }
}
