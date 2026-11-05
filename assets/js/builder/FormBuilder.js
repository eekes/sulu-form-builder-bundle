// @flow
import React from 'react';
import classNames from 'classnames';
import {action, computed, observable, toJS} from 'mobx';
import {observer} from 'mobx-react';
import {Button, Dialog, Icon, Input} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import FieldCard from './FieldCard';
import FieldOverlay from './FieldOverlay';
import fieldTypeStore from './fieldTypeStore';
import Palette from './Palette';
import StepOverlay from './StepOverlay';
import {
    BREAKPOINTS,
    addField,
    addStep,
    becomesOptionalForFlows,
    conditionCandidates,
    createField,
    duplicateField,
    moveField,
    moveStep,
    normalize,
    removeField,
    removeStep,
    resizedWidth,
    setWidth,
    updateField,
    updateStep,
    withUniqueKey,
} from './model';
import styles from './formBuilder.scss';
import type {FieldTypeProps} from 'sulu-admin-bundle/containers/Form/types';
import type {Builder, Field, FieldType} from './types';

type Dragging = {|fieldIndex: number, source: 'field'|} | {|fieldType: FieldType, source: 'palette'|};
type DropTarget = {|fieldIndex: ?number, stepIndex: number|};
type Editing = {|field: Field, fieldIndex: ?number, stepIndex: number|};
type Resizing = {|fieldIndex: number, gridWidth: number, startWidth: number, startX: number, width: number|};

/**
 * The form builder: the steps of a form as tabs, the fields of a step in a 12-column grid that
 * looks the way the form will look, and a modal per field.
 *
 * The value is the plain structure the API sends (see model.js), and every change goes through
 * onChange as a new value - so saving, the dirty state and the locale switch of Sulu's form view
 * all keep working as for any other field.
 */
@observer
class FormBuilder extends React.Component<FieldTypeProps<Object>> {
    @observable stepIndex: number = 0;
    @observable breakpoint: string = 'desktop';
    @observable editing: ?Editing = null;
    @observable editingStepCondition: boolean = false;
    @observable removingStep: ?number = null;
    @observable dragging: ?Dragging = null;
    @observable dropTarget: ?DropTarget = null;
    @observable resizing: ?Resizing = null;
    @observable removingField: ?number = null;
    // A field the editor made optional while a flow needs an answer to it, waiting for confirmation.
    @observable makingOptional: ?{|editing: Editing, field: Field|} = null;

    gridRef: ?HTMLElement = null;

    componentDidMount() {
        fieldTypeStore.load();
    }

    componentWillUnmount() {
        this.stopResizing();
    }

    @computed get builder(): Builder {
        return normalize(toJS(this.props.value));
    }

    @computed get currentStepIndex(): number {
        return Math.min(this.stepIndex, this.builder.steps.length - 1);
    }

    change(builder: Builder) {
        const {onChange, onFinish} = this.props;

        onChange(builder);
        onFinish();
    }

    // Steps

    @action handleStepSelect = (stepIndex: number) => {
        this.stepIndex = stepIndex;
    };

    @action handleAddStep = () => {
        this.change(addStep(this.builder));
        this.stepIndex = this.builder.steps.length - 1;
    };

    handleStepTitleChange = (title: ?string) => {
        this.change(updateStep(this.builder, this.currentStepIndex, {title: title || ''}));
    };

    @action handleMoveStep = (direction: -1 | 1) => {
        this.change(moveStep(this.builder, this.currentStepIndex, direction));
        this.stepIndex = Math.max(0, Math.min(this.builder.steps.length - 1, this.currentStepIndex + direction));
    };

    @action handleRemoveStepClick = () => {
        this.removingStep = this.currentStepIndex;
    };

    @action handleRemoveStepConfirm = () => {
        if (this.removingStep !== null && this.removingStep !== undefined) {
            this.change(removeStep(this.builder, this.removingStep));
        }

        this.removingStep = null;
        this.stepIndex = Math.max(0, this.currentStepIndex - 1);
    };

    @action handleRemoveStepCancel = () => {
        this.removingStep = null;
    };

    @action handleStepConditionOpen = () => {
        this.editingStepCondition = true;
    };

    @action handleStepConditionClose = () => {
        this.editingStepCondition = false;
    };

    @action handleStepConditionConfirm = (condition: ?Object) => {
        this.change(updateStep(this.builder, this.currentStepIndex, {visibleIf: condition}));
        this.editingStepCondition = false;
    };

    // Fields

    @action handleAdd = (fieldType: FieldType) => {
        this.editing = {field: createField(fieldType, this.builder), fieldIndex: null, stepIndex: this.currentStepIndex};
    };

    @action handleEdit = (fieldIndex: number) => {
        this.editing = {field: this.builder.steps[this.currentStepIndex].fields[fieldIndex], fieldIndex, stepIndex: this.currentStepIndex};
    };

    @action handleEditClose = () => {
        this.editing = null;
    };

    @action handleEditConfirm = (field: Field) => {
        const {editing} = this;

        if (!editing) {
            return;
        }

        const isNew = editing.fieldIndex === null || editing.fieldIndex === undefined;

        if (!isNew && becomesOptionalForFlows(editing.field, field, this.requiredBy(editing.field))) {
            this.makingOptional = {editing, field};
            this.editing = null;

            return;
        }

        this.applyEdit(editing, field);
    };

    @action handleMakeOptionalConfirm = () => {
        if (this.makingOptional) {
            this.applyEdit(this.makingOptional.editing, this.makingOptional.field);
        }

        this.makingOptional = null;
    };

    @action handleMakeOptionalCancel = () => {
        // Back to the modal, with what the editor changed there.
        if (this.makingOptional) {
            this.editing = {...this.makingOptional.editing, field: this.makingOptional.field};
        }

        this.makingOptional = null;
    };

    @action applyEdit(editing: Editing, field: Field) {
        const unique = withUniqueKey(this.builder, field, editing.fieldIndex === null ? '' : editing.field.key);

        this.change(
            editing.fieldIndex === null || editing.fieldIndex === undefined
                ? addField(this.builder, editing.stepIndex, unique, this.pendingDropIndex)
                : updateField(this.builder, editing.stepIndex, editing.fieldIndex, unique)
        );

        this.pendingDropIndex = null;
        this.editing = null;
    }

    pendingDropIndex: ?number = null;

    handleDuplicate = (fieldIndex: number) => {
        this.change(duplicateField(this.builder, this.currentStepIndex, fieldIndex));
    };

    /**
     * The flows that read each field, as the form API reports them (see FormAdminMapper).
     */
    get integrationUsage(): {[string]: Array<string>} {
        const usage = this.props.formInspector.getValueByPath('/integrationUsage');

        return usage && typeof usage === 'object' ? toJS(usage) : {};
    }

    usedBy(field: Field): Array<string> {
        return this.integrationUsage[field.key] || [];
    }

    /**
     * The flows that fail without an answer to a field: they map it to a target their integration
     * requires (see FormAdminMapper).
     */
    requiredBy(field: Field): Array<string> {
        const required = this.props.formInspector.getValueByPath('/requiredByIntegrations');
        const flows = required && typeof required === 'object' ? toJS(required)[field.key] : null;

        return Array.isArray(flows) ? flows : [];
    }

    @action handleRemove = (fieldIndex: number) => {
        const field = this.builder.steps[this.currentStepIndex].fields[fieldIndex];

        // A field an integration reads is only removed after the editor saw which flows lose it.
        if (field && this.usedBy(field).length > 0) {
            this.removingField = fieldIndex;

            return;
        }

        this.change(removeField(this.builder, this.currentStepIndex, fieldIndex));
    };

    @action handleRemoveFieldConfirm = () => {
        if (this.removingField !== null && this.removingField !== undefined) {
            this.change(removeField(this.builder, this.currentStepIndex, this.removingField));
        }

        this.removingField = null;
    };

    @action handleRemoveFieldCancel = () => {
        this.removingField = null;
    };

    // Drag and drop

    @action handleFieldDragStart = (fieldIndex: number) => {
        this.dragging = {fieldIndex, source: 'field'};
    };

    @action handlePaletteDragStart = (fieldType: FieldType) => {
        this.dragging = {fieldType, source: 'palette'};
    };

    @action handleDragEnd = () => {
        this.dragging = null;
        this.dropTarget = null;
    };

    @action handleCardDragOver = (fieldIndex: number, after: boolean) => {
        this.dropTarget = {fieldIndex: after ? fieldIndex + 1 : fieldIndex, stepIndex: this.currentStepIndex};
    };

    @action handleGridDragOver = (event: SyntheticDragEvent<HTMLElement>) => {
        event.preventDefault();

        if (!this.dropTarget || this.dropTarget.stepIndex !== this.currentStepIndex) {
            this.dropTarget = {fieldIndex: null, stepIndex: this.currentStepIndex};
        }
    };

    @action handleTabDragOver = (stepIndex: number, event: SyntheticDragEvent<HTMLElement>) => {
        event.preventDefault();
        this.dropTarget = {fieldIndex: null, stepIndex};
    };

    @action handleDrop = (event?: SyntheticDragEvent<HTMLElement>) => {
        if (event) {
            event.preventDefault();
        }

        const {dragging, dropTarget} = this;

        if (dragging && dropTarget) {
            if (dragging.source === 'field') {
                this.change(moveField(
                    this.builder,
                    {fieldIndex: dragging.fieldIndex, stepIndex: this.currentStepIndex},
                    {fieldIndex: dropTarget.fieldIndex, stepIndex: dropTarget.stepIndex}
                ));
            } else {
                // A new field opens its modal first; it lands where it was dropped once confirmed.
                this.pendingDropIndex = dropTarget.fieldIndex;
                this.stepIndex = dropTarget.stepIndex;
                this.editing = {field: createField(dragging.fieldType, this.builder), fieldIndex: null, stepIndex: dropTarget.stepIndex};
            }
        }

        this.dragging = null;
        this.dropTarget = null;
    };

    // Resizing

    setGridRef = (gridRef: ?HTMLElement) => {
        this.gridRef = gridRef;
    };

    @action handleResizeStart = (fieldIndex: number, event: SyntheticPointerEvent<HTMLElement>) => {
        const field = this.builder.steps[this.currentStepIndex].fields[fieldIndex];
        const startWidth = field.width[this.breakpoint];

        this.resizing = {
            fieldIndex,
            gridWidth: this.gridRef ? this.gridRef.clientWidth : 0,
            startWidth,
            startX: event.clientX,
            width: startWidth,
        };

        window.addEventListener('pointermove', this.handleResizeMove);
        window.addEventListener('pointerup', this.handleResizeEnd);
    };

    @action handleResizeMove = (event: PointerEvent) => {
        const {resizing} = this;

        if (resizing) {
            resizing.width = resizedWidth(resizing.startWidth, event.clientX - resizing.startX, resizing.gridWidth);
        }
    };

    @action handleResizeEnd = () => {
        const {resizing} = this;

        if (resizing && resizing.width !== resizing.startWidth) {
            this.change(setWidth(this.builder, this.currentStepIndex, resizing.fieldIndex, this.breakpoint, resizing.width));
        }

        this.resizing = null;
        this.stopResizing();
    };

    stopResizing() {
        window.removeEventListener('pointermove', this.handleResizeMove);
        window.removeEventListener('pointerup', this.handleResizeEnd);
    }

    @action handleBreakpointChange = (breakpoint: string) => {
        this.breakpoint = breakpoint;
    };

    renderStepTabs() {
        const {steps} = this.builder;

        return (
            <div className={styles.steps}>
                {steps.map((step, index) => (
                    <button
                        className={classNames(styles.stepTab, {
                            [styles.activeStep]: index === this.currentStepIndex,
                            [styles.dropOnStep]: this.dropTarget && this.dropTarget.stepIndex === index && index !== this.currentStepIndex,
                        })}
                        key={step.key + index}
                        onClick={() => this.handleStepSelect(index)}
                        onDragOver={(event) => this.handleTabDragOver(index, event)}
                        onDrop={this.handleDrop}
                        type="button"
                    >
                        <span className={styles.stepNumber}>{index + 1}</span>
                        <span>{step.title || translate('eekes_form_builder.step') + ' ' + (index + 1)}</span>
                        {step.visibleIf && <Icon className={styles.stepConditional} name="su-eye" />}
                        <span className={styles.stepCount}>{step.fields.length}</span>
                    </button>
                ))}
                <button className={styles.addStep} onClick={this.handleAddStep} type="button">
                    <Icon name="su-plus" /> {translate('eekes_form_builder.add_step')}
                </button>
            </div>
        );
    }

    renderStepHeader() {
        const {steps} = this.builder;
        const step = steps[this.currentStepIndex];
        const multiStep = steps.length > 1;

        return (
            <div className={styles.stepHeader}>
                <div className={styles.stepTitle}>
                    <Input
                        onChange={this.handleStepTitleChange}
                        placeholder={translate('eekes_form_builder.step_title')}
                        value={step.title}
                    />
                </div>
                <div className={styles.stepActions}>
                    {multiStep && this.currentStepIndex > 0 &&
                        <Button
                            icon="su-eye"
                            onClick={this.handleStepConditionOpen}
                            skin={step.visibleIf ? 'primary' : 'secondary'}
                        >
                            {translate('eekes_form_builder.visibility')}
                        </Button>
                    }
                    {multiStep &&
                        <Button
                            disabled={this.currentStepIndex === 0}
                            icon="su-angle-left"
                            onClick={() => this.handleMoveStep(-1)}
                            skin="icon"
                        />
                    }
                    {multiStep &&
                        <Button
                            disabled={this.currentStepIndex === steps.length - 1}
                            icon="su-angle-right"
                            onClick={() => this.handleMoveStep(1)}
                            skin="icon"
                        />
                    }
                    {multiStep &&
                        <Button icon="su-trash-alt" onClick={this.handleRemoveStepClick} skin="icon" />
                    }
                </div>
            </div>
        );
    }

    renderBreakpoints() {
        return (
            <div className={styles.breakpoints} role="group">
                <span>{translate('eekes_form_builder.preview_size')}</span>
                {BREAKPOINTS.map((breakpoint) => (
                    <button
                        className={classNames(styles.breakpoint, {[styles.activeBreakpoint]: breakpoint === this.breakpoint})}
                        key={breakpoint}
                        onClick={() => this.handleBreakpointChange(breakpoint)}
                        type="button"
                    >
                        <Icon name={breakpoint === 'desktop' ? 'su-desktop' : 'su-mobile'} />
                        {translate('eekes_form_builder.width_' + breakpoint)}
                    </button>
                ))}
            </div>
        );
    }

    renderGrid() {
        const step = this.builder.steps[this.currentStepIndex];
        const {dragging, dropTarget, resizing} = this;
        const dropIndex = dropTarget && dropTarget.stepIndex === this.currentStepIndex ? dropTarget.fieldIndex : undefined;

        return (
            <div
                className={classNames(styles.grid, styles[this.breakpoint], {[styles.dropping]: !!dragging})}
                onDragOver={this.handleGridDragOver}
                onDrop={this.handleDrop}
                ref={this.setGridRef}
            >
                {step.fields.map((field, index) => (
                    <FieldCard
                        dropBefore={!!dragging && dropIndex === index}
                        field={field}
                        fieldType={fieldTypeStore.get(field.type)}
                        index={index}
                        isDragging={!!dragging && dragging.source === 'field' && dragging.fieldIndex === index}
                        key={field.key}
                        onDragEnd={this.handleDragEnd}
                        onDragOver={this.handleCardDragOver}
                        onDragStart={this.handleFieldDragStart}
                        onDrop={this.handleDrop}
                        onDuplicate={this.handleDuplicate}
                        onEdit={this.handleEdit}
                        onRemove={this.handleRemove}
                        onResizeStart={this.handleResizeStart}
                        usedBy={this.usedBy(field)}
                        width={resizing && resizing.fieldIndex === index ? resizing.width : field.width[this.breakpoint]}
                    />
                ))}
                {(step.fields.length === 0 || (dragging && dropIndex === null)) &&
                    <div className={classNames(styles.emptyStep, {[styles.dropHere]: !!dragging})}>
                        {translate('eekes_form_builder.empty_step')}
                    </div>
                }
            </div>
        );
    }

    render() {
        const {formInspector} = this.props;
        const {editing} = this;
        const builder = this.builder;
        const step = builder.steps[this.currentStepIndex];
        const editingType = editing ? fieldTypeStore.get(editing.field.type) : null;

        return (
            <div className={styles.builder}>
                <div className={styles.main}>
                    {this.renderStepTabs()}
                    {this.renderStepHeader()}
                    {this.renderBreakpoints()}
                    {this.renderGrid()}
                </div>

                <Palette
                    disabled={!!this.props.disabled}
                    onAdd={this.handleAdd}
                    onDragEnd={this.handleDragEnd}
                    onDragStart={this.handlePaletteDragStart}
                />

                {editing && editingType &&
                    <FieldOverlay
                        candidates={conditionCandidates(builder, fieldTypeStore.inputTypes, editing.field.key)}
                        field={editing.field}
                        fieldType={editingType}
                        locale={formInspector.locale}
                        onClose={this.handleEditClose}
                        onConfirm={this.handleEditConfirm}
                    />
                }

                {this.editingStepCondition &&
                    <StepOverlay
                        candidates={conditionCandidates(builder, fieldTypeStore.inputTypes, null, this.currentStepIndex)}
                        onClose={this.handleStepConditionClose}
                        onConfirm={this.handleStepConditionConfirm}
                        title={translate('eekes_form_builder.visibility') + ': ' + (step.title || translate('eekes_form_builder.step') + ' ' + (this.currentStepIndex + 1))}
                        value={step.visibleIf}
                    />
                }

                <Dialog
                    cancelText={translate('sulu_admin.cancel')}
                    confirmText={translate('sulu_admin.delete')}
                    onCancel={this.handleRemoveFieldCancel}
                    onConfirm={this.handleRemoveFieldConfirm}
                    open={this.removingField !== null && this.removingField !== undefined}
                    title={translate('eekes_form_builder.remove_used_field')}
                >
                    {translate('eekes_form_builder.remove_used_field_info')}
                    {' '}
                    {this.removingField !== null && this.removingField !== undefined && step.fields[this.removingField]
                        ? this.usedBy(step.fields[this.removingField]).join(', ')
                        : ''}
                </Dialog>

                <Dialog
                    cancelText={translate('sulu_admin.cancel')}
                    confirmText={translate('eekes_form_builder.make_optional')}
                    onCancel={this.handleMakeOptionalCancel}
                    onConfirm={this.handleMakeOptionalConfirm}
                    open={!!this.makingOptional}
                    title={translate('eekes_form_builder.make_optional_title')}
                >
                    {translate('eekes_form_builder.make_optional_info')}
                    {' '}
                    {this.makingOptional ? this.requiredBy(this.makingOptional.editing.field).join(', ') : ''}
                </Dialog>

                <Dialog
                    cancelText={translate('sulu_admin.cancel')}
                    confirmText={translate('sulu_admin.delete')}
                    onCancel={this.handleRemoveStepCancel}
                    onConfirm={this.handleRemoveStepConfirm}
                    open={this.removingStep !== null && this.removingStep !== undefined}
                    title={translate('eekes_form_builder.remove_step')}
                >
                    {translate('eekes_form_builder.remove_step_confirm')}
                </Dialog>
            </div>
        );
    }
}

export default FormBuilder;
