<?php
/**
 * AF Addon: Knowledge Base
 * MyBB 1.8.x / PHP 8.0–8.4
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_KB_ID', 'knowledgebase');
define('AF_KB_VER', '1.0.0');
define('AF_KB_BASE', AF_ADDONS . AF_KB_ID . '/');
define('AF_KB_ASSETS', AF_KB_BASE . 'assets/');
define('AF_KB_TPL_DIR', AF_KB_BASE . 'templates/');
define('AF_KB_MARK', '<!--af_kb_assets-->');
define('AF_KB_RULES_SCHEMA', 'af_kb.rules.v1');
define('AF_KB_ARPG_META_SCHEMA', 'af_kb.arpg.meta.v1');
define('AF_KB_ARPG_RULES_SCHEMA', 'af_kb.arpg.rules.v1');
define('AF_KB_ARPG_MECHANICS_SCHEMA', 'af_kb.arpg.mechanics.v1');
define('AF_KB_ARPG_SHEET_NORMALIZED_SCHEMA', 'af_kb.arpg.sheet-normalized.v1');
define('AF_KB_TRAITS_SCHEMA', 'af_kb.traits.v1');
define('AF_KB_GRANTS_SCHEMA', 'af_kb.grants.v1');
define('AF_KB_ALIAS_MARKER', "define('AF_KB_PAGE_ALIAS', 1);");

define('AF_KB_KEY_PATTERN', '/^[a-z0-9_-]{2,64}$/');
define('AF_KB_CAT_KEY_PATTERN', '/^[a-z0-9_-]{1,64}$/');
define('AF_KB_PERPAGE', 20);

define('AF_KB_REL_RACE_HAS_VARIANT', 'race_has_variant');
define('AF_KB_TYPE_RACE', 'race');
define('AF_KB_TYPE_RACE_VARIANT', 'race_variant');
define('AF_KB_DEFAULT_MECHANIC_KEY', 'dnd');

function af_kb_arpg_supported_types(): array
{
    return array_keys(af_kb_arpg_type_registry());
}

function af_kb_arpg_public_top_level_types(): array
{
    $result = [];
    foreach (af_kb_arpg_type_registry() as $typeKey => $typeDef) {
        if (empty($typeDef['service'])) {
            $result[] = $typeKey;
        }
    }
    return $result;
}

function af_kb_arpg_internal_types(): array
{
    $result = [];
    foreach (af_kb_arpg_type_registry() as $typeKey => $typeDef) {
        if (!empty($typeDef['service'])) {
            $result[] = $typeKey;
        }
    }
    return $result;
}

function af_kb_arpg_reference_types(): array
{
    return [];
}

function af_kb_arpg_type_registry(): array
{
    return [
        'arpg_origin' => ['entity_kind' => 'origin', 'service' => false, 'title_ru' => 'ARPG: Происхождения', 'title_en' => 'ARPG: Origins'],
        'arpg_archetype' => ['entity_kind' => 'archetype', 'service' => false, 'title_ru' => 'ARPG: Архетипы', 'title_en' => 'ARPG: Archetypes'],
        'arpg_faction' => ['entity_kind' => 'faction', 'service' => false, 'title_ru' => 'ARPG: Фракции', 'title_en' => 'ARPG: Factions'],
        'arpg_bestiary' => ['entity_kind' => 'bestiary', 'service' => false, 'title_ru' => 'ARPG: Бестиарий', 'title_en' => 'ARPG: Bestiary'],
        'arpg_ability' => ['entity_kind' => 'ability', 'service' => false, 'title_ru' => 'ARPG: Способности', 'title_en' => 'ARPG: Abilities'],
        'arpg_talent' => ['entity_kind' => 'talent', 'service' => false, 'title_ru' => 'ARPG: Таланты', 'title_en' => 'ARPG: Talents'],
        'arpg_item' => ['entity_kind' => 'item', 'service' => false, 'title_ru' => 'ARPG: Предметы', 'title_en' => 'ARPG: Items'],
        'arpg_lore' => ['entity_kind' => 'lore', 'service' => false, 'title_ru' => 'ARPG: Лор', 'title_en' => 'ARPG: Lore'],
        'arpg_mechanics' => ['entity_kind' => 'service_mechanics', 'service' => true, 'title_ru' => 'ARPG: Сервисная механика', 'title_en' => 'ARPG: Service Mechanics'],
    ];
}

function af_kb_arpg_type_definition(string $typeKey): array
{
    return (array)(af_kb_arpg_type_registry()[$typeKey] ?? []);
}

function af_kb_arpg_envelope_defaults(string $typeKey): array
{
    $typeDef = af_kb_arpg_type_definition($typeKey);
    $isService = !empty($typeDef['service']);
    $typeProfile = (string)($typeDef['entity_kind'] ?? $typeKey);
    $defaults = [
        'schema' => AF_KB_ARPG_META_SCHEMA,
        'mechanic' => 'arpg',
        'tags' => [],
        'ui' => [
            'icon_class' => '',
            'icon_url' => '',
            'background_url' => '',
            'background_tab_url' => '',
        ],
        'blocks' => [],
        'rules' => [
            'schema' => AF_KB_ARPG_RULES_SCHEMA,
            'type_profile' => $typeProfile,
            'version' => '1.0',
        ],
    ];

    if ($isService) {
        $defaults['rules']['type_profile'] = 'service_mechanics';
        $defaults['rules']['service_kind'] = 'mechanic_profile';
        $defaults['rules']['category'] = 'service.mechanics';
        $defaults['rules']['visibility'] = ['catalog' => false, 'search' => false, 'internal' => true];
        $defaults['rules']['stats_registry'] = [];
        $defaults['rules']['damage_type_registry'] = [];
        $defaults['rules']['targeting_registry'] = [];
        $defaults['rules']['resource_ops_registry'] = [];
        $defaults['rules']['modifier_modes_registry'] = [];
        $defaults['rules']['talent_rank_registry'] = [];
        $defaults['rules']['item_rarity_registry'] = [];
        $defaults['rules']['bestiary_rank_registry'] = [];
        $defaults['rules']['entries'] = [];
    }

    return $defaults;
}

function af_kb_default_type_profile_payload_arpg(string $typeKey): array
{
    $map = [
        'arpg_origin' => [
            'size' => 'medium',
            'creature_type' => 'humanoid',
            'base_hp' => 100,
            'base_damage' => 10,
            'base_defense' => 5,
            'movement_speed' => 100,
            'racial_bonuses_text' => '',
            'racial_traits_text' => '',
            'starting_notes' => '',
        ],
        'arpg_archetype' => [
            'role' => 'striker',
            'damage_bias' => 'high',
            'defense_bias' => 'low',
            'resource_affinity' => 'energy',
            'base_damage_bonus' => 0,
            'base_defense_bonus' => 0,
            'slot_rules_text' => '',
            'description_notes' => '',
        ],
        'arpg_faction' => [
            'standing_model' => 'neutral',
            'vendor_access_text' => '',
            'story_flags_text' => '',
            'description_text' => '',
        ],
        'arpg_lore' => [
            'linked_entities_text' => '',
            'timeline_text' => '',
            'source_text' => '',
        ],
        'arpg_ability' => [
            'type' => 'active',
            'subtype' => '',
            'slot' => 'skill_1',
            'damage_type' => 'physical',
            'targeting' => 'single_enemy',
            'range' => 0,
            'cast_time' => 0,
            'cooldown' => 0,
            'duration' => 0,
            'max_charges' => 1,
            'level_cap' => 20,
            'resources' => [],
            'effects' => [],
            'modifiers' => [],
            'triggers' => [],
            'conditions' => [],
            'stacking' => [],
            'upgrade_requirements' => [],
        ],
        'arpg_talent' => [
            'tree' => 'offense',
            'tier' => 1,
            'rank' => 'rare',
            'slot_type' => 'passive',
            'node_label' => '',
            'rank_weight' => 1,
            'socket_cost' => 1,
            'effects' => [],
            'passive_effects' => [],
            'modifiers' => [],
            'grants' => [],
            'requirements' => [],
            'mutual_exclusives' => [],
        ],
        'arpg_item' => [
            'item_kind' => 'weapon',
            'equip_slot' => 'weapon_one_hand',
            'rarity' => 'common',
            'subtype' => '',
            'level_min' => 1,
            'level_max' => 100,
            'progression_stage' => 'base',
            'level_cap' => 100,
            'base_stats' => [],
            'modifiers' => [],
            'effects' => [],
            'passive_effects' => [],
            'triggers' => [],
            'grants' => [],
            'upgrade_steps' => [],
            'weapon_class' => '',
            'base_damage' => 0,
            'damage_type' => 'physical',
            'attack_speed' => 0,
            'range' => 0,
            'crit_bonus' => 0,
            'armor_class' => '',
            'base_defense' => 0,
            'resist_profile_text' => '',
            'accessory_role' => '',
            'passive_focus_text' => '',
            'artifact_set_text' => '',
            'use_kind' => '',
            'stack_max' => 1,
            'use_cooldown' => 0,
            'material_grade' => '',
            'material_usage_text' => '',
            'quest_usage_text' => '',
        ],
        'arpg_bestiary' => [
            'family' => '',
            'archetype' => '',
            'faction' => '',
            'rank' => 'normal',
            'threat_tier' => 1,
            'level' => 1,
            'combat_stats' => ['hp' => 0, 'atk' => 0, 'def' => 0, 'armor' => 0, 'crit_rate' => 0, 'crit_dmg' => 0, 'status_hit' => 0, 'status_resist' => 0],
            'resists' => [],
            'weaknesses' => [],
            'ability_keys' => [],
            'loot' => [],
        ],
        'arpg_mechanics' => [
            'type_profile' => 'service_mechanics',
            'service_kind' => 'mechanic_profile',
            'category' => 'service.mechanics',
            'visibility' => ['catalog' => false, 'search' => false, 'internal' => true],
            'stats_registry' => [],
            'damage_type_registry' => [],
            'targeting_registry' => [],
            'resource_ops_registry' => [],
            'modifier_modes_registry' => [],
            'talent_rank_registry' => [],
            'item_rarity_registry' => [],
            'bestiary_rank_registry' => [],
            'entries' => [],
        ],
    ];

    return (array)($map[$typeKey] ?? []);
}

function af_kb_default_type_definitions(): array
{
    $statsFields = [
        ['path' => 'fixed_bonuses.stats.str', 'type' => 'number', 'label_ru' => 'STR', 'label_en' => 'STR', 'required' => true, 'default' => 0],
        ['path' => 'fixed_bonuses.stats.dex', 'type' => 'number', 'label_ru' => 'DEX', 'label_en' => 'DEX', 'required' => true, 'default' => 0],
        ['path' => 'fixed_bonuses.stats.con', 'type' => 'number', 'label_ru' => 'CON', 'label_en' => 'CON', 'required' => true, 'default' => 0],
        ['path' => 'fixed_bonuses.stats.int', 'type' => 'number', 'label_ru' => 'INT', 'label_en' => 'INT', 'required' => true, 'default' => 0],
        ['path' => 'fixed_bonuses.stats.wis', 'type' => 'number', 'label_ru' => 'WIS', 'label_en' => 'WIS', 'required' => true, 'default' => 0],
        ['path' => 'fixed_bonuses.stats.cha', 'type' => 'number', 'label_ru' => 'CHA', 'label_en' => 'CHA', 'required' => true, 'default' => 0],
    ];

    $base = [
        'schema' => 'af_kb.ui.v1',
        'version' => 1,
        'fields' => [
            ['path' => 'schema', 'type' => 'string', 'label_ru' => 'Схема', 'label_en' => 'Schema', 'required' => true, 'readonly' => true, 'default' => AF_KB_RULES_SCHEMA],
        ],
    ];

    $typeMap = [
        'race' => ['Расы', 'Races'],
        'race_variant' => ['Разновидности рас', 'Race Variants'],
        'class' => ['Классы', 'Classes'],
        'theme' => ['Темы', 'Themes'],
        'bestiary' => ['Бестиарий', 'Bestiary'],
        'skill' => ['Навыки', 'Skills'],
        'knowledge' => ['Знания', 'Knowledge'],
        'language' => ['Языки', 'Languages'],
        'item' => ['Предметы', 'Items'],
        'condition' => ['Состояния', 'Conditions'],
        'faction' => ['Фракции', 'Factions'],
        'perk' => ['Перки', 'Perks'],
        'spell' => ['Заклинания/ритуалы', 'Spells/Rituals'],
    ];

    $defs = [];
    foreach ($typeMap as $key => $titles) {
        $schema = $base;
        $rulesSchema = $key === 'item' ? 'af_kb.item.v2' : AF_KB_RULES_SCHEMA;

        $schema['title_ru'] = $titles[0] . ': параметры (' . $rulesSchema . ')';
        $schema['title_en'] = $titles[1] . ': rules (' . $rulesSchema . ')';
        $schema['root_defaults'] = ['schema' => $rulesSchema];

        if (in_array($key, ['race', 'race_variant', 'class', 'theme', 'skill', 'knowledge', 'perk', 'condition', 'spell'], true)) {
            $schema['root_defaults']['fixed_bonuses'] = [
                'stats' => ['str' => 0, 'dex' => 0, 'con' => 0, 'int' => 0, 'wis' => 0, 'cha' => 0],
                'hp' => 0,
                'ep' => 0,
            ];

            $schema['fields'] = array_merge($schema['fields'], $statsFields, [
                ['path' => 'fixed_bonuses.hp', 'type' => 'number', 'label_ru' => 'HP', 'label_en' => 'HP', 'required' => true, 'default' => 0],
                ['path' => 'fixed_bonuses.ep', 'type' => 'number', 'label_ru' => 'EP', 'label_en' => 'EP', 'required' => true, 'default' => 0],
                ['path' => 'effects', 'type' => 'array', 'label_ru' => 'Эффекты', 'label_en' => 'Effects', 'item' => ['type' => 'object', 'fields' => [
                    ['path' => 'op', 'type' => 'string', 'label_ru' => 'Операция', 'label_en' => 'Operation', 'required' => true],
                    ['path' => 'value', 'type' => 'number', 'label_ru' => 'Значение', 'label_en' => 'Value'],
                ]], 'default' => []],
            ]);
        }

        if (in_array($key, ['race', 'race_variant'], true)) {
            $schema['root_defaults'] += [
                'choices' => [],
                'traits' => [],
            ];

            $schema['fields'][] = [
                'path' => 'choices',
                'type' => 'array',
                'item' => [
                    'type' => 'object',
                    'fields' => [
                        ['path' => 'id', 'type' => 'string', 'required' => true],
                        [
                            'path' => 'type',
                            'type' => 'select',
                            'required' => true,
                            'options' => [
                                ['value' => 'language_pick'],
                                ['value' => 'stat_bonus'],
                                ['value' => 'kb_pick'],
                            ],
                        ],
                        ['path' => 'pick', 'type' => 'number', 'required' => true, 'default' => 1],
                        ['path' => 'kb_type', 'type' => 'string'],
                        ['path' => 'options', 'type' => 'array', 'item' => ['type' => 'string']],
                    ],
                ],
                'default' => [],
            ];

            $schema['fields'][] = [
                'path' => 'traits',
                'type' => 'array',
                'item' => [
                    'type' => 'object',
                    'fields' => [
                        ['path' => 'id', 'type' => 'string', 'required' => true],
                        ['path' => 'title', 'type' => 'i18n', 'required' => true],
                        ['path' => 'desc', 'type' => 'i18n', 'required' => true],
                        [
                            'path' => 'effects',
                            'type' => 'array',
                            'item' => [
                                'type' => 'object',
                                'fields' => [
                                    [
                                        'path' => 'op',
                                        'type' => 'select',
                                        'options' => [['value' => 'choice_ref']],
                                        'required' => true,
                                    ],
                                    ['path' => 'choice_id', 'type' => 'string', 'required' => true],
                                ],
                            ],
                        ],
                    ],
                ],
                'default' => [],
            ];
        }

        if (in_array($key, ['race', 'race_variant'], true)) {
            $schema['root_defaults'] += [
                'size' => 'medium',
                'creature_type' => 'humanoid',
                'speed' => 30,
                'languages' => ['common'],
                'hp_base' => 10,
                'effects' => [],
            ];

            $schema['fields'][] = [
                'path' => 'size',
                'type' => 'select',
                'label_ru' => 'Размер',
                'label_en' => 'Size',
                'required' => true,
                'options' => [
                    ['value' => 'tiny'],
                    ['value' => 'small'],
                    ['value' => 'medium'],
                    ['value' => 'large'],
                    ['value' => 'huge'],
                ],
                'default' => 'medium',
            ];

            $schema['fields'][] = [
                'path' => 'creature_type',
                'type' => 'select',
                'label_ru' => 'Тип существа',
                'label_en' => 'Creature type',
                'required' => true,
                'options' => [
                    ['value' => 'humanoid'],
                    ['value' => 'android'],
                    ['value' => 'construct'],
                    ['value' => 'mutant'],
                    ['value' => 'outsider'],
                    ['value' => 'beast'],
                    ['value' => 'undead'],
                    ['value' => 'other'],
                ],
                'default' => 'humanoid',
            ];

            $schema['fields'][] = ['path' => 'speed', 'type' => 'number', 'required' => true, 'default' => 30];
            $schema['fields'][] = ['path' => 'languages', 'type' => 'array', 'required' => true, 'item' => ['type' => 'string'], 'default' => ['common']];
            $schema['fields'][] = ['path' => 'hp_base', 'type' => 'number', 'required' => true, 'default' => 10];
        }

        if ($key === 'race_variant') {
            $schema['root_defaults'] += ['inherits_from_race' => true];
        }

        if (in_array($key, ['class', 'theme'], true)) {
            $schema['fields'][] = ['path' => 'hp_base', 'type' => 'number', 'label_ru' => 'Базовое HP', 'label_en' => 'Base HP', 'required' => false, 'default' => null];
        }

        if ($key === 'bestiary') {
            $schema['root_defaults'] = [
                'schema' => AF_KB_RULES_SCHEMA,
                'type_profile' => 'bestiary',
                'version' => '1.0',
                'creature' => [
                    'size' => 'medium',
                    'kind' => 'humanoid',
                    'alignment' => '',
                    'challenge_rating' => '1',
                    'xp' => 0,
                    'proficiency_bonus' => 2,
                    'armor_class' => 10,
                    'hp' => ['average' => 10, 'dice' => '2d8+2'],
                    'speed' => ['walk' => 30],
                    'ability_scores' => ['str' => 10, 'dex' => 10, 'con' => 10, 'int' => 10, 'wis' => 10, 'cha' => 10],
                    'saving_throws' => [],
                    'skills' => [],
                    'senses' => ['passive_perception' => 10],
                    'languages' => [],
                    'damage_vulnerabilities' => [],
                    'damage_resistances' => [],
                    'damage_immunities' => [],
                    'condition_immunities' => [],
                    'notes' => '',
                ],
                'traits' => [],
                'actions' => [],
                'reactions' => [],
                'legendary_actions' => [],
                'loot' => [],
                'gm_notes' => '',
            ];

            $schema['fields'] = [
                ['path' => 'schema', 'type' => 'string', 'required' => true, 'readonly' => true, 'default' => AF_KB_RULES_SCHEMA],
                ['path' => 'type_profile', 'type' => 'string', 'required' => true, 'readonly' => true, 'default' => 'bestiary'],
                ['path' => 'version', 'type' => 'string', 'required' => true, 'default' => '1.0'],
                ['path' => 'creature.size', 'type' => 'select', 'required' => true, 'options' => [['value' => 'tiny'], ['value' => 'small'], ['value' => 'medium'], ['value' => 'large'], ['value' => 'huge'], ['value' => 'gargantuan']], 'default' => 'medium'],
                ['path' => 'creature.kind', 'type' => 'string', 'required' => true, 'default' => 'humanoid'],
                ['path' => 'creature.challenge_rating', 'type' => 'string', 'required' => true, 'default' => '1'],
                ['path' => 'creature.xp', 'type' => 'number', 'required' => true, 'default' => 0],
                ['path' => 'creature.proficiency_bonus', 'type' => 'number', 'required' => true, 'default' => 2],
                ['path' => 'creature.armor_class', 'type' => 'number', 'required' => true, 'default' => 10],
                ['path' => 'creature.hp.average', 'type' => 'number', 'required' => true, 'default' => 10],
                ['path' => 'creature.hp.dice', 'type' => 'string', 'required' => true, 'default' => '2d8+2'],
                ['path' => 'creature.speed.walk', 'type' => 'number', 'required' => true, 'default' => 30],
                ['path' => 'creature.ability_scores.str', 'type' => 'number', 'required' => true, 'default' => 10],
                ['path' => 'creature.ability_scores.dex', 'type' => 'number', 'required' => true, 'default' => 10],
                ['path' => 'creature.ability_scores.con', 'type' => 'number', 'required' => true, 'default' => 10],
                ['path' => 'creature.ability_scores.int', 'type' => 'number', 'required' => true, 'default' => 10],
                ['path' => 'creature.ability_scores.wis', 'type' => 'number', 'required' => true, 'default' => 10],
                ['path' => 'creature.ability_scores.cha', 'type' => 'number', 'required' => true, 'default' => 10],
                ['path' => 'creature.damage_vulnerabilities', 'type' => 'array', 'item' => ['type' => 'string'], 'default' => []],
                ['path' => 'creature.damage_resistances', 'type' => 'array', 'item' => ['type' => 'string'], 'default' => []],
                ['path' => 'creature.damage_immunities', 'type' => 'array', 'item' => ['type' => 'string'], 'default' => []],
                ['path' => 'creature.condition_immunities', 'type' => 'array', 'item' => ['type' => 'string'], 'default' => []],
                ['path' => 'traits', 'type' => 'array', 'item' => ['type' => 'object', 'fields' => [['path' => 'name', 'type' => 'i18n', 'required' => true], ['path' => 'desc', 'type' => 'i18n', 'required' => true]]], 'default' => []],
                ['path' => 'actions', 'type' => 'array', 'item' => ['type' => 'object', 'fields' => [['path' => 'name', 'type' => 'i18n', 'required' => true], ['path' => 'desc', 'type' => 'i18n', 'required' => true], ['path' => 'attack_bonus', 'type' => 'number'], ['path' => 'damage', 'type' => 'string']]], 'default' => []],
                ['path' => 'reactions', 'type' => 'array', 'item' => ['type' => 'object', 'fields' => [['path' => 'name', 'type' => 'i18n', 'required' => true], ['path' => 'desc', 'type' => 'i18n', 'required' => true]]], 'default' => []],
                ['path' => 'legendary_actions', 'type' => 'array', 'item' => ['type' => 'object', 'fields' => [['path' => 'name', 'type' => 'i18n', 'required' => true], ['path' => 'desc', 'type' => 'i18n', 'required' => true], ['path' => 'cost', 'type' => 'number', 'default' => 1]]], 'default' => []],
                ['path' => 'loot', 'type' => 'array', 'item' => ['type' => 'object', 'fields' => [['path' => 'kind', 'type' => 'select', 'required' => true, 'options' => [['value' => 'item'], ['value' => 'resource'], ['value' => 'currency'], ['value' => 'table'], ['value' => 'note']]], ['path' => 'ref_type', 'type' => 'string'], ['path' => 'ref_key', 'type' => 'string'], ['path' => 'chance', 'type' => 'number'], ['path' => 'qty_min', 'type' => 'number'], ['path' => 'qty_max', 'type' => 'number'], ['path' => 'notes', 'type' => 'i18n']]], 'default' => []],
                ['path' => 'gm_notes', 'type' => 'string', 'default' => ''],
            ];
        }

        if ($key === 'item') {
            $schema['root_defaults'] = [
                'schema' => 'af_kb.item.v2',
                'item' => [
                    'item_kind' => 'gear',
                    'rarity' => 'common',
                    'price' => 0,
                    'currency' => '',
                    'weight' => 0,
                    'stack_max' => 1,
                    'equip' => ['slot' => '', 'armor' => ['ac_bonus' => 0, 'armor_type' => 'light']],
                    'tags' => [],
                    'on_use' => ['cooldown' => 0, 'cost' => (object)[], 'effects' => []],
                    'on_equip' => ['effects' => [], 'grants' => []],
                    'requirements' => ['level' => 0, 'tags_any' => [], 'tags_all' => []],
                ],
            ];

            $schema['fields'] = [
                ['path' => 'schema', 'type' => 'string', 'required' => true, 'readonly' => true, 'default' => 'af_kb.item.v2'],
                ['path' => 'item.item_kind', 'type' => 'select', 'label_ru' => 'Подтип предмета', 'label_en' => 'Item kind', 'required' => true, 'options_dynamic' => ['source' => 'kb_item_kinds'], 'default' => 'gear'],
                ['path' => 'item.rarity', 'type' => 'select', 'required' => true, 'options' => [['value'=>'common'],['value'=>'uncommon'],['value'=>'rare'],['value'=>'unique'],['value'=>'illegal'],['value'=>'restricted'],['value'=>'legendary'],['value'=>'mythic']], 'default' => 'common'],
                ['path' => 'item.price', 'type' => 'number', 'default' => 0],
                ['path' => 'item.unique_role', 'type' => 'select', 'options' => [['value'=>''],['value'=>'weapon'],['value'=>'armor'],['value'=>'augmentation'],['value'=>'artifact'],['value'=>'gear'],['value'=>'consumable'],['value'=>'ammo']]],
                ['path' => 'item.equip.slot', 'type' => 'select', 'required' => true, 'options' => [['value'=>''],['value'=>'head'],['value'=>'body'],['value'=>'hands'],['value'=>'legs'],['value'=>'feet'],['value'=>'back'],['value'=>'belt'],['value'=>'weapon_mainhand'],['value'=>'weapon_offhand'],['value'=>'weapon_twohand'],['value'=>'weapon_ranged'],['value'=>'weapon_melee'],['value'=>'support_1'],['value'=>'support_2'],['value'=>'support_3'],['value'=>'ammo'],['value'=>'ammo_pouch'],['value'=>'gear'],['value'=>'artifact'],['value'=>'accessory']]],
                ['path' => 'item.equip.armor.ac_bonus', 'type' => 'number', 'default' => 0],
                ['path' => 'item.equip.armor.armor_type', 'type' => 'select', 'options' => [['value'=>'light'],['value'=>'medium'],['value'=>'heavy']], 'default' => 'light'],
                ['path' => 'item.on_use.effects', 'type' => 'array', 'item' => ['type' => 'object', 'fields' => [['path'=>'op','type'=>'select','required'=>true,'options'=>[['value'=>'add_stat'],['value'=>'add_hp'],['value'=>'add_ep'],['value'=>'kb_grant'],['value'=>'set_flag']]],['path'=>'stat','type'=>'select','options'=>[['value'=>'str'],['value'=>'dex'],['value'=>'con'],['value'=>'int'],['value'=>'wis'],['value'=>'cha']]],['path'=>'value','type'=>'number'],['path'=>'kb_type','type'=>'string'],['path'=>'kb_key','type'=>'string'],['path'=>'flag','type'=>'string']]], 'default' => []],
            ];
        }

        $defs[] = [
            'type_key' => $key,
            'title_ru' => $titles[0],
            'title_en' => $titles[1],
            'desc_ru' => '',
            'desc_en' => '',
            'rules_schema' => $rulesSchema,
            'ui_schema_json' => json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'is_active' => $key === 'spell' ? 0 : 1,
            'sortorder' => count($defs),
        ];
    }

    foreach (af_kb_default_arpg_type_definitions() as $arpgDef) {
        $defs[] = $arpgDef;
    }

    return $defs;
}

function af_kb_default_arpg_type_definitions(): array
{
    $defs = [];
    foreach (af_kb_arpg_type_registry() as $typeKey => $typeDef) {
        $titles = [(string)($typeDef['title_ru'] ?? $typeKey), (string)($typeDef['title_en'] ?? $typeKey)];
        $isService = !empty($typeDef['service']);
        $rootDefaults = af_kb_arpg_envelope_defaults($typeKey);
        $requiredMap = [
            'arpg_origin' => ['rules.size', 'rules.creature_type', 'rules.base_hp', 'rules.base_damage', 'rules.base_defense', 'rules.movement_speed', 'rules.racial_bonuses_text', 'rules.racial_traits_text', 'rules.starting_notes'],
            'arpg_archetype' => ['rules.role', 'rules.damage_bias', 'rules.defense_bias', 'rules.resource_affinity', 'rules.base_damage_bonus', 'rules.base_defense_bonus', 'rules.slot_rules_text', 'rules.description_notes'],
            'arpg_faction' => ['rules.standing_model', 'rules.vendor_access_text', 'rules.story_flags_text', 'rules.description_text'],
            'arpg_lore' => ['rules.linked_entities_text', 'rules.timeline_text', 'rules.source_text'],
            'arpg_ability' => ['rules.type', 'rules.subtype', 'rules.slot', 'rules.damage_type', 'rules.targeting', 'rules.range', 'rules.cast_time', 'rules.cooldown', 'rules.duration', 'rules.max_charges', 'rules.level_cap', 'rules.resources', 'rules.effects', 'rules.modifiers', 'rules.triggers', 'rules.conditions', 'rules.stacking', 'rules.upgrade_requirements'],
            'arpg_talent' => ['rules.tree', 'rules.tier', 'rules.rank', 'rules.slot_type', 'rules.node_label', 'rules.rank_weight', 'rules.socket_cost', 'rules.effects', 'rules.passive_effects', 'rules.modifiers', 'rules.grants', 'rules.requirements', 'rules.mutual_exclusives'],
            'arpg_item' => ['rules.item_kind', 'rules.equip_slot', 'rules.rarity', 'rules.subtype', 'rules.level_min', 'rules.level_max', 'rules.progression_stage', 'rules.level_cap', 'rules.base_stats', 'rules.modifiers', 'rules.effects', 'rules.passive_effects', 'rules.triggers', 'rules.grants', 'rules.upgrade_steps'],
            'arpg_bestiary' => ['rules.family', 'rules.archetype', 'rules.faction', 'rules.rank', 'rules.threat_tier', 'rules.level', 'rules.combat_stats.hp', 'rules.combat_stats.atk', 'rules.combat_stats.def', 'rules.combat_stats.armor', 'rules.combat_stats.crit_rate', 'rules.combat_stats.crit_dmg', 'rules.combat_stats.status_hit', 'rules.combat_stats.status_resist', 'rules.resists', 'rules.weaknesses', 'rules.ability_keys', 'rules.loot'],
            'arpg_mechanics' => ['rules.service_kind', 'rules.category', 'rules.visibility.catalog', 'rules.visibility.search', 'rules.visibility.internal', 'rules.entries'],
        ];

        $fieldsMap = [
            'arpg_origin' => [
                ['path' => 'rules.size', 'type' => 'string', 'required' => true, 'default' => 'medium'],
                ['path' => 'rules.creature_type', 'type' => 'string', 'required' => true, 'default' => 'humanoid'],
                ['path' => 'rules.base_hp', 'type' => 'number', 'required' => true, 'default' => 100],
                ['path' => 'rules.base_damage', 'type' => 'number', 'required' => true, 'default' => 10],
                ['path' => 'rules.base_defense', 'type' => 'number', 'required' => true, 'default' => 5],
                ['path' => 'rules.movement_speed', 'type' => 'number', 'required' => true, 'default' => 100],
                ['path' => 'rules.racial_bonuses_text', 'type' => 'string', 'required' => true, 'default' => ''],
                ['path' => 'rules.racial_traits_text', 'type' => 'string', 'required' => true, 'default' => ''],
                ['path' => 'rules.starting_notes', 'type' => 'string', 'required' => true, 'default' => ''],
            ],
            'arpg_archetype' => [
                ['path' => 'rules.role', 'type' => 'string', 'required' => true, 'default' => 'striker'],
                ['path' => 'rules.damage_bias', 'type' => 'string', 'required' => true, 'default' => 'high'],
                ['path' => 'rules.defense_bias', 'type' => 'string', 'required' => true, 'default' => 'low'],
                ['path' => 'rules.resource_affinity', 'type' => 'string', 'required' => true, 'default' => 'energy'],
                ['path' => 'rules.base_damage_bonus', 'type' => 'number', 'required' => true, 'default' => 0],
                ['path' => 'rules.base_defense_bonus', 'type' => 'number', 'required' => true, 'default' => 0],
                ['path' => 'rules.slot_rules_text', 'type' => 'string', 'required' => true, 'default' => ''],
                ['path' => 'rules.description_notes', 'type' => 'string', 'required' => true, 'default' => ''],
            ],
            'arpg_faction' => [
                ['path' => 'rules.standing_model', 'type' => 'string', 'required' => true, 'default' => 'neutral'],
                ['path' => 'rules.vendor_access_text', 'type' => 'string', 'required' => true, 'default' => ''],
                ['path' => 'rules.story_flags_text', 'type' => 'string', 'required' => true, 'default' => ''],
                ['path' => 'rules.description_text', 'type' => 'string', 'required' => true, 'default' => ''],
            ],
            'arpg_lore' => [
                ['path' => 'rules.linked_entities_text', 'type' => 'string', 'required' => true, 'default' => ''],
                ['path' => 'rules.timeline_text', 'type' => 'string', 'required' => true, 'default' => ''],
                ['path' => 'rules.source_text', 'type' => 'string', 'required' => true, 'default' => ''],
            ],
            'arpg_ability' => [
                ['path' => 'rules.type', 'type' => 'string', 'required' => true, 'default' => 'active'],
                ['path' => 'rules.subtype', 'type' => 'string', 'required' => true, 'default' => ''],
                ['path' => 'rules.slot', 'type' => 'string', 'required' => true, 'default' => 'skill_1'],
                ['path' => 'rules.damage_type', 'type' => 'string', 'required' => true, 'default' => 'physical'],
                ['path' => 'rules.targeting', 'type' => 'string', 'required' => true, 'default' => 'single_enemy'],
                ['path' => 'rules.range', 'type' => 'number', 'required' => true, 'default' => 0],
                ['path' => 'rules.cast_time', 'type' => 'number', 'required' => true, 'default' => 0],
                ['path' => 'rules.cooldown', 'type' => 'number', 'required' => true, 'default' => 0],
                ['path' => 'rules.duration', 'type' => 'number', 'required' => true, 'default' => 0],
                ['path' => 'rules.max_charges', 'type' => 'number', 'required' => true, 'default' => 1],
                ['path' => 'rules.level_cap', 'type' => 'number', 'required' => true, 'default' => 20],
                ['path' => 'rules.resources', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.effects', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.modifiers', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.triggers', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.conditions', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.stacking', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.upgrade_requirements', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
            ],
            'arpg_talent' => [
                ['path' => 'rules.tree', 'type' => 'string', 'required' => true, 'default' => 'offense'],
                ['path' => 'rules.tier', 'type' => 'number', 'required' => true, 'default' => 1],
                ['path' => 'rules.rank', 'type' => 'string', 'required' => true, 'default' => 'rare'],
                ['path' => 'rules.slot_type', 'type' => 'string', 'required' => true, 'default' => 'passive'],
                ['path' => 'rules.node_label', 'type' => 'string', 'required' => true, 'default' => ''],
                ['path' => 'rules.rank_weight', 'type' => 'number', 'required' => true, 'default' => 1],
                ['path' => 'rules.socket_cost', 'type' => 'number', 'required' => true, 'default' => 1],
                ['path' => 'rules.effects', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.passive_effects', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.modifiers', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.grants', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.requirements', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.mutual_exclusives', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
            ],
            'arpg_item' => [
                ['path' => 'rules.item_kind', 'type' => 'string', 'required' => true, 'default' => 'weapon'],
                ['path' => 'rules.equip_slot', 'type' => 'string', 'required' => true, 'default' => 'weapon_one_hand'],
                ['path' => 'rules.rarity', 'type' => 'string', 'required' => true, 'default' => 'common'],
                ['path' => 'rules.subtype', 'type' => 'string', 'required' => true, 'default' => ''],
                ['path' => 'rules.level_min', 'type' => 'number', 'required' => true, 'default' => 1],
                ['path' => 'rules.level_max', 'type' => 'number', 'required' => true, 'default' => 100],
                ['path' => 'rules.progression_stage', 'type' => 'string', 'required' => true, 'default' => 'base'],
                ['path' => 'rules.level_cap', 'type' => 'number', 'required' => true, 'default' => 100],
                ['path' => 'rules.base_stats', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.modifiers', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.effects', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.passive_effects', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.triggers', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.grants', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.upgrade_steps', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.weapon_class', 'type' => 'string', 'default' => ''],
                ['path' => 'rules.base_damage', 'type' => 'number', 'default' => 0],
                ['path' => 'rules.damage_type', 'type' => 'string', 'default' => 'physical'],
                ['path' => 'rules.attack_speed', 'type' => 'number', 'default' => 0],
                ['path' => 'rules.range', 'type' => 'number', 'default' => 0],
                ['path' => 'rules.crit_bonus', 'type' => 'number', 'default' => 0],
                ['path' => 'rules.armor_class', 'type' => 'string', 'default' => ''],
                ['path' => 'rules.base_defense', 'type' => 'number', 'default' => 0],
                ['path' => 'rules.resist_profile_text', 'type' => 'string', 'default' => ''],
                ['path' => 'rules.accessory_role', 'type' => 'string', 'default' => ''],
                ['path' => 'rules.passive_focus_text', 'type' => 'string', 'default' => ''],
                ['path' => 'rules.artifact_set_text', 'type' => 'string', 'default' => ''],
                ['path' => 'rules.use_kind', 'type' => 'string', 'default' => ''],
                ['path' => 'rules.stack_max', 'type' => 'number', 'default' => 1],
                ['path' => 'rules.use_cooldown', 'type' => 'number', 'default' => 0],
                ['path' => 'rules.material_grade', 'type' => 'string', 'default' => ''],
                ['path' => 'rules.material_usage_text', 'type' => 'string', 'default' => ''],
                ['path' => 'rules.quest_usage_text', 'type' => 'string', 'default' => ''],
            ],
            'arpg_bestiary' => [
                ['path' => 'rules.family', 'type' => 'string', 'required' => true, 'default' => ''],
                ['path' => 'rules.archetype', 'type' => 'string', 'required' => true, 'default' => ''],
                ['path' => 'rules.faction', 'type' => 'string', 'required' => true, 'default' => ''],
                ['path' => 'rules.rank', 'type' => 'select', 'required' => true, 'options' => [['value' => 'normal'], ['value' => 'elite'], ['value' => 'boss'], ['value' => 'resource']], 'default' => 'normal'],
                ['path' => 'rules.threat_tier', 'type' => 'number', 'required' => true, 'default' => 1],
                ['path' => 'rules.level', 'type' => 'number', 'required' => true, 'default' => 1],
                ['path' => 'rules.combat_stats.hp', 'type' => 'number', 'required' => true, 'default' => 0],
                ['path' => 'rules.combat_stats.atk', 'type' => 'number', 'required' => true, 'default' => 0],
                ['path' => 'rules.combat_stats.def', 'type' => 'number', 'required' => true, 'default' => 0],
                ['path' => 'rules.combat_stats.armor', 'type' => 'number', 'required' => true, 'default' => 0],
                ['path' => 'rules.combat_stats.crit_rate', 'type' => 'number', 'required' => true, 'default' => 0],
                ['path' => 'rules.combat_stats.crit_dmg', 'type' => 'number', 'required' => true, 'default' => 0],
                ['path' => 'rules.combat_stats.status_hit', 'type' => 'number', 'required' => true, 'default' => 0],
                ['path' => 'rules.combat_stats.status_resist', 'type' => 'number', 'required' => true, 'default' => 0],
                ['path' => 'rules.resists', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object', 'fields' => [['path' => 'damage_type', 'type' => 'string', 'required' => true], ['path' => 'value', 'type' => 'number', 'required' => true], ['path' => 'notes', 'type' => 'string', 'default' => '']]], 'default' => []],
                ['path' => 'rules.weaknesses', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object', 'fields' => [['path' => 'damage_type', 'type' => 'string', 'required' => true], ['path' => 'value', 'type' => 'number', 'required' => true], ['path' => 'notes', 'type' => 'string', 'default' => '']]], 'default' => []],
                ['path' => 'rules.ability_keys', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object', 'fields' => [['path' => 'ability_key', 'type' => 'string', 'required' => true], ['path' => 'notes', 'type' => 'string', 'default' => '']]], 'default' => []],
                ['path' => 'rules.loot', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object', 'fields' => [['path' => 'loot_key', 'type' => 'string', 'required' => true], ['path' => 'kind', 'type' => 'string', 'required' => true], ['path' => 'qty_min', 'type' => 'number', 'required' => true], ['path' => 'qty_max', 'type' => 'number', 'required' => true], ['path' => 'chance', 'type' => 'number', 'required' => true], ['path' => 'notes', 'type' => 'string', 'default' => '']]], 'default' => []],
            ],
            'arpg_mechanics' => [
                ['path' => 'rules.service_kind', 'type' => 'select', 'required' => true, 'options' => array_map(static fn($kind) => ['value' => $kind], af_kb_arpg_service_entity_kinds())],
                ['path' => 'rules.category', 'type' => 'string', 'required' => true, 'readonly' => true, 'default' => 'service.mechanics'],
                ['path' => 'rules.visibility.catalog', 'type' => 'bool', 'required' => true, 'readonly' => true, 'default' => false],
                ['path' => 'rules.visibility.search', 'type' => 'bool', 'required' => true, 'readonly' => true, 'default' => false],
                ['path' => 'rules.visibility.internal', 'type' => 'bool', 'required' => true, 'readonly' => true, 'default' => true],
                ['path' => 'rules.stats_registry', 'type' => 'array', 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.damage_type_registry', 'type' => 'array', 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.targeting_registry', 'type' => 'array', 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.resource_ops_registry', 'type' => 'array', 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.modifier_modes_registry', 'type' => 'array', 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.talent_rank_registry', 'type' => 'array', 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.item_rarity_registry', 'type' => 'array', 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.bestiary_rank_registry', 'type' => 'array', 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.entries', 'type' => 'array', 'required' => true, 'item' => ['type' => 'object'], 'default' => []],
            ],
        ];

        $schema = [
            'schema' => 'af_kb.ui.v1',
            'version' => 1,
            'title_ru' => $titles[0] . ': параметры (' . AF_KB_ARPG_META_SCHEMA . ')',
            'title_en' => $titles[1] . ': rules (' . AF_KB_ARPG_META_SCHEMA . ')',
            'ui_profile' => 'arpg',
            'rules_enabled' => true,
            'ui_rules_editor' => true,
            'rules_schema' => AF_KB_ARPG_META_SCHEMA,
            'rules_required_keys' => ['schema', 'mechanic', 'tags', 'ui', 'blocks', 'rules'],
            'required_paths' => array_values($requiredMap[$typeKey] ?? []),
            'root_defaults' => $rootDefaults,
            'fields' => array_merge([
                ['path' => 'schema', 'type' => 'string', 'required' => true, 'readonly' => true, 'default' => AF_KB_ARPG_META_SCHEMA],
                ['path' => 'mechanic', 'type' => 'string', 'required' => true, 'readonly' => true, 'default' => 'arpg'],
                ['path' => 'tags', 'type' => 'array', 'item' => ['type' => 'string'], 'default' => []],
                ['path' => 'ui.icon_class', 'type' => 'string', 'default' => ''],
                ['path' => 'ui.icon_url', 'type' => 'string', 'default' => ''],
                ['path' => 'ui.background_url', 'type' => 'string', 'default' => ''],
                ['path' => 'ui.background_tab_url', 'type' => 'string', 'default' => ''],
                ['path' => 'blocks', 'type' => 'array', 'item' => ['type' => 'object'], 'default' => []],
                ['path' => 'rules.schema', 'type' => 'string', 'required' => true, 'readonly' => true, 'default' => AF_KB_ARPG_RULES_SCHEMA],
                ['path' => 'rules.type_profile', 'type' => 'string', 'required' => true, 'readonly' => true, 'default' => (string)($rootDefaults['rules']['type_profile'] ?? '')],
                ['path' => 'rules.version', 'type' => 'string', 'required' => true, 'default' => '1.0'],
            ], (array)($fieldsMap[$typeKey] ?? [])),
        ];

        $schema['root_defaults']['rules'] = array_replace_recursive(
            (array)($schema['root_defaults']['rules'] ?? []),
            af_kb_default_type_profile_payload_arpg($typeKey)
        );

        $defs[] = [
            'type_key' => $typeKey,
            'title_ru' => $titles[0],
            'title_en' => $titles[1],
            'desc_ru' => '',
            'desc_en' => '',
            'rules_schema' => AF_KB_ARPG_META_SCHEMA,
            'ui_schema_json' => json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'mechanic_key' => 'arpg',
            'is_active' => $isService ? 0 : 1,
            'sortorder' => 100 + count($defs),
        ];
    }

    return $defs;
}

function af_kb_default_item_kind_definitions(): array
{
    return [
        ['kind_key' => 'weapon', 'title_ru' => 'Оружие', 'title_en' => 'Weapon', 'ui_schema_json' => '{"schema":"af_kb.ui.overlay.v1","version":1,"patch":[{"op":"set_defaults","defaults":{"equip":{"slot":"weapon_mainhand","unique":true,"two_handed":false,"stackable":false}}},{"op":"set_required","path":"equip.slot","required":true}]}', 'sortorder' => 10],
        ['kind_key' => 'armor', 'title_ru' => 'Броня', 'title_en' => 'Armor', 'ui_schema_json' => '{"schema":"af_kb.ui.overlay.v1","version":1,"patch":[{"op":"set_defaults","defaults":{"equip":{"slot":"body","unique":true,"stackable":false}}},{"op":"set_required","path":"equip.slot","required":true}]}', 'sortorder' => 20],
        ['kind_key' => 'gear', 'title_ru' => 'Снаряжение', 'title_en' => 'Gear', 'ui_schema_json' => '{"schema":"af_kb.ui.overlay.v1","version":1,"patch":[]}', 'sortorder' => 30],
        ['kind_key' => 'consumable', 'title_ru' => 'Расходник', 'title_en' => 'Consumable', 'ui_schema_json' => '{"schema":"af_kb.ui.overlay.v1","version":1,"patch":[{"op":"set_defaults","defaults":{"equip":{"slot":"support_1","stackable":true}}}]}', 'sortorder' => 40],
        ['kind_key' => 'ammo', 'title_ru' => 'Боеприпас', 'title_en' => 'Ammo', 'ui_schema_json' => '{"schema":"af_kb.ui.overlay.v1","version":1,"patch":[{"op":"set_defaults","defaults":{"equip":{"slot":"ammo"}}}]}', 'sortorder' => 50],
        ['kind_key' => 'augmentation', 'title_ru' => 'Аугментация', 'title_en' => 'Augmentation', 'ui_schema_json' => '{"schema":"af_kb.ui.overlay.v1","version":1,"patch":[]}', 'sortorder' => 60],
        ['kind_key' => 'artifact', 'title_ru' => 'Артефакт', 'title_en' => 'Artifact', 'ui_schema_json' => '{"schema":"af_kb.ui.overlay.v1","version":1,"patch":[{"op":"set_defaults","defaults":{"equip":{"slot":"artifact"}}}]}', 'sortorder' => 70],
        ['kind_key' => 'unique', 'title_ru' => 'Уникальный', 'title_en' => 'Unique', 'ui_schema_json' => '{"schema":"af_kb.ui.overlay.v1","version":1,"patch":[{"op":"set_defaults","defaults":{"unique_role":"gear","equip":{"slot":"gear"}}}]}', 'sortorder' => 80],
    ];
}

function af_kb_normalize_item_kind(string $kind): string
{
    $kind = strtolower(trim($kind));
    if ($kind === '') {
        return '';
    }

    $aliases = [
        'cyberware' => 'augmentation',
        'implant' => 'augmentation',
        'weapon_offhand' => 'weapon',
        'helmet' => 'armor',
    ];

    return $aliases[$kind] ?? $kind;
}

/* -------------------- LANG -------------------- */

function af_knowledgebase_load_lang(bool $admin = false): void
{
    global $lang;

    if (!is_object($lang)) {
        if (class_exists('MyLanguage')) {
            $lang = new MyLanguage();
        } else {
            return;
        }
    }

    $base = 'advancedfunctionality_' . AF_KB_ID;

    $langFolder = !empty($lang->language) ? (string)$lang->language : 'russian';
    $expectedFile = MYBB_ROOT . 'inc/languages/' . $langFolder . '/' . $base . '.lang.php';

    if (!is_file($expectedFile) && function_exists('af_sync_addon_languages')) {
        try {
            af_sync_addon_languages();
        } catch (Throwable $e) {
            // ignore
        }
    }

    if (!is_file($expectedFile)) {
        return;
    }

    if ($admin) {
        $lang->load($base, true, true);
    } else {
        $lang->load($base);
    }
}

/* -------------------- SETTINGS HELPERS -------------------- */

function af_kb_setting_name(string $key): string
{
    return 'af_' . $key;
}

function af_kb_get_setting(string $key, $default = null)
{
    global $mybb;
    return $mybb->settings[$key] ?? $default;
}

function af_kb_get_default_mechanic_mode(): string
{
    $raw = (string)af_kb_get_setting('af_kb_default_mechanic_mode', AF_KB_DEFAULT_MECHANIC_KEY);
    $normalized = strtolower(trim($raw));
    if ($normalized === 'arpg') {
        return 'arpg';
    }

    return AF_KB_DEFAULT_MECHANIC_KEY;
}

function af_kb_get_catalog_active_mechanic_key(): string
{
    return af_kb_get_default_mechanic_mode();
}

function af_kb_sql_mechanic_filter(string $column, ?string $mechanicKey = null): string
{
    global $db;

    $normalized = af_kb_normalize_mechanic_key((string)($mechanicKey ?? af_kb_get_catalog_active_mechanic_key()));
    if (!af_kb_is_allowed_mechanic_key($normalized)) {
        $normalized = AF_KB_DEFAULT_MECHANIC_KEY;
    }

    $escapedDefault = $db->escape_string(AF_KB_DEFAULT_MECHANIC_KEY);
    $escapedMechanic = $db->escape_string($normalized);
    return "(LOWER(COALESCE(NULLIF({$column}, ''), '{$escapedDefault}'))='{$escapedMechanic}')";
}

function af_kb_get_mechanic_options(): array
{
    return [
        'dnd' => 'DnD-механика',
        'arpg' => 'ARPG-механика',
    ];
}

function af_kb_is_allowed_mechanic_key(string $mechanicKey): bool
{
    $options = af_kb_get_mechanic_options();
    return isset($options[af_kb_normalize_mechanic_key($mechanicKey)]);
}

function af_kb_ensure_group(string $name, string $title, string $desc): int
{
    global $db;

    $q = $db->simple_select('settinggroups', 'gid', "name='".$db->escape_string($name)."'", ['limit' => 1]);
    $gid = (int)$db->fetch_field($q, 'gid');
    if ($gid) {
        return $gid;
    }

    $max = $db->fetch_field($db->simple_select('settinggroups', 'MAX(disporder) AS m'), 'm');
    $disp = (int)$max + 1;

    $db->insert_query('settinggroups', [
        'name'        => $db->escape_string($name),
        'title'       => $db->escape_string($title),
        'description' => $db->escape_string($desc),
        'disporder'   => $disp,
        'isdefault'   => 0,
    ]);

    return (int)$db->insert_id();
}

function af_kb_ensure_setting(int $gid, string $name, string $title, string $desc, string $type, string $value, int $order): void
{
    global $db;

    $q = $db->simple_select('settings', 'sid', "name='".$db->escape_string($name)."'", ['limit' => 1]);
    $sid = (int)$db->fetch_field($q, 'sid');

    $row = [
        'name'        => $db->escape_string($name),
        'title'       => $db->escape_string($title),
        'description' => $db->escape_string($desc),
        'optionscode' => $db->escape_string($type),
        'value'       => $db->escape_string($value),
        'disporder'   => $order,
        'gid'         => $gid,
    ];

    if ($sid) {
        $db->update_query('settings', $row, "sid={$sid}");
    } else {
        $db->insert_query('settings', $row);
    }
}

function af_kb_migrate_legacy_categories_ui_setting(): void
{
    global $db;

    $legacyUi = $db->fetch_array($db->simple_select('settings', 'sid,value', "name='af_kb_categories_ui'", ['limit' => 1]));
    if (!empty($legacyUi['sid'])) {
        $legacyValue = ((string)($legacyUi['value'] ?? 'sidebar') === 'top') ? 'top' : 'sidebar';
        $db->update_query('settings', ['value' => $db->escape_string($legacyValue)], "name='af_kb_categories_ui_position'");
        $db->delete_query('settings', "name='af_kb_categories_ui'");
    }
}

function af_kb_ensure_categories_ui_position_setting(int $gid, string $title, string $desc): void
{
    global $db;

    $name = 'af_kb_categories_ui_position';
    $optionsCode = "select\nsidebar=Sidebar\ntop=Top block";

    $existing = $db->fetch_array($db->simple_select(
        'settings',
        'sid,value',
        "name='" . $db->escape_string($name) . "'",
        ['limit' => 1]
    ));

    if (!empty($existing['sid'])) {
        $updateRow = [
            'title'       => $db->escape_string($title),
            'description' => $db->escape_string($desc),
            'optionscode' => $db->escape_string($optionsCode),
            'disporder'   => 10,
            'gid'         => $gid,
        ];

        if (trim((string)($existing['value'] ?? '')) === '') {
            $updateRow['value'] = 'sidebar';
        }

        $db->update_query('settings', $updateRow, "sid=" . (int)$existing['sid']);
        return;
    }

    af_kb_ensure_setting(
        $gid,
        $name,
        $title,
        $desc,
        $optionsCode,
        'sidebar',
        10
    );
}

function af_kb_ensure_default_mechanic_mode_setting(int $gid, string $title, string $desc): void
{
    $optionsCode = "select\ndnd=DnD-механика\narpg=ARPG-механика";
    af_kb_ensure_setting(
        $gid,
        'af_kb_default_mechanic_mode',
        $title,
        $desc,
        $optionsCode,
        AF_KB_DEFAULT_MECHANIC_KEY,
        11
    );
}

/* -------------------- INSTALL / UNINSTALL -------------------- */
function af_knowledgebase_install(): bool
{
    global $db, $lang;

    af_knowledgebase_load_lang(true);

    if (!$db->table_exists('af_kb_types')) {
        $sql = <<<SQL
CREATE TABLE {TABLE_PREFIX}af_kb_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(64) NOT NULL UNIQUE,
  mechanic_key VARCHAR(32) NOT NULL DEFAULT 'dnd',
  title_ru VARCHAR(255) NOT NULL DEFAULT '',
  title_en VARCHAR(255) NOT NULL DEFAULT '',
  short_ru TEXT NOT NULL,
  short_en TEXT NOT NULL,
  description_ru TEXT NOT NULL,
  description_en TEXT NOT NULL,
  icon_class VARCHAR(128) NOT NULL DEFAULT '',
  icon_url VARCHAR(255) NOT NULL DEFAULT '',
  banner_url VARCHAR(255) NOT NULL DEFAULT '',
  bg_url VARCHAR(255) NOT NULL DEFAULT '',
  bg_tab_url VARCHAR(255) NOT NULL DEFAULT '',
  sortorder INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        $db->write_query(str_replace('{TABLE_PREFIX}', TABLE_PREFIX, $sql));
    }


    if (!$db->table_exists('af_kb_item_kinds')) {
        $sql = <<<SQL
CREATE TABLE {TABLE_PREFIX}af_kb_item_kinds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind_key VARCHAR(64) NOT NULL UNIQUE,
  title_ru VARCHAR(255) NOT NULL,
  title_en VARCHAR(255) NOT NULL,
  desc_ru TEXT NULL,
  desc_en TEXT NULL,
  ui_schema_json MEDIUMTEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sortorder INT NOT NULL DEFAULT 0,
  updated_at INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        $db->write_query(str_replace('{TABLE_PREFIX}', TABLE_PREFIX, $sql));
    }

    if (!$db->table_exists('af_kb_entries')) {
        $sql = <<<SQL
CREATE TABLE {TABLE_PREFIX}af_kb_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(64) NOT NULL,
  `key` VARCHAR(64) NOT NULL,
  title_ru VARCHAR(255) NOT NULL DEFAULT '',
  title_en VARCHAR(255) NOT NULL DEFAULT '',
  short_ru TEXT NOT NULL,
  short_en TEXT NOT NULL,
  body_ru MEDIUMTEXT NOT NULL,
  body_en MEDIUMTEXT NOT NULL,
  tech_ru TEXT NOT NULL,
  tech_en TEXT NOT NULL,
  meta_json MEDIUMTEXT NOT NULL,
  data_json MEDIUMTEXT NOT NULL,
  item_kind VARCHAR(64) NULL,
  icon_class VARCHAR(128) NOT NULL DEFAULT '',
  icon_url VARCHAR(255) NOT NULL DEFAULT '',
  banner_url VARCHAR(255) NOT NULL DEFAULT '',
  bg_url VARCHAR(255) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  sortorder INT NOT NULL DEFAULT 0,
  updated_at INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_type_key (type, `key`),
  KEY type_active_sort (type, active, sortorder),
  KEY item_kind (item_kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        $db->write_query(str_replace('{TABLE_PREFIX}', TABLE_PREFIX, $sql));
    }

    if (!$db->table_exists('af_kb_blocks')) {
        $sql = <<<SQL
CREATE TABLE {TABLE_PREFIX}af_kb_blocks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_id INT UNSIGNED NOT NULL,
  block_key VARCHAR(64) NOT NULL DEFAULT '',
  title_ru VARCHAR(255) NOT NULL DEFAULT '',
  title_en VARCHAR(255) NOT NULL DEFAULT '',
  content_ru MEDIUMTEXT NOT NULL,
  content_en MEDIUMTEXT NOT NULL,
  data_json MEDIUMTEXT NOT NULL,
  icon_class VARCHAR(128) NOT NULL DEFAULT '',
  icon_url VARCHAR(255) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  sortorder INT NOT NULL DEFAULT 0,
  KEY entry_sort (entry_id, sortorder),
  KEY entry_block_key (entry_id, block_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        $db->write_query(str_replace('{TABLE_PREFIX}', TABLE_PREFIX, $sql));
    }

    if (!$db->table_exists('af_kb_categories')) {
        $sql = <<<SQL
CREATE TABLE {TABLE_PREFIX}af_kb_categories (
  cat_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(64) NOT NULL,
  parent_id INT UNSIGNED NOT NULL DEFAULT 0,
  `key` VARCHAR(64) NOT NULL,
  title_ru VARCHAR(255) NOT NULL DEFAULT '',
  title_en VARCHAR(255) NOT NULL DEFAULT '',
  description_ru MEDIUMTEXT NULL,
  description_en MEDIUMTEXT NULL,
  sortorder INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_type_key (type, `key`),
  KEY type_parent_sort (type, parent_id, sortorder),
  KEY type_active (type, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        $db->write_query(str_replace('{TABLE_PREFIX}', TABLE_PREFIX, $sql));
    }

    if (!$db->table_exists('af_kb_entry_categories')) {
        $sql = <<<SQL
CREATE TABLE {TABLE_PREFIX}af_kb_entry_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_id INT UNSIGNED NOT NULL,
  cat_id INT UNSIGNED NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_entry_cat (entry_id, cat_id),
  KEY cat_id_idx (cat_id),
  KEY entry_id_idx (entry_id),
  KEY entry_primary (entry_id, is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        $db->write_query(str_replace('{TABLE_PREFIX}', TABLE_PREFIX, $sql));
    }

    if (!$db->table_exists('af_kb_relations')) {
        $sql = <<<SQL
CREATE TABLE {TABLE_PREFIX}af_kb_relations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  from_type VARCHAR(64) NOT NULL,
  from_key VARCHAR(64) NOT NULL,
  rel_type VARCHAR(64) NOT NULL,
  to_type VARCHAR(64) NOT NULL,
  to_key VARCHAR(64) NOT NULL,
  meta_json MEDIUMTEXT NOT NULL,
  sortorder INT NOT NULL DEFAULT 0,
  KEY from_idx (from_type, from_key, rel_type),
  KEY to_idx (to_type, to_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        $db->write_query(str_replace('{TABLE_PREFIX}', TABLE_PREFIX, $sql));
    }

    if (!$db->table_exists('af_kb_log')) {
        $sql = <<<SQL
CREATE TABLE {TABLE_PREFIX}af_kb_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uid INT UNSIGNED NOT NULL,
  action VARCHAR(32) NOT NULL,
  type VARCHAR(64) NOT NULL,
  `key` VARCHAR(64) NOT NULL,
  diff_json MEDIUMTEXT NOT NULL,
  dateline INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        $db->write_query(str_replace('{TABLE_PREFIX}', TABLE_PREFIX, $sql));
    }

    $gid = af_kb_ensure_group(
        'af_knowledgebase',
        $lang->af_knowledgebase_group ?? 'AF: Knowledge Base',
        $lang->af_knowledgebase_group_desc ?? 'Settings for Knowledge Base addon.'
    );

    af_kb_ensure_setting(
        $gid,
        'af_knowledgebase_enabled',
        $lang->af_knowledgebase_enabled ?? 'Enable Knowledge Base',
        $lang->af_knowledgebase_enabled_desc ?? 'Yes/No',
        'yesno',
        '1',
        1
    );
    af_kb_ensure_setting(
        $gid,
        'af_kb_public_catalog',
        $lang->af_kb_public_catalog ?? 'Public catalog',
        $lang->af_kb_public_catalog_desc ?? 'Show catalog for everyone.',
        'yesno',
        '1',
        2
    );
    af_kb_ensure_setting(
        $gid,
        'af_kb_nav_link_enabled',
        $lang->af_kb_nav_link_enabled ?? 'KB nav link',
        $lang->af_kb_nav_link_enabled_desc ?? 'Show Knowledge Base link in the top navigation.',
        'yesno',
        '1',
        3
    );
    af_kb_ensure_setting(
        $gid,
        'af_kb_assets_blacklist',
        'KB assets blacklist',
        'One per line: script.php or script.php?action=value. KB JS/CSS will not be injected on matching pages.',
        'textarea',
        'index.php',
        4
    );
    af_kb_ensure_setting(
        $gid,
        'af_kb_editor_groups',
        $lang->af_kb_editor_groups ?? 'Editor groups',
        $lang->af_kb_editor_groups_desc ?? 'CSV of group IDs that can edit.',
        'text',
        '',
        5
    );
    af_kb_ensure_setting(
        $gid,
        'af_kb_types_manage_groups',
        $lang->af_kb_types_manage_groups ?? 'Type management groups',
        $lang->af_kb_types_manage_groups_desc ?? 'CSV of group IDs that can manage types.',
        'text',
        '',
        6
    );
    af_kb_ensure_setting(
        $gid,
        'af_kb_atf_map',
        $lang->af_kb_atf_map ?? 'ATF → KB mapping',
        $lang->af_kb_atf_map_desc ?? 'JSON mapping of ATF field → KB type.',
        'textarea',
        '{}',
        7
    );
    af_kb_ensure_setting(
        $gid,
        'af_kb_manage_groups',
        'KB categories management groups',
        'CSV of group IDs that can manage KB categories and entry mappings.',
        'text',
        '3,4',
        8
    );
    af_kb_ensure_setting(
        $gid,
        'af_kb_categories_enabled',
        'Enable KB categories',
        'Yes/No',
        'yesno',
        '1',
        9
    );
    af_kb_ensure_setting(
        $gid,
        'af_kb_categories_require_primary',
        'Require primary category',
        'Yes/No',
        'yesno',
        '0',
        10
    );
    af_kb_ensure_categories_ui_position_setting(
        $gid,
        $lang->af_kb_categories_ui_position ?? 'KB categories UI position',
        $lang->af_kb_categories_ui_position_desc ?? 'Sidebar or top block for categories tree.'
    );
    af_kb_ensure_default_mechanic_mode_setting(
        $gid,
        $lang->af_kb_default_mechanic_mode ?? 'KB default mechanic mode',
        $lang->af_kb_default_mechanic_mode_desc ?? 'Preferred/default KB mechanic mode for new types and upcoming UI.'
    );

    af_kb_migrate_legacy_categories_ui_setting();

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
    if (function_exists('af_rebuild_and_reload_settings')) {
        af_rebuild_and_reload_settings();
    }

    af_kb_templates_install_or_update();
    af_kb_ensure_schema();
    af_kb_seed_defaults();
    af_kb_ensure_alias_file();

    return true;
}

function af_knowledgebase_uninstall(): bool
{
    global $db;
    // DO NOT DROP KB TABLES OR DELETE ENTRIES ON UNINSTALL (Hanna requirement)

    $db->delete_query('settings', "name IN ('af_knowledgebase_enabled','af_kb_public_catalog','af_kb_nav_link_enabled','af_kb_assets_blacklist','af_kb_editor_groups','af_kb_types_manage_groups','af_kb_atf_map','af_kb_manage_groups','af_kb_categories_enabled','af_kb_categories_require_primary','af_kb_categories_ui_position','af_kb_categories_ui','af_kb_default_mechanic_mode')");
    $db->delete_query('settinggroups', "name='af_knowledgebase'");
    $db->delete_query('templates', "title LIKE 'knowledgebase_%'");

    af_kb_remove_alias_file_if_owned();

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
    if (function_exists('af_rebuild_and_reload_settings')) {
        af_rebuild_and_reload_settings();
    }

    return true;
}

function af_knowledgebase_activate(): bool
{
    global $lang;

    af_knowledgebase_load_lang(true);

    $gid = af_kb_ensure_settinggroup(
        'af_knowledgebase',
        $lang->af_knowledgebase_group ?? 'AF: Knowledge Base',
        $lang->af_knowledgebase_group_desc ?? 'Settings for Knowledge Base addon.'
    );

    af_kb_ensure_categories_ui_position_setting(
        $gid,
        $lang->af_kb_categories_ui_position ?? 'KB categories UI position',
        $lang->af_kb_categories_ui_position_desc ?? 'Sidebar or top block for categories tree.'
    );
    af_kb_ensure_default_mechanic_mode_setting(
        $gid,
        $lang->af_kb_default_mechanic_mode ?? 'KB default mechanic mode',
        $lang->af_kb_default_mechanic_mode_desc ?? 'Preferred/default KB mechanic mode for new types and upcoming UI.'
    );
    af_kb_ensure_setting(
        $gid,
        'af_kb_assets_blacklist',
        'KB assets blacklist',
        'One per line: script.php or script.php?action=value. KB JS/CSS will not be injected on matching pages.',
        'textarea',
        'index.php',
        4
    );
    af_kb_migrate_legacy_categories_ui_setting();

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
    if (function_exists('af_rebuild_and_reload_settings')) {
        af_rebuild_and_reload_settings();
    }

    af_kb_templates_install_or_update();
    af_kb_ensure_schema();
    af_kb_seed_defaults();
    af_kb_ensure_alias_file();
    return true;
}

function af_knowledgebase_deactivate(): bool
{
    af_kb_remove_alias_file_if_owned();
    return true;
}

function af_kb_alias_target_path(): string
{
    return MYBB_ROOT . 'kb.php';
}

function af_kb_alias_asset_path(): string
{
    return AF_KB_ASSETS . 'kb.php';
}

function af_kb_alias_is_ours(?string $path = null): bool
{
    $path = $path ?? af_kb_alias_target_path();
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }

    $content = (string)@file_get_contents($path);
    return strpos($content, AF_KB_ALIAS_MARKER) !== false;
}

function af_kb_alias_available(): bool
{
    if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'kb.php') {
        return true;
    }

    return af_kb_alias_is_ours();
}

function af_kb_ensure_alias_file(): bool
{
    $target = af_kb_alias_target_path();
    $asset = af_kb_alias_asset_path();

    if (!is_file($asset) || !is_readable($asset)) {
        return false;
    }

    if (is_file($target) && !af_kb_alias_is_ours($target)) {
        if (defined('IN_ADMINCP') && function_exists('flash_message')) {
            flash_message('KnowledgeBase: kb.php already exists and is not managed by AF, alias was not installed. Fallback to misc.php is enabled.', 'error');
        }
        return false;
    }

    return (bool)@copy($asset, $target);
}

function af_kb_remove_alias_file_if_owned(): void
{
    $target = af_kb_alias_target_path();
    if (af_kb_alias_is_ours($target)) {
        @unlink($target);
    }
}

function af_kb_url(array $params = [], bool $html = false): string
{
    $useAlias = af_kb_alias_available();
    $script = $useAlias ? 'kb.php' : 'misc.php';

    if (!$useAlias) {
        $params = array_merge(['action' => 'kb'], $params);
    }

    if ($useAlias && (($params['action'] ?? '') === 'kb' || ($params['action'] ?? '') === 'index')) {
        unset($params['action']);
    }

    $url = $script;
    if (!empty($params)) {
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $url .= '?' . ($html ? str_replace('&', '&amp;', $query) : $query);
    }

    return $url;
}

/* -------------------- TEMPLATES -------------------- */

function af_kb_templates_install_or_update(): void
{
    global $db;

    if (!is_dir(AF_KB_TPL_DIR)) {
        return;
    }

    $files = glob(AF_KB_TPL_DIR . '*.html');
    if (!$files) {
        return;
    }

    foreach ($files as $file) {
        $name = basename($file, '.html');
        if ($name === '') {
            continue;
        }
        $tpl = @file_get_contents($file);
        if ($tpl === false) {
            continue;
        }

        $title = $db->escape_string($name);
        $existing = $db->simple_select('templates', 'tid', "title='{$title}' AND sid='-1'", ['limit' => 1]);
        $tid = (int)$db->fetch_field($existing, 'tid');

        $row = [
            'title'    => $title,
            'template' => $db->escape_string($tpl),
            'sid'      => -1,
            'version'  => '1839',
            'dateline' => TIME_NOW,
        ];

        if ($tid) {
            $db->update_query('templates', $row, "tid='{$tid}'");
        } else {
            $db->insert_query('templates', $row);
        }
    }
}

function af_kb_ensure_schema(): void
{
    global $db;

    if ($db->table_exists('af_kb_types')) {

        if (!$db->field_exists('type_key', 'af_kb_types')) {
            $db->add_column('af_kb_types', 'type_key', "VARCHAR(64) NOT NULL DEFAULT ''");
            $db->write_query("UPDATE ".TABLE_PREFIX."af_kb_types SET type_key=type WHERE type_key=''");
        }
        if (!$db->field_exists('mechanic_key', 'af_kb_types')) {
            $db->add_column('af_kb_types', 'mechanic_key', "VARCHAR(32) NOT NULL DEFAULT '" . AF_KB_DEFAULT_MECHANIC_KEY . "'");
        }
        $db->write_query(
            "UPDATE " . TABLE_PREFIX . "af_kb_types"
            . " SET mechanic_key='" . $db->escape_string(AF_KB_DEFAULT_MECHANIC_KEY) . "'"
            . " WHERE mechanic_key IS NULL OR mechanic_key=''"
        );
        if (!$db->field_exists('desc_ru', 'af_kb_types')) {
            $db->add_column('af_kb_types', 'desc_ru', "TEXT NULL");
        }
        if (!$db->field_exists('desc_en', 'af_kb_types')) {
            $db->add_column('af_kb_types', 'desc_en', "TEXT NULL");
        }
        if (!$db->field_exists('rules_schema', 'af_kb_types')) {
            $db->add_column('af_kb_types', 'rules_schema', "VARCHAR(64) NOT NULL DEFAULT 'af_kb.rules.v1'");
        }
        if (!$db->field_exists('ui_schema_json', 'af_kb_types')) {
            $db->add_column('af_kb_types', 'ui_schema_json', "MEDIUMTEXT NOT NULL");
            $db->write_query("UPDATE ".TABLE_PREFIX."af_kb_types SET ui_schema_json='{}' WHERE ui_schema_json=''");
        }
        if (!$db->field_exists('is_active', 'af_kb_types')) {
            $db->add_column('af_kb_types', 'is_active', "TINYINT(1) NOT NULL DEFAULT 1");
            $db->write_query("UPDATE ".TABLE_PREFIX."af_kb_types SET is_active=active");
        }
        if (!$db->field_exists('updated_at', 'af_kb_types')) {
            $db->add_column('af_kb_types', 'updated_at', "INT UNSIGNED NOT NULL DEFAULT 0");
        }
        if (!$db->field_exists('icon_class', 'af_kb_types')) {
            $db->add_column('af_kb_types', 'icon_class', "VARCHAR(128) NOT NULL DEFAULT ''");
        }
        if (!$db->field_exists('icon_url', 'af_kb_types')) {
            $db->add_column('af_kb_types', 'icon_url', "VARCHAR(255) NOT NULL DEFAULT ''");
        }
        if (!$db->field_exists('banner_url', 'af_kb_types')) {
            $db->add_column('af_kb_types', 'banner_url', "VARCHAR(255) NOT NULL DEFAULT ''");
        }
        if (!$db->field_exists('short_ru', 'af_kb_types')) {
            $db->add_column('af_kb_types', 'short_ru', "TEXT NOT NULL");
        }
        if (!$db->field_exists('short_en', 'af_kb_types')) {
            $db->add_column('af_kb_types', 'short_en', "TEXT NOT NULL");
        }
        if (!$db->field_exists('bg_url', 'af_kb_types')) {
            $db->add_column('af_kb_types', 'bg_url', "VARCHAR(255) NOT NULL DEFAULT ''");
        }
        if (!$db->field_exists('bg_tab_url', 'af_kb_types')) {
            $db->add_column('af_kb_types', 'bg_tab_url', "VARCHAR(255) NOT NULL DEFAULT ''");
        }
    }

    if ($db->table_exists('af_kb_entries')) {

        if (!$db->field_exists('data_json', 'af_kb_entries')) {
            $db->write_query("ALTER TABLE " . TABLE_PREFIX . "af_kb_entries ADD COLUMN data_json MEDIUMTEXT NOT NULL AFTER meta_json");
        }

        if (!$db->field_exists('item_kind', 'af_kb_entries')) {
            $db->add_column('af_kb_entries', 'item_kind', "VARCHAR(64) NULL");
            $db->write_query("ALTER TABLE ".TABLE_PREFIX."af_kb_entries ADD KEY item_kind (item_kind)");
        }
        if (!$db->field_exists('icon_class', 'af_kb_entries')) {
            $db->add_column('af_kb_entries', 'icon_class', "VARCHAR(128) NOT NULL DEFAULT ''");
        }
        if (!$db->field_exists('icon_url', 'af_kb_entries')) {
            $db->add_column('af_kb_entries', 'icon_url', "VARCHAR(255) NOT NULL DEFAULT ''");
        }
        if (!$db->field_exists('bg_url', 'af_kb_entries')) {
            $db->add_column('af_kb_entries', 'bg_url', "VARCHAR(255) NOT NULL DEFAULT ''");
        }
        if (!$db->field_exists('banner_url', 'af_kb_entries')) {
            $db->add_column('af_kb_entries', 'banner_url', "VARCHAR(255) NOT NULL DEFAULT ''");
        }
        if (!$db->field_exists('tech_ru', 'af_kb_entries')) {
            $db->add_column('af_kb_entries', 'tech_ru', "TEXT NOT NULL");
        }
        if (!$db->field_exists('tech_en', 'af_kb_entries')) {
            $db->add_column('af_kb_entries', 'tech_en', "TEXT NOT NULL");
        }

        af_kb_migrate_data_json_once();
    }

    if (!$db->table_exists('af_kb_categories')) {
        $sql = "CREATE TABLE " . TABLE_PREFIX . "af_kb_categories (
"
            . "  cat_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
"
            . "  type VARCHAR(64) NOT NULL,
"
            . "  parent_id INT UNSIGNED NOT NULL DEFAULT 0,
"
            . "  `key` VARCHAR(64) NOT NULL,
"
            . "  title_ru VARCHAR(255) NOT NULL DEFAULT '',
"
            . "  title_en VARCHAR(255) NOT NULL DEFAULT '',
"
            . "  description_ru MEDIUMTEXT NULL,
"
            . "  description_en MEDIUMTEXT NULL,
"
            . "  sortorder INT NOT NULL DEFAULT 0,
"
            . "  active TINYINT(1) NOT NULL DEFAULT 1,
"
            . "  updated_at INT UNSIGNED NOT NULL DEFAULT 0,
"
            . "  UNIQUE KEY uniq_type_key (type, `key`),
"
            . "  KEY type_parent_sort (type, parent_id, sortorder),
"
            . "  KEY type_active (type, active)
"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $db->write_query($sql);
    }

    if (!$db->table_exists('af_kb_entry_categories')) {
        $sql = "CREATE TABLE " . TABLE_PREFIX . "af_kb_entry_categories (
"
            . "  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
"
            . "  entry_id INT UNSIGNED NOT NULL,
"
            . "  cat_id INT UNSIGNED NOT NULL,
"
            . "  is_primary TINYINT(1) NOT NULL DEFAULT 0,
"
            . "  UNIQUE KEY uniq_entry_cat (entry_id, cat_id),
"
            . "  KEY cat_id_idx (cat_id),
"
            . "  KEY entry_id_idx (entry_id),
"
            . "  KEY entry_primary (entry_id, is_primary)
"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $db->write_query($sql);
    }

    if ($db->table_exists('af_kb_blocks')) {
        if (!$db->field_exists('icon_class', 'af_kb_blocks')) {
            $db->add_column('af_kb_blocks', 'icon_class', "VARCHAR(128) NOT NULL DEFAULT ''");
        }
        if (!$db->field_exists('icon_url', 'af_kb_blocks')) {
            $db->add_column('af_kb_blocks', 'icon_url', "VARCHAR(255) NOT NULL DEFAULT ''");
        }
    }
}

function af_kb_migration_flag_key(): string
{
    return 'af_kb_data_json_migrated_v1';
}

function af_kb_migrate_data_json_once(): void
{
    global $cache;

    $flagKey = af_kb_migration_flag_key();
    $flags = [];
    if (is_object($cache)) {
        $cached = $cache->read('af_kb_schema_flags');
        if (is_array($cached)) {
            $flags = $cached;
        }
    }

    if (!empty($flags[$flagKey])) {
        return;
    }

    af_kb_migrate_data_json();

    $flags[$flagKey] = TIME_NOW;
    if (is_object($cache)) {
        $cache->update('af_kb_schema_flags', $flags);
    }
}

function af_kb_migrate_data_json(): void
{
    global $db;

    if (!$db->table_exists('af_kb_entries') || !$db->field_exists('data_json', 'af_kb_entries')) {
        return;
    }

    $where = "active=1";
    if ($db->field_exists('type', 'af_kb_entries')) {
        $where .= " AND `type`='item'";
    }

    $q = $db->simple_select('af_kb_entries', '*', $where);
    while ($entry = $db->fetch_array($q)) {
        $detected = af_kb_detect_entry_data_json((array)$entry);
        $normalized = af_kb_normalize_rules_json((string)($detected['json'] ?? '{}'));
        $db->update_query(
            'af_kb_entries',
            ['data_json' => $db->escape_string($normalized), 'updated_at' => TIME_NOW],
            'id=' . (int)$entry['id']
        );
    }
}

function af_kb_seed_defaults(): void
{
    global $db;

    $requiredTypes = ['race', 'race_variant', 'class', 'theme', 'bestiary', 'skill', 'knowledge', 'language', 'item'];
    $defaultsByType = [];
    foreach (af_kb_default_type_definitions() as $row) {
        $defaultsByType[(string)$row['type_key']] = $row;
    }

    foreach ($requiredTypes as $idx => $typeKey) {
        $existing = $db->fetch_array($db->simple_select('af_kb_types', '*', "(type='".$db->escape_string($typeKey)."' OR type_key='".$db->escape_string($typeKey)."')", ['limit' => 1]));

        $defaultRow = $defaultsByType[$typeKey] ?? [
            'type_key' => $typeKey,
            'title_ru' => ucfirst($typeKey),
            'title_en' => ucfirst($typeKey),
            'rules_schema' => $typeKey === 'item' ? 'af_kb.item.v2' : AF_KB_RULES_SCHEMA,
            'ui_schema_json' => json_encode(af_kb_default_ui_schema_for_type($typeKey), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'is_active' => 1,
            'sortorder' => $idx,
        ];

        $defaultUiSchemaJson = trim((string)($defaultRow['ui_schema_json'] ?? ''));
        if ($defaultUiSchemaJson === '' || $defaultUiSchemaJson === '{}' || $defaultUiSchemaJson === '[]') {
            $defaultUiSchemaJson = json_encode(
                af_kb_default_ui_schema_for_type($typeKey),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }
        $defaultRow['ui_schema_json'] = $defaultUiSchemaJson;

        $defaultRulesSchema = trim((string)($defaultRow['rules_schema'] ?? ''));
        if ($defaultRulesSchema === '') {
            $defaultRulesSchema = $typeKey === 'item' ? 'af_kb.item.v2' : AF_KB_RULES_SCHEMA;
        }

        if (!$existing) {
            $db->insert_query('af_kb_types', [
                'type' => $db->escape_string($defaultRow['type_key']),
                'type_key' => $db->escape_string($defaultRow['type_key']),
                'mechanic_key' => $db->escape_string((string)($defaultRow['mechanic_key'] ?? AF_KB_DEFAULT_MECHANIC_KEY)),
                'title_ru' => $db->escape_string($defaultRow['title_ru']),
                'title_en' => $db->escape_string($defaultRow['title_en']),
                'short_ru' => '',
                'short_en' => '',
                'description_ru' => '',
                'description_en' => '',
                'desc_ru' => '',
                'desc_en' => '',
                'rules_schema' => $db->escape_string($defaultRulesSchema),
                'ui_schema_json' => $db->escape_string($defaultRow['ui_schema_json']),
                'active' => (int)$defaultRow['is_active'],
                'is_active' => (int)$defaultRow['is_active'],
                'sortorder' => (int)$defaultRow['sortorder'],
                'updated_at' => TIME_NOW,
            ]);
            continue;
        }

        $update = [];

        if ((string)($existing['type_key'] ?? '') === '') {
            $update['type_key'] = $db->escape_string($defaultRow['type_key']);
        }
        if ((string)($existing['mechanic_key'] ?? '') === '') {
            $update['mechanic_key'] = $db->escape_string((string)($defaultRow['mechanic_key'] ?? AF_KB_DEFAULT_MECHANIC_KEY));
        }

        $existingRulesSchema = trim((string)($existing['rules_schema'] ?? ''));
        if ($existingRulesSchema === '' || $existingRulesSchema !== $defaultRulesSchema) {
            $update['rules_schema'] = $db->escape_string($defaultRulesSchema);
        }

        $existingUiSchema = trim((string)($existing['ui_schema_json'] ?? ''));
        if (
            $existingUiSchema === ''
            || $existingUiSchema === '{}'
            || $existingUiSchema === '[]'
            || $typeKey === 'item'
        ) {
            $update['ui_schema_json'] = $db->escape_string($defaultRow['ui_schema_json']);
        }

        if (!empty($update)) {
            $update['updated_at'] = TIME_NOW;
            $db->update_query('af_kb_types', $update, 'id='.(int)$existing['id']);
        }
    }

    if ($db->table_exists('af_kb_item_kinds')) {
        $canonicalKinds = [];
        foreach (af_kb_default_item_kind_definitions() as $row) {
            $canonicalKinds[(string)$row['kind_key']] = true;
        }
        $deprecatedKinds = ['cyberware', 'implant', 'weapon_offhand', 'helmet'];

        foreach ($deprecatedKinds as $deprecatedKind) {
            $normalizedKind = af_kb_normalize_item_kind($deprecatedKind);
            if ($normalizedKind === $deprecatedKind || empty($canonicalKinds[$normalizedKind])) {
                continue;
            }
            $db->update_query('af_kb_item_kinds', [
                'is_active' => 0,
                'updated_at' => TIME_NOW,
            ], "kind_key='".$db->escape_string($deprecatedKind)."'");
            if ($db->table_exists('af_kb_entries')) {
                $db->update_query('af_kb_entries', [
                    'item_kind' => $db->escape_string($normalizedKind),
                ], "item_kind='".$db->escape_string($deprecatedKind)."'");
            }
        }

        foreach (af_kb_default_item_kind_definitions() as $row) {
            $existing = $db->fetch_array($db->simple_select('af_kb_item_kinds', '*', "kind_key='".$db->escape_string($row['kind_key'])."'", ['limit' => 1]));
            if (!$existing) {
                $db->insert_query('af_kb_item_kinds', [
                    'kind_key' => $db->escape_string($row['kind_key']),
                    'title_ru' => $db->escape_string($row['title_ru']),
                    'title_en' => $db->escape_string($row['title_en']),
                    'desc_ru' => '',
                    'desc_en' => '',
                    'ui_schema_json' => $db->escape_string($row['ui_schema_json']),
                    'is_active' => 1,
                    'sortorder' => (int)$row['sortorder'],
                    'updated_at' => TIME_NOW,
                ]);
                continue;
            }
            $kindSchema = trim((string)($existing['ui_schema_json'] ?? ''));
            if ($kindSchema === '' || $kindSchema === '{}' || $kindSchema === '[]') {
                $db->update_query('af_kb_item_kinds', [
                    'ui_schema_json' => $db->escape_string($row['ui_schema_json']),
                    'updated_at' => TIME_NOW,
                ], 'id='.(int)$existing['id']);
            }
        }
    }

    af_kb_seed_arpg_types();
}

function af_kb_seed_arpg_types(): void
{
    global $db;

    $definitions = af_kb_default_arpg_type_definitions();
    foreach ($definitions as $idx => $defaultRow) {
        $typeKey = (string)($defaultRow['type_key'] ?? '');
        if ($typeKey === '') {
            continue;
        }

        $existing = $db->fetch_array($db->simple_select('af_kb_types', '*', "(type='".$db->escape_string($typeKey)."' OR type_key='".$db->escape_string($typeKey)."')", ['limit' => 1]));
        if (!$existing) {
            $db->insert_query('af_kb_types', [
                'type' => $db->escape_string($typeKey),
                'type_key' => $db->escape_string($typeKey),
                'mechanic_key' => $db->escape_string('arpg'),
                'title_ru' => $db->escape_string((string)($defaultRow['title_ru'] ?? $typeKey)),
                'title_en' => $db->escape_string((string)($defaultRow['title_en'] ?? $typeKey)),
                'short_ru' => '',
                'short_en' => '',
                'description_ru' => '',
                'description_en' => '',
                'desc_ru' => '',
                'desc_en' => '',
                'rules_schema' => $db->escape_string((string)($defaultRow['rules_schema'] ?? AF_KB_ARPG_META_SCHEMA)),
                'ui_schema_json' => $db->escape_string((string)($defaultRow['ui_schema_json'] ?? '{}')),
                'active' => (int)($defaultRow['is_active'] ?? 1),
                'is_active' => (int)($defaultRow['is_active'] ?? 1),
                'sortorder' => 100 + $idx,
                'updated_at' => TIME_NOW,
            ]);
            continue;
        }

        $update = [
            'mechanic_key' => $db->escape_string('arpg'),
            'rules_schema' => $db->escape_string((string)($defaultRow['rules_schema'] ?? AF_KB_ARPG_META_SCHEMA)),
            'active' => (int)($defaultRow['is_active'] ?? 1),
            'is_active' => (int)($defaultRow['is_active'] ?? 1),
            'sortorder' => 100 + $idx,
            'updated_at' => TIME_NOW,
        ];
        $existingUiSchema = trim((string)($existing['ui_schema_json'] ?? ''));
        if ($existingUiSchema === '' || $existingUiSchema === '{}' || $existingUiSchema === '[]') {
            $update['ui_schema_json'] = $db->escape_string((string)($defaultRow['ui_schema_json'] ?? '{}'));
        }
        $db->update_query('af_kb_types', $update, 'id='.(int)$existing['id']);
    }

    af_kb_reorganize_arpg_entries_and_types();
}

function af_kb_reorganize_arpg_entries_and_types(): void
{
    global $db;

    if (!$db->table_exists('af_kb_types')) {
        return;
    }

    $entryMap = af_kb_arpg_legacy_type_map();

    if ($db->table_exists('af_kb_entries')) {
        foreach ($entryMap as $oldType => $mapping) {
            $newType = (string)($mapping['new_type'] ?? '');
            $serviceKind = (string)($mapping['service_kind'] ?? '');
            $forcedRuleType = (string)($mapping['forced_rule_type'] ?? '');
            $migratedIds = [];
            if ($serviceKind !== '' || $forcedRuleType !== '') {
                $idQuery = $db->simple_select('af_kb_entries', 'id', "type='".$db->escape_string($oldType)."'");
                while ($idRow = $db->fetch_array($idQuery)) {
                    $migratedIds[] = (int)($idRow['id'] ?? 0);
                }
            }
            $db->update_query('af_kb_entries', ['type' => $db->escape_string($newType), 'updated_at' => TIME_NOW], "type='".$db->escape_string($oldType)."'");

            if ($serviceKind === '' && $forcedRuleType === '') {
                continue;
            }

            if (empty($migratedIds)) {
                continue;
            }

            $query = $db->simple_select('af_kb_entries', 'id,data_json', 'id IN (' . implode(',', array_map('intval', $migratedIds)) . ')');
            while ($row = $db->fetch_array($query)) {
                $data = af_kb_decode_json((string)($row['data_json'] ?? '{}'));
                if (!is_array($data)) {
                    $data = [];
                }
                $legacyErrors = [];
                $payload = af_kb_validate_rules_json_by_type_arpg($newType, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', $legacyErrors);
                $normalized = af_kb_decode_json($payload);
                if (!is_array($normalized)) {
                    $normalized = af_kb_arpg_envelope_defaults($newType);
                }
                if ($serviceKind !== '') {
                    $normalized['rules']['service_kind'] = $serviceKind;
                    $normalized['rules']['type_profile'] = 'service_mechanics';
                    $normalized['rules']['category'] = 'service.mechanics';
                    $normalized['rules']['visibility'] = ['catalog' => false, 'search' => false, 'internal' => true];
                    if (!isset($normalized['rules']['entries']) || !is_array($normalized['rules']['entries'])) {
                        $normalized['rules']['entries'] = [];
                    }
                }
                if ($forcedRuleType !== '' && $newType === 'arpg_ability') {
                    $normalized['rules']['type'] = $forcedRuleType;
                }

                $db->update_query('af_kb_entries', [
                    'data_json' => $db->escape_string(json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'),
                    'updated_at' => TIME_NOW,
                ], 'id='.(int)$row['id']);
            }
        }

        $canonicalTypes = ['arpg_ability', 'arpg_talent', 'arpg_item', 'arpg_bestiary', 'arpg_mechanics'];
        foreach ($canonicalTypes as $canonicalType) {
            $query = $db->simple_select('af_kb_entries', 'id,data_json', "type='".$db->escape_string($canonicalType)."'");
            while ($row = $db->fetch_array($query)) {
                $legacyErrors = [];
                $payload = af_kb_validate_rules_json_by_type_arpg($canonicalType, (string)($row['data_json'] ?? '{}'), $legacyErrors);
                $db->update_query('af_kb_entries', [
                    'data_json' => $db->escape_string($payload),
                    'updated_at' => TIME_NOW,
                ], 'id='.(int)$row['id']);
            }
        }
    }

    foreach (array_keys($entryMap) as $legacyTypeKey) {
        $db->update_query('af_kb_types', [
            'active' => 0,
            'is_active' => 0,
            'updated_at' => TIME_NOW,
        ], "(type='".$db->escape_string($legacyTypeKey)."' OR type_key='".$db->escape_string($legacyTypeKey)."')");
    }

    foreach (af_kb_default_arpg_type_definitions() as $def) {
        $typeKey = (string)($def['type_key'] ?? '');
        if ($typeKey === '') {
            continue;
        }
        $row = $db->fetch_array($db->simple_select('af_kb_types', 'id', "(type='".$db->escape_string($typeKey)."' OR type_key='".$db->escape_string($typeKey)."')", ['limit' => 1]));
        if (!$row) {
            continue;
        }
        $db->update_query('af_kb_types', [
            'active' => (int)($def['is_active'] ?? 0),
            'is_active' => (int)($def['is_active'] ?? 0),
            'updated_at' => TIME_NOW,
        ], 'id='.(int)$row['id']);
    }
}

function af_kb_default_stats_dictionary(): array
{
    return [
        'str' => ['ru' => 'Сила', 'en' => 'Strength'],
        'dex' => ['ru' => 'Ловкость', 'en' => 'Dexterity'],
        'int' => ['ru' => 'Интеллект', 'en' => 'Intelligence'],
        'con' => ['ru' => 'Конституция', 'en' => 'Constitution'],
        'wis' => ['ru' => 'Мудрость', 'en' => 'Wisdom'],
        'cha' => ['ru' => 'Харизма', 'en' => 'Charisma'],
    ];
}

function af_kb_default_equip_slots_dictionary(): array
{
    return [
        'none' => ['ru' => 'Нет', 'en' => 'None'],
        'weapon_mainhand' => ['ru' => 'Основное оружие', 'en' => 'Main Hand'],
        'weapon_offhand' => ['ru' => 'Второе оружие', 'en' => 'Off Hand'],
        'weapon_twohand' => ['ru' => 'Двуручное оружие', 'en' => 'Two-hand Weapon'],
        'weapon_melee' => ['ru' => 'Оружие ближнего боя', 'en' => 'Melee Weapon'],
        'weapon_ranged' => ['ru' => 'Дальнобойное оружие', 'en' => 'Ranged Weapon'],
        'body' => ['ru' => 'Броня', 'en' => 'Body Armor'],
        'head' => ['ru' => 'Шлем', 'en' => 'Headgear'],
        'hands' => ['ru' => 'Руки', 'en' => 'Hands'],
        'legs' => ['ru' => 'Ноги', 'en' => 'Legs'],
        'feet' => ['ru' => 'Обувь', 'en' => 'Feet'],
        'back' => ['ru' => 'Спина', 'en' => 'Back'],
        'belt' => ['ru' => 'Пояс', 'en' => 'Belt'],
        'ammo' => ['ru' => 'Боеприпасы', 'en' => 'Ammo'],
        'ammo_pouch' => ['ru' => 'Подсумок', 'en' => 'Ammo pouch'],
        'artifact' => ['ru' => 'Артефакт', 'en' => 'Artifact'],
        'gear' => ['ru' => 'Снаряжение', 'en' => 'Gear'],
        'accessory' => ['ru' => 'Аксессуар', 'en' => 'Accessory'],
        'support_1' => ['ru' => 'Быстрый слот 1', 'en' => 'Quick slot 1'],
        'support_2' => ['ru' => 'Быстрый слот 2', 'en' => 'Quick slot 2'],
        'support_3' => ['ru' => 'Быстрый слот 3', 'en' => 'Quick slot 3'],
        'support_4' => ['ru' => 'Быстрый слот 4', 'en' => 'Quick slot 4'],
    ];
}

function af_kb_l10n_label(string $dict, string $key, bool $isRu): string
{
    $maps = [
        'stats' => af_kb_default_stats_dictionary(),
        'equip_slots' => af_kb_default_equip_slots_dictionary(),
    ];
    $row = $maps[$dict][$key] ?? null;
    if (!is_array($row)) {
        return $key;
    }
    return (string)($isRu ? ($row['ru'] ?? $key) : ($row['en'] ?? $key));
}


function af_kb_default_relation_types_dictionary(): array
{
    return [
        AF_KB_REL_RACE_HAS_VARIANT => ['ru' => 'Разновидности', 'en' => 'Variants'],
    ];
}

function af_kb_relation_type_label(string $relType, bool $isRu): string
{
    $relType = trim($relType);
    if ($relType == '') {
        return '';
    }

    global $lang;

    $langKey = 'af_kb_rel_type_' . preg_replace('/[^a-z0-9_]+/i', '_', strtolower($relType));
    if (isset($lang->{$langKey}) && trim((string)$lang->{$langKey}) !== '') {
        return (string)$lang->{$langKey};
    }

    $dict = af_kb_default_relation_types_dictionary();
    $row = $dict[$relType] ?? null;
    if (is_array($row)) {
        $localized = $isRu ? (string)($row['ru'] ?? '') : (string)($row['en'] ?? '');
        if ($localized !== '') {
            return $localized;
        }
        $display = (string)($row['label'] ?? '');
        if ($display !== '') {
            return $display;
        }
    }

    return $relType;
}

function af_kb_default_ui_schema_for_type(string $typeKey): array
{
    // Для этих типов фронт должен быть "чистый": только body, без секций/карточек
    $plainBodyTypes = ['theme', 'perk', 'lore', 'spell', 'item'];

    if (in_array($typeKey, $plainBodyTypes, true)) {
        return [
            'schema' => 'af_kb.ui.schema.v1',
            'version' => 1,
            'sections' => []
        ];
    }

    $raceSchemaJson = '{"schema":"af_kb.ui.schema.v1","version":1,"sections":[
            {"id":"race_overview","title":{"ru":"Кратко","en":"Overview"},"layout":"two_col","blocks":[
                {"id":"short","source":"entry","path":"short"},
                {"id":"creature_type","source":"rules","path":"creature_type"}
            ]},
            {"id":"race_stats","title":{"ru":"Характеристики","en":"Stats"},"layout":"sidebar_grid","blocks":[
                {"id":"size","source":"rules","path":"size"},
                {"id":"speed","source":"rules","path":"speed"},
                {"id":"hp_base","source":"rules","path":"hp_base"},
                {"id":"languages","source":"rules","path":"languages"},
                {"id":"language_slots","source":"rules","path":"fixed_bonuses.language_slots"},
                {"id":"knowledge_slots","source":"rules","path":"fixed_bonuses.knowledge_slots"},
                {"id":"visibility","source":"rules","path":"visibility"}
            ]},
            {"id":"race_bonuses","title":{"ru":"Бонусы","en":"Bonuses"},"layout":"cards","blocks":[
                {"id":"fixed_bonuses","source":"rules","path":"fixed_bonuses"},
                {"id":"fixed_stats","source":"rules","path":"fixed_bonuses.stats"},
                {"id":"resistances","source":"rules","path":"resistances"}
            ]},
            {"id":"race_choices","title":{"ru":"Выборы","en":"Choices"},"layout":"cards","blocks":[
                {"id":"choices","source":"rules","path":"choices"}
            ]},
            {"id":"race_grants","title":{"ru":"Выдаёт","en":"Grants"},"layout":"cards","blocks":[
                {"id":"grants","source":"rules","path":"grants"}
            ]},
            {"id":"race_traits","title":{"ru":"Черты","en":"Traits"},"layout":"stack","blocks":[
                {"id":"traits","source":"rules","path":"traits"}
            ]},
            {"id":"race_effects","title":{"ru":"Эффекты","en":"Effects"},"layout":"stack","blocks":[
                {"id":"effects","source":"rules","path":"effects"}
            ]}
        ]}';

    $schemas = [
        'race' => $raceSchemaJson,
        'race_variant' => $raceSchemaJson,

        'class' => '{"schema":"af_kb.ui.schema.v1","version":1,"sections":[
            {"id":"class_overview","title":{"ru":"Кратко","en":"Overview"},"layout":"two_col","blocks":[
                {"id":"short","source":"entry","path":"short"},
                {"id":"key_ability","source":"rules","path":"key_ability"}
            ]},
            {"id":"class_core","title":{"ru":"База класса","en":"Class Core"},"layout":"sidebar_grid","blocks":[
                {"id":"hp_base","source":"rules","path":"hp_base"},
                {"id":"hp_per_level","source":"rules","path":"hp_per_level"},
                {"id":"proficiencies","source":"rules","path":"proficiencies"}
            ]},
            {"id":"class_bonuses","title":{"ru":"Бонусы","en":"Bonuses"},"layout":"cards","blocks":[
                {"id":"fixed_bonuses","source":"rules","path":"fixed_bonuses"},
                {"id":"choices","source":"rules","path":"choices"},
                {"id":"grants","source":"rules","path":"grants"}
            ]},
            {"id":"class_progression","title":{"ru":"Прогрессия","en":"Progression"},"layout":"timeline","blocks":[
                {"id":"progression","source":"rules","path":"progression"}
            ]},
            {"id":"class_traits","title":{"ru":"Черты","en":"Traits"},"layout":"stack","blocks":[
                {"id":"traits","source":"rules","path":"traits"}
            ]}
        ]}',

        'skill' => '{"schema":"af_kb.ui.schema.v1","version":1,"sections":[
            {"id":"skill_overview","title":{"ru":"Навык","en":"Skill"},"layout":"two_col","blocks":[
                {"id":"short","source":"entry","path":"short"},
                {"id":"category","source":"rules","path":"skill.category"}
            ]},
            {"id":"skill_stats","title":{"ru":"Параметры","en":"Parameters"},"layout":"sidebar_grid","blocks":[
                {"id":"key_stat","source":"rules","path":"skill.key_stat"},
                {"id":"rank_max","source":"rules","path":"skill.rank_max"},
                {"id":"trained_only","source":"rules","path":"skill.trained_only"},
                {"id":"armor_check_penalty","source":"rules","path":"skill.armor_check_penalty"}
            ]},
            {"id":"skill_notes","title":{"ru":"Заметки","en":"Notes"},"layout":"full","blocks":[
                {"id":"notes","source":"rules","path":"skill.notes"}
            ]},
            {"id":"skill_bonuses","title":{"ru":"Бонусы/Выдачи","en":"Bonuses/Grants"},"layout":"cards","blocks":[
                {"id":"grants","source":"rules","path":"grants"},
                {"id":"choices","source":"rules","path":"choices"},
                {"id":"traits","source":"rules","path":"traits"}
            ]}
        ]}'
    ];

    $schema = af_kb_decode_json((string)($schemas[$typeKey] ?? ''));

    if (!$schema) {
        $schema = [
            'schema' => 'af_kb.ui.schema.v1',
            'version' => 1,
            'sections' => []
        ];
    }

    return $schema;
}

function af_kb_default_type_rules_config_dnd(string $typeKey): array
{
    $defaults = [
        'rules_enabled' => true,
        'rules_schema' => '',
        'rules_required_keys' => [],
        'ui_rules_editor' => true,
    ];

    $typeConfig = [
        'race' => [
            'rules_enabled' => true,
            'rules_schema' => AF_KB_RULES_SCHEMA,
            'rules_required_keys' => ['fixed_bonuses', 'choices', 'traits'],
            'ui_rules_editor' => true,
        ],
        'race_variant' => [
            'rules_enabled' => true,
            'rules_schema' => AF_KB_RULES_SCHEMA,
            'rules_required_keys' => ['fixed_bonuses', 'choices', 'traits'],
            'ui_rules_editor' => true,
        ],
        'class' => [
            'rules_enabled' => true,
            'rules_schema' => AF_KB_RULES_SCHEMA,
            'rules_required_keys' => ['schema', 'type_profile', 'version', 'fixed', 'grants', 'choices', 'progression'],
            'ui_rules_editor' => true,
        ],
        'theme' => [
            'rules_enabled' => true,
            'rules_schema' => AF_KB_RULES_SCHEMA,
            'rules_required_keys' => ['schema', 'type_profile', 'version', 'fixed', 'grants', 'choices'],
            'ui_rules_editor' => true,
        ],
        'bestiary' => [
            'rules_enabled' => true,
            'rules_schema' => AF_KB_RULES_SCHEMA,
            'rules_required_keys' => ['schema', 'type_profile', 'version', 'creature'],
            'ui_rules_editor' => true,
        ],
        'skill' => [
            'rules_enabled' => true,
            'rules_schema' => AF_KB_RULES_SCHEMA,
            'rules_required_keys' => ['schema', 'type_profile', 'version', 'skill'],
            'ui_rules_editor' => true,
        ],
        'knowledge' => [
            'rules_enabled' => true,
            'rules_schema' => AF_KB_RULES_SCHEMA,
            'rules_required_keys' => ['schema', 'type_profile', 'version', 'skill', 'knowledge_group'],
            'ui_rules_editor' => true,
        ],
        'language' => [
            'rules_enabled' => true,
            'rules_schema' => AF_KB_RULES_SCHEMA,
            'rules_required_keys' => ['schema', 'type_profile', 'version'],
            'ui_rules_editor' => true,
        ],
        'item' => [
            'rules_enabled' => true,
            'rules_schema' => 'af_kb.item.v2',
            'rules_required_keys' => ['schema', 'type_profile', 'version', 'item'],
            'ui_rules_editor' => true,
        ],
        'spell' => [
            'rules_enabled' => true,
            'rules_schema' => AF_KB_RULES_SCHEMA,
            'rules_required_keys' => ['schema', 'type_profile', 'version', 'spell', 'effects'],
            'ui_rules_editor' => true,
        ],
        'condition' => [
            'rules_enabled' => true,
            'rules_schema' => AF_KB_RULES_SCHEMA,
            'rules_required_keys' => ['schema', 'type_profile', 'version', 'condition'],
            'ui_rules_editor' => true,
        ],
        'perk' => [
            'rules_enabled' => true,
            'rules_schema' => AF_KB_RULES_SCHEMA,
            'rules_required_keys' => ['schema', 'type_profile', 'version', 'effects'],
            'ui_rules_editor' => true,
        ],
        'faction' => [
            'rules_enabled' => false,
            'rules_schema' => '',
            'rules_required_keys' => [],
            'ui_rules_editor' => true,
        ],
        'lore' => [
            'rules_enabled' => false,
            'rules_schema' => '',
            'rules_required_keys' => [],
            'ui_rules_editor' => false,
        ],
    ];

    return array_replace($defaults, (array)($typeConfig[$typeKey] ?? []));
}

function af_kb_default_type_rules_config_arpg(string $typeKey): array
{
    $typeDef = af_kb_arpg_type_definition($typeKey);
    $isSupported = !empty($typeDef);
    $isService = !empty($typeDef['service']);

    $defaults = [
        'rules_enabled' => false,
        'rules_schema' => AF_KB_ARPG_META_SCHEMA,
        'rules_required_keys' => [],
        'ui_rules_editor' => false,
    ];

    if (!$isSupported) {
        return $defaults;
    }

    return [
        'rules_enabled' => true,
        'rules_schema' => AF_KB_ARPG_META_SCHEMA,
        'rules_required_keys' => ['schema', 'mechanic', 'tags', 'ui', 'blocks', 'rules'],
        'ui_rules_editor' => true,
    ];
}

function af_kb_default_type_rules_config(string $typeKey, ?string $mechanicKey = null): array
{
    $mechanic = af_kb_get_mechanic_profile($mechanicKey ?? af_kb_get_type_mechanic_key($typeKey));
    $resolver = $mechanic['providers']['rules_config'] ?? 'af_kb_default_type_rules_config_dnd';
    if (!is_string($resolver) || !function_exists($resolver)) {
        $resolver = 'af_kb_default_type_rules_config_dnd';
    }

    return (array)$resolver($typeKey);
}

function af_kb_get_type_profile_definition_dnd(string $typeKey): array
{
    $fixedStats = ['str' => 0, 'dex' => 0, 'con' => 0, 'int' => 0, 'wis' => 0, 'cha' => 0];

    $base = [
        'schema' => AF_KB_RULES_SCHEMA,
        'type_profile' => $typeKey,
        'version' => '1.0',
        'fixed' => [
            'stats' => $fixedStats,
            'hp' => 0,
            'speed' => 0,
            'ep' => 0,
            'armor' => 0,
            'initiative' => 0,
            'carry' => 0,
        ],
        'grants' => [],
        'choices' => [],
        'traits' => [],
    ];

    $profiles = [
        'race' => [
            'ui_profile' => 'race',
            'rules_enabled' => true,
            'defaults' => [
                'schema' => AF_KB_RULES_SCHEMA,
                'type_profile' => 'race',
                'version' => '1.0',
                'fixed_bonuses' => [
                    'stats' => $fixedStats,
                    'hp' => 0,
                    'ep' => 0,
                ],
                'choices' => [],
                'traits' => [],
                'size' => 'medium',
                'creature_type' => 'humanoid',
                'speed' => 30,
                'hp_base' => 10,
                'languages' => ['common'],
            ],
        ],
        'race_variant' => [
            'ui_profile' => 'race_variant',
            'rules_enabled' => true,
            'defaults' => [
                'schema' => AF_KB_RULES_SCHEMA,
                'type_profile' => 'race_variant',
                'version' => '1.0',
                'fixed_bonuses' => [
                    'stats' => $fixedStats,
                    'hp' => 0,
                    'ep' => 0,
                ],
                'choices' => [],
                'traits' => [],
                'size' => 'medium',
                'creature_type' => 'humanoid',
                'speed' => 30,
                'hp_base' => 10,
                'languages' => ['common'],
                'effects' => [],
                'inherits_from_race' => true,
            ],
        ],
        'class' => [
            'ui_profile' => 'class',
            'rules_enabled' => true,
            'defaults' => array_replace_recursive($base, [
                'hp_per_level' => 6,
                'key_ability' => 'str',
                'proficiencies' => new stdClass(),
                'progression' => [],
            ]),
        ],
        'theme' => [
            'ui_profile' => 'theme',
            'rules_enabled' => true,
            'defaults' => $base,
        ],
        'bestiary' => [
            'ui_profile' => 'bestiary',
            'rules_enabled' => true,
            'defaults' => [
                'schema' => AF_KB_RULES_SCHEMA,
                'type_profile' => 'bestiary',
                'version' => '1.0',
                'creature' => [
                    'size' => 'medium',
                    'kind' => 'humanoid',
                    'challenge_rating' => '1',
                    'xp' => 0,
                    'proficiency_bonus' => 2,
                    'armor_class' => 10,
                    'hp' => ['average' => 10, 'dice' => '2d8+2'],
                    'speed' => ['walk' => 30],
                    'ability_scores' => ['str' => 10, 'dex' => 10, 'con' => 10, 'int' => 10, 'wis' => 10, 'cha' => 10],
                    'saving_throws' => [],
                    'skills' => [],
                    'damage_vulnerabilities' => [],
                    'damage_resistances' => [],
                    'damage_immunities' => [],
                    'condition_immunities' => [],
                    'senses' => ['passive_perception' => 10],
                    'languages' => [],
                    'notes' => '',
                ],
                'traits' => [],
                'actions' => [],
                'reactions' => [],
                'legendary_actions' => [],
                'loot' => [],
                'gm_notes' => '',
            ],
        ],
        'skill' => [
            'ui_profile' => 'skill',
            'rules_enabled' => true,
            'defaults' => array_replace_recursive($base, [
                'skill' => [
                    'trained_only' => false,
                    'untrained_allowed' => true,
                    'armor_check_penalty_applies' => false,
                    'rank_mode' => 'ranked',
                    'max_rank' => 10,
                    'rank_bonus' => 1,
                    'base_formula' => 'attribute',
                    'can_buy_rank' => true,
                ],
            ]),
        ],
        'knowledge' => [
            'ui_profile' => 'knowledge',
            'rules_enabled' => true,
            'defaults' => array_replace_recursive($base, [
                'knowledge_group' => 'lore',
                'skill' => [
                    'rank_mode' => 'ranked',
                    'max_rank' => 10,
                    'rank_bonus' => 1,
                    'base_formula' => 'attribute',
                    'can_buy_rank' => true,
                ],
            ]),
        ],
        'language' => [
            'ui_profile' => 'language',
            'rules_enabled' => true,
            'defaults' => array_replace_recursive($base, [
                'script' => '',
                'rarity' => 'common',
                'family' => '',
                'requires' => [],
            ]),
        ],
        'spell' => [
            'ui_profile' => 'spell',
            'rules_enabled' => true,
            'defaults' => array_replace_recursive($base, [
                'spell' => [
                    'rank' => 1,
                    'tradition' => 'arcane',
                    'casting_time' => '1_action',
                    'range' => '',
                    'duration' => '',
                    'area' => '',
                    'requires_check' => false,
                    'check_stat' => 'int',
                    'dc' => 0,
                ],
                'effects' => [],
            ]),
        ],

        'item' => [
            'ui_profile' => 'item',
            'rules_enabled' => true,
            'defaults' => [
                'schema' => 'af_kb.item.v2',
                'type_profile' => 'item',
                'version' => '1.0',
                'item' => [
                    'item_kind' => 'gear',
                    'rarity' => 'common',
                    'price' => 0,
                    'currency' => 'credits',
                    'weight' => 0,
                    'stack_max' => 1,
                    'equip' => [
                        'slot' => '',
                        'armor' => [
                            'ac_bonus' => 0,
                            'armor_type' => 'light',
                        ],
                    ],
                    'weapon' => [
                        'damage_bonus' => 0,
                        'damage_type' => 'kinetic',
                        'rate_of_fire' => 0,
                        'range' => '',
                        'ammo_type_key' => '',
                    ],
                    'ammo' => [
                        'ammo_type' => '',
                        'damage_type' => 'kinetic',
                        'damage_bonus' => 0,
                    ],
                    'gear' => [
                        'subtype' => '',
                    ],
                    'bonuses' => [],
                    'augmentation' => [
                        'subtype' => 'cybernetic',
                        'slot' => '',
                        'grade' => '',
                        'humanity_cost_percent' => 0,
                        'modifiers' => [],
                        'effects' => [],
                        'grants' => [],
                        'requirements' => [],
                        'conflicts' => [],
                    ],
                    'cyberware' => [
                        'slot' => '',
                        'grade' => '',
                        'humanity_cost_percent' => 0,
                        'modifiers' => [],
                        'effects' => [],
                        'grants' => [],
                        'requirements' => [],
                        'conflicts' => [],
                        'subtype' => 'cybernetic',
                    ],
                    'tags' => [],
                    'on_use' => [],
                    'on_equip' => [],
                    'requirements' => [],
                    'unique_role' => '',
                    'unique_base_kind' => '',
                ],
            ],
        ],

        'condition' => [
            'ui_profile' => 'condition',
            'rules_enabled' => true,
            'defaults' => array_replace_recursive($base, [
                'condition' => [
                    'severity' => 1,
                    'duration_default' => '',
                    'stacking' => 'none',
                    'effects' => [],
                ],
            ]),
        ],
        'perk' => [
            'ui_profile' => 'perk',
            'rules_enabled' => true,
            'defaults' => array_replace_recursive($base, [
                'tier' => 1,
                'level_req' => 1,
                'prereq' => [],
                'effects' => [],
            ]),
        ],
        'faction' => [
            'ui_profile' => 'faction',
            'rules_enabled' => false,
            'defaults' => ['meta' => []],
        ],
        'lore' => [
            'ui_profile' => 'lore',
            'rules_enabled' => false,
            'defaults' => ['meta' => []],
        ],
    ];

    $profile = (array)($profiles[$typeKey] ?? [
        'ui_profile' => $typeKey,
        'rules_enabled' => true,
        'defaults' => $base,
    ]);

    $profile['allowed_choices'] = ['kb_pick', 'stat_bonus', 'language_pick', 'proficiency_pick', 'equipment_pick', 'spell_pick'];
    $profile['allowed_grants'] = ['resource', 'skill', 'item', 'sense', 'resistance', 'speed'];

    $expectedSchema = (string)($profile['defaults']['schema'] ?? AF_KB_RULES_SCHEMA);
    $profile['validators'] = array_replace_recursive(
        ['schema' => $expectedSchema],
        (array)($profile['validators'] ?? [])
    );

    return $profile;
}

function af_kb_get_type_profile_definition_arpg(string $typeKey): array
{
    $typeDef = af_kb_arpg_type_definition($typeKey);
    $base = af_kb_arpg_envelope_defaults($typeKey);
    $base['rules'] = array_replace_recursive((array)($base['rules'] ?? []), af_kb_default_type_profile_payload_arpg($typeKey));

    return [
        'ui_profile' => 'arpg',
        'rules_enabled' => !empty($typeDef),
        'defaults' => $base,
        'validators' => [
            'schema' => AF_KB_ARPG_META_SCHEMA,
            'mechanic' => 'arpg',
            'rules_schema' => AF_KB_ARPG_RULES_SCHEMA,
        ],
    ];
}

function af_kb_get_type_profile_definition(string $typeKey, ?string $mechanicKey = null): array
{
    $mechanic = af_kb_get_mechanic_profile($mechanicKey ?? af_kb_get_type_mechanic_key($typeKey));
    $resolver = $mechanic['providers']['type_profile'] ?? 'af_kb_get_type_profile_definition_dnd';
    if (!is_string($resolver) || !function_exists($resolver)) {
        $resolver = 'af_kb_get_type_profile_definition_dnd';
    }

    return (array)$resolver($typeKey);
}

function af_kb_get_type_schema_dnd(string $typeKey): array
{
    global $db;

    $safeType = $db->escape_string($typeKey);
    $row = $db->fetch_array(
        $db->simple_select(
            'af_kb_types',
            'ui_schema_json,rules_schema',
            "(type='".$safeType."' OR type_key='".$safeType."')",
            ['limit' => 1]
        )
    );

    $schema = $row ? af_kb_decode_json((string)($row['ui_schema_json'] ?? '{}')) : [];

    // Для item и race_variant жёстко берём каноничную editor-schema,
    // даже если в БД раньше остался старый ui_schema_json.
    if (in_array($typeKey, ['item', AF_KB_TYPE_RACE_VARIANT], true)) {
        foreach (af_kb_default_type_definitions() as $def) {
            if ((string)($def['type_key'] ?? '') === $typeKey) {
                $schema = af_kb_decode_json((string)($def['ui_schema_json'] ?? '{}'));
                break;
            }
        }
    }

    if (empty($schema)) {
        $schema = af_kb_default_ui_schema_for_type($typeKey);
    }

    $rulesConfig = af_kb_default_type_rules_config($typeKey);
    if (!array_key_exists('rules_schema', $rulesConfig)) {
        $rulesConfig['rules_schema'] = '';
    }

    $dbRulesSchema = trim((string)($row['rules_schema'] ?? ''));
    if ($typeKey !== 'item' && $dbRulesSchema !== '' && !isset($schema['rules_schema'])) {
        $rulesConfig['rules_schema'] = $dbRulesSchema;
    }

    $profileSchema = af_kb_get_type_profile_definition($typeKey);

    $schema['rules_enabled'] = isset($schema['rules_enabled'])
        ? !empty($schema['rules_enabled'])
        : !empty($profileSchema['rules_enabled']);

    $schema['rules_schema'] = (string)(
        $typeKey === 'item'
            ? 'af_kb.item.v2'
            : ($schema['rules_schema'] ?? $rulesConfig['rules_schema'] ?? '')
    );

    $schema['rules_required_keys'] = isset($schema['rules_required_keys']) && is_array($schema['rules_required_keys'])
        ? array_values($schema['rules_required_keys'])
        : array_values((array)($rulesConfig['rules_required_keys'] ?? []));

    $schema['ui_rules_editor'] = isset($schema['ui_rules_editor'])
        ? !empty($schema['ui_rules_editor'])
        : !empty($rulesConfig['ui_rules_editor']);

    $schema['type_profile'] = (string)($schema['type_profile'] ?? $typeKey);
    $schema['ui_profile'] = (string)($schema['ui_profile'] ?? ($profileSchema['ui_profile'] ?? $typeKey));
    $schema['defaults'] = array_replace_recursive(
        (array)($profileSchema['defaults'] ?? []),
        (array)($schema['defaults'] ?? [])
    );
    $schema['allowed_choices'] = array_values((array)($schema['allowed_choices'] ?? $profileSchema['allowed_choices'] ?? []));
    $schema['allowed_grants'] = array_values((array)($schema['allowed_grants'] ?? $profileSchema['allowed_grants'] ?? []));
    $schema['validators'] = array_replace_recursive(
        (array)($profileSchema['validators'] ?? []),
        (array)($schema['validators'] ?? [])
    );

    if ($typeKey === 'item') {
        $schema['root_defaults'] = af_kb_normalize_item_rules_payload((array)($schema['root_defaults'] ?? []));
        $schema['root_defaults']['schema'] = 'af_kb.item.v2';
        $schema['root_defaults']['type_profile'] = 'item';
        if (!isset($schema['root_defaults']['version']) || trim((string)$schema['root_defaults']['version']) === '') {
            $schema['root_defaults']['version'] = '1.0';
        }

        if (isset($schema['fields']) && is_array($schema['fields'])) {
            foreach ($schema['fields'] as &$field) {
                if (($field['path'] ?? '') === 'schema') {
                    $field['default'] = 'af_kb.item.v2';
                }
            }
            unset($field);
        }
    }

    return $schema;
}

function af_kb_get_type_schema_arpg(string $typeKey): array
{
    $row = af_kb_find_type_row($typeKey);

    $schema = $row ? af_kb_decode_json((string)($row['ui_schema_json'] ?? '{}')) : [];
    if (empty($schema)) {
        $schema = [
            'schema' => 'af_kb.ui.v1',
            'version' => 1,
            'ui_profile' => 'arpg',
            'fields' => [],
        ];
    }

    $rulesConfig = af_kb_default_type_rules_config_arpg($typeKey);
    $profileSchema = af_kb_get_type_profile_definition_arpg($typeKey);
    $supported = in_array($typeKey, af_kb_arpg_supported_types(), true);

    $schema['rules_enabled'] = !empty($rulesConfig['rules_enabled']) && $supported;
    $schema['ui_rules_editor'] = isset($schema['ui_rules_editor'])
        ? !empty($schema['ui_rules_editor'])
        : !empty($rulesConfig['ui_rules_editor']);
    $schema['rules_schema'] = (string)($rulesConfig['rules_schema'] ?? AF_KB_ARPG_META_SCHEMA);
    $schema['rules_required_keys'] = array_values((array)($rulesConfig['rules_required_keys'] ?? []));
    $schema['type_profile'] = (string)($schema['type_profile'] ?? $typeKey);
    $schema['ui_profile'] = 'arpg';
    $schema['defaults'] = array_replace_recursive(
        (array)($profileSchema['defaults'] ?? []),
        (array)($schema['defaults'] ?? [])
    );
    $schema['validators'] = array_replace_recursive(
        (array)($profileSchema['validators'] ?? []),
        (array)($schema['validators'] ?? [])
    );

    return $schema;
}

function af_kb_get_type_schema(string $typeKey, ?string $mechanicKey = null): array
{
    $mechanic = af_kb_get_mechanic_profile($mechanicKey ?? af_kb_get_type_mechanic_key($typeKey));
    $resolver = $mechanic['providers']['schema'] ?? 'af_kb_get_type_schema_dnd';
    if (!is_string($resolver) || !function_exists($resolver)) {
        $resolver = 'af_kb_get_type_schema_dnd';
    }

    return (array)$resolver($typeKey);
}

function af_kb_get_item_kind_overlay(string $kindKey): array
{
    global $db;

    if (!$db->table_exists('af_kb_item_kinds') || $kindKey === '') {
        return [];
    }

    $row = $db->fetch_array($db->simple_select('af_kb_item_kinds', 'ui_schema_json', "kind_key='".$db->escape_string($kindKey)."'", ['limit' => 1]));
    if (!$row) {
        return [];
    }

    return af_kb_decode_json((string)($row['ui_schema_json'] ?? '{}'));
}

function af_kb_apply_overlay_to_schema(array $schema, array $overlay): array
{
    $patch = $overlay['patch'] ?? [];
    if (!is_array($patch)) {
        return $schema;
    }

    foreach ($patch as $op) {
        if (!is_array($op)) {
            continue;
        }

        if (($op['op'] ?? '') === 'set_required' && !empty($op['path'])) {
            $requiredMap = (array)($schema['required_paths'] ?? []);
            $requiredMap[(string)$op['path']] = !empty($op['required']);
            $schema['required_paths'] = $requiredMap;
        }

        if (($op['op'] ?? '') === 'set_defaults' && is_array($op['defaults'] ?? null)) {
            $schema['root_defaults'] = array_replace_recursive((array)($schema['root_defaults'] ?? []), $op['defaults']);
        }

        if (!isset($schema['fields']) || !is_array($schema['fields']) || empty($op['path'])) {
            continue;
        }

        foreach ($schema['fields'] as &$field) {
            if (($field['path'] ?? '') !== $op['path']) {
                continue;
            }
            if (($op['op'] ?? '') === 'set_required') {
                $field['required'] = !empty($op['required']);
                if (isset($op['min'])) {
                    $field['min'] = $op['min'];
                }
            }
        }
        unset($field);
    }

    return $schema;
}

function af_kb_get_template(string $name): string
{
    global $templates;

    $tpl = '';
    if (is_object($templates)) {
        $tpl = (string)$templates->get($name);
    }

    if ($tpl === '' && is_file(AF_KB_TPL_DIR . $name . '.html')) {
        $tpl = (string)@file_get_contents(AF_KB_TPL_DIR . $name . '.html');
    }

    return $tpl;
}

function af_kb_parse_assets_blacklist(string $raw): array
{
    $out = [];
    $lines = preg_split('~\R~', $raw);
    if (!is_array($lines)) {
        return $out;
    }

    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }

        $script = '';
        $action = null;

        $qPos = strpos($line, '?');
        if ($qPos === false) {
            $script = strtolower($line);
        } else {
            $script = strtolower(trim(substr($line, 0, $qPos)));
            $query = trim(substr($line, $qPos + 1));
            if ($query !== '') {
                $parts = explode('&', $query);
                foreach ($parts as $part) {
                    $part = trim((string)$part);
                    if ($part === '') {
                        continue;
                    }

                    $eqPos = strpos($part, '=');
                    if ($eqPos === false) {
                        continue;
                    }

                    $key = strtolower(trim(substr($part, 0, $eqPos)));
                    $val = strtolower(trim(substr($part, $eqPos + 1)));
                    if ($key === 'action') {
                        $action = $val;
                        break;
                    }
                }
            }
        }

        if ($script === '') {
            continue;
        }

        $script = strtolower(basename(str_replace('\\', '/', $script)));
        if ($script === '') {
            continue;
        }

        $out[] = ['script' => $script, 'action' => $action];
    }

    return $out;
}

function af_kb_assets_disabled_for_current_page(): bool
{
    global $mybb;

    $script = defined('THIS_SCRIPT') ? strtolower((string)THIS_SCRIPT) : '';
    if ($script !== '') {
        $script = strtolower(basename(str_replace('\\', '/', $script)));
    }
    if ($script === '') {
        return false;
    }

    // index.php — hard-disable always.
    if ($script === 'index.php') {
        return true;
    }

    $action = strtolower((string)($mybb->input['action'] ?? ''));
    $raw = trim((string)af_kb_get_setting('af_kb_assets_blacklist', 'index.php'));
    if ($raw === '') {
        return false;
    }

    $conditions = af_kb_parse_assets_blacklist($raw);
    foreach ($conditions as $cond) {
        $condScript = strtolower((string)($cond['script'] ?? ''));
        if ($condScript === '' || $condScript !== $script) {
            continue;
        }

        $condAction = $cond['action'] ?? null;
        if ($condAction === null || $condAction === '') {
            return true;
        }

        if ($action === strtolower((string)$condAction)) {
            return true;
        }
    }

    return false;
}

function af_kb_asset_version(string $filename): string
{
    $abs = AF_KB_ASSETS . ltrim($filename, '/');
    if (is_file($abs)) {
        $mtime = (int)@filemtime($abs);
        if ($mtime > 0) {
            return (string)$mtime;
        }
    }

    return AF_KB_VER;
}

function af_kb_strip_assets_from_html(string &$html): void
{
    if ($html === '') {
        return;
    }

    $patterns = [
        '~\s*<!--\s*af_kb_assets\s*-->\s*~i',
        '~\s*' . preg_quote(AF_KB_MARK, '~') . '\s*~i',
        '~\s*<link[^>]+href=["\"][^"\"]*/inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase(?:_kbui)?\.css(?:\?[^"\"]*)?["\"][^>]*>\s*~i',
        '~\s*<script[^>]+src=["\"][^"\"]*/inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase(?:_chips|_insert)?\.js(?:\?[^"\"]*)?["\"][^>]*>\s*</script>\s*~i',
        '~\s*<script[^>]*>\s*window\.afKbLang\s*=.*?</script>\s*~is',
    ];

    foreach ($patterns as $pattern) {
        $html = (string)preg_replace($pattern, '', $html);
    }
}

function af_kb_build_css_include_tag(string $fileRel): string
{
    global $mybb;

    $fileRel = ltrim(str_replace('\\', '/', trim($fileRel)), '/');
    if ($fileRel === '') {
        return '';
    }

    $decision = function_exists('af_theme_stylesheet_delivery_decision')
        ? af_theme_stylesheet_delivery_decision(AF_KB_ID, $fileRel)
        : ['include_file' => true, 'use_theme_stylesheet' => false, 'theme_href' => ''];

    if (!empty($decision['use_theme_stylesheet']) && !empty($decision['theme_href'])) {
        return '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars_uni((string)$decision['theme_href']) . '" />';
    }

    if (empty($decision['include_file'])) {
        return '';
    }

    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    if ($bburl === '') {
        return '';
    }

    $assetFile = basename($fileRel);
    $href = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_KB_ID . '/' . $fileRel
        . '?v=' . af_kb_asset_version($assetFile);
    return '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars_uni($href) . '" />';
}
/**
 * Гарантированно добавляет CSS/JS KB в $headerinclude (один раз).
 * Важно: единая версия — filemtime.
 */
function af_kb_ensure_header_bits(): void
{
    global $mybb, $headerinclude;

    if (af_kb_assets_disabled_for_current_page()) {
        return;
    }

    static $done = false;
    if ($done) return;
    $done = true;

    $assetBase = rtrim((string)($mybb->asset_url ?? ''), '/');
    if ($assetBase === '') {
        // fallback: относительные пути
        $assetBase = '';
    }

    $cssTag = af_kb_build_css_include_tag('assets/knowledgebase.css');
    $js  = $assetBase . '/inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase.js?v=' . af_kb_asset_version('knowledgebase.js');

    // marker чтобы не плодить дубли даже если $done сорвут
    if (strpos((string)$headerinclude, '<!-- af_kb_assets -->') !== false) {
        return;
    }

    $headerinclude .= "\n<!-- af_kb_assets -->\n";
    if ($cssTag !== '') {
        $headerinclude .= $cssTag . "\n";
    }
    $headerinclude .= '<script type="text/javascript" src="' . htmlspecialchars_uni($js) . '"></script>' . "\n";
}

/* -------------------- ACCESS -------------------- */

function af_kb_is_admin(): bool
{
    global $mybb;
    return !empty($mybb->user['uid']) && $mybb->user['uid'] > 0 && (int)($mybb->usergroup['cancp'] ?? 0) === 1;
}

function af_kb_get_user_groups(): array
{
    global $mybb;

    $groups = [];
    if (!empty($mybb->user['usergroup'])) {
        $groups[] = (int)$mybb->user['usergroup'];
    }
    if (!empty($mybb->user['additionalgroups'])) {
        $extra = explode(',', (string)$mybb->user['additionalgroups']);
        foreach ($extra as $gid) {
            $gid = (int)trim($gid);
            if ($gid > 0) {
                $groups[] = $gid;
            }
        }
    }

    return array_unique($groups);
}

function af_kb_user_in_groups(string $csv): bool
{
    if ($csv === '') {
        return false;
    }

    $allowed = [];
    foreach (explode(',', $csv) as $gid) {
        $gid = (int)trim($gid);
        if ($gid > 0) {
            $allowed[] = $gid;
        }
    }

    if (!$allowed) {
        return false;
    }

    $userGroups = af_kb_get_user_groups();
    foreach ($userGroups as $gid) {
        if (in_array($gid, $allowed, true)) {
            return true;
        }
    }

    return false;
}

function af_kb_can_edit(): bool
{
    if (af_kb_is_admin()) {
        return true;
    }

    $csv = (string)af_kb_get_setting('af_kb_editor_groups', '');
    return af_kb_user_in_groups($csv);
}

function af_kb_can_manage_types(): bool
{
    if (af_kb_is_admin()) {
        return true;
    }

    $csv = (string)af_kb_get_setting('af_kb_types_manage_groups', '');
    return af_kb_user_in_groups($csv);
}

function af_kb_can_view(): bool
{
    if ((int)af_kb_get_setting('af_kb_public_catalog', 1) === 1) {
        return true;
    }

    return af_kb_can_edit() || af_kb_is_admin();
}

function af_kb_cat_can_manage(): bool
{
    if (af_kb_is_admin()) {
        return true;
    }

    $csv = (string)af_kb_get_setting('af_kb_manage_groups', '3,4');
    return af_kb_user_in_groups($csv);
}

function af_kb_categories_enabled(): bool
{
    return (int)af_kb_get_setting('af_kb_categories_enabled', 1) === 1;
}

function af_kb_categories_require_primary(): bool
{
    return (int)af_kb_get_setting('af_kb_categories_require_primary', 0) === 1;
}

function af_kb_cat_validate_key($key): bool
{
    $key = trim((string)$key);
    return $key !== '' && preg_match(AF_KB_CAT_KEY_PATTERN, $key) === 1;
}

function af_kb_cat_cache_key(string $type, bool $onlyActive): string
{
    return 'af_kb_cat_tree_' . md5($type . '|' . ($onlyActive ? '1' : '0'));
}

function af_kb_cat_clear_cache(string $type): void
{
    global $cache;
    if (!is_object($cache)) {
        return;
    }
    $cache->delete(af_kb_cat_cache_key($type, true));
    $cache->delete(af_kb_cat_cache_key($type, false));
}

function af_kb_cat_get_flat(string $type, bool $onlyActive = false): array
{
    global $db;

    if (!$db->table_exists('af_kb_categories') || $type === '') {
        return [];
    }

    $where = "type='" . $db->escape_string($type) . "'";
    if ($onlyActive) {
        $where .= ' AND active=1';
    }

    $list = [];
    $q = $db->simple_select('af_kb_categories', '*', $where, ['order_by' => 'parent_id, sortorder, cat_id', 'order_dir' => 'ASC']);
    while ($row = $db->fetch_array($q)) {
        $list[(int)$row['cat_id']] = $row;
    }
    return $list;
}

function af_kb_cat_get_tree(string $type, bool $onlyActive = true): array
{
    global $cache;

    if ($type === '') {
        return [];
    }

    $cacheKey = af_kb_cat_cache_key($type, $onlyActive);
    if (is_object($cache)) {
        $cached = $cache->read($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $flat = af_kb_cat_get_flat($type, $onlyActive);
    $nodes = [];
    foreach ($flat as $catId => $row) {
        $row['cat_id'] = (int)$catId;
        $row['parent_id'] = (int)($row['parent_id'] ?? 0);
        $row['children'] = [];
        $nodes[$catId] = $row;
    }

    $tree = [];
    foreach ($nodes as $catId => $node) {
        $parentId = (int)$node['parent_id'];
        if ($parentId > 0 && isset($nodes[$parentId])) {
            $nodes[$parentId]['children'][] = &$nodes[$catId];
        } else {
            $tree[] = &$nodes[$catId];
        }
    }
    unset($node);

    if (is_object($cache)) {
        $cache->update($cacheKey, $tree);
    }

    return $tree;
}

function af_kb_cat_flatten_tree_with_level(array $nodes, int $level = 0): array
{
    $flat = [];
    foreach ($nodes as $node) {
        $node['level'] = $level;
        $children = (array)($node['children'] ?? []);
        $node['children'] = [];
        $flat[] = $node;
        if ($children) {
            $flat = array_merge($flat, af_kb_cat_flatten_tree_with_level($children, $level + 1));
        }
    }
    return $flat;
}

function af_kb_cat_create($type, $parent_id, $key, $title_ru, $title_en, $description_ru = '', $description_en = '', $sortorder = 0, $active = 1): int
{
    global $db;

    if (!af_kb_cat_validate_key($key) || trim((string)$type) === '') {
        return 0;
    }

    $db->insert_query('af_kb_categories', [
        'type' => $db->escape_string((string)$type),
        'parent_id' => (int)$parent_id,
        'key' => $db->escape_string((string)$key),
        'title_ru' => $db->escape_string((string)$title_ru),
        'title_en' => $db->escape_string((string)$title_en),
        'description_ru' => $db->escape_string((string)$description_ru),
        'description_en' => $db->escape_string((string)$description_en),
        'sortorder' => (int)$sortorder,
        'active' => (int)$active === 1 ? 1 : 0,
        'updated_at' => TIME_NOW,
    ]);

    af_kb_cat_clear_cache((string)$type);
    return (int)$db->insert_id();
}

function af_kb_cat_update($cat_id, array $data): array
{
    global $db;

    $catId = (int)$cat_id;
    if ($catId <= 0) {
        return ['ok' => false, 'error' => 'Category not found'];
    }

    $existing = $db->fetch_array($db->simple_select('af_kb_categories', '*', 'cat_id=' . $catId, ['limit' => 1]));
    if (!$existing) {
        return ['ok' => false, 'error' => 'Category not found'];
    }

    $targetParentId = (int)($data['parent_id'] ?? $existing['parent_id']);
    if ($targetParentId === $catId) {
        return ['ok' => false, 'error' => 'Invalid parent category'];
    }

    if ($targetParentId > 0) {
        $parentRow = $db->fetch_array($db->simple_select('af_kb_categories', 'cat_id,type', 'cat_id=' . $targetParentId, ['limit' => 1]));
        if (!$parentRow || (string)$parentRow['type'] !== (string)$existing['type']) {
            return ['ok' => false, 'error' => 'Invalid parent category'];
        }

        $flatCats = af_kb_cat_get_flat((string)$existing['type'], false);
        $descendants = af_kb_cat_collect_descendant_ids($catId, $flatCats);
        if (in_array($targetParentId, $descendants, true)) {
            return ['ok' => false, 'error' => 'Invalid parent category'];
        }
    }

    $update = [
        'parent_id' => $targetParentId,
        'title_ru' => $db->escape_string((string)($data['title_ru'] ?? $existing['title_ru'])),
        'title_en' => $db->escape_string((string)($data['title_en'] ?? $existing['title_en'])),
        'description_ru' => $db->escape_string((string)($data['description_ru'] ?? $existing['description_ru'])),
        'description_en' => $db->escape_string((string)($data['description_en'] ?? $existing['description_en'])),
        'sortorder' => (int)($data['sortorder'] ?? $existing['sortorder']),
        'active' => (int)($data['active'] ?? $existing['active']) === 1 ? 1 : 0,
        'updated_at' => TIME_NOW,
    ];
    if (isset($data['key']) && af_kb_cat_validate_key((string)$data['key'])) {
        $update['key'] = $db->escape_string((string)$data['key']);
    }

    $db->update_query('af_kb_categories', $update, 'cat_id=' . $catId);
    af_kb_cat_clear_cache((string)$existing['type']);
    return ['ok' => true];
}

function af_kb_cat_delete($cat_id): array
{
    global $db;

    $catId = (int)$cat_id;
    $row = $db->fetch_array($db->simple_select('af_kb_categories', '*', 'cat_id=' . $catId, ['limit' => 1]));
    if (!$row) {
        return ['ok' => false, 'error' => 'Category not found'];
    }

    $hasChild = (int)$db->fetch_field($db->simple_select('af_kb_categories', 'COUNT(*) AS cnt', 'parent_id=' . $catId), 'cnt') > 0;
    if ($hasChild) {
        return ['ok' => false, 'error' => 'Category has children'];
    }

    $hasEntries = (int)$db->fetch_field($db->simple_select('af_kb_entry_categories', 'COUNT(*) AS cnt', 'cat_id=' . $catId), 'cnt') > 0;
    if ($hasEntries) {
        return ['ok' => false, 'error' => 'Category in use'];
    }

    $db->delete_query('af_kb_categories', 'cat_id=' . $catId);
    af_kb_cat_clear_cache((string)$row['type']);
    return ['ok' => true];
}

function af_kb_cat_collect_descendant_ids(int $cat_id, array $flatCats): array
{
    $all = [$cat_id];
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($flatCats as $row) {
            $id = (int)($row['cat_id'] ?? 0);
            $parent = (int)($row['parent_id'] ?? 0);
            if ($id > 0 && in_array($parent, $all, true) && !in_array($id, $all, true)) {
                $all[] = $id;
                $changed = true;
            }
        }
    }
    return $all;
}

function af_kb_entry_get_categories(int $entry_id): array
{
    global $db;

    $result = ['cat_ids' => [], 'primary' => 0];
    if ($entry_id <= 0 || !$db->table_exists('af_kb_entry_categories')) {
        return $result;
    }

    $q = $db->simple_select('af_kb_entry_categories', 'cat_id,is_primary', 'entry_id=' . $entry_id);
    while ($row = $db->fetch_array($q)) {
        $catId = (int)$row['cat_id'];
        $result['cat_ids'][] = $catId;
        if ((int)$row['is_primary'] === 1) {
            $result['primary'] = $catId;
        }
    }
    return $result;
}

function af_kb_entry_set_categories(int $entry_id, array $cat_ids, int $primary_cat_id = 0): array
{
    global $db;

    if ($entry_id <= 0 || !$db->table_exists('af_kb_entry_categories')) {
        return ['ok' => false, 'error' => 'Invalid entry id or categories table missing'];
    }

    $entry = $db->fetch_array($db->simple_select('af_kb_entries', 'id,type', 'id=' . $entry_id, ['limit' => 1]));
    if (!$entry) {
        return ['ok' => false, 'error' => 'Entry not found'];
    }

    $type = (string)$entry['type'];
    $valid = [];
    $postedCatIds = [];
    foreach ($cat_ids as $catId) {
        $catId = (int)$catId;
        if ($catId <= 0) {
            continue;
        }
        $postedCatIds[] = $catId;
        $cat = $db->fetch_array($db->simple_select('af_kb_categories', 'cat_id,type', 'cat_id=' . $catId, ['limit' => 1]));
        if ($cat && (string)$cat['type'] === $type) {
            $valid[] = $catId;
        }
    }

    $valid = array_values(array_unique($valid));
    $postedCatIds = array_values(array_unique($postedCatIds));

    if ($postedCatIds && count($postedCatIds) !== count($valid)) {
        return ['ok' => false, 'error' => 'One or more categories do not belong to entry type'];
    }

    if ($primary_cat_id > 0) {
        $primaryRow = $db->fetch_array($db->simple_select('af_kb_categories', 'cat_id,type', 'cat_id=' . $primary_cat_id, ['limit' => 1]));
        if (!$primaryRow || (string)$primaryRow['type'] !== $type) {
            return ['ok' => false, 'error' => 'Primary category does not belong to entry type'];
        }
        if (!in_array($primary_cat_id, $valid, true)) {
            $valid[] = $primary_cat_id;
        }
    }

    if (af_kb_categories_require_primary() && $primary_cat_id <= 0) {
        return ['ok' => false, 'error' => 'Primary category is required'];
    }

    $valid = array_values(array_unique(array_map('intval', $valid)));

    $db->delete_query('af_kb_entry_categories', 'entry_id=' . $entry_id);

    foreach ($valid as $catId) {
        $db->insert_query('af_kb_entry_categories', [
            'entry_id' => $entry_id,
            'cat_id' => (int)$catId,
            'is_primary' => ($primary_cat_id > 0 && (int)$catId === $primary_cat_id) ? 1 : 0,
        ]);
    }

    return ['ok' => true, 'cat_ids' => $valid, 'primary_cat_id' => $primary_cat_id];
}


function af_kb_is_staff_viewer(): bool
{
    global $mybb;

    return (int)($mybb->usergroup['cancp'] ?? 0) === 1
        || (int)($mybb->usergroup['canmodcp'] ?? 0) === 1;
}

/* -------------------- UTILITIES -------------------- */

function af_kb_is_ru(): bool
{
    global $lang;
    return isset($lang->language) && $lang->language === 'russian';
}

function af_kb_pick_text(array $row, string $field): string
{
    $suffix = af_kb_is_ru() ? '_ru' : '_en';
    $key = $field . $suffix;
    $value = (string)($row[$key] ?? '');
    if ($value === '') {
        $fallback = (string)($row[$field . '_ru'] ?? '');
        if ($fallback === '') {
            $fallback = (string)($row[$field . '_en'] ?? '');
        }
        return $fallback;
    }

    return $value;
}

function kb_entry_localize(array $row): array
{
    return [
        'title' => af_kb_pick_text($row, 'title'),
        'short' => af_kb_pick_text($row, 'short'),
        'body'  => af_kb_pick_text($row, 'body'),
    ];
}

/**
 * Рендерит полноценную страницу (режим B): один output_page().
 * $fullpageTplName — имя шаблона ПОЛНОЙ страницы (с <!DOCTYPE ... {$headerinclude}{$header}{$footer}).
 */
function af_kb_render_fullpage(string $innerHtml, string $fullpageTplName): void
{
    global $templates, $headerinclude, $header, $footer, $theme, $lang, $mybb;

    // ассеты
    if (function_exists('af_kb_ensure_header_bits')) {
        af_kb_ensure_header_bits();
    }

    // ВАЖНО: поддерживаем оба варианта шаблонов
    $kb_content = $innerHtml;
    $af_kb_content = $innerHtml;

    $page = '';
    $tpl = af_kb_get_template($fullpageTplName);
    if ($tpl === '') {
        $tpl = af_kb_get_template('knowledgebase_page');
    }

    eval("\$page = \"" . $tpl . "\";");
    output_page($page);
    exit;
}



function af_kb_sanitize_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $url = str_replace(["\r", "\n", "\t"], '', $url);
    $url = preg_replace('/["\'()\\\\]/', '', $url);
    if ($url === null || $url === '') {
        return '';
    }

    $parts = parse_url($url);
    if ($parts === false) {
        return '';
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($scheme !== '') {
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        return $url;
    }

    if (strpos($url, '//') === 0) {
        return '';
    }

    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
        return '';
    }

    return $url;
}

function af_kb_sanitize_icon_class(string $class): string
{
    $class = trim($class);
    if ($class === '') {
        return '';
    }

    $class = preg_replace('/[^a-zA-Z0-9 _:-]/', '', $class);
    return $class ?? '';
}

function af_kb_build_icon_html(string $iconUrl, string $iconClass): string
{
    $url = af_kb_sanitize_url($iconUrl);
    if ($url !== '') {
        return '<img class="af-kb-icon-img" src="' . htmlspecialchars_uni($url) . '" alt="" loading="lazy" />';
    }

    $class = af_kb_sanitize_icon_class($iconClass);
    if ($class !== '') {
        return '<i class="' . htmlspecialchars_uni($class) . '"></i>';
    }

    return '';
}

function af_kb_build_bg_style(string $bgUrl): string
{
    $url = af_kb_sanitize_url($bgUrl);
    if ($url === '') {
        return '';
    }

    return "background-image:url('" . htmlspecialchars_uni($url) . "');";
}

function af_kb_build_body_bg_style(string $bgUrl): string
{
    $url = af_kb_sanitize_url($bgUrl);
    if ($url === '') {
        return '';
    }

    $escaped = htmlspecialchars_uni($url);

    // id + marker, чтобы не плодить дубликаты при повторных прогонках pre_output_page
    return '<style id="af-kb-body-bg-style">'
        . 'html,body{'
        . 'background-image:url(\'' . $escaped . '\') !important;'
        . 'background-repeat:no-repeat !important;'
        . 'background-position:center center !important;'
        . 'background-attachment:fixed !important;'
        . 'background-size:cover !important;'
        . '}'
        . '</style><!--af_kb_body_bg-->';
}

function af_kb_resolve_body_bg_for_request(): string
{
    global $mybb, $db;

    // фон нам нужен только на витрине KB (каталог/категория/запись)
    $action = (string)$mybb->get_input('action');
    if ($action !== 'kb') {
        return '';
    }

    $type = trim((string)$mybb->get_input('type'));
    $key  = trim((string)$mybb->get_input('key'));

    if ($type === '') {
        // корневой каталог — фон не задаём
        return '';
    }

    // 1) фон типа (категории)
    $typeRow = $db->fetch_array(
        $db->simple_select(
            'af_kb_types',
            'bg_url',
            "type='".$db->escape_string($type)."'",
            ['limit' => 1]
        )
    );
    $typeBg = $typeRow ? (string)($typeRow['bg_url'] ?? '') : '';

    // если это страница категории (без key) — достаточно фона типа
    if ($key === '') {
        return $typeBg;
    }

    // 2) фон записи (meta_json ui.background_url → bg_url → fallback к типу)
    $entry = $db->fetch_array(
        $db->simple_select(
            'af_kb_entries',
            'meta_json,bg_url',
            "type='".$db->escape_string($type)."' AND `key`='".$db->escape_string($key)."'",
            ['limit' => 1]
        )
    );

    $entryBg = '';
    if ($entry) {
        $meta = json_decode((string)($entry['meta_json'] ?? ''), true);
        if (is_array($meta) && isset($meta['ui']) && is_array($meta['ui'])) {
            $entryBg = (string)($meta['ui']['background_url'] ?? '');
        }
        if ($entryBg === '') {
            $entryBg = (string)($entry['bg_url'] ?? '');
        }
    }

    if ($entryBg !== '') {
        return $entryBg;
    }

    return $typeBg;
}

function af_kb_build_tech_hint(string $text): string
{
    $text = preg_replace('/^\s*\[icon=[^\]]+\]\s*/i', '', $text) ?? $text;
    $text = trim(strip_tags($text));
    if ($text === '') {
        return '';
    }

    $text = preg_replace('/\r\n?/', "\n", $text);
    $lines = preg_split('/\n+/', $text);
    if (!is_array($lines)) {
        return $text;
    }

    $lines = array_slice($lines, 0, 3);
    $text = implode("\n", $lines);
    return trim($text);
}

function af_kb_strip_tech_icon_tag(string $text): string
{
    $text = preg_replace('/^\s*\[icon=[^\]]+\]\s*/i', '', $text);
    return $text ?? '';
}

function af_kb_build_tech_note_html(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $iconHtml = '';
    if (preg_match('/^\s*\[icon=([^\]]+)\]\s*/i', $text, $matches)) {
        $rawIcon = trim($matches[1]);
        $text = substr($text, strlen($matches[0]));
        $url = af_kb_sanitize_url($rawIcon);
        if ($url !== '' && (preg_match('~^https?://~i', $rawIcon) || strpos($rawIcon, '/') !== false || strpos($rawIcon, '.') !== false)) {
            $iconHtml = '<img class="af-kb-icon-img" src="' . htmlspecialchars_uni($url) . '" alt="" loading="lazy" />';
        } else {
            $class = af_kb_sanitize_icon_class($rawIcon);
            if ($class !== '') {
                $iconHtml = '<i class="' . htmlspecialchars_uni($class) . '"></i>';
            }
        }
    }

    $parsed = af_kb_parse_message(trim($text));
    if ($parsed === '') {
        return '';
    }

    if ($iconHtml !== '') {
        return '<span class="af-kb-tech-icon">' . $iconHtml . '</span><span class="af-kb-tech-text">' . $parsed . '</span>';
    }

    return $parsed;
}

function af_kb_sanitize_rendered_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    // На всякий случай прибиваем скрипты/стили (даже если allow_html=0 в модалке)
    $html = preg_replace('~<\s*(script|style)\b[^>]*>.*?<\s*/\s*\1\s*>~is', '', $html) ?? $html;

    // Разрешаем набор тегов, которых требует MyBB MyCode (таблицы/спойлеры/цитаты/код)
    // ВАЖНО: оставляем style и onclick (они нужны MyBB-рендеру), но чистим href/src.
    $allowed = implode('', [
        '<b><strong><i><em><u><s><br><p>',
        '<ul><ol><li>',
        '<span><div>',
        '<a><img>',
        '<blockquote><code><pre>',
        '<hr>',
        '<table><caption><colgroup><col><thead><tbody><tfoot><tr><th><td>',
        '<details><summary>',
        '<input><button>',
        '<iframe>',
    ]);

    $html = strip_tags($html, $allowed);
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    // Чистим href/src от мусора и потенциально опасных схем
    // (javascript:, data:, vbscript:, file: и т.п.)
    $html = preg_replace_callback(
        '/\s(href|src)\s*=\s*("|\')(.*?)\2/i',
        static function (array $m): string {
            $attr = strtolower($m[1]);
            $val = $m[3];

            $clean = af_kb_sanitize_url($val);

            // Дополнительно рубим data: и javascript: даже если sanitize_url вдруг пропустил относительное странное
            $lower = strtolower(trim($val));
            if (strpos($lower, 'javascript:') === 0 || strpos($lower, 'data:') === 0 || strpos($lower, 'vbscript:') === 0) {
                return '';
            }

            if ($clean === '') {
                return '';
            }

            return ' ' . $attr . '="' . htmlspecialchars_uni($clean) . '"';
        },
        $html
    );

    $html = preg_replace_callback(
        '/\s(href|src)\s*=\s*([^\s>\'"]+)/i',
        static function (array $m): string {
            $attr = strtolower($m[1]);
            $val = $m[2];

            $clean = af_kb_sanitize_url($val);
            $lower = strtolower(trim($val));
            if (strpos($lower, 'javascript:') === 0 || strpos($lower, 'data:') === 0 || strpos($lower, 'vbscript:') === 0) {
                return '';
            }

            if ($clean === '') {
                return '';
            }

            return ' ' . $attr . '="' . htmlspecialchars_uni($clean) . '"';
        },
        $html
    );

    return $html;
}

function af_kb_render_tech_note_details(string $label, string $text): string
{
    $html = af_kb_build_tech_note_html($text);
    if ($html === '') {
        return '';
    }

    return '<details class="af-kb-tech"><summary>' . htmlspecialchars_uni($label) . '</summary><div class="af-kb-tech-note">' . $html . '</div></details>';
}

function af_kb_parse_message(string $message): string
{
    if ($message === '') {
        return '';
    }

    if (!class_exists('postParser')) {
        require_once MYBB_ROOT . 'inc/class_parser.php';
    }

    $parser = new postParser;
    $options = [
        'allow_html'         => 1,
        'allow_mycode'       => 1,
        'allow_basicmycode'  => 1,
        'allow_smilies'      => 1,
        'allow_imgcode'      => 1,
        'allow_videocode'    => 1,
        'allow_list'         => 1,
        'allow_alignmycode'  => 1,
        'allow_font'         => 1,
        'allow_color'        => 1,
        'allow_size'         => 1,
        'filter_badwords'    => 1,
        'nl2br'              => 1,
    ];

    return $parser->parse_message($message, $options);
}

function af_kb_parse_message_modal(string $message): string
{
    if ($message === '') {
        return '';
    }

    if (!class_exists('postParser')) {
        require_once MYBB_ROOT . 'inc/class_parser.php';
    }

    $parser = new postParser;

    // В модалке мы РАЗРЕШАЕМ HTML на этапе парсера,
    // но потом ЖЁСТКО режем всё санитайзером af_kb_sanitize_rendered_html().
    // Это решает: таблицы/сложный MyCode/встроенные разметки, которые иначе “ломались”.
    $options = [
        'allow_html'         => 1,

        'allow_mycode'       => 1,
        'allow_basicmycode'  => 1,
        'allow_smilies'      => 1,
        'allow_imgcode'      => 1,
        'allow_videocode'    => 1,
        'allow_list'         => 1,
        'allow_alignmycode'  => 1,
        'allow_font'         => 1,
        'allow_color'        => 1,
        'allow_size'         => 1,
        'filter_badwords'    => 1,
        'nl2br'              => 1,
    ];

    return $parser->parse_message($message, $options);
}

function af_kb_render_block(string $raw): string
{
    if ($raw === '') {
        return '';
    }

    // Нормальный путь: парсим + санитайзим
    if (function_exists('af_kb_parse_message_modal') && function_exists('af_kb_sanitize_rendered_html')) {
        $parsed = af_kb_parse_message_modal($raw);
        $safe = af_kb_sanitize_rendered_html($parsed);

        // а возвращаем безопасный текст (как раньше были “голые BB-коды”).
        if (trim($safe) !== '') {
            return $safe;
        }

        return nl2br(htmlspecialchars_uni($raw));
    }

    return nl2br(htmlspecialchars_uni($raw));
}

function af_kb_render_json_error(string $message, int $code = 403): void
{
    af_kb_send_json(['success' => false, 'error' => $message], $code);
}

function af_kb_send_json(array $payload, int $code = 200): void
{
    $GLOBALS['af_disable_pre_output'] = true;
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function af_kb_debug_entry_cats_log_path(): string
{
    return MYBB_ROOT . 'inc/plugins/advancedfunctionality/cache/af_kb_entry_cats_last_post.json';
}

function af_kb_debug_entry_cats_store_last_post(array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    @file_put_contents(af_kb_debug_entry_cats_log_path(), $json, LOCK_EX);
}

function af_kb_debug_entry_cats_read_last_post(): ?array
{
    $path = af_kb_debug_entry_cats_log_path();
    if (!is_file($path)) {
        return null;
    }

    $raw = (string)@file_get_contents($path);
    if ($raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function af_kb_validate_json(string $raw): bool
{
    if ($raw === '') {
        return true;
    }

    json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE;
}

function af_kb_normalize_json(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '{}';
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return $raw;
    }

    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function af_kb_decode_json(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return [];
    }

    return $decoded;
}


function af_kb_cleanup_meta_payload(array $meta): array
{
    if (array_key_exists('stats', $meta)) {
        unset($meta['stats']);
    }
    if (array_key_exists('bonuses', $meta)) {
        unset($meta['bonuses']);
    }
    if (array_key_exists('links', $meta)) {
        unset($meta['links']);
    }

    return $meta;
}

function af_kb_is_empty_json(string $raw): bool
{
    $raw = trim($raw);
    if ($raw === '') {
        return true;
    }

    return in_array($raw, ['{}', '[]'], true);
}

function af_kb_is_technical_block(array $block): bool
{
    $blockKey = strtolower(trim((string)($block['block_key'] ?? '')));
    if ($blockKey === 'data' || $blockKey === 'rules' || $blockKey === 'tech' || $blockKey === 'tech_hint' || $blockKey === 'meta_json' || strpos($blockKey, 'meta') === 0) {
        return true;
    }

    $dataJson = trim((string)($block['data_json'] ?? ''));
    if ($dataJson === '') {
        return false;
    }
    $decoded = json_decode($dataJson, true);
    if (!is_array($decoded)) {
        return false;
    }

    return !empty($decoded['is_technical']) || (($decoded['visibility'] ?? '') === 'technical');
}


function af_kb_item_get_humanity_cost(array $entry): float
{
    $rules = kb_parse_rules($entry);
    $item = (array)($rules['item'] ?? []);
    $augmentation = (array)($item['augmentation'] ?? []);
    if (isset($augmentation['humanity_cost_percent'])) {
        return max(0.0, min(100.0, (float)$augmentation['humanity_cost_percent']));
    }
    $cyberware = (array)($item['cyberware'] ?? []);
    if (isset($cyberware['humanity_cost_percent'])) {
        return max(0.0, min(100.0, (float)$cyberware['humanity_cost_percent']));
    }
    if (isset($entry['humanity_cost'])) {
        return max(0.0, (float)$entry['humanity_cost']);
    }
    return 0.0;
}

function kb_item_get_humanity_cost(array $entry): float
{
    return af_kb_item_get_humanity_cost($entry);
}

function af_kb_normalize_rules_json(string $raw): string
{
    $decoded = af_kb_decode_json($raw);
    if (!$decoded) {
        return '{}';
    }

    if (is_array($decoded)) {
        $mechanic = trim((string)($decoded['mechanic'] ?? ''));
        $hasArpgEnvelope = (isset($decoded['rules']) && is_array($decoded['rules'] ?? null))
            || (isset($decoded['entity_kind']) && isset($decoded['data_json']) && is_array($decoded['data_json'] ?? null));
        if ($mechanic === 'arpg' || $hasArpgEnvelope) {
            return (string)(json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        }
    }

    $schema = trim((string)($decoded['schema'] ?? ''));
    $typeProfile = trim((string)($decoded['type_profile'] ?? ''));
    $isItem = (
        $schema === 'af_kb.item.v2'
        || $typeProfile === 'item'
        || (isset($decoded['item']) && is_array($decoded['item']))
    );

    if ($isItem) {
        $decoded = af_kb_normalize_item_rules_payload($decoded);
        if (!isset($decoded['item']) || !is_array($decoded['item'])) {
            $decoded['item'] = [];
        }

        return (string)json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    foreach (['choices', 'traits', 'grants'] as $arrayKey) {
        if (!isset($decoded[$arrayKey]) || !is_array($decoded[$arrayKey])) {
            $decoded[$arrayKey] = [];
        }
    }

    return (string)json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function af_kb_validate_rules_json_by_type_dnd(string $type, string $normalizedJson, array &$errors): string
{
    $typeSchema   = af_kb_get_type_schema($type);
    $rulesEnabled = !empty($typeSchema['rules_enabled']);

    $rulesData = af_kb_decode_json($normalizedJson);
    if (!is_array($rulesData)) {
        $rulesData = [];
    }

    if (!$rulesEnabled) {
        return json_encode($rulesData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    $isItem = ($type === 'item');

    $expectedSchema = trim((string)($typeSchema['rules_schema'] ?? ($isItem ? 'af_kb.item.v2' : AF_KB_RULES_SCHEMA)));
    if ($expectedSchema === '') {
        $expectedSchema = $isItem ? 'af_kb.item.v2' : AF_KB_RULES_SCHEMA;
    }

    $defaults = (array)($typeSchema['defaults'] ?? []);

    if ($isItem) {
        $rulesData = af_kb_normalize_item_rules_payload($rulesData);
        $defaults = af_kb_normalize_item_rules_payload($defaults);
    }

    $shouldMergeDefaults = !in_array($type, ['class', 'theme'], true);
    if ($shouldMergeDefaults) {
        $rulesData = array_replace_recursive($defaults, $rulesData);
    }

    $rulesData['schema'] = $expectedSchema;
    $rulesData['type_profile'] = $type;
    if (!isset($rulesData['version']) || (string)$rulesData['version'] === '') {
        $rulesData['version'] = '1.0';
    }

    if (!$isItem) {
        foreach (['traits', 'grants', 'choices'] as $k) {
            if (!isset($rulesData[$k]) || !is_array($rulesData[$k])) {
                $rulesData[$k] = [];
            }
        }
    }

    if (in_array($type, ['skill', 'knowledge'], true)) {
        $rulesData['skill'] = af_kb_normalize_skill_payload((array)($rulesData['skill'] ?? []));
    }

    if ($isItem) {
        $rulesData = af_kb_normalize_item_rules_payload($rulesData);
        if (!isset($rulesData['item']) || !is_array($rulesData['item'])) {
            $rulesData['item'] = [];
        }
        $rulesData = af_kb_validate_item_slot_requirements($rulesData, $errors);
    }

    $requiredKeys = (array)($typeSchema['rules_required_keys'] ?? []);
    $needsEffects = in_array('effects', $requiredKeys, true) || array_key_exists('effects', $defaults);
    if ($needsEffects && (!isset($rulesData['effects']) || !is_array($rulesData['effects']))) {
        $rulesData['effects'] = [];
    }

    foreach ($requiredKeys as $requiredKey) {
        $rk = (string)$requiredKey;
        if (!$shouldMergeDefaults && in_array($rk, ['fixed', 'grants', 'choices', 'progression'], true)) {
            continue;
        }
        if (!array_key_exists($rk, $rulesData)) {
            $errors[] = 'Required data field missing: ' . $rk;
        }
    }

    if (!$isItem) {
        $rulesData['traits'] = af_kb_normalize_traits_json($rulesData['traits'], $errors);
        $rulesData['grants'] = af_kb_normalize_grants_json($rulesData['grants'], $errors);
    }

    return json_encode($rulesData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

function af_kb_validate_rules_json_by_type_arpg(string $type, string $normalizedJson, array &$errors): string
{
    $rulesData = af_kb_decode_json($normalizedJson);
    if (!is_array($rulesData)) {
        $rulesData = [];
    }

    $typeDef = af_kb_arpg_type_definition($type);
    if (empty($typeDef)) {
        $errors[] = 'ARPG rules are not configured for type "' . $type . '".';
        return json_encode($rulesData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    $rulesData = af_kb_arpg_migrate_legacy_payload($type, $rulesData);

    $defaults = af_kb_arpg_envelope_defaults($type);
    $defaults['rules'] = array_replace_recursive((array)($defaults['rules'] ?? []), af_kb_default_type_profile_payload_arpg($type));
    $rulesData = array_replace_recursive($defaults, $rulesData);

    $isService = !empty($typeDef['service']);
    $rulesData['schema'] = AF_KB_ARPG_META_SCHEMA;
    $rulesData['mechanic'] = 'arpg';
    $rulesData['rules']['schema'] = AF_KB_ARPG_RULES_SCHEMA;
    $rulesData['rules']['type_profile'] = $isService ? 'service_mechanics' : (string)($typeDef['entity_kind'] ?? '');
    if (trim((string)($rulesData['rules']['version'] ?? '')) === '') {
        $rulesData['rules']['version'] = '1.0';
    }

    $validationErrors = [];
    $rulesData = af_kb_validate_arpg_entry_by_type($type, $rulesData, $validationErrors);
    if (!empty($validationErrors)) {
        $errors = array_merge($errors, $validationErrors);
    }

    return json_encode($rulesData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

function af_kb_arpg_legacy_type_map(): array
{
    return [
        'arpg_ability_active' => ['new_type' => 'arpg_ability', 'service_kind' => '', 'forced_rule_type' => 'active'],
        'arpg_ability_passive' => ['new_type' => 'arpg_ability', 'service_kind' => '', 'forced_rule_type' => 'passive'],
        'arpg_modifier' => ['new_type' => 'arpg_mechanics', 'service_kind' => 'modifier_template'],
        'arpg_status' => ['new_type' => 'arpg_mechanics', 'service_kind' => 'status_def'],
        'arpg_resource' => ['new_type' => 'arpg_mechanics', 'service_kind' => 'resource_def'],
        'arpg_mechanic_profile' => ['new_type' => 'arpg_mechanics', 'service_kind' => 'mechanic_profile'],
        'arpg_resource_def' => ['new_type' => 'arpg_mechanics', 'service_kind' => 'resource_def'],
        'arpg_status_def' => ['new_type' => 'arpg_mechanics', 'service_kind' => 'status_def'],
        'arpg_modifier_template' => ['new_type' => 'arpg_mechanics', 'service_kind' => 'modifier_template'],
        'arpg_formula_def' => ['new_type' => 'arpg_mechanics', 'service_kind' => 'formula_def'],
        'arpg_trigger_template' => ['new_type' => 'arpg_mechanics', 'service_kind' => 'trigger_template'],
        'arpg_condition_template' => ['new_type' => 'arpg_mechanics', 'service_kind' => 'condition_template'],
        'arpg_scaling_table' => ['new_type' => 'arpg_mechanics', 'service_kind' => 'scaling_table'],
        'arpg_combat_template' => ['new_type' => 'arpg_mechanics', 'service_kind' => 'combat_template'],
        'arpg_snippet' => ['new_type' => 'arpg_mechanics', 'service_kind' => 'snippet'],
    ];
}

function af_kb_arpg_migrate_legacy_payload(string $type, array $payload): array
{
    $typeDef = af_kb_arpg_type_definition($type);
    $typeProfile = (string)($typeDef['entity_kind'] ?? '');
    $isService = !empty($typeDef['service']);
    $hasEnvelope = isset($payload['schema']) || isset($payload['rules']) || isset($payload['mechanic']);

    if (!$hasEnvelope && isset($payload['meta']) && isset($payload['data_json'])) {
        $legacyMeta = (array)($payload['meta'] ?? []);
        $legacyMetaRules = (array)($legacyMeta['rules'] ?? []);
        $legacyUi = (array)($legacyMeta['ui'] ?? []);
        $legacyDataRoot = (array)($payload['data_json'] ?? []);
        $legacyData = (array)($legacyDataRoot['data'] ?? $legacyDataRoot['rules'] ?? $legacyDataRoot);
        $payload = [
            'schema' => AF_KB_ARPG_META_SCHEMA,
            'mechanic' => 'arpg',
            'tags' => (array)($legacyMeta['tags'] ?? $payload['tags'] ?? []),
            'ui' => [
                'icon_class' => (string)($legacyUi['icon_class'] ?? $legacyUi['icon'] ?? ''),
                'icon_url' => (string)($legacyUi['icon_url'] ?? ''),
                'background_url' => (string)($legacyUi['background_url'] ?? ''),
                'background_tab_url' => (string)($legacyUi['background_tab_url'] ?? ''),
            ],
            'blocks' => (array)($legacyDataRoot['blocks'] ?? $legacyMeta['blocks'] ?? []),
            'rules' => array_replace_recursive(
                ['schema' => AF_KB_ARPG_RULES_SCHEMA, 'type_profile' => $typeProfile, 'version' => (string)($legacyMetaRules['version'] ?? '1.0')],
                $legacyData
            ),
        ];
    } elseif (!$hasEnvelope || isset($payload['type_profile']) || isset($payload['mechanics']) || isset($payload['classification'])) {
        $legacyUi = [];
        if (isset($payload['ui']) && is_array($payload['ui'])) {
            $legacyUi = (array)$payload['ui'];
        } elseif (isset($payload['meta']['ui']) && is_array($payload['meta']['ui'])) {
            $legacyUi = (array)$payload['meta']['ui'];
        }

        $legacyBlocks = [];
        if (isset($payload['blocks']) && is_array($payload['blocks'])) {
            $legacyBlocks = (array)$payload['blocks'];
        } elseif (isset($payload['data_json']['blocks']) && is_array($payload['data_json']['blocks'])) {
            $legacyBlocks = (array)$payload['data_json']['blocks'];
        }

        $legacyRules = (array)($payload['data'] ?? $payload['mechanics'] ?? $payload['classification'] ?? []);
        $payload = [
            'schema' => AF_KB_ARPG_META_SCHEMA,
            'mechanic' => 'arpg',
            'tags' => (array)($payload['tags'] ?? []),
            'ui' => [
                'icon_class' => (string)($legacyUi['icon_class'] ?? $legacyUi['icon'] ?? ''),
                'icon_url' => (string)($legacyUi['icon_url'] ?? ''),
                'background_url' => (string)($legacyUi['background_url'] ?? ''),
                'background_tab_url' => (string)($legacyUi['background_tab_url'] ?? ''),
            ],
            'blocks' => $legacyBlocks,
            'rules' => array_replace_recursive(
                ['schema' => AF_KB_ARPG_RULES_SCHEMA, 'type_profile' => $typeProfile, 'version' => '1.0'],
                $payload,
                $legacyRules
            ),
        ];
    }

    if (!isset($payload['rules']) || !is_array($payload['rules'])) {
        $payload['rules'] = [];
    }
    if (!isset($payload['blocks']) || !is_array($payload['blocks'])) {
        $payload['blocks'] = [];
    }
    if (!isset($payload['ui']) || !is_array($payload['ui'])) {
        $payload['ui'] = [];
    }

    $payload['blocks'] = array_values(array_filter((array)$payload['blocks'], static fn($block) => is_array($block)));
    $payload['rules'] = af_kb_arpg_migrate_legacy_rules_contract($type, (array)$payload['rules']);
    if (trim((string)($payload['rules']['schema'] ?? '')) === '') {
        $payload['rules']['schema'] = AF_KB_ARPG_RULES_SCHEMA;
    }
    if ($isService) {
        $payload['rules']['type_profile'] = 'service_mechanics';
        if (trim((string)($payload['rules']['service_kind'] ?? '')) === '') {
            $payload['rules']['service_kind'] = 'mechanic_profile';
        }
    } else {
        $payload['rules']['type_profile'] = $typeProfile;
    }

    return $payload;
}

function af_kb_arpg_pick_first(array $source, array $keys, $fallback = null)
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $source)) {
            continue;
        }
        $value = $source[$key];
        if ($value === null) {
            continue;
        }
        if (is_string($value) && trim($value) === '') {
            continue;
        }
        return $value;
    }

    return $fallback;
}

function af_kb_arpg_pick_array(array $source, array $keys): array
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $source)) {
            continue;
        }
        if (is_array($source[$key])) {
            return array_values((array)$source[$key]);
        }
    }

    return [];
}

function af_kb_arpg_migrate_legacy_rules_contract(string $type, array $rules): array
{
    if ($type === 'arpg_ability') {
        $rules['type'] = (string)af_kb_arpg_pick_first($rules, ['type', 'ability_type', 'activation_type', 'cast_kind', 'kind'], '');
        $rules['subtype'] = (string)af_kb_arpg_pick_first($rules, ['subtype', 'ability_subtype'], (string)($rules['subtype'] ?? ''));
        $rules['slot'] = (string)af_kb_arpg_pick_first($rules, ['slot', 'ability_slot'], (string)($rules['slot'] ?? ''));
        $rules['damage_type'] = (string)af_kb_arpg_pick_first($rules, ['damage_type', 'element', 'damage_kind'], (string)($rules['damage_type'] ?? ''));
        $rules['targeting'] = (string)af_kb_arpg_pick_first($rules, ['targeting', 'target_type', 'target'], (string)($rules['targeting'] ?? ''));
        foreach (['range', 'cast_time', 'cooldown', 'duration', 'max_charges', 'level_cap'] as $numericKey) {
            $rules[$numericKey] = af_kb_arpg_pick_first($rules, [$numericKey, 'ability_' . $numericKey], $rules[$numericKey] ?? null);
        }
        $rules['resources'] = af_kb_arpg_pick_array($rules, ['resources', 'resource_costs', 'costs']);
        $rules['effects'] = af_kb_arpg_pick_array($rules, ['effects', 'ability_effects']);
        $rules['modifiers'] = af_kb_arpg_pick_array($rules, ['modifiers', 'buffs']);
        $rules['triggers'] = af_kb_arpg_pick_array($rules, ['triggers', 'proc_triggers']);
        $rules['conditions'] = af_kb_arpg_pick_array($rules, ['conditions', 'requirements']);
        $rules['stacking'] = af_kb_arpg_pick_array($rules, ['stacking', 'stacks']);
        $rules['upgrade_requirements'] = af_kb_arpg_pick_array($rules, ['upgrade_requirements', 'upgrades', 'progression']);
    } elseif ($type === 'arpg_talent') {
        $rules['tree'] = (string)af_kb_arpg_pick_first($rules, ['tree', 'talent_tree', 'branch'], (string)($rules['tree'] ?? ''));
        $rules['tier'] = af_kb_arpg_pick_first($rules, ['tier', 'row'], $rules['tier'] ?? null);
        $rules['rank'] = (string)af_kb_arpg_pick_first($rules, ['rank', 'rarity'], (string)($rules['rank'] ?? ''));
        $rules['slot_type'] = (string)af_kb_arpg_pick_first($rules, ['slot_type', 'slot', 'talent_slot'], (string)($rules['slot_type'] ?? ''));
        $rules['node_label'] = (string)af_kb_arpg_pick_first($rules, ['node_label', 'label', 'title'], (string)($rules['node_label'] ?? ''));
        $rules['rank_weight'] = af_kb_arpg_pick_first($rules, ['rank_weight', 'weight'], $rules['rank_weight'] ?? null);
        $rules['socket_cost'] = af_kb_arpg_pick_first($rules, ['socket_cost', 'cost', 'point_cost'], $rules['socket_cost'] ?? null);
        $rules['effects'] = af_kb_arpg_pick_array($rules, ['effects']);
        $rules['passive_effects'] = af_kb_arpg_pick_array($rules, ['passive_effects', 'passives']);
        $rules['modifiers'] = af_kb_arpg_pick_array($rules, ['modifiers']);
        $rules['grants'] = af_kb_arpg_pick_array($rules, ['grants', 'grant_refs']);
        $rules['requirements'] = af_kb_arpg_pick_array($rules, ['requirements', 'prerequisites']);
        $rules['mutual_exclusives'] = af_kb_arpg_pick_array($rules, ['mutual_exclusives', 'exclusive_with']);
    } elseif ($type === 'arpg_item') {
        $rules['item_kind'] = (string)af_kb_arpg_pick_first($rules, ['item_kind', 'kind', 'item_type'], (string)($rules['item_kind'] ?? ''));
        $rules['equip_slot'] = (string)af_kb_arpg_pick_first($rules, ['equip_slot', 'slot', 'equipment_slot'], (string)($rules['equip_slot'] ?? ''));
        $rules['rarity'] = (string)af_kb_arpg_pick_first($rules, ['rarity', 'rank'], (string)($rules['rarity'] ?? ''));
        $rules['subtype'] = (string)af_kb_arpg_pick_first($rules, ['subtype', 'item_subtype'], (string)($rules['subtype'] ?? ''));
        $rules['progression_stage'] = (string)af_kb_arpg_pick_first($rules, ['progression_stage', 'stage'], (string)($rules['progression_stage'] ?? ''));
        foreach (['level_min', 'level_max', 'level_cap', 'base_damage', 'attack_speed', 'range', 'crit_bonus', 'base_defense', 'stack_max', 'use_cooldown'] as $numericKey) {
            $rules[$numericKey] = af_kb_arpg_pick_first($rules, [$numericKey], $rules[$numericKey] ?? null);
        }
        $rules['base_stats'] = af_kb_arpg_pick_array($rules, ['base_stats', 'stats']);
        $rules['modifiers'] = af_kb_arpg_pick_array($rules, ['modifiers']);
        $rules['effects'] = af_kb_arpg_pick_array($rules, ['effects']);
        $rules['passive_effects'] = af_kb_arpg_pick_array($rules, ['passive_effects', 'passives']);
        $rules['triggers'] = af_kb_arpg_pick_array($rules, ['triggers']);
        $rules['grants'] = af_kb_arpg_pick_array($rules, ['grants', 'grant_refs']);
        $rules['upgrade_steps'] = af_kb_arpg_pick_array($rules, ['upgrade_steps', 'upgrades']);
    } elseif ($type === 'arpg_bestiary') {
        $rules['family'] = (string)af_kb_arpg_pick_first($rules, ['family', 'species_family', 'creature_family'], (string)($rules['family'] ?? ''));
        $rules['archetype'] = (string)af_kb_arpg_pick_first($rules, ['archetype', 'creature_archetype'], (string)($rules['archetype'] ?? ''));
        $rules['faction'] = (string)af_kb_arpg_pick_first($rules, ['faction', 'faction_key'], (string)($rules['faction'] ?? ''));
        $rules['rank'] = (string)af_kb_arpg_pick_first($rules, ['rank', 'monster_rank'], (string)($rules['rank'] ?? ''));
        $rules['threat_tier'] = af_kb_arpg_pick_first($rules, ['threat_tier', 'threat', 'danger_tier'], $rules['threat_tier'] ?? null);
        $rules['level'] = af_kb_arpg_pick_first($rules, ['level', 'monster_level'], $rules['level'] ?? null);
        $legacyCombat = (array)af_kb_arpg_pick_first($rules, ['combat_stats', 'stats', 'combat'], []);
        $rules['combat_stats'] = [
            'hp' => af_kb_arpg_pick_first($legacyCombat, ['hp', 'health'], $legacyCombat['hp'] ?? 0),
            'atk' => af_kb_arpg_pick_first($legacyCombat, ['atk', 'attack'], $legacyCombat['atk'] ?? 0),
            'def' => af_kb_arpg_pick_first($legacyCombat, ['def', 'defense'], $legacyCombat['def'] ?? 0),
            'armor' => af_kb_arpg_pick_first($legacyCombat, ['armor'], $legacyCombat['armor'] ?? 0),
            'crit_rate' => af_kb_arpg_pick_first($legacyCombat, ['crit_rate', 'crit'], $legacyCombat['crit_rate'] ?? 0),
            'crit_dmg' => af_kb_arpg_pick_first($legacyCombat, ['crit_dmg', 'crit_damage'], $legacyCombat['crit_dmg'] ?? 0),
            'status_hit' => af_kb_arpg_pick_first($legacyCombat, ['status_hit', 'status_accuracy'], $legacyCombat['status_hit'] ?? 0),
            'status_resist' => af_kb_arpg_pick_first($legacyCombat, ['status_resist', 'status_resistance'], $legacyCombat['status_resist'] ?? 0),
        ];
        $rules['ability_keys'] = af_kb_arpg_pick_array($rules, ['ability_keys', 'abilities', 'ability_refs']);
        $rules['loot'] = af_kb_arpg_pick_array($rules, ['loot', 'loot_table', 'drops']);
    } elseif ($type === 'arpg_mechanics') {
        $legacyKind = (string)af_kb_arpg_pick_first($rules, ['service_kind', 'entity_kind', 'kind', 'mechanic_kind'], '');
        if ($legacyKind !== '') {
            $rules['service_kind'] = $legacyKind;
        }
        if (!isset($rules['entries']) || !is_array($rules['entries'])) {
            $rules['entries'] = af_kb_arpg_pick_array($rules, ['entries', 'templates', 'items']);
        }
    }

    return $rules;
}

function af_kb_arpg_public_entity_kinds(): array
{
    return ['origin', 'archetype', 'faction', 'bestiary', 'ability', 'talent', 'item', 'lore'];
}

function af_kb_arpg_service_entity_kinds(): array
{
    return ['mechanic_profile', 'resource_def', 'status_def', 'modifier_template', 'formula_def', 'trigger_template', 'condition_template', 'scaling_table', 'combat_template', 'snippet'];
}

function af_kb_arpg_get_payload_path(array $payload, string $path, bool &$exists = false)
{
    $parts = explode('.', trim($path));
    $cursor = $payload;
    foreach ($parts as $part) {
        if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
            $exists = false;
            return null;
        }
        $cursor = $cursor[$part];
    }

    $exists = true;
    return $cursor;
}

function af_kb_arpg_require_path(array $payload, string $path, string $error, array &$errors): bool
{
    $exists = false;
    $value = af_kb_arpg_get_payload_path($payload, $path, $exists);
    if (!$exists) {
        $errors[] = $error;
        return false;
    }

    if ($value === '' || $value === null || (is_array($value) && empty($value))) {
        $errors[] = $error;
        return false;
    }

    return true;
}

function af_kb_arpg_validate_ref_string(string $value): bool
{
    $ref = trim($value);
    if ($ref === '') {
        return false;
    }
    return (bool)preg_match('/^[a-z0-9_]+:[a-z0-9_.\\-]+$/i', $ref);
}

function af_kb_validate_arpg_envelope(string $type, array $payload, bool $isService, array &$errors): array
{
    if ((string)($payload['schema'] ?? '') !== AF_KB_ARPG_META_SCHEMA) {
        $errors[] = 'ARPG envelope: schema must be "' . AF_KB_ARPG_META_SCHEMA . '".';
        $payload['schema'] = AF_KB_ARPG_META_SCHEMA;
    }

    if ((string)($payload['mechanic'] ?? '') !== 'arpg') {
        $errors[] = 'ARPG envelope: mechanic must be "arpg".';
        $payload['mechanic'] = 'arpg';
    }

    if (!isset($payload['tags']) || !is_array($payload['tags'])) {
        $errors[] = 'ARPG envelope: tags must be an array.';
        $payload['tags'] = [];
    }
    if (!isset($payload['ui']) || !is_array($payload['ui'])) {
        $errors[] = 'ARPG envelope: ui must be an object.';
        $payload['ui'] = [];
    }
    foreach (['icon_class', 'icon_url', 'background_url', 'background_tab_url'] as $uiKey) {
        if (!isset($payload['ui'][$uiKey]) || !is_string($payload['ui'][$uiKey])) {
            $payload['ui'][$uiKey] = '';
        }
    }
    if (!isset($payload['blocks']) || !is_array($payload['blocks'])) {
        $errors[] = 'ARPG envelope: blocks must be an array.';
        $payload['blocks'] = [];
    }
    if (!isset($payload['rules']) || !is_array($payload['rules'])) {
        $errors[] = 'ARPG envelope: rules must be an object.';
        $payload['rules'] = [];
    }
    if ((string)($payload['rules']['schema'] ?? '') !== AF_KB_ARPG_RULES_SCHEMA) {
        $errors[] = 'ARPG envelope: rules.schema must be "' . AF_KB_ARPG_RULES_SCHEMA . '".';
        $payload['rules']['schema'] = AF_KB_ARPG_RULES_SCHEMA;
    }
    if (!isset($payload['rules']['version']) || trim((string)$payload['rules']['version']) === '') {
        $payload['rules']['version'] = '1.0';
    }

    return $payload;
}

function af_kb_validate_arpg_public_entity(string $entityKind, array $payload, array &$errors): void
{
    $requiredMap = [
        'origin' => ['rules.type_profile', 'rules.size', 'rules.creature_type', 'rules.base_hp', 'rules.base_damage', 'rules.base_defense', 'rules.movement_speed', 'rules.racial_bonuses_text', 'rules.racial_traits_text', 'rules.starting_notes'],
        'archetype' => ['rules.type_profile', 'rules.role', 'rules.damage_bias', 'rules.defense_bias', 'rules.resource_affinity', 'rules.base_damage_bonus', 'rules.base_defense_bonus', 'rules.slot_rules_text', 'rules.description_notes'],
        'faction' => ['rules.type_profile', 'rules.standing_model', 'rules.vendor_access_text', 'rules.story_flags_text', 'rules.description_text'],
        'lore' => ['rules.type_profile', 'rules.linked_entities_text', 'rules.timeline_text', 'rules.source_text'],
        'ability' => ['rules.type_profile', 'rules.type', 'rules.subtype', 'rules.slot', 'rules.damage_type', 'rules.targeting', 'rules.range', 'rules.cast_time', 'rules.cooldown', 'rules.duration', 'rules.max_charges', 'rules.level_cap', 'rules.resources', 'rules.effects', 'rules.modifiers', 'rules.triggers', 'rules.conditions', 'rules.stacking', 'rules.upgrade_requirements'],
        'talent' => ['rules.type_profile', 'rules.tree', 'rules.tier', 'rules.rank', 'rules.slot_type', 'rules.node_label', 'rules.rank_weight', 'rules.socket_cost', 'rules.effects', 'rules.passive_effects', 'rules.modifiers', 'rules.grants', 'rules.requirements', 'rules.mutual_exclusives'],
        'item' => ['rules.type_profile', 'rules.item_kind', 'rules.equip_slot', 'rules.rarity', 'rules.subtype', 'rules.level_min', 'rules.level_max', 'rules.progression_stage', 'rules.level_cap', 'rules.base_stats', 'rules.modifiers', 'rules.effects', 'rules.passive_effects', 'rules.triggers', 'rules.grants', 'rules.upgrade_steps'],
        'bestiary' => ['rules.type_profile', 'rules.family', 'rules.archetype', 'rules.faction', 'rules.rank', 'rules.threat_tier', 'rules.level', 'rules.combat_stats.hp', 'rules.combat_stats.atk', 'rules.combat_stats.def', 'rules.combat_stats.armor', 'rules.combat_stats.crit_rate', 'rules.combat_stats.crit_dmg', 'rules.combat_stats.status_hit', 'rules.combat_stats.status_resist', 'rules.resists', 'rules.weaknesses', 'rules.ability_keys', 'rules.loot'],
    ];

    foreach ((array)($requiredMap[$entityKind] ?? []) as $requiredPath) {
        af_kb_arpg_require_path($payload, (string)$requiredPath, 'ARPG ' . $entityKind . ' requires "' . $requiredPath . '".', $errors);
    }

    if ((string)($payload['rules']['type_profile'] ?? '') !== $entityKind) {
        $errors[] = 'ARPG ' . $entityKind . ': rules.type_profile must be "' . $entityKind . '".';
    }

    if ($entityKind === 'ability') {
        foreach (['resources', 'effects', 'modifiers', 'triggers', 'conditions', 'stacking', 'upgrade_requirements'] as $arrKey) {
            if (!is_array($payload['rules'][$arrKey] ?? null)) {
                $errors[] = 'ARPG ability: "rules.' . $arrKey . '" must be an array.';
            }
        }
        foreach ((array)($payload['rules']['triggers'] ?? []) as $idx => $trigger) {
            if (!is_array($trigger)) {
                $errors[] = 'ARPG ability: trigger #' . ($idx + 1) . ' must be an object.';
                continue;
            }
            $templateRef = trim((string)($trigger['template_ref'] ?? ''));
            if ($templateRef !== '' && !af_kb_arpg_validate_ref_string($templateRef)) {
                $errors[] = 'ARPG ability: trigger #' . ($idx + 1) . ' has invalid template_ref.';
            }
        }
        foreach ((array)($payload['rules']['conditions'] ?? []) as $idx => $condition) {
            if (!is_array($condition)) {
                $errors[] = 'ARPG ability: condition #' . ($idx + 1) . ' must be an object.';
                continue;
            }
            $templateRef = trim((string)($condition['template_ref'] ?? ''));
            if ($templateRef !== '' && !af_kb_arpg_validate_ref_string($templateRef)) {
                $errors[] = 'ARPG ability: condition #' . ($idx + 1) . ' has invalid template_ref.';
            }
        }
    }

    if ($entityKind === 'item') {
        $equipableKinds = ['weapon', 'armor', 'accessory', 'artifact'];
        if (in_array((string)($payload['rules']['item_kind'] ?? ''), $equipableKinds, true) && trim((string)($payload['rules']['equip_slot'] ?? '')) === '') {
            $errors[] = 'ARPG item requires rules.equip_slot for equipable item_kind.';
        }
    }

    if ($entityKind === 'talent') {
        foreach (['effects', 'passive_effects', 'modifiers', 'grants', 'requirements', 'mutual_exclusives'] as $requiredArrKey) {
            if (!is_array($payload['rules'][$requiredArrKey] ?? null)) {
                $errors[] = 'ARPG talent requires "rules.' . $requiredArrKey . '" array.';
            }
        }
    }

    if ($entityKind === 'bestiary') {
        foreach (['resists', 'weaknesses', 'ability_keys', 'loot'] as $requiredArrKey) {
            if (!is_array($payload['rules'][$requiredArrKey] ?? null)) {
                $errors[] = 'ARPG bestiary requires "rules.' . $requiredArrKey . '" array.';
            }
        }

        foreach (['family', 'archetype', 'faction', 'rank'] as $requiredTextKey) {
            if (trim((string)($payload['rules'][$requiredTextKey] ?? '')) === '') {
                $errors[] = 'ARPG bestiary requires non-empty "rules.' . $requiredTextKey . '".';
            }
        }

        foreach (['threat_tier', 'level'] as $requiredNumberKey) {
            if (!is_numeric($payload['rules'][$requiredNumberKey] ?? null)) {
                $errors[] = 'ARPG bestiary requires numeric "rules.' . $requiredNumberKey . '".';
            }
        }

        $combatStats = (array)($payload['rules']['combat_stats'] ?? []);
        foreach (['hp', 'atk', 'def', 'armor', 'crit_rate', 'crit_dmg', 'status_hit', 'status_resist'] as $statKey) {
            if (!is_numeric($combatStats[$statKey] ?? null)) {
                $errors[] = 'ARPG bestiary requires numeric "rules.combat_stats.' . $statKey . '".';
            }
        }

        foreach (['resists', 'weaknesses'] as $rowSetKey) {
            foreach ((array)($payload['rules'][$rowSetKey] ?? []) as $idx => $row) {
                if (!is_array($row)) {
                    $errors[] = 'ARPG bestiary "' . $rowSetKey . '" row #' . ($idx + 1) . ' must be an object.';
                    continue;
                }
                if (trim((string)($row['damage_type'] ?? '')) === '') {
                    $errors[] = 'ARPG bestiary "' . $rowSetKey . '" row #' . ($idx + 1) . ' requires damage_type.';
                }
                if (!is_numeric($row['value'] ?? null)) {
                    $errors[] = 'ARPG bestiary "' . $rowSetKey . '" row #' . ($idx + 1) . ' requires numeric value.';
                }
            }
        }

        foreach ((array)($payload['rules']['ability_keys'] ?? []) as $idx => $row) {
            if (!is_array($row)) {
                $errors[] = 'ARPG bestiary "ability_keys" row #' . ($idx + 1) . ' must be an object.';
                continue;
            }
            if (trim((string)($row['ability_key'] ?? '')) === '') {
                $errors[] = 'ARPG bestiary "ability_keys" row #' . ($idx + 1) . ' requires ability_key.';
            }
        }

        $allowedLootKinds = ['item', 'currency', 'material', 'reward', 'custom'];
        foreach ((array)($payload['rules']['loot'] ?? []) as $idx => $row) {
            if (!is_array($row)) {
                $errors[] = 'ARPG bestiary "loot" row #' . ($idx + 1) . ' must be an object.';
                continue;
            }
            if (trim((string)($row['loot_key'] ?? '')) === '') {
                $errors[] = 'ARPG bestiary "loot" row #' . ($idx + 1) . ' requires loot_key.';
            }
            $kind = trim((string)($row['kind'] ?? ''));
            if ($kind === '' || !in_array($kind, $allowedLootKinds, true)) {
                $errors[] = 'ARPG bestiary "loot" row #' . ($idx + 1) . ' has invalid kind.';
            }
            foreach (['qty_min', 'qty_max', 'chance'] as $numKey) {
                if (!is_numeric($row[$numKey] ?? null)) {
                    $errors[] = 'ARPG bestiary "loot" row #' . ($idx + 1) . ' requires numeric ' . $numKey . '.';
                }
            }
            if (is_numeric($row['qty_min'] ?? null) && is_numeric($row['qty_max'] ?? null) && (float)$row['qty_min'] > (float)$row['qty_max']) {
                $errors[] = 'ARPG bestiary "loot" row #' . ($idx + 1) . ' requires qty_min <= qty_max.';
            }
        }
    }
}

function af_kb_validate_arpg_service_entity(string $entityKind, array $payload, array &$errors): void
{
    if ((string)($payload['rules']['type_profile'] ?? '') !== 'service_mechanics') {
        $errors[] = 'ARPG service entry requires rules.type_profile="service_mechanics".';
    }
    if ((string)($payload['rules']['category'] ?? '') !== 'service.mechanics') {
        $errors[] = 'ARPG service entry requires rules.category="service.mechanics".';
    }
    $visibility = (array)($payload['rules']['visibility'] ?? []);
    if (!empty($visibility['catalog']) || !empty($visibility['search']) || empty($visibility['internal'])) {
        $errors[] = 'ARPG service entry requires rules.visibility={catalog:false,search:false,internal:true}.';
    }
    if (!in_array($entityKind, af_kb_arpg_service_entity_kinds(), true)) {
        $errors[] = 'ARPG service entry has unsupported rules.service_kind "' . $entityKind . '".';
    }
    if (!is_array($payload['rules']['entries'] ?? null)) {
        $errors[] = 'ARPG service entry requires rules.entries[] array.';
    }
    if ($entityKind === 'mechanic_profile') {
        foreach (['stats_registry', 'damage_type_registry', 'targeting_registry', 'resource_ops_registry', 'modifier_modes_registry', 'talent_rank_registry', 'item_rarity_registry', 'bestiary_rank_registry'] as $registryKey) {
            if (!is_array($payload['rules'][$registryKey] ?? null)) {
                $errors[] = 'ARPG mechanic_profile requires rules.' . $registryKey . ' array.';
            }
        }
    }
}

function af_kb_validate_arpg_entry_by_type(string $type, array $payload, array &$errors): array
{
    $typeDef = af_kb_arpg_type_definition($type);
    if (empty($typeDef)) {
        return $payload;
    }

    $isService = !empty($typeDef['service']);
    $payload = af_kb_validate_arpg_envelope($type, $payload, $isService, $errors);

    if ($isService) {
        $payload['rules']['type_profile'] = 'service_mechanics';
        $serviceKind = (string)($payload['rules']['service_kind'] ?? '');
        af_kb_validate_arpg_service_entity($serviceKind, $payload, $errors);
    } else {
        $entityKind = (string)($typeDef['entity_kind'] ?? '');
        $payload['rules']['type_profile'] = $entityKind;
        af_kb_validate_arpg_public_entity($entityKind, $payload, $errors);
    }

    return $payload;
}

function af_kb_validate_rules_json_by_type(string $type, string $normalizedJson, array &$errors, ?string $mechanicKey = null): string
{
    $mechanic = af_kb_get_mechanic_profile($mechanicKey ?? af_kb_get_type_mechanic_key($type));
    $resolver = $mechanic['providers']['validator'] ?? 'af_kb_validate_rules_json_by_type_dnd';
    if (!is_string($resolver) || !function_exists($resolver)) {
        $resolver = 'af_kb_validate_rules_json_by_type_dnd';
    }

    return (string)$resolver($type, $normalizedJson, $errors);
}

function af_kb_item_root_fields(): array
{
    return ['item_kind', 'rarity', 'price', 'currency', 'weight', 'stack_max', 'slot', 'equip', 'weapon', 'ammo', 'gear', 'bonuses', 'passive_bonuses', 'augmentation', 'cyberware', 'tags', 'on_use', 'on_equip', 'requirements'];
}

function af_kb_item_bonus_allowed_types(): array
{
    return [
        'hp',
        'hp_max',
        'armor',
        'damage',
        'initiative',
        'speed',
        'carry',
        'ep',
        'attribute_points',
        'skill_points',
        'knowledge_slots',
        'language_slots',
        'str', 'dex', 'con', 'int', 'wis', 'cha',
    ];
}

function af_kb_normalize_item_bonus_rows($rows): array
{
    if (!is_array($rows)) {
        return [];
    }

    $allowedTypes = array_flip(af_kb_item_bonus_allowed_types());
    $normalized = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $type = trim((string)($row['type'] ?? $row['target'] ?? $row['stat'] ?? ''));
        if ($type === '') {
            continue;
        }
        if (isset($allowedTypes[$type])) {
            $target = $type;
            $type = 'resource';
        } else {
            $target = trim((string)($row['target'] ?? $row['stat'] ?? $row['attribute'] ?? $row['key'] ?? ''));
        }

        $value = isset($row['value']) ? (float)$row['value'] : (isset($row['amount']) ? (float)$row['amount'] : 0.0);
        if ($value == 0.0 && empty($row['notes']) && empty($row['conditions'])) {
            continue;
        }

        $normalized[] = [
            'type' => $type,
            'target' => $target,
            'mode' => trim((string)($row['mode'] ?? 'add')) ?: 'add',
            'value' => $value,
            'unit' => trim((string)($row['unit'] ?? '')),
            'conditions' => trim((string)($row['conditions'] ?? $row['condition'] ?? '')),
            'notes' => trim((string)($row['notes'] ?? '')),
        ];
    }

    return array_values($normalized);
}

function af_kb_normalize_item_rules_payload(array $payload): array
{
    $rootFields = af_kb_item_root_fields();
    $hasItem = isset($payload['item']) && is_array($payload['item']);

    $legacyRootSlot = trim((string)($payload['slot'] ?? ''));

    if (!$hasItem) {
        $item = [];
        foreach ($rootFields as $field) {
            if (array_key_exists($field, $payload)) {
                $item[$field] = $payload[$field];
            }
        }
        if ($item !== []) {
            $payload['item'] = $item;
        }
    }

    foreach ($rootFields as $field) {
        unset($payload[$field]);
    }

    if (!isset($payload['item']) || !is_array($payload['item'])) {
        $payload['item'] = [];
    }

    if (!isset($payload['item']['equip']) || !is_array($payload['item']['equip'])) {
        $payload['item']['equip'] = [];
    }

    $legacyItemSlot = trim((string)($payload['item']['slot'] ?? ''));
    if ($legacyRootSlot !== '' && trim((string)($payload['item']['equip']['slot'] ?? '')) === '') {
        $payload['item']['equip']['slot'] = $legacyRootSlot;
    }
    if ($legacyItemSlot !== '' && trim((string)($payload['item']['equip']['slot'] ?? '')) === '') {
        $payload['item']['equip']['slot'] = $legacyItemSlot;
    }

    unset($payload['item']['slot']);

    $item = (array)$payload['item'];
    $bonusSource = [];
    if (isset($item['bonuses']) && is_array($item['bonuses'])) {
        $bonusSource = $item['bonuses'];
    } elseif (isset($item['passive_bonuses']) && is_array($item['passive_bonuses'])) {
        $bonusSource = $item['passive_bonuses'];
    }

    $payload['item']['bonuses'] = af_kb_normalize_item_bonus_rows($bonusSource);
    unset($payload['item']['passive_bonuses']);

    return $payload;
}

function af_kb_item_augmentation_slots_allowed(): array
{
    return [
        'nervous_system',
        'circulatory_system',
        'immune_system',
        'integumentary_system',
        'operating_system',
        'skeleton',
        'arms',
        'hands',
        'legs',
        'eyes',
        'frontal_cortex',
        'cyberaudio',
    ];
}

function af_kb_prepare_item_payload_for_save(array $metaPayload, string $fallbackItemKind = ''): array
{
    $rules = $metaPayload['rules'] ?? [];
    if (!is_array($rules)) {
        $rules = [];
    }

    $rules = af_kb_normalize_item_rules_payload($rules);

    if (!isset($rules['item']) || !is_array($rules['item'])) {
        $rules['item'] = [];
    }

    $item = $rules['item'];

    $rulesItemKind = af_kb_normalize_item_kind((string)($item['item_kind'] ?? ''));
    $metaItemKind = af_kb_normalize_item_kind((string)($metaPayload['item_kind'] ?? ''));
    $fallbackItemKind = af_kb_normalize_item_kind($fallbackItemKind);

    $itemKind = $rulesItemKind !== ''
        ? $rulesItemKind
        : ($metaItemKind !== ''
            ? $metaItemKind
            : ($fallbackItemKind !== '' ? $fallbackItemKind : 'gear'));

    $item['item_kind'] = $itemKind;

    if (!isset($item['equip']) || !is_array($item['equip'])) {
        $item['equip'] = [];
    }
    if (!isset($item['augmentation']) || !is_array($item['augmentation'])) {
        $item['augmentation'] = [];
    }
    if (!isset($item['cyberware']) || !is_array($item['cyberware'])) {
        $item['cyberware'] = [];
    }

    $equipSlot = strtolower(trim((string)($item['equip']['slot'] ?? '')));
    if (strpos($equipSlot, 'consumable_') === 0) {
        $equipSlot = str_replace('consumable_', 'support_', $equipSlot);
    }

    $augmentationSlotsAllowed = af_kb_item_augmentation_slots_allowed();

    $augmentationSlot = trim((string)($item['augmentation']['slot'] ?? ''));
    $cyberwareSlot = trim((string)($item['cyberware']['slot'] ?? ''));

    if ($augmentationSlot === '' && $cyberwareSlot !== '') {
        $augmentationSlot = $cyberwareSlot;
    }
    if ($cyberwareSlot === '' && $augmentationSlot !== '') {
        $cyberwareSlot = $augmentationSlot;
    }

    $effectiveKind = $itemKind;
    if ($itemKind === 'unique') {
        $uniqueRole = af_kb_normalize_item_kind((string)($item['unique_role'] ?? $item['unique_base_kind'] ?? ''));
        if ($uniqueRole !== '') {
            $effectiveKind = $uniqueRole;
        }
    }

    if (
        $effectiveKind === 'augmentation'
        && $augmentationSlot === ''
        && $equipSlot !== ''
        && in_array($equipSlot, $augmentationSlotsAllowed, true)
    ) {
        $augmentationSlot = $equipSlot;
        $cyberwareSlot = $equipSlot;
        $equipSlot = '';
    }

    $item['equip']['slot'] = $equipSlot;
    $item['augmentation']['slot'] = $augmentationSlot;
    $item['cyberware']['slot'] = $cyberwareSlot;

    $rules['item'] = $item;
    $metaPayload['item_kind'] = $itemKind;
    $metaPayload['rules'] = $rules;

    return [
        'meta' => $metaPayload,
        'rules' => $rules,
        'item_kind' => $itemKind,
    ];
}

function af_kb_validate_item_slot_requirements(array $rulesData, array &$errors): array
{
    $rulesData = af_kb_normalize_item_rules_payload($rulesData);

    $item = is_array($rulesData['item'] ?? null) ? (array)$rulesData['item'] : [];
    $kind = af_kb_normalize_item_kind((string)($item['item_kind'] ?? ''));
    if ($kind === '') {
        $kind = 'gear';
    }
    $item['item_kind'] = $kind;

    $uniqueRole = af_kb_normalize_item_kind((string)($item['unique_role'] ?? $item['unique_base_kind'] ?? ''));
    $effectiveKind = $kind;
    if ($kind === 'unique' && $uniqueRole !== '') {
        $effectiveKind = $uniqueRole;
    }

    if (!isset($item['equip']) || !is_array($item['equip'])) {
        $item['equip'] = [];
    }
    if (!isset($item['augmentation']) || !is_array($item['augmentation'])) {
        $item['augmentation'] = [];
    }
    if (!isset($item['cyberware']) || !is_array($item['cyberware'])) {
        $item['cyberware'] = [];
    }

    $equipSlot = strtolower(trim((string)($item['equip']['slot'] ?? '')));
    if (strpos($equipSlot, 'consumable_') === 0) {
        $equipSlot = str_replace('consumable_', 'support_', $equipSlot);
    }

    $augmentationSlotsAllowed = af_kb_item_augmentation_slots_allowed();

    $slotByKind = [
        'armor' => ['head', 'body', 'hands', 'legs', 'feet', 'back', 'belt'],
        'weapon' => ['weapon_mainhand', 'weapon_offhand', 'weapon_twohand', 'weapon_ranged', 'weapon_melee'],
        'consumable' => ['', 'support_1', 'support_2', 'support_3', 'support_4'],
        'ammo' => ['ammo', 'ammo_pouch'],
        'gear' => ['', 'gear', 'accessory'],
        'artifact' => ['', 'artifact', 'accessory'],
        'unique' => ['', 'weapon_mainhand', 'weapon_offhand', 'weapon_twohand', 'weapon_ranged', 'weapon_melee', 'head', 'body', 'hands', 'legs', 'feet', 'back', 'belt', 'support_1', 'support_2', 'support_3', 'support_4', 'ammo', 'ammo_pouch', 'gear', 'artifact', 'accessory'],
    ];

    $augmentationSlot = strtolower(trim((string)($item['augmentation']['slot'] ?? '')));
    $cyberwareSlot = strtolower(trim((string)($item['cyberware']['slot'] ?? '')));

    if ($augmentationSlot === '' && $cyberwareSlot !== '') {
        $augmentationSlot = $cyberwareSlot;
    }
    if ($cyberwareSlot === '' && $augmentationSlot !== '') {
        $cyberwareSlot = $augmentationSlot;
    }

    if (
        $effectiveKind === 'augmentation'
        && $augmentationSlot === ''
        && $equipSlot !== ''
        && in_array($equipSlot, $augmentationSlotsAllowed, true)
    ) {
        $augmentationSlot = $equipSlot;
        $cyberwareSlot = $equipSlot;
    }

    if ($effectiveKind === 'augmentation') {
        if ($augmentationSlot !== '' && !in_array($augmentationSlot, $augmentationSlotsAllowed, true)) {
            $errors[] = 'item.augmentation.slot: incompatible with augmentation';
        }

        // Для аугментаций используем только dedicated-slot, а не equip.slot
        $equipSlot = '';
    } elseif ($kind === 'unique' && $uniqueRole === '') {
        $errors[] = 'item.unique_role: required for unique';
    } else {
        $allowed = $slotByKind[$effectiveKind] ?? [''];
        if ($equipSlot !== '' && !in_array($equipSlot, $allowed, true)) {
            $errors[] = 'item.equip.slot: incompatible with item_kind=' . $effectiveKind;
        }
        if (in_array($effectiveKind, ['armor', 'weapon'], true) && $equipSlot === '') {
            $errors[] = 'item.equip.slot: required for armor/weapon';
        }
    }

    $item['equip']['slot'] = $equipSlot;
    $item['augmentation']['slot'] = $augmentationSlot;
    $item['cyberware']['slot'] = $cyberwareSlot;

    $rulesData['item'] = $item;

    return $rulesData;
}

function af_kb_normalize_item_entries_bulk(): array
{
    global $db;

    $updated = 0;
    $seen = 0;

    $q = $db->simple_select('af_kb_entries', 'id, type', "type='item'");
    while ($entry = $db->fetch_array($q)) {
        $entryId = (int)($entry['id'] ?? 0);
        if ($entryId <= 0) {
            continue;
        }
        $seen++;

        $dataRow = $db->fetch_array($db->simple_select('af_kb_blocks', 'id, data_json', "entry_id={$entryId} AND block_key='data'", ['limit' => 1]));
        if (!$dataRow) {
            continue;
        }

        $raw = (string)($dataRow['data_json'] ?? '{}');
        $errors = [];
        $normalized = af_kb_validate_rules_json_by_type('item', af_kb_normalize_json($raw), $errors);
        $normalized = af_kb_normalize_json($normalized);
        if ($normalized === af_kb_normalize_json($raw)) {
            continue;
        }

        $db->update_query('af_kb_blocks', ['data_json' => $db->escape_string($normalized)], 'id=' . (int)$dataRow['id']);
        $updated++;
    }

    return ['seen' => $seen, 'updated' => $updated];
}

function af_kb_normalize_skill_payload(array $skillData): array
{
    $allowedStats = ['str', 'dex', 'con', 'int', 'wis', 'cha'];

    $keyStat = strtolower(trim((string)($skillData['key_stat'] ?? '')));
    $attribute = strtolower(trim((string)($skillData['attribute'] ?? '')));

    $canonical = '';
    if (in_array($keyStat, $allowedStats, true)) {
        $canonical = $keyStat;
    } elseif (in_array($attribute, $allowedStats, true)) {
        $canonical = $attribute;
    }

    if ($canonical !== '') {
        $skillData['key_stat'] = $canonical;
        $skillData['attribute'] = $canonical;
    } else {
        unset($skillData['key_stat'], $skillData['attribute']);
    }

    return $skillData;
}

function af_kb_validate_key_token(string $value): bool
{
    return (bool)preg_match('/^[a-z0-9_]+$/', $value);
}

function af_kb_trimmed_string($value, int $maxLen = 0): string
{
    $text = trim((string)$value);
    if ($maxLen > 0 && function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLen);
    }
    if ($maxLen > 0) {
        return substr($text, 0, $maxLen);
    }
    return $text;
}

function af_kb_normalize_grant_meta_fields(array $grant): array
{
    $meta = [];

    $titleRu = af_kb_trimmed_string($grant['title_ru'] ?? '', 500);
    $titleEn = af_kb_trimmed_string($grant['title_en'] ?? '', 500);
    $descRu = af_kb_trimmed_string($grant['desc_ru'] ?? '', 2000);
    $descEn = af_kb_trimmed_string($grant['desc_en'] ?? '', 2000);
    $unit = af_kb_trimmed_string($grant['unit'] ?? '', 16);
    $format = af_kb_trimmed_string($grant['format'] ?? '', 64);

    if ($titleRu !== '') {
        $meta['title_ru'] = $titleRu;
    }
    if ($titleEn !== '') {
        $meta['title_en'] = $titleEn;
    }
    if ($descRu !== '') {
        $meta['desc_ru'] = $descRu;
    }
    if ($descEn !== '') {
        $meta['desc_en'] = $descEn;
    }
    if ($unit !== '') {
        $meta['unit'] = $unit;
    }
    if ($format !== '') {
        $meta['format'] = $format;
    }

    return $meta;
}

function af_kb_normalize_traits_json($rawTraits, array &$errors): array
{
    $traits = $rawTraits;
    if (is_array($traits) && array_key_exists('schema', $traits)) {
        if ((string)($traits['schema'] ?? '') !== AF_KB_TRAITS_SCHEMA) {
            $errors[] = 'Traits JSON schema mismatch.';
        }
        $traits = $traits['traits'] ?? [];
    }
    if (!is_array($traits)) {
        $errors[] = 'Traits JSON must contain an array of traits.';
        return [];
    }

    $normalized = [];
    foreach ($traits as $index => $trait) {
        if (!is_array($trait)) {
            $errors[] = 'Trait #' . ($index + 1) . ' must be an object.';
            continue;
        }
        $key = trim((string)($trait['key'] ?? ''));
        if ($key !== '' && !af_kb_validate_key_token($key)) {
            $errors[] = 'Trait key must use [a-z0-9_].';
        }
        $titleRu = trim((string)($trait['title_ru'] ?? ''));
        $titleEn = trim((string)($trait['title_en'] ?? ''));
        if ($titleRu === '' && $titleEn === '') {
            $errors[] = 'Trait #' . ($index + 1) . ' requires title_ru or title_en.';
        }
        $normalized[] = [
            'key' => $key,
            'title_ru' => $titleRu,
            'title_en' => $titleEn,
            'desc_ru' => trim((string)($trait['desc_ru'] ?? '')),
            'desc_en' => trim((string)($trait['desc_en'] ?? '')),
            'tags' => isset($trait['tags']) && is_array($trait['tags']) ? array_values($trait['tags']) : [],
            'meta' => isset($trait['meta']) && is_array($trait['meta']) ? $trait['meta'] : [],
        ];
    }

    return $normalized;
}

function af_kb_normalize_grants_json($rawGrants, array &$errors): array
{
    $grants = $rawGrants;
    if (is_array($grants) && array_key_exists('schema', $grants)) {
        if ((string)($grants['schema'] ?? '') !== AF_KB_GRANTS_SCHEMA) {
            $errors[] = 'Grants JSON schema mismatch.';
        }
        $grants = $grants['grants'] ?? [];
    }
    if (!is_array($grants)) {
        $errors[] = 'Grants JSON must contain an array of grants.';
        return [];
    }

    $allowedOps = ['resource', 'skill', 'language', 'knowledge', 'item', 'resistance', 'sense', 'speed'];
    $allowedResourceKeys = ['hp', 'ep', 'skill_points', 'feat_points', 'perk_points', 'language_slots', 'knowledge_slots'];
    $normalized = [];
    foreach ($grants as $index => $grant) {
        if (!is_array($grant)) {
            $errors[] = 'Grant #' . ($index + 1) . ' must be an object.';
            continue;
        }
        $op = trim((string)($grant['op'] ?? ''));
        $legacyType = trim((string)($grant['type'] ?? ''));
        if ($op === '' && $legacyType !== '') {
            if ($legacyType === 'resource_gain') {
                $op = 'resource';
            } elseif ($legacyType === 'skill_rank') {
                $op = 'skill';
            } elseif ($legacyType === 'item_grant') {
                $op = 'item';
            } elseif ($legacyType === 'resistance_grant') {
                $op = 'resistance';
            } elseif ($legacyType === 'sense_grant') {
                $op = 'sense';
            } elseif ($legacyType === 'speed_grant') {
                $op = 'speed';
            } elseif ($legacyType === 'kb_grant') {
                $kbType = trim((string)($grant['kb_type'] ?? ''));
                if (in_array($kbType, ['skill', 'language', 'knowledge', 'item'], true)) {
                    $op = $kbType;
                }
            }
        }

        if (!in_array($op, $allowedOps, true)) {
            $errors[] = 'Grant #' . ($index + 1) . ' has unsupported op.';
            continue;
        }

        if ($op === 'resource') {
            $resourceKey = trim((string)($grant['key'] ?? $grant['resource'] ?? ''));
            if (!in_array($resourceKey, $allowedResourceKeys, true)) {
                $errors[] = 'Grant #' . ($index + 1) . ' resource requires valid key.';
                continue;
            }
            $mode = trim((string)($grant['mode'] ?? $grant['stack_mode'] ?? 'add'));
            if (!in_array($mode, ['add', 'set'], true)) {
                $mode = 'add';
            }
            $normalized[] = [
                'op' => 'resource',
                'key' => $resourceKey,
                'value' => (int)($grant['value'] ?? $grant['amount'] ?? 0),
                'mode' => $mode,
            ];
            continue;
        }

        if ($op === 'skill') {
            $skillKey = trim((string)($grant['key'] ?? $grant['skill_key'] ?? $grant['kb_key'] ?? ''));
            if ($skillKey === '') {
                $errors[] = 'Grant #' . ($index + 1) . ' skill requires key.';
                continue;
            }
            $rankRaw = $grant['rank'] ?? $grant['skill_rank'] ?? $grant['value'] ?? 0;
            $rank = is_numeric($rankRaw) ? (int)$rankRaw : 0;
            $rank = max(0, min(4, $rank));
            $normalizedGrant = ['op' => 'skill', 'key' => $skillKey, 'rank' => $rank];
            if (isset($grant['rank_max']) && is_numeric($grant['rank_max'])) {
                $normalizedGrant['rank_max'] = max(0, (int)$grant['rank_max']);
            }
            $normalized[] = $normalizedGrant;
            continue;
        }


        if ($op === 'language') {
            $languageKey = trim((string)($grant['key'] ?? $grant['language_key'] ?? $grant['kb_key'] ?? ''));
            if ($languageKey === '') {
                $errors[] = 'Grant #' . ($index + 1) . ' language requires key.';
                continue;
            }
            $normalized[] = ['op' => 'language', 'key' => $languageKey];
            continue;
        }

        if ($op === 'knowledge') {
            $knowledgeKey = trim((string)($grant['key'] ?? $grant['knowledge_key'] ?? $grant['kb_key'] ?? ''));
            if ($knowledgeKey === '') {
                $errors[] = 'Grant #' . ($index + 1) . ' knowledge requires key.';
                continue;
            }
            $normalized[] = ['op' => 'knowledge', 'key' => $knowledgeKey];
            continue;
        }
        if ($op === 'item') {
            $itemKey = trim((string)($grant['key'] ?? $grant['kb_key'] ?? ''));
            if ($itemKey === '') {
                $errors[] = 'Grant #' . ($index + 1) . ' item requires key.';
                continue;
            }
            $normalized[] = [
                'op' => 'item',
                'key' => $itemKey,
                'amount' => max(1, (int)($grant['amount'] ?? $grant['qty'] ?? 1)),
            ];
            continue;
        }

        if ($op === 'resistance') {
            $resistanceKey = trim((string)($grant['key'] ?? $grant['damage_type'] ?? ''));
            if ($resistanceKey === '') {
                $errors[] = 'Grant #' . ($index + 1) . ' resistance requires key.';
                continue;
            }
            $normalizedGrant = [
                'op' => 'resistance',
                'key' => $resistanceKey,
                'value' => (int)($grant['value'] ?? 0),
            ];
            $normalizedGrant = array_merge($normalizedGrant, af_kb_normalize_grant_meta_fields($grant));
            $normalized[] = $normalizedGrant;
            continue;
        }

        if ($op === 'sense') {
            $senseKey = trim((string)($grant['key'] ?? $grant['sense_type'] ?? ''));
            if ($senseKey === '') {
                $errors[] = 'Grant #' . ($index + 1) . ' sense requires key.';
                continue;
            }
            $normalizedGrant = [
                'op' => 'sense',
                'key' => $senseKey,
                'value' => (int)($grant['value'] ?? $grant['range'] ?? 0),
            ];
            $normalizedGrant = array_merge($normalizedGrant, af_kb_normalize_grant_meta_fields($grant));
            $normalized[] = $normalizedGrant;
            continue;
        }

        if ($op === 'speed') {
            $speedKind = trim((string)($grant['kind'] ?? $grant['speed_type'] ?? ''));
            if ($speedKind === '') {
                $errors[] = 'Grant #' . ($index + 1) . ' speed requires kind.';
                continue;
            }
            $mode = trim((string)($grant['mode'] ?? 'set'));
            if (!in_array($mode, ['set', 'add', 'max'], true)) {
                $mode = 'set';
            }
            $normalized[] = [
                'op' => 'speed',
                'kind' => $speedKind,
                'value' => (int)($grant['value'] ?? 0),
                'mode' => $mode,
            ];
        }
    }

    return $normalized;
}

function af_kb_get_entry_data_json(int $entryId): string
{
    global $db;

    if ($entryId <= 0) {
        return '{}';
    }

    $entry = $db->fetch_array($db->simple_select('af_kb_entries', '*', 'id=' . $entryId, ['limit' => 1]));
    if (!$entry) {
        return '{}';
    }

    $rules = af_kb_get_rules_by_entry_id($entryId);
    if (!is_array($rules) || empty($rules)) {
        return '{}';
    }

    $json = json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return '{}';
    }

    return af_kb_normalize_rules_json($json);
}

function af_kb_get_entry_data_json_for_editor(array $entry): string
{
    $metaRules = af_kb_extract_rules_from_meta_json((string)($entry['meta_json'] ?? '{}'));
    if (is_array($metaRules)) {
        $json = json_encode($metaRules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            return af_kb_normalize_rules_json($json);
        }
    }

    $legacyCandidates = [];
    $legacyCandidates[] = trim((string)($entry['data_json'] ?? ''));

    $entryId = (int)($entry['id'] ?? 0);
    if ($entryId > 0) {
        $blocksMap = af_kb_migration_discover_block_columns();
        $found = af_kb_migration_find_rules_in_blocks($entryId, $blocksMap);
        $legacyCandidates[] = trim((string)($found['raw'] ?? ''));
    }

    foreach ($legacyCandidates as $candidate) {
        if ($candidate === '' || !af_kb_validate_json($candidate)) {
            continue;
        }
        $decoded = json_decode($candidate, true);
        if (!is_array($decoded)) {
            continue;
        }
        return af_kb_normalize_rules_json($candidate);
    }

    return '{}';
}

function af_kb_extract_rules_from_meta_json(string $metaJson): ?array
{
    $meta = af_kb_decode_json($metaJson);
    if (!is_array($meta)) {
        return null;
    }

    $rules = $meta['rules'] ?? null;
    if (!is_array($rules)) {
        return null;
    }

    if (!isset($rules['schema']) && !isset($rules['type_profile'])) {
        return null;
    }

    return $rules;
}

function af_kb_extract_rules_for_consumer($entry, string $consumer = 'generic'): array
{
    $consumer = strtolower(trim($consumer));
    if ($consumer === '') {
        $consumer = 'generic';
    }

    $result = [
        'consumer' => $consumer,
        'mechanic_key' => AF_KB_DEFAULT_MECHANIC_KEY,
        'schema' => '',
        'rules' => [],
        'supported' => false,
        'skip' => true,
        'status' => 'empty',
        'reason' => 'rules_not_found',
    ];

    if (!is_array($entry)) {
        $result['status'] = 'invalid_entry';
        $result['reason'] = 'entry_not_array';
        return $result;
    }

    $type = trim((string)($entry['type'] ?? ''));
    if ($type !== '' && function_exists('af_kb_get_type_mechanic_key')) {
        $result['mechanic_key'] = af_kb_get_type_mechanic_key($type);
    }

    $rules = af_kb_extract_rules_from_meta_json((string)($entry['meta_json'] ?? ''));
    if (!is_array($rules) || empty($rules)) {
        $payload = af_kb_decode_json((string)($entry['data_json'] ?? ''));
        if (is_array($payload) && is_array($payload['meta']['rules'] ?? null)) {
            $rules = (array)$payload['meta']['rules'];
        } else {
            $result['status'] = 'empty';
            $result['reason'] = 'rules_not_found';
            return $result;
        }
    }

    $schema = trim((string)($rules['schema'] ?? ''));
    if ($schema !== '') {
        $result['schema'] = $schema;
    }
    if (
        $result['mechanic_key'] === AF_KB_DEFAULT_MECHANIC_KEY
        && in_array($schema, [AF_KB_ARPG_RULES_SCHEMA, AF_KB_ARPG_META_SCHEMA, AF_KB_ARPG_MECHANICS_SCHEMA], true)
    ) {
        $result['mechanic_key'] = 'arpg';
    }

    if ($result['mechanic_key'] === 'dnd') {
        if ($schema !== '' && $schema !== AF_KB_RULES_SCHEMA) {
            $result['status'] = 'unsupported_schema';
            $result['reason'] = 'dnd_schema_mismatch';
            return $result;
        }

        $result['rules'] = $rules;
        $result['supported'] = true;
        $result['skip'] = false;
        $result['status'] = 'ok';
        $result['reason'] = '';
        return $result;
    }

    if ($result['mechanic_key'] === 'arpg') {
        $result['rules'] = $rules;
        $result['status'] = 'arpg_partial';
        $result['reason'] = 'arpg_partial_support';
        $result['supported'] = in_array($consumer, ['shop', 'inventory', 'generic'], true);
        $result['skip'] = !$result['supported'];
        return $result;
    }

    $result['status'] = 'unsupported_mechanic';
    $result['reason'] = 'unknown_mechanic';
    return $result;
}

function af_kb_get_normalized_item_profile($entry, string $consumer = 'generic'): array
{
    $extract = af_kb_extract_rules_for_consumer($entry, $consumer);
    $rules = is_array($extract['rules'] ?? null) ? (array)$extract['rules'] : [];
    $item = is_array($rules['item'] ?? null) ? (array)$rules['item'] : [];
    $equip = is_array($item['equip'] ?? null) ? (array)$item['equip'] : [];
    $weapon = is_array($item['weapon'] ?? null) ? (array)$item['weapon'] : [];
    $armor = is_array($equip['armor'] ?? null) ? (array)$equip['armor'] : [];
    $tags = $rules['tags'] ?? ($item['tags'] ?? []);
    if (!is_array($tags)) {
        $tags = [];
    }

    $fallbackKind = '';
    if (is_array($entry)) {
        $fallbackKind = trim((string)($entry['item_kind'] ?? $entry['kind'] ?? ''));
    }

    $profile = [
        'consumer' => $consumer,
        'mechanic_key' => (string)($extract['mechanic_key'] ?? AF_KB_DEFAULT_MECHANIC_KEY),
        'supported' => (bool)($extract['supported'] ?? false),
        'skip' => (bool)($extract['skip'] ?? true),
        'status' => (string)($extract['status'] ?? 'empty'),
        'reason' => (string)($extract['reason'] ?? 'rules_not_found'),
        'item_kind' => trim((string)($item['item_kind'] ?? $item['kind'] ?? $fallbackKind)),
        'slot' => trim((string)($item['slot'] ?? '')),
        'equip_slot' => trim((string)($equip['slot'] ?? $item['slot'] ?? '')),
        'rarity' => trim((string)($item['rarity'] ?? '')),
        'stack_max' => max(1, (int)($item['stack_max'] ?? 1)),
        'price' => max(0, (int)($item['price'] ?? 0)),
        'currency' => trim((string)($item['currency'] ?? 'credits')),
        'weapon_damage_bonus' => (int)($weapon['damage_bonus'] ?? 0),
        'weapon_damage_type' => trim((string)($weapon['damage_type'] ?? '')),
        'armor_ac_bonus' => max(0, (int)($armor['ac_bonus'] ?? 0)),
        'tags' => $tags,
        'raw_rules' => $rules,
    ];

    if ($profile['mechanic_key'] === 'arpg') {
        $profile['supported'] = !empty($rules) || $profile['item_kind'] !== '';
        $profile['skip'] = !$profile['supported'];
        if ($profile['status'] === 'empty' && $profile['item_kind'] !== '') {
            $profile['status'] = 'fallback_meta';
            $profile['reason'] = 'item_kind_from_meta_only';
            $profile['supported'] = true;
            $profile['skip'] = false;
        }
    }

    return $profile;
}

function af_kb_get_normalized_ability_profile($entry, string $consumer = 'generic'): array
{
    $extract = af_kb_extract_rules_for_consumer($entry, $consumer);
    $rules = is_array($extract['rules'] ?? null) ? (array)$extract['rules'] : [];
    $spell = is_array($rules['spell'] ?? null) ? (array)$rules['spell'] : [];
    $ritual = is_array($rules['ritual'] ?? null) ? (array)$rules['ritual'] : [];
    $base = !empty($spell) ? $spell : $ritual;

    return [
        'consumer' => $consumer,
        'mechanic_key' => (string)($extract['mechanic_key'] ?? AF_KB_DEFAULT_MECHANIC_KEY),
        'supported' => (bool)($extract['supported'] ?? false),
        'skip' => (bool)($extract['skip'] ?? true),
        'status' => (string)($extract['status'] ?? 'empty'),
        'reason' => (string)($extract['reason'] ?? 'rules_not_found'),
        'ability_type' => trim((string)($base['type'] ?? (empty($spell) ? 'ritual' : 'spell'))),
        'cost' => $base['cost'] ?? '',
        'cooldown' => $base['cooldown'] ?? '',
        'resource' => $base['resource'] ?? '',
        'rank' => $base['rank'] ?? '',
        'school' => $base['school'] ?? '',
        'tradition' => $base['tradition'] ?? '',
        'effects' => is_array($rules['effects'] ?? null) ? (array)$rules['effects'] : [],
        'requirements' => is_array($rules['requirements'] ?? null) ? (array)$rules['requirements'] : [],
        'raw_rules' => $rules,
    ];
}

function af_kb_get_rules_by_entry_id(int $entryId): ?array
{
    global $db;

    if ($entryId <= 0 || !$db->table_exists('af_kb_entries')) {
        return null;
    }

    $entry = $db->fetch_array($db->simple_select('af_kb_entries', 'meta_json', 'id=' . $entryId, ['limit' => 1]));
    if (!is_array($entry)) {
        return null;
    }

    return af_kb_extract_rules_from_meta_json((string)($entry['meta_json'] ?? ''));
}

function af_kb_detect_entry_data_json(array $entry): array
{
    $default = ['source' => 'none', 'json' => '{}'];

    $meta = af_kb_decode_json((string)($entry['meta_json'] ?? '{}'));
    if (!empty($meta['rules']) && is_array($meta['rules'])) {
        $json = json_encode($meta['rules'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            return ['source' => 'entries.meta_json.rules', 'json' => $json];
        }
    }

    return $default;
}

function af_kb_render_data_table(string $json): string
{
    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return '';
    }

    $rows = '';
    foreach ($decoded as $key => $value) {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $rows .= '<tr><th>' . htmlspecialchars_uni((string)$key) . '</th><td>'
            . htmlspecialchars_uni((string)$value) . '</td></tr>';
    }

    if ($rows === '') {
        return '';
    }

    return '<table class="af-kb-data-table">' . $rows . '</table>';
}

function af_kb_render_tech_details(string $label, string $json, string $copyLabel = ''): string
{
    $json = trim($json);
    if ($json === '' || af_kb_is_empty_json($json)) {
        return '';
    }

    $table = af_kb_render_data_table($json);
    if ($table === '') {
        return '';
    }

    $copyButton = '';
    if ($copyLabel !== '') {
        $copyButton = '<button type="button" class="af-kb-copy-json" data-json="' . htmlspecialchars_uni($json) . '">'
            . htmlspecialchars_uni($copyLabel) . '</button>';
    }

    return '<details class="af-kb-tech"><summary>' . htmlspecialchars_uni($label) . '</summary>' . $copyButton . $table . '</details>';
}

function af_kb_get_entry_ui(array $entry): array
{
    $meta = af_kb_decode_json((string)($entry['meta_json'] ?? ''));
    $ui = [];
    if (!empty($meta['ui']) && is_array($meta['ui'])) {
        $ui = $meta['ui'];
    }

    return [
        'icon_class' => (string)($ui['icon_class'] ?? $entry['icon_class'] ?? ''),
        'icon_url' => (string)($ui['icon_url'] ?? $entry['icon_url'] ?? ''),
        'background_url' => (string)($ui['background_url'] ?? $entry['bg_url'] ?? ''),
        'background_tab_url' => (string)($meta['background_tab_url'] ?? $ui['background_tab_url'] ?? ''),
    ];
}

function af_kb_get_entry_summary(string $type, string $key): array
{
    static $cache = [];
    $cacheKey = $type . ':' . $key;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    global $db;

    $where = "type='".$db->escape_string($type)."' AND `key`='".$db->escape_string($key)."'";
    if (!af_kb_can_edit()) {
        $where .= " AND active=1";
    }

    $entry = $db->fetch_array($db->simple_select('af_kb_entries', '*', $where, ['limit' => 1]));
    if (!$entry) {
        $cache[$cacheKey] = [];
        return [];
    }

    $ui = af_kb_get_entry_ui($entry);
    $title = af_kb_pick_text($entry, 'title') ?: $entry['key'];
    $techHint = af_kb_build_tech_hint(af_kb_pick_text($entry, 'tech'));

    $cache[$cacheKey] = [
        'title' => $title,
        'icon_url' => $ui['icon_url'],
        'icon_class' => $ui['icon_class'],
        'tech_hint' => $techHint,
    ];

    return $cache[$cacheKey];
}

/* -------------------- ATF UTILITIES -------------------- */

function af_kb_parse_atf_options(string $rawOptions): array
{
    $result = [];
    $lines = preg_split('/\r\n|\r|\n/', $rawOptions);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (strpos($line, '=') === false) {
            $result[$line] = $line;
            continue;
        }
        [$key, $label] = array_map('trim', explode('=', $line, 2));
        if ($key === '') {
            continue;
        }
        $result[$key] = $label === '' ? $key : $label;
    }

    return $result;
}

function af_kb_resolve_atf_select_label(string $fieldOptions, string $rawValue): string
{
    $map = af_kb_parse_atf_options($fieldOptions);
    return $map[$rawValue] ?? $rawValue;
}

/* -------------------- RUNTIME -------------------- */

function af_knowledgebase_init(): void
{
    global $plugins;

    $plugins->add_hook('misc_start', 'af_kb_misc_route', 10);
    $plugins->add_hook('pre_output_page', 'af_knowledgebase_pre_output', 10);
    $plugins->add_hook('parse_message_end', 'af_kb_parse_message_end', 10);
}

function af_kb_build_sceditor_assets_and_init(string $bburl, string $pageHtml): array
{
    // Возвращает ['assets' => '...', 'init' => '...']
    $assets = '';
    $init = '';

    $root = defined('MYBB_ROOT') ? MYBB_ROOT : '';
    if ($root === '') {
        return ['assets' => '', 'init' => ''];
    }

    // Проверяем наличие SCEditor файлов в ФС
    $sceditorBaseFs = rtrim($root, '/\\') . '/jscripts/sceditor/';
    $hasCore =
        is_file($sceditorBaseFs . 'jquery.sceditor.min.js') &&
        is_file($sceditorBaseFs . 'jquery.sceditor.bbcode.min.js');

    // mybb-адаптер и тема - опционально, но желательно
    $hasMybb = is_file($sceditorBaseFs . 'jquery.sceditor.mybb.min.js');
    $hasTheme = is_file($sceditorBaseFs . 'themes/default.min.css');
    $hasContentCss = is_file($sceditorBaseFs . 'themes/content.min.css') || is_file($sceditorBaseFs . 'themes/content.css');

    if (!$hasCore) {
        // SCEditor отсутствует — не подключаем, чтобы не было 404/ошибок.
        return ['assets' => '', 'init' => ''];
    }

    // Фолбэк jQuery: только если на странице нет упоминаний jquery.* в HTML
    $hasJqInHtml = (stripos($pageHtml, 'jscripts/jquery') !== false)
        || (stripos($pageHtml, 'jquery.min.js') !== false)
        || (stripos($pageHtml, 'jquery.js') !== false);

    if (!$hasJqInHtml) {
        // На всякий пожарный — подгружаем стандартный jQuery MyBB.
        // (Если он уже есть в headerinclude — этот блок не добавится.)
        if (is_file(rtrim($root, '/\\') . '/jscripts/jquery.js')) {
            $assets .= '<script src="'.$bburl.'/jscripts/jquery.js"></script>';
        }
        if (is_file(rtrim($root, '/\\') . '/jscripts/jquery.plugins.min.js')) {
            $assets .= '<script src="'.$bburl.'/jscripts/jquery.plugins.min.js"></script>';
        }
    }

    if ($hasTheme) {
        $assets .= '<link rel="stylesheet" type="text/css" href="'.$bburl.'/jscripts/sceditor/themes/default.min.css" />';
    }

    $assets .= '<script src="'.$bburl.'/jscripts/sceditor/jquery.sceditor.min.js"></script>';
    $assets .= '<script src="'.$bburl.'/jscripts/sceditor/jquery.sceditor.bbcode.min.js"></script>';

    if ($hasMybb) {
        $assets .= '<script src="'.$bburl.'/jscripts/sceditor/jquery.sceditor.mybb.min.js"></script>';
    }

    // content css (если есть) — улучшает вид редактора
    $contentCssUrl = '';
    if (is_file($sceditorBaseFs . 'themes/content.min.css')) {
        $contentCssUrl = $bburl.'/jscripts/sceditor/themes/content.min.css';
    } elseif (is_file($sceditorBaseFs . 'themes/content.css')) {
        $contentCssUrl = $bburl.'/jscripts/sceditor/themes/content.css';
    }

    // Инициализацию SCEditor выполняет knowledgebase.js.
    // Здесь пробрасываем style в глобальные опции, чтобы редактор применял content.css.
    if ($contentCssUrl !== '') {
        $init = '<script>(function(){window.sceditor_options=window.sceditor_options||{};window.sceditor_options.style='
            . json_encode($contentCssUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ';})();</script>';
    }

    return ['assets' => $assets, 'init' => $init];
}

function af_knowledgebase_pre_output(string &$page = ''): void
{
    global $mybb, $lang;

    $action = $mybb->get_input('action');
    $thisScript = defined('THIS_SCRIPT') ? strtolower((string)THIS_SCRIPT) : '';
    $isKbScript = $thisScript === 'kb.php';
    if ($isKbScript && $action === '') {
        $action = 'kb';
    }
    $is_kb_page = in_array(
        $action,
        ['kb', 'kb_edit', 'kb_get', 'kb_list', 'kb_children', 'kb_type_edit', 'kb_type_delete', 'kb_help', 'kb_types'],
        true
    );

    $enabled = !empty($mybb->settings['af_knowledgebase_enabled']);
    $assetsDisabled = af_kb_assets_disabled_for_current_page();

    // Dedupe KB assets/markers regardless of source of injection.
    af_kb_strip_assets_from_html($page);

    if ($enabled && !$assetsDisabled) {
        $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
        if ($bburl !== '') {
            $assetsBase = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_KB_ID . '/assets';

            $cssTag = '';
            $jsTag = '';
            $editorAssets = '';
            $editorInit = '';
            $bodyBgCss = '';

            if ($is_kb_page) {
                // KB base css/js
                $cssTag .= af_kb_build_css_include_tag('assets/knowledgebase.css');
                $jsTag  .= '<script src="'.$assetsBase.'/knowledgebase.js?v='.af_kb_asset_version('knowledgebase.js').'"></script>';

                // ✅ ВАЖНО: фон для body — инжектим в конец <head>, с !important
                // и только для action=kb (витрина категории/записи)
                if ((string)$action === 'kb' && strpos($page, '<!--af_kb_body_bg-->') === false) {
                    $bgUrl = af_kb_resolve_body_bg_for_request();
                    $bodyBgCss = af_kb_build_body_bg_style($bgUrl);
                }
            }

            // SCEditor только на страницах редактирования KB
            if (in_array($action, ['kb_edit', 'kb_type_edit'], true)) {
                $bundle = af_kb_build_sceditor_assets_and_init($bburl, $page);
                $editorAssets = $bundle['assets'] ?? '';
                $editorInit   = $bundle['init'] ?? '';
            }

            af_knowledgebase_load_lang(false);
            $langPayload = json_encode([
                'kbInsertLabel'  => $lang->af_kb_kb_insert_label ?? 'KB',
                'kbInsertTitle'  => $lang->af_kb_kb_insert_title ?? 'Insert KB',
                'kbInsertSearch' => $lang->af_kb_kb_insert_search ?? 'Search...',
                'kbInsertSelect' => $lang->af_kb_kb_insert_select ?? 'Select category',
                'kbInsertEmpty'  => $lang->af_kb_kb_insert_empty ?? 'Nothing found',
                'kbInsertHint'   => $lang->af_kb_kb_insert_hint ?? 'Select category or continue search',
                'kbInsertButton' => $lang->af_kb_kb_insert_button ?? 'Insert',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $langTag = $langPayload !== false ? '<script>window.afKbLang='.$langPayload.';</script>' : '';
            $endpointTag = '<script>window.afKbEndpoints=' . json_encode([
                'get' => af_kb_url(['action' => 'kb_get']),
                'list' => af_kb_url(['action' => 'kb_list']),
                'types' => af_kb_url(['action' => 'kb_types']),
                'children' => af_kb_url(['action' => 'kb_children']),
                'json_list' => af_kb_url(['action' => 'kb_json_list']),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';

            $kbUiCss  = af_kb_build_css_include_tag('assets/knowledgebase_kbui.css');
            $chipsJs  = '<script src="'.$assetsBase.'/knowledgebase_chips.js?v='.af_kb_asset_version('knowledgebase_chips.js').'"></script>';
            $insertJs = '<script src="'.$assetsBase.'/knowledgebase_insert.js?v='.af_kb_asset_version('knowledgebase_insert.js').'"></script>';

            // ✅ bodyBgCss ставим ближе к концу head, чтобы перебить тему
            $inject = $cssTag
                . $kbUiCss
                . $editorAssets
                . $jsTag
                . $chipsJs
                . $insertJs
                . $langTag
                . $endpointTag
                . $editorInit
                . $bodyBgCss
                . AF_KB_MARK;

            if (stripos($page, '</head>') !== false) {
                $page = str_ireplace('</head>', $inject . '</head>', $page);
            } else {
                $page .= $inject;
            }
        }
    }

    // Навлинк (оставляю твою логику как есть)
    if ($enabled && (int)af_kb_get_setting('af_kb_nav_link_enabled', 1) === 1 && af_kb_can_view()) {
        if (strpos($page, '<!--af_kb_nav-->') === false) {
            af_knowledgebase_load_lang(false);
            $linkText = $lang->af_knowledgebase_name ?? 'KB';
            $li = '<li class="af-kb-link"><a href="' . af_kb_url() . '">'.htmlspecialchars_uni($linkText).'</a></li><!--af_kb_nav-->';
            $patched = preg_replace(
                '~(<ul[^>]*class="[^"]*\bmenu\b[^"]*\btop_links\b[^"]*"[^>]*>)(.*?)(</ul>)~is',
                '$1$2'.$li.'$3',
                $page,
                1
            );
            if ($patched !== null) {
                $page = $patched;
            }
        }
    }

    if ($enabled && af_kb_alias_available()) {
        $page = af_kb_normalize_legacy_misc_kb_urls($page);
    }
}

function af_kb_normalize_legacy_misc_kb_urls(string $html): string
{
    return preg_replace_callback(
        '~misc\.php\?(?:[^"\'\s<>]*?)action=(kb(?:_[a-z0-9_]+)?)([^"\'\s<>]*)~i',
        static function (array $m): string {
            $action = strtolower((string)($m[1] ?? 'kb'));
            $tail = html_entity_decode((string)($m[2] ?? ''), ENT_QUOTES, 'UTF-8');
            $tail = ltrim($tail, '&');
            parse_str($tail, $params);
            if ($action !== 'kb') {
                $params = array_merge(['action' => $action], $params);
            }

            return af_kb_url($params);
        },
        $html
    ) ?? $html;
}

function af_kb_parse_message_end(&$message, &$options = null): void
{
    global $mybb;

    if (empty($mybb->settings['af_knowledgebase_enabled'])) {
        return;
    }

    if (!af_kb_can_view()) {
        return;
    }

    if (!is_string($message) || stripos($message, '[kb=') === false) {
        return;
    }

    $message = preg_replace_callback(
        '/\\[kb=([a-z0-9_-]{2,64}):([a-z0-9_-]{2,64})\\]/i',
        static function (array $matches): string {
            $type = strtolower($matches[1]);
            $key  = strtolower($matches[2]);

            $summary = af_kb_get_entry_summary($type, $key);
            $title   = $summary['title'] ?? ($type . ':' . $key);

            $iconHtml = af_kb_build_icon_html($summary['icon_url'] ?? '', $summary['icon_class'] ?? '');
            $iconWrap = $iconHtml !== '' ? '<span class="af-kb-chip-icon">' . $iconHtml . '</span>' : '';

            $techHint = $summary['tech_hint'] ?? '';

            $attrs = ' data-kb-type="' . htmlspecialchars_uni($type) . '" data-kb-key="' . htmlspecialchars_uni($key) . '"'
                   . ' data-kb-title="' . htmlspecialchars_uni($title) . '"';
            if ($techHint !== '') {
                $attrs .= ' data-tech-hint="' . htmlspecialchars_uni($techHint) . '"';
            }

            return '<span class="af-kb-chip"' . $attrs . '>' . $iconWrap
                 . '<span class="af-kb-chip-label">' . htmlspecialchars_uni($title) . '</span></span>';
        },
        $message
    );
}

function af_kb_find_type_row(string $typeKey): ?array
{
    global $db;

    $safeType = $db->escape_string($typeKey);
    $q = $db->simple_select('af_kb_types', '*', "(type='".$safeType."' OR type_key='".$safeType."')");
    $best = null;
    $bestScore = -1;
    while ($row = $db->fetch_array($q)) {
        $score = 0;
        if ((string)($row['type_key'] ?? '') === $typeKey) {
            $score += 100;
        }
        if ((string)($row['type'] ?? '') === $typeKey) {
            $score += 50;
        }
        if ((int)($row['active'] ?? 0) === 1) {
            $score += 10;
        }
        $score += 1;

        $rowId = (int)($row['id'] ?? 0);
        $bestId = (int)($best['id'] ?? 0);
        if ($best === null || $score > $bestScore || ($score === $bestScore && $rowId > $bestId)) {
            $best = $row;
            $bestScore = $score;
        }
    }

    return $best ?: null;
}

function af_kb_normalize_mechanic_key(string $mechanicKey): string
{
    $normalized = strtolower(trim($mechanicKey));
    return $normalized !== '' ? $normalized : AF_KB_DEFAULT_MECHANIC_KEY;
}

/**
 * @param array|string $typeDefOrKey
 */
function af_kb_get_type_mechanic_key($typeDefOrKey): string
{
    $typeDef = null;
    if (is_array($typeDefOrKey)) {
        $typeDef = $typeDefOrKey;
    } else {
        $typeKey = trim((string)$typeDefOrKey);
        if ($typeKey !== '') {
            $typeDef = af_kb_find_type_row($typeKey);
        }
    }

    $raw = '';
    if (is_array($typeDef)) {
        $raw = (string)($typeDef['mechanic_key'] ?? '');
    }

    if (trim($raw) !== '') {
        return af_kb_normalize_mechanic_key($raw);
    }

    return af_kb_get_default_mechanic_mode();
}

function af_kb_get_mechanic_profile(string $mechanicKey): array
{
    $normalized = af_kb_normalize_mechanic_key($mechanicKey);

    if ($normalized === 'arpg') {
        $typeProfileMap = [];
        foreach (af_kb_arpg_supported_types() as $supportedType) {
            $typeProfileMap[$supportedType] = $supportedType;
        }

        return [
            'mechanic_key' => 'arpg',
            'status' => 'active',
            'enabled' => true,
            'rules_schema_ids' => [
                'default' => AF_KB_ARPG_META_SCHEMA,
                'meta' => AF_KB_ARPG_META_SCHEMA,
                'rules' => AF_KB_ARPG_RULES_SCHEMA,
                'mechanics' => AF_KB_ARPG_MECHANICS_SCHEMA,
                'sheet_normalized' => AF_KB_ARPG_SHEET_NORMALIZED_SCHEMA,
            ],
            'ui_profile_id' => 'arpg',
            'ui_config' => [
                'routing_mode' => 'mechanic_aware_arpg',
            ],
            'providers' => [
                'rules_config' => 'af_kb_default_type_rules_config_arpg',
                'type_profile' => 'af_kb_get_type_profile_definition_arpg',
                'schema' => 'af_kb_get_type_schema_arpg',
                'validator' => 'af_kb_validate_rules_json_by_type_arpg',
            ],
            'type_profile_map' => $typeProfileMap,
            'validator_hooks' => [
                'rules_json_by_type' => 'af_kb_validate_rules_json_by_type_arpg',
            ],
        ];
    }

    return [
        'mechanic_key' => AF_KB_DEFAULT_MECHANIC_KEY,
        'status' => 'active',
        'enabled' => true,
        'rules_schema_ids' => [
            'default' => AF_KB_RULES_SCHEMA,
            'item' => 'af_kb.item.v2',
        ],
        'ui_profile_id' => 'dnd',
        'ui_config' => [
            'routing_mode' => 'legacy_dnd',
        ],
        'providers' => [
            'rules_config' => 'af_kb_default_type_rules_config_dnd',
            'type_profile' => 'af_kb_get_type_profile_definition_dnd',
            'schema' => 'af_kb_get_type_schema_dnd',
            'validator' => 'af_kb_validate_rules_json_by_type_dnd',
        ],
        'type_profile_map' => [
            'race' => 'race',
            'race_variant' => 'race_variant',
            'class' => 'class',
            'theme' => 'theme',
            'skill' => 'skill',
            'knowledge' => 'knowledge',
            'language' => 'language',
            'item' => 'item',
            'spell' => 'spell',
            'condition' => 'condition',
            'perk' => 'perk',
            'faction' => 'faction',
            'lore' => 'lore',
        ],
        'validator_hooks' => [
            'rules_json_by_type' => 'af_kb_validate_rules_json_by_type_dnd',
        ],
    ];
}

function af_kb_find_item_kind_row(string $kindKey): ?array
{
    global $db;

    if ($kindKey === '' || !$db->table_exists('af_kb_item_kinds')) {
        return null;
    }

    $row = $db->fetch_array($db->simple_select('af_kb_item_kinds', '*', "kind_key='".$db->escape_string($kindKey)."'", ['limit' => 1]));
    return $row ?: null;
}


function kb_parse_rules(array $entry): array
{
    $candidates = [];
    if (!empty($entry['rules_json'])) {
        $candidates[] = af_kb_decode_json((string)$entry['rules_json']);
    }
    $meta = af_kb_decode_json((string)($entry['meta_json'] ?? '{}'));
    if (($meta['schema'] ?? '') === AF_KB_RULES_SCHEMA) {
        $candidates[] = $meta;
    }
    if (is_array($meta['rules'] ?? null)) {
        $candidates[] = (array)$meta['rules'];
    }
    if (is_array($entry['rules'] ?? null)) {
        $candidates[] = (array)$entry['rules'];
    }
    foreach ($candidates as $rules) {
        if (is_array($rules) && $rules) {
            return $rules;
        }
    }
    return [];
}

function af_kb_is_internal_service_type(string $typeKey, ?string $mechanicKey = null): bool
{
    if ($typeKey === '') {
        return false;
    }

    $normalizedMechanic = af_kb_normalize_mechanic_key((string)$mechanicKey);
    if ($normalizedMechanic !== '' && $normalizedMechanic !== 'arpg') {
        return false;
    }

    return in_array($typeKey, af_kb_arpg_internal_types(), true);
}

function af_kb_resolve_entry_visibility(array $entry): array
{
    $visibility = [
        'catalog' => true,
        'search' => true,
        'internal' => false,
    ];

    $rules = kb_parse_rules($entry);
    if (isset($rules['visibility']) && is_array($rules['visibility'])) {
        foreach (['catalog', 'search', 'internal'] as $flag) {
            if (array_key_exists($flag, $rules['visibility'])) {
                $visibility[$flag] = !empty($rules['visibility'][$flag]);
            }
        }
    }

    $type = (string)($entry['type'] ?? '');
    $mechanicKey = af_kb_get_type_mechanic_key($type);
    if (af_kb_is_internal_service_type($type, $mechanicKey)) {
        $visibility['catalog'] = false;
        $visibility['search'] = false;
        $visibility['internal'] = true;
    }

    return $visibility;
}

function af_kb_entry_visible_in_context(array $entry, string $context, bool $canEdit): bool
{
    if ($canEdit) {
        return true;
    }

    $visibility = af_kb_resolve_entry_visibility($entry);
    if (!empty($visibility['internal'])) {
        return false;
    }

    if ($context === 'search') {
        return !empty($visibility['search']);
    }

    return !empty($visibility['catalog']);
}

function kb_collect_blocks(array $entry): array
{
    $out = [];
    foreach (['meta_json', 'data_json'] as $field) {
        $payload = af_kb_decode_json((string)($entry[$field] ?? '{}'));
        foreach ((array)($payload['blocks'] ?? $payload['blockdata'] ?? []) as $block) {
            if (!is_array($block)) continue;
            $key = (string)($block['block_key'] ?? '');
            if ($key !== '') $out[$key] = $block;
        }
        foreach ($payload as $k => $v) {
            if (strpos((string)$k, 'block_') === 0 && is_array($v)) {
                $out[substr((string)$k, 6)] = $v;
            }
        }
    }
    return $out;
}

function kb_resolve_ui_schema(array $typeRow, ?array $kindRow): array
{
    $typeKey = (string)($typeRow['type_key'] ?? $typeRow['type'] ?? '');
    $schema = af_kb_decode_json((string)($typeRow['ui_schema_json'] ?? ''));
    if (!$schema) {
        $schema = af_kb_default_ui_schema_for_type($typeKey);
    }
    if ($kindRow) {
        $schema = af_kb_apply_overlay_to_schema($schema, af_kb_decode_json((string)($kindRow['ui_schema_json'] ?? '{}')));
    }
    return $schema;
}

function kb_resolve_data_for_ui(array $rules, array $blocks, array $overlay): array
{
    $defaults = (array)($overlay['root_defaults'] ?? []);
    $resolved = array_replace_recursive($defaults, $rules);
    $equip = (array)($resolved['equip'] ?? []);
    return ['rules' => $resolved, 'blocks' => $blocks, 'equip' => $equip];
}

function af_kb_humanize_effect(array $effect, bool $isRu): string
{
    $op = (string)($effect['op'] ?? '');
    $skill = (string)($effect['skill'] ?? '');
    $scope = (string)($effect['scope'] ?? '');
    $value = (string)($effect['value'] ?? '');

    // нормальная сборка "skill/scope" без лишнего "/"
    $ss = trim($skill . ($scope !== '' ? '/' . $scope : ''));

    if ($op === 'stat_bonus') {
        $stat = af_kb_l10n_label('stats', (string)($effect['stat'] ?? ''), $isRu);
        return '+' . $value . ' ' . ($isRu ? 'к' : 'to') . ' ' . $stat;
    }

    $map = [
        // было: ': '.$skill.'/'.$scope.' '.$value
        'dc_modifier' => ($isRu ? 'Модификатор сложности' : 'DC modifier') . ': ' . $ss . ' ' . $value,

        'grant_skill_class' => ($isRu ? 'Навык ' : 'Skill ') . $skill . ($isRu ? ' становится классовым' : ' becomes class skill'),
        'skill_bonus_if_already_class' => ($isRu ? 'Если ' : 'If ') . $skill . ($isRu ? ' уже классовый: +' : ' already class: +') . $value,
        'penalty_reduction' => ($isRu ? 'Снижение штрафа' : 'Penalty reduction') . ': ' . $skill . ' ' . $value,
        'threshold_shift' => ($isRu ? 'Сдвиг порога' : 'Threshold shift') . ': ' . (string)($effect['check'] ?? '') . ' ' . (string)($effect['from'] ?? '') . '→' . (string)($effect['to'] ?? ''),
        'limited_triggered_restore' => (string)($effect['uses_per_day'] ?? '') . ($isRu ? '/день: при ' : '/day: on ') . (string)($effect['trigger'] ?? '') . ' ' . (string)($effect['restore'] ?? ''),
        'special_rule' => ($isRu ? 'Особое правило: ' : 'Special rule: ') . (string)($effect['id'] ?? ''),
        'choice_ref' => ($isRu ? 'Связано с выбором: ' : 'Linked choice: ') . (string)($effect['choice_id'] ?? ''),
    ];

    return $map[$op] ?? (($isRu ? 'Неизвестный эффект: op=' : 'Unknown effect: op=') . $op);
}

function af_kb_render_ui_block(array $block, array $vm, array $entry, bool $isRu): string
{
    $source = (string)($block['source'] ?? '');
    $path = (string)($block['path'] ?? '');

    // Флаги рендера (прокидываем из vm)
    $noCardWrap = !empty($vm['__no_card_wrap']); // для lore/spell и других, если понадобится

    if ($source === 'entry') {
        if ($path === 'body') {
            $body = af_kb_pick_text($entry, 'body');
            if ($body === '') return '';
            $html = af_kb_parse_message($body);
            return $noCardWrap ? $html : '<div class="kb-card">'.$html.'</div>';
        }
        if ($path === 'short') {
            $short = af_kb_pick_text($entry, 'short');
            if ($short === '') return '';
            $html = af_kb_parse_message($short);
            return $noCardWrap ? $html : '<div class="kb-card">'.$html.'</div>';
        }
        if ($path === 'item_kind') {
            $kind = trim((string)($entry['item_kind'] ?? ''));
            return $kind !== '' ? '<div class="kb-chip">'.htmlspecialchars_uni($kind).'</div>' : '';
        }
    }

    if ($source === 'rules') {
        $v = af_kb_get_nested((array)$vm['rules'], $path);
        if ($v === null || $v === '' || $v === []) return '';

        if ($path === 'choices' && is_array($v)) {
            $cards = '';
            foreach ($v as $choice) {
                if (!is_array($choice)) continue;
                $type = (string)($choice['type'] ?? '');
                $pick = (int)($choice['pick'] ?? 1);
                $text = $type;

                if ($type === 'kb_pick') {
                    $text = ($isRu ? 'Выберите ' : 'Pick ') . $pick . ': ' . (string)($choice['kb_type'] ?? '');
                } elseif ($type === 'language_pick') {
                    $text = ($isRu ? 'Выберите ' : 'Pick ') . $pick . ($isRu ? ' язык' : ' language');
                } elseif ($type === 'stat_bonus') {
                    $text = ($isRu ? 'Выберите ' : 'Pick ') . $pick . ($isRu ? ' атрибут: +' : ' stat: +') . (string)($choice['value'] ?? '2');
                }

                $cards .= '<div class="kb-choice-card" id="choice-'.htmlspecialchars_uni((string)($choice['id'] ?? '')).'">'
                        . htmlspecialchars_uni($text)
                        . '</div>';
            }
            return $cards;
        }

        if ($path === 'traits' && is_array($v)) {
            $items = '';
            foreach ($v as $tr) {
                if (!is_array($tr)) continue;

                $title = $isRu
                    ? ((string)($tr['title']['ru'] ?? '') ?: (string)($tr['id'] ?? 'Trait'))
                    : ((string)($tr['title']['en'] ?? '') ?: (string)($tr['id'] ?? 'Trait'));
                $desc = $isRu ? (string)($tr['desc']['ru'] ?? '') : (string)($tr['desc']['en'] ?? '');

                $links = [];
                foreach ((array)($tr['effects'] ?? []) as $ef) {
                    if (is_array($ef) && ($ef['op'] ?? '') === 'choice_ref' && !empty($ef['choice_id'])) {
                        $cid = (string)$ef['choice_id'];
                        $links[] = '<a href="#choice-'.htmlspecialchars_uni($cid).'">'.htmlspecialchars_uni($cid).'</a>';
                    }
                }

                $items .= '<div class="kb-card"><strong>'.htmlspecialchars_uni($title).'</strong><div>'.af_kb_parse_message($desc).'</div>';
                if ($links) {
                    $items .= '<div class="kb-muted">'.($isRu ? 'Связано с выборами: ' : 'Linked choices: ').implode(', ', $links).'</div>';
                }
                $items .= '</div>';
            }
            return $items;
        }

        if ($path === 'fixed_bonuses.stats' && is_array($v)) {
            $cells = '';
            foreach (['str','dex','con','int','wis','cha'] as $k) {
                $val = (int)($v[$k] ?? 0);
                if ($val === 0) continue;
                $cells .= '<div class="kb-stat"><span>'
                       . htmlspecialchars_uni(af_kb_l10n_label('stats', $k, $isRu))
                       . '</span><strong>'.($val > 0 ? '+' : '').$val.'</strong></div>';
            }
            return $cells !== '' ? '<div class="kb-stats-grid">'.$cells.'</div>' : '';
        }

        if ($path === 'grants' && is_array($v)) {
            $lines = '';
            foreach ($v as $eff) {
                if (is_array($eff)) {
                    $lines .= '<div class="kb-effect-line">'.htmlspecialchars_uni(af_kb_humanize_effect($eff, $isRu)).'</div>';
                }
            }
            return $lines;
        }

        // дефолт: карточка со значением
        return '<div class="kb-card">'.af_kb_render_value_html($v, $isRu).'</div>';
    }

    if ($source === 'equip') {
        $v = af_kb_get_nested((array)$vm['equip'], $path);
        if ($v === null || $v === '') return '';
        $label = $path === 'slot'
            ? af_kb_l10n_label('equip_slots', (string)$v, $isRu)
            : (is_bool($v) ? ($v ? ($isRu?'Да':'Yes') : ($isRu?'Нет':'No')) : (string)$v);

        return '<div class="kb-card"><strong>'.htmlspecialchars_uni($path).':</strong> '.htmlspecialchars_uni((string)$label).'</div>';
    }

    if ($source === 'blocks') {
        $b = (array)($vm['blocks'][$path] ?? []);
        if (!$b) return '';

        $prog = (array)($b['progression'] ?? []);
        if (!$prog) {
            return '<div class="kb-card">'.htmlspecialchars_uni(json_encode($b, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '').'</div>';
        }

        $rows = [];
        foreach ($prog as $row) {
            if (!is_array($row)) continue;
            $lvl = (string)($row['level'] ?? '');
            $title = $isRu ? ((string)($row['title_ru'] ?? $row['title'] ?? '')) : ((string)($row['title_en'] ?? $row['title'] ?? ''));
            $effects = '';
            foreach ((array)($row['effects'] ?? []) as $eff) {
                if (is_array($eff)) {
                    $effects .= '<div class="kb-effect-line">'.htmlspecialchars_uni(af_kb_humanize_effect($eff, $isRu)).'</div>';
                }
            }
            $rows[] = '<div class="kb-level"><div class="kb-level-head">'
                . ($lvl !== '' ? ($isRu?'Уровень ':'Level ').htmlspecialchars_uni($lvl).': ' : '')
                . htmlspecialchars_uni($title)
                . '</div>'.$effects.'</div>';
        }

        return '<div class="kb-timeline">'.implode('', $rows).'</div>';
    }

    if ($source === 'raw') {
        $json = $path === 'rules_json' ? (array)$vm['rules'] : af_kb_decode_json((string)($entry['meta_json'] ?? '{}'));
        return '<details class="kb-raw"><summary>'.htmlspecialchars_uni($isRu ? 'Показать JSON' : 'Show JSON')
            . '</summary><pre>'.htmlspecialchars_uni(json_encode($json, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '{}')
            . '</pre></details>';
    }

    return '';
}

function af_kb_render_entry_ui(array $entry, array $typeRow, bool $isRu): string
{
    global $mybb;

    $typeKey = (string)($typeRow['type_key'] ?? $typeRow['type'] ?? $entry['type'] ?? '');

    // Определяем: это просмотр конкретной записи (есть key) или листинг типа.
    // Важно: без новых хелперов, только по входным данным misc.php.
    $isEntryView = false;
    if (isset($mybb) && is_object($mybb)) {
        $action = (string)($mybb->input['action'] ?? '');
        $key    = (string)($mybb->input['key'] ?? '');
        if ($action === 'kb' && $key !== '') {
            $isEntryView = true;
        }
    }

    /**
     * ЖЁСТКИЙ РЕЖИМ ДЛЯ theme:
     * - на странице записи: только body, без UI-секций, без заголовков, без raw JSON
     * - на листинге: по желанию можно short+body (или только short)
     */
    if ($typeKey === 'theme') {
        $body  = af_kb_pick_text($entry, 'body');
        $short = af_kb_pick_text($entry, 'short');

        if ($isEntryView) {
            // Страница записи: только body
            return $body !== '' ? trim(af_kb_parse_message($body)) : '';
        }

        // Листинг типа: short (+ опционально body)
        $html = '';
        if ($short !== '') {
            $html .= af_kb_parse_message($short);
        }
        if ($body !== '') {
            $html .= ($html !== '' ? "\n" : '') . af_kb_parse_message($body);
        }

        return trim($html);
    }

    // ЖЁСТКИЙ РЕЖИМ ДЛЯ spell: как у тебя было
    if ($typeKey === 'spell') {
        $body  = af_kb_pick_text($entry, 'body');
        $short = af_kb_pick_text($entry, 'short');

        $html = '';
        if ($short !== '') {
            $html .= af_kb_parse_message($short);
        }
        if ($body !== '') {
            $html .= ($html !== '' ? "\n" : '') . af_kb_parse_message($body);
        }

        return trim($html);
    }


    if ($typeKey === 'item') {
        $body = af_kb_pick_text($entry, 'body');
        if ($body === '') {
            return '';
        }

        // Для предметов на странице записи выводим только основное описание
        // без технических карточек Slot / Humanity / Modifiers / Effects / Grants
        return trim(af_kb_parse_message($body));
    }

    // ----- обычная логика для остальных типов -----

    $itemKind = trim((string)($entry['item_kind'] ?? ''));
    if ($itemKind === '') {
        $meta = af_kb_decode_json((string)($entry['meta_json'] ?? '{}'));
        $itemKind = trim((string)($meta['item_kind'] ?? ''));
    }

    $kindRow = af_kb_find_item_kind_row($itemKind);
    $schema  = kb_resolve_ui_schema($typeRow, $kindRow);
    $rules   = kb_parse_rules($entry);
    $blocks  = kb_collect_blocks($entry);

    $vm = kb_resolve_data_for_ui($rules, $blocks, $schema);

    // lore — как раньше: без kb-card для body/short и без kb-section при пустом заголовке
    if ($typeKey === 'lore') {
        $vm['__no_card_wrap'] = 1;
    }

    $html = '';
    foreach ((array)($schema['sections'] ?? []) as $section) {
        if (!is_array($section)) continue;

        $title  = $isRu ? (string)($section['title']['ru'] ?? '') : (string)($section['title']['en'] ?? '');
        $layout = (string)($section['layout'] ?? 'stack');

        $parts = '';
        foreach ((array)($section['blocks'] ?? []) as $block) {
            if (!is_array($block)) continue;
            $parts .= af_kb_render_ui_block($block, $vm, $entry, $isRu);
        }

        if (trim(strip_tags($parts)) === '') continue;

        $head = $title !== '' ? '<h3>'.htmlspecialchars_uni($title).'</h3>' : '';

        if ($typeKey === 'lore' && $title === '') {
            $html .= $parts;
            continue;
        }

        $html .= '<section class="kb-section kb-section--'.htmlspecialchars_uni($layout).'">'.$head.$parts.'</section>';
    }

    return trim($html);
}

function af_kb_get_nested(array $data, string $path)
{
    if ($path === '') {
        return $data;
    }

    $cur = $data;
    foreach (explode('.', $path) as $part) {
        if (!is_array($cur) || !array_key_exists($part, $cur)) {
            return null;
        }
        $cur = $cur[$part];
    }

    return $cur;
}

function af_kb_render_value_html($value, bool $isRu, array $schema = []): string
{
    if ($value === null || $value === '') {
        return '';
    }
    if (is_bool($value)) {
        return $value ? ($isRu ? 'Да' : 'Yes') : ($isRu ? 'Нет' : 'No');
    }
    if (is_scalar($value)) {
        return htmlspecialchars_uni((string)$value);
    }
    if (!is_array($value)) {
        return '';
    }

    $statsDict = (array)($schema['dictionaries']['stats'] ?? []);

    if (array_key_exists('stats', $value) && is_array($value['stats'])) {
        $items = [];
        foreach ($value['stats'] as $statKey => $statVal) {
            $dict = (array)($statsDict[$statKey] ?? []);
            $label = $isRu ? ($dict['ru'] ?? strtoupper((string)$statKey)) : ($dict['en'] ?? strtoupper((string)$statKey));
            $items[] = '<li><strong>'.htmlspecialchars_uni((string)$label).':</strong> '.htmlspecialchars_uni((string)$statVal).'</li>';
        }
        if (isset($value['hp'])) {
            $items[] = '<li><strong>HP:</strong> '.htmlspecialchars_uni((string)$value['hp']).'</li>';
        }
        if (isset($value['ep'])) {
            $items[] = '<li><strong>EP:</strong> '.htmlspecialchars_uni((string)$value['ep']).'</li>';
        }
        return $items ? '<ul>'.implode('', $items).'</ul>' : '';
    }

    $items = [];
    foreach ($value as $k => $v) {
        if (is_array($v)) {
            $rendered = af_kb_render_value_html($v, $isRu, $schema);
            if ($rendered !== '') {
                $items[] = '<li><strong>'.htmlspecialchars_uni((string)$k).':</strong> '.$rendered.'</li>';
            }
            continue;
        }
        $items[] = '<li>'.htmlspecialchars_uni((string)$v).'</li>';
    }

    return $items ? '<ul>'.implode('', $items).'</ul>' : '';
}

function af_kb_build_resolved_ui_schema(array $typeRow, array $entry): array
{
    $typeKey = (string)($typeRow['type_key'] ?? $typeRow['type'] ?? $entry['type'] ?? '');
    $schema = af_kb_get_type_schema($typeKey);

    if ($typeKey === 'item') {
        $itemKind = trim((string)($entry['item_kind'] ?? ''));
        if ($itemKind === '') {
            $meta = af_kb_decode_json((string)($entry['meta_json'] ?? '{}'));
            $itemKind = trim((string)($meta['item_kind'] ?? ''));
        }
        $kindRow = af_kb_find_item_kind_row($itemKind);
        if ($kindRow) {
            $schema = af_kb_apply_overlay_to_schema($schema, af_kb_decode_json((string)($kindRow['ui_schema_json'] ?? '{}')));
        }
    }

    return $schema;
}

function af_kb_render_structured_rules(array $entry, array $typeRow, bool $isRu): string
{
    $typeKey = (string)($typeRow['type_key'] ?? ($typeRow['id'] ?? ($typeRow['key'] ?? '')));
    if ($typeKey === 'theme') {
        return '';
    }

    $meta = af_kb_decode_json((string)($entry['meta_json'] ?? '{}'));
    $rules = [];
    if (($meta['schema'] ?? '') === AF_KB_RULES_SCHEMA) {
        $rules = $meta;
    } elseif (is_array($meta['rules'] ?? null)) {
        $rules = $meta['rules'];
    }

    $schema = af_kb_build_resolved_ui_schema($typeRow, $entry);
    $defaults = (array)($schema['root_defaults'] ?? []);
    if ($rules) {
        $rules = array_replace_recursive($defaults, $rules);
    } else {
        $rules = $defaults;
    }

    $sections = (array)($schema['sections'] ?? []);
    $chunks = [];

    foreach ($sections as $section) {
        if (!is_array($section)) {
            continue;
        }

        $title = $isRu ? ((string)($section['title_ru'] ?? '')) : ((string)($section['title_en'] ?? ''));
        $blocks = (array)($section['blocks'] ?? []);
        $rows = [];

        foreach ($blocks as $blockPath) {
            $blockPath = (string)$blockPath;
            $label = $blockPath;
            $value = null;

            if ($blockPath === 'title') {
                $value = af_kb_pick_text($entry, 'title');
                $label = $isRu ? 'Название' : 'Title';
            } elseif ($blockPath === 'short') {
                $value = af_kb_pick_text($entry, 'short');
                $label = $isRu ? 'Кратко' : 'Summary';
            } elseif ($blockPath === 'body') {
                $value = af_kb_pick_text($entry, 'body');
                $label = $isRu ? 'Описание' : 'Description';

                if ($value !== '') {
                    $rows[] = '<div class="af-kb-rule-row af-kb-rule-row--body">'
                        . af_kb_parse_message((string)$value)
                        . '</div>';
                }
                continue;
            } elseif ($blockPath === 'tags') {
                $value = trim((string)($entry['tags'] ?? ''));
            } elseif (strpos($blockPath, 'rules.') === 0) {
                $value = af_kb_get_nested($rules, substr($blockPath, 6));
                $label = substr($blockPath, 6);
            } elseif (strpos($blockPath, 'equip.') === 0) {
                $value = af_kb_get_nested($rules, substr($blockPath, 5));
                $label = substr($blockPath, 5);
            }

            $rendered = af_kb_render_value_html($value, $isRu, $schema);
            if ($rendered === '') {
                continue;
            }

            $rows[] = '<div class="af-kb-rule-row"><strong>'
                . htmlspecialchars_uni($label)
                . ':</strong> '
                . $rendered
                . '</div>';
        }

        if (!$rows) {
            continue;
        }

        $chunks[] = '<section class="af-kb-rule-section"><h3>'
            . htmlspecialchars_uni($title)
            . '</h3>'
            . implode('', $rows)
            . '</section>';
    }

    if (!$chunks && $rules) {
        foreach (['hp_base', 'speed', 'languages', 'fixed_bonuses', 'choices', 'traits'] as $path) {
            $v = af_kb_get_nested($rules, $path);
            $rendered = af_kb_render_value_html($v, $isRu, $schema);
            if ($rendered !== '') {
                $chunks[] = '<div class="af-kb-rule-row"><strong>'
                    . htmlspecialchars_uni($path)
                    . ':</strong> '
                    . $rendered
                    . '</div>';
            }
        }
    }

    $rawToggle = '';
    if ($rules) {
        $rawToggle = '<details class="af-kb-tech"><summary>'
            . htmlspecialchars_uni($isRu ? 'Показать исходный JSON' : 'Show source JSON')
            . '</summary><pre>'
            . htmlspecialchars_uni(json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}')
            . '</pre></details>';
    }

    if (!$chunks && $rawToggle === '') {
        return '';
    }

    return '<div class="af-kb-structured-rules">' . implode('', $chunks) . $rawToggle . '</div>';
}



function af_kb_migration_table_columns(string $table): array
{
    global $db;

    $columns = [];
    $query = $db->write_query("SHOW COLUMNS FROM " . TABLE_PREFIX . $table);
    while ($row = $db->fetch_array($query)) {
        $name = (string)($row['Field'] ?? '');
        if ($name !== '') {
            $columns[$name] = $name;
        }
    }

    return $columns;
}

function af_kb_migration_discover_block_columns(): array
{
    $columns = af_kb_migration_table_columns('af_kb_blocks');

    $entryIdCol = '';
    foreach (['entry_id', 'kb_id', 'eid', 'entryid'] as $candidate) {
        if (isset($columns[$candidate])) {
            $entryIdCol = $candidate;
            break;
        }
    }

    $blockKeyCol = '';
    foreach (['block_key', 'code', 'name', 'slug', 'type'] as $candidate) {
        if (isset($columns[$candidate])) {
            $blockKeyCol = $candidate;
            break;
        }
    }

    $contentCol = '';
    foreach (['content', 'body', 'value', 'text', 'data_json'] as $candidate) {
        if (isset($columns[$candidate])) {
            $contentCol = $candidate;
            break;
        }
    }

    return [
        'all' => array_keys($columns),
        'entry_id' => $entryIdCol,
        'block_key' => $blockKeyCol,
        'content' => $contentCol,
    ];
}

function af_kb_migration_find_rules_in_blocks(int $entryId, array $mapping): array
{
    global $db;

    $entryCol = (string)($mapping['entry_id'] ?? '');
    $keyCol = (string)($mapping['block_key'] ?? '');
    $contentCol = (string)($mapping['content'] ?? '');
    if ($entryId <= 0 || $entryCol === '' || $contentCol === '') {
        return ['raw' => '', 'reason' => 'no block mapping'];
    }

    $fields = [$contentCol . ' AS payload'];
    if ($keyCol !== '') {
        $fields[] = $keyCol . ' AS block_key';
    }

    $where = $entryCol . '=' . $entryId;
    if ($db->field_exists('active', 'af_kb_blocks')) {
        $where .= ' AND active=1';
    }

    $priority = ['data_json', 'rules_json', 'rules', 'data', 'item_rules', 'payload_json'];
    if ($keyCol !== '') {
        foreach ($priority as $candidate) {
            $row = $db->fetch_array($db->simple_select('af_kb_blocks', implode(',', $fields), $where . " AND " . $keyCol . "='" . $db->escape_string($candidate) . "'", ['limit' => 1]));
            if (!is_array($row)) {
                continue;
            }
            $raw = trim((string)($row['payload'] ?? ''));
            if ($raw !== '') {
                return ['raw' => $raw, 'reason' => 'block:' . $candidate];
            }
        }
    }

    $query = $db->simple_select('af_kb_blocks', implode(',', $fields), $where, ['order_by' => 'id', 'order_dir' => 'ASC']);
    while ($row = $db->fetch_array($query)) {
        $raw = trim((string)($row['payload'] ?? ''));
        if ($raw === '' || substr($raw, 0, 1) !== '{') {
            continue;
        }
        if (strpos($raw, '"schema":"af_kb.rules.v1"') !== false || strpos($raw, '"type_profile"') !== false) {
            return ['raw' => $raw, 'reason' => 'fallback-json-pattern'];
        }
    }

    return ['raw' => '', 'reason' => 'no blocks'];
}

function af_kb_migration_log(int $entryId, string $status, string $reason, array $extra = []): void
{
    global $db, $mybb;

    if (!$db->table_exists('af_kb_log')) {
        return;
    }

    $payload = array_merge([
        'entry_id' => $entryId,
        'status' => $status,
        'reason' => $reason,
    ], $extra);

    $db->insert_query('af_kb_log', [
        'uid' => (int)($mybb->user['uid'] ?? 0),
        'action' => 'migrate_rules',
        'type' => 'entry',
        'key' => (string)$entryId,
        'diff_json' => $db->escape_string(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'),
        'dateline' => TIME_NOW,
    ]);
}

function af_kb_migration_backup_tables(): array
{
    global $db;

    $suffix = date('Ymd_His');
    $created = [];
    foreach (['af_kb_entries', 'af_kb_blocks'] as $table) {
        if (!$db->table_exists($table)) {
            continue;
        }
        $backup = $table . '_bak_' . $suffix;
        $db->write_query('CREATE TABLE ' . TABLE_PREFIX . $backup . ' LIKE ' . TABLE_PREFIX . $table);
        $db->write_query('INSERT INTO ' . TABLE_PREFIX . $backup . ' SELECT * FROM ' . TABLE_PREFIX . $table);
        $created[] = $backup;
    }

    return $created;
}

function af_kb_handle_migrate_rules(): void
{
    global $db, $mybb;

    if (!af_kb_can_edit() || (int)($mybb->user['uid'] ?? 0) <= 0) {
        af_kb_render_json_error('No access', 403);
    }

    $dry = (int)$mybb->get_input('dry', MyBB::INPUT_INT) === 1;
    $limit = max(1, (int)$mybb->get_input('limit', MyBB::INPUT_INT));
    $limit = min($limit, 500);
    $offset = max(0, (int)$mybb->get_input('offset', MyBB::INPUT_INT));
    $onlyId = max(0, (int)$mybb->get_input('only_id', MyBB::INPUT_INT));

    $entriesCols = af_kb_migration_table_columns('af_kb_entries');
    $blocksMap = af_kb_migration_discover_block_columns();

    $where = '1=1';
    if ($onlyId > 0) {
        $where .= ' AND id=' . $onlyId;
    }

    $totalRow = $db->fetch_array($db->simple_select('af_kb_entries', 'COUNT(*) AS cnt', $where, ['limit' => 1]));
    $total = (int)($totalRow['cnt'] ?? 0);

    $query = $db->simple_select('af_kb_entries', '*', $where, ['order_by' => 'id', 'order_dir' => 'ASC', 'limit_start' => $offset, 'limit' => $limit]);

    $stats = [
        'total' => $total,
        'processed' => 0,
        'already_migrated' => 0,
        'found_rules_in_blocks' => 0,
        'migrated' => 0,
        'skipped_no_rules' => 0,
        'invalid_json' => 0,
    ];

    $updated = [];
    $backupTables = [];
    $backupDone = $dry;

    while ($entry = $db->fetch_array($query)) {
        $stats['processed']++;
        $entryId = (int)($entry['id'] ?? 0);
        $metaRaw = (string)($entry['meta_json'] ?? '');
        $meta = af_kb_decode_json($metaRaw);
        if (!is_array($meta)) {
            $meta = [];
        }

        $existingRules = is_array($meta['rules'] ?? null) ? $meta['rules'] : null;
        if (is_array($existingRules) && (isset($existingRules['schema']) || isset($existingRules['type_profile']))) {
            $stats['already_migrated']++;
            af_kb_migration_log($entryId, 'skip', 'already migrated');
            continue;
        }

        $found = af_kb_migration_find_rules_in_blocks($entryId, $blocksMap);
        $rawRules = trim((string)($found['raw'] ?? ''));
        if ($rawRules === '') {
            $stats['skipped_no_rules']++;
            af_kb_migration_log($entryId, 'skip', (string)($found['reason'] ?? 'no blocks'));
            continue;
        }

        $stats['found_rules_in_blocks']++;
        $rules = json_decode($rawRules, true);
        if (!is_array($rules)) {
            $stats['invalid_json']++;
            af_kb_migration_log($entryId, 'fail', 'invalid json', ['source' => (string)($found['reason'] ?? '')]);
            continue;
        }

        if (!is_array($meta['ui'] ?? null)) {
            $meta['ui'] = [];
        }

        foreach (['icon_url', 'bg_url', 'banner_url', 'icon_class'] as $field) {
            if (!isset($entriesCols[$field])) {
                continue;
            }
            if (!empty($meta['ui'][$field])) {
                continue;
            }
            $value = trim((string)($entry[$field] ?? ''));
            if ($value !== '') {
                $meta['ui'][$field] = $value;
            }
        }

        $meta['schema'] = 'af_kb.meta.v2';
        $meta['rules'] = $rules;

        if (!empty($entriesCols['item_kind']) && trim((string)($entry['item_kind'] ?? '')) === '') {
            $kind = trim((string)($rules['item']['item_kind'] ?? ''));
            if ($kind !== '') {
                $entry['item_kind'] = $kind;
            }
        }

        $update = [
            'meta_json' => $db->escape_string(json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"schema":"af_kb.meta.v2"}'),
            'updated_at' => TIME_NOW,
        ];

        if (!empty($entriesCols['item_kind']) && !empty($entry['item_kind'])) {
            $update['item_kind'] = $db->escape_string((string)$entry['item_kind']);
        }
        if (!empty($entriesCols['rarity'])) {
            $rarity = trim((string)($rules['item']['rarity'] ?? ''));
            if ($rarity !== '') {
                $update['rarity'] = $db->escape_string($rarity);
            }
        }

        if (!$dry) {
            if (!$backupDone) {
                $backupTables = af_kb_migration_backup_tables();
                $backupDone = true;
            }
            $db->update_query('af_kb_entries', $update, 'id=' . $entryId);
            $stats['migrated']++;
            $updated[] = $entryId;
            af_kb_migration_log($entryId, 'ok', 'migrated', ['source' => (string)($found['reason'] ?? '')]);
        }
    }

    af_kb_send_json([
        'success' => true,
        'dry_run' => $dry,
        'params' => ['limit' => $limit, 'offset' => $offset, 'only_id' => $onlyId],
        'diagnostics' => $stats,
        'blocks_mapping' => $blocksMap,
        'backup_tables' => $backupTables,
        'updated_ids' => $updated,
    ]);
}


/* -------------------- ROUTER -------------------- */

function af_kb_misc_route(): void
{
    global $mybb;

    $action = $mybb->get_input('action');
    if (!in_array($action, ['kb', 'kb_edit', 'kb_get', 'kb_list', 'kb_children', 'kb_race_variants', 'kb_type_edit', 'kb_type_delete', 'kb_help', 'kb_types', 'knowledgebase_entry', 'kb_debug_entry', 'kb_migrate_rules', 'kb_manage_categories', 'kb_manage_categories_save', 'kb_entry_categories_save', 'kb_debug_entry_cats'], true)) {
        return;
    }

    if (empty($mybb->settings['af_knowledgebase_enabled'])) {
        error_no_permission();
    }

    if (
        $action === 'kb'
        && af_kb_alias_available()
        && (defined('THIS_SCRIPT') ? THIS_SCRIPT : '') === 'misc.php'
        && strtoupper((string)($mybb->request_method ?? 'GET')) === 'GET'
    ) {
        $params = $mybb->input;
        unset($params['action']);
        redirect(af_kb_url($params), '', '', true);
    }

    af_kb_dispatch();
}

function af_kb_render_kb_page(): void
{
    global $mybb;

    if (empty($mybb->settings['af_knowledgebase_enabled'])) {
        error_no_permission();
    }

    af_knowledgebase_load_lang(false);
    af_kb_dispatch();
}

function af_kb_dispatch(): void
{
    global $mybb;

    $action = trim((string)$mybb->get_input('action'));
    if ($action === '' || $action === 'index' || $action === 'kb') {
        $action = 'view';
    }

    af_knowledgebase_load_lang(false);

    if ($action === 'kb_get') {
        af_kb_handle_json_get();
    }
    if ($action === 'kb_list') {
        af_kb_handle_json_list();
    }
    if ($action === 'kb_types') {
        af_kb_handle_json_types();
    }
    if ($action === 'kb_children') {
        af_kb_handle_json_children();
    }
    if ($action === 'kb_race_variants') {
        af_kb_handle_json_race_variants();
    }

    if ($action === 'kb_edit') {
        af_kb_handle_edit();
    }

    if ($action === 'kb_type_edit') {
        af_kb_handle_type_edit();
    }

    if ($action === 'kb_type_delete') {
        af_kb_handle_type_delete();
    }

    if ($action === 'kb_help') {
        af_kb_handle_help();
    }

    if ($action === 'kb_debug_entry') {
        af_kb_handle_debug_entry();
    }

    if ($action === 'kb_migrate_rules') {
        af_kb_handle_migrate_rules();
    }

    if ($action === 'knowledgebase_entry') {
        af_kb_handle_entry_modal();
    }


    if ($action === 'kb_manage_categories') {
        af_kb_handle_manage_categories();
    }

    if ($action === 'kb_manage_categories_save') {
        af_kb_handle_manage_categories_save();
    }

    if ($action === 'kb_entry_categories_save') {
        af_kb_handle_entry_categories_save();
    }

    if ($action === 'kb_debug_entry_cats') {
        af_kb_handle_debug_entry_categories();
    }

    af_kb_handle_view();
}


function af_kb_render_category_tree_html(array $nodes, string $type, string $activeKey = '', int $level = 0): string
{
    $html = '';
    foreach ($nodes as $node) {
        $catKey = (string)($node['key'] ?? '');
        $title = af_kb_pick_text($node, 'title');
        if ($title === '') {
            $title = $catKey;
        }
        $isActive = $activeKey !== '' && $activeKey === $catKey;
        $childHtml = !empty($node['children']) ? af_kb_render_category_tree_html((array)$node['children'], $type, $activeKey, $level + 1) : '';
        $toggle = $childHtml !== '' ? '<span class="af-kb-cat-toggle" aria-hidden="true">▾</span>' : '';
        $html .= '<li class="af-kb-cat-node level-' . (int)$level . ($isActive ? ' is-active' : '') . '">'
            . '<a href="misc.php?action=kb&type=' . urlencode($type) . '&cat=' . urlencode($catKey) . '">' . $toggle . htmlspecialchars_uni($title) . '</a>'
            . ($childHtml !== '' ? '<ul>' . $childHtml . '</ul>' : '')
            . '</li>';
    }
    return $html;
}

function af_kb_handle_manage_categories(): void
{
    global $mybb, $lang, $db;

    if (!af_kb_categories_enabled() || !af_kb_cat_can_manage()) {
        error_no_permission();
    }

    $type = trim((string)$mybb->get_input('type'));
    if ($type === '') {
        error('Type is required');
    }

    $editCatId = (int)$mybb->get_input('edit_cat_id', MyBB::INPUT_INT);

    $tree = af_kb_cat_get_tree($type, false);
    $flat = af_kb_cat_flatten_tree_with_level($tree);
    $flatById = [];
    foreach ($flat as $cat) {
        $flatById[(int)$cat['cat_id']] = $cat;
    }

    $editing = $editCatId > 0 && isset($flatById[$editCatId]) ? $flatById[$editCatId] : null;

    $rows = '';
    foreach ($flat as $cat) {
        $catId = (int)$cat['cat_id'];
        $title = af_kb_pick_text($cat, 'title') ?: (string)$cat['key'];
        $indent = max(0, (int)($cat['level'] ?? 0)) * 22;
        $rows .= '<tr>'
            . '<td>' . $catId . '</td>'
            . '<td>' . htmlspecialchars_uni((string)$cat['key']) . '</td>'
            . '<td style="padding-left:' . (4 + $indent) . 'px">' . ($indent > 0 ? '↳ ' : '') . htmlspecialchars_uni($title) . '</td>'
            . '<td>' . (int)$cat['sortorder'] . '</td>'
            . '<td>' . ((int)$cat['active'] === 1 ? 'Yes' : 'No') . '</td>'
            . '<td>'
            . '<a class="af-kb-btn" href="misc.php?action=kb_manage_categories&type=' . urlencode($type) . '&edit_cat_id=' . $catId . '">Edit</a> '
            . '<form method="post" action="misc.php?action=kb_manage_categories_save" style="display:inline">'
            . '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '" />'
            . '<input type="hidden" name="type" value="' . htmlspecialchars_uni($type) . '" />'
            . '<input type="hidden" name="cat_id" value="' . $catId . '" />'
            . '<button class="af-kb-btn af-kb-btn--delete" name="mode" value="delete" onclick="return confirm(\'Delete category?\');">Delete</button>'
            . '</form>'
            . '</td>'
            . '</tr>';
    }

    $opts = '<option value="0">—</option>';
    $excludeIds = [];
    if ($editing) {
        $excludeIds = af_kb_cat_collect_descendant_ids((int)$editing['cat_id'], $flat);
    }
    foreach ($flat as $cat) {
        $candidateId = (int)$cat['cat_id'];
        if (in_array($candidateId, $excludeIds, true)) {
            continue;
        }
        $level = max(0, (int)($cat['level'] ?? 0));
        $prefix = str_repeat('— ', $level);
        $selected = $editing && (int)$editing['parent_id'] === $candidateId ? ' selected="selected"' : '';
        $opts .= '<option value="' . $candidateId . '"' . $selected . '>' . htmlspecialchars_uni($prefix . (af_kb_pick_text($cat, 'title') ?: (string)$cat['key'])) . '</option>';
    }

    $formCatId = $editing ? (int)$editing['cat_id'] : 0;
    $formKey = $editing ? htmlspecialchars_uni((string)$editing['key']) : '';
    $formTitleRu = $editing ? htmlspecialchars_uni((string)$editing['title_ru']) : '';
    $formTitleEn = $editing ? htmlspecialchars_uni((string)$editing['title_en']) : '';
    $formDescriptionRu = $editing ? htmlspecialchars_uni((string)$editing['description_ru']) : '';
    $formDescriptionEn = $editing ? htmlspecialchars_uni((string)$editing['description_en']) : '';
    $formSortorder = $editing ? (int)$editing['sortorder'] : 0;
    $formActive = !$editing || (int)$editing['active'] === 1 ? 'checked="checked"' : '';
    $titleSuffix = $editing ? ' (editing #' . $formCatId . ')' : '';
    $baseManageUrl = 'misc.php?action=kb_manage_categories&type=' . urlencode($type);
    $cancelEditLink = $editing ? '<a class="af-kb-btn" href="' . $baseManageUrl . '">Cancel edit</a>' : '';

    $content = '<div class="af-kb-header"><h1>Manage KB categories: ' . htmlspecialchars_uni($type) . '</h1></div>'
        . '<table class="tborder" cellspacing="1" cellpadding="6" width="100%"><tr><th>ID</th><th>Key</th><th>Title</th><th>Sort</th><th>Active</th><th>Actions</th></tr>' . $rows . '</table>'
        . '<form class="af-kb-form" method="post" action="misc.php?action=kb_manage_categories_save">'
        . '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '" />'
        . '<input type="hidden" name="type" value="' . htmlspecialchars_uni($type) . '" />'
        . '<input type="hidden" name="cat_id" value="' . $formCatId . '" />'
        . '<h3>Create / edit' . $titleSuffix . '</h3>'
        . '<label>Parent</label><select name="parent_id">' . $opts . '</select>'
        . '<label>Key</label><input type="text" name="key" value="' . $formKey . '" ' . ($editing ? 'readonly="readonly"' : '') . ' />'
        . '<label>Title RU</label><input type="text" name="title_ru" value="' . $formTitleRu . '" />'
        . '<label>Title EN</label><input type="text" name="title_en" value="' . $formTitleEn . '" />'
        . '<label>Description RU</label><textarea name="description_ru">' . $formDescriptionRu . '</textarea>'
        . '<label>Description EN</label><textarea name="description_en">' . $formDescriptionEn . '</textarea>'
        . '<label>Sortorder</label><input type="number" name="sortorder" value="' . $formSortorder . '" />'
        . '<label><input type="checkbox" name="active" value="1" ' . $formActive . ' /> Active</label>'
        . '<div><button class="af-kb-btn" name="mode" value="save">Save</button> ' . $cancelEditLink . '</div>'
        . '</form>';

    af_kb_render_fullpage($content, 'af_kb_edit_fullpage');
}

function af_kb_handle_manage_categories_save(): void
{
    global $mybb;

    if (!af_kb_categories_enabled() || !af_kb_cat_can_manage()) {
        error_no_permission();
    }

    if ($mybb->request_method !== 'post') {
        error_no_permission();
    }

    verify_post_check($mybb->get_input('my_post_key'));

    $mode = trim((string)$mybb->get_input('mode'));
    $type = trim((string)$mybb->get_input('type'));
    $catId = (int)$mybb->get_input('cat_id', MyBB::INPUT_INT);

    if ($mode === 'delete') {
        $res = af_kb_cat_delete($catId);
        if (empty($res['ok'])) {
            error((string)($res['error'] ?? 'Unable to delete category'));
        }
        redirect(af_kb_url(['action' => 'kb_manage_categories', 'type' => $type]), 'Category deleted');
    }

    if ($mode === 'edit') {
        redirect(af_kb_url(['action' => 'kb_manage_categories', 'type' => $type, 'edit_cat_id' => $catId]), 'Use the form below to update category data.');
    }

    $key = trim((string)$mybb->get_input('key'));
    if (!af_kb_cat_validate_key($key)) {
        error('Invalid category key');
    }

    $payload = [
        'parent_id' => (int)$mybb->get_input('parent_id', MyBB::INPUT_INT),
        'key' => $key,
        'title_ru' => trim((string)$mybb->get_input('title_ru')),
        'title_en' => trim((string)$mybb->get_input('title_en')),
        'description_ru' => trim((string)$mybb->get_input('description_ru')),
        'description_en' => trim((string)$mybb->get_input('description_en')),
        'sortorder' => (int)$mybb->get_input('sortorder', MyBB::INPUT_INT),
        'active' => (int)$mybb->get_input('active', MyBB::INPUT_INT) === 1 ? 1 : 0,
    ];

    if ($catId > 0) {
        $updateResult = af_kb_cat_update($catId, $payload);
        if (empty($updateResult['ok'])) {
            error((string)($updateResult['error'] ?? 'Unable to update category'));
        }
    } else {
        af_kb_cat_create($type, $payload['parent_id'], $payload['key'], $payload['title_ru'], $payload['title_en'], $payload['description_ru'], $payload['description_en'], $payload['sortorder'], $payload['active']);
    }

    redirect(af_kb_url(['action' => 'kb_manage_categories', 'type' => $type]), 'Saved');
}

function af_kb_handle_entry_categories_save(): void
{
    global $mybb, $db;

    if (!af_kb_categories_enabled() || !af_kb_can_edit()) {
        error_no_permission();
    }

    if ($mybb->request_method !== 'post') {
        error_no_permission();
    }

    verify_post_check($mybb->get_input('my_post_key'));

    $entryId = (int)$mybb->get_input('entry_id', MyBB::INPUT_INT);
    $entry = $db->fetch_array($db->simple_select('af_kb_entries', 'id,type', 'id=' . $entryId, ['limit' => 1]));
    if (!$entry) {
        af_kb_send_json(['ok' => false, 'error' => 'Entry not found'], 404);
    }

    $type = trim((string)$mybb->get_input('type'));
    if ($type !== '' && $type !== (string)$entry['type']) {
        af_kb_send_json(['ok' => false, 'error' => 'Type mismatch'], 400);
    }

    $catIds = $mybb->get_input('cat_ids', MyBB::INPUT_ARRAY);
    $primary = (int)$mybb->get_input('primary_cat_id', MyBB::INPUT_INT);
    if (!is_array($catIds)) {
        $catIds = [];
    }

    $catIds = array_values(array_filter(array_map('intval', $catIds), static function (int $value): bool {
        return $value > 0;
    }));

    $saveResult = af_kb_entry_set_categories($entryId, $catIds, $primary);
    if (empty($saveResult['ok'])) {
        af_kb_send_json(['ok' => false, 'error' => (string)($saveResult['error'] ?? 'Unable to save categories')], 400);
    }

    af_kb_debug_entry_cats_store_last_post([
        'entry_id' => $entryId,
        'type' => (string)$entry['type'],
        'cat_ids' => $catIds,
        'primary_cat_id' => $primary,
        'saved_at' => TIME_NOW,
    ]);

    af_kb_send_json(['ok' => true, 'message' => 'Сохранено']);
}

function af_kb_handle_debug_entry_categories(): void
{
    global $mybb, $db;

    if (!af_kb_categories_enabled() || !af_kb_cat_can_manage()) {
        error_no_permission();
    }

    $entryId = (int)$mybb->get_input('entry_id', MyBB::INPUT_INT);
    if ($entryId <= 0) {
        af_kb_send_json(['ok' => false, 'error' => 'entry_id is required'], 400);
    }

    $entry = $db->fetch_array($db->simple_select('af_kb_entries', 'id,type,`key`', 'id=' . $entryId, ['limit' => 1]));
    if (!$entry) {
        af_kb_send_json(['ok' => false, 'error' => 'Entry not found'], 404);
    }

    $linked = af_kb_entry_get_categories($entryId);

    af_kb_send_json([
        'ok' => true,
        'entry' => [
            'id' => (int)$entry['id'],
            'type' => (string)$entry['type'],
            'key' => (string)$entry['key'],
        ],
        'linked_cat_ids' => array_values(array_map('intval', (array)($linked['cat_ids'] ?? []))),
        'primary_cat_id' => (int)($linked['primary'] ?? 0),
        'last_post_payload' => af_kb_debug_entry_cats_read_last_post(),
    ]);
}


/* -------------------- VIEW HANDLERS -------------------- */

function af_kb_handle_entry_modal(): void
{
    global $mybb, $db, $lang;

    if (!af_kb_can_view()) {
        error($lang->af_kb_no_access ?? 'No access.');
    }

    $id = (int)$mybb->get_input('id', MyBB::INPUT_INT);
    if ($id <= 0) {
        error($lang->af_kb_not_found ?? 'Not found');
    }

    $entry = $db->fetch_array($db->simple_select('af_kb_entries', '*', 'id=' . $id, ['limit' => 1]));
    if (!$entry) {
        error($lang->af_kb_not_found ?? 'Not found');
    }

    $title = af_kb_pick_text($entry, 'title');
    if ($title === '') {
        $title = (string)($entry['key'] ?? ('#' . $id));
    }
    $short = af_kb_pick_text($entry, 'short');
    $body = af_kb_parse_message(af_kb_pick_text($entry, 'body'));

    if ((int)$mybb->get_input('ajax', MyBB::INPUT_INT) === 1) {
        echo '<div class="af-kb-modal-entry">'
            . '<h1>' . htmlspecialchars_uni($title) . '</h1>'
            . ($short !== '' ? '<p>' . af_kb_parse_message($short) . '</p>' : '')
            . '<div>' . $body . '</div>'
            . '</div>';
        exit;
    }

    redirect(af_kb_url(['type' => (string)$entry['type'], 'key' => (string)$entry['key']]));
}

function af_kb_handle_view(): void
{
    global $mybb, $db, $lang, $headerinclude, $header, $footer, $theme, $templates;

    if (!af_kb_can_view()) {
        error($lang->af_kb_no_access ?? 'No access.');
    }

    $type = trim((string)$mybb->get_input('type'));
    $key = trim((string)$mybb->get_input('key'));
    $query = trim((string)$mybb->get_input('q'));
    $catKey = trim((string)$mybb->get_input('cat'));
    $isAjax = (int)$mybb->get_input('ajax', MyBB::INPUT_INT) === 1;

    if ($type === '') {
        if (function_exists('add_breadcrumb')) {
            add_breadcrumb($lang->af_kb_catalog_title ?? 'Knowledge Base', 'misc.php?action=kb');
        }

        $activeMechanicKey = af_kb_get_catalog_active_mechanic_key();
        $typesWhere = "active=1 AND type<>'" . $db->escape_string(AF_KB_TYPE_RACE_VARIANT) . "'";
        $typesWhere .= ' AND ' . af_kb_sql_mechanic_filter('mechanic_key', $activeMechanicKey);
        if ($query !== '') {
            $safeQuery = $db->escape_string($query);
            $typesWhere .= " AND (title_ru LIKE '%{$safeQuery}%' OR title_en LIKE '%{$safeQuery}%')";
        }

        $page = max(1, (int)$mybb->get_input('page', MyBB::INPUT_INT));
        $perpage = AF_KB_PERPAGE;
        $total = (int)$db->fetch_field(
            $db->simple_select('af_kb_types', 'COUNT(*) AS cnt', $typesWhere),
            'cnt'
        );
        $start = ($page - 1) * $perpage;

        $types = [];
        $seenTypes = [];
        $q = $db->simple_select(
            'af_kb_types',
            '*',
            $typesWhere,
            [
                'order_by' => 'sortorder, type',
                'order_dir' => 'ASC',
                'limit' => $perpage,
                'limit_start' => $start,
            ]
        );
        while ($row = $db->fetch_array($q)) {
            $rowType = (string)($row['type_key'] ?? '');
            if ($rowType === '') {
                $rowType = (string)($row['type'] ?? '');
            }
            if ($rowType === '' || isset($seenTypes[$rowType])) {
                continue;
            }
            $rowMechanic = af_kb_get_type_mechanic_key($row);
            if (!af_kb_can_edit() && af_kb_is_internal_service_type($rowType, $rowMechanic)) {
                continue;
            }
            $row['type'] = $rowType;
            $seenTypes[$rowType] = true;
            $types[] = $row;
        }

        $rows = '';
        foreach ($types as $row) {
            $title = af_kb_pick_text($row, 'title');
            if ($title === '') {
                $title = $row['type'];
            }
            $desc = af_kb_pick_text($row, 'short');
            if ($desc === '') {
                $desc = af_kb_pick_text($row, 'description');
            }
            $iconHtml = af_kb_build_icon_html($row['icon_url'] ?? '', $row['icon_class'] ?? '');
            $iconWrap = $iconHtml !== '' ? '<span class="af-kb-icon">' . $iconHtml . '</span>' : '';
            $bgStyle = af_kb_build_bg_style($row['bg_tab_url'] ?? '');
            $styleAttr = $bgStyle !== '' ? ' style="' . $bgStyle . '"' : '';
            $bgClass = $bgStyle !== '' ? ' af-kb-tab--with-bg' : '';
            $rows .= '<a class="af-kb-tab'.$bgClass.'"'.$styleAttr.' href="misc.php?action=kb&type='.htmlspecialchars_uni($row['type']).'">'
                . '<span class="af-kb-tab-title">'.$iconWrap.htmlspecialchars_uni($title).'</span>'
                . '<span class="af-kb-tab-desc">'.af_kb_parse_message($desc).'</span>'
                . '</a>';
        }

        $paginationUrl = 'misc.php?action=kb';
        if ($query !== '') {
            $paginationUrl .= '&q=' . urlencode($query);
        }
        $kb_pagination = $total > $perpage && function_exists('multipage')
            ? multipage($total, $perpage, $page, $paginationUrl)
            : '';

        $kb_page_title = $lang->af_kb_catalog_title ?? 'Knowledge Base';
        $kb_types_rows = $rows;
        $kb_query = htmlspecialchars_uni($query);
        $kb_can_edit = af_kb_can_edit() ? '1' : '0';
        $kb_create_link = af_kb_can_manage_types()
            ? '<a class="af-kb-btn af-kb-btn--create af-kb-btn-create" href="misc.php?action=kb_type_edit">'.htmlspecialchars_uni($lang->af_kb_type_create ?? 'Create category').'</a>'
            : '';
        $kb_help_link = af_kb_can_edit()
            ? '<a class="af-kb-help-link" href="misc.php?action=kb_help" title="'.htmlspecialchars_uni($lang->af_kb_help_title ?? 'KB help').'"><i class="fa-regular fa-circle-question"></i></a>'
            : '';
        $kb_page_bg = '';
        $kb_body_style = '';
        $af_kb_content = '';
        eval("\$af_kb_content = \"" . af_kb_get_template('knowledgebase_catalog') . "\";");
        eval("\$page = \"" . af_kb_get_template('knowledgebase_page') . "\";");
        output_page($page);
        exit;
    }

    if ($key === '') {
        $requestedMechanic = af_kb_get_type_mechanic_key($type);
        if (!af_kb_can_edit() && af_kb_is_internal_service_type($type, $requestedMechanic)) {
            error($lang->af_kb_not_found ?? 'Not found');
        }

        $escapedType = $db->escape_string($type);
        $where = "e.type='{$escapedType}'";
        if (!af_kb_can_edit()) {
            $where .= " AND e.active=1";
        }
        if ($query !== '') {
            $safeQuery = $db->escape_string($query);
            $where .= " AND (e.title_ru LIKE '%{$safeQuery}%' OR e.title_en LIKE '%{$safeQuery}%')";
        }

        $catFilterIds = [];
        $catTreeHtml = '';
        $catSidebarTreeHtml = '';
        $currentCatId = 0;
        if (af_kb_categories_enabled()) {
            $onlyActiveCats = !af_kb_can_edit();
            $catFlat = af_kb_cat_get_flat($type, $onlyActiveCats);
            $catTreeNodes = af_kb_cat_get_tree($type, $onlyActiveCats);
            $catTreeBody = af_kb_render_category_tree_html($catTreeNodes, $type, $catKey);
            if ($catTreeBody !== '') {
                $catTreeHtml = '<ul class="af-kb-cat-tree">' . $catTreeBody . '</ul>';
            }

            $sidebarNodes = $catTreeNodes;
            if ($catKey !== '') {
                $currentCat = null;
                foreach ($catFlat as $flatRow) {
                    if ((string)($flatRow['key'] ?? '') === $catKey) {
                        $currentCat = $flatRow;
                        break;
                    }
                }

                if ($currentCat) {
                    $currentCatId = (int)$currentCat['cat_id'];
                    $catFilterIds = af_kb_cat_collect_descendant_ids($currentCatId, $catFlat);

                    $parentId = (int)($currentCat['parent_id'] ?? 0);
                    $rootCatId = $parentId > 0 ? $parentId : $currentCatId;
                    if ($rootCatId > 0 && isset($catFlat[$rootCatId])) {
                        $rootNode = $catFlat[$rootCatId];
                        $rootNode['cat_id'] = $rootCatId;
                        $rootNode['parent_id'] = (int)($rootNode['parent_id'] ?? 0);
                        $rootNode['children'] = [];
                        foreach ($catFlat as $flatCatId => $flatCat) {
                            if ((int)($flatCat['parent_id'] ?? 0) === $rootCatId) {
                                $flatCat['cat_id'] = (int)$flatCatId;
                                $flatCat['parent_id'] = (int)($flatCat['parent_id'] ?? 0);
                                $flatCat['children'] = [];
                                $rootNode['children'][] = $flatCat;
                            }
                        }
                        if (!empty($rootNode['children'])) {
                            $sidebarNodes = [$rootNode];
                        }
                    }
                }
            }

            $sidebarBody = af_kb_render_category_tree_html($sidebarNodes, $type, $catKey);
            if ($sidebarBody !== '') {
                $catSidebarTreeHtml = '<ul class="af-kb-cat-tree">' . $sidebarBody . '</ul>';
            }
        }

        if (!empty($catFilterIds)) {
            $where .= ' AND ec.cat_id IN (' . implode(',', array_map('intval', $catFilterIds)) . ')';
        }

        $page = max(1, (int)$mybb->get_input('page', MyBB::INPUT_INT));
        $perpage = AF_KB_PERPAGE;
        $start = ($page - 1) * $perpage;

        $join = !empty($catFilterIds) ? ' LEFT JOIN ' . TABLE_PREFIX . 'af_kb_entry_categories ec ON ec.entry_id=e.id ' : '';
        $totalRow = $db->fetch_array($db->write_query('SELECT COUNT(DISTINCT e.id) AS cnt FROM ' . TABLE_PREFIX . 'af_kb_entries e' . $join . ' WHERE ' . $where));
        $total = (int)($totalRow['cnt'] ?? 0);

        $entries = [];
        $sql = 'SELECT DISTINCT e.* FROM ' . TABLE_PREFIX . 'af_kb_entries e' . $join . ' WHERE ' . $where . ' ORDER BY e.sortorder ASC, e.title_ru ASC, e.title_en ASC LIMIT ' . $start . ',' . $perpage;
        $q = $db->write_query($sql);
        while ($row = $db->fetch_array($q)) {
            if (!af_kb_entry_visible_in_context($row, $query !== '' ? 'search' : 'catalog', af_kb_can_edit())) {
                continue;
            }
            $entries[] = $row;
        }

        $typeRow = af_kb_find_type_row($type);
        $typeTitle = $type;
        $typeDesc = '';
        if ($typeRow) {
            $typeTitle = af_kb_pick_text($typeRow, 'title') ?: $type;
            $typeDesc = af_kb_pick_text($typeRow, 'description');
        }

        $kb_banner = '';
        $kb_type_banner = '';
        $typeBannerUrl = $typeRow ? af_kb_sanitize_url((string)($typeRow['banner_url'] ?? '')) : '';
        if ($typeBannerUrl !== '') {
            $kb_banner = '<img class="af-kb-banner" src="' . htmlspecialchars_uni($typeBannerUrl) . '" alt="" loading="lazy" />';
            $kb_type_banner = $kb_banner;
        }

        $typeIconUrl = $typeRow ? ($typeRow['icon_url'] ?? '') : '';
        $typeIconClass = $typeRow ? ($typeRow['icon_class'] ?? '') : '';
        $rows = '';
        foreach ($entries as $row) {
            $title = af_kb_pick_text($row, 'title');
            if ($title === '') {
                $title = $row['key'];
            }
            $short = af_kb_parse_message(af_kb_pick_text($row, 'short'));
            $entryUi = af_kb_get_entry_ui($row);
            $iconUrl = $entryUi['icon_url'] ?: $typeIconUrl;
            $iconClass = $entryUi['icon_class'] ?: $typeIconClass;
            $iconHtml = af_kb_build_icon_html($iconUrl, $iconClass);
            $iconWrap = $iconHtml !== '' ? '<span class="af-kb-icon">' . $iconHtml . '</span>' : '';
            $entryBgStyle = af_kb_build_bg_style($entryUi['background_tab_url'] ?? '');
            $entryStyle = $entryBgStyle !== '' ? ' style="' . $entryBgStyle . '"' : '';
            $entryClass = $entryBgStyle !== '' ? ' af-kb-entry--with-bg' : '';
            $rows .= '<div class="af-kb-entry'.$entryClass.'"'.$entryStyle.'>
                <h3><a href="misc.php?action=kb&type='.htmlspecialchars_uni($row['type']).'&key='.htmlspecialchars_uni($row['key']).'">'.$iconWrap.htmlspecialchars_uni($title).'</a></h3>
                <div class="af-kb-entry-short">'.$short.'</div>
            </div>';
        }

        if (function_exists('add_breadcrumb')) {
            add_breadcrumb($lang->af_kb_catalog_title ?? 'Knowledge Base', 'misc.php?action=kb');
            add_breadcrumb($typeTitle, 'misc.php?action=kb&type=' . urlencode($type));
        }

        $typeIconHtml = $typeRow ? af_kb_build_icon_html($typeRow['icon_url'] ?? '', $typeRow['icon_class'] ?? '') : '';
        $kb_type_icon = $typeIconHtml !== '' ? '<span class="af-kb-icon">' . $typeIconHtml . '</span>' : '';
        $kb_page_title = htmlspecialchars_uni($typeTitle);
        $kb_type_title = htmlspecialchars_uni($typeTitle);
        $kb_type_description = af_kb_parse_message($typeDesc);
        $kb_type_value = htmlspecialchars_uni($type);
        $kb_query = htmlspecialchars_uni($query);
        $kb_entries_rows = $rows;
        $kb_entries_style = '';
        $kb_entries_class = '';
        $kb_categories_tree = $catTreeHtml ?? '';
        $kb_categories_tree_sidebar = $catSidebarTreeHtml ?: $kb_categories_tree;
        $kb_categories_enabled = af_kb_categories_enabled() ? '1' : '0';
        $uiPositionRaw = (string)af_kb_get_setting('af_kb_categories_ui_position', af_kb_get_setting('af_kb_categories_ui', 'sidebar'));
        $kb_ui_position = $uiPositionRaw === 'top' ? 'top' : 'sidebar';

        $has_sidebar_tree = $kb_categories_tree_sidebar !== '';
        $has_top_tree = $kb_categories_tree !== '';
        $sidebar_enabled = $kb_ui_position === 'sidebar' && $has_sidebar_tree;
        $top_enabled = $kb_ui_position === 'top' && $has_top_tree;
        $kb_layout_class = 'af-kb-layout--full';
        if ($sidebar_enabled) {
            $kb_layout_class = 'af-kb-layout--sidebar';
        } elseif ($top_enabled) {
            $kb_layout_class = 'af-kb-layout--top';
        }
        $kb_sidebar_html = $sidebar_enabled ? '<aside class="af-kb-sidebar">' . $kb_categories_tree_sidebar . '</aside>' : '';
        $kb_topcats_html = $top_enabled ? '<div class="af-kb-topcats">' . $kb_categories_tree . '</div>' : '';
        $paginationUrl = 'misc.php?action=kb&type=' . urlencode($type);
        if ($query !== '') {
            $paginationUrl .= '&q=' . urlencode($query);
        }
        if ($catKey !== '') {
            $paginationUrl .= '&cat=' . urlencode($catKey);
        }
        $kb_pagination = $total > $perpage && function_exists('multipage')
            ? multipage($total, $perpage, $page, $paginationUrl)
            : '';
        $kb_can_edit = af_kb_can_edit() ? '1' : '0';
        $actions = [];
        if (af_kb_can_edit()) {
            $actions[] = '<a class="af-kb-btn af-kb-btn--create af-kb-btn-create" href="misc.php?action=kb_edit&type='.htmlspecialchars_uni($type).'">'.htmlspecialchars_uni($lang->af_kb_create ?? 'Create').'</a>';
        }
        if ($type === AF_KB_TYPE_RACE) {
            $actions[] = '<a class="af-kb-btn" href="misc.php?action=kb&type=' . htmlspecialchars_uni(AF_KB_TYPE_RACE_VARIANT) . '">Разновидности рас</a>';
        } elseif ($type === AF_KB_TYPE_RACE_VARIANT) {
            $actions[] = '<a class="af-kb-btn" href="misc.php?action=kb&type=' . htmlspecialchars_uni(AF_KB_TYPE_RACE) . '">К расам</a>';
        }
        if (af_kb_cat_can_manage() && af_kb_categories_enabled()) {
            $actions[] = '<a class="af-kb-btn" href="misc.php?action=kb_manage_categories&type=' . htmlspecialchars_uni($type) . '">Manage categories</a>';
        }
        if (af_kb_can_manage_types()) {
            $actions[] = '<a class="af-kb-btn af-kb-btn--edit af-kb-btn-edit" href="misc.php?action=kb_type_edit&type='.htmlspecialchars_uni($type).'">'.htmlspecialchars_uni($lang->af_kb_type_edit ?? 'Edit category').'</a>';
            $confirm = htmlspecialchars_uni($lang->af_kb_type_delete_confirm ?? 'Delete category?');
            $actions[] = '<a class="af-kb-btn af-kb-btn--delete af-kb-btn-delete" href="misc.php?action=kb_type_delete&type='.htmlspecialchars_uni($type).'&my_post_key='.htmlspecialchars_uni($mybb->post_code).'" onclick="return confirm(\''.$confirm.'\');">'.htmlspecialchars_uni($lang->af_kb_type_delete ?? 'Delete category').'</a>';
        }
        $kb_help_link = af_kb_can_edit()
            ? '<a class="af-kb-help-link" href="misc.php?action=kb_help" title="'.htmlspecialchars_uni($lang->af_kb_help_title ?? 'KB help').'"><i class="fa-regular fa-circle-question"></i></a>'
            : '';
        $kb_type_actions = implode(' ', $actions);
        $kb_page_bg = '';
        $kb_body_style = af_kb_build_body_bg_style($typeRow ? ($typeRow['bg_url'] ?? '') : '');
        $af_kb_content = '';
        eval("\$af_kb_content = \"" . af_kb_get_template('knowledgebase_list') . "\";");
        eval("\$page = \"" . af_kb_get_template('knowledgebase_page') . "\";");
        output_page($page);
        exit;
    }

    $typeRow = af_kb_find_type_row($type);
    if (!$typeRow) {
        error($lang->af_kb_not_found ?? 'Not found');
    }

    $escapedType = $db->escape_string($type);
    $escapedKey = $db->escape_string($key);
    $where = "type='{$escapedType}' AND `key`='{$escapedKey}'";
    if (!af_kb_can_edit()) {
        $where .= " AND active=1";
    }

    $entry = $db->fetch_array($db->simple_select('af_kb_entries', '*', $where, ['limit' => 1]));
    if (!$entry) {
        error($lang->af_kb_not_found ?? 'Not found');
    }
    if (!af_kb_entry_visible_in_context($entry, 'catalog', af_kb_can_edit())) {
        error($lang->af_kb_not_found ?? 'Not found');
    }

    $typeTitle = af_kb_pick_text($typeRow, 'title') ?: $type;

    $entryLocalized = kb_entry_localize($entry);
    $title = $entryLocalized['title'];
    if ($title === '') {
        $title = $entry['key'];
    }

    $short = af_kb_parse_message($entryLocalized['short']);
    $isRu = af_kb_is_ru();
    $body = af_kb_render_entry_ui($entry, $typeRow, $isRu);
    if ($body === '') {
        $body = af_kb_parse_message($entryLocalized['body']);
    }

    if (function_exists('add_breadcrumb')) {
        add_breadcrumb($lang->af_kb_catalog_title ?? 'Knowledge Base', 'misc.php?action=kb');
        add_breadcrumb($typeTitle, 'misc.php?action=kb&type=' . urlencode($type));
        add_breadcrumb($title, 'misc.php?action=kb&type=' . urlencode($type) . '&key=' . urlencode($key));
    }

    $blocks = [];
    $bq = $db->simple_select('af_kb_blocks', '*', 'entry_id='.(int)$entry['id'], ['order_by' => 'sortorder, id', 'order_dir' => 'ASC']);
    while ($row = $db->fetch_array($bq)) {
        if (!$row['active'] && !af_kb_can_edit()) {
            continue;
        }
        $blocks[] = $row;
    }

    $kb_blocks = '';
    foreach ($blocks as $block) {
        if (af_kb_is_technical_block($block)) {
            continue;
        }
        $blockIconHtml = af_kb_build_icon_html($block['icon_url'] ?? '', $block['icon_class'] ?? '');
        $block_icon = $blockIconHtml !== '' ? '<span class="af-kb-icon">' . $blockIconHtml . '</span>' : '';
        $block_title = htmlspecialchars_uni(af_kb_pick_text($block, 'title'));
        $block_content = af_kb_parse_message(af_kb_pick_text($block, 'content'));
        $block_data_table = '';
        $blockData = af_kb_decode_json((string)($block['data_json'] ?? '{}'));
        if ($type === 'theme' && (string)($block['block_key'] ?? '') === 'knowledges') {
            $timeline = [];
            $progression = isset($blockData['progression']) && is_array($blockData['progression']) ? $blockData['progression'] : [];
            foreach ($progression as $step) {
                if (!is_array($step)) { continue; }
                $lvl = (int)($step['level'] ?? 0);
                $stepTitle = (string)($step['title_ru'] ?? $step['title_en'] ?? '');
                $timeline[] = '<li><strong>Lv ' . $lvl . '</strong> ' . htmlspecialchars_uni($stepTitle) . '</li>';
            }
            if ($timeline) {
                $block_data_table = '<ul class="af-kb-timeline">' . implode('', $timeline) . '</ul>';
            }
        }
        if ((string)($block['block_key'] ?? '') === 'bonus' && isset($blockData['effects']) && is_array($blockData['effects'])) {
            $cards = [];
            foreach ($blockData['effects'] as $effect) {
                if (!is_array($effect)) { continue; }
                $cards[] = '<li>' . htmlspecialchars_uni((string)($effect['op'] ?? 'effect')) . ': ' . htmlspecialchars_uni((string)($effect['value'] ?? '')) . '</li>';
            }
            if ($cards) {
                $block_data_table .= '<ul class="af-kb-bonus-list">' . implode('', $cards) . '</ul>';
            }
        }
        eval("\$kb_blocks .= \"" . af_kb_get_template('knowledgebase_blocks_item') . "\";");
    }

    $relations = [];
    $rq = $db->simple_select('af_kb_relations', '*', "from_type='{$escapedType}' AND from_key='{$escapedKey}'", ['order_by' => 'sortorder, id', 'order_dir' => 'ASC']);
    while ($row = $db->fetch_array($rq)) {
        $relations[] = $row;
    }

    $grouped = [];
    foreach ($relations as $rel) {
        $grouped[$rel['rel_type']][] = $rel;
    }

    $kb_relations = '';
    foreach ($grouped as $relType => $items) {
        $kb_rel_items = '';
        foreach ($items as $rel) {
            $toTitle = $rel['to_key'];
            $rel_icon = '';
            $target = $db->fetch_array(
                $db->simple_select(
                    'af_kb_entries',
                    '*',
                    "type='".$db->escape_string($rel['to_type'])."' AND `key`='".$db->escape_string($rel['to_key'])."'",
                    ['limit' => 1]
                )
            );
            if ($target) {
                $toTitle = af_kb_pick_text($target, 'title');
                if ($toTitle === '') {
                    $toTitle = $target['key'];
                }
                $targetUi = af_kb_get_entry_ui($target);
                $relIconHtml = af_kb_build_icon_html($targetUi['icon_url'], $targetUi['icon_class']);
                if ($relIconHtml !== '') {
                    $rel_icon = '<span class="af-kb-icon">' . $relIconHtml . '</span>';
                }
            }
            $rel_to_type = htmlspecialchars_uni($rel['to_type']);
            $rel_to_key = htmlspecialchars_uni($rel['to_key']);
            $rel_title = htmlspecialchars_uni($toTitle);
            $rel_meta_details = '';
            if (af_kb_is_staff_viewer() && !empty($rel['meta_json'])) {
                $rel_meta_details = af_kb_render_tech_details(
                    $lang->af_kb_technical_data ?? 'Technical data',
                    $rel['meta_json']
                );
            }
            eval("\$kb_rel_items .= \"" . af_kb_get_template('knowledgebase_rel_item') . "\";");
        }
        $relTypeLabel = af_kb_relation_type_label((string)$relType, $isRu);
        $kb_relations .= '<div class="af-kb-rel-group"><h4>'.htmlspecialchars_uni($relTypeLabel).'</h4><ul>'.$kb_rel_items.'</ul></div>';
    }

    $entryUi = af_kb_get_entry_ui($entry);
    $entryIconHtml = af_kb_build_icon_html($entryUi['icon_url'], $entryUi['icon_class']);
    $kb_entry_icon = $entryIconHtml !== '' ? '<span class="af-kb-icon">' . $entryIconHtml . '</span>' : '';
    $kb_page_title = htmlspecialchars_uni($title);
    $kb_title = htmlspecialchars_uni($title);
    $kb_short = '';
    $kb_entry_body = $body;
    $kb_banner = '';
    $bannerUrl = af_kb_sanitize_url((string)($entry['banner_url'] ?? ''));
    if ($bannerUrl !== '') {
        $kb_banner = '<img class="af-kb-banner" src="' . htmlspecialchars_uni($bannerUrl) . '" alt="" loading="lazy" />';
    }
    $kb_can_edit = af_kb_can_edit() ? '1' : '0';
    $kb_edit_link = af_kb_can_edit() ? '<a class="af-kb-btn af-kb-btn--edit af-kb-btn-edit" href="misc.php?action=kb_edit&type='.htmlspecialchars_uni($type).'&key='.htmlspecialchars_uni($key).'">'.htmlspecialchars_uni($lang->af_kb_edit ?? 'Edit').'</a>' : '';
    $kb_delete_form = '';
    if (af_kb_can_edit()) {
        $deleteLabel = $lang->af_kb_delete_entry ?? 'Delete entry';
        $kb_delete_form = '<form class="af-kb-delete-form" method="post" action="misc.php?action=kb_edit&amp;type='
            . htmlspecialchars_uni($type) . '&amp;key=' . htmlspecialchars_uni($key) . '">'
            . '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '" />'
            . '<button type="submit" name="kb_delete" value="1" class="af-kb-btn af-kb-btn--delete af-kb-btn-delete"'
            . ' onclick="return confirm(\'' . htmlspecialchars_uni($lang->af_kb_delete_confirm ?? 'Delete entry?') . '\');">'
            . htmlspecialchars_uni($deleteLabel) . '</button></form>';
    }
    $kb_help_link = af_kb_can_edit()
        ? '<a class="af-kb-help-link" href="misc.php?action=kb_help" title="'.htmlspecialchars_uni($lang->af_kb_help_title ?? 'KB help').'"><i class="fa-regular fa-circle-question"></i></a>'
        : '';
    $kb_meta_details = '';
    if (af_kb_is_staff_viewer()) {
        $metaDetails = af_kb_render_tech_details(
            'Meta JSON',
            (string)($entry['meta_json'] ?? ''),
            $lang->af_kb_copy_json ?? 'Copy JSON'
        );
        $kb_meta_details = $metaDetails;

        if (af_kb_is_admin() && af_kb_can_edit() && (int)$mybb->get_input('debug_rules', MyBB::INPUT_INT) === 1) {
            $rules = af_kb_extract_rules_from_meta_json((string)($entry['meta_json'] ?? ''));
            $rulesJson = is_array($rules) ? json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}';
            if ($rulesJson === false || $rulesJson === '') {
                $rulesJson = '{}';
            }
            $kb_meta_details .= af_kb_render_tech_details(
                'Data JSON (debug)',
                $rulesJson,
                $lang->af_kb_copy_json ?? 'Copy JSON'
            );
        }
    }
    $kb_tech_details = '';
    if (af_kb_is_staff_viewer()) {
        $kb_tech_details = af_kb_render_tech_note_details(
            $lang->af_kb_tech_label ?? 'Technical note',
            af_kb_pick_text($entry, 'tech')
        );
    }
    $kb_page_bg = '';
    $bodyBgUrl = $entryUi['background_url'] ?: ($typeRow ? ($typeRow['bg_url'] ?? '') : '');
    $kb_body_style = af_kb_build_body_bg_style($bodyBgUrl);
    $af_kb_content = '';
    eval("\$af_kb_content = \"" . af_kb_get_template('knowledgebase_view') . "\";");

    if ($isAjax) {
        echo $af_kb_content;
        exit;
    }

    eval("\$page = \"" . af_kb_get_template('knowledgebase_page') . "\";");
    output_page($page);
    exit;
}

function af_kb_handle_edit(): void
{
    global $mybb, $db, $lang;

    if (!af_kb_can_edit()) {
        error_no_permission();
    }

    $type = trim((string)$mybb->get_input('type'));
    $key = trim((string)$mybb->get_input('key'));

    $entry = null;
    if ($type !== '' && $key !== '') {
        $entry = $db->fetch_array(
            $db->simple_select(
                'af_kb_entries',
                '*',
                "type='".$db->escape_string($type)."' AND `key`='".$db->escape_string($key)."'",
                ['limit' => 1]
            )
        );
    }

    $errors = [];

    if ($mybb->request_method === 'post') {
        verify_post_check($mybb->get_input('my_post_key'));

        $type = trim((string)$mybb->get_input('type'));
        $key = trim((string)$mybb->get_input('key'));
        $mechanicKey = af_kb_get_type_mechanic_key($type);

        if ((int)$mybb->get_input('kb_delete', MyBB::INPUT_INT) === 1) {
            if ($type === '' || $key === '') {
                error($lang->af_kb_not_found ?? 'Not found');
            }
            $existing = $db->fetch_array(
                $db->simple_select(
                    'af_kb_entries',
                    '*',
                    "type='".$db->escape_string($type)."' AND `key`='".$db->escape_string($key)."'",
                    ['limit' => 1]
                )
            );
            if (!$existing) {
                error($lang->af_kb_not_found ?? 'Not found');
            }

            $db->delete_query('af_kb_blocks', 'entry_id='.(int)$existing['id']);
            $db->delete_query('af_kb_relations', "from_type='".$db->escape_string($type)."' AND from_key='".$db->escape_string($key)."'");
            $db->delete_query('af_kb_entries', 'id='.(int)$existing['id']);

            $db->insert_query('af_kb_log', [
                'uid'      => (int)$mybb->user['uid'],
                'action'   => $db->escape_string('delete'),
                'type'     => $db->escape_string($type),
                'key'      => $db->escape_string($key),
                'diff_json'=> $db->escape_string('{}'),
                'dateline' => TIME_NOW,
            ]);

            redirect(af_kb_url(['type' => $type]), 'Deleted');
        }

        if ($type === '') {
            $errors[] = 'Type is required.';
        }
        if ($key === '') {
            $errors[] = 'Key is required.';
        }
        if ($key !== '' && !preg_match(AF_KB_KEY_PATTERN, $key)) {
            $errors[] = $lang->af_kb_invalid_key ?? 'Invalid key.';
        }

        $metaJson = trim((string)$mybb->get_input('meta_json'));
        $kbRulesJsonRaw = trim((string)$mybb->get_input('kb_rules_json'));
        $hasPostedRulesJson = ($kbRulesJsonRaw !== '');
        $postedRulesPayload = [];

        $entryIconClass = af_kb_sanitize_icon_class((string)$mybb->get_input('icon_class'));
        $entryIconUrl = af_kb_sanitize_url((string)$mybb->get_input('icon_url'));
        $entryBannerUrl = af_kb_sanitize_url((string)$mybb->get_input('banner_url'));
        $entryBgUrl = af_kb_sanitize_url((string)$mybb->get_input('background_url'));
        $entryBgTabUrl = af_kb_sanitize_url((string)$mybb->get_input('entry_background_tab_url'));
        if (!af_kb_validate_json($metaJson)) {
            $errors[] = $lang->af_kb_invalid_json ?? 'Invalid JSON.';
        }
        if ($hasPostedRulesJson) {
            if (!af_kb_validate_json($kbRulesJsonRaw)) {
                $errors[] = 'Invalid rules JSON.';
            } else {
                $postedRulesPayload = af_kb_decode_json($kbRulesJsonRaw);
            }
        }        

        $metaPayload = af_kb_decode_json($metaJson);
        $metaBeforeRaw = '';
        if (!empty($entry['id'])) {
            $metaBeforeRow = $db->fetch_array($db->simple_select('af_kb_entries', 'meta_json', 'id='.(int)$entry['id'], ['limit' => 1]));
            $metaBeforeRaw = (string)($metaBeforeRow['meta_json'] ?? '');
        }
        $metaBefore = af_kb_decode_json($metaBeforeRaw);
        if (!is_array($metaBefore)) {
            $metaBefore = [];
        }

        if (!is_array($metaPayload)) {
            $metaPayload = [];
        }

        $metaPayload = array_replace_recursive($metaBefore, $metaPayload);
        if (empty($metaPayload['schema']) || (string)$metaPayload['schema'] === 'af_kb.meta.v1') {
            $metaPayload['schema'] = 'af_kb.meta.v2';
        }
        $metaPayload = af_kb_cleanup_meta_payload($metaPayload);

        $entryRulesRaw = [];

        if ($hasPostedRulesJson) {
            $entryRulesRaw = is_array($postedRulesPayload) ? $postedRulesPayload : [];
            $metaPayload['rules'] = $entryRulesRaw;
        } elseif (is_array($metaPayload['rules'] ?? null)) {
            $entryRulesRaw = (array)$metaPayload['rules'];
        }

        if (af_kb_is_admin() && (int)$mybb->get_input('kb_debug_rules', MyBB::INPUT_INT) === 1) {
            $rawGrantsBeforeNormalize = isset($entryRulesRaw['grants']) && is_array($entryRulesRaw['grants'])
                ? $entryRulesRaw['grants']
                : [];
            error_log('[af_kb_rules_save_debug_raw_grants] ' . json_encode([
                'entry_id' => (int)($entry['id'] ?? 0),
                'type' => $type,
                'grants' => $rawGrantsBeforeNormalize,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $itemKind = af_kb_normalize_item_kind((string)($metaPayload['item_kind'] ?? $mybb->get_input('item_kind')));
        if ($type === 'item') {
            $preparedItem = af_kb_prepare_item_payload_for_save($metaPayload, $itemKind);
            $metaPayload = (array)($preparedItem['meta'] ?? $metaPayload);
            $entryRulesRaw = (array)($preparedItem['rules'] ?? []);
            $metaPayload['rules'] = $entryRulesRaw;
            $itemKind = (string)($preparedItem['item_kind'] ?? $itemKind);
        }

        $entryDataJsonNormalized = af_kb_normalize_json(
            json_encode($entryRulesRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
        );

        if ($type === 'item') {
            if ($itemKind === '') {
                $itemKind = 'gear';
            }
            $metaPayload['item_kind'] = $itemKind;

            $schema = af_kb_get_type_schema('item', $mechanicKey);
            if ($itemKind !== '') {
                $schema = af_kb_apply_overlay_to_schema($schema, af_kb_get_item_kind_overlay($itemKind));
            }

            foreach ((array)($schema['fields'] ?? []) as $field) {
                if (empty($field['required']) || empty($field['path'])) {
                    continue;
                }

                if ((string)$field['path'] === 'item.equip.slot') {
                    continue;
                }

                $parts = explode('.', (string)$field['path']);
                $present = false;
                $cursor = null;

                foreach ([$metaPayload, (array)($metaPayload['rules'] ?? [])] as $candidateRoot) {
                    $cursorCandidate = $candidateRoot;
                    $candidatePresent = true;

                    foreach ($parts as $part) {
                        if (!is_array($cursorCandidate) || !array_key_exists($part, $cursorCandidate)) {
                            $candidatePresent = false;
                            break;
                        }
                        $cursorCandidate = $cursorCandidate[$part];
                    }

                    if ($candidatePresent) {
                        $present = true;
                        $cursor = $cursorCandidate;
                        break;
                    }
                }

                if (!$present || $cursor === '' || $cursor === null || (is_array($cursor) && $cursor === [])) {
                    $errors[] = 'Required meta field missing: ' . $field['path'];
                }
            }
        }

        $blocksInput = $mybb->get_input('blocks', MyBB::INPUT_ARRAY);
        $relationsInput = $mybb->get_input('relations', MyBB::INPUT_ARRAY);

        $parsedBlocks = [];
        if (is_array($blocksInput)) {
            foreach ($blocksInput as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $blockKey = trim((string)($block['block_key'] ?? ''));
                if (strtolower($blockKey) === 'data') {
                    continue;
                }
                $titleRu = trim((string)($block['title_ru'] ?? ''));
                $titleEn = trim((string)($block['title_en'] ?? ''));
                $contentRu = trim((string)($block['content_ru'] ?? ''));
                $contentEn = trim((string)($block['content_en'] ?? ''));
                $dataJson = trim((string)($block['data_json'] ?? ''));
                $blockIconClass = af_kb_sanitize_icon_class((string)($block['icon_class'] ?? ''));
                $blockIconUrl = af_kb_sanitize_url((string)($block['icon_url'] ?? ''));
                $active = !empty($block['active']) ? 1 : 0;
                $sortorder = (int)($block['sortorder'] ?? 0);

                $dataJsonEmpty = af_kb_is_empty_json($dataJson);
                if ($blockKey === '' && $titleRu === '' && $titleEn === '' && $contentRu === '' && $contentEn === '' && $dataJsonEmpty) {
                    continue;
                }

                if (!$dataJsonEmpty && !af_kb_validate_json($dataJson)) {
                    $errors[] = $lang->af_kb_invalid_json ?? 'Invalid JSON.';
                    break;
                }

                $parsedBlocks[] = [
                    'block_key'   => $blockKey,
                    'title_ru'    => $titleRu,
                    'title_en'    => $titleEn,
                    'content_ru'  => $contentRu,
                    'content_en'  => $contentEn,
                    'data_json'   => af_kb_normalize_json($dataJson),
                    'icon_class'  => $blockIconClass,
                    'icon_url'    => $blockIconUrl,
                    'active'      => $active,
                    'sortorder'   => $sortorder,
                ];
            }
        }

        $parsedRelations = [];
        if (is_array($relationsInput)) {
            foreach ($relationsInput as $rel) {
                if (!is_array($rel)) {
                    continue;
                }
                $relType = trim((string)($rel['rel_type'] ?? ''));
                $toType = trim((string)($rel['to_type'] ?? ''));
                $toKey = trim((string)($rel['to_key'] ?? ''));
                $meta = trim((string)($rel['meta_json'] ?? ''));
                $sortorder = (int)($rel['sortorder'] ?? 0);

                $metaEmpty = af_kb_is_empty_json($meta);
                if ($relType === '' && $toType === '' && $toKey === '' && $metaEmpty) {
                    continue;
                }

                if ($toKey !== '' && !preg_match(AF_KB_KEY_PATTERN, $toKey)) {
                    $errors[] = $lang->af_kb_invalid_key ?? 'Invalid key.';
                    break;
                }

                if (!$metaEmpty && !af_kb_validate_json($meta)) {
                    $errors[] = $lang->af_kb_invalid_json ?? 'Invalid JSON.';
                    break;
                }

                if ($relType === '' || $toType === '' || $toKey === '') {
                    $errors[] = 'Relation requires rel_type, to_type, to_key.';
                    break;
                }

                $parsedRelations[] = [
                    'rel_type'  => $relType,
                    'to_type'   => $toType,
                    'to_key'    => $toKey,
                    'meta_json' => af_kb_normalize_json($meta),
                    'sortorder' => $sortorder,
                ];
            }
        }

        $raceParentKey = '';
        if ($type === AF_KB_TYPE_RACE_VARIANT) {
            $raceParentKey = trim((string)$mybb->get_input('race_parent_key'));
            if ($raceParentKey !== '' && !preg_match(AF_KB_KEY_PATTERN, $raceParentKey)) {
                $errors[] = $lang->af_kb_invalid_key ?? 'Invalid key.';
            }

            if (!$errors && $raceParentKey !== '') {
                $raceExists = $db->fetch_array(
                    $db->simple_select(
                        'af_kb_entries',
                        'id',
                        "type='" . $db->escape_string(AF_KB_TYPE_RACE) . "' AND `key`='" . $db->escape_string($raceParentKey) . "'",
                        ['limit' => 1]
                    )
                );
                if (!$raceExists) {
                    $errors[] = 'Selected parent race does not exist.';
                }
            }
        }

        $catIds = [];
        $primaryCatId = 0;
        if (af_kb_categories_enabled()) {
            $catIdsInput = $mybb->get_input('cat_ids', MyBB::INPUT_ARRAY);
            if (is_array($catIdsInput)) {
                $catIds = array_values(array_unique(array_filter(array_map('intval', $catIdsInput), static function (int $value): bool {
                    return $value > 0;
                })));
            }
            $primaryCatId = (int)$mybb->get_input('primary_cat_id', MyBB::INPUT_INT);
            if (af_kb_categories_require_primary() && $primaryCatId <= 0) {
                $errors[] = 'Primary category is required.';
            }
            if ($primaryCatId > 0 && !in_array($primaryCatId, $catIds, true)) {
                $catIds[] = $primaryCatId;
            }
        }

        if (!$errors) {
            $entryDataJsonNormalized = af_kb_validate_rules_json_by_type($type, $entryDataJsonNormalized, $errors, $mechanicKey);
        }

        if (!$errors) {
            $existing = $db->fetch_array(
                $db->simple_select(
                    'af_kb_entries',
                    '*',
                    "type='".$db->escape_string($type)."' AND `key`='".$db->escape_string($key)."'",
                    ['limit' => 1]
                )
            );

            if ($existing && (!$entry || (int)$existing['id'] !== (int)($entry['id'] ?? 0))) {
                $errors[] = 'Entry with this type/key already exists.';
            }
        }

        if (!$errors) {
            if (!isset($metaPayload['ui']) || !is_array($metaPayload['ui'])) {
                $metaPayload['ui'] = [];
            }
            $metaPayload['blocks'] = [];
            foreach ($parsedBlocks as $metaBlock) {
                $blockData = af_kb_decode_json((string)($metaBlock['data_json'] ?? '{}'));
                $metaPayload['blocks'][] = [
                    'block_key' => (string)($metaBlock['block_key'] ?? ''),
                    'level' => (int)($blockData['level'] ?? 0),
                    'title' => [
                        'ru' => (string)($metaBlock['title_ru'] ?? ''),
                        'en' => (string)($metaBlock['title_en'] ?? ''),
                    ],
                    'effects' => isset($blockData['effects']) && is_array($blockData['effects']) ? $blockData['effects'] : [],
                    'data' => $blockData,
                ];
            }
            $metaPayload['ui']['icon_class'] = $entryIconClass;
            $metaPayload['ui']['icon_url'] = $entryIconUrl;
            $metaPayload['ui']['background_url'] = $entryBgUrl;
            $metaPayload['ui']['background_tab_url'] = $entryBgTabUrl;

            $rulesObject = af_kb_decode_json($entryDataJsonNormalized);
            if (!is_array($rulesObject)) {
                $errors[] = 'Rules JSON must be an object.';
            } elseif ($mechanicKey === 'arpg') {
                if (empty($rulesObject['schema']) || (($rulesObject['mechanic'] ?? '') !== 'arpg') || !is_array($rulesObject['rules'] ?? null)) {
                    $errors[] = 'ARPG JSON must contain schema, mechanic=arpg and rules object.';
                }
            } elseif (empty($rulesObject['schema']) || empty($rulesObject['type_profile'])) {
                $errors[] = 'Rules JSON must be an object with schema and type_profile.';
            }

            if (!$errors) {
                $metaPayload['rules'] = $rulesObject;
                $metaPayload = af_kb_cleanup_meta_payload($metaPayload);
                $metaJsonNormalized = json_encode($metaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($metaJsonNormalized === false) {
                    $metaJsonNormalized = '{}';
                }

                if (af_kb_is_admin() && (int)$mybb->get_input('kb_debug_rules', MyBB::INPUT_INT) === 1) {
                    $rulesPreview = (string)json_encode($rulesObject, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($rulesPreview === '') {
                        $rulesPreview = '{}';
                    }
                    $debugPayload = [
                        'entry_id' => (int)($entry['id'] ?? 0),
                        'strlen(meta_json_before)' => strlen($metaBeforeRaw),
                        'has_rules_before' => (is_array($metaBefore['rules'] ?? null) ? 1 : 0),
                        'has_rules_after' => (is_array($metaPayload['rules'] ?? null) ? 1 : 0),
                        'rules_schema' => (string)($rulesObject['schema'] ?? ''),
                        'type_profile' => (string)($rulesObject['type_profile'] ?? ''),
                        'grants_after_normalize' => isset($rulesObject['grants']) && is_array($rulesObject['grants']) ? $rulesObject['grants'] : [],
                        'rules_preview_200' => substr($rulesPreview, 0, 200),
                    ];
                    error_log('[af_kb_rules_save_debug] ' . json_encode($debugPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }

                $data = [
                    'type'       => $db->escape_string($type),
                    'key'        => $db->escape_string($key),
                    'title_ru'   => $db->escape_string((string)$mybb->get_input('title_ru')),
                    'title_en'   => $db->escape_string((string)$mybb->get_input('title_en')),
                    'short_ru'   => $db->escape_string((string)$mybb->get_input('short_ru')),
                    'short_en'   => $db->escape_string((string)$mybb->get_input('short_en')),
                    'body_ru'    => $db->escape_string((string)$mybb->get_input('body_ru')),
                    'body_en'    => $db->escape_string((string)$mybb->get_input('body_en')),
                    'tech_ru'    => $db->escape_string((string)$mybb->get_input('tech_ru')),
                    'tech_en'    => $db->escape_string((string)$mybb->get_input('tech_en')),
                    'meta_json'  => $db->escape_string(af_kb_normalize_json($metaJsonNormalized)),
                    'data_json'  => $db->escape_string($entryDataJsonNormalized),
                    'icon_class' => $db->escape_string($entryIconClass),
                    'icon_url'   => $db->escape_string($entryIconUrl),
                    'banner_url' => $db->escape_string($entryBannerUrl),
                    'bg_url'     => $db->escape_string($entryBgUrl),
                    'active'     => (int)$mybb->get_input('active', MyBB::INPUT_INT) ? 1 : 0,
                    'sortorder'  => (int)$mybb->get_input('sortorder', MyBB::INPUT_INT),
                    'updated_at' => TIME_NOW,
                    'item_kind'  => $db->escape_string($itemKind ?? ''),
                ];

                $txStarted = false;
                if (method_exists($db, 'write_query')) {
                    $db->write_query('START TRANSACTION');
                    $txStarted = true;
                }

                try {
                    if ($entry) {
                        $db->update_query('af_kb_entries', $data, 'id=' . (int)$entry['id']);
                        $entryId = (int)$entry['id'];
                        $action = 'update';
                    } else {
                        $entryId = (int)$db->insert_query('af_kb_entries', $data);
                        $action = 'create';
                    }

                    $db->delete_query('af_kb_blocks', 'entry_id=' . $entryId);
                    foreach ($parsedBlocks as $block) {
                        $db->insert_query('af_kb_blocks', [
                            'entry_id'   => $entryId,
                            'block_key'  => $db->escape_string($block['block_key']),
                            'title_ru'   => $db->escape_string($block['title_ru']),
                            'title_en'   => $db->escape_string($block['title_en']),
                            'content_ru' => $db->escape_string($block['content_ru']),
                            'content_en' => $db->escape_string($block['content_en']),
                            'data_json'  => $db->escape_string($block['data_json']),
                            'icon_class' => $db->escape_string($block['icon_class']),
                            'icon_url'   => $db->escape_string($block['icon_url']),
                            'active'     => (int)$block['active'],
                            'sortorder'  => (int)$block['sortorder'],
                        ]);
                    }

                    $db->insert_query('af_kb_blocks', [
                        'entry_id'   => $entryId,
                        'block_key'  => 'data',
                        'title_ru'   => '',
                        'title_en'   => '',
                        'content_ru' => '',
                        'content_en' => '',
                        'data_json'  => $db->escape_string($entryDataJsonNormalized),
                        'icon_class' => '',
                        'icon_url'   => '',
                        'active'     => 1,
                        'sortorder'  => 9999,
                    ]);

                    $db->delete_query('af_kb_relations', "from_type='" . $db->escape_string($type) . "' AND from_key='" . $db->escape_string($key) . "'");
                    foreach ($parsedRelations as $rel) {
                        $db->insert_query('af_kb_relations', [
                            'from_type' => $db->escape_string($type),
                            'from_key'  => $db->escape_string($key),
                            'rel_type'  => $db->escape_string($rel['rel_type']),
                            'to_type'   => $db->escape_string($rel['to_type']),
                            'to_key'    => $db->escape_string($rel['to_key']),
                            'meta_json' => $db->escape_string($rel['meta_json']),
                            'sortorder' => (int)$rel['sortorder'],
                        ]);
                    }

                    if ($type === AF_KB_TYPE_RACE_VARIANT) {
                        $db->delete_query(
                            'af_kb_relations',
                            "to_type='" . $db->escape_string(AF_KB_TYPE_RACE_VARIANT) . "'"
                            . " AND to_key='" . $db->escape_string($key) . "'"
                            . " AND from_type='" . $db->escape_string(AF_KB_TYPE_RACE) . "'"
                            . " AND rel_type='" . $db->escape_string(AF_KB_REL_RACE_HAS_VARIANT) . "'"
                        );

                        if ($raceParentKey !== '') {
                            $db->insert_query('af_kb_relations', [
                                'from_type' => $db->escape_string(AF_KB_TYPE_RACE),
                                'from_key'  => $db->escape_string($raceParentKey),
                                'rel_type'  => $db->escape_string(AF_KB_REL_RACE_HAS_VARIANT),
                                'to_type'   => $db->escape_string(AF_KB_TYPE_RACE_VARIANT),
                                'to_key'    => $db->escape_string($key),
                                'meta_json' => $db->escape_string('{}'),
                                'sortorder' => 0,
                            ]);
                        }
                    }

                    if (af_kb_categories_enabled()) {
                        $saveCategoriesResult = af_kb_entry_set_categories($entryId, $catIds, $primaryCatId);
                        if (empty($saveCategoriesResult['ok'])) {
                            throw new RuntimeException((string)($saveCategoriesResult['error'] ?? 'Unable to save categories'));
                        }
                    }

                    $db->insert_query('af_kb_log', [
                        'uid'      => (int)$mybb->user['uid'],
                        'action'   => $db->escape_string($action),
                        'type'     => $db->escape_string($type),
                        'key'      => $db->escape_string($key),
                        'diff_json'=> $db->escape_string('{}'),
                        'dateline' => TIME_NOW,
                    ]);

                    if ($txStarted) {
                        $db->write_query('COMMIT');
                    }

                    redirect(af_kb_url(['type' => $type, 'key' => $key]), 'Saved');
                } catch (Throwable $e) {
                    if ($txStarted) {
                        $db->write_query('ROLLBACK');
                    }
                    $errors[] = $e->getMessage();
                }
            }
        }
    }

    $entry = $entry ?: [
        'type'      => $type,
        'key'       => $key,
        'title_ru'  => '',
        'title_en'  => '',
        'short_ru'  => '',
        'short_en'  => '',
        'body_ru'   => '',
        'body_en'   => '',
        'tech_ru'   => '',
        'tech_en'   => '',
        'meta_json' => '{}',
        'item_kind' => '',
        'icon_class' => '',
        'icon_url' => '',
        'banner_url' => '',
        'bg_url' => '',
        'active'    => 1,
        'sortorder' => 0,
    ];

    $blocksRows = '';
    $blocks = [];
    if (!empty($entry['id'])) {
        $bq = $db->simple_select('af_kb_blocks', '*', 'entry_id='.(int)$entry['id'], ['order_by' => 'sortorder, id', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($bq)) {
            if (strtolower(trim((string)($row['block_key'] ?? ''))) === 'data') {
                continue;
            }
            $blocks[] = $row;
        }
    }

    if (!$blocks) {
        $metaBlocks = af_kb_decode_json((string)($entry['meta_json'] ?? '{}'));
        if (isset($metaBlocks['blocks']) && is_array($metaBlocks['blocks'])) {
            foreach ($metaBlocks['blocks'] as $metaBlock) {
                if (!is_array($metaBlock)) {
                    continue;
                }
                $blocks[] = [
                    'block_key' => (string)($metaBlock['block_key'] ?? ''),
                    'title_ru' => (string)($metaBlock['title']['ru'] ?? ''),
                    'title_en' => (string)($metaBlock['title']['en'] ?? ''),
                    'content_ru' => '',
                    'content_en' => '',
                    'data_json' => json_encode($metaBlock['data'] ?? $metaBlock, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'icon_class' => '',
                    'icon_url' => '',
                    'active' => 1,
                    'sortorder' => 0,
                ];
            }
        }
    }

    if (!$blocks) {
        $blocks[] = [
            'block_key' => '',
            'title_ru' => '',
            'title_en' => '',
            'content_ru' => '',
            'content_en' => '',
            'data_json' => '',
            'icon_class' => '',
            'icon_url' => '',
            'active' => 1,
            'sortorder' => 0,
        ];
    }

    $blockIndex = 0;
    foreach ($blocks as $block) {
        $block_index = $blockIndex;
        $block_block_key = htmlspecialchars_uni($block['block_key']);
        $block_title_ru = htmlspecialchars_uni($block['title_ru']);
        $block_title_en = htmlspecialchars_uni($block['title_en']);
        $block_content_ru = htmlspecialchars_uni($block['content_ru']);
        $block_content_en = htmlspecialchars_uni($block['content_en']);
        $block_data_json = htmlspecialchars_uni($block['data_json'] ?? '');
        $block_icon_class = htmlspecialchars_uni($block['icon_class'] ?? '');
        $block_icon_url = htmlspecialchars_uni($block['icon_url'] ?? '');
        $block_active_checked = !empty($block['active']) ? 'checked="checked"' : '';
        $block_sortorder = (int)$block['sortorder'];
        eval("\$blocksRows .= \"" . af_kb_get_template('knowledgebase_blocks_edit_item') . "\";");
        $blockIndex++;
    }

    $relationsRows = '';
    $relations = [];
    if (!empty($entry['type']) && !empty($entry['key'])) {
        $rq = $db->simple_select(
            'af_kb_relations',
            '*',
            "from_type='".$db->escape_string($entry['type'])."' AND from_key='".$db->escape_string($entry['key'])."'",
            ['order_by' => 'sortorder, id', 'order_dir' => 'ASC']
        );
        while ($row = $db->fetch_array($rq)) {
            $relations[] = $row;
        }
    }

    if (!$relations) {
        $relations[] = [
            'rel_type' => '',
            'to_type' => '',
            'to_key' => '',
            'meta_json' => '',
            'sortorder' => 0,
        ];
    }

    $relIndex = 0;
    foreach ($relations as $rel) {
        $rel_index = $relIndex;
        $rel_type = htmlspecialchars_uni($rel['rel_type']);
        $rel_to_type = htmlspecialchars_uni($rel['to_type']);
        $rel_to_key = htmlspecialchars_uni($rel['to_key']);
        $rel_meta_json = htmlspecialchars_uni($rel['meta_json'] ?? '');
        $rel_sortorder = (int)$rel['sortorder'];
        eval("\$relationsRows .= \"" . af_kb_get_template('knowledgebase_rel_edit_item') . "\";");
        $relIndex++;
    }

    $kb_errors = '';
    if ($errors) {
        $items = '';
        foreach ($errors as $error) {
            $items .= '<li>'.htmlspecialchars_uni($error).'</li>';
        }
        $kb_errors = '<div class="af-kb-errors"><ul>'.$items.'</ul></div>';
    }

    $kb_page_title = htmlspecialchars_uni($entry['title_ru'] ?: $entry['title_en'] ?: ($entry['key'] ?: 'KB'));
    $kb_type_value = htmlspecialchars_uni($entry['type']);
    $kb_key_value = htmlspecialchars_uni($entry['key']);
    $kb_title_ru = htmlspecialchars_uni($entry['title_ru']);
    $kb_title_en = htmlspecialchars_uni($entry['title_en']);
    $kb_short_ru = htmlspecialchars_uni($entry['short_ru']);
    $kb_short_en = htmlspecialchars_uni($entry['short_en']);
    $kb_body_ru = htmlspecialchars_uni($entry['body_ru']);
    $kb_body_en = htmlspecialchars_uni($entry['body_en']);
    $kb_tech_ru = htmlspecialchars_uni($entry['tech_ru'] ?? '');
    $kb_tech_en = htmlspecialchars_uni($entry['tech_en'] ?? '');
    $kb_meta_json = htmlspecialchars_uni($entry['meta_json'] ?: '{}');
    $entryRulesJson = af_kb_get_entry_data_json_for_editor((array)$entry);
    $kb_rules_json = htmlspecialchars_uni($entryRulesJson);
    $kb_mechanic_raw = af_kb_get_type_mechanic_key($entry['type']);
    $kb_mechanic_key = htmlspecialchars_uni($kb_mechanic_raw);

    $kb_type_schema = htmlspecialchars_uni(json_encode(af_kb_get_type_schema($entry['type'], $kb_mechanic_raw), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $kb_item_kind_value = htmlspecialchars_uni((string)($entry['item_kind'] ?? ''));
    $itemKinds = [];
    if ($db->table_exists('af_kb_item_kinds')) {
        $qKinds = $db->simple_select('af_kb_item_kinds', 'kind_key,title_ru,title_en,is_active', 'is_active=1', ['order_by' => 'sortorder, kind_key']);
        while ($krow = $db->fetch_array($qKinds)) {
            $itemKinds[] = ['value' => (string)$krow['kind_key'], 'label_ru' => (string)$krow['title_ru'], 'label_en' => (string)$krow['title_en']];
        }
    }
    $kb_item_kinds_json = htmlspecialchars_uni(json_encode($itemKinds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $kb_categories_editor = '';
    if (af_kb_categories_enabled() && !empty($entry['type'])) {
        $flatCats = af_kb_cat_get_flat((string)$entry['type'], false);
        $links = !empty($entry['id']) ? af_kb_entry_get_categories((int)$entry['id']) : ['cat_ids' => [], 'primary' => 0];
        $selected = array_map('intval', (array)($links['cat_ids'] ?? []));
        if ($mybb->request_method === 'post') {
            $postedCatIds = $mybb->get_input('cat_ids', MyBB::INPUT_ARRAY);
            if (is_array($postedCatIds)) {
                $selected = array_values(array_unique(array_filter(array_map('intval', $postedCatIds), static function (int $value): bool {
                    return $value > 0;
                })));
            }
        }

        $primaryCat = (int)($links['primary'] ?? 0);
        if ($mybb->request_method === 'post') {
            $primaryCat = (int)$mybb->get_input('primary_cat_id', MyBB::INPUT_INT);
        }

        $itemsHtml = '';
        $primaryOptions = '<option value="0">—</option>';
        foreach ($flatCats as $cat) {
            $catId = (int)$cat['cat_id'];
            $title = af_kb_pick_text($cat, 'title') ?: (string)$cat['key'];
            $checked = in_array($catId, $selected, true) ? ' checked="checked"' : '';
            $itemsHtml .= '<label><input type="checkbox" name="cat_ids[]" value="' . $catId . '"' . $checked . ' /> ' . htmlspecialchars_uni($title) . '</label><br />';
            $primaryOptions .= '<option value="' . $catId . '"' . ($primaryCat === $catId ? ' selected="selected"' : '') . '>' . htmlspecialchars_uni($title) . '</option>';
        }

        $kb_categories_editor = '<section><h3>Categories</h3>'
            . '<div>' . $itemsHtml . '</div>'
            . '<label>Primary category</label><select name="primary_cat_id" data-af-kb-primary="1">' . $primaryOptions . '</select>'
            . '<script>(function(){'
            . 'var form=document.querySelector("form.af-kb-form");if(!form){return;}'
            . 'var primary=form.querySelector("select[name=\"primary_cat_id\"]");if(!primary){return;}'
            . 'function selectedCats(){return Array.prototype.slice.call(form.querySelectorAll("input[name=\"cat_ids[]\"]:checked")).map(function(el){return String(el.value||"0");});}'
            . 'function syncPrimary(){var selected=selectedCats();Array.prototype.forEach.call(primary.options,function(opt){if(opt.value==="0"){opt.hidden=false;return;}opt.hidden=selected.indexOf(String(opt.value))===-1;});if(primary.value!=="0"&&selected.indexOf(String(primary.value))===-1){primary.value="0";}}'
            . 'Array.prototype.forEach.call(form.querySelectorAll("input[name=\"cat_ids[]\"]"),function(cb){cb.addEventListener("change",syncPrimary);});syncPrimary();'
            . '})();</script>'
            . '</section>';
    }

    $raceParentEditor = '';
    if ($type === AF_KB_TYPE_RACE_VARIANT) {
        $selectedRaceParentKey = '';
        if ($mybb->request_method === 'post') {
            $selectedRaceParentKey = trim((string)$mybb->get_input('race_parent_key'));
        } elseif (!empty($entry['key'])) {
            $parentRelation = af_kb_get_race_parent_for_variant((string)$entry['key'], false);
            if (is_array($parentRelation['race'] ?? null)) {
                $selectedRaceParentKey = (string)($parentRelation['race']['key'] ?? '');
            }
        }

        $raceOptions = '<option value="">— Без родительской расы —</option>';
        $rq = $db->simple_select(
            'af_kb_entries',
            '`key`,title_ru,title_en',
            "type='" . $db->escape_string(AF_KB_TYPE_RACE) . "'",
            ['order_by' => 'sortorder, title_ru, title_en, `key`', 'order_dir' => 'ASC']
        );
        while ($raceRow = $db->fetch_array($rq)) {
            $raceKey = (string)($raceRow['key'] ?? '');
            $raceTitle = af_kb_pick_text($raceRow, 'title');
            if ($raceTitle === '') {
                $raceTitle = $raceKey;
            }
            $selectedAttr = ($raceKey !== '' && $raceKey === $selectedRaceParentKey) ? ' selected="selected"' : '';
            $raceOptions .= '<option value="' . htmlspecialchars_uni($raceKey) . '"' . $selectedAttr . '>' . htmlspecialchars_uni($raceTitle) . ' (' . htmlspecialchars_uni($raceKey) . ')</option>';
        }

        $raceParentEditor = '<section><h3>Родительская раса</h3>'
            . '<label>Parent race</label>'
            . '<select name="race_parent_key">' . $raceOptions . '</select>'
            . '<div class="af-kb-help">Для type=race_variant связь сохраняется в af_kb_relations как race → race_variant (rel_type=' . htmlspecialchars_uni(AF_KB_REL_RACE_HAS_VARIANT) . ').</div>'
            . '</section>';
    }
    $entryUi = af_kb_get_entry_ui($entry);
    $kb_icon_class = htmlspecialchars_uni($entryUi['icon_class'] ?? '');
    $kb_icon_url = htmlspecialchars_uni($entryUi['icon_url'] ?? '');
    $kb_banner_url = htmlspecialchars_uni($entry['banner_url'] ?? '');
    $kb_background_url = htmlspecialchars_uni($entryUi['background_url'] ?? '');
    $kb_background_tab_url = htmlspecialchars_uni($entryUi['background_tab_url'] ?? '');
    $kb_active_checked = !empty($entry['active']) ? 'checked="checked"' : '';
    $kb_sortorder = (int)$entry['sortorder'];
    $kb_blocks_rows = $blocksRows;
    $kb_relations_rows = $relationsRows;
    $kb_blocks_index = $blockIndex;
    $kb_relations_index = $relIndex;
    $kb_race_parent_editor = $raceParentEditor;

    $kb_delete_button = !empty($entry['id']) ? '<button type="submit" name="kb_delete" value="1" class="af-kb-btn af-kb-btn--delete af-kb-btn-delete">'.$lang->af_kb_delete.'</button>' : '';
    $kb_help_link = af_kb_can_edit()
        ? '<a class="af-kb-help-link" href="misc.php?action=kb_help" title="'.htmlspecialchars_uni($lang->af_kb_help_title ?? 'KB help').'"><i class="fa-regular fa-circle-question"></i></a>'
        : '';
    $kb_tech_template_label = htmlspecialchars_uni($lang->af_kb_insert_template ?? 'Insert template');
    $kb_tech_template = htmlspecialchars_uni($lang->af_kb_tech_template_value ?? '[icon=URL_OR_CLASS] Short technical hint here (1–2 sentences).');
    $kb_page_bg = '';
    $kb_body_style = '';
    $kb_header_debug = '';
    if (af_kb_is_admin() && (int)$mybb->get_input('kb_debug', MyBB::INPUT_INT) === 1) {
        af_kb_ensure_header_bits();
        global $headerinclude;

        $markerPresent = strpos((string)$headerinclude, '<!-- af_kb_assets -->') !== false;
        $headerNotEmpty = trim((string)$headerinclude) !== '';

        $kb_header_debug = '<div class="af-kb-help"><strong>KB debug</strong>: headerinclude_non_empty='
            . ($headerNotEmpty ? 'yes' : 'no')
            . ', kb_assets_marker=' . ($markerPresent ? 'yes' : 'no')
            . '</div>';
    }


    if (function_exists('add_breadcrumb')) {
        add_breadcrumb($lang->af_kb_catalog_title ?? 'Knowledge Base', 'misc.php?action=kb');
        if (!empty($entry['type'])) {
            $typeRow = $db->fetch_array(
                $db->simple_select('af_kb_types', '*', "type='".$db->escape_string($entry['type'])."'", ['limit' => 1])
            );
            $typeTitle = $typeRow ? af_kb_pick_text($typeRow, 'title') : $entry['type'];
            add_breadcrumb($typeTitle ?: $entry['type'], 'misc.php?action=kb&type=' . urlencode($entry['type']));
        }
        if (!empty($entry['key'])) {
            $entryTitle = $entry['title_ru'] ?: $entry['title_en'] ?: $entry['key'];
            add_breadcrumb($entryTitle, 'misc.php?action=kb&type=' . urlencode($entry['type']) . '&key=' . urlencode($entry['key']));
        }
        $editLabel = !empty($entry['id'])
            ? ($lang->af_kb_edit ?? 'Edit')
            : ($lang->af_kb_create ?? 'Create');
        add_breadcrumb($editLabel, 'misc.php?action=kb_edit&type=' . urlencode($entry['type']) . '&key=' . urlencode($entry['key']));
    }

    $kb_content = '';
    // страховка от фаталов в шаблонах (минимальный набор)
    $kb_can_edit = '1';
    $kb_page_bg = $kb_page_bg ?? '';
    $kb_body_style = $kb_body_style ?? '';
    $kb_help_link = $kb_help_link ?? '';
    $kb_errors = $kb_errors ?? '';
    eval("\$kb_content = \"" . af_kb_get_template('knowledgebase_edit') . "\";");
    af_kb_render_fullpage($kb_content, 'af_kb_edit_fullpage');
}

function af_kb_handle_type_edit(): void
{
    global $mybb, $db, $lang;

    if (!af_kb_can_manage_types()) {
        error_no_permission();
    }

    $type = trim((string)$mybb->get_input('type'));
    $typeRow = null;
    if ($type !== '') {
        $typeRow = af_kb_find_type_row($type);
    }
    $isEditing = (bool)$typeRow;

    $errors = [];

    if ($mybb->request_method === 'post') {
        verify_post_check($mybb->get_input('my_post_key'));

        $type = trim((string)$mybb->get_input('type'));
        if ($type === '') {
            $errors[] = $lang->af_kb_type_required ?? 'Type is required.';
        }

        if ($typeRow && $type !== $typeRow['type']) {
            $errors[] = $lang->af_kb_type_locked ?? 'Type cannot be changed.';
        }

        $titleRu = trim((string)$mybb->get_input('title_ru'));
        $titleEn = trim((string)$mybb->get_input('title_en'));
        $mechanicKeyInput = trim((string)$mybb->get_input('mechanic_key'));
        $mechanicKey = $mechanicKeyInput !== ''
            ? af_kb_normalize_mechanic_key($mechanicKeyInput)
            : af_kb_get_default_mechanic_mode();
        if (!af_kb_is_allowed_mechanic_key($mechanicKey)) {
            $errors[] = 'Mechanic key is invalid.';
        }
        if ($mechanicKey === 'arpg' && !in_array($type, af_kb_arpg_supported_types(), true)) {
            $errors[] = 'Mechanic "arpg" is allowed only for ARPG-ready types.';
        }

        // NEW: короткое описание (только для табов)
        $shortRu = trim((string)$mybb->get_input('short_ru'));
        $shortEn = trim((string)$mybb->get_input('short_en'));

        // длинное описание
        $descRu = trim((string)$mybb->get_input('description_ru'));
        $descEn = trim((string)$mybb->get_input('description_en'));

        $iconClass = af_kb_sanitize_icon_class((string)$mybb->get_input('icon_class'));
        $iconUrl = af_kb_sanitize_url((string)$mybb->get_input('icon_url'));

        // NEW: баннер категории
        $bannerUrl = af_kb_sanitize_url((string)$mybb->get_input('banner_url'));

        $bgUrl = af_kb_sanitize_url((string)$mybb->get_input('bg_url'));
        $bgTabUrl = af_kb_sanitize_url((string)$mybb->get_input('bg_tab_url'));

        $sortorder = (int)$mybb->get_input('sortorder', MyBB::INPUT_INT);
        $active = (int)$mybb->get_input('active', MyBB::INPUT_INT) ? 1 : 0;

        if (!$errors) {
            $existingType = $db->fetch_array(
                $db->simple_select('af_kb_types', '*', "type='".$db->escape_string($type)."'", ['limit' => 1])
            );

            if ($existingType && !$typeRow) {
                $errors[] = $lang->af_kb_type_exists ?? 'Type already exists.';
            }
        }

        if (!$errors) {
            $data = [
                'type'           => $db->escape_string($type),
                'mechanic_key'   => $db->escape_string($mechanicKey),
                'title_ru'       => $db->escape_string($titleRu),
                'title_en'       => $db->escape_string($titleEn),

                // NEW
                'short_ru'       => $db->escape_string($shortRu),
                'short_en'       => $db->escape_string($shortEn),

                'description_ru' => $db->escape_string($descRu),
                'description_en' => $db->escape_string($descEn),

                'icon_class'     => $db->escape_string($iconClass),
                'icon_url'       => $db->escape_string($iconUrl),

                // NEW
                'banner_url'     => $db->escape_string($bannerUrl),

                'bg_url'         => $db->escape_string($bgUrl),
                'bg_tab_url'     => $db->escape_string($bgTabUrl),

                'sortorder'      => $sortorder,
                'active'         => $active,
            ];

            if ($typeRow) {
                $db->update_query('af_kb_types', $data, 'id='.(int)$typeRow['id']);
            } else {
                $db->insert_query('af_kb_types', $data);
            }

            redirect(af_kb_url(['type' => $type]), $lang->af_kb_type_saved ?? 'Category saved.');
        }
    }

    $typeRow = $typeRow ?: [
        'type'           => $type,
        'mechanic_key'   => af_kb_get_default_mechanic_mode(),
        'title_ru'       => '',
        'title_en'       => '',
        'short_ru'       => '',
        'short_en'       => '',
        'description_ru' => '',
        'description_en' => '',
        'icon_class'     => '',
        'icon_url'       => '',
        'banner_url'     => '',
        'bg_url'         => '',
        'bg_tab_url'     => '',
        'sortorder'      => 0,
        'active'         => 1,
    ];

    $kb_errors = '';
    if ($errors) {
        $items = '';
        foreach ($errors as $error) {
            $items .= '<li>'.htmlspecialchars_uni($error).'</li>';
        }
        $kb_errors = '<div class="af-kb-errors"><ul>'.$items.'</ul></div>';
    }

    $kb_page_title = htmlspecialchars_uni($lang->af_kb_type_edit ?? 'Edit category');
    $kb_type_value = htmlspecialchars_uni($typeRow['type']);
    $typeMechanicKey = af_kb_get_type_mechanic_key($typeRow);
    if (!af_kb_is_allowed_mechanic_key($typeMechanicKey)) {
        $typeMechanicKey = af_kb_get_default_mechanic_mode();
    }
    $kb_type_mechanic_key = htmlspecialchars_uni($typeMechanicKey);
    $kb_type_mechanic_options = '';
    foreach (af_kb_get_mechanic_options() as $mechanicKey => $mechanicLabel) {
        $selected = $mechanicKey === $typeMechanicKey ? ' selected="selected"' : '';
        $kb_type_mechanic_options .= '<option value="' . htmlspecialchars_uni($mechanicKey) . '"' . $selected . '>'
            . htmlspecialchars_uni($mechanicLabel)
            . '</option>';
    }
    $kb_type_title_ru = htmlspecialchars_uni($typeRow['title_ru']);
    $kb_type_title_en = htmlspecialchars_uni($typeRow['title_en']);

    // NEW
    $kb_type_short_ru = htmlspecialchars_uni($typeRow['short_ru'] ?? '');
    $kb_type_short_en = htmlspecialchars_uni($typeRow['short_en'] ?? '');

    $kb_type_description_ru = htmlspecialchars_uni($typeRow['description_ru']);
    $kb_type_description_en = htmlspecialchars_uni($typeRow['description_en']);

    $kb_type_icon_class = htmlspecialchars_uni($typeRow['icon_class'] ?? '');
    $kb_type_icon_url = htmlspecialchars_uni($typeRow['icon_url'] ?? '');

    // NEW
    $kb_type_banner_url = htmlspecialchars_uni($typeRow['banner_url'] ?? '');

    $kb_type_bg_url = htmlspecialchars_uni($typeRow['bg_url'] ?? '');
    $kb_type_bg_tab_url = htmlspecialchars_uni($typeRow['bg_tab_url'] ?? '');

    $kb_type_sortorder = (int)$typeRow['sortorder'];
    $kb_type_active_checked = !empty($typeRow['active']) ? 'checked="checked"' : '';
    $kb_type_readonly = $isEditing ? 'readonly="readonly"' : '';
    $kb_help_link = af_kb_can_edit()
        ? '<a class="af-kb-help-link" href="misc.php?action=kb_help" title="'.htmlspecialchars_uni($lang->af_kb_help_title ?? 'KB help').'"><i class="fa-regular fa-circle-question"></i></a>'
        : '';

    $cancelTarget = $typeRow['type'] !== '' ? 'misc.php?action=kb&type='.urlencode($typeRow['type']) : 'misc.php?action=kb';
    $kb_cancel_link = htmlspecialchars_uni($cancelTarget);

    $kb_type_delete_link = '';
    if (!empty($typeRow['type'])) {
        $confirm = htmlspecialchars_uni($lang->af_kb_type_delete_confirm ?? 'Delete category?');
        $kb_type_delete_link = '<a class="af-kb-btn af-kb-btn--delete af-kb-btn-delete" href="misc.php?action=kb_type_delete&type='.htmlspecialchars_uni($typeRow['type']).'&my_post_key='.htmlspecialchars_uni($mybb->post_code).'" onclick="return confirm(\''.$confirm.'\');">'.htmlspecialchars_uni($lang->af_kb_type_delete ?? 'Delete category').'</a>';
    }
    $kb_page_bg = '';
    $kb_body_style = '';
    $kb_header_debug = '';
    if (af_kb_is_admin() && (int)$mybb->get_input('kb_debug', MyBB::INPUT_INT) === 1) {
        af_kb_ensure_header_bits();
        global $headerinclude;

        $markerPresent = strpos((string)$headerinclude, '<!-- af_kb_assets -->') !== false;
        $headerNotEmpty = trim((string)$headerinclude) !== '';

        $kb_header_debug = '<div class="af-kb-help"><strong>KB debug</strong>: headerinclude_non_empty='
            . ($headerNotEmpty ? 'yes' : 'no')
            . ', kb_assets_marker=' . ($markerPresent ? 'yes' : 'no')
            . '</div>';
    }


    if (function_exists('add_breadcrumb')) {
        add_breadcrumb($lang->af_kb_catalog_title ?? 'Knowledge Base', 'misc.php?action=kb');
        $categoriesLabel = $lang->af_kb_categories_label ?? 'Categories';
        add_breadcrumb($categoriesLabel, 'misc.php?action=kb');
        $editLabel = $isEditing
            ? ($lang->af_kb_type_edit ?? 'Edit category')
            : ($lang->af_kb_type_create ?? 'Create category');
        add_breadcrumb($editLabel, 'misc.php?action=kb_type_edit&type=' . urlencode($typeRow['type']));
    }

    $kb_content = '';
    eval("\$kb_content = \"" . af_kb_get_template('knowledgebase_type_edit') . "\";");
    af_kb_render_fullpage($kb_content, 'af_kb_edit_fullpage');
}

function af_kb_handle_type_delete(): void
{
    global $mybb, $db, $lang;

    if (!af_kb_can_manage_types()) {
        error_no_permission();
    }

    verify_post_check($mybb->get_input('my_post_key'));

    $type = trim((string)$mybb->get_input('type'));
    if ($type === '') {
        error($lang->af_kb_not_found ?? 'Not found');
    }

    $typeRow = $db->fetch_array(
        $db->simple_select('af_kb_types', '*', "type='".$db->escape_string($type)."'", ['limit' => 1])
    );
    if (!$typeRow) {
        error($lang->af_kb_not_found ?? 'Not found');
    }

    $entryIds = [];
    $q = $db->simple_select('af_kb_entries', 'id', "type='".$db->escape_string($type)."'");
    while ($row = $db->fetch_array($q)) {
        $entryIds[] = (int)$row['id'];
    }

    if ($entryIds) {
        $db->delete_query('af_kb_blocks', 'entry_id IN ('.implode(',', $entryIds).')');
    }

    $db->delete_query('af_kb_relations', "from_type='".$db->escape_string($type)."' OR to_type='".$db->escape_string($type)."'");
    $db->delete_query('af_kb_entries', "type='".$db->escape_string($type)."'");
    $db->delete_query('af_kb_log', "type='".$db->escape_string($type)."'");
    $db->delete_query('af_kb_types', 'id='.(int)$typeRow['id']);

    redirect(af_kb_url(), $lang->af_kb_type_deleted ?? 'Category deleted.');
}

function af_kb_handle_debug_entry(): void
{
    global $mybb, $db, $cache;

    if (!af_kb_is_admin() && !af_kb_can_edit()) {
        error_no_permission();
    }

    $entryId = (int)$mybb->get_input('entry_id', MyBB::INPUT_INT);
    if ($entryId <= 0) {
        af_kb_send_json(['success' => false, 'error' => 'Missing entry_id']);
    }

    $entry = $db->fetch_array($db->simple_select('af_kb_entries', '*', 'id=' . $entryId, ['limit' => 1]));
    if (!$entry) {
        af_kb_send_json(['success' => false, 'error' => 'Entry not found']);
    }

    $entryRow = [
        'id' => (int)($entry['id'] ?? 0),
        'type' => (string)($entry['type'] ?? ''),
        'key' => (string)($entry['key'] ?? ''),
        'title_ru' => (string)($entry['title_ru'] ?? ''),
        'title_en' => (string)($entry['title_en'] ?? ''),
        'meta_json' => (string)($entry['meta_json'] ?? ''),
        'data_json' => (string)($entry['data_json'] ?? ''),
        'updated_at' => (int)($entry['updated_at'] ?? 0),
        'item_kind' => (string)($entry['item_kind'] ?? ''),
    ];

    $sources = [];
    $append = static function (string $source, string $json) use (&$sources): void {
        $sources[] = [
            'source' => $source,
            'len' => function_exists('mb_strlen') ? mb_strlen($json) : strlen($json),
            'preview' => (function_exists('mb_substr') ? mb_substr($json, 0, 200) : substr($json, 0, 200)),
        ];
    };

    if (!af_kb_is_empty_json((string)($entry['data_json'] ?? ''))) {
        $append('entries.data_json', (string)$entry['data_json']);
    }

    $meta = af_kb_decode_json((string)($entry['meta_json'] ?? '{}'));
    if (!empty($meta['rules']) && is_array($meta['rules'])) {
        $json = json_encode($meta['rules'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $append('entries.meta_json.rules', $json);
    }

    $tablePrefix = TABLE_PREFIX . 'af_kb_';
    $like = $db->escape_string($tablePrefix . '%');
    $res = $db->query("SHOW TABLES LIKE '{$like}'");
    while ($tblRow = $db->fetch_array($res)) {
        $tableName = '';
        foreach ($tblRow as $v) { $tableName = (string)$v; break; }
        if ($tableName === '') {
            continue;
        }

        $cols = [];
        $colRes = $db->query('SHOW COLUMNS FROM ' . $tableName);
        while ($colRow = $db->fetch_array($colRes)) {
            $field = (string)($colRow['Field'] ?? '');
            if ($field !== '') {
                $cols[] = $field;
            }
        }

        $idCol = '';
        foreach (['entry_id', 'kb_id'] as $candidate) {
            if (in_array($candidate, $cols, true)) {
                $idCol = $candidate;
                break;
            }
        }
        if ($idCol === '') {
            continue;
        }

        $jsonCol = '';
        foreach (['data_json', 'rules_json', 'data', 'json'] as $candidate) {
            if (in_array($candidate, $cols, true)) {
                $jsonCol = $candidate;
                break;
            }
        }
        if ($jsonCol === '') {
            continue;
        }

        $row = $db->fetch_array($db->query('SELECT ' . $jsonCol . ' AS payload FROM ' . $tableName . ' WHERE ' . $idCol . '=' . $entryId . ' LIMIT 1'));
        if ($row && trim((string)($row['payload'] ?? '')) !== '') {
            $append($tableName . '.' . $jsonCol . ' via ' . $idCol, (string)$row['payload']);
        }
    }

    $cacheHit = null;
    if (is_object($cache)) {
        $cacheKey = 'af_kb_entry_data_' . $entryId;
        $cacheValue = $cache->read($cacheKey);
        if ($cacheValue !== null && $cacheValue !== false) {
            $raw = json_encode($cacheValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            if ($raw !== '') {
                $cacheHit = ['key' => $cacheKey, 'len' => strlen($raw)];
            }
        }
    }

    $final = af_kb_detect_entry_data_json((array)$entry);

    $charsheets = ['source' => 'unavailable', 'value' => '{}'];
    if (function_exists('af_cs_kb_get_data_rules_result')) {
        $result = af_cs_kb_get_data_rules_result((string)($entry['type'] ?? ''), (string)($entry['key'] ?? ''));
        $charsheets['source'] = (string)($result['data_source'] ?? $result['reason'] ?? 'unknown');
        $charsheets['value'] = json_encode((array)($result['rules'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    af_kb_send_json([
        'success' => true,
        'entry_row' => $entryRow,
        'detected_data_sources' => $sources,
        'cache_source' => $cacheHit,
        'final_data_json' => [
            'source' => (string)($final['source'] ?? 'none'),
            'value' => af_kb_normalize_rules_json((string)($final['json'] ?? '{}')),
        ],
        'charactersheets_current_source' => $charsheets,
    ]);
}

function af_kb_handle_help(): void
{
    global $lang, $headerinclude, $header, $footer, $templates;

    if (!af_kb_can_edit()) {
        error_no_permission();
    }

    $kb_page_title = htmlspecialchars_uni($lang->af_kb_help_title ?? 'KB help');
    $kb_page_bg = '';
    $kb_body_style = '';

    if (function_exists('add_breadcrumb')) {
        add_breadcrumb($lang->af_kb_catalog_title ?? 'Knowledge Base', 'misc.php?action=kb');
        add_breadcrumb($lang->af_kb_help_title ?? 'KB help', 'misc.php?action=kb_help');
    }

    $af_kb_content = '';
    eval("\$af_kb_content = \"" . af_kb_get_template('knowledgebase_help') . "\";");
    eval("\$page = \"" . af_kb_get_template('knowledgebase_page') . "\";");
    output_page($page);
    exit;
}

/* -------------------- JSON API -------------------- */

function af_kb_handle_json_get(): void
{
    global $mybb, $db, $lang;

    if (!af_kb_can_view()) {
        af_kb_render_json_error($lang->af_kb_no_access ?? 'No access', 403);
    }

    $type = trim((string)$mybb->get_input('type'));
    $key = trim((string)$mybb->get_input('key'));
    if ($type === '' || $key === '') {
        af_kb_render_json_error('Missing parameters', 400);
    }

    $where = "type='".$db->escape_string($type)."' AND `key`='".$db->escape_string($key)."'";
    if (!af_kb_can_edit()) {
        $where .= ' AND active=1';
    }

    $entry = $db->fetch_array($db->simple_select('af_kb_entries', '*', $where, ['limit' => 1]));
    if (!$entry) {
        af_kb_render_json_error($lang->af_kb_not_found ?? 'Not found', 404);
    }
    if (!af_kb_entry_visible_in_context($entry, 'catalog', af_kb_can_edit())) {
        af_kb_render_json_error($lang->af_kb_not_found ?? 'Not found', 404);
    }

    $sectionsHtml = [];
    $bq = $db->simple_select('af_kb_blocks', '*', 'entry_id='.(int)$entry['id'], ['order_by' => 'sortorder, id', 'order_dir' => 'ASC']);
    while ($row = $db->fetch_array($bq)) {
        if (!$row['active'] && !af_kb_can_edit()) {
            continue;
        }
        if (af_kb_is_technical_block($row)) {
            continue;
        }

        $blockTitle = af_kb_pick_text($row, 'title');
        $blockContent = af_kb_pick_text($row, 'content');
        $blockHtml = af_kb_render_block($blockContent);
        if ($blockTitle === '' && $blockHtml === '') {
            continue;
        }
        $sectionsHtml[] = [
            'label' => $blockTitle !== '' ? $blockTitle : (string)($row['block_key'] ?? ''),
            'html' => $blockHtml,
        ];
    }

    $entryUi = af_kb_get_entry_ui($entry);
    $entryLocalized = kb_entry_localize($entry);
    $entryBody = $entryLocalized['body'];
    $entryTech = af_kb_pick_text($entry, 'tech');
    $tooltipText = af_kb_strip_tech_icon_tag($entryTech);
    $bodyRendered  = af_kb_render_block($entryBody);
    $tooltipHtml   = af_kb_render_block($tooltipText);
    $techHint = af_kb_build_tech_hint(af_kb_pick_text($entry, 'tech'));
    $payload = [
        'entry' => [
            'type'      => $entry['type'],
            'key'       => $entry['key'],
            'title'     => $entryLocalized['title'],
            'body_html' => $bodyRendered,
            'sections_html' => $sectionsHtml,
            'tech_hint' => $techHint,
            'tooltip_html' => $tooltipHtml,
            'icon_url'  => $entryUi['icon_url'],
            'icon_class'=> $entryUi['icon_class'],
            'banner_url'=> $entry['banner_url'] ?? '',
        ],
    ];

    af_kb_send_json($payload);
}

function af_kb_handle_json_list(): void
{
    global $mybb, $db, $lang;

    if (!af_kb_can_view() && (int)($mybb->user['uid'] ?? 0) === 0) {
        af_kb_render_json_error($lang->af_kb_no_access ?? 'No access', 403);
    }

    $type = trim((string)$mybb->get_input('type'));
    if ($type === '') {
        af_kb_render_json_error('Missing type', 400);
    }

    $query = trim((string)$mybb->get_input('q'));
    $typeMechanic = af_kb_get_type_mechanic_key($type);
    if (!af_kb_can_edit() && af_kb_is_internal_service_type($type, $typeMechanic)) {
        af_kb_send_json(['success' => true, 'items' => []]);
    }

    $catKey = trim((string)$mybb->get_input('cat'));
    $isAjax = (int)$mybb->get_input('ajax', MyBB::INPUT_INT) === 1;
    $where = "type='".$db->escape_string($type)."'";
    if (!af_kb_can_edit()) {
        $where .= ' AND active=1';
    }
    if ($query !== '') {
        $safeQuery = $db->escape_string($query);
        $where .= " AND (title_ru LIKE '%{$safeQuery}%' OR title_en LIKE '%{$safeQuery}%'"
            . " OR `key` LIKE '%{$safeQuery}%' OR tech_ru LIKE '%{$safeQuery}%' OR tech_en LIKE '%{$safeQuery}%')";
    }

    $items = [];
    $q = $db->simple_select('af_kb_entries', '*', $where, ['order_by' => 'sortorder, title_ru, title_en', 'order_dir' => 'ASC']);
    while ($row = $db->fetch_array($q)) {
        if (!af_kb_entry_visible_in_context($row, $query !== '' ? 'search' : 'catalog', af_kb_can_edit())) {
            continue;
        }
        $entryUi = af_kb_get_entry_ui($row);
        $items[] = [
            'type'  => $row['type'],
            'key'   => $row['key'],
            'title' => af_kb_pick_text($row, 'title') ?: $row['key'],
            'tech' => af_kb_build_tech_hint(af_kb_pick_text($row, 'tech')),
            'icon_url' => $entryUi['icon_url'],
            'icon_class' => $entryUi['icon_class'],
            'banner_url' => $row['banner_url'] ?? '',
        ];
    }

    af_kb_send_json(['success' => true, 'items' => $items]);
}

function af_kb_handle_json_types(): void
{
    global $mybb, $db, $lang;

    if (!af_kb_can_view() && (int)($mybb->user['uid'] ?? 0) === 0) {
        af_kb_render_json_error($lang->af_kb_no_access ?? 'No access', 403);
    }

    $where = af_kb_can_edit()
        ? "type<>'" . $db->escape_string(AF_KB_TYPE_RACE_VARIANT) . "'"
        : "active=1 AND type<>'" . $db->escape_string(AF_KB_TYPE_RACE_VARIANT) . "'";

    $items = [];
    $seenTypes = [];
    $q = $db->simple_select('af_kb_types', '*', $where, ['order_by' => 'sortorder, type', 'order_dir' => 'ASC']);
    while ($row = $db->fetch_array($q)) {
        $rowType = (string)($row['type_key'] ?? '');
        if ($rowType === '') {
            $rowType = (string)($row['type'] ?? '');
        }
        if ($rowType === '' || isset($seenTypes[$rowType])) {
            continue;
        }
        $rowMechanic = af_kb_get_type_mechanic_key($row);
        if (!af_kb_can_edit() && af_kb_is_internal_service_type($rowType, $rowMechanic)) {
            continue;
        }
        $seenTypes[$rowType] = true;
        $items[] = [
            'type' => $rowType,
            'mechanic_key' => af_kb_get_type_mechanic_key($row),
            'title' => af_kb_pick_text($row, 'title') ?: $rowType,
            'icon_url' => $row['icon_url'],
            'icon_class' => $row['icon_class'],
            'background_tab_url' => $row['bg_tab_url'] ?? '',
        ];
    }

    af_kb_send_json(['success' => true, 'items' => $items]);
}

function af_kb_handle_json_children(): void
{
    global $mybb, $db, $lang;

    if (!af_kb_can_view()) {
        af_kb_render_json_error($lang->af_kb_no_access ?? 'No access', 403);
    }

    $fromType = trim((string)$mybb->get_input('from_type'));
    $fromKey = trim((string)$mybb->get_input('from_key'));
    $relType = trim((string)$mybb->get_input('rel_type'));

    if ($fromType === '' || $fromKey === '' || $relType === '') {
        af_kb_render_json_error('Missing parameters', 400);
    }

    $items = [];
    $rq = $db->simple_select(
        'af_kb_relations',
        '*',
        "from_type='".$db->escape_string($fromType)."' AND from_key='".$db->escape_string($fromKey)."' AND rel_type='".$db->escape_string($relType)."'",
        ['order_by' => 'sortorder, id', 'order_dir' => 'ASC']
    );
    while ($row = $db->fetch_array($rq)) {
        $title = $row['to_key'];
        $target = $db->fetch_array(
            $db->simple_select(
                'af_kb_entries',
                '*',
                "type='".$db->escape_string($row['to_type'])."' AND `key`='".$db->escape_string($row['to_key'])."'",
                ['limit' => 1]
            )
        );
        if ($target) {
            $title = af_kb_pick_text($target, 'title');
            if ($title === '') {
                $title = $target['key'];
            }
        }
        $items[] = [
            'to_type' => $row['to_type'],
            'to_key'  => $row['to_key'],
            'title'   => $title,
        ];
    }

    af_kb_send_json(['items' => $items]);
}

function af_kb_format_select_option(array $entry): array
{
    $value = (string)($entry['key'] ?? '');
    $label = af_kb_pick_text($entry, 'title');
    if ($label === '') {
        $label = $value;
    }

    return [
        'value' => $value,
        'label' => $label,
    ];
}

function af_kb_handle_json_race_variants(): void
{
    global $mybb, $lang;

    if (!af_kb_can_view()) {
        af_kb_render_json_error($lang->af_kb_no_access ?? 'No access', 403);
    }

    $raceKey = trim((string)$mybb->get_input('race'));
    if ($raceKey === '') {
        $raceKey = trim((string)$mybb->get_input('race_key'));
    }
    if ($raceKey === '') {
        af_kb_render_json_error('Missing race', 400);
    }

    if (!preg_match('/^[a-z0-9_-]{2,64}$/i', $raceKey)) {
        af_kb_render_json_error('Invalid race key', 400);
    }

    $activeOnly = (int)$mybb->get_input('include_inactive', MyBB::INPUT_INT) !== 1;
    $links = af_kb_get_race_variants($raceKey, $activeOnly);

    $items = [];
    foreach ($links as $link) {
        $entry = is_array($link['variant'] ?? null) ? $link['variant'] : [];
        if (empty($entry)) {
            continue;
        }

        $option = af_kb_format_select_option($entry);
        $option['sortorder'] = (int)($link['sortorder'] ?? 0);
        $items[] = $option;
    }

    af_kb_send_json([
        'success' => true,
        'race' => $raceKey,
        'items' => $items,
        'select_contract' => [
            'value_key' => 'value',
            'label_key' => 'label',
        ],
    ]);
}

function af_kb_get_entry(string $type, string $key): ?array
{
    global $db;
    static $cache = [];
    $idx = $type . ':' . $key;
    if (array_key_exists($idx, $cache)) {
        return $cache[$idx];
    }

    $row = $db->fetch_array($db->simple_select('af_kb_entries', '*', "type='".$db->escape_string($type)."' AND `key`='".$db->escape_string($key)."'", ['limit' => 1]));
    if (!$row) {
        $cache[$idx] = null;
        return null;
    }

    $cache[$idx] = [
        'type' => (string)$row['type'],
        'key' => (string)$row['key'],
        'title_ru' => (string)$row['title_ru'],
        'title_en' => (string)$row['title_en'],
        'short_ru' => (string)$row['short_ru'],
        'short_en' => (string)$row['short_en'],
        'body_ru' => (string)$row['body_ru'],
        'body_en' => (string)$row['body_en'],
        'item_kind' => (string)($row['item_kind'] ?? ''),
        'meta' => af_kb_decode_json((string)($row['meta_json'] ?? '{}')),
    ];

    return $cache[$idx];
}


function af_kb_get_race_parent_for_variant(string $variantKey, bool $activeOnly = true): ?array
{
    global $db;

    $variantKey = trim($variantKey);
    if ($variantKey === '') {
        return null;
    }

    $where = "to_type='" . $db->escape_string(AF_KB_TYPE_RACE_VARIANT) . "'"
        . " AND to_key='" . $db->escape_string($variantKey) . "'"
        . " AND from_type='" . $db->escape_string(AF_KB_TYPE_RACE) . "'"
        . " AND rel_type='" . $db->escape_string(AF_KB_REL_RACE_HAS_VARIANT) . "'";

    $relation = $db->fetch_array(
        $db->simple_select('af_kb_relations', '*', $where, ['order_by' => 'sortorder, id', 'order_dir' => 'ASC', 'limit' => 1])
    );

    if (!$relation) {
        return null;
    }

    $entryWhere = "type='" . $db->escape_string((string)$relation['from_type']) . "'"
        . " AND `key`='" . $db->escape_string((string)$relation['from_key']) . "'";
    if ($activeOnly) {
        $entryWhere .= ' AND active=1';
    }

    $race = $db->fetch_array($db->simple_select('af_kb_entries', '*', $entryWhere, ['limit' => 1]));
    if (!$race) {
        return null;
    }

    return [
        'relation_id' => (int)($relation['id'] ?? 0),
        'rel_type' => (string)($relation['rel_type'] ?? ''),
        'sortorder' => (int)($relation['sortorder'] ?? 0),
        'race' => $race,
    ];
}

function af_kb_get_race_variants(string $raceKey, bool $activeOnly = true): array
{
    global $db;

    $raceKey = trim($raceKey);
    if ($raceKey === '') {
        return [];
    }

    $where = "from_type='" . $db->escape_string(AF_KB_TYPE_RACE) . "'"
        . " AND from_key='" . $db->escape_string($raceKey) . "'"
        . " AND to_type='" . $db->escape_string(AF_KB_TYPE_RACE_VARIANT) . "'"
        . " AND rel_type='" . $db->escape_string(AF_KB_REL_RACE_HAS_VARIANT) . "'";

    $rows = [];
    $query = $db->simple_select('af_kb_relations', '*', $where, ['order_by' => 'sortorder, id', 'order_dir' => 'ASC']);
    while ($relation = $db->fetch_array($query)) {
        $entryWhere = "type='" . $db->escape_string((string)$relation['to_type']) . "'"
            . " AND `key`='" . $db->escape_string((string)$relation['to_key']) . "'";
        if ($activeOnly) {
            $entryWhere .= ' AND active=1';
        }

        $variant = $db->fetch_array($db->simple_select('af_kb_entries', '*', $entryWhere, ['limit' => 1]));
        if (!$variant) {
            continue;
        }

        $rows[] = [
            'relation_id' => (int)($relation['id'] ?? 0),
            'rel_type' => (string)($relation['rel_type'] ?? ''),
            'sortorder' => (int)($relation['sortorder'] ?? 0),
            'variant' => $variant,
        ];
    }

    return $rows;
}

function af_kb_get_meta(string $type, string $key): array
{
    $entry = af_kb_get_entry($type, $key);
    return is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
}

function af_kb_resolve_title(string $type, string $key, string $lang = 'ru'): string
{
    $entry = af_kb_get_entry($type, $key);
    if (!$entry) {
        return $key;
    }

    if ($lang === 'en' && !empty($entry['title_en'])) {
        return (string)$entry['title_en'];
    }

    return (string)($entry['title_ru'] ?: $entry['title_en'] ?: $key);
}

function af_kb_list(string $type, array $opts = []): array
{
    global $db;

    $page = max(1, (int)($opts['page'] ?? 1));
    $perpage = max(1, min(200, (int)($opts['perpage'] ?? 50)));
    $activeOnly = array_key_exists('active_only', $opts) ? (bool)$opts['active_only'] : true;

    $where = "type='".$db->escape_string($type)."'";
    if ($activeOnly) {
        $where .= " AND active=1";
    }

    if (!empty($opts['q'])) {
        $q = $db->escape_string((string)$opts['q']);
        $where .= " AND (`key` LIKE '%{$q}%' OR title_ru LIKE '%{$q}%' OR title_en LIKE '%{$q}%')";
    }

    $rows = [];
    $query = $db->simple_select('af_kb_entries', '*', $where, [
        'order_by' => 'sortorder, `key`',
        'order_dir' => 'ASC',
        'limit_start' => ($page - 1) * $perpage,
        'limit' => $perpage,
    ]);

    while ($row = $db->fetch_array($query)) {
        $rows[] = [
            'type' => (string)$row['type'],
            'key' => (string)$row['key'],
            'title_ru' => (string)$row['title_ru'],
            'title_en' => (string)$row['title_en'],
            'item_kind' => (string)($row['item_kind'] ?? ''),
            'meta' => af_kb_decode_json((string)($row['meta_json'] ?? '{}')),
        ];
    }

    return $rows;
}
