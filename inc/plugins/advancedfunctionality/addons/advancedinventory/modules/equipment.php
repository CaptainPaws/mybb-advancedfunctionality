<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

function af_advinv_entity_equipment_render(int $ownerUid, string $sub, int $page, bool $ajax): string
{
    $filters = ['entity' => 'equipment', 'subtype' => $sub, 'page' => max(1, $page)];
    $data = af_inv_get_items($ownerUid, array_merge($filters, ['enrich' => true]));
    $canManage = af_advancedinventory_user_can_manage();
    $equipped = af_inv_get_equipped($ownerUid);

    $rows = af_advinv_render_tab_cards($data['items'], $canManage, true, $equipped);
    $filterButtons = af_advinv_render_subfilter_links('equipment', $ownerUid, $sub, af_advinv_entity_equipment_subfilters());

    $slotsHtml = '';
    foreach (af_inv_equipment_slots() as $slotCode => $slotTitle) {
        $eqItem = af_inv_find_item_by_id($data['items'], (int)($equipped[$slotCode]['item_id'] ?? 0));
        if (!$eqItem && !empty($equipped[$slotCode])) {
            $eqItem = af_inv_get_item_for_owner($ownerUid, (int)$equipped[$slotCode]['item_id']);
        }
        $name = $eqItem ? htmlspecialchars_uni((string)$eqItem['title']) : '<span class="af-inv-empty-slot">Пусто</span>';
        $button = $eqItem ? '<button class="af-inv-action" data-action="unequip" data-equip-slot="' . htmlspecialchars_uni($slotCode) . '">Снять</button>' : '';
        $slotsHtml .= '<div class="af-inv-slot"><div class="af-inv-slot-name">' . htmlspecialchars_uni($slotTitle) . '</div><div class="af-inv-slot-item">' . $name . '</div>' . $button . '</div>';
    }
    $slotsHtml = '<div class="af-inv-slots">' . $slotsHtml . '</div>';

    $apiBase = af_advancedinventory_url('', [], false);
    return '<div class="af-inv-subfilters">' . $filterButtons . '</div><div class="af-inv-grid-wrap"><div class="af-inv-grid">' . $rows . '</div>' . $slotsHtml . '</div><div class="af-inv-api" data-api-base="' . htmlspecialchars_uni($apiBase) . '" data-owner="' . $ownerUid . '"></div>';
}

function af_advinv_entity_equipment_subfilters(): array
{
    return ['all' => 'Все', 'weapon' => 'Оружие', 'armor' => 'Броня', 'ammo' => 'Боеприпасы', 'consumable' => 'Расходники'];
}

function af_advinv_entity_equipment_classify_from_kb_meta(array $kbMeta): string
{
    $kindFromRules = mb_strtolower(trim((string)($kbMeta['rules']['item']['item_kind'] ?? '')));
    if (in_array($kindFromRules, ['weapon', 'armor', 'ammo', 'consumable'], true)) {
        return $kindFromRules;
    }

    $kind = mb_strtolower(trim((string)($kbMeta['item_kind'] ?? '')));
    if (in_array($kind, ['weapon', 'armor', 'ammo', 'consumable'], true)) {
        return $kind;
    }

    return '';
}
