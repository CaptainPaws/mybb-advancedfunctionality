<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

enum AF_ACS_TurnState: string
{
    case Pending='pending';
    case Resolved='resolved';
    case Passed='passed';
    case Forfeited='forfeited';
}

/** Pure vocabulary/identities. No SQL, time polling, ticks, upkeep or state mutation. */
final class AF_ACS_Clock
{
    public const VERSION='acs-clock-1';
    private static function id(string $id): string
    {
        if(!preg_match('/\A[1-9][0-9]{0,19}\z/D',$id) || (strlen($id)===20 && strcmp($id,'18446744073709551615')>0))throw new InvalidArgumentException('ACS_INVALID_INTEGER');return $id;
    }
    public static function apRefresh(string $roundId): string {return 'round:'.self::id($roundId).':ap_refresh';}
    public static function entityStart(string $entityTurnId): string {return 'entity_turn:'.self::id($entityTurnId).':start';}
    public static function entityEnd(string $entityTurnId): string {return 'entity_turn:'.self::id($entityTurnId).':end';}
    /** This is a state graph, never authorization or permission to skip boundaries/publication. */
    public static function transitionExists(AF_ACS_TurnState $from,AF_ACS_TurnState $to): bool
    {
        return $from===AF_ACS_TurnState::Pending && in_array($to,[AF_ACS_TurnState::Resolved,AF_ACS_TurnState::Passed,AF_ACS_TurnState::Forfeited],true);
    }
    public static function closed(AF_ACS_TurnState $state): bool {return $state!==AF_ACS_TurnState::Pending;}
    /** Stable serialization order is not initiative; every pending group has the same opportunity. */
    public static function rosterHash(array $groupPayloads): string
    {
        uksort($groupPayloads,static fn($a,$b)=>strlen((string)$a)<=>strlen((string)$b) ?: strcmp((string)$a,(string)$b));
        $parts=[];foreach($groupPayloads as $group=>$json)$parts[]=self::id((string)$group).':'.hash('sha256',$json);
        return hash('sha256',implode("\n",$parts));
    }
}
