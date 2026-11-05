/**
 * @jest-environment jsdom
 */

/**
 * The rows of a repeater: added from the prototype the server rendered, removed down to the
 * minimum, and seen by the conditions as answers of the repeater.
 */
const {init, values} = require('../../../public/js/form-builder.js');

const row = (index) => `
    <fieldset class="eekes-form-repeater-row" data-eekes-repeater-row>
        <legend>Participant <span data-eekes-repeater-number></span></legend>
        <button type="button" data-eekes-repeater-remove hidden>Remove</button>
        <input type="text" name="eekes_form_event[step1][people][${index}][name]">
        <input type="checkbox" value="1" name="eekes_form_event[step1][people][${index}][vegan]">
    </fieldset>`;

function render({min = 1, max = null, rows = 1, indexes = null} = {}) {
    const prototype = row('__row__').replace(/"/g, '&quot;');

    document.body.innerHTML = `
        <form class="eekes-form">
            <input type="text" name="eekes_form_event[step1][name]" value="Organiser">
            <div data-eekes-repeater="people" data-eekes-repeater-min="${min}"${max ? ` data-eekes-repeater-max="${max}"` : ''} data-prototype="${prototype}">
                <div class="eekes-form-repeater-rows">${(indexes || Array.from({length: rows}, (_, index) => index)).map(row).join('')}</div>
                <button type="button" data-eekes-repeater-add hidden>Add another</button>
            </div>
            <div data-eekes-field="note" data-eekes-condition='{"match":"all","rules":[{"field":"people","operator":"contains","value":"jane"}]}'>
                <input type="text" name="eekes_form_event[step1][note]">
            </div>
        </form>`;

    const form = document.querySelector('form');
    init(form);

    return form;
}

const rows = (form) => form.querySelectorAll('[data-eekes-repeater-row]');
const add = (form) => form.querySelector('[data-eekes-repeater-add]');

test('The add button appears with the script, and adds a row with a new index', () => {
    const form = render();

    expect(add(form).hidden).toBe(false);

    add(form).click();
    add(form).click();

    expect(rows(form)).toHaveLength(3);
    expect(Array.from(form.querySelectorAll('input[name$="[name]"]')).map((input) => input.name)).toEqual([
        'eekes_form_event[step1][name]',
        'eekes_form_event[step1][people][0][name]',
        'eekes_form_event[step1][people][1][name]',
        'eekes_form_event[step1][people][2][name]',
    ]);
    expect(Array.from(form.querySelectorAll('[data-eekes-repeater-number]')).map((number) => number.textContent)).toEqual(['1', '2', '3']);
});

test('Rows can be removed down to the minimum, and an index is never reused', () => {
    const form = render({min: 1, rows: 2});

    const removes = () => Array.from(form.querySelectorAll('[data-eekes-repeater-remove]'));
    expect(removes().map((button) => button.hidden)).toEqual([false, false]);

    removes()[0].click();

    expect(rows(form)).toHaveLength(1);
    expect(removes()[0].hidden).toBe(true);
    expect(form.querySelector('[data-eekes-repeater-number]').textContent).toBe('1');

    add(form).click();

    expect(form.querySelectorAll('input[name="eekes_form_event[step1][people][2][name]"]')).toHaveLength(1);
});

test('After a failed submit the rows keep their indexes, and a new row takes the next free one', () => {
    const form = render({indexes: [0, 2]});

    add(form).click();

    expect(form.querySelectorAll('input[name="eekes_form_event[step1][people][3][name]"]')).toHaveLength(1);
});

test('The add button hides at the maximum', () => {
    const form = render({max: 2});

    add(form).click();

    expect(rows(form)).toHaveLength(2);
    expect(add(form).hidden).toBe(true);
});

test('The answers in the rows belong to the repeater, not to fields of the same name', () => {
    const form = render({rows: 2});
    const [first, second] = form.querySelectorAll('input[name$="][name]"][name*="people"]');

    first.value = 'Joe';
    second.value = 'Jane';
    form.querySelector('input[name$="[1][vegan]"]').checked = true;

    expect(values(form)).toEqual({name: 'Organiser', people: ['Joe', 'Jane', true], note: ''});
});

test('A condition on the repeater looks at every row', () => {
    const form = render({rows: 2});
    const note = form.querySelector('[data-eekes-field="note"]');

    expect(note.hidden).toBe(true);

    form.querySelector('input[name$="[1][name]"]').value = 'Jane';
    form.querySelector('input[name$="[1][name]"]').dispatchEvent(new Event('input', {bubbles: true}));

    expect(note.hidden).toBe(false);
});
