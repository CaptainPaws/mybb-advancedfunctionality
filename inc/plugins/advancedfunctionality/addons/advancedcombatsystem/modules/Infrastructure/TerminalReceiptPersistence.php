<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Shared SQL-only terminal verifier; no Builder/Resolver or authorization callbacks. */
trait AF_ACS_TerminalReceiptPersistence
{
    private function terminal(array $row): ?array
    {
        $records=$this->terminalRecords([$row['submission_id']]);
        return $this->verifyTerminalRecord($row,$records[$row['submission_id']]??null);
    }

    private function terminalRecords(array $ids, bool $details = false): array
    {
        if (!$ids) { return []; }
        $extra=$details?",JSON_EXTRACT(c.payload_json,'$.summary') AS card_summary,JSON_EXTRACT(c.payload_json,'$.plan.actions') AS card_actions,JSON_EXTRACT(c.payload_json,'$.final.status_instances') AS card_statuses,JSON_EXTRACT(c.payload_json,'$.boundary_history') AS card_boundaries":'';
        $rows=$this->sql('SELECT r.*,s.status AS publication_status,s.request_hash AS submission_hash,s.sealed_json AS submission_sealed,s.result_json AS terminal_result,s.pid AS linked_pid,s.turn_id AS linked_turn,l.id AS link_id,l.pid AS post_pid,l.turn_id AS post_turn,c.event_id AS result_event,c.result_hash AS mechanical_hash,SHA2(c.payload_json,256) AS actual_result_hash'.$extra.' FROM '.$this->table('combat_receipts').' r JOIN '.$this->table('submissions').' s ON s.submission_id=r.submission_id LEFT JOIN '.$this->table('post_links').' l ON l.submission_id=r.submission_id LEFT JOIN '.$this->table('combat_results').' c ON c.submission_id=r.submission_id WHERE r.submission_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids)['rows'];
        $out=[];foreach($rows as $r){$out[$r['submission_id']]=$r;}return $out;
    }

    private function verifyTerminalRecord(array $row, ?array $r): ?array
    {
        if (!$r) {
            if ($row['status']==='committed') { throw new RuntimeException('ACS_COMBAT_RECEIPT_MISSING'); }
            return null;
        }
        // One joined SELECT observes receipt and terminal state from the same InnoDB read view.
        if ($r['publication_status']!=='committed' || !hash_equals($r['request_hash'],$row['request_hash'])
            || !hash_equals($r['request_hash'],$r['submission_hash']) || $r['submission_sealed']!==$row['sealed_json']
            || !hash_equals($r['sealed_hash'],hash('sha256',$row['sealed_json']))
            || !hash_equals($r['receipt_hash'],hash('sha256',$r['receipt_json']))) { throw new RuntimeException('ACS_COMBAT_RECEIPT_INVALID'); }
        $receipt=json_decode($r['receipt_json'],true,32,JSON_THROW_ON_ERROR);
        if (($receipt['schema_version']??null)!==1 || ($receipt['status']??null)!=='COMMITTED'
            || ($receipt['submission_id']??null)!==$row['submission_id'] || (string)$r['pid']!==(string)$r['linked_pid']
            || (string)$r['turn_id']!==$row['turn_id'] || (string)$r['linked_turn']!==$row['turn_id'] || $r['terminal_result']!==$r['receipt_json']
            || (string)$r['post_pid']!==(string)$r['pid'] || (string)$r['post_turn']!==$row['turn_id']
            || (string)$r['link_id']!==($receipt['post_link_id']??null) || (string)$r['result_event']!==($receipt['event_id']??null)
            || ($receipt['result_hash']??null)!==$r['mechanical_hash'] || $r['mechanical_hash']!==$r['actual_result_hash']) { throw new RuntimeException('ACS_COMBAT_RECEIPT_INVALID'); }
        return $receipt;
    }

}
