<?php

if (!defined('IN_MYBB')) { die('No direct access'); }

/** Same MyBB write connection; no second connection or implicit nested commit. */
final class AF_ACS_TransactionManager
{
    private bool $locked = false;

    public function __construct(private mysqli $connection, private string $lockName) {}

    public function exclusive(callable $callback): mixed
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
