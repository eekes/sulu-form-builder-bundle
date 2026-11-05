// @flow
/*
 * Condition sets as plain values: {match: 'all' | 'any', rules: [condition | set]}. Pure
 * functions, so the editor stays a thin view and the rules are unit-tested.
 */
import type {Condition, ConditionSet} from './types';

export const OPERATORS = ['equals', 'not_equals', 'contains', 'not_contains', 'filled', 'empty'];

export function isCondition(rule: mixed): boolean %checks {
    return !!rule && typeof rule === 'object' && typeof rule.field === 'string';
}

/**
 * Reads what the API sends, including the earlier single-condition shape.
 */
export function normalizeSet(value: mixed): ?ConditionSet {
    if (!value || typeof value !== 'object') {
        return null;
    }

    if (isCondition(value)) {
        // $FlowFixMe: checked above
        return {match: 'all', rules: [value]};
    }

    // $FlowFixMe: an object
    const rules = Array.isArray(value.rules) ? value.rules : [];

    return {
        // $FlowFixMe: an object
        match: value.match === 'any' ? 'any' : 'all',
        rules: rules
            .map((rule) => isCondition(rule) ? rule : normalizeSet(rule))
            .filter(Boolean),
    };
}

export function emptyCondition(field: string): Condition {
    return {field, operator: 'equals', value: ''};
}

export function addRule(set: ?ConditionSet, rule: Condition | ConditionSet): ConditionSet {
    const base = set || {match: 'all', rules: []};

    return {...base, rules: [...base.rules, rule]};
}

export function updateRule(set: ConditionSet, index: number, rule: Condition | ConditionSet): ConditionSet {
    return {...set, rules: set.rules.map((current, position) => position === index ? rule : current)};
}

/**
 * Removing the last rule removes the set: no rules means "always".
 */
export function removeRule(set: ConditionSet, index: number): ?ConditionSet {
    const rules = set.rules.filter((rule, position) => position !== index);

    return rules.length === 0 ? null : {...set, rules};
}

export function setMatch(set: ConditionSet, match: 'all' | 'any'): ConditionSet {
    return {...set, match};
}

export function fieldsOf(set: ?ConditionSet): Array<string> {
    if (!set) {
        return [];
    }

    return set.rules.reduce((fields, rule) => fields.concat(isCondition(rule) ? [rule.field] : fieldsOf(rule)), []);
}

export function renameInSet(set: ?ConditionSet, oldKey: string, newKey: string): ?ConditionSet {
    if (!set) {
        return set;
    }

    return {
        ...set,
        rules: set.rules.map((rule) => {
            if (isCondition(rule)) {
                return rule.field === oldKey ? {...rule, field: newKey} : rule;
            }

            return renameInSet(rule, oldKey, newKey);
        }),
    };
}

/**
 * The same rules as the server and the website script, for a preview in the admin.
 */
export function isSetMet(set: ?ConditionSet, values: {[string]: mixed}): boolean {
    if (!set) {
        return true;
    }

    const test = (rule) => isCondition(rule) ? isConditionMet(rule, values) : isSetMet(rule, values);

    return set.match === 'any' ? set.rules.some(test) : set.rules.every(test);
}

function strings(value: mixed): Array<string> {
    const items = Array.isArray(value) ? value : [value];

    return items
        .map((item) => item === true ? '1' : item)
        .filter((item) => item !== null && item !== undefined && item !== false && typeof item !== 'object')
        .map((item) => String(item).trim().toLowerCase())
        .filter((item) => item !== '');
}

function isConditionMet(condition: Condition, values: {[string]: mixed}): boolean {
    const actual = strings(values[condition.field]);
    const expected = String(condition.value || '').trim().toLowerCase();
    const contains = actual.some((item) => expected === '' || item.includes(expected));

    switch (condition.operator) {
        case 'filled': return actual.length > 0;
        case 'empty': return actual.length === 0;
        case 'equals': return actual.includes(expected);
        case 'not_equals': return !actual.includes(expected);
        case 'contains': return contains;
        case 'not_contains': return !contains;
        default: return true;
    }
}
