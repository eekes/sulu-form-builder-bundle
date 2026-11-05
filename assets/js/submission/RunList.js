// @flow
import React from 'react';
import classNames from 'classnames';
import {action, observable} from 'mobx';
import {observer} from 'mobx-react';
import {Button} from 'sulu-admin-bundle/components';
import {ResourceRequester} from 'sulu-admin-bundle/services';
import {translate} from 'sulu-admin-bundle/utils';
import styles from './submission.scss';

type Attempt = {
    durationMs: ?number,
    message: ?string,
    number: number,
    request: mixed,
    response: mixed,
    retryable: boolean,
    startedAt: string,
    succeeded: ?boolean,
    trigger: 'submission' | 'automatic' | 'editor' | 'release',
    triggeredBy: ?string,
};

type Run = {
    action: string,
    attemptLog: Array<Attempt>,
    attempts: number,
    createdAt: string,
    finishedAt: ?string,
    flowName: string,
    id: number,
    integration: string,
    message: ?string,
    request: mixed,
    response: mixed,
    retryable: boolean,
    nextAttemptAt: ?string,
    status: 'pending' | 'succeeded' | 'failed' | 'retrying' | 'skipped',
    values: mixed,
};

type Props = {|
    runs: Array<Run>,
|};

function formatDate(value: ?string): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    return isNaN(date.getTime()) ? value : date.toLocaleString();
}

/**
 * What every integration flow did with this submission - and which flows did not run, and why -
 * with every attempt, everything that was sent and received, and a way to try again.
 */
@observer
class RunList extends React.Component<Props> {
    // A plain reference: deep observability would turn attemptLog into a MobX array, which
    // Array.isArray rejects. The list is only ever replaced as a whole.
    @observable.ref runs: Array<Run>;
    @observable open: {[number]: boolean} = {};
    @observable retrying: ?number = null;

    constructor(props: Props) {
        super(props);

        this.runs = props.runs;
    }

    // Replaced, not extended: MobX 4 does not see keys added to an observable object.
    @action toggle(id: number) {
        this.open = {...this.open, [id]: !this.open[id]};
    }

    @action retry(run: Run) {
        this.retrying = run.id;
        ResourceRequester.post('eekes_form_integration_runs', undefined, {id: run.id, action: 'retry'})
            .then(action((updated) => {
                this.runs = this.runs.map((current) => current.id === run.id ? updated : current);
                this.open = {...this.open, [run.id]: true};
            }))
            .finally(action(() => {
                this.retrying = null;
            }));
    }

    renderJson(title: string, value: mixed) {
        if (value === null || value === undefined) {
            return null;
        }

        return (
            <div className={styles.runBlock}>
                <h5>{title}</h5>
                <pre>{typeof value === 'string' ? value : JSON.stringify(value, null, 2)}</pre>
            </div>
        );
    }

    renderAttempt(attempt: Attempt) {
        const status = attempt.succeeded === null ? 'pending' : (attempt.succeeded ? 'succeeded' : 'failed');
        const trigger = translate('eekes_form_builder.attempt_trigger.' + attempt.trigger)
            + (attempt.triggeredBy ? ' (' + attempt.triggeredBy + ')' : '');

        return (
            <li className={styles.attempt} key={attempt.number}>
                <div className={styles.attemptHeader}>
                    <span className={classNames(styles.status, styles[status])}>
                        #{attempt.number} {translate('eekes_form_builder.run_status.' + status)}
                    </span>
                    <span className={styles.runMeta}>
                        {formatDate(attempt.startedAt)} · {trigger}
                        {attempt.durationMs !== null && attempt.durationMs !== undefined ? ' · ' + attempt.durationMs + ' ms' : ''}
                        {attempt.retryable ? ' · ' + translate('eekes_form_builder.attempt_retryable') : ''}
                    </span>
                </div>
                {attempt.message && <p className={styles.runMessage}>{attempt.message}</p>}
                {this.renderJson(translate('eekes_form_builder.run_request'), attempt.request)}
                {this.renderJson(translate('eekes_form_builder.run_response'), attempt.response)}
            </li>
        );
    }

    render() {
        if (this.runs.length === 0) {
            return <p className={styles.empty}>{translate('eekes_form_builder.no_runs')}</p>;
        }

        return (
            <ul className={styles.runs}>
                {this.runs.map((run) => (
                    <li className={styles.run} key={run.id}>
                        <div className={styles.runHeader}>
                            <span className={classNames(styles.status, styles[run.status])}>
                                {translate('eekes_form_builder.run_status.' + run.status)}
                            </span>
                            <span className={styles.runTitle}>
                                <strong>{run.flowName}</strong> · {run.integration}: {run.action}
                            </span>
                            {run.status !== 'skipped' &&
                                <span className={styles.runMeta}>
                                    {translate('eekes_form_builder.attempts')}: {run.attempts}
                                </span>
                            }
                            {run.status !== 'skipped' &&
                                <Button onClick={() => this.toggle(run.id)} skin="link">{translate('eekes_form_builder.show_details')}</Button>
                            }
                            {run.retryable &&
                                <Button
                                    icon="su-sync"
                                    loading={this.retrying === run.id}
                                    onClick={() => this.retry(run)}
                                    skin="secondary"
                                >
                                    {translate('eekes_form_builder.retry')}
                                </Button>
                            }
                        </div>
                        {run.message && <p className={styles.runMessage}>{run.message}</p>}
                        {run.status === 'retrying' && run.nextAttemptAt &&
                            <p className={styles.runMeta}>{translate('eekes_form_builder.next_attempt_at')}: {formatDate(run.nextAttemptAt)}</p>
                        }
                        {this.open[run.id] &&
                            <div className={styles.runDetails}>
                                {this.renderJson(translate('eekes_form_builder.run_values'), run.values)}
                                {Array.isArray(run.attemptLog) && run.attemptLog.length > 0
                                    ? (
                                        <div className={styles.runBlock}>
                                            <h5>{translate('eekes_form_builder.attempt_log')}</h5>
                                            <ol className={styles.attempts}>{run.attemptLog.map((attempt) => this.renderAttempt(attempt))}</ol>
                                        </div>
                                    )
                                    : (
                                        <React.Fragment>
                                            {this.renderJson(translate('eekes_form_builder.run_request'), run.request)}
                                            {this.renderJson(translate('eekes_form_builder.run_response'), run.response)}
                                        </React.Fragment>
                                    )
                                }
                            </div>
                        }
                    </li>
                ))}
            </ul>
        );
    }
}

export default RunList;
