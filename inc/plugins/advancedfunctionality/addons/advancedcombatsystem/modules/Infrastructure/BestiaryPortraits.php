<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

trait AF_ACS_BestiaryPortraits
{
    /** Resolve only already-authorized entity IDs, under the viewer's own KB permissions. */
    private function bestiaryPortraits(string $sid,array $ids): array
    {
        global $db;
        if (!$ids || !af_is_addon_enabled('knowledgebase') || !$db->table_exists('af_kb_entries')) return [];
        require_once AF_ADDONS.'knowledgebase/knowledgebase.php';
        if (!af_kb_can_view()) return [];

        $canEdit=af_kb_can_edit();
        $result=[];
        foreach (array_chunk($ids,100) as $chunk) {
            $rows=$this->sql('SELECT e.id AS entity_id,k.* FROM '.$this->table('entities').' e JOIN `'.TABLE_PREFIX.'af_kb_entries` k'
                ." ON k.type='arpg_bestiary' AND k.active=1 AND k.`key`=JSON_UNQUOTE(JSON_EXTRACT(e.definition_json,'$.source.source_key'))"
                ." WHERE e.session_id=? AND e.kind IN ('npc','monster') AND e.id IN (".implode(',',array_fill(0,count($chunk),'?')).')',
                [$sid,...$chunk])['rows'];
            foreach ($rows as $row) {
                if (af_kb_entry_visible_in_context($row,'entry',$canEdit)) {
                    $result[(string)$row['entity_id']]=(string)$row['icon_url'];
                }
            }
        }
        return $result;
    }
}
