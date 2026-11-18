<?php

if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Application/AuthorizationService.php';
require_once __DIR__.'/../Infrastructure/Adapters/CapabilityValidator.php';

/** Lightweight observations by default; explicit ACP validation uses read-only owner APIs. */
final class AF_ACS_Diagnostics
{
    private array $sourceCache = [];

    private function declarations(string $file): array
    {
        if (isset($this->sourceCache[$file])) { return $this->sourceCache[$file]; }
        $names = [];
        if (is_file($file) && is_readable($file)) {
            $tokens = token_get_all((string)file_get_contents($file));
            foreach ($tokens as $i => $token) {
                if (!is_array($token) || $token[0] !== T_FUNCTION) { continue; }
                for ($j = $i + 1; isset($tokens[$j]); ++$j) {
                    $next = $tokens[$j];
                    if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
                    if (is_array($next) && $next[0] === T_STRING) { $names[$next[1]] = true; }
                    break;
                }
            }
        }
        return $this->sourceCache[$file] = $names;
    }

    private function interfaces(array $sources): array
    {
        $result = [];
        foreach ($sources as $file => $names) {
            $declared = $this->declarations($file);
            foreach ($names as $name) {
                $parts = explode('::', $name, 2);
                $loaded = count($parts) === 2 ? (class_exists($parts[0], false) && method_exists($parts[0], $parts[1])) : function_exists($name);
                $result[$name] = ['declared' => isset($declared[end($parts)]), 'loaded' => $loaded, 'callable_now' => count($parts) === 2 ? ($loaded && is_callable($parts)) : $loaded];
            }
        }
        return $result;
    }

    public function schema(): array
    {
        (new AF_ACS_AuthorizationService)->requirePermission('admin.diagnostics.view');
        try { require_once __DIR__.'/Migrations.php'; $state=af_advancedcombatsystem_migrations()->status(); }
        catch (Throwable $error) { $state=['schema_version'=>null,'migrations'=>[],'error'=>'ACS_DATABASE_UNAVAILABLE','database'=>null]; }
        // status() already verifies the entire registered ledger/checksums and exact schema readback.
        $state['required_version']=class_exists('AF_ACS_Migrations',false)?AF_ACS_Migrations::VERSION:null;
        $state['ready']=$state['required_version']!==null && $state['error']===null && $state['schema_version']===$state['required_version'];
        return $state;
    }

    /** Implementation evidence, current bootstrap, data validation and acceptance are independent. */
    public function capabilities(bool $validateData = false, array $targets = []): array
    {
        (new AF_ACS_AuthorizationService)->requirePermission('admin.integrations.view');
        $addons=[];
        if(function_exists('af_discover_addons'))try{foreach(af_discover_addons() as $meta)$addons[$meta['id']]=$meta;}catch(Throwable $error){}
        $base=AF_ADDONS;$acs=__DIR__.'/../';$adapters=$acs.'Infrastructure/Adapters/';
        $core=dirname(rtrim(AF_ADDONS,'/')).'.php';
        // Supported compositions reviewed against the current source, not an API-presence readiness allowlist.
        $definitions=[
            'core.facilities'=>['IC-CORE','advancedfunctionality',[$core=>['af_discover_addons','af_is_addon_enabled','advancedfunctionality_bootstrap_addons','af_xmlhttp_call_addon','af_xmlhttp_router','af_frontend_asset_allowed']],[],
                'Стандартный AF bootstrap и ACP router','CORE_INTERFACE_UNAVAILABLE','Восстановите штатный AF bootstrap и ACP router.'],
            'core.schema'=>['IC-CORE','advancedcombatsystem',[],[__DIR__.'/Migrations.php'=>['AF_ACS_Migrations::status','AF_ACS_Migrations::assertInstalled']],
                'MigrationManager: ledger, checksums, таблицы, колонки и индексы','SCHEMA_NOT_READY','Откройте диагностику схемы; исправьте указанную причину и отдельно запустите штатное обновление. Данные сохраняются.'],
            'kb.definition_reader'=>['IC-KB','knowledgebase',[$base.'knowledgebase/knowledgebase.php'=>['af_kb_arpg_read_definition','af_kb_arpg_normalize_entry']],[$adapters.'KnowledgeBaseAdapter.php'=>['AF_ACS_KnowledgeBaseAdapter::readDefinition']],
                'Разрешённое определение и versioned references; только поддерживаемые схемы','INSTALLED_KB_ACCEPTANCE_REQUIRED','Выберите один KB key и проверьте источник без записи; ошибки исправляются через владельца KB.'],
            'kb.combat_snapshot'=>['IC-KB','knowledgebase',[$base.'knowledgebase/knowledgebase.php'=>['af_kb_arpg_read_definition']],[$adapters.'RegistrationSource.php'=>['AF_ACS_RegistrationSource::read','AF_ACS_RegistrationSource::definitions'],$acs.'Infrastructure/SnapshotStore.php'=>['AF_ACS_SnapshotStore::append']],
                'RegistrationSource и неизменяемые ACS pins, без перезаписи старых снимков','INSTALLED_KB_SNAPSHOT_ACCEPTANCE_REQUIRED','Проверьте выбранное определение, права и закрепление версий при ручной регистрации. Проверка ACP снимок не сохраняет.'],
            'cs.combat_snapshot'=>['IC-CS','charactersheets',[$base.'charactersheets/charactersheets.php'=>['af_charactersheets_create_combat_snapshot'],$base.'charactersheets/modules/combat_snapshot.php'=>['af_charactersheets_prepare_combat_snapshot']],[$adapters.'CharacterSheetAdapter.php'=>['AF_ACS_CharacterSheetAdapter::createSnapshot']],
                'Read-only combat preparation, owner identity, equipment provenance','INSTALLED_CS_ACCEPTANCE_REQUIRED','Выберите один ID листа; проверьте разрешённый read-only snapshot и источники экипировки.'],
            'inv.reserve_consume'=>['IC-INV','advancedinventory',[$base.'advancedinventory/modules/OwnerProtocol.php'=>['AF_ADVINV_OwnerProtocol::reserve','AF_ADVINV_OwnerProtocol::consume','AF_ADVINV_OwnerProtocol::lookup','AF_ADVINV_OwnerProtocol::participate']],[$adapters.'InventoryAdapter.php'=>['AF_ACS_InventoryAdapter::forCombat','AF_ACS_InventoryAdapter::participate'],$adapters.'CombatInventoryAccess.php'=>['AF_ACS_CombatInventoryAccess::authorize']],
                'Owner reserve/consume/release/lookup; critical costs только same-writer InnoDB','INSTALLED_INVENTORY_ACCEPTANCE_REQUIRED','Владелец проверяет owner schema, writer routing, резервы и отдельный spending grant. ACP не создаёт reserve/consume и не выдаёт authorization.'],
            'stats.consumer'=>['IC-STATS','advancedstatistic',[$base.'advancedstatistic/modules/combat_statistics.php'=>['af_advancedstatistic_publish_completed_aggregate','af_advancedstatistic_completed_aggregate_receipt']],[$adapters.'StatisticsAdapter.php'=>['AF_ACS_StatisticsAdapter::publishCompletedAggregate','AF_ACS_StatisticsAdapter::lookup'],$acs.'Infrastructure/StatisticsRepository.php'=>['AF_ACS_StatisticsRepository::consume','AF_ACS_StatisticsRepository::receipt']],
                'Completed aggregate: version/hash, durable receipt и archived recovery','INSTALLED_STATISTICS_ACCEPTANCE_REQUIRED','Проверьте installed completed aggregate и lookup прежней версии на изолированной копии. Диагностика не запускает consumer и не меняет очередь.'],
            'aam.delivery'=>['IC-AAM','advancedalertsandmentions',[$base.'advancedalertsandmentions/combat_delivery.php'=>['AF_AAM_CombatDelivery::deliver','AF_AAM_CombatDelivery::lookup']],[$acs.'Infrastructure/NotificationOutbox.php'=>['AF_ACS_NotificationOutbox::authorize','AF_ACS_NotificationOutbox::acknowledge'],$acs.'Application/NotificationService.php'=>['AF_ACS_NotificationService::drain']],
                'Typed ACS alerts: stable key/hash, atomic AAM alert + retained receipt, lookup и bounded outbox consumer','INSTALLED_AAM_ACCEPTANCE_REQUIRED','Обновите AAM через штатную установку receipt table/types и ACS task. Installed roles, hidden payload, replay/lost ACK/outage требуют ручной приёмки; диагностика не отправляет уведомления.'],
            'theme.components'=>['IC-THEME','adaptivethemeframework',[$base.'adaptivethemeframework/adaptivethemeframework.php'=>['af_adaptivethemeframework_register_component','af_adaptivethemeframework_slots']],[$acs.'Presentation/ThreadComponents.php'=>['af_advancedcombatsystem_register_thread_component','af_advancedcombatsystem_combat_card','af_advancedcombatsystem_thread_session_link']],
                'ACS providers в существующих ATF thread/post slots','INSTALLED_ACS_COMPONENT_ACCEPTANCE_REQUIRED','Вручную проверьте установленную тему, роли, мобильный вид и sticky/meta-line; шаблоны ATF не подменять.'],
            'editor.combat_form'=>['IC-EDITOR','advancededitor',[$base.'advancededitor/advancededitor.php'=>['af_advancededitor_init','af_advancededitor_misc_start']],[$acs.'Presentation/EditorPublicationController.php'=>['AF_ACS_EditorPublicationController::handle','AF_ACS_EditorPublicationController::decorate']],
                'Native form/Quick Reply test binding, один редактор, production gate закрыт','INSTALLED_EDITOR_FORM_ACCEPTANCE_REQUIRED','Вручную проверьте newreply/Quick Reply, editor lifecycle и recovery в разрешённом staging scope; второй SCEditor не создавать.'],
            'mybb.atomic_publish'=>['IC-MYBB','mybb',[MYBB_ROOT.'inc/datahandlers/post.php'=>['PostDataHandler::insert_post']],[$adapters.'MybbPostGateway.php'=>['AF_ACS_MybbPostGateway::publish'],$acs.'Application/PublishService.php'=>['AF_ACS_PublishService::issue']],
                'R2 native Gateway и отдельный ACS coordinator, без общей MyBB/ACS транзакции','INSTALLED_MYBB_PUBLISH_AUDIT_REQUIRED','Откройте «Публикация»: нужны installed source/hooks/routing, native lifecycle и явное решение администратора по IC-MYBB-1. Диагностика публикацию не разрешает.'],
        ];
        $state=$this->schema();$rows=[];
        foreach($definitions as $id=>[$contract,$owner,$sources,$implementation,$scope,$blocker,$action]){
            $meta=$addons[$owner]??null;
            $detected=$owner==='advancedfunctionality'?is_file($core):($owner==='mybb'?is_file(MYBB_ROOT.'inc/datahandlers/post.php'):($owner==='advancedcombatsystem'?is_file(__DIR__.'/../../manifest.php'):($meta!==null||is_file($base.$owner.'/manifest.php'))));
            $enabled=in_array($owner,['advancedfunctionality','mybb'],true)?$detected:($detected&&function_exists('af_is_addon_enabled')&&af_is_addon_enabled($owner));
            $ownerApis=$this->interfaces($sources);$codeApis=$this->interfaces($implementation);$observed=$ownerApis+$codeApis;
            $missingOwner=(bool)array_filter($ownerApis,static fn($api)=>!$api['declared']);
            $missingCode=(bool)array_filter($codeApis,static fn($api)=>!$api['declared']);
            $supported=true;
            $impl=!$supported?'NOT_IMPLEMENTED':($missingCode?'INCOMPLETE':'IMPLEMENTED');
            $runtime=!$enabled?'UNAVAILABLE':($missingOwner||$missingCode?'INTERFACE_MISSING':(array_filter($observed,static fn($api)=>!$api['loaded'])?'LAZY_LOADABLE':'LOADED'));
            $ready=false;$reason=!$detected?'OWNER_NOT_DETECTED':(!$enabled?'OWNER_DISABLED':(!$supported?$blocker:($missingCode?'IMPLEMENTATION_INCOMPLETE':($missingOwner?'INTERFACE_MISSING':$blocker))));
            if($id==='core.schema'){$ready=$detected&&$state['ready'];$reason=$ready?null:($state['error']??'SCHEMA_NOT_READY');}
            elseif($id==='core.facilities'){
                $ready=$enabled&&!$missingOwner&&$observed&&!array_filter($observed,static fn($api)=>!$api['callable_now'])&&class_exists('AF_Admin',false)&&method_exists('AF_Admin','findAdminController');
                $reason=$ready?null:'CORE_INTERFACE_UNAVAILABLE';
            }
            $blocked=!$detected||!$enabled||!$supported||$missingOwner||$missingCode||in_array($id,['core.schema','core.facilities','mybb.atomic_publish'],true);
            $version=$meta['version']??null;
            if($owner==='mybb')$version=$GLOBALS['mybb']->version??null;
            if($owner==='advancedfunctionality'&&function_exists('advancedfunctionality_info'))$version=advancedfunctionality_info()['version']??null;
            $data=AF_ACS_CapabilityValidator::notChecked($id,'not_checked',in_array($id,['kb.definition_reader','kb.combat_snapshot','cs.combat_snapshot'],true)?'SOURCE_NOT_SELECTED':'VALIDATION_NOT_REQUESTED');
            if($id==='core.schema')$data=AF_ACS_CapabilityValidator::row($id,$ready,'schema_integrity_readback',$ready?[]:[['code'=>$reason,'path'=>'schema','severity'=>'error']]);
            $rows[$id]=[
                'capability'=>$id,'contract_id'=>$contract,'owner'=>$owner,'detected'=>$detected,'enabled'=>$enabled,'version'=>is_string($version)?$version:null,
                'version_evidence'=>$owner==='mybb'?'runtime metadata; не installed acceptance':'manifest / текущий bootstrap',
                'interface_status'=>$impl==='IMPLEMENTED'?'EXISTING':$impl,'implementation_status'=>$impl,'implementation_scope'=>$scope,
                'interfaces'=>$observed,'runtime_status'=>$runtime,'installation_status'=>$id==='core.schema'?($state['ready']?'VERIFIED':'BLOCKED'):'NOT_VERIFIED',
                'installation_scope'=>$id==='core.schema'?'MigrationManager full schema readback':'Owner installation/schema не проверялись; detected/enabled не заменяют эту проверку',
                'installed_acceptance'=>'NOT_VERIFIED','verification_level'=>$id==='core.schema'?'schema_integrity_readback':'source_review / declarations',
                'readiness'=>$ready?'READY':($blocked?'BLOCKED':'NOT_VERIFIED'),'reason'=>$reason,'scope'=>$scope,'data_check'=>$data,
                'implementation_blocker'=>$impl==='IMPLEMENTED'?null:($missingCode?'IMPLEMENTATION_INCOMPLETE':$blocker),
                'roadmap_stage'=>null,'remediation'=>!$detected?'Установите или восстановите owner '.$owner.'. '.$action:(!$enabled?'Включите owner '.$owner.' штатным способом после его проверки. '.$action:($ready?'В указанной области блокировок нет. Installed acceptance публикации проверяется отдельно.':$action)),
            ];
        }
        $checks=[];
        if($validateData){require_once __DIR__.'/IntegrationCapabilities.php';$checks=(new AF_ACS_IntegrationCapabilities)->inspect($targets);}
        foreach(['kb.definition_reader','kb.combat_snapshot','cs.combat_snapshot'] as $id)if(isset($checks[$id]))$rows[$id]['data_check']=$checks[$id];
        $kb=$base.'knowledgebase/';$cs=$base.'charactersheets/';
        $capSources=[
            'accuracy_registry'=>[$kb.'knowledgebase.php'=>['af_kb_arpg_character_stat_registry']],
            'accuracy_roundtrip'=>[$kb.'knowledgebase.php'=>['af_kb_validate_rules_json_by_type_arpg','af_kb_arpg_normalize_entry']],
            'canonical_elements_17'=>[$kb.'modules/arpg_elements.php'=>['af_kb_arpg_primary_elements','af_kb_arpg_list_primary_elements']],
            'magic_direction_hierarchy'=>[$kb.'modules/arpg_elements.php'=>['af_kb_arpg_read_element']],
            'legacy_element_aliases'=>[$kb.'modules/arpg_elements.php'=>['af_kb_arpg_canonical_element_key']],
            'explicit_damage_element'=>[$kb.'modules/arpg_elements.php'=>['af_kb_arpg_element_damage']],
            'element_snapshot_provenance'=>[$kb.'modules/arpg_elements.php'=>['af_kb_arpg_read_element']],
            'status_resistance_mapping'=>[$kb.'modules/arpg_bestiary.php'=>['af_kb_arpg_status_resistance']],
            'bestiary_snapshot'=>[$kb.'knowledgebase.php'=>['af_kb_arpg_read_definition']],
            'character_snapshot'=>[$cs.'charactersheets.php'=>['af_charactersheets_create_combat_snapshot']],
            'equipment_source_breakdown'=>[$cs.'modules/combat_snapshot.php'=>['af_charactersheets_prepare_combat_snapshot']],
            'snapshot_read_only'=>[$cs.'modules/combat_snapshot.php'=>['af_charactersheets_prepare_combat_snapshot']],
            'definition_versioning'=>[$kb.'knowledgebase.php'=>['af_kb_arpg_acs_fingerprint','af_kb_arpg_normalize_entry']],
        ];
        foreach(AF_ACS_CapabilityValidator::IDS as $id){
            $owner=in_array($id,['character_snapshot','equipment_source_breakdown','snapshot_read_only'],true)?'cs.combat_snapshot':'kb.definition_reader';
            $row=$rows[$owner];$row['capability']=$id;$row['interfaces']=$this->interfaces($capSources[$id]);
            $row['data_check']=$checks[$id]??AF_ACS_CapabilityValidator::notChecked($id,'not_checked',in_array($id,['bestiary_snapshot','character_snapshot','equipment_source_breakdown','snapshot_read_only'],true)?'SOURCE_NOT_SELECTED':'VALIDATION_NOT_REQUESTED');
            $row['implementation_scope']='Поддерживаемый owner/ACS validation contract: '.$id;
            $row['runtime_status']=!$row['enabled']?'UNAVAILABLE':(array_filter($row['interfaces'],static fn($api)=>!$api['loaded'])?'LAZY_LOADABLE':'LOADED');
            $row['remediation']='Нажмите «Проверить без записи». Область результата указана отдельно; ошибки данных исправляются через владельца источника. Installed acceptance остаётся отдельной ручной проверкой.';
            if(array_filter($row['interfaces'],static fn($api)=>!$api['declared'])){$row['runtime_status']='INTERFACE_MISSING';$row['readiness']='BLOCKED';$row['reason']='INTERFACE_MISSING';}
            $rows[$id]=$row;
        }
        // Loading a supported lazy module during a selected probe is evidence, never acceptance.
        if($validateData)foreach($rows as &$row){foreach($row['interfaces'] as $name=>&$api){$parts=explode('::',$name,2);$api['loaded']=count($parts)===2?class_exists($parts[0],false)&&method_exists($parts[0],$parts[1]):function_exists($name);$api['callable_now']=count($parts)===2?$api['loaded']&&is_callable($parts):$api['loaded'];}unset($api);
            if(in_array($row['runtime_status'],['LOADED','LAZY_LOADABLE'],true))$row['runtime_status']=array_filter($row['interfaces'],static fn($api)=>!$api['loaded'])?'LAZY_LOADABLE':'LOADED';
        }unset($row);
        foreach($rows as &$row){
            if($row['capability']!=='core.schema'&&$row['data_check']['readiness']!=='NOT_CHECKED')$row['verification_level']='source_review + '.$row['data_check']['scope'];
            if($row['data_check']['readiness']==='BLOCKED'){$row['readiness']='BLOCKED';$row['reason']=$row['data_check']['reason'];}
        }unset($row);
        return $rows;
    }

    /** No global readiness flag: only dependencies of the requested operation. */
    public function gate(string $operation): array
    {
        $dependencies = [
            'participant.register' => ['core.facilities', 'core.schema', 'kb.combat_snapshot', 'cs.combat_snapshot'],
            'combat.preview' => ['core.facilities', 'core.schema', 'kb.definition_reader', 'kb.combat_snapshot'],
            'item.consume' => ['core.facilities', 'core.schema', 'inv.reserve_consume'],
            'combat.publish' => ['core.facilities', 'core.schema', 'mybb.atomic_publish', 'theme.components', 'editor.combat_form'],
        ];
        (new AF_ACS_AuthorizationService)->requirePermission('admin.integrations.view');
        if (!isset($dependencies[$operation])) { return ['allowed' => false, 'reason' => 'ACS_UNKNOWN_OPERATION', 'blockers' => []]; }
        $rows = $this->capabilities();
        $blockers = [];
        foreach ($dependencies[$operation] as $id) { if ($rows[$id]['readiness'] !== 'READY') { $blockers[] = $rows[$id]; } }
        $enabled = function_exists('af_is_addon_enabled') && af_is_addon_enabled('advancedcombatsystem');
        return ['allowed' => !$blockers && $enabled, 'reason' => $enabled ? ($blockers ? 'ACS_DEPENDENCY_BLOCKED' : null) : 'ACS_DISABLED', 'blockers' => $blockers];
    }

    public function recentErrors(): array
    {
        (new AF_ACS_AuthorizationService)->requirePermission('admin.diagnostics.view');
        try {
            require_once __DIR__.'/../Infrastructure/Repository.php';
            $rows = (new AF_ACS_Repository($GLOBALS['db']))->recentDiagnostics();
        } catch (Throwable $error) { $rows = []; }
        $last = function_exists('af_read_addon_lifecycle_diagnostic') ? af_read_addon_lifecycle_diagnostic('advancedcombatsystem') : null;
        $code = $last['message'] ?? '';
        // Lifecycle files may contain older messages. Never expose raw text.
        if (is_string($code) && preg_match('/\AACS_[A-Z_]+(?: \[error_id=[a-f0-9]{32}\])?\z/D', $code)) {
            $rows[] = ['error_id' => 'lifecycle', 'category' => 'LIFECYCLE', 'operation' => 'lifecycle', 'phase' => 'acs_lifecycle', 'created_at' => (int)($last['recorded_at'] ?? 0), 'code' => $code];
        }
        return $rows;
    }
}
