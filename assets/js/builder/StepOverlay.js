// @flow
import React from 'react';
import {action, observable, toJS} from 'mobx';
import {observer} from 'mobx-react';
import {Overlay} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import ConditionSetEditor from './ConditionSetEditor';
import styles from './formBuilder.scss';
import type {ConditionSet, Field} from './types';

type Props = {|
    candidates: Array<Field>,
    onClose: () => void,
    onConfirm: (condition: ?ConditionSet) => void,
    title: string,
    value: ?ConditionSet,
|};

/**
 * When a step is shown at all. Only answers of earlier steps qualify: those are known before the
 * visitor reaches it.
 */
@observer
class StepOverlay extends React.Component<Props> {
    @observable condition: ?ConditionSet;

    constructor(props: Props) {
        super(props);

        this.condition = this.props.value ? toJS(this.props.value) : null;
    }

    @action handleChange = (condition: ?ConditionSet) => {
        this.condition = condition;
    };

    handleConfirm = () => {
        this.props.onConfirm(this.condition ? toJS(this.condition) : null);
    };

    render() {
        const {candidates, onClose, title} = this.props;

        return (
            <Overlay
                confirmText={translate('sulu_admin.apply')}
                onClose={onClose}
                onConfirm={this.handleConfirm}
                open={true}
                size="small"
                title={title}
            >
                <div className={styles.overlayContent}>
                    <ConditionSetEditor
                        candidates={candidates}
                        emptyText={translate('eekes_form_builder.always_visible')}
                        info={translate('eekes_form_builder.step_visible_info')}
                        onChange={this.handleChange}
                        value={this.condition}
                    />
                </div>
            </Overlay>
        );
    }
}

export default StepOverlay;
