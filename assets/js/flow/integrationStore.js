// @flow
import {action, observable} from 'mobx';
import {Requester, ResourceRequester, resourceRouteRegistry} from 'sulu-admin-bundle/services';
import {userStore} from 'sulu-admin-bundle/stores';

export const RESOURCE_KEY = 'eekes_form_integrations';

export type IntegrationSetting = {|
    default: mixed,
    dynamicOptions: boolean,
    help: ?string,
    key: string,
    label: string,
    options: Array<{label: string, value: string}>,
    required: boolean,
    type: 'text' | 'textarea' | 'select' | 'checkbox',
|};

export type IntegrationField = {|
    help: ?string,
    key: string,
    label: string,
    required: boolean,
    type: string,
|};

export type IntegrationAction = {|
    allowCustomFields: boolean,
    description: ?string,
    dynamicFields: boolean,
    fields: Array<IntegrationField>,
    key: string,
    settings: Array<IntegrationSetting>,
    title: string,
|};

export type Integration = {|
    actions: Array<IntegrationAction>,
    icon: string,
    key: string,
    status: {|message: ?string, ready: boolean|},
    title: string,
|};

/**
 * The integrations a developer enabled, loaded once per admin session.
 */
class IntegrationStore {
    @observable integrations: Array<Integration> = [];
    // Whether the user may open Settings → Form builder integrations to fix an integration that is not ready.
    @observable mayManage: boolean = false;
    promise: ?Promise<Array<Integration>> = null;

    load(): Promise<Array<Integration>> {
        if (!this.promise) {
            this.promise = ResourceRequester.getList(RESOURCE_KEY, {userLocale: userStore.systemLocale})
                .then(action((response) => {
                    this.integrations = response._embedded[RESOURCE_KEY];
                    this.mayManage = response.mayManage === true;

                    return this.integrations;
                }));
        }

        return this.promise;
    }

    integration(key: ?string): ?Integration {
        return this.integrations.find((candidate) => candidate.key === key);
    }

    action(integration: ?string, actionKey: ?string): ?IntegrationAction {
        const found = this.integrations.find((candidate) => candidate.key === integration);

        return found ? found.actions.find((candidate) => candidate.key === actionKey) : null;
    }

    /**
     * The choices of a setting that come from the remote service. A refusal (wrong API key, ...)
     * rejects with the message of the service.
     */
    options(integration: string, actionKey: string, setting: string): Promise<Array<{label: string, value: string}>> {
        const query = 'action=' + encodeURIComponent(actionKey) + '&setting=' + encodeURIComponent(setting);

        return this.get(integration, 'options', query).then((response) => response.options);
    }

    /**
     * The fields the action gets from the remote service for the settings picked so far, e.g. the
     * merge fields of a Mailchimp audience. Rejects like options().
     */
    fields(integration: string, actionKey: string, settings: {[string]: mixed}): Promise<Array<IntegrationField>> {
        const query = 'action=' + encodeURIComponent(actionKey)
            + '&settings=' + encodeURIComponent(JSON.stringify(settings))
            + '&userLocale=' + encodeURIComponent(userStore.systemLocale);

        return this.get(integration, 'fields', query).then((response) => response.fields);
    }

    get(integration: string, path: string, query: string): Promise<Object> {
        const base = resourceRouteRegistry.getUrl('list', RESOURCE_KEY, {});

        return Requester.get(base.split('?')[0] + '/' + encodeURIComponent(integration) + '/' + path + '?' + query)
            .catch((error) => {
                const read = error && typeof error.json === 'function' ? error.json() : Promise.resolve({});

                return read.then((body) => Promise.reject(new Error(body.detail || body.title || String(error))));
            });
    }
}

export default new IntegrationStore();
