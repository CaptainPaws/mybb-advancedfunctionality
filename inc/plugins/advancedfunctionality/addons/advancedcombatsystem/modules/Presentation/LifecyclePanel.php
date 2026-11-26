<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Existing GM dialog step and native fallback. Reads only; forms reuse command handlers. */
final class AF_ACS_LifecyclePanel
{
    private static function esc(mixed $value): string { return htmlspecialchars_uni((string)$value); }

    public static function code(Throwable $error): string
    {
        $code=$error->getMessage();
        return preg_match('/\AACS_[A-Z0-9_]{1,90}\z/D',$code)?$code:'UNEXPECTED_EXCEPTION';
    }

    private static function readError(Throwable $error,string $phase): void
    {
        $id=bin2hex(random_bytes(16));$code=self::code($error);
        error_log('ACS gm.lifecycle_overview error_id='.$id.' phase='.$phase.' code='.$code);
        echo '<p role="alert">Диагностика недоступна. Код: '.self::esc($code).' · Фаза: '.self::esc($phase).' · error_id='.$id.'</p>';
    }

    public static function render(AF_ACS_EncounterService $service,array $session,?array $view,string $url,string $base,bool $finish=false): void
    {
        $encounter=$view['encounter']??null;
        $round=null;
        if ($encounter!==null) {
            echo '<section class="atf-card" aria-label="Управление боем"><h3>'.($finish?'Завершить столкновение':'Управление боем').'</h3>';
            $description=match($encounter['status']) {
                'active'=>'Столкновение активно',
                'paused'=>'Столкновение на паузе',
                'pending_resolution'=>'Исход ожидает подтверждения',
                'completed'=>'Столкновение завершено',
                default=>AF_ACS_GmPanel::state($encounter['status']),
            };
            echo '<p><strong>'.self::esc($description).((int)$encounter['round_number']>0?' · Раунд '.self::esc($encounter['round_number']):'').'</strong></p>';
            try {
                $round=$service->lifecycleOverview($session['id'],$encounter['id'],$session['tid']);
                if ($round['encounter_version']!==$encounter['encounter_version']) {
                    $round=null;
                    throw new RuntimeException('ACS_VERSION_CONFLICT');
                }
            } catch (Throwable $error) {
                self::readError($error,'read_round_and_publication');
            }
            echo '<p>Незавершённые ходы текущего раунда: '.self::esc($round['pending_turns']??'—').'</p>';
            $recoveryUrl=self::recoveryUrl($session['id']);
            if ($round['publication_blocked']??false) {
                echo '<p>BLOCKED · Есть незавершённая публикация. <a href="'.self::esc($recoveryUrl).'">Открыть восстановление</a></p>';
            }
            if ($finish) {
                echo '<p><a class="atf-button" href="'.self::esc($url).'">К управлению боем</a></p>';
                self::finish($service,$view,$url.'&gm_finish=1',$round);
            } else {
                if ($session['status']==='active' && in_array($encounter['status'],['active','paused'],true)
                    && $round!==null && !$round['publication_blocked']) {
                    if ($round['round_open'] && ($encounter['status']==='active' || $encounter['paused_from']==='active')) {
                        require_once __DIR__.'/RoundManagement.php';
                        AF_ACS_RoundManagementPage::pauseResumeForm($url,$view);
                    } else {
                        echo '<p>BLOCKED · Для паузы или возобновления нужен подходящий открытый раунд.</p>';
                    }
                }
                echo '<nav aria-label="Действия столкновения"><a class="atf-button" href="'
                    .self::esc(AF_ACS_GmPanel::stepUrl($base,$encounter['id'],'roster')).'">Управление составом</a>';
                if (in_array($encounter['status'],['active','paused','pending_resolution'],true)) {
                    echo '<a class="atf-button" href="'.self::esc($url.'&gm_finish=1').'">Завершить столкновение</a>';
                }
                echo '</nav>';
            }
            echo '</section>';
        }
        self::session($session,$url,$base);
    }

    private static function finish(AF_ACS_EncounterService $service,array $view,string $url,?array $round): void
    {
        $s=$view['session'];$e=$view['encounter'];
        try {
            $state=$service->resolution($s['id'],$e['id']);
            if ($state['encounter_version']!==$e['encounter_version'] || $state['expected_version']!==$s['session_version']) {
                throw new RuntimeException('ACS_VERSION_CONFLICT');
            }
        } catch (Throwable $error) {
            self::readError($error,'read_outcome');
            return;
        }
        if ($state['confirmed']) {
            echo '<p>Исход подтверждён. История сохранена. Добыча и награды управляются отдельно.</p>';
            echo '<p><a href="'.self::esc('misc.php?action=acs_loot&session_id='.$s['id'].'&encounter_id='.$e['id']).'">Добыча и награды</a></p>';
            return;
        }

        $pending=$state['pending'];
        $labels=['victory'=>'Победа','draw'=>'Ничья','inconclusive'=>'Без окончательного результата'];
        if ($e['status']==='pending_resolution' && $pending!==null) {
            $condition=match($pending['condition']) {
                'last_active_side'=>'Одна активная враждебная сторона',
                'no_active_sides'=>'Не осталось активных сторон',
                default=>$pending['condition'],
            };
            echo '<h4>Штатное завершение</h4><p>Ожидает подтверждения решение #'.self::esc($pending['resolution_id'])
                .' · '.self::esc($condition).'.</p><p>Доступные исходы: '
                .self::esc(implode(', ',array_map(static fn($outcome)=>$labels[$outcome]??$outcome,$pending['outcomes']))).'.</p>';
            echo '<p>Подтверждение сохранит исход и завершит столкновение. Закрытие сессии выполняется отдельно.</p>';
            if ($s['status']==='active' && $round!==null && !$round['publication_blocked']) {
                AF_ACS_GmPanel::form($url,'outcome_confirm',$s,$e);
                AF_ACS_GmPanel::fields(['resolution_id'=>$pending['resolution_id']]);
                echo '<label>Исход <select name="outcome" required><option value="">Выберите исход</option>';
                foreach ($pending['outcomes'] as $outcome) {
                    echo '<option value="'.self::esc($outcome).'">'.self::esc($labels[$outcome]??$outcome).'</option>';
                }
                echo '</select></label>';
                AF_ACS_GmPanel::submit('Подтвердить исход столкновения');
                if ($pending['allow_continuation']) {
                    AF_ACS_GmPanel::form($url,'outcome_continue',$s,$e);
                    AF_ACS_GmPanel::fields(['resolution_id'=>$pending['resolution_id']]);
                    AF_ACS_GmPanel::submit('Продолжить по разрешённой политике');
                }
            } elseif ($s['status']!=='active') {
                echo '<p>BLOCKED · Для подтверждения нужна активная сессия.</p>';
            }
        } else {
            echo '<h4>Штатное завершение</h4><p>BLOCKED · Серверного кандидата исхода для подтверждения нет.</p>';
        }

        echo '<h4>Прекратить столкновение без победителя</h4>';
        if ($e['status']==='pending_resolution' && in_array('inconclusive',$pending['outcomes']??[],true)) {
            echo '<p>Для этого кандидата сервер разрешил исход «Без окончательного результата». Выберите его в форме штатного завершения, когда подтверждение доступно.</p>';
        } else {
            echo '<p>BLOCKED · Отдельного перехода для досрочного прекращения в текущем контракте нет. Исход без окончательного результата доступен, когда его предложит сервер.</p>';
        }
    }

    private static function session(array $session,string $url,string $base): void
    {
        echo '<section class="atf-card" aria-label="Управление сессией"><h3>Управление сессией</h3><p><strong>Сессия: '
            .self::esc(AF_ACS_GmPanel::state($session['status'])).'</strong></p>';
        echo '<p>Закрытие завершает работу с сессией, сохраняя историю, персонажей, операции и незавершённые обязательства.</p>';
        try {
            $view=af_advancedcombatsystem_sessions()->view($session['id'],true);
        } catch (Throwable $error) {
            self::readError($error,'read_session_obligations');
            echo '</section>';
            return;
        }
        if (!$view['can_audit']) {
            echo '<p>Для управления закрытием нужны права мастера этой сессии.</p></section>';
            return;
        }
        $data=$view['outstanding_obligations'];
        echo '<ul><li>Ожидающие обязательства: '.count($data['pending']).'</li><li>Требуют восстановления: '
            .count($data['recovery_required']).'</li><li>Блокируют закрытие или архивирование: '.count($data['explicitly_blocked']).'</li></ul>';
        $blocked=$data['explicitly_blocked'];
        if ($blocked) {
            $sources=array_count_values(array_column($blocked,'source'));
            $labels=['encounter'=>'Незавершённые столкновения','publication'=>'Незавершённые публикации',
                'sealed_action'=>'Запечатанные действия','prepared_execution'=>'Подготовленные срабатывания',
                'prepared_cost'=>'Обязательства подготовленных действий','custody'=>'Хранение предметов',
                'operation'=>'Операции без подтверждённого маршрута восстановления','loot_generation'=>'Формирование добычи',
                'reward_distribution'=>'Распределение наград','reward'=>'Награды','achievement_source'=>'Источники достижений',
                'compensation'=>'Компенсации','statistics'=>'Доставка статистики'];
            echo '<ul>';
            foreach ($sources as $source=>$count) {
                echo '<li>'.self::esc($labels[$source]??$source).' · '.$count.'</li>';
            }
            echo '</ul>';
            foreach ($blocked as $row) {
                if ($row['source']==='encounter' && preg_match('/\A[1-9][0-9]{0,19}\z/D',$row['reference'])) {
                    $target=AF_ACS_GmPanel::stepUrl($base,$row['reference'],'settings').'&gm_finish=1';
                    echo '<p><a href="'.self::esc($target).'">Проверить завершение столкновения #'.self::esc($row['reference']).'</a></p>';
                }
            }
            echo '<p>Закрытие заблокировано. Проверьте перечисленные обязательства перед следующим шагом.</p>';
        }
        if ($blocked || $data['recovery_required'] || $data['pending']) {
            echo '<p><a href="'.self::esc(self::recoveryUrl($session['id'])).'">Открыть восстановление операций</a></p>';
        }
        if ($view['session']['session_version']!==$session['session_version']) {
            echo '<p>Состояние изменилось. Обновите панель.</p></section>';
            return;
        }
        foreach (['request_close'=>'Подготовить закрытие','close'=>'Закрыть боевую сессию',
            'archive'=>'Архивировать','reopen_session'=>'Переоткрыть сессию'] as $op=>$label) {
            if (!empty($view['can_'.($op==='reopen_session'?'reopen':$op)]) && (!$blocked || $op==='reopen_session')) {
                AF_ACS_GmPanel::form($url,'session_'.$op,$session);
                AF_ACS_GmPanel::submit($label);
            }
        }
        echo '</section>';
    }

    public static function recoveryUrl(string $sid): string
    {
        return 'misc.php?action=acs_recovery&session_id='.$sid;
    }
}
