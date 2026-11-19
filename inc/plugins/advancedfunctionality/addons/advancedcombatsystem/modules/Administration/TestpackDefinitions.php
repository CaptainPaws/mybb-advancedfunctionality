<?php
if (!defined('IN_MYBB') || !defined('IN_ADMINCP') || !IN_ADMINCP) { die('No direct access'); }

final class AF_ACS_TestpackDefinitions
{
    public const SHOP = 'acs_test_arsenal';
    public const CATEGORY = 'acs_test_gear';

    public static function entries(array $statRegistry, array $primaryElements): array
    {
        $entries = [];
        $add = static function (string $key, string $type, string $title, string $description, array $rules, array $catalog = [], int $price = 0) use (&$entries): void {
            $entries['acs_test_'.$key] = compact('type', 'title', 'description', 'rules', 'catalog', 'price');
        };
        $add('origin', 'arpg_origin', 'Огненный авангард', 'Учебное происхождение для приёмки ACS.', [
            'size'=>'medium', 'creature_type'=>'humanoid', 'hp_base'=>100, 'attack_power_base'=>10,
            'defense_base'=>5, 'movement_speed'=>100, 'accuracy_base'=>90, 'crit_damage_base'=>50, 'status_resist_base'=>0,
            'racial_traits_text'=>'Гуманоид среднего размера, обученный держать строй.',
            'starting_notes'=>'Выберите основную стихию Огонь в анкете.',
        ]);
        $add('archetype', 'arpg_archetype', 'Пламенный воитель', 'Воитель первой линии с двуручным оружием.', [
            'base_defense_bonus'=>0, 'defense_base'=>2, 'attack_power_base'=>2,
            'slot_rules_text'=>'Двуручное оружие, физические и огненные атаки, защитные реакции.',
        ]);

        $service = static function (string $key, string $kind, string $title, array $definition) use ($add): void {
            $add($key, 'arpg_mechanics', $title, 'Служебное определение тестового комплекта ACS.', [
                'service_kind'=>$kind, 'entries'=>[['schema'=>'af_kb.arpg.mechanic.v1', 'key'=>'acs_test_'.$key, 'version'=>'1.0'] + $definition],
            ]);
        };
        $service('shadow_mark', 'status_def', 'Метка теневого зверя', [
            'type'=>'mark', 'scope'=>'combat', 'duration'=>['value'=>1, 'unit'=>'target_turn'],
            'phase'=>'on_apply', 'decrement'=>['event'=>'on_turn_end', 'amount'=>1],
            'repeat_rule'=>'refresh', 'max_stacks'=>1, 'removal_conditions'=>[], 'expiry_conditions'=>[],
            'immunities'=>[], 'resistances'=>[], 'periodic_effects'=>[], 'formula_refs'=>[], 'events'=>[],
        ]);

        $ability = static function (string $key, string $title, string $description, string $damage, string $target, array $effects, array $catalog, array $options = []) use ($add): void {
            $add($key, 'arpg_ability', $title, $description, $options + [
                'name'=>$title, 'type'=>'active', 'damage_type'=>$damage, 'targeting'=>$target, 'max_charges'=>0, 'effects'=>$effects,
            ] + ($damage === 'elemental' ? ['damage_element'=>'fire'] : []), ['version'=>1] + $catalog);
        };
        $attack = static function (string $key, string $title, string $description, int $value, string $damage, array $weapons, string $element, int $ap = 1, array $extra = [], bool $multiple = false) use ($ability): void {
            $target = $multiple ? 'multiple' : 'single_enemy';
            $effect = ['effect_type'=>'damage','value'=>$value,'damage_type'=>$damage,'target'=>$target]
                + ($damage === 'elemental' ? ['damage_element'=>'fire'] : []);
            $ability($key, $title, $description, $damage, $target, [$effect, ...$extra],
                ['weapons'=>$weapons,'distance'=>'melee','required_element'=>$element,'purpose'=>'attack'],
                ['action_cost'=>$ap] + ($multiple ? ['target_count'=>['min'=>1,'max'=>3]] : []));
        };
        $twoHand = ['weapon_two_hand'];
        $attack('heavy_strike','Тяжёлый удар','Физический удар двуручным оружием. 18 ед. до защиты.',18,'physical',$twoHand,'any');
        $attack('fire_cleave','Огненный рассекатель','Огненный удар двуручным оружием. 20 ед. до RES.',20,'elemental',$twoHand,'fire');
        $attack('burning_lunge','Пылающий выпад','Огненный выпад. 14 ед. до RES.',14,'elemental',['any'],'fire');
        $attack('fire_wave','Огненная волна','Атака до трёх явно выбранных целей. 12 ед. огненного урона на цель.',12,'elemental',['any'],'fire',1,[],true);
        $attack('crushing_strike','Разрушительный удар','Физический удар за 2 AP. 30 ед. до защиты.',30,'physical',$twoHand,'any',2);

        foreach ([
            'defender_stance'=>['Стойка защитника', 'Барьер реакции перед получением удара.', 'shield', 8, 'before_hit', 'all_allies', 'defense'],
            'stone_skin'=>['Каменная кожа', 'Барьер реакции при выборе цели.', 'shield', 12, 'on_targeted', 'all_allies', 'defense'],
            'bandage'=>['Перевязка ран', 'Лечебная реакция после попадания. Восстанавливает до 12 HP.', 'heal', 12, 'after_hit', 'self', 'heal'],
        ] as $key => [$title, $description, $effect, $amount, $event, $target, $purpose]) {
            $properties = ['effect_type'=>$effect,'value'=>$amount,'target'=>$target];
            if ($effect === 'shield') {
                $properties += ['mass'=>true,'scope'=>'per_target','absorbs'=>['physical','elemental'],
                    'duration_value'=>1,'duration_unit'=>'round'];
            }
            $ability($key,$title,$description,'healing',$target,[$properties],
                ['weapons'=>['none'],'distance'=>'any','required_element'=>'any','purpose'=>$purpose]);
            $service($key.'_reaction','trigger_template',$title.' — реакция',[
                'event'=>$event,'condition_ref'=>null,
                'effect_ref'=>['type'=>'arpg_ability','key'=>'acs_test_'.$key,'effect_index'=>0],
                'priority'=>0,'limits'=>['per_round'=>1],'formula_refs'=>[],
            ]);
        }

        $attack('guard_flare','Вспышка стража','Стихийная атака Пепельного стража.',9,'elemental',['none'],'any');
        $attack('beast_claw','Коготь зверя','Физическая атака. При попадании может оставить метку на один ход.',10,'physical',['none'],'any',1,[
            ['effect_type'=>'status','status_key'=>'acs_test_shadow_mark','target'=>'single_enemy','base_chance'=>65,'polarity'=>'negative','trigger'=>'on_hit'],
        ]);
        foreach ([
            'ash_guard'=>['Пепельный страж', 55, 6, 85, 85, 12, ['heavy_strike','guard_flare']],
            'shadow_beast'=>['Теневой зверь', 42, 3, 115, 95, 0, ['beast_claw']],
        ] as $key => [$title,$hp,$def,$speed,$accuracy,$fireRes,$abilities]) {
            $res = [];
            foreach (array_keys($primaryElements) as $element) {
                $res[] = ['damage_type'=>'elemental','scope'=>'elemental','unit'=>'RES','damage_element'=>$element,
                    'value'=>$element === 'fire' ? $fireRes : 0];
            }
            $stats = array_fill_keys(array_keys($statRegistry), 0);
            $stats = array_replace($stats, ['hp'=>$hp,'atk'=>8,'def'=>$def,'speed'=>$speed,'accuracy'=>$accuracy,'crit_dmg'=>50]);
            $add($key,'arpg_bestiary',$title,'Противник для короткого учебного столкновения.',[
                'family'=>'training','archetype'=>'melee','faction'=>'test_arsenal','rank'=>'normal',
                'combat_stats'=>$stats,'resists'=>$res,
                'ability_keys'=>array_map(static fn(string $id): array => ['_editor_ability_key'=>'acs_test_'.$id], $abilities),
            ]);
        }

        foreach ([
            'ember_blade'=>['Клинок тлеющих углей','weapon','greatsword','weapon_two_hand','weapon_mainhand',['accuracy'=>5,'def'=>1],4],
            'training_bow'=>['Учебный лук','weapon','bow','weapon_ranged','weapon_mainhand',['accuracy'=>3],3],
            'vanguard_armor'=>['Доспех авангарда','armor','heavy','chest','body',['def'=>5,'hp'=>8],4],
            'scout_armor'=>['Лёгкая броня разведчика','armor','light','chest','body',['def'=>2,'speed'=>5],3],
            'fortitude_ring'=>['Кольцо стойкости','accessory','ring','ring','accessory',['hp'=>6],2],
            'flame_amulet'=>['Амулет пламени','accessory','amulet','amulet','accessory',['def'=>2],2],
            'small_healing_potion'=>['Малое зелье лечения','consumable','potion','','',[],1,15],
            'large_healing_potion'=>['Большое зелье лечения','consumable','potion','','',[],2,30],
            'energy_elixir'=>['Энергетический эликсир','consumable','elixir','','',[],1],
        ] as $key => $row) {
            [$title,$kind,$subtype,$slot,$inventorySlot,$bonuses,$price] = $row;
            $item = ['item_kind'=>$kind,'rarity'=>'common'];
            if ($inventorySlot !== '') $item['equip'] = ['slot'=>$inventorySlot,'stackable'=>false];
            if (isset($row[7])) {
                $item['on_use'] = ['effects'=>[['op'=>'add_hp','value'=>$row[7]]]];
            }
            $modifiers = [];
            foreach ($bonuses as $stat => $value) $modifiers[] = ['stat_key'=>$stat,'mode'=>'flat','value'=>$value];
            $description = match ($key) {
                'energy_elixir'=>'Товар для проверки покупки и хранения. Боевое восстановление ресурса не поддерживается.',
                'ember_blade'=>'Двуручный меч. +5 Accuracy и +1 DEF; урон задаётся выбранной способностью.',
                'training_bow'=>'Дальнобойное оружие. +3 Accuracy; урон задаётся выбранной способностью.',
                default=>isset($row[7]) ? 'Восстанавливает до '.$row[7].' HP за 1 AP по правилам потребления ACS.' : 'Предмет для сравнения характеристик до и после экипировки.',
            };
            $add($key,'arpg_item',$title,$description,[
                'item_kind'=>$kind,'equip_slot'=>$slot,'subtype'=>$subtype,'base_stats'=>$modifiers,'item'=>$item,
            ] + ($kind === 'weapon' ? ['weapon_class'=>$subtype] : []), [], $price);
        }
        return $entries;
    }
}
