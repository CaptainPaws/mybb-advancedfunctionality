<?php
if(!defined('IN_MYBB')){die('No direct access');}
/** Infrastructure RNG only. Never used by preview or read/confirm/recovery. */
final class AF_ACS_RandomSource
{
    public function __construct(private readonly ?Closure $sample=null){}
    public function action():array
    {
        $draw=function():array{$n=$this->sample?($this->sample)():random_int(0,9007199254740991);if(!is_int($n)||$n<0||$n>9007199254740991)throw new RuntimeException('ACS_SEAL_RNG_INVALID');return ['sample_n'=>$n,'u'=>$n/9007199254740992.0];};
        return ['rng_version'=>'uniform53-v1','hit'=>$draw(),'crit'=>$draw()];
    }
}
