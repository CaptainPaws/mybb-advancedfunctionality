<?php

if (!defined('IN_MYBB')) { die('No direct access'); }

/** Body-free context for ACS errors that cross the ACP lifecycle boundary. */
final class AF_ACS_LifecycleFailure extends RuntimeException
{
    public function __construct(Throwable $error, public readonly string $phase, public readonly array $schema = [])
    {
        parent::__construct(self::safeCode($error));
    }

    public static function safeCode(Throwable $error): string
    {
        $message = $error->getMessage();
        if ($message === 'ACS_DATABASE_ERROR' || $error instanceof mysqli_sql_exception) {
            $errno = (int)$error->getCode();
            return match ($errno) {
                1044, 1045, 1142, 1143, 1227 => 'ACS_DB_PERMISSION_DENIED',
                1046 => 'ACS_DB_SCHEMA_NOT_SELECTED',
                1054 => 'ACS_DB_COLUMN_UNKNOWN',
                1064 => 'ACS_DB_STATEMENT_SYNTAX',
                1146 => 'ACS_DB_TABLE_UNAVAILABLE',
                1205 => 'ACS_DB_LOCK_TIMEOUT',
                1213 => 'ACS_DB_DEADLOCK',
                2006, 2013 => 'ACS_DB_CONNECTION_LOST',
                default => $errno > 0 && $errno <= 65535 ? 'ACS_DB_ERRNO_'.$errno : 'ACS_DATABASE_ERROR',
            };
        }
        return preg_match('/\AACS_[A-Z0-9_]{1,90}\z/D', $message) ? $message : 'ACS_LIFECYCLE_UNEXPECTED';
    }
}
