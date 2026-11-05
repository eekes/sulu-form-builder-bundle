// @flow
/*
 * The mapping of a flow as plain values: {targetKey: {source: 'field', field} | {source: 'value', value}}.
 */
export type Mapping = {[string]: {field?: string, source: 'field' | 'value', value?: string}};

export const NOT_MAPPED = '__none';
export const FIXED_VALUE = '__value';

/**
 * What the source select shows for a target: a field key, "fixed value" or "not used".
 */
export function sourceOf(mapping: Mapping, target: string): string {
    const entry = mapping[target];

    if (!entry) {
        return NOT_MAPPED;
    }

    return entry.source === 'field' && entry.field ? entry.field : FIXED_VALUE;
}

export function setSource(mapping: Mapping, target: string, source: string): Mapping {
    const next = {...mapping};

    if (source === NOT_MAPPED) {
        delete next[target];
    } else if (source === FIXED_VALUE) {
        next[target] = {source: 'value', value: mapping[target] && mapping[target].source === 'value' ? mapping[target].value || '' : ''};
    } else {
        next[target] = {source: 'field', field: source};
    }

    return next;
}

export function setFixedValue(mapping: Mapping, target: string, value: string): Mapping {
    return {...mapping, [target]: {source: 'value', value}};
}

export function removeTarget(mapping: Mapping, target: string): Mapping {
    const next = {...mapping};
    delete next[target];

    return next;
}

/**
 * Targets the editor added themselves, besides the fields the action declares.
 */
export function customTargets(mapping: Mapping, declared: Array<string>): Array<string> {
    return Object.keys(mapping).filter((target) => !declared.includes(target));
}

/**
 * A key for a field of the editor's own: what the server accepts.
 */
export function toTargetKey(text: string): string {
    return (text || '').trim().replace(/[^a-zA-Z0-9_.-]+/g, '_').replace(/^_+|_+$/g, '').substring(0, 64);
}

/**
 * Required fields without a mapping, the same check the server makes before saving.
 */
export function missingRequired(mapping: Mapping, fields: Array<{key: string, required: boolean}>): Array<string> {
    return fields.filter((field) => field.required && !mapping[field.key]).map((field) => field.key);
}

/**
 * The fields the action declares, followed by those of the remote account (merge fields, custom
 * fields) it does not declare already.
 */
export function withDynamicFields<T: {key: string}>(declared: Array<T>, dynamic: Array<T>): Array<T> {
    const keys = declared.map((field) => field.key);

    return declared.concat(dynamic.filter((field) => {
        if (keys.includes(field.key)) {
            return false;
        }

        keys.push(field.key);

        return true;
    }));
}
