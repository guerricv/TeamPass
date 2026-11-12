/* TeamPass, copyright 2009-2026 Teampass.net. GNU GPL-3.0-or-later. */
const assert = require('node:assert/strict')
const { readFileSync } = require('node:fs')
const { join } = require('node:path')
const vm = require('node:vm')
const { test } = require('node:test')

test('API users search uses displayed names while managing sharing permissions', () => {
  const source = readFileSync(join(__dirname, '../../app/pages/api.js.php'), 'utf8')
  const start = source.indexOf('    function filterApiUsersTable()')
  const end = source.indexOf("    $(document).on('input keyup search'", start)
  assert.ok(start > 0 && end > start)
  const rows = [{ text: `R&D O'Brien "hello" <team>` }, { text: 'Another user' }]
  let criteria = ''
  let noResults = false
  const context = vm.createContext({ $: selector => {
    if (selector === '#api-users-search') return { val: () => criteria }
    if (selector === '#table-api-keys tbody tr') return { length: rows.length, each: callback => rows.forEach(row => callback.call(row)) }
    if (selector === '#api-search-no-results') return { toggleClass: (name, hidden) => { noResults = !hidden } }
    return { find: () => ({ text: () => selector.text }), toggleClass: (name, hidden) => { selector.hidden = hidden } }
  } })
  vm.runInContext(source.slice(start, end), context)
  for (const term of ['R&D', "O'Brien", '"hello"', '<team>']) {
    criteria = term
    vm.runInContext('filterApiUsersTable()', context)
    assert.equal(rows[0].hidden, false, term)
    assert.equal(rows[1].hidden, true, term)
    assert.equal(noResults, false)
  }
  for (const term of ['amp', 'quot', '#039']) {
    criteria = term
    vm.runInContext('filterApiUsersTable()', context)
    assert.equal(rows[0].hidden, true, term)
    assert.equal(noResults, true)
  }
})
