<?php
if (!defined('IN_MYBB')) die('No direct access');

/**
 * Owner-side port for a FUTURE audited publication coordinator, never an HTTP grant.
 * No production implementation is shipped. The coordinator must verify all engines,
 * an active transaction on the supplied writer, and pre-acquire AA owner locks in
 * its audited global lock order. Locks live until the outer outcome, not hook return.
 */
interface AF_AA_PostMaskUnitOfWork
{
    public function assertActiveWriterAndOwnerLock(object $database, int $uid): void;
    /** In-memory invalidation on BOTH commit and rollback; not a delivery outbox. */
    public function afterCompletion(callable $invalidate): void;
    public function markRollbackOnly(): void;
}

/** Bound to one real handler object; request fields/settings cannot activate it. */
final class AF_AA_PostMaskScope
{
    private static ?object $handler=null;
    private static ?AF_AA_PostMaskUnitOfWork $unit=null;

    public static function within(AF_AA_PostMaskUnitOfWork $unit, object $handler, callable $publish): mixed
    {
        if(self::$handler!==null)throw new RuntimeException('AA_MASK_NESTED_SCOPE');
        self::$handler=$handler;self::$unit=$unit;
        try { return $publish(); }
        catch(Throwable $e) { $unit->markRollbackOnly();throw $e; }
        finally { self::$handler=null;self::$unit=null; }
    }
    public static function forHandler(object $handler): ?AF_AA_PostMaskUnitOfWork
    {
        if(self::$handler!==null && self::$handler!==$handler)throw new RuntimeException('AA_MASK_HANDLER_SCOPE_MISMATCH');
        return self::$unit;
    }
}
