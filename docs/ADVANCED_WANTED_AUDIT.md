# AdvancedWanted: pre-implementation audit

1. **Public pages.** AF public pages are ordinary MyBB entry points (for example
   `kb.php`) which load `global.php`; enabled addon bootstraps are loaded by the
   core `global_start` hook. Addon-owned copies live in `assets/` for packaging.
2. **ACP.** An addon advertises `admin.slug` and `admin.controller` in its
   manifest. The AF ACP router loads the controller and calls
   `AF_Admin_<slug>::dispatch()`. Settings still use native MyBB setting groups.
3. **Renderers.** ATF's renderer is closely coupled to thread field rows. Wanted
   therefore uses a small schema renderer, while reusing KB option services and
   their canonical keys/labels rather than copying ATF.
4. **Registries.** KB exposes `af_kb_get_arpg_mechanics_options()`,
   `af_kb_get_arpg_mechanics_option_label()`, `af_kb_get_public_type_options()`
   and `af_kb_get_origin_variants()`. These are the Wanted `kb_dynamic` backend.
5. **Workflow metadata.** CharacterWorkflow has one row per `tid` in
   `af_character_workflow`. A nullable, indexed `wanted_id` is the safe metadata
   extension: ordinary applications retain `NULL` and their behavior is unchanged.
6. **Transport.** Apply creates a short-lived, HMAC-signed HttpOnly intent; the
   post-insert hook verifies and consumes it and writes both workflow metadata and the Wanted
   application relation. No editable ATF field is trusted.
7. **Hooks.** `datahandler_post_insert_thread` is the thread-created boundary.
   Acceptance has the owner API `af_cwf_accept_character_application`; Wanted is
   notified there. Release/rejection has no single existing hook, so Wanted owns
   `af_wanted_application_released()` for callers. Its documented fallback is
   `reserved` only when the same applicant still owns the reservation, otherwise
   `open`.
8. **AJAX.** KB has AJAX-specific catalog code, but no generic reusable catalog
   abstraction. Wanted consequently uses a progressive-enhancement GET form; it
   never submits both AJAX and navigation.
9. **Tables.** Three new owner tables are sufficient: entries, field definitions and
   values. Workflow linkage belongs in the existing workflow row; reservation
   expiry is supported by the timestamp already on entries.

