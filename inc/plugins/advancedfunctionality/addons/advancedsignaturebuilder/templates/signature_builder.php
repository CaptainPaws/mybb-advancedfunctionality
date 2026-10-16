<?php
if (!defined('IN_MYBB')) die('No direct access');
$e=static fn($v)=>htmlspecialchars_uni((string)$v);
$assetBase=rtrim((string)$mybb->settings['bburl'],'/').'/inc/plugins/advancedfunctionality/addons/advancedsignaturebuilder/assets/';
$limits=af_asb_limits();
$icons=[
 'eye'=>'<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
 'bookmark'=>'<path d="M6 3h12v18l-6-4-6 4Z"/>',
 'save'=>'<path d="M4 3h13l4 4v14H3V3Z"/><path d="M7 3v6h10V3M7 21v-9h10v9"/>',
 'image'=>'<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8" cy="8" r="1.4"/><path d="m3 17 5-5 4 4 4-6 5 6"/>',
 'trash'=>'<path d="M4 6h16M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7M14 10v7"/>',
 'up'=>'<path d="m6 14 6-6 6 6"/>','down'=>'<path d="m6 10 6 6 6-6"/>',
 'plus'=>'<path d="M12 4v16M4 12h16"/>','arrow'=>'<path d="M3 12h18m-7-7 7 7-7 7"/>',
 'info'=>'<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7v1"/>',
 'flag'=>'<path d="M5 22V3m0 1 7-2 7 3-3 5 3 5-7-3-7 2m7-12v10"/>',
 'gear'=>'<path d="m10 2 4 0 .8 3 2.4 1.4 3-.8 2 3.5-2.2 2.2v2.8l2.2 2.2-2 3.5-3-.8L14.8 20l-.8 3h-4l-.8-3-2.4-1.4-3 .8-2-3.5L4 13.7v-2.8L1.8 8.7l2-3.5 3 .8L9.2 4.6Z"/><circle cx="12" cy="12" r="4"/>',
];
$icon=static fn($name)=>'<svg class="af-asb__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.$icons[$name].'</svg>';
$descriptions=['minimal'=>'Заголовок, текст<br>и кнопки-ссылки.','quote'=>'Выделенная цитата<br>с оформлением.','card'=>'Аватар, текст<br>и ссылки.','gallery'=>'Несколько изображений<br>в ряд с описанием.'];
ob_start(); ?>
<form method="post" action="usercp.php" class="af-asb" data-af-asb-builder data-aa-gallery-picker>
<input type="hidden" name="my_post_key" value="<?= $e($mybb->post_code) ?>">
<input type="hidden" name="action" value="do_editsig">
<input type="hidden" name="af_signature_builder" value="1">
<input type="hidden" name="af_signature_model" data-af-sig-model>
<p class="af-asb__intro">Создайте содержимое своей подписи из готовых шаблонов. Оформление, цвета, шрифты и декоративные элементы настраиваются отдельно в <a href="<?= $e(af_asb_studio_url($uid)) ?>">APStudio</a>.</p>
<?= $legacy.$backup ?>
<p role="status" aria-live="polite" data-af-sig-status><?= $e($status) ?></p>
<div class="af-asb__layout">
<div class="af-asb__controls">
<section class="af-asb__panel"><header class="af-asb__heading"><span class="af-asb__step" aria-hidden="true">1</span><div><h2>Выбор шаблона</h2><p>Выберите подходящий шаблон для вашей подписи. Его оформление будет взято из настроек в <a href="<?= $e(af_asb_studio_url($uid)) ?>">APStudio</a>.</p></div></header>
<div class="af-asb__templates">
<?php foreach(af_asb_templates() as $key=>[$name,$description]): ?>
<label class="af-asb__template<?= $model['template']===$key?' is-active':'' ?>">
<input type="radio" name="af_asb_template" value="<?= $e($key) ?>" <?= $model['template']===$key?'checked':'' ?>>
<img class="af-asb__thumbnail" src="<?= $e($assetBase.'template-'.$key.'.svg') ?>" alt="" width="240" height="112">
<strong><?= $e($name) ?></strong><small><?= $descriptions[$key] ?></small></label>
<?php endforeach ?>
</div></section>
<section class="af-asb__panel"><header class="af-asb__heading"><span class="af-asb__step" aria-hidden="true">2</span><div><h2>Основные поля</h2><p>Заполните основные текстовые поля. Вы можете отключить ненужные элементы.</p></div></header>
<?php foreach(['title'=>'Заголовок','subtitle'=>'Подзаголовок','text'=>'Основной текст / цитата'] as $key=>$name): ?>
<div class="af-asb__field"><label for="af-asb-<?= $key ?>"><?= $name ?></label><div class="af-asb__input-wrap">
<?php if($key==='text'): ?><textarea id="af-asb-text" rows="3" maxlength="<?= $limits[$key] ?>" data-asb-text="text"><?= $e($model[$key]) ?></textarea>
<?php else: ?><input type="text" id="af-asb-<?= $key ?>" maxlength="<?= $limits[$key] ?>" data-asb-text="<?= $key ?>" value="<?= $e($model[$key]) ?>"><?php endif ?>
<output data-asb-count="<?= $key ?>"><?= mb_strlen($model[$key]).'/'.$limits[$key] ?></output></div>
<?php if($key!=='text'): ?><label class="af-asb__switch"><input type="checkbox" data-asb-show="<?= $key ?>" <?= $model['show_'.$key]?'checked':'' ?>><span>Показывать <?= $key==='title'?'заголовок':'подзаголовок' ?></span></label><?php else: ?><label class="af-asb__switch"><input type="checkbox" data-asb-show="divider" <?= $model['show_divider']?'checked':'' ?>><span>Показывать разделитель</span></label><?php endif ?>
</div><?php endforeach ?>
</section>
<section class="af-asb__panel"><header class="af-asb__heading"><span class="af-asb__step" aria-hidden="true">3</span><div><h2>Дополнительные поля</h2><p>Добавьте изображения, ссылки и дополнительную информацию. Все поля необязательны.</p></div></header>
<div class="af-asb__extras"><section class="af-asb__image-group"><h3>Дополнительные изображения</h3><div class="af-asb__images">
<?php foreach($model['images'] as $slot=>$image): $index=$slot+1;$name='Изображение '.$index; ?>
<fieldset class="af-asb__image" data-asb-image="<?= $index ?>"><legend><?= $name ?></legend>
<div class="af-asb__upload"><span class="af-asb__image-placeholder"><?= $icon('image') ?></span><img data-asb-image-thumb alt="Предпросмотр: <?= $name ?>" hidden><button type="button" data-asb-image-pick <?= $seed['image_allowed']?'':'disabled' ?>>Выбрать файл</button></div>
<small>JPG, PNG, WebP, GIF</small>
<details class="af-asb__image-settings"><summary title="URL и параметры изображения">URL / параметры</summary>
<label><input type="checkbox" data-asb-image-enabled <?= $image['enabled']?'checked':'' ?> <?= $seed['image_allowed']?'':'disabled' ?>> Показывать</label>
<label><span class="af-asb__sr-only">URL: <?= $name ?></span><input type="url" maxlength="2048" placeholder="https://…/image.gif" value="<?= $e($image['url']) ?>" data-asb-image-url <?= $seed['image_allowed']?'':'disabled' ?>></label>
<button type="button" data-asb-image-clear><?= $icon('trash') ?> Удалить</button></details></fieldset>
<?php endforeach ?></div></section>
<section class="af-asb__links"><h3>Кнопки-ссылки</h3><div data-asb-links></div><div class="af-asb__add-link"><button type="button" data-asb-add-link><?= $icon('plus') ?> Добавить кнопку</button><small>До 6 кнопок</small></div></section></div>
<div class="af-asb__field af-asb__field--status"><label for="af-asb-status"><strong>Подпись автора / статус</strong> <span>(необязательно)</span></label><div class="af-asb__input-wrap"><input type="text" id="af-asb-status" data-asb-text="status" maxlength="<?= $limits['status'] ?>" value="<?= $e($model['status']) ?>"><output data-asb-count="status"><?= mb_strlen($model['status']).'/'.$limits['status'] ?></output></div></div>
</section></div>
<aside class="af-asb__sidebar"><section class="af-asb__panel"><header class="af-asb__heading"><span class="af-asb__step" aria-hidden="true"><?= $icon('eye') ?></span><div><h2>Предпросмотр подписи</h2><p>Так подпись будет выглядеть в сообщениях форума.</p></div></header>
<style data-af-sig-preview-style><?= $preview['css'] ?></style><div class="af-asb__preview" data-af-sig-preview><?= $preview['html'] ?></div></section>
<?php if(function_exists('af_aa_surface_settings')): ?>
<section class="af-asb__panel af-asb__studio"><div class="af-asb__studio-heading"><span class="af-asb__studio-emblem"><?= $icon('gear') ?></span><div><h2>Оформление в APStudio</h2><p>Цвета, шрифты, стиль кнопок, декоративные элементы и общий визуальный вид подписи настраиваются в <a href="<?= $e(af_asb_studio_url($uid)) ?>">APStudio</a>.<br>Здесь вы создаёте только содержимое и выбираете шаблон.</p></div></div><a class="af-asb__studio-link" href="<?= $e(af_asb_studio_url($uid)) ?>"><?= $icon('flag') ?><span>Настроить оформление в APStudio</span><?= $icon('arrow') ?></a><p class="af-asb__studio-note"><?= $icon('info') ?><span>Изменения в оформлении применяются автоматически<br>ко всем элементам вашей подписи.</span></p></section>
<?php else: ?><section class="af-asb__panel"><p>Используется стандартное оформление темы. Для визуальных пресетов включите AdvancedAppearance.</p></section><?php endif ?>
</aside></div>
<div class="af-asb__actions"><button type="button" data-af-sig-draft><?= $icon('bookmark') ?> Сохранить шаблон</button><button type="button" data-af-sig-preview-button><?= $icon('eye') ?> Предпросмотр</button><button type="submit" class="af-asb__publish" disabled><?= $icon('save') ?> Обновить подпись</button></div>
<details class="af-asb__advanced"><summary>Фон и дополнительные параметры подписи</summary>
<div class="af-asb__advanced-body"><fieldset class="af-asb__image af-asb__image--background" data-asb-image="0"><legend>Фоновое изображение</legend>
<label><input type="checkbox" data-asb-image-enabled <?= $model['background']['enabled']?'checked':'' ?> <?= $seed['image_allowed']?'':'disabled' ?>> Показывать фон</label>
<img data-asb-image-thumb alt="Предпросмотр фона" hidden><label>URL изображения<input type="url" maxlength="2048" placeholder="https://…/image.gif" value="<?= $e($model['background']['url']) ?>" data-asb-image-url <?= $seed['image_allowed']?'':'disabled' ?>></label>
<button type="button" data-asb-image-pick <?= $seed['image_allowed']?'':'disabled' ?>>Выбрать файл</button><button type="button" data-asb-image-clear>Удалить</button></fieldset>
<label class="af-asb__switch"><input type="checkbox" data-asb-show="status" <?= $model['show_status']?'checked':'' ?>><span>Показывать подпись автора / статус</span></label>
<fieldset class="af-asb__post-options"><legend>Подпись в опубликованных сообщениях</legend>
<?php foreach(['0'=>'Оставить текущие настройки','enable'=>'Включить во всех моих сообщениях','disable'=>'Отключить во всех моих сообщениях'] as $value=>$label): ?><label><input type="radio" name="updateposts" value="<?= $value ?>" <?= (string)($mybb->input['updateposts']??'0')===(string)$value?'checked':'' ?>> <?= $label ?></label><?php endforeach ?>
</fieldset>
</div></details>
<?php foreach(['trash','up','down'] as $name): ?><template data-asb-icon="<?= $name ?>"><?= $icon($name) ?></template><?php endforeach ?>
<script type="application/json" data-af-sig-seed><?= json_encode($seed,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<noscript>Для интерактивного конструктора нужен JavaScript. Существующая подпись не меняется.</noscript>
</form>
<?php return ob_get_clean();
