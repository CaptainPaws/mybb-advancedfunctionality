<?php if (!defined('IN_MYBB')) die('No direct access'); $esc=static fn($s)=>htmlspecialchars_uni((string)$s); ?>
<div class="atf-avatar-editor" data-atf-avatar-editor>
<?php foreach ($cards as $card): $id='atf-avatar-'.$card['key']; $mimeNames=array_map(static fn($m)=>strtoupper(str_replace(['image/','jpeg'],['','jpg'],$m)),$card['mimes']); ?>
<form id="<?= $id ?>" class="atf-avatar-editor__card" action="usercp.php" method="post" enctype="multipart/form-data" data-avatar-card="<?= $card['key'] ?>" data-save-error="<?= $card['error']!==''?'1':'0' ?>" data-gallery-purpose="<?= $card['purpose'] ?>" data-file-field="<?= $card['field'] ?>" data-file-limits="<?= $esc(json_encode(['mimes'=>$card['mimes'],'maxBytes'=>$card['max_bytes'],'mimeLimits'=>$card['mime_limits']])) ?>">
<input type="hidden" name="my_post_key" value="<?= $esc($mybb->post_code) ?>">
<input type="hidden" name="atf_avatar_editor" value="1">
<input type="hidden" name="action" value="<?= $card['action'] ?>">
<input type="hidden" name="ag_avatar_media_id" value="">
<input type="hidden" name="ag_avatar_confirm" value="">
<header class="atf-avatar-editor__header"><span class="atf-avatar-editor__emblem" aria-hidden="true"><?= $card['key']==='primary'?'♙':'♜' ?></span><div><h2><?= $esc($card['title']) ?></h2><p><?= $esc($card['subtitle']) ?></p></div><small class="atf-avatar-editor__limits"><?= $esc($card['limits']) ?><br><?= $esc(implode(', ',$mimeNames)) ?></small></header>
<?php if ($card['error']!==''): ?><div class="atf-avatar-editor__error" data-avatar-server-error role="alert"><?= $card['error'] ?></div><?php endif ?>
<div class="atf-avatar-editor__layout">
<aside class="atf-avatar-editor__current"><div class="atf-avatar-editor__portrait" data-avatar-current><?php if ($card['current']!==''): ?><img src="<?= $esc($card['current']) ?>" alt="Текущий аватар" decoding="async"><?php else: ?><span class="atf-avatar-editor__empty">Аватар не установлен</span><?php endif ?></div><span class="atf-avatar-editor__current-label">◇ Текущий аватар</span><button type="button" name="<?= $card['remove'] ?>" value="1" class="atf-avatar-editor__delete" data-avatar-remove formnovalidate <?= !$card['has_current']?'disabled':'' ?>>Удалить аватар</button></aside>
<div class="atf-avatar-editor__workspace">
<div class="atf-avatar-editor__tabs" role="tablist" aria-label="Способ выбора изображения">
<?php foreach (['upload'=>'Загрузить с компьютера','url'=>'Указать URL','gallery'=>'Выбрать из галереи'] as $mode=>$label): $available=$card[$mode]; ?>
<button type="button" id="<?= $id.'-'.$mode ?>-tab" role="tab" aria-controls="<?= $id.'-'.$mode ?>" aria-selected="<?= $mode==='upload'?'true':'false' ?>" tabindex="<?= $mode==='upload'?'0':'-1' ?>" data-avatar-tab="<?= $mode ?>" <?= !$available?'disabled':'' ?> title="<?= $esc($mode==='url'?$card['url_hint']:($mode==='gallery'&&!$available?'Advanced Gallery недоступна':'')) ?>"><?= $label ?></button>
<?php endforeach ?>
</div>
<div class="atf-avatar-editor__workarea">
<div class="atf-avatar-editor__source">
<section id="<?= $id ?>-upload" role="tabpanel" aria-labelledby="<?= $id ?>-upload-tab" data-avatar-pane="upload">
<label class="atf-avatar-editor__upload" data-avatar-drop for="<?= $id ?>-file"><span class="atf-avatar-editor__upload-icon" aria-hidden="true">▧</span><strong>Выберите файл или перетащите его сюда</strong><small><?= $esc(implode(', ',$mimeNames)) ?></small><span class="atf-avatar-editor__choose">Выбрать файл</span><input id="<?= $id ?>-file" class="atf-avatar-editor__file" type="file" name="<?= $card['field'] ?>" accept="<?= $esc(implode(',',$card['mimes'])) ?>" <?= !$card['upload']?'disabled':'' ?>></label>
<?php if (!$card['upload']): ?><p>Загрузка аватара недоступна для вашей группы.</p><?php endif ?>
<?php if ($card['key']==='primary' && ($mybb->settings['avatarresizing']??'')==='user'): ?><label class="atf-avatar-editor__resize"><input type="checkbox" name="auto_resize" value="1" checked> Уменьшать до разрешённого размера</label><?php endif ?>
</section>
<section id="<?= $id ?>-url" role="tabpanel" aria-labelledby="<?= $id ?>-url-tab" data-avatar-pane="url" hidden><label>URL изображения<input type="text" inputmode="url" name="avatarurl" placeholder="https://…" data-avatar-url <?= !$card['url']?'disabled':'' ?>></label><p><?= $esc($card['url_hint']) ?></p><button type="button" data-avatar-url-preview>Предпросмотр</button></section>
<section id="<?= $id ?>-gallery" role="tabpanel" aria-labelledby="<?= $id ?>-gallery-tab" data-avatar-pane="gallery" hidden><p>Выберите изображение в галерее или нажмите на миниатюру справа. Оно применится только после сохранения.</p></section>
<?php if (!$card['url']): ?><p class="atf-avatar-editor__hint"><?= $esc($card['url_hint']) ?></p><?php endif ?>
</div>
<div class="atf-avatar-editor__gallery">
<button type="button" class="atf-avatar-editor__gallery-open" data-avatar-open-gallery <?= !$card['gallery']?'disabled':'' ?>><span aria-hidden="true">▧</span><span><strong>Открыть галерею</strong><small><?= $card['gallery']?'Выберите изображение из вашей галереи':'Advanced Gallery недоступна' ?></small></span></button>
<div class="atf-avatar-editor__recent-heading"><span>Последние изображения</span><button type="button" data-avatar-open-gallery <?= !$card['gallery']?'disabled':'' ?>>Показать все</button></div>
<div class="atf-avatar-editor__recent" data-avatar-recent aria-label="Последние изображения галереи"><small><?= $card['gallery']?'Загрузка изображений…':'Выбор из галереи отключён' ?></small></div>
</div>
</div>
<div class="atf-avatar-editor__selection" data-avatar-selection hidden><img data-avatar-selected-image alt="Предпросмотр выбранного изображения"><div><span data-avatar-selected-label>Предпросмотр</span><button type="button" data-avatar-cancel>Отменить выбор</button></div></div>
<div class="atf-avatar-editor__actions"><p data-avatar-status role="status" aria-live="polite"></p><button type="submit" data-avatar-save class="atf-avatar-editor__save" <?= !$card['upload']&&!$card['url']?'disabled':'' ?>><?= $esc($card['save']) ?></button></div>
</div></div>
</form>
<?php endforeach ?>
</div>
