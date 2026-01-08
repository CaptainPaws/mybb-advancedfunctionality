<?php
/**
 * ACP controller for AdvancedProfileFields (AF router)
 *
 * Канон как в AdvancedThreadFields:
 * - URL строим через module=advancedfunctionality&af_view=advancedprofilefields
 * - внутренние действия: только do
 * - действия (apply/revert) выполняем через POST + my_post_key
 * - НЕ вызываем output_header/output_footer (иначе дубль меню/сайдбара внутри AF router)
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

// bootstrap аддона (чтобы af_apf_apply_template_patches() точно существовал в ACP)
$bootstrap = AF_ADDONS.'advancedprofilefields/advancedprofilefields.php';
if (file_exists($bootstrap)) {
    require_once $bootstrap;
}

if (!class_exists('AF_Admin_Advancedprofilefields')) {
    class AF_Admin_Advancedprofilefields
    {
        private const ROUTER_MODULE = 'advancedfunctionality';
        private const ROUTER_VIEW   = 'advancedprofilefields';

        private static function url(array $params = []): string
        {
            // В AF router аддоны открываются по af_view=...
            $base = [
                'module'  => self::ROUTER_MODULE,
                'af_view' => self::ROUTER_VIEW,
            ];

            $all = array_merge($base, $params);

            // action зарезервирован под роутер/ACP — никогда не тащим его
            unset($all['action']);

            return 'index.php?'.http_build_query($all, '', '&');
        }

        private static function go(array $params = []): void
        {
            admin_redirect(self::url($params));
        }

        private static function load_lang(): void
        {
            // Каноничный хелпер AF (если есть)
            if (function_exists('af_load_addon_lang')) {
                af_load_addon_lang('advancedprofilefields', true);
                af_load_addon_lang('advancedprofilefields', false);
                return;
            }

            // Фоллбек на твой загрузчик
            if (function_exists('af_advancedprofilefields_load_lang')) {
                af_advancedprofilefields_load_lang(true);
                af_advancedprofilefields_load_lang(false);
            }
        }

        public static function dispatch(): void
        {
            global $mybb, $db;

            self::load_lang();

            // Внутренние действия аддона — только do
            $do = (string)$mybb->get_input('do');

            // APPLY / REVERT — выполняем только через POST
            if (in_array($do, ['apply', 'revert'], true)) {
                if ($mybb->request_method !== 'post') {
                    // если кто-то пришёл GET'ом — просто вернём на главную страницы аддона
                    self::go();
                    return;
                }

                if (function_exists('verify_post_check')) {
                    verify_post_check($mybb->get_input('my_post_key'));
                }

                if (!function_exists('af_apf_apply_template_patches')) {
                    if (function_exists('flash_message')) {
                        flash_message('Функция патча не найдена: af_apf_apply_template_patches(). Проверь bootstrap/пути.', 'error');
                    }
                    self::go();
                    return;
                }

                af_apf_apply_template_patches($do === 'apply');

                if (function_exists('flash_message')) {
                    flash_message(
                        $do === 'apply' ? 'Патчи шаблонов применены.' : 'Патчи шаблонов откатаны.',
                        'success'
                    );
                }

                self::go();
                return;
            }

            // ---------- PAGE CONTENT (без output_header/footer) ----------
            echo '<h2 style="margin: 0 0 10px 0;">AdvancedProfileFields</h2>';

            $isPatched = function_exists('af_apf_is_patched') ? af_apf_is_patched() : false;


            echo '<div class="page_description">'
                . '<p>Аддон добавляет CSS-классы для дополнительных полей профиля (customfields) в профиле / UserCP / регистрации / постбите.</p>'
                . '<p><strong>Ключевые классы:</strong> <code>af-apf-name</code>, <code>af-apf-value</code>, <code>af-apf-row</code>, <code>af-apf-fid-*</code>, <code>af-apf-stat-posts</code>, <code>af-apf-stat-threads</code>.</p>'
                . '</div>';

            echo '<div style="margin: 12px 0; padding: 10px; border-left: 4px solid #888;">'
                . '<strong>Статус патча:</strong> '
                . ($isPatched ? '<span style="color:#0a0;">включён</span>' : '<span style="color:#a00;">не применён</span>')
                . '</div>';

            // ✅ КНОПКИ ЧЕРЕЗ POST (как канонично для действий в ACP)
            $actionUrl = htmlspecialchars_uni(self::url());
            $postKey   = htmlspecialchars_uni((string)$mybb->post_code);

            echo '<div style="display:flex; gap:10px; flex-wrap:wrap; margin: 12px 0;">';

            echo '<form action="'.$actionUrl.'" method="post" style="margin:0;">'
                . '<input type="hidden" name="my_post_key" value="'.$postKey.'" />'
                . '<input type="hidden" name="do" value="apply" />'
                . '<input type="submit" class="button" value="Применить патчи" />'
                . '</form>';

            echo '<form action="'.$actionUrl.'" method="post" style="margin:0;">'
                . '<input type="hidden" name="my_post_key" value="'.$postKey.'" />'
                . '<input type="hidden" name="do" value="revert" />'
                . '<input type="submit" class="button" value="Откатить патчи" />'
                . '</form>';

            echo '</div>';

            echo '<h3>Что патчим</h3><ul>'
                . '<li><code>member_profile_customfields_field</code> (+ multi)</li>'
                . '<li><code>usercp_profile_customfield</code></li>'
                . '<li><code>member_register_customfield</code></li>'
                . '<li><code>postbit_profilefield</code> (+ multi)</li>'
                . '<li><code>postbit_author_user</code> (posts/threads)</li>'
                . '</ul>';

            echo '<h3>Быстрый тест</h3>'
                . '<ol>'
                . '<li>Нажми «Применить патчи»</li>'
                . '<li>Открой любую тему → инспектор → найди вывод доп. поля профиля в постбите</li>'
                . '<li>Должны появиться <code>af-apf-name</code> и <code>af-apf-value</code></li>'
                . '</ol>';
        }
    }
}

// Алиасы — на случай разных сборок имени класса роутером
if (!class_exists('AF_Admin_AdvancedProfilefields')) {
    class AF_Admin_AdvancedProfilefields extends AF_Admin_Advancedprofilefields {}
}
if (!class_exists('AF_Admin_AdvancedProfileFields')) {
    class AF_Admin_AdvancedProfileFields extends AF_Admin_Advancedprofilefields {}
}
