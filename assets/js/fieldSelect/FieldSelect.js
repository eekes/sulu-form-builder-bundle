// @flow
import React from 'react';
import {action, observable} from 'mobx';
import {observer} from 'mobx-react';
import {Loader, SingleSelect} from 'sulu-admin-bundle/components';
import stripTags from '../utils/stripTags';
import loadFormFields from '../utils/formFields';
import type {FieldTypeProps} from 'sulu-admin-bundle/containers/Form/types';

/**
 * Picks a field of the form being edited, for the conditions of a notification. The form comes
 * from the "id" router attribute, which is set in every tab of a form.
 */
@observer
class FieldSelect extends React.Component<FieldTypeProps<?string>> {
    @observable fields: Array<{key: string, label: string}> = [];
    @observable loading: boolean = true;

    componentDidMount() {
        const {formInspector, router} = this.props;

        loadFormFields(router && router.attributes ? router.attributes.id : undefined, formInspector.locale ? formInspector.locale.get() : undefined)
            .then((fields) => this.setFields(fields.map((field) => ({key: field.key, label: stripTags(field.label) || field.key}))));
    }

    @action setFields(fields: Array<{key: string, label: string}>) {
        this.fields = fields;
        this.loading = false;
    }

    handleChange = (value: string) => {
        const {onChange, onFinish} = this.props;

        onChange(value);
        onFinish();
    };

    render() {
        const {disabled, value} = this.props;

        if (this.loading) {
            return <Loader size={20} />;
        }

        return (
            <SingleSelect disabled={!!disabled} onChange={this.handleChange} value={value || undefined}>
                {this.fields.map((field) => (
                    <SingleSelect.Option key={field.key} value={field.key}>
                        {field.label + ' (' + field.key + ')'}
                    </SingleSelect.Option>
                ))}
            </SingleSelect>
        );
    }
}

export default FieldSelect;
