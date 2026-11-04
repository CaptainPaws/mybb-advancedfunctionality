<?php
declare(strict_types=1);
define('IN_MYBB',1);
$base=__DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/';
require $base.'Infrastructure/CombatCardQuery.php';require $base.'Infrastructure/RecoveryQueueQuery.php';require $base.'Presentation/CombatCard.php';require $base.'Presentation/ThreadComponents.php';
foreach(['AF_ACS_BuilderService','AF_ACS_Resolver','AF_ACS_CombatCommitWriter','AF_ACS_RecoveryService','AF_ACS_MybbPostGateway'] as $c)if(class_exists($c,false))throw new RuntimeException('Presentation loaded heavy '.$c);
echo "ACS presentation bootstrap: no Builder/Resolver/writer/Recovery/Gateway passed\n";
