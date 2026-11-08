/*
 * Teampass - a collaborative passwords manager.
 * Copyright 2009-2026 Teampass.net
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('app/pages/utilities.health.js.php', 'utf8');
const functions = source.slice(source.indexOf('function tpSchemaValue('), source.indexOf('function tpRenderDatabase('));

function fixture() {
    const elements = new Map();
    function element(selector) {
        if (!selector.startsWith('<') && elements.has(selector)) return elements.get(selector);
        const result = {children: [], value: '', props: {}, classes: '',
            empty() { this.children = []; this.value = ''; return this; },
            append(child) { this.children.push(child); return this; },
            appendTo(parent) { parent.append(this); return this; },
            addClass(value) { this.classes += value; return this; },
            text(value) { this.value = String(value); return this; },
            prop(name, value) { this.props[name] = value; return this; }};
        if (!selector.startsWith('<')) elements.set(selector, result);
        return result;
    }
    const context = { $: element, tpHealthKey: 'test-key', Date, JSON,
        tpSchemaL10n: {success: 'conform', warning: 'review', danger: 'incompatible', unavailable: 'unavailable',
            running: 'running', counts: '%errors% errors / %warnings% warnings',
            meta: '%version% %date% %expected% %inspected%', extra_column: 'additional column'},
        prepareExchangedData: value => value };
    vm.createContext(context);
    vm.runInContext(functions, context);
    return {context, elements};
}

test('schema differences use text nodes, including entity-like names and executable markup', () => {
    const {context, elements} = fixture();
    const name = 'R&D O\'Brien "<img src=x onerror=alert(1)>" &quot;';
    context.tpRenderSchemaReport({status: 'warning', prefix: 'tp_', counts: {warning: 1, danger: 0},
        issues: [{severity: 'warning', code: 'extra_column', table: name, object: name, expected: null, actual: name}]});
    const row = elements.get('#health-schema-issues').children[0];
    assert.equal(row.children[1].children[0].value, 'tp_' + name);
    assert.equal(row.children[2].value, name);
    assert.equal(row.children[5].value, JSON.stringify(name));
    assert.equal(elements.get('#health-schema-results').props.hidden, false);
});

test('unavailable and conforming results clear old differences and do not display false success', () => {
    const {context, elements} = fixture();
    context.tpRenderSchemaReport({status: 'success', issues: []});
    assert.equal(elements.get('#health-schema-summary').children[0].value, 'conform');
    context.tpRenderSchemaReport({status: 'unexpected', issues: []});
    assert.equal(elements.get('#health-schema-summary').children[0].value, 'unavailable');
    assert.equal(elements.get('#health-schema-results').props.hidden, true);
    assert.equal(elements.get('#health-schema-issues').children.length, 0);
});

test('failed transport restores the check button without retaining old results', () => {
    const {context, elements} = fixture();
    context.$.post = () => ({fail(callback) { callback(); return this; }, always(callback) { callback(); return this; }});
    context.tpCheckDatabaseSchema();
    assert.equal(elements.get('#health-schema-check').props.disabled, false);
    assert.equal(elements.get('#health-schema-summary').value, 'unavailable');
    assert.equal(elements.get('#health-schema-results').props.hidden, true);
});

test('column and index descriptions retain differences relevant to administrators', () => {
    const {context} = fixture();
    assert.equal(context.tpSchemaValue({type: 'varchar(500)', nullable: false, default: null, extra: '', collation: ''}), 'varchar(500) NOT NULL DEFAULT null');
    assert.equal(context.tpSchemaValue({unique: true, type: 'BTREE', columns: [{name: 'label', prefix: 20, direction: 'D'}]}), 'UNIQUE BTREE (label(20) DESC)');
    assert.notEqual(context.tpSchemaValue(null), context.tpSchemaValue('NULL'));
});
