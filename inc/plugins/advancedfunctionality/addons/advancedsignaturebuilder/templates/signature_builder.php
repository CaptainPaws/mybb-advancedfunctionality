<?php
if (!defined('IN_MYBB')) die('No direct access');
$e=static fn($v)=>htmlspecialchars_uni((string)$v);
$assetBase=rtrim((string)$mybb->settings['bburl'],'/').'/inc/plugins/advancedfunctionality/addons/advancedsignaturebuilder/assets/';
$limits=af_asb_limits();
ob_start(); ?>
<form method="post" action="usercp.php" class="af-asb" data-af-asb-builder data-aa-gallery-picker>
<input type="hidden" name="my_post_key" value="<?= $e($mybb->post_code) ?>">
<input type="hidden" name="action" value="do_editsig">
<input type="hidden" name="af_signature_builder" value="1">
<input type="hidden" name="af_signature_model" data-af-sig-model>
<p class="af-asb__intro">Создайте содержимое своей подписи из готовых шаблонов. Цвета, шрифты и декоративное оформление настраиваются отдельно в APStudio.</p>
<?= $legacy.$backup ?>
<p role="status" aria-live="polite" data-af-sig-status><?= $e($status) ?></p>
<div class="af-asb__layout">
<div class="af-asb__controls">
<section class="af-asb__panel"><header class="af-asb__heading"><span class="af-asb__step" aria-hidden="true">1</span><div><h2>Выбор шаблона</h2><p>Выберите композицию. Введённые данные сохраняются при переключении.</p></div></header>
<div class="af-asb__templates">
<?php foreach(af_asb_templates() as $key=>[$name,$description]): ?>
<label class="af-asb__template<?= $model['template']===$key?' is-active':'' ?>">
<input type="radio" name="af_asb_template" value="<?= $e($key) ?>" <?= $model['template']===$key?'checked':'' ?>>
<img class="af-asb__thumbnail" src="<?= $e($assetBase.'template-'.$key.'.svg') ?>" alt="" width="240" height="112">
<strong><?= $e($name) ?></strong><small><?= $e($description) ?></small></label>
<?php endforeach ?>
</div></section>
<section class="af-asb__panel"><header class="af-asb__heading"><span class="af-asb__step" aria-hidden="true">2</span><div><h2>Основные поля</h2><p>Заполните текст. Ненужные заголовки можно отключить.</p></div></header>
<?php foreach(['title'=>'Заголовок','subtitle'=>'Подзаголовок','text'=>'Основной текст / цитата'] as $key=>$name): ?>
<div class="af-asb__field"><label for="af-asb-<?= $key ?>"><?= $name ?></label><div class="af-asb__input-wrap">
<?php if($key==='text'): ?><textarea id="af-asb-text" rows="4" maxlength="<?= $limits[$key] ?>" data-asb-text="text"><?= $e($model[$key]) ?></textarea>
<?php else: ?><input type="text" id="af-asb-<?= $key ?>" maxlength="<?= $limits[$key] ?>" data-asb-text="<?= $key ?>" value="<?= $e($model[$key]) ?>"><?php endif ?>
<output data-asb-count="<?= $key ?>"><?= mb_strlen($model[$key]).'/'.$limits[$key] ?></output></div>
<?php if($key!=='text'): ?><label class="af-asb__switch"><input type="checkbox" data-asb-show="<?= $key ?>" <?= $model['show_'.$key]?'checked':'' ?>><span>Показывать <?= $key==='title'?'заголовок':'подзаголовок' ?></span></label><?php endif ?>
</div><?php endforeach ?>
</section>
<section class="af-asb__panel"><header class="af-asb__heading"><span class="af-asb__step" aria-hidden="true">3</span><div><h2>Дополнительные поля</h2><p>Добавьте изображения, ссылки и статус. Все поля необязательны.</p></div></header>
<div class="af-asb__extras"><div>
<div class="af-asb__images">
<?php foreach(array_merge([$model['background']],$model['images']) as $index=>$image): $name=$index===0?'Фоновое изображение':'Изображение '.$index; ?>
<fieldset class="af-asb__image<?= $index===0?' af-asb__image--background':'' ?>" data-asb-image="<?= $index ?>"><legend><?= $name ?></legend>
<label><input type="checkbox" data-asb-image-enabled <?= $image['enabled']?'checked':'' ?> <?= $seed['image_allowed']?'':'disabled' ?>> Показывать</label>
<img data-asb-image-thumb alt="Предпросмотр: <?= $name ?>" hidden>
<label class="af-asb__image-url"><span>URL изображения</span><input type="url" maxlength="2048" placeholder="https://…/image.gif" value="<?= $e($image['url']) ?>" data-asb-image-url <?= $seed['image_allowed']?'':'disabled' ?>></label>
<div class="af-asb__image-actions"><button type="button" data-asb-image-pick <?= $seed['image_allowed']?'':'disabled' ?>>Выбрать файл</button><button type="button" data-asb-image-clear>Удалить</button></div>
<small>JPG, PNG, WebP, GIF · лимиты Gallery</small></fieldset>
<?php endforeach ?></div>
<p class="af-asb__hint">Карточка использует первое изображение, галерея — до трёх. Итоговые ограничения подписи проверяет MyBB.</p>
</div><section class="af-asb__links"><h3>Кнопки-ссылки</h3><div data-asb-links></div><button type="button" data-asb-add-link>＋ Добавить кнопку</button><small>До 6 кнопок. Порядок меняется стрелками.</small></section></div>
<div class="af-asb__field"><label for="af-asb-status">Подпись автора / статус</label><div class="af-asb__input-wrap"><input type="text" id="af-asb-status" data-asb-text="status" maxlength="<?= $limits['status'] ?>" value="<?= $e($model['status']) ?>"><output data-asb-count="status"><?= mb_strlen($model['status']).'/'.$limits['status'] ?></output></div><label class="af-asb__switch"><input type="checkbox" data-asb-show="status" <?= $model['show_status']?'checked':'' ?>><span>Показывать</span></label></div>
</section></div>
<aside class="af-asb__sidebar"><section class="af-asb__panel"><header class="af-asb__heading"><span class="af-asb__step" aria-hidden="true">◉</span><div><h2>Предпросмотр подписи</h2><p>Так подпись будет выглядеть в сообщениях форума.</p></div></header>
<style data-af-sig-preview-style><?= $preview['css'] ?></style><div class="af-asb__preview" data-af-sig-preview><?= $preview['html'] ?></div></section>
<?php if(function_exists('af_aa_surface_settings')): ?>
<section class="af-asb__panel af-asb__studio"><h2>Оформление в APStudio</h2><p>Цвета, шрифты, стиль кнопок, декоративные элементы и общий визуальный вид подписи настраиваются в APStudio.</p><a class="af-asb__studio-link" href="<?= $e(af_asb_studio_url($uid)) ?>">Настроить оформление в APStudio <span aria-hidden="true">→</span></a><p class="af-asb__hint">Изменения оформления применяются ко всем элементам подписи.</p></section>
<?php else: ?><section class="af-asb__panel"><p>Используется стандартное оформление темы. Для визуальных пресетов включите AdvancedAppearance.</p></section><?php endif ?>
</aside></div>
<fieldset class="af-asb__post-options"><legend>Подпись в опубликованных сообщениях</legend>
<?php foreach(['0'=>'Оставить текущие настройки','enable'=>'Включить во всех моих сообщениях','disable'=>'Отключить во всех моих сообщениях'] as $value=>$label): ?><label><input type="radio" name="updateposts" value="<?= $value ?>" <?= (string)($mybb->input['updateposts']??'0')===(string)$value?'checked':'' ?>> <?= $label ?></label><?php endforeach ?>
</fieldset>
<div class="af-asb__actions"><button type="button" data-af-sig-draft>♧ Сохранить шаблон</button><button type="button" data-af-sig-preview-button>◉ Предпросмотр</button><button type="submit" class="af-asb__publish" disabled>▣ Обновить подпись</button></div>
<script type="application/json" data-af-sig-seed><?= json_encode($seed,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<noscript>Для интерактивного конструктора нужен JavaScript. Существующая подпись не меняется.</noscript>
</form>
<?php return ob_get_clean();
