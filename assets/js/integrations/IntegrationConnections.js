// @flow
import React from 'react';
import classNames from 'classnames';
import {action, observable} from 'mobx';
import {observer} from 'mobx-react';
import {Button, Dialog, Icon, Loader} from 'sulu-admin-bundle/components';
import {ResourceRequester} from 'sulu-admin-bundle/services';
import {userStore} from 'sulu-admin-bundle/stores';
import {translate} from 'sulu-admin-bundle/utils';
import styles from './integrationConnections.scss';
import type {ViewProps} from 'sulu-admin-bundle/containers';

export const INTEGRATIONS_VIEW = 'eekes_form_builder.integration_connections';
const RESOURCE_KEY = 'eekes_form_integration_connections';

type Detail = {|label: string, value: string|};

type LastTest = {|
    details: Array<Detail>,
    message: ?string,
    passed: boolean,
    testedAt: string,
    testedBy: ?string,
|};

type Connection = {|
    connectable: boolean,
    connected: boolean,
    details: Array<Detail>,
    lastTest: ?LastTest,
    icon: string,
    key: string,
    message: ?string,
    ready: boolean,
    redirectUri: ?string,
    requirements: Array<{|hint: ?string, label: string, met: boolean|}>,
    state: string,
    steps: Array<string>,
    testSendsData: boolean,
    testable: boolean,
    title: string,
|};

type Result = {|key: string, message: ?string, success: boolean, test?: boolean|};

/**
 * Settings → Form builder integrations: whether every enabled integration can run, what is still needed
 * when it cannot, and connecting or disconnecting an account (OAuth) for those that support it.
 */
@observer
class IntegrationConnections extends React.Component<ViewProps> {
    // Plain references: the lists are replaced as a whole, and Array.isArray rejects MobX arrays.
    @observable.ref connections: ?Array<Connection> = null;
    @observable.ref result: ?Result = null;
    @observable busy: ?string = null;
    @observable disconnecting: ?string = null;
    @observable sendingTest: ?string = null;
    @observable testing: ?string = null;
    // The integration whose test just came back, highlighted for a moment.
    @observable justTested: ?string = null;

    componentDidMount() {
        this.load();
    }

    get mayEdit(): boolean {
        return this.props.route.options.mayEdit === true;
    }

    load() {
        ResourceRequester.getList(RESOURCE_KEY, {userLocale: userStore.systemLocale}).then(action((response) => {
            this.connections = response._embedded[RESOURCE_KEY];
            this.result = response.result || this.result;
        }));
    }

    @action connect(connection: Connection) {
        this.result = null;
        this.busy = connection.key;

        // The service sends the user back to the admin, which comes back here with the result.
        ResourceRequester.post(RESOURCE_KEY, {}, {key: connection.key, action: 'connect'})
            .then((response) => {
                window.location.href = response.url;
            })
            .catch((error) => {
                // Sulu's requester rejects with the response itself; its body says why.
                const reason = error && typeof error.json === 'function'
                    ? error.json().then((body) => body && body.detail ? body.detail : null, () => null)
                    : Promise.resolve(null);

                reason.then(action((message) => {
                    this.busy = null;
                    this.result = {key: connection.key, message, success: false};
                }));
            });
    }

    @action handleDisconnectConfirm = () => {
        const key = this.disconnecting;

        if (!key) {
            return;
        }

        this.result = null;
        this.busy = key;
        ResourceRequester.post(RESOURCE_KEY, {}, {key, action: 'disconnect', userLocale: userStore.systemLocale})
            .then(action((updated) => {
                this.connections = (this.connections || []).map((connection) => connection.key === key ? updated : connection);
            }))
            .finally(action(() => {
                this.busy = null;
                this.disconnecting = null;
            }));
    };

    @action handleDisconnectCancel = () => {
        this.disconnecting = null;
    };

    @action test(connection: Connection) {
        // A test that sends something real (a webhook) is only sent after a confirmation.
        if (connection.testSendsData && this.sendingTest !== connection.key) {
            this.sendingTest = connection.key;

            return;
        }

        // The old outcome goes away at once, so a new test that fails the same way is visibly a new one.
        this.sendingTest = null;
        this.result = null;
        this.testing = connection.key;
        ResourceRequester.post(RESOURCE_KEY, {}, {key: connection.key, action: 'test', userLocale: userStore.systemLocale})
            .then(action((updated) => {
                this.connections = (this.connections || []).map((current) => current.key === connection.key ? updated : current);
                this.justTested = connection.key;
                setTimeout(action(() => {
                    if (this.justTested === connection.key) {
                        this.justTested = null;
                    }
                }), 2500);
            }))
            .catch((error) => {
                // The test itself could not run (no permission, a server error): say so instead of nothing.
                const reason = error && typeof error.json === 'function'
                    ? error.json().then((body) => body && (body.detail || body.message) ? body.detail || body.message : null, () => null)
                    : Promise.resolve(null);

                reason.then(action((message) => {
                    this.result = {key: connection.key, message, success: false, test: true};
                }));
            })
            .finally(action(() => {
                this.testing = null;
            }));
    }

    @action handleSendTestConfirm = () => {
        const connection = (this.connections || []).find((candidate) => candidate.key === this.sendingTest);

        if (connection) {
            this.test(connection);
        }
    };

    @action handleSendTestCancel = () => {
        this.sendingTest = null;
    };

    renderLastTest(connection: Connection) {
        const {lastTest} = connection;

        if (!connection.testable) {
            return null;
        }

        if (this.testing === connection.key) {
            return (
                <div className={classNames(styles.lastTest, styles.testing)}>
                    <Loader size={14} />
                    <span>{translate('eekes_form_builder.integration_testing')}</span>
                </div>
            );
        }

        if (!lastTest) {
            return <p className={styles.lastTest}>{translate('eekes_form_builder.integration_never_tested')}</p>;
        }

        const date = new Date(lastTest.testedAt);

        return (
            <div
                className={classNames(styles.lastTest, lastTest.passed ? styles.success : styles.failure, {
                    [styles.fresh]: this.justTested === connection.key,
                })}
            >
                <strong>
                    {translate('eekes_form_builder.integration_last_test')}:{' '}
                    {translate(lastTest.passed ? 'eekes_form_builder.integration_test_passed' : 'eekes_form_builder.integration_test_failed')}
                </strong>
                {' · '}{isNaN(date.getTime()) ? lastTest.testedAt : date.toLocaleString()}
                {lastTest.testedBy ? ' · ' + lastTest.testedBy : ''}
                {lastTest.message && <p>{lastTest.message}</p>}
                {lastTest.details.length > 0 &&
                    <dl className={styles.details}>
                        {lastTest.details.map((detail) => (
                            <React.Fragment key={detail.label}>
                                <dt>{detail.label}</dt>
                                <dd>{detail.value}</dd>
                            </React.Fragment>
                        ))}
                    </dl>
                }
            </div>
        );
    }

    renderResult() {
        const {result, connections} = this;

        if (!result) {
            return null;
        }

        const connection = (connections || []).find((candidate) => candidate.key === result.key);
        const title = connection ? connection.title : result.key;

        return (
            <div className={classNames(styles.result, result.success ? styles.success : styles.failure)}>
                <strong>{title}: </strong>
                {result.success
                    ? translate('eekes_form_builder.integration_connected')
                    : translate(result.test ? 'eekes_form_builder.integration_test_could_not_run' : 'eekes_form_builder.integration_connect_failed')
                        + (result.message ? ': ' + result.message : '')}
            </div>
        );
    }

    renderConnection(connection: Connection) {
        return (
            <li className={styles.connection} key={connection.key}>
                <div className={styles.header}>
                    <Icon className={styles.icon} name={connection.icon} />
                    <h2>{connection.title}</h2>
                    <span className={classNames(styles.badge, connection.ready ? styles.ready : styles.actionRequired)}>
                        {translate(connection.ready ? 'eekes_form_builder.integration_ready' : 'eekes_form_builder.integration_action_required')}
                    </span>
                </div>

                {connection.message && <p className={styles.message}>{connection.message}</p>}

                {connection.details.length > 0 &&
                    <dl className={styles.details}>
                        {connection.details.map((detail) => (
                            <React.Fragment key={detail.label}>
                                <dt>{detail.label}</dt>
                                <dd>{detail.value}</dd>
                            </React.Fragment>
                        ))}
                    </dl>
                }

                {Array.isArray(connection.requirements) && connection.requirements.length > 0 &&
                    <div className={styles.steps}>
                        <h3>{translate('eekes_form_builder.integration_requirements')}</h3>
                        <ul className={styles.requirements}>
                            {connection.requirements.map((requirement) => (
                                <li className={requirement.met ? styles.met : styles.notMet} key={requirement.label}>
                                    <Icon className={styles.requirementIcon} name={requirement.met ? 'su-check' : 'su-times'} />
                                    <span className={styles.requirementLabel}>{requirement.label}</span>
                                    {requirement.hint && <span className={styles.requirementHint}>{requirement.hint}</span>}
                                </li>
                            ))}
                        </ul>
                    </div>
                }

                {connection.steps.length > 0 &&
                    <div className={styles.steps}>
                        <h3>{translate('eekes_form_builder.integration_steps')}</h3>
                        <ol>{connection.steps.map((step) => <li key={step}>{step}</li>)}</ol>
                    </div>
                }

                {connection.redirectUri &&
                    <div className={styles.redirect}>
                        <h3>{translate('eekes_form_builder.integration_redirect_uri')}</h3>
                        <code>{connection.redirectUri}</code>
                    </div>
                }

                {this.renderLastTest(connection)}

                {(connection.connectable || connection.testable) && this.mayEdit &&
                    <div className={styles.actions}>
                        {connection.connectable &&
                            <Button
                                icon="su-link"
                                loading={this.busy === connection.key}
                                onClick={() => this.connect(connection)}
                                skin={connection.connected ? 'secondary' : 'primary'}
                            >
                                {translate(connection.connected ? 'eekes_form_builder.integration_reconnect' : 'eekes_form_builder.integration_connect')}
                            </Button>
                        }
                        {/* Not ready yet: a test could only repeat what the steps above already say. */}
                        {connection.testable && connection.ready &&
                            <Button
                                icon="su-sync"
                                loading={this.testing === connection.key}
                                onClick={() => this.test(connection)}
                                skin="secondary"
                            >
                                {translate(connection.testSendsData ? 'eekes_form_builder.integration_send_test' : 'eekes_form_builder.integration_test')}
                            </Button>
                        }
                        {connection.testable && !connection.ready &&
                            <span className={styles.hint}>{translate('eekes_form_builder.integration_test_after_steps')}</span>
                        }
                        {connection.connectable && connection.connected &&
                            <Button onClick={action(() => { this.disconnecting = connection.key; })} skin="link">
                                {translate('eekes_form_builder.integration_disconnect')}
                            </Button>
                        }
                    </div>
                }
            </li>
        );
    }

    render() {
        const {connections} = this;

        return (
            <div className={styles.page}>
                <h1>{translate('eekes_form_builder.form_integrations')}</h1>
                {this.renderResult()}
                {!connections && <Loader />}
                {connections && <ul className={styles.connections}>{connections.map((connection) => this.renderConnection(connection))}</ul>}

                <Dialog
                    cancelText={translate('sulu_admin.cancel')}
                    confirmLoading={!!this.busy}
                    confirmText={translate('eekes_form_builder.integration_disconnect')}
                    onCancel={this.handleDisconnectCancel}
                    onConfirm={this.handleDisconnectConfirm}
                    open={!!this.disconnecting}
                    title={translate('eekes_form_builder.integration_disconnect') + '?'}
                >
                    {translate('eekes_form_builder.integration_disconnect_confirm')}
                </Dialog>

                <Dialog
                    cancelText={translate('sulu_admin.cancel')}
                    confirmText={translate('eekes_form_builder.integration_send_test')}
                    onCancel={this.handleSendTestCancel}
                    onConfirm={this.handleSendTestConfirm}
                    open={!!this.sendingTest}
                    title={translate('eekes_form_builder.integration_send_test') + '?'}
                >
                    {translate('eekes_form_builder.integration_send_test_confirm')}
                </Dialog>
            </div>
        );
    }
}

export default IntegrationConnections;
