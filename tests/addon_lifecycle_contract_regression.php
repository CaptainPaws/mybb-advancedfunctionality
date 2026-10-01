<?php

declare(strict_types=1);

// Unit coverage for the low-level runtime guard helpers. ACP lifecycle coverage
// belongs to addon_admin_lifecycle_integration.php, which calls AF_Admin.

$root = dirname(__DIR__);
$core = file_get_contents($root.'/inc/plugins/advancedfunctionality.php');
$router = file_get_contents($root.'/inc/plugins/advancedfunctionality/admin/router.php');
$menu = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php');

function lifecycleFunction(string $source, string $name): string
{
    $start = strpos($source, 'function '.$name.'(');
    if ($start === false) throw new RuntimeException('Missing '.$name);
    $brace = strpos($source, '{', $start);
    $depth = 0;
    for ($i = $brace, $length = strlen($source); $i < $length; $i++) {
        if ($source[$i] === '{') $depth++;
        if ($source[$i] === '}' && --$depth === 0) return substr($source, $start, $i - $start + 1);
    }
    throw new RuntimeException('Unterminated '.$name);
}

eval(lifecycleFunction($core, 'af_is_addon_enabled'));
eval(lifecycleFunction($core, 'af_lifecycle_transition'));
eval(lifecycleFunction($core, 'af_run_addon_callback'));

$mybb = (object)['settings' => []];
$GLOBALS['mybb'] =& $mybb;
$addons = ['knowledgebase', 'advancedbyddylist', 'advancededitor', 'advancedthreadfields', 'advancedmenu'];

foreach ($addons as $addon) {
    $calls = 0;
    for ($cycle = 1; $cycle <= 2; $cycle++) {
        $mybb->settings['af_'.$addon.'_enabled'] = '1';
        af_lifecycle_transition($addon, true);
        af_run_addon_callback($addon, static function () use (&$calls): void { $calls++; });

        // Model registries which existed before the setting changed.
        $GLOBALS['af_assets_queue'] = ['css' => [['owner' => $addon]], 'js' => []];
        $GLOBALS['af_advancedmenu_system_registry'] = ['stale' => ['source_addon' => $addon]];
        $GLOBALS['af_self_heal_registry'] = [$addon => static function (): void {}];
        $mybb->settings['af_'.$addon.'_enabled'] = '0';
        af_lifecycle_transition($addon, false);

        $inactiveCalls = ['provider' => 0, 'frontend_owner' => 0, 'menu_provider' => 0, 'self_heal' => 0];
        foreach (array_keys($inactiveCalls) as $kind) {
            af_run_addon_callback($addon, static function () use (&$inactiveCalls, $kind): void { $inactiveCalls[$kind]++; });
        }
        if (array_sum($inactiveCalls) !== 0) throw new RuntimeException("{$addon}: inactive provider/owner/menu/self-heal callback ran");
        if (af_is_addon_enabled($addon)) throw new RuntimeException("{$addon}: inactive state was stale in cycle {$cycle}");
        if (($GLOBALS['af_assets_queue']['css'] ?? []) !== []) throw new RuntimeException("{$addon}: asset registry survived disable");
        if (isset($GLOBALS['af_advancedmenu_system_registry'])) throw new RuntimeException("{$addon}: menu registry survived disable");
        if (isset($GLOBALS['af_self_heal_registry'])) throw new RuntimeException("{$addon}: self-heal registry survived disable");

        $mybb->settings['af_'.$addon.'_enabled'] = '1';
        af_lifecycle_transition($addon, true);
        af_run_addon_callback($addon, static function () use (&$calls): void { $calls++; });
    }
    if ($calls !== 4) throw new RuntimeException("{$addon}: inactive callback ran or reactivation callback was lost");
}

foreach ([$core, $router] as $source) {
    $disable = substr($source, strpos($source, 'public static function disableAddon'), 1500);
    $callback = strpos($disable, "\$fn = 'af_'.\$id.'_deactivate'");
    if ($callback === false || str_contains($disable, 'af_lifecycle_transition(')) {
        throw new RuntimeException('ACP disable must use reloaded settings without broad request invalidation');
    }
}
if (!str_contains($core, "\$allowed = \$addonId === '' || !function_exists('af_is_addon_enabled') || af_is_addon_enabled(\$addonId)")) {
    throw new RuntimeException('Manifest permission does not require active state');
}
if (!str_contains($menu, 'af_run_addon_callback($owner, $function)')
    || !str_contains($menu, "af_is_addon_enabled(\$owner)")) {
    throw new RuntimeException('Menu providers do not use the shared lifecycle guard');
}
$forbiddenLifecycleHack = <<<'REGEX'
~if\s*\(\s*\$addonId\s*===\s*['"](?:knowledgebase|advancedbyddylist|advancededitor|advancedthreadfields|advancedmenu)~
REGEX;
if (preg_match($forbiddenLifecycleHack, $core)) {
    throw new RuntimeException('Addon-specific lifecycle exclusion added to AF core');
}

echo "AF runtime guard unit contract passed twice for Knowledge Base, Advanced Buddy List, Advanced Editor, AdvancedThreadFields, and AdvancedMenu.\n";
