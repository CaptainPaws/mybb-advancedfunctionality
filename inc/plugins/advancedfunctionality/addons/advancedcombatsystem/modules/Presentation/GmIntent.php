<?php
if(!defined('IN_MYBB')){die('No direct access');}

/** Presentation confirmation only; services remain the authority for rights, versions and execution. */
final class AF_ACS_GmIntent
{
    public static function consequence(string $op):?string
    {
        return match($op){
            'set_role','remove_member','transfer_owner'=>'Доступ или полномочия участника изменятся. Передача управления не переносит собственность и не возвращает AP.',
            'controller_set','controller_assign','delegation_revoke','delegation_grant'=>'Изменится право управления. Уже сохранённая история и общий бюджет AP не сбрасываются; новые действия повторно проверят полномочия.',
            'participant_visibility','relation_set','side_change'=>'Изменятся правила доступности целей или стороны. Прошлые результаты и текущие расходы не пересчитываются.',
            'participant_remove'=>'Существо будет исключено из текущего состава. Его регистрация в сессии и характеристики сохранятся. Позднее его можно добавить снова.',
            'side_accent'=>'Цветовой акцент стороны будет изменён.',
            'side_delete'=>'Будут удалены сторона и все её отношения с другими сторонами.',
            'round_start','ambush_start'=>'Будет запущено столкновение и закреплён roster. Подготовки исполняются только действующим серверным механизмом; просмотр не повторяет запуск.',
            'manage_pause','manage_resume','manage_deadline_set','manage_extend_deadline'=>'Изменится состояние хода или срок ожидания. Команда не возвращает AP и не перебрасывает сохранённые результаты.',
            'manage_gm_skip','manage_gm_forfeit','manage_deadline_process','manage_round_next'=>'Ход или раунд может быть закрыт; разрешённые границы исполняются сервером. Не повторяйте команду с новым ключом после неизвестного ответа.',
            'end_conditions_set'=>'Будут закреплены условия завершения в новом снимке конфигурации до запуска. Во время боя эти правила не подменяются; победитель выбирается только из серверного кандидата.',
            'loot_allocation_close'=>'Заявки будут закрыты, победитель закреплён по сохранённым правилам. Ошибка выдачи не отменяет победителя и не разрешает новый бросок.',
            'loot_issue','reward_issue'=>'Будет запрошена отдельная выдача через owner-сервис. Неизвестный ответ требует проверки прежней операции, а не новой выдачи.',
            'reward_distribute'=>'Будут закреплены обязательства перед получателями по сохранённому источнику. Это ещё не начисление EXP или валюты.',
            'statistics_sync','statistics_recover'=>'Будет обработана сохранённая статистика завершённого столкновения. Боевые события и награды не пересчитываются.',
            'outcome_confirm'=>'Будет подтверждён только предложенный сервером исход. Это завершает столкновение, но не закрывает сессию и не выдаёт награды автоматически.',
            'outcome_continue','rejoin_encounter','release'=>'Изменится разрешённое участие или продолжение боя. Это не создаёт новый оплаченный ход и не отменяет сохранённые события.',
            'request_close','close','archive','reopen_session'=>'Изменится состояние сессии. История, резервы и незавершённые обязательства сохраняются; автоматического возврата предметов или возобновления завершённых боёв нет.',
            'clock_rest_short','clock_rest_full','clock_combat_reset'=>'Будет выполнено разрешённое восстановление или сброс боевых кулдаунов. Это отдельная журналируемая операция, не изменение Inventory/Balance.',
            'custody'=>'Изменится только разрешённое временное хранение или использование предмета. Постоянная собственность не переносится; Inventory повторно проверит owner authorization и прежний резерв.',
            'recovery'=>'Решение изменит состояние прежней операции. Оно не подтверждает неизвестный PID, не создаёт пост и не исполняет бой от имени автора.',
            default=>null,
        };
    }
    public static function checkbox(string $op):string
    {
        $text=self::consequence($op);return $text===null?'':'<p class="af-acs-gm__warning">'.htmlspecialchars_uni($text).'</p><label><input type="checkbox" name="acs_confirm" value="1" required> Подтверждаю эти последствия</label>';
    }
    public static function requireConfirmation(string $op):void
    {
        if(self::consequence($op)!==null && ($_POST['acs_confirm']??null)!=='1' && ($_POST['confirm']??null)!=='1' && ($_POST['confirm_full']??null)!=='1')throw new RuntimeException('ACS_UI_CONFIRMATION_REQUIRED');
    }
}
