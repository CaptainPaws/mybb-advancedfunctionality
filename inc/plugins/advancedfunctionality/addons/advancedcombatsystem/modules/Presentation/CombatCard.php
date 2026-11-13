<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Pure escaped markup from an authorized, stored-result DTO. No calculation or prose editing. */
final class AF_ACS_CombatCard
{
    private static function esc(mixed $v): string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public static function render(array $card): string
    {
        $html='<aside class="af-acs-card" aria-label="Боевой результат ACS"><h4>ACS · '.self::esc($card['label']).'</h4>';
        if (!empty($card['can_manage']) && $card['status']==='recovery_required')$html.='<p><a href="misc.php?action=acs_recovery&amp;session_id='.rawurlencode($card['session_id']).'&amp;submission_id='.rawurlencode($card['submission_id']).'">Разобрать отправку</a></p>';
        if (!$card['mechanics_confirmed']) {return $html.'<p>Боевой результат ещё не применён.</p></aside>';}
        if ($card['details']===null) {return $html.'<p>Подробности ограничены правилами видимости сессии.</p></aside>';}
        $html.='<ol>';
        foreach($card['details'] as $step){
            $outcome=['cover'=>'назначение укрытия выполнено','spatial'=>'пространственное действие выполнено','assisted'=>'помощь применена','success'=>'успех взаимодействия','critical_success'=>'критический успех взаимодействия','partial_success'=>'частичный успех взаимодействия','failure'=>'провал взаимодействия','hit'=>'попадание','crit'=>'критическое попадание','miss'=>'промах','multiple'=>'несколько целей','used'=>'действие с предметом выполнено','search'=>'поиск выполнен','share'=>'передача сведений выполнена','environment'=>'воздействие на окружение выполнено'][$step['outcome']]??'исход недоступен';
            $html.='<li><p><strong>'.self::esc($step['actor_name']).' → '.self::esc($step['target_name']).'</strong> · '.self::esc($outcome).'</p>';
            $costs=[];foreach($step['costs'] as $c)$costs[]=self::esc($c['amount']).' '.($c['kind']==='ap'?'AP':'ресурса');
            $special=in_array($step['outcome'],['search','share','environment'],true);$html.='<p>Стоимость: '.implode(', ',$costs).'.'.($special?'':' Урон: '.self::esc($step['damage']).'.').'</p>';
            if($special){$html.=$step['outcome']==='environment'?'<p>Воздействие на окружение применено.</p></li>':'<p>Доступные сведения обновляются по правилам аудитории.</p></li>';continue;}
            if ($step['outcome']==='used') {
                $item=$step['item']??[];$operation=($item['operation']??'')==='consume'?'Использование':'Смена оружия';
                $html.='<p>'.self::esc($operation).' · предмет #'.self::esc($item['item_id']??'—').' · '.self::esc($item['qty']??'—').' шт. Подтверждено сервером.</p></li>';
                continue; // Deterministic use does not claim an applied hit/crit check.
            }
            if(isset($step['cover'])){$html.='<p>Назначение укрытия подтверждено сервером.</p></li>';continue;}if(isset($step['spatial'])){$html.='<p>Перемещение/контакт подтверждены сервером.</p></li>';continue;}
            if(isset($step['cooperation'])){$html.='<p>Совместная задача: '.self::esc($step['cooperation']['progress']).' / '.self::esc($step['cooperation']['cap']).(!empty($step['cooperation']['completed'])?' · завершена':'').'.</p></li>';continue;}
            if(isset($step['interaction'])){$html.='<p>Объект #'.self::esc($step['interaction']['entity_id']).' · '.self::esc($step['interaction']['key']).($step['interaction']['progress']===null?'':' · прогресс '.self::esc($step['interaction']['progress'])).'.</p></li>';continue;}
            $r=$step['rolls']??[];$p=$step['probabilities']??[];
            $html.='<details><summary>Броски и защита</summary><p>Попадание: '.self::esc($r['hit']['u']??'—').'; критическое попадание: '.self::esc($r['crit']['u']??'—').(empty($r['crit_used'])?' (резерв)':'').'.</p>';
            $html.='<p>Вероятность попадания: '.self::esc($p['P_hit']??'—').'%; критического попадания при попадании: '.self::esc($p['P_crit']??'—').'%.</p>';
            foreach($step['components'] as $i=>$c){
                $type=['physical'=>'физический','elemental'=>'стихийный','true'=>'истинный'][$c['damage_type']]??'компонент';
                $html.='<p>Компонент '.($i+1).' · '.$type.': до защиты '.self::esc($c['after_crit']??'—').', эффективная защита '.self::esc($c['effective_defense']??'—').', после защиты '.self::esc($c['post_defense']??'—').', распределённый урон '.self::esc($c['allocated']??'—').'.</p>';
            }
            $html.='</details>';
            foreach($step['mechanics']??[] as $event){
                $text=match($event['type']){
                    'target'=>'Цель: '.$event['original_target'].' → '.$event['resolved_target'].'; '.$event['outcome'].'; урон '.$event['damage'].'; hit '.($event['rolls']['hit']['u']??'—').'; crit '.($event['rolls']['crit']['u']??'—'),
                    'reaction'=>'Реакция '.$event['id'].' · '.$event['phase'].': '.($event['executed']?'исполнена':'пропущена: '.($event['reason']??'—')).(isset($event['redirect_success'])?'; перехват '.($event['redirect_success']?'успешен':'неуспешен'):''),
                    'status'=>'Статус '.$event['effect_key'].' · '.$event['status'].'; стаки '.$event['stacks'].'; срок '.$event['remaining_duration'].' '.$event['duration_unit'],
                    'tick'=>'Эффект '.$event['effect_id'].': тиков '.$event['tick_count'].' · '.$event['boundary_key'],
                    'upkeep'=>'Поддержание '.$event['id'].': '.(!empty($event['paid'])?'оплачено':($event['reason']??'прекращено')),
                    default=>'',
                };if($text!=='')$html.='<p>'.self::esc($text).'</p>';
            }$html.='</li>';
        }
        return $html.'</ol></aside>';
    }
}
