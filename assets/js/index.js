// @flow
import {fieldRegistry, viewRegistry} from 'sulu-admin-bundle/containers';
import {listItemActionRegistry} from 'sulu-admin-bundle/views';
import FormBuilder from './builder/FormBuilder';
import FieldSelect from './fieldSelect/FieldSelect';
import ConditionsField from './conditions/ConditionsField';
import FlowField from './flow/FlowField';
import NotificationPreview from './notification/NotificationPreview';
import ShowSubmissionItemAction from './submission/ShowSubmissionItemAction';
import IntegrationConnections, {INTEGRATIONS_VIEW} from './integrations/IntegrationConnections';
import fieldTypeStore from './builder/fieldTypeStore';
import * as builderModel from './builder/model';

fieldRegistry.add('eekes_form_builder', FormBuilder);
fieldRegistry.add('eekes_form_builder_field_select', FieldSelect);
fieldRegistry.add('eekes_form_builder_conditions', ConditionsField);
fieldRegistry.add('eekes_form_builder_flow', FlowField);
fieldRegistry.add('eekes_form_builder_notification_preview', NotificationPreview);

listItemActionRegistry.add('eekes_form_builder.show_submission', ShowSubmissionItemAction);

viewRegistry.add(INTEGRATIONS_VIEW, IntegrationConnections);

export {
    FormBuilder,
    FieldSelect,
    ConditionsField,
    FlowField,
    NotificationPreview,
    ShowSubmissionItemAction,
    IntegrationConnections,
    builderModel,
    fieldTypeStore,
};
