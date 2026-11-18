<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Pure escaped markup from an authorized stored-result DTO. Native details need no JS or editor hooks. */
final class AF_ACS_CombatCard
{
    private static function esc(mixed $v):string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function outcome(string $v):string
    {
        return ['copy_ability'=>'Временная способность получена','steal_effect'=>'Эффект перенесён','dispel_effect'=>'Эффект рассеян','cover'=>'Укрытие изменено','spatial'=>'Пространственное действие выполнено','assisted'=>'Помощь применена','success'=>'Успех','critical_success'=>'Критический успех','partial_success'=>'Частичный успех','failure'=>'Проверка не пройдена','hit'=>'Попадание','crit'=>'Критическое попадание','miss'=>'Промах','multiple'=>'Несколько целей','used'=>'Предметная операция выполнена','search'=>'Поиск выполнен','share'=>'Сведения переданы','environment'=>'Воздействие на окружение выполнено','spawned'=>'Призыв выполнен','executed'=>'Исполнено','skipped'=>'Пропущено'][$v]??'Результат сохранён';
    }
    private static function entity(mixed $id):string {return $id===null||$id===''?'Участник':'Участник #'.$id;}
    private static function phase(?string $v):string
    {return ['on_targeted'=>'При выборе целью','before_hit'=>'До попадания','after_hit'=>'После попадания','after_damage'=>'После урона','start'=>'Начало хода','end'=>'Завершение хода','initial'=>'Начальные эффекты'][$v??'']??'Сохранённая фаза';}
    private static function reason(?string $v):string
    {return ['condition_false'=>'условие не выполнено','check_failed'=>'проверка не пройдена','hit_required'=>'требовалось попадание','limit_used'=>'реакция уже использована','effects_cost_unaffordable'=>'не хватило ресурсов','source_or_target_unavailable'=>'исполнитель или цель недоступны','preparation_unavailable'=>'подготовка недоступна','trigger_subject_changed'=>'цель изменилась','single_target_required'=>'нужна одна цель','redirect_already_used'=>'перехват уже использован','redirect_target_ineligible'=>'перехват недопустим','redirect_target_unavailable'=>'цель перехвата недоступна'][$v??'']??'сохранённое условие не позволило исполнение';}
    private static function costs(array $costs):string
    {$out=[];foreach($costs as $c)$out[]=($c['amount']??'—').' '.($c['kind']==='ap'?'AP':($c['resource']??'ресурса'));return $out?'<p>Расходы: '.self::esc(implode(', ',$out)).'.</p>':'';}
    private static function section(string $title,array $lines):string
    {return $lines?'<section class="af-acs-card__section"><h6>'.self::esc($title).'</h6><ul><li>'.implode('</li><li>',array_map(self::esc(...),$lines)).'</li></ul></section>':'';}
    private static function events(array $events):string
    {
        $groups=[];
        foreach($events as $e){$type=$e['type'];$entity=$e['entity_name']??self::entity($e['entity_id']??$e['target_entity_id']??null);$group='Эффекты';
            $line=match($type){
                'damage'=>$entity.': потеря HP '.$e['hp_loss'].', поглощено '.$e['absorbed'].', сверх оставшегося HP '.$e['overkill'],
                'heal'=>$entity.': восстановлено HP '.$e['effective_recovery'].'; рассчитано '.$e['calculated'].', избыточное лечение '.$e['overheal'],
                'barrier_absorb'=>$entity.': барьер #'.$e['pool_id'].' поглотил '.$e['amount'],
                'barrier_applied'=>'Создан барьер #'.$e['pool_id'],
                'barrier_restored'=>'Барьер #'.$e['pool_id'].': восстановлено '.$e['restored'],
                'barrier_duration'=>'Барьер #'.$e['pool_id'].': оставшаяся длительность '.$e['remaining_duration'],
                'status_created','status_refreshed','status_removed','status_duration','status_combat_ended','status_rejected'=>(['status_created'=>'Применён','status_refreshed'=>'Обновлён','status_removed'=>'Снят','status_duration'=>'Изменилась длительность','status_combat_ended'=>'Завершён','status_rejected'=>'Отклонён'][$type]).' статус '.($e['effect_key']??('#'.($e['id']??'—'))).(isset($e['target_entity_id'])?' · '.$entity:'').(isset($e['stacks'])?' · стаки '.$e['stacks']:'').(isset($e['remaining_duration'])?' · осталось '.$e['remaining_duration']:''),
                'status_removal_check'=>'Снятие статуса: '.($e['success']?'успех':'неуспех'),
                'upkeep_result'=>'Поддержание эффекта #'.$e['id'].': '.(!empty($e['paid'])?'оплачено':'не оплачено'),
                'sustained_stopped'=>'Поддержание эффекта #'.$e['id'].' прекращено',
                'absorb_effect'=>$entity.': поглощение '.$e['amount'].' · '.($e['mode']==='barrier'?'барьер':'ресурс'),
                'ability_copied'=>'Получена временная способность','effect_transferred'=>'Эффект перенесён: '.$e['old_id'].' → '.$e['new_id'],'effect_dispelled'=>'Эффект #'.$e['id'].' рассеян',
                'transform_changed'=>$entity.': форма изменена','form_status_removed'=>'Статус #'.$e['id'].' снят правилом формы','form_max_hp'=>$entity.': максимум HP после смены формы '.$e['maximum'].', текущие HP '.$e['current_hp'],
                'cost_paid'=>$entity.': оплачено '.$e['amount'].' '.($e['kind']==='ap'?'AP':($e['resource']??'ресурса')),
                'participation'=>$entity.': участие изменено',
                'summon.despawned'=>$entity.': призыв завершён','summon.independent'=>$entity.': призыв стал самостоятельным',
                'summon.duration'=>$entity.': осталось '.$e['remaining'],'summon.upkeep'=>$entity.': поддержание призыва',
                'object.triggered'=>'Объект #'.$e['entity_id'].': сработал триггер','object.passive'=>'Объект #'.$e['entity_id'].': пассивный эффект',
                'object.change'=>'Объект #'.$e['entity_id'].': '.(['damage'=>'получен урон','activate'=>'активирован','deactivate'=>'деактивирован','destroy'=>'уничтожен','boundary'=>'изменён на границе хода'][$e['operation']]??'состояние изменено'),
                default=>'',
            };
            if($line==='')continue;
            if($type==='damage')$group='Урон';elseif($type==='heal')$group='Лечение';elseif(str_starts_with($type,'barrier_')||$type==='absorb_effect')$group='Барьеры и поглощение';elseif(str_starts_with($type,'status_')||in_array($type,['upkeep_result','sustained_stopped'],true))$group='Статусы';elseif(str_starts_with($type,'summon.'))$group='Призывы';elseif(str_starts_with($type,'object.'))$group='Окружение';elseif($type==='cost_paid')$group='Оплаченные ресурсы';
            $groups[$group][]=$line;
        }
        $html='';foreach($groups as $title=>$lines)$html.=self::section($title,$lines);return $html;
    }
    private static function technical(array $strike):string
    {
        $html='';$rolls=$strike['rolls'];
        if($rolls && !(($rolls['hit_used']??null)===false && ($rolls['crit_used']??null)===false)){
            $attack=(bool)$strike['components'];
            $html.='<p>'.($attack?'Запечатанный бросок попадания: ':'Запечатанная проверка действия: ').self::esc($rolls['hit']['u']??'—').($attack?'; критического попадания: '.self::esc($rolls['crit']['u']??'—').(empty($rolls['crit_used'])?' (резерв)':''):'').'.</p>';
        }
        if($html!=='' && $strike['probabilities'])$html.='<p>Сохранённая вероятность успеха: '.self::esc($strike['probabilities']['P_hit']??'—').'%'.($strike['components']?'; крит при попадании '.self::esc($strike['probabilities']['P_crit']??'—').'%':'').'.</p>';
        foreach($strike['components'] as $i=>$c){$type=['physical'=>'физический','elemental'=>'стихийный','true'=>'истинный'][$c['damage_type']]??'компонент';
            $html.='<p>Компонент '.($i+1).' · '.$type.': до защиты '.self::esc($c['after_crit']??'—').'; эффективная защита '.self::esc($c['effective_defense']??'—').'; после защиты '.self::esc($c['post_defense']??'—').'; распределено '.self::esc($c['allocated']??'—').'.</p>';
        }
        foreach($strike['formulas'] as $f){
            $html.='<section><h6>Сохранённый расчёт '.self::esc($f['ref']['key']??'формулы').(isset($f['ref']['version'])?' · версия '.self::esc($f['ref']['version']):'').'</h6>';
            $values=[];foreach($f['inputs'] as $key=>$value)$values[]=$key.' = '.$value;if($values)$html.='<p>Входы: '.self::esc(implode('; ',$values)).'.</p>';
            foreach($f['steps'] as $step)$html.='<p>Шаг '.self::esc($step['index']).': '.self::esc(['add'=>'сложение','subtract'=>'вычитание','multiply'=>'умножение','divide'=>'деление','negate'=>'смена знака'][$step['operator']??'']??($step['type']==='input'?'входное значение':'значение')).' → '.self::esc($step['raw']).'.</p>';
            if(isset($f['terminal']['clamped']))$html.='<p>Итог: '.self::esc($f['terminal']['clamped']).' '.self::esc($f['unit']??'').'.</p>';$html.='</section>';
        }
        foreach($strike['causes'] as $cause){
            $pairs=[];foreach($cause as $key=>$value){
                if(!is_scalar($value))continue;
                $label=match($key){'id','causal_id'=>'Связь','cause'=>'Причина','parent_event_id'=>'Родительское событие','source'=>'Источник','target'=>'Получатель','roll'=>'Запечатанное значение','kind'=>'Тип','phase'=>'Фаза','executed'=>'Исполнено','reason','skipped'=>'Причина пропуска','basis','basis_hp_loss'=>'Основание расчёта','calculated'=>'Рассчитано',default=>'Ссылка'};
                if($key==='kind')$value=['primary'=>'Основное действие','reflection'=>'Отражение','lifesteal'=>'Восстановление от нанесённого урона','reflect_effect'=>'Отражение эффекта'][$value]??'Причинный эффект';
                elseif($key==='phase')$value=self::phase($value);elseif(in_array($key,['reason','skipped'],true))$value=self::reason($value);
                $pairs[]=$label.': '.(is_bool($value)?($value?'да':'нет'):$value);
            }if($pairs)$html.='<p>'.self::esc(implode(' · ',$pairs)).'</p>';
        }
        foreach($strike['reactions'] as $r)if(isset($r['causal_key'])||isset($r['roll_id']))$html.='<p>Реакция #'.self::esc($r['id']).(isset($r['causal_key'])?' · причинная связь '.self::esc($r['causal_key']):'').(isset($r['roll_id'])?' · сохранённый бросок #'.self::esc($r['roll_id']):'').'.</p>';
        return $html===''?'':'<details class="af-acs-card__technical"><summary>Расчёты, броски и причинные связи</summary>'.$html.'</details>';
    }
    private static function actions(array $actions):string
    {
        if(!$actions)return '';$html='<ol class="af-acs-card__actions">';
        foreach($actions as $step){
            $targets=$step['target_names']??array_map(self::entity(...),$step['targets']);
            $html.='<li><h5>'.self::esc($step['actor_name']).' · '.self::esc($step['ability_key']).'</h5>'.($targets?'<p>Цели по порядку: '.self::esc(implode(' → ',$targets)).'.</p>':'').'<p>'.self::esc(self::outcome($step['outcome'])).'.</p>'.self::costs($step['costs']);
            if(isset($step['item'])){$item=$step['item'];$html.='<p>Предмет: '.(($item['operation']??null)==='consume'?'использование':'смена экипировки').' · #'.self::esc($item['item_id']??'—').' · '.self::esc($item['qty']??'—').' шт.</p>';}
            if(isset($step['granted_use']))$html.='<p>Использована временная способность; осталось применений: '.self::esc($step['granted_use']['remaining_uses']).'.</p>';
            if(isset($step['cooperation']))$html.='<p>Совместная задача: '.self::esc($step['cooperation']['progress']).' / '.self::esc($step['cooperation']['cap']).(!empty($step['cooperation']['completed'])?' · завершена':'').'.</p>';
            foreach($step['strikes'] as $strike){
                if(count($step['strikes'])>1 || $strike['target_id']!==$strike['original_target'])$html.='<p>'.self::esc($strike['original_name']??self::entity($strike['original_target'])).($strike['target_id']!==$strike['original_target']?' → '.self::esc($strike['target_name']??self::entity($strike['target_id'])):'').' · '.self::esc(self::outcome($strike['outcome'])).'.</p>';
                $html.=self::events($strike['events']);
                if($strike['damage']>0 && !array_filter($strike['events'],static fn($e)=>$e['type']==='damage'))$html.='<p>Сохранённый урон после защиты: '.self::esc($strike['damage']).'.</p>';
                foreach($strike['reactions'] as $r){$html.='<section class="af-acs-card__section"><h6>Реакция · '.self::esc($r['entity_name']??self::entity($r['entity_id']??null)).'</h6><p>'.self::esc(self::phase($r['phase']??null)).': '.(!empty($r['executed'])?'исполнена':'пропущена — '.self::esc(self::reason($r['reason']??null))).(isset($r['redirect_success'])?'; перехват '.($r['redirect_success']?'успешен':'неуспешен'):'').'.</p>'.self::events($r['events']).'</section>';}
                $html.=self::technical($strike);
            }
            $mechanics=[];foreach($step['mechanics'] as $e){$line=match($e['type']){'form'=>'Форма: '.(['primary_form'=>'основная','form_modifier'=>'модификатор','cosmetic_form'=>'внешний вид'][$e['slot']]??'слой формы').' · '.(['override'=>'замещение','modifier'=>'модификатор','hybrid'=>'гибрид'][$e['mode']]??'сохранённый режим').' · осталось '.$e['remaining'],'granted_ability'=>'Временная способность: осталось применений '.$e['remaining_uses'],'effect_transfer'=>'Эффект перенесён: '.$e['previous_effect_id'].' → '.$e['effect_id'],'reflect_effect'=>'Отражение: '.($e['executed']?'исполнено':'не исполнено'),default=>''};if($line!=='')$mechanics[]=$line;}$html.=self::section('Связанные эффекты после хода',$mechanics);
            foreach($step['prepared'] as $p){$html.='<section class="af-acs-card__section"><h6>Подготовленное действие</h6><p>'.self::esc(self::phase($p['phase'])).' · '.self::esc(self::outcome($p['outcome'])).(isset($p['reason'])?' · '.self::esc(self::reason($p['reason'])):'').'.</p>'.self::actions($p['actions']);$links=[];foreach(['source_event_id'=>'Исходное событие','parent_event_id'=>'Родительское событие','execution_id'=>'Исполнение'] as $key=>$label)if(isset($p[$key]))$links[]=$label.' #'.$p[$key];if($links)$html.='<details><summary>Связь с подготовкой</summary><p>'.self::esc(implode(' · ',$links)).'</p></details>';$html.='</section>';}
            if($step['spawns'])$html.=self::section('Создание существ и объектов',array_map(static fn($s)=>($s['kind']??null)==='object'?'Создан объект окружения':'Создано призванное существо',$step['spawns']));
            $html.='</li>';
        }return $html.'</ol>';
    }
    private static function boundaries(array $rows,bool $end):string
    {
        $html='';foreach($rows as $b){if(($b['phase']==='end')!==$end)continue;$events=self::events($b['events']);if($events!=='')$html.='<section class="af-acs-card__section"><h5>'.self::esc(self::phase($b['phase'])).'</h5>'.$events.'</section>';}return $html;
    }
    public static function render(array $card):string
    {
        $full=$card['mechanics_confirmed'] && $card['details']!==null;
        $header='ACS · '.$card['label'];
        if($full){$outcomes=[];foreach($card['details'] as $a)foreach($a['strikes'] as $s)$outcomes[self::outcome($s['outcome'])]=true;
            $header=implode(', ',$card['actors']).(isset($card['round'])?' · раунд '.$card['round']:'').' · AP действий: '.$card['ap'].' · подтверждено: '.implode(', ',array_keys($outcomes));}
        $html='<aside class="af-acs-card" aria-label="Боевой результат ACS"><details class="af-acs-card__disclosure"><summary>'.self::esc($header).'</summary><div class="af-acs-card__body">';
        if(!$card['mechanics_confirmed'])$html.='<p>Боевой результат ещё не применён.</p>';
        elseif(!$full)$html.='<p>Результат подтверждён. Подробности ограничены правилами видимости сессии.</p>';
        else{
            $html.='<p>Сохранённая история подтверждённого хода. Обычное редактирование поста её не изменяет.</p>'.self::boundaries($card['boundaries'],false).self::actions($card['details']).self::boundaries($card['boundaries'],true);
            if(isset($card['encounter_id']))$html.='<p><a href="misc.php?action=acs_archive&amp;session_id='.rawurlencode($card['session_id']).'&amp;encounter_id='.rawurlencode($card['encounter_id']).'&amp;pid='.rawurlencode($card['pid']).'">Журнал и связанные корректировки</a></p>';
            if($card['spawns'])$html.=self::section('Сохранённые призывы и объекты',array_map(static fn($s)=>(($s['kind']??null)==='object'?'Объект':'Призыв').' #'.$s['entity_id'],$card['spawns']));
        }
        if(!empty($card['can_manage'])&&$card['status']==='recovery_required')$html.='<p><a href="misc.php?action=acs_recovery&amp;session_id='.rawurlencode($card['session_id']).'&amp;submission_id='.rawurlencode($card['submission_id']).'">Проверить прежнюю отправку</a></p>';
        return $html.'</div></details></aside>';
    }
}
