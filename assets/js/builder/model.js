// @flow
/*
 * Everything the builder does to a form, as pure functions on the plain value the API sends: a
 * list of steps, each with its fields in the order of the grid. Every function returns a new
 * value and leaves the one it was given alone, which is what the Sulu form store expects from
 * onChange - and what makes these functions testable without React.
 */
import {normalizeSet, renameInSet} from './conditions';
import type {Builder, Field, FieldType, Step, Width} from './types';

export const COLUMNS = 12;
export const BREAKPOINTS = ['desktop', 'tablet', 'mobile'];

/**
 * The texts and switches every field has. Everything else the field type modal edits is an
 * option of the type.
 */
export const COMMON_PROPERTIES = ['key', 'label', 'placeholder', 'help', 'required', 'showInList'];

const KEY_PATTERN = /^[a-zA-Z][a-zA-Z0-9_]{0,63}$/;

export function normalize(value: ?Object): Builder {
    const steps = value && Array.isArray(value.steps) ? value.steps : [];

    if (steps.length === 0) {
        return {steps: [createStep([])]};
    }

    return {
        steps: steps.map((step) => ({
            key: step.key || 'step1',
            title: step.title || '',
            titles: step.titles || {},
            visibleIf: normalizeSet(step.visibleIf),
            fields: Array.isArray(step.fields) ? step.fields.map(normalizeField) : [],
        })),
    };
}

function normalizeField(field: Object): Field {
    return {
        ...field,
        width: {
            desktop: clampWidth(field.width && field.width.desktop, COLUMNS),
            tablet: clampWidth(field.width && field.width.tablet, clampWidth(field.width && field.width.desktop, COLUMNS)),
            mobile: clampWidth(field.width && field.width.mobile, COLUMNS),
        },
        required: !!field.required,
        showInList: !!field.showInList,
        visibleIf: normalizeSet(field.visibleIf),
        requiredIf: normalizeSet(field.requiredIf),
        options: field.options && !Array.isArray(field.options) ? field.options : {},
        translations: field.translations && !Array.isArray(field.translations) ? field.translations : {},
    };
}

export function clampWidth(width: mixed, fallback: number = COLUMNS): number {
    const number = typeof width === 'number' ? width : parseInt(width, 10);

    if (isNaN(number)) {
        return fallback;
    }

    return Math.max(1, Math.min(COLUMNS, Math.round(number)));
}

export function allKeys(builder: Builder): Array<string> {
    return builder.steps.reduce((keys, step) => keys.concat(step.fields.map((field) => field.key)), []);
}

/**
 * A key that is not taken yet: "email", then "email_2", "email_3", ...
 */
export function uniqueKey(base: string, taken: Array<string>): string {
    let key = toKey(base);
    let suffix = 1;
    const original = key;

    while (taken.includes(key)) {
        key = original.substring(0, 60) + '_' + ++suffix;
    }

    return key;
}

/**
 * What the server would make of a label: lowercase, ASCII, underscores, starting with a letter.
 */
export function toKey(text: string): string {
    const key = (text || '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .substring(0, 64);

    if (key === '') {
        return 'field';
    }

    return /^[a-z]/.test(key) ? key : ('f_' + key).substring(0, 64);
}

export function isValidKey(key: string): boolean {
    return KEY_PATTERN.test(key);
}

export function createStep(existingSteps: Array<Step>): Step {
    const keys = existingSteps.map((step) => step.key);
    let number = existingSteps.length + 1;

    while (keys.includes('step' + number)) {
        number++;
    }

    return {key: 'step' + number, title: '', titles: {}, visibleIf: null, fields: []};
}

export function createField(fieldType: FieldType, builder: Builder): Field {
    return {
        key: uniqueKey(fieldType.key, allKeys(builder)),
        type: fieldType.key,
        width: {desktop: COLUMNS, tablet: COLUMNS, mobile: COLUMNS},
        required: false,
        showInList: false,
        visibleIf: null,
        requiredIf: null,
        label: fieldType.input ? fieldType.title : '',
        placeholder: null,
        help: null,
        options: {},
        translations: {},
    };
}

function replaceStep(builder: Builder, stepIndex: number, step: Step): Builder {
    return {steps: builder.steps.map((current, index) => index === stepIndex ? step : current)};
}

function replaceFields(builder: Builder, stepIndex: number, fields: Array<Field>): Builder {
    return replaceStep(builder, stepIndex, {...builder.steps[stepIndex], fields});
}

export function addStep(builder: Builder): Builder {
    return {steps: [...builder.steps, createStep(builder.steps)]};
}

export function updateStep(builder: Builder, stepIndex: number, changes: $Shape<Step>): Builder {
    return replaceStep(builder, stepIndex, {...builder.steps[stepIndex], ...changes});
}

/**
 * A form always keeps one step, so there is always somewhere to put a field.
 */
export function removeStep(builder: Builder, stepIndex: number): Builder {
    if (builder.steps.length <= 1) {
        return builder;
    }

    return {steps: builder.steps.filter((step, index) => index !== stepIndex)};
}

export function moveStep(builder: Builder, stepIndex: number, direction: -1 | 1): Builder {
    const target = stepIndex + direction;

    if (target < 0 || target >= builder.steps.length) {
        return builder;
    }

    const steps = [...builder.steps];
    [steps[stepIndex], steps[target]] = [steps[target], steps[stepIndex]];

    return {steps};
}

/**
 * Adds a field to a step, at the end or before the field at the given index.
 */
export function addField(builder: Builder, stepIndex: number, field: Field, atIndex: ?number): Builder {
    const fields = [...builder.steps[stepIndex].fields];
    const index = atIndex === undefined || atIndex === null ? fields.length : Math.max(0, Math.min(fields.length, atIndex));

    fields.splice(index, 0, field);

    return replaceFields(builder, stepIndex, fields);
}

/**
 * Replaces a field, and follows a change of its key in every condition that refers to it.
 */
export function updateField(builder: Builder, stepIndex: number, fieldIndex: number, field: Field): Builder {
    const previous = builder.steps[stepIndex].fields[fieldIndex];
    const fields = [...builder.steps[stepIndex].fields];
    fields[fieldIndex] = field;

    const updated = replaceFields(builder, stepIndex, fields);

    return previous && previous.key !== field.key ? renameReferences(updated, previous.key, field.key) : updated;
}

export function removeField(builder: Builder, stepIndex: number, fieldIndex: number): Builder {
    return replaceFields(builder, stepIndex, builder.steps[stepIndex].fields.filter((field, index) => index !== fieldIndex));
}

export function duplicateField(builder: Builder, stepIndex: number, fieldIndex: number): Builder {
    const original = builder.steps[stepIndex].fields[fieldIndex];
    // A copy is a new field: without the id of its original, or the server would take it for it.
    const {id, ...rest} = original; // eslint-disable-line no-unused-vars
    const copy = {...rest, key: uniqueKey(original.key, allKeys(builder))};

    return addField(builder, stepIndex, copy, fieldIndex + 1);
}

/**
 * Moves a field to a position in the same or another step. The target index is the position
 * among the fields of the target step before the move, which is what a drop target knows.
 */
export function moveField(
    builder: Builder,
    from: {fieldIndex: number, stepIndex: number},
    to: {fieldIndex: ?number, stepIndex: number}
): Builder {
    const field = builder.steps[from.stepIndex].fields[from.fieldIndex];

    if (!field) {
        return builder;
    }

    let targetIndex = to.fieldIndex === null || to.fieldIndex === undefined
        ? builder.steps[to.stepIndex].fields.length
        : to.fieldIndex;

    if (from.stepIndex === to.stepIndex && targetIndex > from.fieldIndex) {
        targetIndex--;
    }

    if (from.stepIndex === to.stepIndex && targetIndex === from.fieldIndex) {
        return builder;
    }

    return addField(removeField(builder, from.stepIndex, from.fieldIndex), to.stepIndex, field, targetIndex);
}

export function setWidth(builder: Builder, stepIndex: number, fieldIndex: number, breakpoint: string, width: number): Builder {
    const field = builder.steps[stepIndex].fields[fieldIndex];

    return updateField(builder, stepIndex, fieldIndex, {
        ...field,
        width: {...field.width, [breakpoint]: clampWidth(width)},
    });
}

/**
 * How many columns a resize handle that moved deltaX pixels makes of a field.
 */
export function resizedWidth(startWidth: number, deltaX: number, gridWidth: number): number {
    if (gridWidth <= 0) {
        return startWidth;
    }

    return clampWidth(startWidth + deltaX / (gridWidth / COLUMNS));
}

/**
 * The fields of a step as rows, the way Bootstrap wraps them: a field that no longer fits next
 * to the previous ones starts a new row.
 */
export function rows(fields: Array<Field>, breakpoint: string): Array<Array<{field: Field, index: number}>> {
    const result = [];
    let row = [];
    let used = 0;

    fields.forEach((field, index) => {
        const width = field.width[breakpoint] || COLUMNS;

        if (row.length > 0 && used + width > COLUMNS) {
            result.push(row);
            row = [];
            used = 0;
        }

        row.push({field, index});
        used += width;
    });

    if (row.length > 0) {
        result.push(row);
    }

    return result;
}

/**
 * The flat data the modal of a field type edits: the common properties and the options of the
 * type side by side, the way its Sulu form XML names them.
 */
export function toModalData(field: Field): Object {
    const data = {...field.options};

    COMMON_PROPERTIES.forEach((name) => {
        data[name] = field[name];
    });

    return data;
}

export function fromModalData(field: Field, data: Object): Field {
    const options = {};

    Object.keys(data).forEach((name) => {
        if (!COMMON_PROPERTIES.includes(name)) {
            options[name] = data[name];
        }
    });

    const key = typeof data.key === 'string' && data.key.trim() !== '' ? data.key.trim() : field.key;

    return {
        ...field,
        key: isValidKey(key) ? key : toKey(key),
        label: data.label || '',
        placeholder: data.placeholder || null,
        help: data.help || null,
        required: !!data.required,
        showInList: !!data.showInList,
        options,
    };
}

/**
 * Keeps a key unique when the editor typed one that another field already uses.
 */
export function withUniqueKey(builder: Builder, field: Field, ownKey: string): Field {
    const taken = allKeys(builder).filter((key) => key !== ownKey);

    return taken.includes(field.key) ? {...field, key: uniqueKey(field.key, taken)} : field;
}

/**
 * Whether saving an edited field makes optional what a flow needs: it was required, it no longer
 * is, and flows map it to a target their integration requires.
 */
export function becomesOptionalForFlows(before: Field, after: Field, requiredBy: Array<string>): boolean {
    return before.required && !after.required && requiredBy.length > 0;
}

export function renameReferences(builder: Builder, oldKey: string, newKey: string): Builder {
    return {
        steps: builder.steps.map((step) => ({
            ...step,
            visibleIf: renameInSet(step.visibleIf, oldKey, newKey),
            fields: step.fields.map((field) => ({
                ...field,
                visibleIf: renameInSet(field.visibleIf, oldKey, newKey),
                requiredIf: renameInSet(field.requiredIf, oldKey, newKey),
            })),
        })),
    };
}

/**
 * The fields a condition can look at: every field that collects an answer, except the field
 * the condition is for. For a step, only the fields of earlier steps qualify.
 */
export function conditionCandidates(
    builder: Builder,
    inputTypes: Array<string>,
    exceptKey: ?string,
    beforeStepIndex: ?number
): Array<Field> {
    const steps = beforeStepIndex === null || beforeStepIndex === undefined
        ? builder.steps
        : builder.steps.slice(0, beforeStepIndex);

    return steps
        .reduce((fields, step) => fields.concat(step.fields), [])
        .filter((field) => field.key !== exceptKey && inputTypes.includes(field.type));
}

export function needsValue(operator: ?string): boolean {
    return operator !== 'filled' && operator !== 'empty';
}

export function widthLabel(width: Width, breakpoint: string): string {
    return (width[breakpoint] || COLUMNS) + '/' + COLUMNS;
}
