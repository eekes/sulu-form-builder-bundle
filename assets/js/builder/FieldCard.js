// @flow
import React from 'react';
import classNames from 'classnames';
import {observer} from 'mobx-react';
import {Icon} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import stripTags from '../utils/stripTags';
import {COLUMNS} from './model';
import styles from './formBuilder.scss';
import type {Field, FieldType} from './types';

type Props = {|
    dropBefore: boolean,
    field: Field,
    fieldType: ?FieldType,
    index: number,
    isDragging: boolean,
    onDragEnd: () => void,
    onDragOver: (index: number, after: boolean) => void,
    onDragStart: (index: number) => void,
    onDrop: () => void,
    onDuplicate: (index: number) => void,
    onEdit: (index: number) => void,
    onRemove: (index: number) => void,
    onResizeStart: (index: number, event: SyntheticPointerEvent<HTMLElement>) => void,
    usedBy: Array<string>,
    width: number,
|};

/**
 * One field in the grid, as wide as it will be on the website: drag it to move it, drag its
 * right edge to resize it, click it to edit it.
 */
@observer
class FieldCard extends React.Component<Props> {
    handleClick = () => {
        this.props.onEdit(this.props.index);
    };

    handleKeyDown = (event: SyntheticKeyboardEvent<HTMLElement>) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            this.props.onEdit(this.props.index);
        }
    };

    handleDuplicate = (event: SyntheticEvent<HTMLElement>) => {
        event.stopPropagation();
        this.props.onDuplicate(this.props.index);
    };

    handleRemove = (event: SyntheticEvent<HTMLElement>) => {
        event.stopPropagation();
        this.props.onRemove(this.props.index);
    };

    handleDragStart = (event: SyntheticDragEvent<HTMLElement>) => {
        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', 'field:' + this.props.field.key);
        }

        this.props.onDragStart(this.props.index);
    };

    handleDragOver = (event: SyntheticDragEvent<HTMLElement>) => {
        event.preventDefault();
        event.stopPropagation();

        const rect = event.currentTarget.getBoundingClientRect();
        this.props.onDragOver(this.props.index, event.clientX > rect.left + rect.width / 2);
    };

    handleDrop = (event: SyntheticDragEvent<HTMLElement>) => {
        event.preventDefault();
        event.stopPropagation();
        this.props.onDrop();
    };

    handleResizeStart = (event: SyntheticPointerEvent<HTMLElement>) => {
        event.preventDefault();
        event.stopPropagation();
        this.props.onResizeStart(this.props.index, event);
    };

    stopPropagation = (event: SyntheticEvent<HTMLElement>) => {
        event.stopPropagation();
    };

    render() {
        const {dropBefore, field, fieldType, isDragging, onDragEnd, usedBy, width} = this.props;
        const label = stripTags(field.label || '');
        const content = !fieldType || fieldType.input ? null : stripTags(String(field.options.content || ''));

        return (
            <div
                className={classNames(styles.card, {
                    [styles.dragging]: isDragging,
                    [styles.dropBefore]: dropBefore,
                    [styles.missingType]: !fieldType,
                    [styles.contentCard]: fieldType && !fieldType.input,
                })}
                draggable={true}
                onClick={this.handleClick}
                onDragEnd={onDragEnd}
                onDragOver={this.handleDragOver}
                onDragStart={this.handleDragStart}
                onDrop={this.handleDrop}
                onKeyDown={this.handleKeyDown}
                role="button"
                style={{gridColumn: 'span ' + width}}
                tabIndex={0}
                title={translate('eekes_form_builder.edit_field')}
            >
                <div className={styles.cardHeader}>
                    <span className={styles.cardType}>
                        <Icon name={fieldType ? fieldType.icon : 'su-exclamation-triangle'} />
                        {fieldType ? fieldType.title : field.type}
                    </span>
                    <span className={styles.cardWidth}>{width + '/' + COLUMNS}</span>
                </div>

                <div className={styles.cardLabel}>
                    {label || content || <em>{field.key}</em>}
                    {field.required && <span className={styles.required}>*</span>}
                </div>

                {!fieldType && <div className={styles.cardWarning}>{translate('eekes_form_builder.missing_type')}</div>}

                <div className={styles.cardFooter}>
                    <span className={styles.cardKey}>{field.key}</span>
                    {field.visibleIf &&
                        <span className={styles.badge} title={translate('eekes_form_builder.conditional')}>
                            <Icon name="su-eye" />
                        </span>
                    }
                    {!field.required && field.requiredIf &&
                        <span className={styles.badge} title={translate('eekes_form_builder.conditionally_required')}>
                            <Icon name="su-exclamation-circle" />
                        </span>
                    }
                    {usedBy.length > 0 &&
                        <span className={styles.badge} title={translate('eekes_form_builder.used_by') + ': ' + usedBy.join(', ')}>
                            <Icon name="su-share" />
                        </span>
                    }
                    {field.showInList &&
                        <span className={styles.badge} title={translate('eekes_form_builder.in_list')}>
                            <Icon name="su-th-list" />
                        </span>
                    }
                </div>

                <div className={styles.cardActions} onClick={this.stopPropagation} role="toolbar">
                    <button onClick={this.handleDuplicate} title={translate('eekes_form_builder.duplicate_field')} type="button">
                        <Icon name="su-copy" />
                    </button>
                    <button onClick={this.handleRemove} title={translate('eekes_form_builder.remove_field')} type="button">
                        <Icon name="su-trash-alt" />
                    </button>
                </div>

                <div
                    className={styles.resizeHandle}
                    draggable={false}
                    onClick={this.stopPropagation}
                    onDragStart={this.stopPropagation}
                    onPointerDown={this.handleResizeStart}
                    title={translate('eekes_form_builder.resize')}
                />
            </div>
        );
    }
}

export default FieldCard;
