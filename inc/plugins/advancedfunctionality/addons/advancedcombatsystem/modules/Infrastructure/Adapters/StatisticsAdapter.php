<?php
if (!defined('IN_MYBB')) {die('No direct access');}
final class AF_ACS_StatisticsAdapter
{
    public static function publishCompletedAggregate(string $operation_key,string $encounter_id,string $aggregate_version,string $visibility,array $payload):array
    {
        if(!af_is_addon_enabled('advancedstatistic'))throw new RuntimeException('ACS_STATISTICS_CONSUMER_UNAVAILABLE');
        require_once __DIR__.'/../../../../advancedstatistic/modules/combat_statistics.php';
        return af_advancedstatistic_publish_completed_aggregate($operation_key,$encounter_id,$aggregate_version,$visibility,$payload);
    }
    public static function lookup(string $operation_key):?array
    {
        if(!af_is_addon_enabled('advancedstatistic'))throw new RuntimeException('ACS_STATISTICS_CONSUMER_UNAVAILABLE');
        require_once __DIR__.'/../../../../advancedstatistic/modules/combat_statistics.php';
        return af_advancedstatistic_completed_aggregate_receipt($operation_key);
    }
}
