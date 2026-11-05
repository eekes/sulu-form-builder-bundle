// @flow
import React from 'react';
import classNames from 'classnames';
import {action, observable} from 'mobx';
import {observer} from 'mobx-react';
import {Button} from 'sulu-admin-bundle/components';
import {ResourceRequester} from 'sulu-admin-bundle/services';
import {translate} from 'sulu-admin-bundle/utils';
import styles from './submission.scss';

const RESOURCE_KEY = 'eekes_form_notification_deliveries';

type Delivery = {|
    attachments: Array<string>,
    createdAt: string,
    from: ?string,
    id: number,
    message: ?string,
    notificationName: string,
    recipients: {[string]: Array<string>},
    resendable: boolean,
    sentAt: ?string,
    status: 'queued' | 'sent' | 'failed' | 'skipped',
    subject: ?string,
    template: ?string,
    trigger: 'submission' | 'resend' | 'release',
    triggeredBy: ?string,
|};

type Props = {|
    deliveries: Array<Delivery>,
|};

function formatDate(value: ?string): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    return isNaN(date.getTime()) ? value : date.toLocaleString();
}

/**
 * Every e-mail of this submission - and every notification that was not sent, and why - with the
 * e-mail exactly as it was sent, and a way to send it again.
 */
@observer
class DeliveryList extends React.Component<Props> {
    // Plain references: replaced as a whole, and Array.isArray rejects MobX arrays.
    @observable.ref deliveries: Array<Delivery>;
    @observable.ref bodies: {[number]: string} = {};
    @observable resending: ?number = null;

    constructor(props: Props) {
        super(props);

        this.deliveries = props.deliveries;
    }

    @action toggle(delivery: Delivery) {
        if (this.bodies[delivery.id] !== undefined) {
            const {[delivery.id]: removed, ...rest} = this.bodies; // eslint-disable-line no-unused-vars
            this.bodies = rest;

            return;
        }

        ResourceRequester.get(RESOURCE_KEY, {id: delivery.id}).then(action((full) => {
            this.bodies = {...this.bodies, [delivery.id]: full.html || ''};
        }));
    }

    @action resend(delivery: Delivery) {
        this.resending = delivery.id;

        ResourceRequester.post(RESOURCE_KEY, undefined, {id: delivery.id, action: 'resend'})
            .then(action((sent) => {
                this.deliveries = [...this.deliveries, sent];
            }))
            .finally(action(() => {
                this.resending = null;
            }));
    }

    render() {
        if (this.deliveries.length === 0) {
            return <p className={styles.empty}>{translate('eekes_form_builder.no_emails')}</p>;
        }

        return (
            <ul className={styles.runs}>
                {this.deliveries.map((delivery) => (
                    <li className={styles.run} key={delivery.id}>
                        <div className={styles.runHeader}>
                            <span className={classNames(styles.status, styles[delivery.status])}>
                                {translate('eekes_form_builder.delivery_status.' + delivery.status)}
                            </span>
                            <span className={styles.runTitle}>
                                <strong>{delivery.notificationName}</strong>
                                {delivery.subject ? ' · ' + delivery.subject : ''}
                            </span>
                            <span className={styles.runMeta}>
                                {formatDate(delivery.sentAt || delivery.createdAt)}
                                {delivery.trigger !== 'submission'
                                    ? ' · ' + translate('eekes_form_builder.delivery_trigger.' + delivery.trigger)
                                        + (delivery.triggeredBy ? ' (' + delivery.triggeredBy + ')' : '')
                                    : ''}
                            </span>
                            {delivery.resendable &&
                                <Button onClick={() => this.toggle(delivery)} skin="link">
                                    {translate(this.bodies[delivery.id] !== undefined ? 'eekes_form_builder.delivery_hide' : 'eekes_form_builder.delivery_show')}
                                </Button>
                            }
                            {delivery.resendable &&
                                <Button icon="su-envelope" loading={this.resending === delivery.id} onClick={() => this.resend(delivery)} skin="secondary">
                                    {translate('eekes_form_builder.delivery_resend')}
                                </Button>
                            }
                        </div>
                        {delivery.recipients.to && delivery.recipients.to.length > 0 &&
                            <p className={styles.runMeta}>
                                {translate('eekes_form_builder.delivery_to')}: {delivery.recipients.to.join(', ')}
                                {delivery.recipients.cc ? ' · CC: ' + delivery.recipients.cc.join(', ') : ''}
                                {delivery.recipients.bcc ? ' · BCC: ' + delivery.recipients.bcc.join(', ') : ''}
                                {delivery.attachments.length > 0 ? ' · 📎 ' + delivery.attachments.join(', ') : ''}
                            </p>
                        }
                        {delivery.message && <p className={styles.runMessage}>{delivery.message}</p>}
                        {this.bodies[delivery.id] !== undefined &&
                            <iframe className={styles.emailFrame} sandbox="" srcDoc={this.bodies[delivery.id]} title={delivery.subject || ''} />
                        }
                    </li>
                ))}
            </ul>
        );
    }
}

export default DeliveryList;
