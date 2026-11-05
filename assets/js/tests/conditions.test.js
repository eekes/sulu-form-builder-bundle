/**
 * The website script and ConditionEvaluator.php have to agree; these are the cases of
 * ConditionEvaluatorTest.php.
 */
const {fieldKey, isMet} = require('../../../public/js/form-builder.js');

const cases = [
    ['equals', 'sales', 'sales', true],
    ['equals', ' Sales ', 'SALES', true],
    ['equals', 'sales', 'support', false],
    ['equals', 'sales', ['support', 'sales'], true],
    ['equals', 'sales', null, false],
    ['equals', '1', true, true],
    ['equals', '1', false, false],
    ['equals', '18', 18, true],
    ['not_equals', 'sales', 'support', true],
    ['not_equals', 'sales', 'Sales', false],
    ['not_equals', 'sales', null, true],
    ['contains', 'gmail', 'jane@GMAIL.com', true],
    ['contains', 'gmail', 'jane@example.com', false],
    ['contains', 'port', ['sales', 'support'], true],
    ['not_contains', 'gmail', 'jane@example.com', true],
    ['filled', null, 'x', true],
    ['filled', null, '   ', false],
    ['filled', null, [], false],
    ['filled', null, 0, true],
    ['empty', null, '', true],
    ['empty', null, null, true],
    ['empty', null, 'x', false],
    ['filled', null, [{name: 'Jane', age: null}], true],
    ['filled', null, [{name: ' ', agree: false}], false],
    ['empty', null, [], true],
    ['contains', 'jane', [{name: 'Joe'}, {name: 'Jane', agree: true}], true],
    ['equals', '1', [{agree: true}], true],
];

test.each(cases)('%s %p against %p is %p', (operator, expected, actual, met) => {
    expect(isMet({field: 'field', operator, value: expected}, {field: actual})).toBe(met);
});

test('The field key is the last bracket of the input name', () => {
    expect(fieldKey('eekes_form_quote[project][budget]')).toBe('budget');
    expect(fieldKey('eekes_form_quote[project][topics][]')).toBe('topics');
    expect(fieldKey('plain')).toBeNull();
});
