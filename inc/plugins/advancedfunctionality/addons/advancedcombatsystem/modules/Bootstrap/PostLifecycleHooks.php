<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Lightweight native binding. SQL stack is loaded only when an actual lifecycle hook fires. */
final class AF_ACS_PostLifecycleHooks
{
    public const MODERATION_SHA='891913789e5a20adceff7bbebcb68ae660fe04dc9653afe81e3cc58bf70fd4cc';
    private static array $pending=[];
    private static bool $shutdown=false;

    public static function supported(): bool
    {
        global $mybb;
        $file=MYBB_ROOT.'inc/class_moderation.php';
        return ($mybb->version??'')==='1.8.41' && is_file($file) && hash_equals(self::MODERATION_SHA,hash_file('sha256',$file));
    }

    public static function register(object $plugins): bool
    {
        if (!self::supported()) { return false; }
        foreach (['approve_posts','unapprove_posts','soft_delete_posts','restore_posts','delete_post'] as $hook) { $plugins->add_hook('class_moderation_'.$hook,[new AF_ACS_NativePostObserver('class_moderation_'.$hook,'posts'),'observe'],100); }
        foreach (['approve_threads','unapprove_threads','soft_delete_threads','restore_threads','delete_thread_start','delete_thread'] as $hook) { $plugins->add_hook('class_moderation_'.$hook,[new AF_ACS_NativePostObserver('class_moderation_'.$hook,'threads'),'observe'],100); }
        foreach (['move_simple','move_thread_redirect','move_threads','merge_threads'] as $hook) { $plugins->add_hook('class_moderation_'.$hook,[new AF_ACS_NativePostObserver('class_moderation_'.$hook,'threadArguments'),'observe'],100); }
        foreach (['split_posts','merge_posts'] as $hook) { $plugins->add_hook('class_moderation_'.$hook,[new AF_ACS_NativePostObserver('class_moderation_'.$hook,'postArguments'),'observe'],100); }
        return true;
    }

    public static function receive(string $event, string $shape, mixed $args): void
    {
        if ($shape==='posts') { self::capture('pid',$args,$event); }
        elseif ($shape==='threads') { self::capture('tid',$args,$event); }
        elseif ($shape==='threadArguments') { self::capture('tid',$args['tids']??[$args['tid']??0,$args['mergetid']??0],$event); }
        elseif ($shape==='postArguments') { self::capture('pid',$args['pids']??[],$event); }
    }

    private static function capture(string $column, mixed $ids, string $event): void
    {
        global $db;
        try {
            if (!empty($GLOBALS['af_acs_deactivated']) || !af_is_addon_enabled('advancedcombatsystem')) { return; }
            require_once __DIR__.'/../Infrastructure/SubmissionStore.php';
            $store=new AF_ACS_SubmissionStore($db);$store->assertMybbBoundary($db);
            $invalidate=(bool)preg_match('/move|merge|split|delete_thread_start/',$event);
            foreach (array_unique((array)$ids) as $id) {
                if (!preg_match('/\A[1-9][0-9]*\z/D',(string)$id)) { continue; }
                foreach ($store->linkedSubmissions($column,(string)$id) as $linked) {
                    self::$pending[$linked['submission_id']]=$event;
                    if (!self::$shutdown) { self::$shutdown=true;register_shutdown_function([self::class,'flush']); }
                    // Pre-move/delete hooks invalidate first; never wait until shutdown to block commit.
                    $store->observePost($linked['submission_id'],$event,$invalidate);
                }
            }
        } catch (Throwable $error) { error_log('ACS native post lifecycle observation failed'); }
    }

    /** Mixed/pre-write branches are reread after normal MyBB side effects. Never replays MyBB. */
    public static function flush(): void
    {
        global $db;
        if (!self::$pending) { return; }
        $pending=self::$pending;self::$pending=[];
        try {
            if (!empty($GLOBALS['af_acs_deactivated']) || !af_is_addon_enabled('advancedcombatsystem')) { return; }
            require_once __DIR__.'/../Infrastructure/SubmissionStore.php';
            $store=new AF_ACS_SubmissionStore($db);$store->assertMybbBoundary($db);
            foreach ($pending as $id=>$event) {
                try { $store->observePost($id,$event,false); }
                catch (Throwable $error) { error_log('ACS native post lifecycle reconciliation failed'); }
            }
        } catch (Throwable $error) { error_log('ACS native post lifecycle boundary unavailable'); }
    }
}

/** MyBB current_hook is not restored after nested hooks; retain our own immutable event name. */
final readonly class AF_ACS_NativePostObserver
{
    public function __construct(private string $event, private string $shape) {}
    public function observe(&$args): void { AF_ACS_PostLifecycleHooks::receive($this->event,$this->shape,$args); }
}
