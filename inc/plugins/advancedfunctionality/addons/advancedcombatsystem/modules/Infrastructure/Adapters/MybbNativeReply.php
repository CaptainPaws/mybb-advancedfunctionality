<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Bounded authenticated reply path; the real handler owns insertion and native side effects. */
final class AF_ACS_MybbNativeReply
{
    public const CORE = [
        'newreply.php'=>'affb30736516ac15f7597424b27df39c074093bbed8922e54e121bda9fdc4f43',
        'inc/datahandlers/post.php'=>'9d7a5aee7cf100bff0cbd918934b16524f3d556e94ced4719bef97622c6a5d13',
        'inc/db_mysqli.php'=>'99f7077ef3dd5c2032e9a690dfd50f4b707147d7ec43621ed5bc875cb8edde4a',
        'inc/class_core.php'=>'6fe9f32d470218e4fc0b15e9631007d9108c1e2cae53f8abf1862ec771488d7b',
    ];

    public function __construct(private object $database, private Closure $boundary) {}

    public function bootstrap(): void
    {
        global $mybb;
        if (!defined('THIS_SCRIPT') || THIS_SCRIPT !== 'newreply.php') { throw new RuntimeException('ACS_MYBB_SURFACE_UNSUPPORTED'); }
        if (($mybb->version ?? '') !== '1.8.41') { throw new RuntimeException('ACS_MYBB_CORE_UNVERIFIED'); }
        foreach (self::CORE as $file=>$hash) {
            if (!is_file(MYBB_ROOT.$file) || !hash_equals($hash,hash_file('sha256',MYBB_ROOT.$file))) {
                throw new RuntimeException('ACS_MYBB_CORE_UNVERIFIED');
            }
        }
        require_once MYBB_ROOT.'inc/datahandler.php';
        require_once MYBB_ROOT.'inc/datahandlers/post.php';
        if (realpath((new ReflectionClass('PostDataHandler'))->getFileName()) !== realpath(MYBB_ROOT.'inc/datahandlers/post.php')) {
            throw new RuntimeException('ACS_MYBB_CORE_UNVERIFIED');
        }
        require_once __DIR__.'/MybbReplyHandler.php';
    }

    /** Mirror newreply globals for hooks, preserving the original request and cosmetic mask fields. */
    public function request(AF_ACS_MybbPostContext $context, array $topic, callable $callback): AF_ACS_MybbPostResult
    {
        global $mybb;
        $names=['thread','forum','forumpermissions','tid','fid','pid','uid','username','post','posthandler','postinfo','visible','closed'];
        $saved=[]; foreach ($names as $name) { $saved[$name]=[array_key_exists($name,$GLOBALS),$GLOBALS[$name] ?? null]; }
        $input=$mybb->input;
        try {
            $GLOBALS['thread']=$topic; $GLOBALS['tid']=(int)$context->submission['tid']; $GLOBALS['fid']=(int)$topic['fid'];
            $GLOBALS['forum']=get_forum((int)$topic['fid']); $GLOBALS['forumpermissions']=forum_permissions((int)$topic['fid']);
            $GLOBALS['pid']=0; $GLOBALS['uid']=(int)$context->submission['author_uid']; $GLOBALS['username']=$mybb->user['username'];
            $snapshot=json_decode($context->submission['sealed_json'],true,64,JSON_THROW_ON_ERROR);
            $mybb->input=array_replace($input,[
                'action'=>'do_newreply','tid'=>(int)$context->submission['tid'],'message'=>$snapshot['prose'],
                'subject'=>$context->options['subject'] ?? '', 'replyto'=>(int)$context->options['replyto'],
                'icon'=>(int)$context->options['icon'],'posthash'=>$context->options['posthash'],
                'postoptions'=>array_intersect_key($context->options,array_flip(['signature','disablesmilies','subscriptionmethod'])),
            ]);
            if ($context->options['quick_reply']) {
                $mybb->input['method']='quickreply';
                $mybb->input['postoptions']['subscriptionmethod']=get_subscription_method((int)$context->submission['tid']);
            }
            return $callback();
        } finally {
            $mybb->input=$input;
            foreach ($saved as $name=>[$exists,$value]) { if ($exists) { $GLOBALS[$name]=$value; } else { unset($GLOBALS[$name]); } }
        }
    }

    public function preflight(array $row, AF_ACS_SessionAccess $access): array
    {
        global $mybb;
        ($this->boundary)(); $access->csrf();
        if ($access->uid() !== $row['author_uid']) { throw new RuntimeException('ACS_MYBB_ACTOR_MISMATCH'); }
        $thread=$access->thread($row['tid']); $access->draftThread($row['tid']);
        $forum=get_forum((int)$thread['fid']);
        if (!$forum || empty($forum['open']) || $forum['type'] !== 'f' || (int)$thread['visible'] !== 1) {
            throw new RuntimeException('ACS_MYBB_TOPIC_UNAVAILABLE');
        }
        // Attachment upload/update/remove and native draft conversion are deliberately unsupported.
        foreach (['pid','savedraft','previewpost','newattachment','updateattachment','attachmentaid','attachmentact'] as $name) {
            if (!empty($mybb->input[$name])) { throw new RuntimeException('ACS_MYBB_BRANCH_UNSUPPORTED'); }
        }
        if (!empty($_FILES)) { throw new RuntimeException('ACS_MYBB_ATTACHMENTS_UNSUPPORTED'); }
        $maximum=(int)($mybb->usergroup['maxposts'] ?? 0);
        if ($maximum > 0) {
            $q=$this->database->simple_select('posts','COUNT(*) AS posts_today',"uid='".(int)$row['author_uid']."' AND visible != '-1' AND dateline>".(TIME_NOW-86400));
            if ((int)$this->database->fetch_field($q,'posts_today') >= $maximum) { throw new RuntimeException('ACS_MYBB_DAILY_LIMIT'); }
        }
        // Native password validation is retained; SessionAccess already returns a typed denial for missing cookies.
        check_forum_password((int)$thread['fid']);
        return $thread;
    }

    public function validate(AF_ACS_MybbPostContext $context, array $topic): AF_ACS_MybbReplyHandler
    {
        global $mybb,$session,$plugins;
        ($this->boundary)();
        $plugins->run_hooks('newreply_do_newreply_start');
        ($this->boundary)();
        // A start hook may transform prose, but cannot change actor/topic or select a different lifecycle.
        if ((string)$mybb->get_input('tid') !== $context->submission['tid'] || !empty($mybb->input['pid'])
            || !empty($mybb->input['savedraft']) || !empty($mybb->input['modoptions']) || $mybb->get_input('action') !== 'do_newreply') {
            throw new RuntimeException('ACS_MYBB_BRANCH_UNSUPPORTED');
        }
        $posthash=$mybb->get_input('posthash');
        if ($posthash !== '' && !preg_match('/\A[a-f0-9]{32}\z/D',$posthash)) { throw new RuntimeException('ACS_MYBB_ATTACHMENTS_UNSUPPORTED'); }
        if ($posthash !== '') {
            $q=$this->database->simple_select('attachments','COUNT(*) AS n',"pid=0 AND posthash='".$this->database->escape_string($posthash)."'");
            if ((int)$this->database->fetch_field($q,'n') !== 0) { throw new RuntimeException('ACS_MYBB_ATTACHMENTS_UNSUPPORTED'); }
        }
        // Native duplicate detection is a refusal only; its PID is NEVER used as submission identity.
        $q=$this->database->simple_select('posts','pid',"uid='".(int)$context->submission['author_uid']."' AND tid='".(int)$topic['tid']."' AND subject='".$this->database->escape_string($mybb->get_input('subject'))."' AND message='".$this->database->escape_string($mybb->get_input('message'))."' AND visible > -1 AND dateline>".(TIME_NOW-600));
        if ($this->database->num_rows($q) > 0) { throw new RuntimeException('ACS_MYBB_NATIVE_DUPLICATE'); }
        $handler=new AF_ACS_MybbReplyHandler('insert'); $handler->acsBoundary=$this->boundary;
        $post=[
            'tid'=>(int)$context->submission['tid'],'fid'=>(int)$topic['fid'],'uid'=>(int)$context->submission['author_uid'],
            'username'=>$mybb->user['username'],'message'=>$mybb->get_input('message'),'subject'=>$mybb->get_input('subject'),
            'replyto'=>(int)$context->options['replyto'],'icon'=>(int)$context->options['icon'],
            'ipaddress'=>$session->packedip,'posthash'=>$posthash,'pid'=>0,'savedraft'=>0,'options'=>$mybb->input['postoptions'],
        ];
        $handler->set_data($post); $GLOBALS['post']=$post; $GLOBALS['posthandler']=$handler;
        $handler->validate_post(); // includes native flood, author/message/image/video/options validation and hooks
        ($this->boundary)();
        require_once MYBB_ROOT.'inc/functions_indicators.php';
        mark_thread_read((int)$topic['tid'],(int)$topic['fid']);
        ($this->boundary)();
        $handler->assertFreshReply($context->submission['author_uid'],$context->submission['tid'],(string)$topic['fid']);
        if ($handler->get_validated() && !$handler->get_errors()) { $handler->verify_post_merge(); }
        return $handler;
    }

    public function finish(array $reply, AF_ACS_MybbReplyHandler $handler): void
    {
        global $plugins;
        ($this->boundary)();
        $GLOBALS['postinfo']=$reply; $GLOBALS['pid']=$reply['pid']; $GLOBALS['visible']=$reply['visible'];
        $GLOBALS['closed']=$reply['closed'] ?? ''; $GLOBALS['post']=$handler->data;
        $plugins->run_hooks('newreply_do_newreply_end');
        ($this->boundary)();
    }
}
