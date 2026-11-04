<?php
declare(strict_types=1);
define('IN_MYBB',1);
require __DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Infrastructure/PublicationStatusQuery.php';
foreach(['AF_ACS_BuilderService','AF_ACS_Resolver','AF_ACS_CombatCommitWriter','AF_ACS_MybbPostGateway'] as $class) {
    if(class_exists($class,false))throw new RuntimeException('Status query loaded '.$class);
}
echo "ACS publication status: SQL-only bootstrap, no Builder/Resolver/writer/Gateway passed\n";
