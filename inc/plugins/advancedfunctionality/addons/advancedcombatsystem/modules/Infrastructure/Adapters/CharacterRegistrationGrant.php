<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/SessionAccess.php';
require_once __DIR__.'/../SessionRepository.php';

/** Internal, session-bound read authority for one CharacterSheets identity. */
final class AF_ACS_CharacterRegistrationGrant
{
    private function __construct(
        public readonly string $sessionId,
        public readonly int $actorUid,
        public readonly int $ownerUid,
        public readonly int $sheetId,
        public readonly int $sourceTid
    ) {}

    public static function assertGm(string $sid,int $actor): array
    {
        global $db;
        $access=new AF_ACS_SessionAccess();
        $actorId=(string)$actor;
        $repo=new AF_ACS_SessionRepository($db);
        $session=$repo->session(AF_ACS_Repository::integer($sid));
        if (!af_is_addon_enabled('advancedcombatsystem') || !empty($GLOBALS['af_acs_deactivated'])
            || $access->uid()!==$actorId || ($session['status']??null)!=='active'
            || !AF_ACS_AuthorizationService::sessionGm($session,$repo->roles($sid,$actorId),$actorId)) {
            throw new RuntimeException('ACS_REGISTRATION_DENIED');
        }
        $access->thread($session['tid']);
        return $session;
    }

    public static function forSheet(string $sid,int $actor,array $sheet): self
    {
        $grant=new self($sid,$actor,(int)$sheet['uid'],(int)$sheet['id'],(int)$sheet['tid']);
        $grant->assertRead($grant->ownerUid,$actor);
        return $grant;
    }

    public function assertRead(int $owner,int $actor): void
    {
        global $db;
        if ($owner<=0 || $owner!==$this->ownerUid || $actor!==$this->actorUid || $this->sheetId<=0 || $this->sourceTid<=0) {
            throw new RuntimeException('ACS_REGISTRATION_DENIED');
        }
        $session=self::assertGm($this->sessionId,$actor);
        $access=new AF_ACS_SessionAccess();
        try {
            $source=$access->thread((string)$this->sourceTid,(string)$actor);
        } catch (RuntimeException $e) {
            throw new RuntimeException('ACS_CHARACTER_SOURCE_UNAVAILABLE',0,$e);
        }
        if ((int)$source['visible']!==1) throw new RuntimeException('ACS_CHARACTER_SOURCE_UNAVAILABLE');
        $sheet=$db->fetch_array($db->simple_select('af_cs_sheets','uid,tid,slug','id='.$this->sheetId,['limit'=>1]));
        if ((int)($sheet['uid']??0)!==$owner || (int)($sheet['tid']??0)!==$this->sourceTid
            || (preg_match('/^thread-(\d+)$/D',(string)$sheet['slug'],$match) && (int)$match[1]!==$this->sourceTid)) {
            throw new RuntimeException('ACS_DEFINITION_VERSION_CONFLICT');
        }
        if (!af_is_addon_enabled('charactersheets') || !af_is_addon_enabled('knowledgebase')) {
            throw new RuntimeException('ACS_DEPENDENCY_UNAVAILABLE');
        }
        $accept=af_charactersheets_get_accept_row($this->sourceTid);
        if (empty($accept['accepted']) || (int)$accept['uid']!==$owner
            || (string)$accept['sheet_slug']!==(string)$sheet['slug'] || (int)($accept['kb_entry_id']??0)<=0) {
            throw new RuntimeException('ACS_CHARACTER_ACCEPT_LINK_INVALID');
        }
        $entry=$db->fetch_array($db->simple_select('af_kb_entries','*','id='.(int)$accept['kb_entry_id'],['limit'=>1]));
        if (!$entry || ($entry['type']??null)!=='character' || empty($entry['active'])
            || !af_kb_can_view() || !af_kb_entry_visible_in_context($entry,'entry',af_kb_can_edit())) {
            throw new RuntimeException('ACS_CHARACTER_DEFINITION_UNAVAILABLE');
        }
        $access->user((string)$owner);
        try {
            $access->thread($session['tid'],(string)$owner);
        } catch (RuntimeException $e) {
            throw new RuntimeException('ACS_CHARACTER_TOPIC_UNAVAILABLE',0,$e);
        }
        $issues=[];
        $meta=af_kb_arpg_acs_source($entry,$issues)['rules']['character_meta']??[];
        if ((int)($meta['source_tid']??0)!==$this->sourceTid || (int)($meta['availability']['owner_uid']??0)!==$owner) {
            throw new RuntimeException('ACS_CHARACTER_ACCEPT_LINK_INVALID');
        }
    }
}
