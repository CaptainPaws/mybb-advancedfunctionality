<?php
declare(strict_types=1);

/** Server-only phase participant. No KB callbacks and no independent battle state. */
final class AF_ACS_PreparedPhase
{
    private array $trace=[];
    private array $visited=[];
    public function __construct(private array $entries,private Closure $execute,array $diagnostics=[]) { $this->trace=$diagnostics; }
    public static function decimal(string $a,string $b):int { return strlen($a)<=>strlen($b)?:strcmp($a,$b); }
    public static function compare(array $a,array $b):int
    {
        return $a['priority']<=>$b['priority']?:self::decimal((string)($a['causal_order']??$a['registered_order']),(string)($b['causal_order']??$b['registered_order']))?:self::decimal((string)$a['id'],(string)$b['id'])?:strcmp($a['queue_kind']??'reaction',$b['queue_kind']??'reaction');
    }
    public function entries(string $phase,int $actionIndex,string $originalTarget):array
    {
        return array_values(array_filter($this->entries,static fn($e)=>$e['phase']===$phase&&$e['action_index']===$actionIndex&&$e['subject']===$originalTarget));
    }
    public function apply(array $entry,array $event,array $state):array
    {
        $identity=$entry['identity'];if(isset($this->visited[$identity]))throw new RuntimeException('ACS_CAUSAL_REPEAT');$this->visited[$identity]=true;
        $result=($this->execute)($entry,$event,$state);$this->trace[]=$result['trace'];return $result['state'];
    }
    public function hasParticipants():bool { return $this->entries!==[]; }
    public function traces():array
    {
        if(count($this->visited)!==count($this->entries))throw new RuntimeException('ACS_CAUSAL_EVENT_UNREACHED');
        return $this->trace;
    }
}
