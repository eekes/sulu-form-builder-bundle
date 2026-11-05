// @flow
import React from 'react';
import {action, observable} from 'mobx';
import {observer} from 'mobx-react';
import {Icon, Loader, Overlay} from 'sulu-admin-bundle/components';
import {ResourceRequester} from 'sulu-admin-bundle/services';
import {translate} from 'sulu-admin-bundle/utils';
import DeliveryList from './DeliveryList';
import RunList from './RunList';
import styles from './submission.scss';

type Props = {|
    id: number | string,
    mayRelease: boolean,
    onClose: () => void,
    onReleased: () => void,
    resourceKey: string,
|};

/**
 * Every answer of one submission, readable: one row per field with the label the visitor saw,
 * uploads as download links. A blocked submission also shows why it was caught and where it came
 * from, and can be released as a real one ("Not spam").
 */
@observer
class SubmissionOverlay extends React.Component<Props> {
    static defaultProps = {
        mayRelease: false,
        onReleased: () => {},
        resourceKey: 'eekes_form_submissions',
    };

    // A plain reference: deep observability would turn the arrays into MobX arrays, which Array.isArray rejects.
    @observable.ref submission: ?Object = null;
    @observable releasing: boolean = false;

    componentDidMount() {
        const {id, resourceKey} = this.props;

        ResourceRequester.get(resourceKey, {id}).then(action((submission) => {
            this.submission = submission;
        }));
    }

    get canRelease(): boolean {
        const {submission} = this;

        return this.props.mayRelease && !!submission && submission.spam === true && submission.releasable === true;
    }

    @action handleRelease = () => {
        const {id, onClose, onReleased, resourceKey} = this.props;
        this.releasing = true;

        ResourceRequester.post(resourceKey, {}, {id, action: 'release'}).then(action(() => {
            this.releasing = false;
            onReleased();
            onClose();
        })).catch(action(() => {
            this.releasing = false;
        }));
    };

    renderDate(value: string): string {
        const date = new Date(value);

        return isNaN(date.getTime()) ? value : date.toLocaleString();
    }

    render() {
        const {onClose} = this.props;
        const {submission} = this;

        return (
            <Overlay
                confirmLoading={this.releasing}
                confirmText={this.canRelease ? translate('eekes_form_builder.not_spam') : undefined}
                onClose={onClose}
                onConfirm={this.canRelease ? this.handleRelease : undefined}
                open={true}
                size="large"
                title={translate(submission && submission.spam ? 'eekes_form_builder.blocked_submission' : 'eekes_form_builder.submission')
                    + (submission ? ' #' + submission.id : '')}
            >
                <div className={styles.content}>
                    {!submission && <Loader />}
                    {submission &&
                        <React.Fragment>
                            <dl className={styles.meta}>
                                <dt>{translate('eekes_form_builder.submitted_at')}</dt>
                                <dd>{this.renderDate(submission.createdAt)}</dd>
                                <dt>{translate('eekes_form_builder.language')}</dt>
                                <dd>{submission.locale}</dd>
                                {submission.url &&
                                    <React.Fragment>
                                        <dt>{translate('eekes_form_builder.sent_from')}</dt>
                                        <dd><a href={submission.url} rel="noopener noreferrer" target="_blank">{submission.url}</a></dd>
                                    </React.Fragment>
                                }
                                {submission.spam &&
                                    <React.Fragment>
                                        <dt>{translate('eekes_form_builder.spam_check')}</dt>
                                        <dd>{submission.spamCheck}</dd>
                                        <dt>{translate('eekes_form_builder.spam_reason')}</dt>
                                        <dd>{submission.spamReason}</dd>
                                        {submission.spamScore !== null && submission.spamScore !== undefined &&
                                            <React.Fragment>
                                                <dt>{translate('eekes_form_builder.spam_score')}</dt>
                                                <dd>{submission.spamScore}</dd>
                                            </React.Fragment>
                                        }
                                        {submission.ipAddress &&
                                            <React.Fragment>
                                                <dt>{translate('eekes_form_builder.ip_address')}</dt>
                                                <dd>{submission.ipAddress}</dd>
                                            </React.Fragment>
                                        }
                                        {submission.userAgent &&
                                            <React.Fragment>
                                                <dt>{translate('eekes_form_builder.user_agent')}</dt>
                                                <dd>{submission.userAgent}</dd>
                                            </React.Fragment>
                                        }
                                    </React.Fragment>
                                }
                            </dl>

                            {submission.spam && submission.values.length === 0 &&
                                <p className={styles.empty}>{translate('eekes_form_builder.spam_without_answers')}</p>
                            }

                            <table className={styles.values}>
                                <tbody>
                                    {submission.values.map((value) => (
                                        <tr key={value.key}>
                                            <th>{value.label}</th>
                                            <td>
                                                {value.files.length > 0
                                                    ? value.files.map((file) => (
                                                        <a className={styles.file} download={file.name} href={file.url} key={file.url}>
                                                            <Icon name="su-download" /> {file.name}
                                                        </a>
                                                    ))
                                                    : (value.display !== ''
                                                        ? <span className={styles.display}>{value.display}</span>
                                                        : <em className={styles.empty}>{translate('eekes_form_builder.no_answer')}</em>)
                                                }
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>

                            {Array.isArray(submission.deliveries) &&
                                <section className={styles.runSection}>
                                    <h3>{translate('eekes_form_builder.emails')}</h3>
                                    <DeliveryList deliveries={submission.deliveries} />
                                </section>
                            }

                            {Array.isArray(submission.runs) &&
                                <section className={styles.runSection}>
                                    <h3>{translate('eekes_form_builder.runs')}</h3>
                                    <RunList runs={submission.runs} />
                                </section>
                            }
                        </React.Fragment>
                    }
                </div>
            </Overlay>
        );
    }
}

export default SubmissionOverlay;
