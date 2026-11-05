// @flow
import {addRule, emptyCondition, fieldsOf, isSetMet, normalizeSet, removeRule, setMatch, updateRule} from '../builder/conditions';

const {isSetMet: websiteIsSetMet} = require('../../../public/js/form-builder.js');

const set = {
    match: 'any',
    rules: [
        {field: 'topic', operator: 'equals', value: 'sales'},
        {match: 'all', rules: [{field: 'topic', operator: 'equals', value: 'other'}, {field: 'email', operator: 'contains', value: '@acme'}]},
    ],
};

test.each([
    [{topic: 'sales'}, true],
    [{topic: 'other', email: 'jane@acme.com'}, true],
    [{topic: 'other', email: 'jane@example.com'}, false],
    [{topic: 'support'}, false],
])('The admin preview and the website script agree on %p', (values, met) => {
    expect(isSetMet(set, values)).toBe(met);
    expect(websiteIsSetMet(set, values)).toBe(met);
});

test('No set means always', () => {
    expect(isSetMet(null, {})).toBe(true);
    expect(websiteIsSetMet(null, {})).toBe(true);
});

test('The website script also reads a single condition', () => {
    expect(websiteIsSetMet({field: 'topic', operator: 'filled'}, {topic: 'x'})).toBe(true);
});

test('Rules are added, changed and removed without touching the original', () => {
    let value = addRule(null, emptyCondition('topic'));
    expect(value).toEqual({match: 'all', rules: [{field: 'topic', operator: 'equals', value: ''}]});

    value = updateRule(value, 0, {field: 'topic', operator: 'filled', value: ''});
    value = setMatch(addRule(value, {match: 'any', rules: [emptyCondition('email')]}), 'any');
    expect(fieldsOf(value)).toEqual(['topic', 'email']);

    expect(removeRule(removeRule(value, 1) || value, 0)).toBeNull();
});

test('A set without rules or with junk is normalized', () => {
    expect(normalizeSet(null)).toBeNull();
    expect(normalizeSet({match: 'weird', rules: [{field: 'a', operator: 'filled'}, 'junk']})).toEqual({match: 'all', rules: [{field: 'a', operator: 'filled'}]});
});
