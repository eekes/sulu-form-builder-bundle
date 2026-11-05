// @flow
import {action, observable} from 'mobx';
import {ResourceRequester} from 'sulu-admin-bundle/services';
import {userStore} from 'sulu-admin-bundle/stores';
import type {FieldType} from './types';

export const RESOURCE_KEY = 'eekes_form_field_types';

/**
 * The palette of the builder, loaded once per admin session: field types only change with a
 * deployment.
 */
class FieldTypeStore {
    @observable types: Array<FieldType> = [];
    @observable loaded: boolean = false;
    promise: ?Promise<Array<FieldType>> = null;

    load(): Promise<Array<FieldType>> {
        if (!this.promise) {
            this.promise = ResourceRequester.getList(RESOURCE_KEY, {userLocale: userStore.systemLocale})
                .then(action((response) => {
                    this.types = response._embedded[RESOURCE_KEY];
                    this.loaded = true;

                    return this.types;
                }));
        }

        return this.promise;
    }

    get(key: string): ?FieldType {
        return this.types.find((type) => type.key === key);
    }

    get inputTypes(): Array<string> {
        return this.types.filter((type) => type.input).map((type) => type.key);
    }

    get groups(): Array<{key: string, title: string, types: Array<FieldType>}> {
        const groups = [];

        this.types.forEach((type) => {
            let group = groups.find((candidate) => candidate.key === type.group);

            if (!group) {
                group = {key: type.group, title: type.groupTitle, types: []};
                groups.push(group);
            }

            group.types.push(type);
        });

        return groups;
    }
}

export default new FieldTypeStore();
