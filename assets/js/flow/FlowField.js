// @flow
import React from 'react';
import {action, observable, toJS} from 'mobx';
import {observer} from 'mobx-react';
import {Button, Icon, Input, Loader, SingleSelect, Toggler} from 'sulu-admin-bundle/components';
import TextArea from 'sulu-admin-bundle/components/TextArea';
import {translate} from 'sulu-admin-bundle/utils';
import stripTags from '../utils/stripTags';
import loadFormFields from '../utils/formFields';
import integrationStore from './integrationStore';
import {INTEGRATIONS_VIEW} from '../integrations/IntegrationConnections';
import {FIXED_VALUE, NOT_MAPPED, customTargets, missingRequired, removeTarget, setFixedValue, setSource, sourceOf, toTargetKey, withDynamicFields} from './mapping';
import styles from './flow.scss';
import type {FieldTypeProps} from 'sulu-admin-bundle/containers/Form/types';
import type {Field} from '../builder/types';
import type {IntegrationAction, IntegrationField, IntegrationSetting} from './integrationStore';
import type {Mapping} from './mapping';

type FlowValue = {
    action: string,
    integration: string,
    mapping: Mapping,
    settings: {[string]: mixed},
};

const SEPARATOR = '::';
// How long the modal waits after a setting changed before it asks for the fields again.
const FIELDS_DELAY = 500;

/**
 * What a flow does: which action of which integration, its settings, and which answer - or fixed
 * value - fills each field the action needs. Settings are rendered from the description the
 * integration gives, so an integration needs no JavaScript of its own.
 */
@observer
class FlowField extends React.Component<FieldTypeProps<?Object>> {
    @observable loading: boolean = true;
    @observable formFields: Array<Field> = [];
    @observable options: {[string]: Array<{label: string, value: string}>} = {};
    @observable optionErrors: {[string]: string} = {};
    @observable newTarget: string = '';
    @observable dynamicFields: Array<IntegrationField> = [];
    @observable dynamicFieldsLoading: boolean = false;
    @observable dynamicFieldsError: ?string = null;
    fieldsTimer: ?TimeoutID = null;
    fieldsRequest: number = 0;

    componentDidMount() {
        const {formInspector, router} = this.props;
        const formId = router && router.attributes ? router.attributes.id : undefined;

        Promise.all([integrationStore.load(), loadFormFields(formId, formInspector.locale ? formInspector.locale.get() : undefined)])
            .then(action(([, fields]) => {
                this.formFields = fields;
                this.loading = false;
                this.loadDynamicOptions(this.value.integration, this.value.action);
                this.loadDynamicFields(this.value.integration, this.value.action, this.value.settings);
            }));
    }

    componentWillUnmount() {
        if (this.fieldsTimer) {
            clearTimeout(this.fieldsTimer);
        }
    }

    get value(): FlowValue {
        const value = toJS(this.props.value) || {};

        return {
            integration: value.integration || '',
            action: value.action || '',
            settings: value.settings && !Array.isArray(value.settings) ? value.settings : {},
            mapping: value.mapping && !Array.isArray(value.mapping) ? value.mapping : {},
        };
    }

    get action(): ?IntegrationAction {
        const {action: actionKey, integration} = this.value;

        return integrationStore.action(integration, actionKey);
    }

    change(value: FlowValue) {
        const {onChange, onFinish} = this.props;

        onChange(value);
        onFinish();
    }

    /**
     * Takes the action as arguments rather than reading it from the value: right after the editor
     * picks an action, the value prop has not caught up yet.
     */
    @action loadDynamicOptions(integration: string, actionKey: string) {
        const flowAction = integrationStore.action(integration, actionKey);

        this.options = {};
        this.optionErrors = {};

        if (!flowAction) {
            return;
        }

        flowAction.settings.filter((setting) => setting.dynamicOptions).forEach((setting) => {
            integrationStore.options(integration, actionKey, setting.key)
                // Replaced, not extended: MobX 4 does not see keys added to an observable object.
                .then(action((options) => {
                    this.options = {...this.options, [setting.key]: options};
                }))
                .catch(action((error) => {
                    this.optionErrors = {...this.optionErrors, [setting.key]: error.message};
                }));
        });
    }

    /**
     * The fields of the remote account (merge fields, custom fields) for the settings picked so
     * far. Only the answer to the latest request counts: an editor may switch audiences quickly.
     */
    @action loadDynamicFields(integration: string, actionKey: string, settings: {[string]: mixed}) {
        const flowAction = integrationStore.action(integration, actionKey);
        const request = ++this.fieldsRequest;

        this.dynamicFieldsError = null;

        if (!flowAction || !flowAction.dynamicFields) {
            this.dynamicFields = [];
            this.dynamicFieldsLoading = false;

            return;
        }

        this.dynamicFieldsLoading = true;

        integrationStore.fields(integration, actionKey, settings)
            .then(action((fields) => {
                if (request === this.fieldsRequest) {
                    this.dynamicFields = fields;
                    this.dynamicFieldsLoading = false;
                }
            }))
            .catch(action((error) => {
                if (request === this.fieldsRequest) {
                    this.dynamicFields = [];
                    this.dynamicFieldsError = error.message;
                    this.dynamicFieldsLoading = false;
                }
            }));
    }

    scheduleDynamicFields(value: FlowValue) {
        if (this.fieldsTimer) {
            clearTimeout(this.fieldsTimer);
        }

        this.fieldsTimer = setTimeout(() => this.loadDynamicFields(value.integration, value.action, value.settings), FIELDS_DELAY);
    }

    handleActionChange = (choice: string) => {
        const [integration, actionKey] = choice.split(SEPARATOR);
        const flowAction = integrationStore.action(integration, actionKey);
        const settings = {};

        (flowAction ? flowAction.settings : []).forEach((setting) => {
            if (setting.default !== null && setting.default !== undefined) {
                settings[setting.key] = setting.default;
            }
        });

        this.change({integration, action: actionKey, settings, mapping: this.suggestMapping(flowAction)});
        this.loadDynamicOptions(integration, actionKey);
        this.loadDynamicFields(integration, actionKey, settings);
    };

    /**
     * Pre-fills the mapping by matching keys and e-mail fields, so most flows only need a glance.
     */
    suggestMapping(flowAction: ?IntegrationAction): Mapping {
        const mapping = {};

        (flowAction ? flowAction.fields : []).forEach((target) => {
            const match = this.formFields.find((field) => field.key === target.key)
                || (target.type === 'email' ? this.formFields.find((field) => field.type === 'email') : null);

            if (match) {
                mapping[target.key] = {source: 'field', field: match.key};
            }
        });

        return mapping;
    }

    handleSettingChange = (key: string, settingValue: mixed) => {
        const value = this.value;
        const next = {...value, settings: {...value.settings, [key]: settingValue}};

        this.change(next);
        this.scheduleDynamicFields(next);
    };

    handleSourceChange = (target: string, source: string) => {
        const value = this.value;

        this.change({...value, mapping: setSource(value.mapping, target, source)});
    };

    handleFixedValueChange = (target: string, fixedValue: ?string) => {
        const value = this.value;

        this.change({...value, mapping: setFixedValue(value.mapping, target, fixedValue || '')});
    };

    handleRemoveTarget = (target: string) => {
        const value = this.value;

        this.change({...value, mapping: removeTarget(value.mapping, target)});
    };

    @action handleNewTargetChange = (newTarget: ?string) => {
        this.newTarget = newTarget || '';
    };

    @action handleAddTarget = () => {
        const key = toTargetKey(this.newTarget);
        const value = this.value;

        if (key === '' || value.mapping[key]) {
            return;
        }

        this.change({...value, mapping: setSource(value.mapping, key, FIXED_VALUE)});
        this.newTarget = '';
    };

    renderSetting(setting: IntegrationSetting) {
        const current = this.value.settings[setting.key];
        const label = setting.label + (setting.required ? ' *' : '');

        let input;

        if (setting.type === 'checkbox') {
            input = (
                <Toggler checked={!!current} onChange={(checked) => this.handleSettingChange(setting.key, checked)}>
                    {label}
                </Toggler>
            );
        } else if (setting.type === 'select') {
            const options = setting.dynamicOptions ? this.options[setting.key] : setting.options;

            input = this.optionErrors[setting.key]
                ? <p className={styles.error}>{translate('eekes_form_builder.load_options_failed') + ': ' + this.optionErrors[setting.key]}</p>
                : (!options
                    ? <Loader size={20} />
                    : (
                        <SingleSelect onChange={(choice) => this.handleSettingChange(setting.key, choice)} value={current ? String(current) : undefined}>
                            {options.map((option) => (
                                <SingleSelect.Option key={option.value} value={option.value}>{option.label}</SingleSelect.Option>
                            ))}
                        </SingleSelect>
                    ));
        } else if (setting.type === 'textarea') {
            input = <TextArea onChange={(text) => this.handleSettingChange(setting.key, text)} value={current ? String(current) : ''} />;
        } else {
            input = <Input onChange={(text) => this.handleSettingChange(setting.key, text)} value={current ? String(current) : ''} />;
        }

        return (
            <div className={styles.setting} key={setting.key}>
                {setting.type !== 'checkbox' && <label>{label}</label>}
                {input}
                {setting.help && <p className={styles.help}>{setting.help}</p>}
            </div>
        );
    }

    renderMappingRow(target: string, label: string, required: boolean, help: ?string, removable: boolean) {
        const {mapping} = this.value;
        const source = sourceOf(mapping, target);
        const entry = mapping[target];

        return (
            <tr className={required && source === NOT_MAPPED ? styles.missing : undefined} key={target}>
                <th>
                    {label}{required && <span className={styles.required}> *</span>}
                    <span className={styles.targetKey}>{target}</span>
                    {help && <span className={styles.help}>{help}</span>}
                </th>
                <td>
                    <SingleSelect onChange={(choice) => this.handleSourceChange(target, choice)} value={source}>
                        <SingleSelect.Option value={NOT_MAPPED}>{translate('eekes_form_builder.not_mapped')}</SingleSelect.Option>
                        {this.formFields.map((field) => (
                            <SingleSelect.Option key={field.key} value={field.key}>
                                {(stripTags(field.label) || field.key) + ' (' + field.key + ')'}
                            </SingleSelect.Option>
                        ))}
                        <SingleSelect.Option value={FIXED_VALUE}>{translate('eekes_form_builder.fixed_value')}</SingleSelect.Option>
                    </SingleSelect>
                    {source === FIXED_VALUE &&
                        <Input
                            onChange={(text) => this.handleFixedValueChange(target, text)}
                            placeholder={translate('eekes_form_builder.fixed_value_info', {placeholder: '{{ email }}'})}
                            value={entry && entry.value ? entry.value : ''}
                        />
                    }
                </td>
                <td className={styles.removeCell}>
                    {removable &&
                        <button onClick={() => this.handleRemoveTarget(target)} title={translate('sulu_admin.delete')} type="button">
                            <Icon name="su-trash-alt" />
                        </button>
                    }
                </td>
            </tr>
        );
    }

    /**
     * The integration cannot run yet - not configured, not connected - so the flow would fail
     * for every submission until someone fixes it in Settings → Form builder integrations.
     */
    renderNotReady(key: ?string) {
        const integration = integrationStore.integration(key);

        if (!integration || !integration.status || integration.status.ready) {
            return null;
        }

        const {router} = this.props;

        return (
            <div className={styles.problems}>
                <strong>{integration.status.message || translate('eekes_form_builder.integration_action_required')}</strong>
                <p>
                    {integrationStore.mayManage && router
                        ? (
                            <Button onClick={() => router.navigate(INTEGRATIONS_VIEW)} skin="link">
                                {translate('eekes_form_builder.integration_open_settings')}
                            </Button>
                        )
                        : translate('eekes_form_builder.integration_no_permission')
                    }
                </p>
            </div>
        );
    }

    render() {
        if (this.loading) {
            return <Loader />;
        }

        const {data} = this.props;
        const value = this.value;
        const flowAction = this.action;
        const targets = flowAction ? withDynamicFields(flowAction.fields, this.dynamicFields) : [];
        const missing = missingRequired(value.mapping, targets);
        // toJS: the form data is observable, and Array.isArray rejects a MobX array.
        const problems = data && Array.isArray(toJS(data.problems)) ? toJS(data.problems) : [];

        return (
            <div className={styles.flow}>
                <section>
                    <h3>{translate('eekes_form_builder.choose_integration')}</h3>
                    <SingleSelect onChange={this.handleActionChange} value={value.integration ? value.integration + SEPARATOR + value.action : undefined}>
                        {integrationStore.integrations.reduce((options, integration) => options.concat(integration.actions.map((integrationAction) => (
                            <SingleSelect.Option key={integration.key + SEPARATOR + integrationAction.key} value={integration.key + SEPARATOR + integrationAction.key}>
                                {integration.title + ': ' + integrationAction.title}
                            </SingleSelect.Option>
                        ))), [])}
                    </SingleSelect>
                    {flowAction && flowAction.description && <p className={styles.help}>{flowAction.description}</p>}
                </section>

                {this.renderNotReady(value.integration)}

                {problems.length > 0 &&
                    <div className={styles.problems}>
                        <strong>{translate('eekes_form_builder.flow_problems')}</strong>
                        <ul>{problems.map((problem) => <li key={problem}>{problem}</li>)}</ul>
                    </div>
                }

                {flowAction && flowAction.settings.length > 0 &&
                    <section>
                        <h3>{translate('eekes_form_builder.settings_section')}</h3>
                        <div className={styles.settings}>{flowAction.settings.map((setting) => this.renderSetting(setting))}</div>
                    </section>
                }

                {flowAction &&
                    <section>
                        <h3>{translate('eekes_form_builder.mapping')}</h3>
                        <p className={styles.help}>{translate('eekes_form_builder.mapping_info')}</p>
                        <table className={styles.mapping}>
                            <tbody>
                                {targets.map((target) => this.renderMappingRow(target.key, target.label, target.required, target.help, false))}
                                {customTargets(value.mapping, targets.map((target) => target.key)).map((target) => this.renderMappingRow(target, target, false, null, true))}
                            </tbody>
                        </table>
                        {this.dynamicFieldsLoading && <Loader size={20} />}
                        {this.dynamicFieldsError &&
                            <p className={styles.error}>{translate('eekes_form_builder.load_fields_failed') + ': ' + this.dynamicFieldsError}</p>
                        }
                        {missing.length > 0 && <p className={styles.error}>{missing.join(', ')}: {translate('eekes_form_builder.not_mapped')}</p>}
                        {flowAction.allowCustomFields &&
                            <div className={styles.addTarget}>
                                <Input
                                    onChange={this.handleNewTargetChange}
                                    placeholder={translate('eekes_form_builder.custom_field_key')}
                                    value={this.newTarget}
                                />
                                <Button icon="su-plus" onClick={this.handleAddTarget} skin="secondary">
                                    {translate('eekes_form_builder.add_custom_field')}
                                </Button>
                            </div>
                        }
                    </section>
                }
            </div>
        );
    }
}

export default FlowField;
