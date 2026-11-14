/* Focused DOM/transport fixture; no browser, network or installed application. */
'use strict';
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('inc/plugins/advancedfunctionality/addons/advancedcombatsystem/assets/ambush.js', 'utf8');
const dto = {viewer_uid: '8', session_id: '1', encounter_id: '2', expected_version: '9', encounter_version: '4', phase: 'ambush_ready', prepared_count: 1, unsupported_count: 0, blocker_count: 0, groups: [{group_id: '3', budget: 3, commitment: 2, remaining_first_round: 1}], participants_ready: true, can_ready: false, can_start: true, start_operation_id: null, operation_status: null, mechanics_confirmed: false};
function mount(storage, fetcher, state = dto) {
    const elements = new Map();
    const element = () => ({textContent: '', value: 'GM reason', hidden: false, disabled: false, append() {}, replaceChildren() {}, insertRow() { return {append() {}, insertCell() { return {}; }}; }});
    const root = {dataset: {}, querySelector(selector) { if (!elements.has(selector)) { const node = element(); node.dataset = {op: selector.match(/data-op="([^"]+)/)?.[1]}; elements.set(selector, node); } return elements.get(selector); }, addEventListener(type, callback) { this.click = callback; }};
    root.querySelector('[data-acs-ambush-config]').textContent = JSON.stringify({context: state, post_key: 'token', endpoint: 'xmlhttp.php?action=af_addon_api&addon=advancedcombatsystem'});
    vm.runInNewContext(source, {document: {querySelectorAll: () => [root], createElement: element}, window: {location: {href: 'https://fixture.test/showthread.php?tid=51'}}, sessionStorage: storage, URL, URLSearchParams, Uint8Array, crypto: {getRandomValues: bytes => bytes.fill(1)}, fetch: fetcher});
    return {button: op => root.querySelector(`[data-op="${op}"]`), click: op => root.click({target: {closest: () => root.querySelector(`[data-op="${op}"]`)}})};
}
(async () => {
    const map = new Map(), storage = {getItem: k => map.get(k), setItem: (k, v) => map.set(k, v), removeItem: k => map.delete(k)};
    let reject, requests = [];
    const first = mount(storage, (url, options) => { requests.push(options); return new Promise((_, r) => { reject = r; }); });
    const waiting = first.click('start'); await first.click('start'); assert.equal(requests.length, 1, 'Double click dispatched twice');
    const originalBody = requests[0].body.toString(); reject(new Error('Lost ACK')); await waiting;
    assert.equal(map.size, 1); assert.equal(first.button('start').disabled, true);
    const saved = JSON.parse([...map.values()][0]);
    const armed = {...dto, can_start: false, phase: 'ambush_resolution', operation_status: 'prepared', start_operation_id: `ambush:1:${saved.input.operation_key}`};
    const terminal = {...armed, phase: 'active', mechanics_confirmed: true, operation_status: 'committed', round_id: '7', executed_count: 1};
    const reloaded = mount(storage, async (url, options) => {
        requests.push(options);
        return {json: async () => ({ok: true, data: options.method === 'POST' ? terminal : armed})};
    });
    assert.equal(reloaded.button('start').disabled, true); assert.equal(reloaded.button('resume').hidden, true);
    await reloaded.click('status'); assert.equal(reloaded.button('resume').hidden, false); assert.equal(requests[1].method, undefined, 'Status wrote');
    await reloaded.click('resume'); assert.equal(requests[2].body.toString(), originalBody, 'Resume changed immutable key/version/reason'); assert.equal(map.size, 0);
    await reloaded.click('start'); assert.equal(requests.length, 3, 'Terminal start dispatched again');
    storage.setItem('acs-ambush:8:1:2', JSON.stringify(saved));
    const recovery = mount(storage, async () => ({json: async () => ({ok: true, data: {...armed, phase: 'recovery_required', operation_status: 'recovery_required'}})}));
    await recovery.click('status'); assert.equal(recovery.button('resume').hidden, true); assert.equal(recovery.button('start').disabled, true); assert.equal(map.size, 1); map.clear();
    let sent = 0;
    const noStorage = mount({...storage, setItem() { throw new Error('Quota'); }}, async () => { ++sent; });
    await noStorage.click('start'); assert.equal(sent, 0, 'Mutation sent without durable tab key');
    map.clear();
    const stale = mount(storage, async (url, options) => ({json: async () => options.method === 'POST' ? ({ok: false, error: {code: 'ACS_VERSION_CONFLICT', message: 'Stale'}}) : ({ok: true, data: dto})}));
    await stale.click('start'); assert.equal(stale.button('start').disabled, true); await stale.click('status'); assert.equal(map.size, 0); assert.equal(stale.button('start').disabled, false, 'Confirmed rejection could not refresh');
    console.log('ACS ambush UI: double-click, durable tab key, lost ACK/reload/read-only lookup, identical resume, terminal no-retry, storage failure and stale refresh passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
