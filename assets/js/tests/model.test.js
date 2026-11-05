// @flow
import {
    addField,
    becomesOptionalForFlows,
    addStep,
    conditionCandidates,
    createField,
    duplicateField,
    fromModalData,
    moveField,
    moveStep,
    normalize,
    removeField,
    removeStep,
    resizedWidth,
    rows,
    setWidth,
    toKey,
    toModalData,
    uniqueKey,
    updateField,
    withUniqueKey,
} from '../builder/model';

const textType = {formKey: 'eekes_form_builder_field_text', group: 'input', groupTitle: 'Fields', icon: 'su-pen', input: true, key: 'text', localizedOptions: [], title: 'Text'};
const headingType = {...textType, input: false, key: 'heading', title: 'Heading'};

function field(key, desktop = 12, extra = {}) {
    return normalize({steps: [{fields: [{key, type: 'text', width: {desktop}, ...extra}]}]}).steps[0].fields[0];
}

function builder(...steps) {
    return normalize({steps: steps.map((fields, index) => ({key: 'step' + (index + 1), fields}))});
}

test('An empty value still has a step to put fields in', () => {
    expect(normalize(null).steps).toHaveLength(1);
    expect(normalize({steps: []}).steps[0].key).toBe('step1');
});

test('Tablet follows desktop and mobile spans the full width unless set otherwise', () => {
    expect(field('name', 6).width).toEqual({desktop: 6, tablet: 6, mobile: 12});
});

test('Keys are derived the way the server derives them', () => {
    expect(toKey('Your e-mail address')).toBe('your_e_mail_address');
    expect(toKey('Événement')).toBe('evenement');
    expect(toKey('2 children?')).toBe('f_2_children');
    expect(toKey('')).toBe('field');
    expect(uniqueKey('email', ['email', 'email_2'])).toBe('email_3');
});

test('A new field gets a free key and the full width', () => {
    const value = builder([field('text')]);
    const created = createField(textType, value);

    expect(created.key).toBe('text_2');
    expect(created.width).toEqual({desktop: 12, tablet: 12, mobile: 12});
    expect(created.label).toBe('Text');
    expect(createField(headingType, value).label).toBe('');
});

test('A field is added at the end or at a position', () => {
    let value = builder([field('a'), field('b')]);
    value = addField(value, 0, field('c'));
    value = addField(value, 0, field('d'), 1);

    expect(value.steps[0].fields.map((item) => item.key)).toEqual(['a', 'd', 'b', 'c']);
});

test('Moving a field within a step accounts for the gap it leaves', () => {
    const value = builder([field('a'), field('b'), field('c')]);

    expect(moveField(value, {stepIndex: 0, fieldIndex: 0}, {stepIndex: 0, fieldIndex: 2}).steps[0].fields.map((item) => item.key)).toEqual(['b', 'a', 'c']);
    expect(moveField(value, {stepIndex: 0, fieldIndex: 2}, {stepIndex: 0, fieldIndex: 0}).steps[0].fields.map((item) => item.key)).toEqual(['c', 'a', 'b']);
    expect(moveField(value, {stepIndex: 0, fieldIndex: 0}, {stepIndex: 0, fieldIndex: null}).steps[0].fields.map((item) => item.key)).toEqual(['b', 'c', 'a']);
    expect(moveField(value, {stepIndex: 0, fieldIndex: 1}, {stepIndex: 0, fieldIndex: 1})).toBe(value);
});

test('A field can move to another step', () => {
    const value = moveField(builder([field('a'), field('b')], [field('c')]), {stepIndex: 0, fieldIndex: 1}, {stepIndex: 1, fieldIndex: 0});

    expect(value.steps[0].fields.map((item) => item.key)).toEqual(['a']);
    expect(value.steps[1].fields.map((item) => item.key)).toEqual(['b', 'c']);
});

test('The original value is never changed', () => {
    const value = builder([field('a'), field('b')]);
    const json = JSON.stringify(value);

    removeField(value, 0, 0);
    duplicateField(value, 0, 0);
    setWidth(value, 0, 0, 'desktop', 4);
    moveField(value, {stepIndex: 0, fieldIndex: 0}, {stepIndex: 0, fieldIndex: null});

    expect(JSON.stringify(value)).toBe(json);
});

test('A duplicate lands right after the original with a key of its own', () => {
    const value = duplicateField(builder([field('a'), field('b')]), 0, 0);

    expect(value.steps[0].fields.map((item) => item.key)).toEqual(['a', 'a_2', 'b']);
});

test('Making a field optional is only questioned when a flow needs it', () => {
    const required = field('email', 12, {required: true});
    const optional = {...required, required: false};

    expect(becomesOptionalForFlows(required, optional, ['Lead to CRM'])).toBe(true);
    expect(becomesOptionalForFlows(required, optional, [])).toBe(false);
    expect(becomesOptionalForFlows(required, required, ['Lead to CRM'])).toBe(false);
    expect(becomesOptionalForFlows(optional, required, ['Lead to CRM'])).toBe(false);
});

test('A field keeps its id through edits, and a duplicate gets none', () => {
    let value = builder([field('a', 12, {id: 'ab12-cd34'})]);
    value = updateField(value, 0, 0, fromModalData(value.steps[0].fields[0], {key: 'renamed', label: 'A'}));
    value = moveField(value, {stepIndex: 0, fieldIndex: 0}, {stepIndex: 0, fieldIndex: null});
    value = duplicateField(value, 0, 0);

    expect(value.steps[0].fields[0].id).toBe('ab12-cd34');
    expect(value.steps[0].fields[0].key).toBe('renamed');
    expect(value.steps[0].fields[1]).not.toHaveProperty('id');
});

test('Widths are clamped to the grid', () => {
    let value = builder([field('a')]);
    value = setWidth(value, 0, 0, 'desktop', 20);
    value = setWidth(value, 0, 0, 'tablet', 0);

    expect(value.steps[0].fields[0].width).toEqual({desktop: 12, tablet: 1, mobile: 12});
});

test('Dragging the resize handle snaps to whole columns', () => {
    expect(resizedWidth(6, 100, 1200)).toBe(7);
    expect(resizedWidth(6, -260, 1200)).toBe(3);
    expect(resizedWidth(6, 2000, 1200)).toBe(12);
    expect(resizedWidth(6, 100, 0)).toBe(6);
});

test('Fields wrap into rows the way Bootstrap wraps columns', () => {
    const value = rows([field('a', 6), field('b', 6), field('c', 4), field('d', 12), field('e', 3)], 'desktop');

    expect(value.map((row) => row.map((item) => item.field.key))).toEqual([['a', 'b'], ['c'], ['d'], ['e']]);
    expect(rows([field('a', 6), field('b', 6)], 'mobile')).toHaveLength(2);
});

test('Steps are added, moved and removed, but the last one stays', () => {
    let value = addStep(builder([field('a')]));
    expect(value.steps.map((step) => step.key)).toEqual(['step1', 'step2']);

    value = moveStep(value, 1, -1);
    expect(value.steps.map((step) => step.key)).toEqual(['step2', 'step1']);
    expect(moveStep(value, 0, -1)).toBe(value);

    value = removeStep(removeStep(value, 0), 0);
    expect(value.steps.map((step) => step.key)).toEqual(['step1']);
});

test('The modal edits the common properties and the options side by side', () => {
    const original = field('department', 12, {label: 'Department', options: {display: 'radio', choices: [{label: 'Sales'}]}});
    const data = toModalData(original);

    expect(data).toEqual({display: 'radio', choices: [{label: 'Sales'}], key: 'department', label: 'Department', placeholder: undefined, help: undefined, required: false, showInList: false});

    const changed = fromModalData(original, {...data, label: 'Team', required: true, display: 'select', key: 'Team name'});
    expect(changed.label).toBe('Team');
    expect(changed.required).toBe(true);
    expect(changed.options).toEqual({display: 'select', choices: [{label: 'Sales'}]});
    expect(changed.key).toBe('team_name');
    expect(changed.width).toBe(original.width);
});

test('A key another field already uses gets a suffix', () => {
    const value = builder([field('name'), field('email')]);

    expect(withUniqueKey(value, {...field('email'), key: 'name'}, 'email').key).toBe('name_2');
    expect(withUniqueKey(value, field('email'), 'email').key).toBe('email');
});

test('A single stored condition becomes a set of one', () => {
    expect(field('budget', 12, {visibleIf: {field: 'type', operator: 'filled'}}).visibleIf).toEqual({match: 'all', rules: [{field: 'type', operator: 'filled'}]});
});

test('Renaming a field follows its conditions, nested groups and requirements included', () => {
    const value = builder([
        field('type'),
        field('budget', 12, {
            visibleIf: {match: 'any', rules: [{field: 'type', operator: 'equals', value: 'company'}, {match: 'all', rules: [{field: 'type', operator: 'filled'}]}]},
            requiredIf: {match: 'all', rules: [{field: 'type', operator: 'filled'}]},
        }),
    ]);

    const renamed = updateField(value, 0, 0, {...value.steps[0].fields[0], key: 'customer_type'});

    expect(renamed.steps[0].fields[1].visibleIf).toEqual({match: 'any', rules: [{field: 'customer_type', operator: 'equals', value: 'company'}, {match: 'all', rules: [{field: 'customer_type', operator: 'filled'}]}]});
    expect(renamed.steps[0].fields[1].requiredIf).toEqual({match: 'all', rules: [{field: 'customer_type', operator: 'filled'}]});
});

test('A condition can look at every answer but its own, and a step only at earlier ones', () => {
    const value = builder(
        [field('name'), {...field('intro'), type: 'heading'}],
        [field('budget')]
    );

    expect(conditionCandidates(value, ['text'], 'name').map((item) => item.key)).toEqual(['budget']);
    expect(conditionCandidates(value, ['text'], null, 1).map((item) => item.key)).toEqual(['name']);
});
