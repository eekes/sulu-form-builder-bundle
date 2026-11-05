// @flow
import React from 'react';
import {action, observable, toJS} from 'mobx';
import {observer} from 'mobx-react';
import {Overlay, SingleSelect} from 'sulu-admin-bundle/components';
import Form, {memoryFormStoreFactory} from 'sulu-admin-bundle/containers/Form';
import {translate} from 'sulu-admin-bundle/utils';
import ConditionSetEditor from './ConditionSetEditor';
import {BREAKPOINTS, COLUMNS, fromModalData, toModalData} from './model';
import styles from './formBuilder.scss';
import type {IObservableValue} from 'mobx/lib/mobx';
import type {ConditionSet, Field, FieldType, Width} from './types';

type Props = {|
    candidates: Array<Field>,
    field: Field,
    fieldType: FieldType,
    locale: ?IObservableValue<string>,
    onClose: () => void,
    onConfirm: (field: Field) => void,
|};

const COLUMN_OPTIONS = Array.from({length: COLUMNS}, (value, index) => index + 1);

/**
 * The settings of one field, in a modal instead of a block in a long page.
 *
 * The top part is a plain Sulu form, rendered from the form XML of the field type - so a field
 * type of an application gets its modal from XML alone. Width and visibility are the same for
 * every type and depend on the rest of the builder, so they are rendered here.
 */
@observer
class FieldOverlay extends React.Component<Props> {
    formStore: Object;
    formRef: ?Object;

    @observable width: Width;
    @observable visibleIf: ?ConditionSet;
    @observable requiredIf: ?ConditionSet;

    constructor(props: Props) {
        super(props);

        const {field, fieldType, locale} = this.props;

        this.formStore = memoryFormStoreFactory.createFromFormKey(fieldType.formKey, toModalData(field), locale);
        this.width = {...field.width};
        this.visibleIf = field.visibleIf ? toJS(field.visibleIf) : null;
        this.requiredIf = field.requiredIf ? toJS(field.requiredIf) : null;
    }

    componentWillUnmount() {
        this.formStore.destroy();
    }

    setFormRef = (formRef: ?Object) => {
        this.formRef = formRef;
    };

    handleConfirm = () => {
        if (this.formRef) {
            this.formRef.submit();
        }
    };

    handleSubmit = () => {
        const {field, onConfirm} = this.props;

        onConfirm({
            ...fromModalData(field, toJS(this.formStore.data)),
            width: toJS(this.width),
            visibleIf: this.visibleIf ? toJS(this.visibleIf) : null,
            requiredIf: this.requiredIf ? toJS(this.requiredIf) : null,
        });
    };

    @action handleWidthChange = (breakpoint: string, width: number) => {
        this.width = {...this.width, [breakpoint]: width};
    };

    @action handleConditionChange = (condition: ?ConditionSet) => {
        this.visibleIf = condition;
    };

    @action handleRequiredChange = (condition: ?ConditionSet) => {
        this.requiredIf = condition;
    };

    render() {
        const {candidates, fieldType, onClose} = this.props;

        return (
            <Overlay
                confirmText={translate('sulu_admin.apply')}
                onClose={onClose}
                onConfirm={this.handleConfirm}
                open={true}
                size="small"
                title={translate('eekes_form_builder.edit_field') + ': ' + fieldType.title}
            >
                <div className={styles.overlayContent}>
                    <Form onSubmit={this.handleSubmit} ref={this.setFormRef} store={this.formStore} />

                    <section className={styles.overlaySection}>
                        <h3>{translate('eekes_form_builder.layout')}</h3>
                        <p className={styles.info}>{translate('eekes_form_builder.layout_info')}</p>
                        <div className={styles.widths}>
                            {BREAKPOINTS.map((breakpoint) => (
                                <label className={styles.widthSelect} key={breakpoint}>
                                    <span>{translate('eekes_form_builder.width_' + breakpoint)}</span>
                                    <SingleSelect
                                        onChange={(width) => this.handleWidthChange(breakpoint, width)}
                                        value={this.width[breakpoint]}
                                    >
                                        {COLUMN_OPTIONS.map((columns) => (
                                            <SingleSelect.Option key={columns} value={columns}>
                                                {columns + '/' + COLUMNS}
                                            </SingleSelect.Option>
                                        ))}
                                    </SingleSelect>
                                </label>
                            ))}
                        </div>
                    </section>

                    <section className={styles.overlaySection}>
                        <h3>{translate('eekes_form_builder.visibility')}</h3>
                        <ConditionSetEditor
                            candidates={candidates}
                            emptyText={translate('eekes_form_builder.always_visible')}
                            onChange={this.handleConditionChange}
                            value={this.visibleIf}
                        />
                    </section>

                    {fieldType.input &&
                        <section className={styles.overlaySection}>
                            <h3>{translate('eekes_form_builder.required_when')}</h3>
                            <ConditionSetEditor
                                candidates={candidates}
                                emptyText={translate('eekes_form_builder.required_when_empty')}
                                info={translate('eekes_form_builder.required_when_info')}
                                onChange={this.handleRequiredChange}
                                value={this.requiredIf}
                            />
                        </section>
                    }
                </div>
            </Overlay>
        );
    }
}

export default FieldOverlay;
