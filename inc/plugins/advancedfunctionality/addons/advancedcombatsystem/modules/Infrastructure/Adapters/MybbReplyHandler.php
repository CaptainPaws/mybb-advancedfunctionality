<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Native logic is retained. Refuse merging, including a race after preflight; never force a fresh INSERT. */
final class AF_ACS_MybbReplyHandler extends PostDataHandler
{
    public ?Closure $acsBoundary = null;

    function verify_post_merge($simple_mode = false)
    {
        if ($this->acsBoundary !== null) { ($this->acsBoundary)(); }
        $result = parent::verify_post_merge($simple_mode);
        if ($result !== true) { throw new RuntimeException('ACS_MYBB_MERGE_UNSUPPORTED'); }
        return $result;
    }

    public function assertFreshReply(string $uid, string $tid, string $fid): void
    {
        if ($this->method !== 'insert' || $this->admin_override || !empty($this->data['pid']) || !empty($this->data['savedraft'])
            || !empty($this->data['modoptions']) || (string)$this->data['uid'] !== $uid
            || (string)$this->data['tid'] !== $tid || (string)$this->data['fid'] !== $fid) {
            throw new RuntimeException('ACS_MYBB_BRANCH_UNSUPPORTED');
        }
    }
}
