<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

class AF_Admin_KnowledgeBase
{
    public static function dispatch(): void
    {
        global $db;

        echo '<div class="group">';
        echo '<h3>Knowledge Base</h3>';
        echo '<div class="border_wrapper" style="padding:12px;">';
        echo '<p>Аддон «База знаний» хранит сущности по паре (type, key) и используется другими модулями AF.</p>';

        $gid = (int)$db->fetch_field(
            $db->simple_select('settinggroups', 'gid', "name='af_kb'", ['limit' => 1]),
            'gid'
        );
        if ($gid) {
            echo '<p><a class="button" href="index.php?module=config-settings&amp;gid='.$gid.'">Перейти к настройкам</a></p>';
        } else {
            echo '<p>Группа настроек не найдена. Проверьте установку аддона.</p>';
        }

        echo '</div></div>';
    }
}
