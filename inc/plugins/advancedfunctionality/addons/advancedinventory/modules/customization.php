<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

function af_advinv_entity_customization_render(int $ownerUid, string $sub, int $page, bool $ajax): string
{
    $filters = ['entity' => 'customization', 'subtype' => $sub, 'page' => max(1, $page)];
    $data = af_inv_get_items($ownerUid, array_merge($filters, ['enrich' => true]));
    $rows = af_advinv_render_tab_cards($data['items'], af_advancedinventory_user_can_manage(), false);
    $filterButtons = af_advinv_render_subfilter_links('customization', $ownerUid, $sub, af_advinv_entity_customization_subfilters());
    $apiBase = af_advancedinventory_url('', [], false);
    return '<div class="af-inv-subfilters">' . $filterButtons . '</div><div class="af-inv-grid-wrap"><div class="af-inv-grid">' . $rows . '</div></div><div class="af-inv-api" data-api-base="' . htmlspecialchars_uni($apiBase) . '" data-owner="' . $ownerUid . '"></div>';
}

function af_advinv_entity_customization_subfilters(): array
{
    return ['all' => 'Все', 'profile' => 'Профиль', 'postbit' => 'Постбит', 'sheet' => 'Лист персонажа'];
}
