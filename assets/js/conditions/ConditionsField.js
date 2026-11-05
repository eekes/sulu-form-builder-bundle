// @flow
import React from 'react';
import {action, observable, toJS} from 'mobx';
import {observer} from 'mobx-react';
import {Loader} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import ConditionSetEditor from '../builder/ConditionSetEditor';
import {normalizeSet} from '../builder/conditions';
import loadFormFields from '../utils/formFields';
import type {FieldTypeProps} from 'sulu-admin-bundle/containers/Form/types';
import type {ConditionSet, Field} from '../builder/types';

/**
 * Rules on the answers of the form being edited, as a field of any Sulu form in one of its tabs:
 * when a notification is sent, when an integration flow runs.
 */
@observer
class ConditionsField extends React.Component<FieldTypeProps<?Object>> {
    @observable candidates: Array<Field> = [];
    @observable loading: boolean = true;

    componentDidMount() {
        const {formInspector, router} = this.props;

        loadFormFields(router && router.attributes ? router.attributes.id : undefined, formInspector.locale ? formInspector.locale.get() : undefined)
            .then(action((fields) => {
                this.candidates = fields;
                this.loading = false;
            }));
    }

    handleChange = (set: ?ConditionSet) => {
        const {onChange, onFinish} = this.props;

        onChange(set);
        onFinish();
    };

    render() {
        const {schemaOptions: {empty_text: {value: emptyText} = {}} = {}, value} = this.props;

        if (this.loading) {
            return <Loader size={20} />;
        }

        return (
            <ConditionSetEditor
                candidates={this.candidates}
                emptyText={typeof emptyText === 'string' ? translate(emptyText) : translate('eekes_form_builder.always')}
                onChange={this.handleChange}
                value={normalizeSet(toJS(value))}
            />
        );
    }
}

export default ConditionsField;
