// @flow
import React from 'react';
import {observer} from 'mobx-react';
import {Button, Icon, Input, SingleSelect} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import stripTags from '../utils/stripTags';
import {OPERATORS, addRule, emptyCondition, isCondition, removeRule, setMatch, updateRule} from './conditions';
import {needsValue} from './model';
import styles from './formBuilder.scss';
import type {Condition, ConditionSet, Field} from './types';

type Props = {|
    candidates: Array<Field>,
    emptyText: string,
    info?: string,
    onChange: (set: ?ConditionSet) => void,
    value: ?ConditionSet,
|};

/**
 * Rules combined with "all" (AND) or "any" (OR), with one level of groups, which covers
 * "(A and B) or C". No rules means the empty text: "always visible", "never required", ...
 */
@observer
class ConditionSetEditor extends React.Component<Props> {
    get firstField(): string {
        const {candidates} = this.props;

        return candidates.length > 0 ? candidates[0].key : '';
    }

    handleAddRule = () => {
        this.props.onChange(addRule(this.props.value, emptyCondition(this.firstField)));
    };

    handleAddGroup = () => {
        this.props.onChange(addRule(this.props.value, {match: 'any', rules: [emptyCondition(this.firstField)]}));
    };

    render() {
        const {candidates, emptyText, info, onChange, value} = this.props;

        return (
            <div className={styles.conditionEditor}>
                {info && <p className={styles.info}>{info}</p>}
                {value
                    ? <RuleList candidates={candidates} nested={false} onChange={onChange} set={value} />
                    : <p className={styles.always}>{emptyText}</p>
                }
                <div className={styles.conditionActions}>
                    <Button disabled={candidates.length === 0} icon="su-plus" onClick={this.handleAddRule} skin="link">
                        {translate('eekes_form_builder.add_rule')}
                    </Button>
                    <Button disabled={candidates.length === 0} icon="su-plus" onClick={this.handleAddGroup} skin="link">
                        {translate('eekes_form_builder.add_group')}
                    </Button>
                </div>
            </div>
        );
    }
}

type RuleListProps = {|
    candidates: Array<Field>,
    nested: boolean,
    onChange: (set: ?ConditionSet) => void,
    set: ConditionSet,
|};

const RuleList = observer(({candidates, nested, onChange, set}: RuleListProps) => (
    <div className={nested ? styles.conditionGroup : undefined}>
        <div className={styles.conditionMatch}>
            <span>{translate('eekes_form_builder.match_prefix')}</span>
            <SingleSelect onChange={(match) => onChange(setMatch(set, match))} value={set.match}>
                <SingleSelect.Option value="all">{translate('eekes_form_builder.match_all')}</SingleSelect.Option>
                <SingleSelect.Option value="any">{translate('eekes_form_builder.match_any')}</SingleSelect.Option>
            </SingleSelect>
            <span>{translate('eekes_form_builder.match_suffix')}</span>
            {nested &&
                <Button icon="su-trash-alt" onClick={() => onChange(null)} skin="icon" />
            }
        </div>
        {set.rules.map((rule, index) => isCondition(rule)
            ? (
                <RuleRow
                    candidates={candidates}
                    // $FlowFixMe: checked by isCondition
                    condition={rule}
                    key={index}
                    onChange={(condition) => onChange(updateRule(set, index, condition))}
                    onRemove={() => onChange(removeRule(set, index))}
                />
            )
            : (
                <RuleList
                    candidates={candidates}
                    key={index}
                    nested={true}
                    // $FlowFixMe: a nested set
                    onChange={(nestedSet) => onChange(nestedSet ? updateRule(set, index, nestedSet) : removeRule(set, index))}
                    // $FlowFixMe: a nested set
                    set={rule}
                />
            )
        )}
        {nested &&
            <Button
                icon="su-plus"
                onClick={() => onChange(addRule(set, emptyCondition(candidates.length > 0 ? candidates[0].key : '')))}
                skin="link"
            >
                {translate('eekes_form_builder.add_rule')}
            </Button>
        }
    </div>
));

type RuleRowProps = {|
    candidates: Array<Field>,
    condition: Condition,
    onChange: (condition: Condition) => void,
    onRemove: () => void,
|};

const RuleRow = observer(({candidates, condition, onChange, onRemove}: RuleRowProps) => {
    const field = candidates.find((candidate) => candidate.key === condition.field);
    const choices = field && Array.isArray(field.options.choices)
        ? field.options.choices
            .map((choice) => ({label: String(choice.label || choice.value || ''), value: String(choice.value || choice.label || '')}))
            .filter((choice) => choice.value !== '')
        : [];

    return (
        <div className={styles.conditionRow}>
            <SingleSelect onChange={(key) => onChange({...condition, field: key})} value={condition.field || undefined}>
                {candidates.map((candidate) => (
                    <SingleSelect.Option key={candidate.key} value={candidate.key}>
                        {stripTags(candidate.label) || candidate.key}
                    </SingleSelect.Option>
                ))}
            </SingleSelect>
            <SingleSelect onChange={(operator) => onChange({...condition, operator})} value={condition.operator}>
                {OPERATORS.map((operator) => (
                    <SingleSelect.Option key={operator} value={operator}>
                        {translate('eekes_form_builder.operator.' + operator)}
                    </SingleSelect.Option>
                ))}
            </SingleSelect>
            <div>
                {needsValue(condition.operator) && (choices.length > 0
                    ? (
                        <SingleSelect onChange={(value) => onChange({...condition, value})} value={condition.value || undefined}>
                            {choices.map((choice) => (
                                <SingleSelect.Option key={choice.value} value={choice.value}>{choice.label}</SingleSelect.Option>
                            ))}
                        </SingleSelect>
                    )
                    : <Input onChange={(value) => onChange({...condition, value: value || ''})} value={condition.value || ''} />
                )}
            </div>
            <button className={styles.removeRule} onClick={onRemove} title={translate('sulu_admin.delete')} type="button">
                <Icon name="su-trash-alt" />
            </button>
        </div>
    );
});

export default ConditionSetEditor;
