<?php
if (!defined('IN_MYBB')) {die('No direct access');}

/** Dedicated IC-STATS consumer. No index/post counters and no raw combat journal ingest. */
function af_advancedstatistic_publish_completed_aggregate(string $operation_key,string $encounter_id,string $aggregate_version,string $visibility,array $payload):array
{
    global $db;
    if(!af_is_addon_enabled('advancedstatistic'))throw new RuntimeException('ACS_STATISTICS_CONSUMER_UNAVAILABLE');
    require_once __DIR__.'/../../advancedcombatsystem/modules/Infrastructure/StatisticsRepository.php';
    return (new AF_ACS_StatisticsRepository($db))->consume($operation_key,$encounter_id,$aggregate_version,$visibility,$payload);
}
function af_advancedstatistic_completed_aggregate_receipt(string $operation_key):?array
{
    global $db;
    if(!af_is_addon_enabled('advancedstatistic'))throw new RuntimeException('ACS_STATISTICS_CONSUMER_UNAVAILABLE');
    require_once __DIR__.'/../../advancedcombatsystem/modules/Infrastructure/StatisticsRepository.php';
    return (new AF_ACS_StatisticsRepository($db))->receipt($operation_key);
}
