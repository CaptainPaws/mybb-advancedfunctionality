<?php
if (!defined('IN_MYBB') || !defined('IN_ADMINCP') || !IN_ADMINCP) { die('No direct access'); }
require_once __DIR__.'/../Domain/Effects.php';

/** Fixed synthetic sample only. Never reads/updates production entities or accepts a plan from GET/POST. */
final class AF_ACS_EffectsDiagnostics
{
    public static function render(): void
    {
        (new AF_ACS_AuthorizationService)->requirePermission('admin.diagnostics.view');
        $ref=['key'=>'synthetic-demo','version'=>'1','fingerprint'=>'sha256:'.str_repeat('0',64)];
        $entity=['hp'=>20,'max_hp'=>20,'life_state'=>'active','recovery_blocked'=>false,'group_id'=>'1',
            'resources'=>['energy'=>['current'=>10,'maximum'=>10]],'source_ref'=>$ref,'version'=>1];
        $pool=['source_entity_id'=>'1','source_ref'=>$ref,'scope'=>'shared_pool','scope_key'=>'demo','targets'=>['1','2'],
            'strength'=>5,'maximum_strength'=>5,'barrier_priority'=>0,'applied_order'=>1,'status'=>'active','version'=>1,
            'absorbs'=>['physical'],'duration'=>['value'=>3,'unit'=>'own_turn']];
        $state=['schema_version'=>1,'entities'=>[1=>$entity,2=>$entity],'groups'=>[1=>['ap'=>['current'=>3,'maximum'=>3],'version'=>1]],'barriers'=>[1=>$pool],'next_barrier_order'=>2];
        // Prepared integer allocation: this diagnostic does not invent KB formulas or execute Damage math.
        $allocation=new AF_ACS_DamageResult(['trace_version'=>AF_ACS_Damage::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,
            'integer_budget'=>4,'allocation_sum'=>4,'components'=>[['source_index'=>0,'damage_type'=>'physical','allocated'=>4]]],[]);
        $action=['actor_id'=>'1','costs'=>[['kind'=>'hp','amount'=>2],['kind'=>'ap','amount'=>1],['kind'=>'resource','resource'=>'energy','amount'=>2]],
            'effects'=>[['kind'=>'damage','targets'=>[['entity_id'=>'1','allocation'=>$allocation],['entity_id'=>'2','allocation'=>$allocation]]],['kind'=>'heal','entity_id'=>'1','amount'=>5]]];
        $result=AF_ACS_Effects::attempt($state,$action);
        echo '<p>Виртуальный пример Effects: два синтетических участника с HP 20, общий барьер 5, урон 4 каждому. Стоимость: 2 HP, 1 AP, 2 энергии; затем лечение первого на 5. Реальные ресурсы и CharacterSheets не читаются и не изменяются.</p>';
        if(!$result['ready']){echo '<p>Виртуальный пример не прошёл проверку.</p>';return;}
        $v=$result['evaluation']->serverState();$table=new Table;
        $table->construct_header('Показатель');$table->construct_header('Виртуальное значение');
        foreach(['HP первого участника'=>$v['entities'][1]['hp'],'HP второго участника'=>$v['entities'][2]['hp'],'Остаток общего барьера'=>$v['barriers'][1]['strength'],
            'AP группы'=>$v['groups'][1]['ap']['current'],'Энергия первого участника'=>$v['entities'][1]['resources']['energy']['current']] as $label=>$value){
            $table->construct_cell(htmlspecialchars_uni($label));$table->construct_cell(htmlspecialchars_uni((string)$value));$table->construct_row();
        }
        $table->output('Effects: '.AF_ACS_Effects::VERSION.' — изолированный read-only пример');
    }
}
