// @flow
import {FIXED_VALUE, NOT_MAPPED, customTargets, missingRequired, removeTarget, setFixedValue, setSource, sourceOf, toTargetKey, withDynamicFields} from '../flow/mapping';

test('A target is filled from an answer, a fixed value, or not at all', () => {
    let mapping = setSource({}, 'email', 'email_field');
    expect(sourceOf(mapping, 'email')).toBe('email_field');

    mapping = setSource(mapping, 'source', FIXED_VALUE);
    mapping = setFixedValue(mapping, 'source', 'website');
    expect(sourceOf(mapping, 'source')).toBe(FIXED_VALUE);
    expect(mapping.source).toEqual({source: 'value', value: 'website'});

    mapping = setSource(mapping, 'email', NOT_MAPPED);
    expect(sourceOf(mapping, 'email')).toBe(NOT_MAPPED);
    expect(mapping).toEqual({source: {source: 'value', value: 'website'}});
});

test('Switching to a fixed value keeps a value that was already typed', () => {
    const mapping = setSource({tag: {source: 'value', value: 'lead'}}, 'tag', FIXED_VALUE);

    expect(mapping.tag).toEqual({source: 'value', value: 'lead'});
});

test('Required targets without a mapping are reported, the way the server checks them', () => {
    const fields = [{key: 'email', required: true}, {key: 'name', required: false}];

    expect(missingRequired({}, fields)).toEqual(['email']);
    expect(missingRequired({email: {source: 'field', field: 'e'}}, fields)).toEqual([]);
});

test('Targets of the editor\'s own are told apart from the declared ones', () => {
    const mapping = {email: {source: 'field', field: 'e'}, score: {source: 'value', value: '1'}};

    expect(customTargets(mapping, ['email', 'name'])).toEqual(['score']);
    expect(removeTarget(mapping, 'score')).toEqual({email: {source: 'field', field: 'e'}});
});

test('An own target gets a key the server accepts', () => {
    expect(toTargetKey('  Lead score! ')).toBe('Lead_score');
    expect(toTargetKey('merge_fields.COMPANY')).toBe('merge_fields.COMPANY');
});

test('Fields of the remote account follow the declared ones, without doubles', () => {
    const declared = [{key: 'email', required: true}, {key: 'first_name', required: false}];
    const dynamic = [{key: 'COMPANY', required: false}, {key: 'email', required: false}, {key: 'VAT', required: true}, {key: 'COMPANY', required: true}];
    const fields = withDynamicFields(declared, dynamic);

    expect(fields.map((field) => field.key)).toEqual(['email', 'first_name', 'COMPANY', 'VAT']);
    expect(fields[0].required).toBe(true);
    expect(missingRequired({email: {source: 'field', field: 'email'}}, fields)).toEqual(['VAT']);
    expect(customTargets({COMPANY: {source: 'value', value: 'Acme'}, extra: {source: 'value', value: 'x'}}, fields.map((field) => field.key))).toEqual(['extra']);
});
