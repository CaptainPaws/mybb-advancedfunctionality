# Описание канонического персонажа: KB → ATF

- `short_ru` / `short_en` — каталог и краткие превью.
- `body_ru` — основной текст KB и источник первоначального `character_app` в ATF.
- Если `body_ru` пуст, используется `body_en`; если оба пусты, сохраняется legacy-fallback профильного `character_app` (включая существующие aliases контрактного resolver).
- `character_app`, `character_post`, `character_userinfo` больше не показываются отдельными полями редактора KB. Их сохранённые значения остаются в `character_profile` и сериализуются без очистки.

Цепочка: `af_kb_handle_character_apply()` → `af_kb_build_character_application_prefill()` → token store → `newthread.php?af_atf_prefill_token=…` → `af_atf_prepare_input_block()` → named values в ATF field IDs.

ATF применяет prefill только к первоначальному GET новой анкеты. POST/preview используют отправленные значения, редактирование — значения анкеты из БД. Изменения KB не синхронизируются поверх пользовательского текста. Массовая миграция описаний не выполняется.
