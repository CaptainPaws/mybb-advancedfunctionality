<?php

if (!defined('IN_MYBB')) { die('No direct access'); }

/** Same MyBB write connection; no second connection or implicit nested commit. */
final class AF_ACS_TransactionManager
{
    private bool $locked = false;
    private static ?WeakMap $settingsScopes = null;

    public function __construct(private mysqli $connection, private string $lockName) {}

    /** MyBB/ATF share the connection: scope ACS settings and restore them exactly. */
    public static function databaseSettings(mysqli $connection, callable $callback): mixed
    {
        self::$settingsScopes ??= new WeakMap;
        if (isset(self::$settingsScopes[$connection])) { return $callback(); }
        try {
            $result = $connection->query('SELECT @@character_set_client AS client,@@character_set_connection AS connection,@@character_set_results AS results,@@collation_connection AS collation,@@session.sql_mode AS sql_mode');
            if (!$result) { throw new RuntimeException('ACS_DB_SESSION_UNAVAILABLE'); }
            $saved = $result->fetch_assoc();
            $clientCharset = $connection->character_set_name();
        } catch (mysqli_sql_exception $error) { throw new RuntimeException('ACS_DB_SESSION_UNAVAILABLE'); }
        self::$settingsScopes[$connection] = true;
        try {
            if (!$connection->set_charset('utf8mb4')) { throw new RuntimeException('ACS_DB_UTF8MB4_UNAVAILABLE'); }
            $modes = $saved['sql_mode'];
            if (!preg_match('/(?:^|,)STRICT_(?:TRANS|ALL)_TABLES(?:,|$)/', $modes)) {
                $modes .= ($modes === '' ? '' : ',').'STRICT_ALL_TABLES';
            }
            self::setSession($connection, 'SET SESSION sql_mode=?', [$modes]);
            return $callback();
        } catch (mysqli_sql_exception $error) { throw new RuntimeException('ACS_DB_SESSION_SETTINGS_FAILED'); }
        finally {
            try {
                if (!$connection->set_charset($clientCharset)) { throw new RuntimeException('ACS_DB_SESSION_RESTORE_FAILED'); }
                self::setSession($connection, 'SET SESSION character_set_client=?,character_set_connection=?,character_set_results=?,collation_connection=?,sql_mode=?', [$saved['client'], $saved['connection'], $saved['results'], $saved['collation'], $saved['sql_mode']]);
            } catch (Throwable $error) { throw new RuntimeException('ACS_DB_SESSION_RESTORE_FAILED'); }
            finally { unset(self::$settingsScopes[$connection]); }
        }
    }

    private static function setSession(mysqli $connection, string $sql, array $values): void
    {
        $statement = $connection->prepare($sql);
        if (!$statement) { throw new RuntimeException('ACS_DB_SESSION_SETTINGS_FAILED'); }
        try {
            if (!$statement->bind_param(str_repeat('s', count($values)), ...$values) || !$statement->execute()) {
                throw new RuntimeException('ACS_DB_SESSION_SETTINGS_FAILED');
            }
        } finally { $statement->close(); }
    }

    public function exclusive(callable $callback): mixed
    {
        return self::databaseSettings($this->connection, fn() => $this->exclusiveWithSettings($callback));
    }

    private function exclusiveWithSettings(callable $callback): mixed
    {
        if ($this->locked) { throw new RuntimeException('ACS_NESTED_MAINTENANCE'); }
        try {
            $stmt = $this->connection->prepare('SELECT GET_LOCK(?, 0)');
            if (!$stmt) { throw new RuntimeException('ACS_LOCK_UNAVAILABLE'); }
            $stmt->bind_param('s', $this->lockName);
            if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('ACS_LOCK_UNAVAILABLE'); }
            $result = $stmt->get_result()->fetch_row();
            $stmt->close();
        } catch (mysqli_sql_exception $error) { throw new RuntimeException('ACS_LOCK_UNAVAILABLE'); }
        if ((string)($result[0] ?? '') !== '1') { throw new RuntimeException('ACS_MAINTENANCE_BUSY'); }
        $this->locked = true;
        try {
            // MySQL/MariaDB reject SET TRANSACTION in an existing transaction.
            // Unlike START TRANSACTION, this guard never commits caller work.
            $result = $this->connection->query('SELECT @@session.autocommit');
            if (!$result) { throw new RuntimeException('ACS_TRANSACTION_ERROR'); }
            $row = $result->fetch_row();
            if ((string)$row[0] !== '1') { throw new RuntimeException('ACS_AMBIENT_TRANSACTION'); }
            if (!$this->connection->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')) { throw new RuntimeException('ACS_AMBIENT_TRANSACTION'); }
            return $callback();
        } catch (mysqli_sql_exception $error) {
            throw new RuntimeException('ACS_TRANSACTION_ERROR', (int)$error->getCode());
        } finally {
            try {
                $stmt = $this->connection->prepare('SELECT RELEASE_LOCK(?)');
                if (!$stmt) { throw new RuntimeException('ACS_LOCK_RELEASE_UNKNOWN'); }
                $stmt->bind_param('s', $this->lockName);
                if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('ACS_LOCK_RELEASE_UNKNOWN'); }
                $released = $stmt->get_result()->fetch_row();
                $stmt->close();
                if ((string)($released[0] ?? '') !== '1') { throw new RuntimeException('ACS_LOCK_RELEASE_UNKNOWN'); }
            } catch (mysqli_sql_exception $error) { throw new RuntimeException('ACS_LOCK_RELEASE_UNKNOWN'); }
            finally { $this->locked = false; }
        }
    }

    public function run(callable $callback): mixed
    {
        return $this->exclusive(function () use ($callback) {
            if (!$this->connection->begin_transaction()) { throw new RuntimeException('ACS_TRANSACTION_ERROR'); }
            try {
                $result = $callback();
                if (!$this->connection->commit()) { throw new RuntimeException('ACS_COMMIT_UNKNOWN'); }
                return $result;
            } catch (Throwable $error) {
                if (!$this->connection->rollback()) { throw new RuntimeException('ACS_ROLLBACK_UNKNOWN'); }
                throw $error;
            }
        });
    }
}
