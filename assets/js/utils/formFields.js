// @flow
import {ResourceRequester} from 'sulu-admin-bundle/services';
import fieldTypeStore from '../builder/fieldTypeStore';
import {normalize} from '../builder/model';
import type {Field} from '../builder/types';

const FORMS_RESOURCE_KEY = 'eekes_forms';

/**
 * The fields that collect an answer, of the form whose tabs are open: the form id is the "id"
 * router attribute in every tab of a form. Used wherever a tab other than the builder refers to
 * fields - conditions of a notification, the mapping of an integration flow.
 */
export default function loadFormFields(formId: mixed, locale: ?string): Promise<Array<Field>> {
    if (!formId) {
        return Promise.resolve([]);
    }

    return Promise.all([fieldTypeStore.load(), ResourceRequester.get(FORMS_RESOURCE_KEY, {id: formId, locale})])
        .then(([, form]) => {
            const inputTypes = fieldTypeStore.inputTypes;

            return normalize(form.builder).steps
                .reduce((fields, step) => fields.concat(step.fields), [])
                .filter((field) => inputTypes.includes(field.type));
        });
}
