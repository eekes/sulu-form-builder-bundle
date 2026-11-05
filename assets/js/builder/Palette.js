// @flow
import React from 'react';
import {observer} from 'mobx-react';
import {Icon, Loader} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import fieldTypeStore from './fieldTypeStore';
import styles from './formBuilder.scss';
import type {FieldType} from './types';

type Props = {|
    disabled: boolean,
    onAdd: (fieldType: FieldType) => void,
    onDragEnd: () => void,
    onDragStart: (fieldType: FieldType) => void,
|};

/**
 * Every field type, grouped. Click one to add it at the end of the step, or drag it to the place
 * it belongs.
 */
@observer
class Palette extends React.Component<Props> {
    render() {
        const {disabled, onAdd, onDragEnd, onDragStart} = this.props;

        if (!fieldTypeStore.loaded) {
            return <aside className={styles.palette}><Loader size={20} /></aside>;
        }

        return (
            <aside className={styles.palette}>
                <h3 className={styles.paletteTitle}>{translate('eekes_form_builder.add_field')}</h3>
                {fieldTypeStore.groups.map((group) => (
                    <div className={styles.paletteGroup} key={group.key}>
                        <h4>{group.title}</h4>
                        {group.types.map((type) => (
                            <button
                                className={styles.paletteItem}
                                disabled={disabled}
                                draggable={!disabled}
                                key={type.key}
                                onClick={() => onAdd(type)}
                                onDragEnd={onDragEnd}
                                onDragStart={(event) => {
                                    if (event.dataTransfer) {
                                        event.dataTransfer.effectAllowed = 'copy';
                                        event.dataTransfer.setData('text/plain', 'type:' + type.key);
                                    }

                                    onDragStart(type);
                                }}
                                type="button"
                            >
                                <Icon name={type.icon} />
                                <span className={styles.paletteLabel}>{type.title}</span>
                                <Icon className={styles.paletteAdd} name="su-plus" />
                            </button>
                        ))}
                    </div>
                ))}
            </aside>
        );
    }
}

export default Palette;
