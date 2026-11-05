// @flow
import React from 'react';
import classNames from 'classnames';
import {action, observable, reaction, toJS} from 'mobx';
import {observer} from 'mobx-react';
import {Button, Input, Loader} from 'sulu-admin-bundle/components';
import {ResourceRequester} from 'sulu-admin-bundle/services';
import {userStore} from 'sulu-admin-bundle/stores';
import {translate} from 'sulu-admin-bundle/utils';
import styles from './notificationPreview.scss';
import type {FieldTypeProps} from 'sulu-admin-bundle/containers/Form/types';

const RESOURCE_KEY = 'eekes_form_notification_previews';
const RECIPIENT_STORAGE_KEY = 'eekes_form_builder.test_recipient';

/**
 * The e-mail of the notification as it is being edited - not saved yet - with sample answers for
 * the fields of the form, in the template that is picked. It follows the edits, and sends a test
 * to one address.
 */
@observer
class NotificationPreview extends React.Component<FieldTypeProps<mixed>> {
    @observable.ref preview: ?{|from: ?string, html: ?string, problem: ?string, subject: ?string|} = null;
    @observable loading: boolean = false;
    @observable mobile: boolean = false;
    @observable recipient: string = '';
    @observable sending: boolean = false;
    @observable.ref testResult: ?{|message: string, success: boolean|} = null;

    disposeReaction: ?() => void = null;
    timer: ?TimeoutID = null;

    // An action: Sulu's MobX runs in strict mode, which refuses changes outside of one.
    @action componentDidMount() {
        this.recipient = this.defaultRecipient();
        this.load();

        // Every edit of the notification refreshes the preview, a moment after the typing stops.
        this.disposeReaction = reaction(
            () => JSON.stringify(this.notification()),
            () => {
                if (this.timer) {
                    clearTimeout(this.timer);
                }

                this.timer = setTimeout(() => this.load(), 700);
            }
        );
    }

    componentWillUnmount() {
        if (this.disposeReaction) {
            this.disposeReaction();
        }

        if (this.timer) {
            clearTimeout(this.timer);
        }
    }

    defaultRecipient(): string {
        try {
            const remembered = window.localStorage.getItem(RECIPIENT_STORAGE_KEY);

            if (remembered) {
                return remembered;
            }
        } catch (error) {
            // Storage can be unavailable; the address is then simply not remembered.
        }

        const contact: Object = userStore.contact || {};

        return typeof contact.mainEmail === 'string' ? contact.mainEmail : '';
    }

    notification(): Object {
        const {data} = this.props;

        return data ? toJS(data) : {};
    }

    parameters(extra: Object = {}): Object {
        const {formInspector, router} = this.props;

        return {
            formId: router && router.attributes ? router.attributes.id : undefined,
            locale: formInspector && formInspector.locale ? formInspector.locale.get() : undefined,
            ...extra,
        };
    }

    @action load = () => {
        this.loading = true;

        ResourceRequester.post(RESOURCE_KEY, this.notification(), this.parameters())
            .then(action((preview) => {
                this.preview = preview;
            }))
            .catch(action(() => {
                this.preview = {from: null, html: null, problem: translate('eekes_form_builder.email_preview_failed'), subject: null};
            }))
            .finally(action(() => {
                this.loading = false;
            }));
    };

    @action handleRecipientChange = (recipient: ?string) => {
        this.recipient = recipient || '';
    };

    @action handleSend = () => {
        this.sending = true;
        this.testResult = null;

        try {
            window.localStorage.setItem(RECIPIENT_STORAGE_KEY, this.recipient);
        } catch (error) {
            // Not remembered then.
        }

        ResourceRequester.post(RESOURCE_KEY, {...this.notification(), testRecipient: this.recipient}, this.parameters({action: 'send'}))
            .then(action(() => {
                this.testResult = {message: translate('eekes_form_builder.email_test_sent', {recipient: this.recipient}), success: true};
            }))
            .catch((error) => {
                const reason = error && typeof error.json === 'function'
                    ? error.json().then((body) => body && body.detail ? body.detail : null, () => null)
                    : Promise.resolve(null);

                reason.then(action((message) => {
                    this.testResult = {
                        message: translate('eekes_form_builder.email_test_failed') + (message ? ': ' + message : ''),
                        success: false,
                    };
                }));
            })
            .finally(action(() => {
                this.sending = false;
            }));
    };

    @action toggleMobile = (mobile: boolean) => {
        this.mobile = mobile;
    };

    render() {
        const {preview} = this;

        return (
            <div className={styles.preview}>
                <div className={styles.toolbar}>
                    <div className={styles.meta}>
                        {preview && preview.subject !== null && preview.subject !== undefined &&
                            <div><span>{translate('eekes_form_builder.email_preview_subject')}:</span> <strong>{preview.subject}</strong></div>
                        }
                        {preview && preview.from &&
                            <div><span>{translate('eekes_form_builder.email_preview_from')}:</span> {preview.from}</div>
                        }
                    </div>
                    <div className={styles.switch}>
                        <button className={classNames({[styles.active]: !this.mobile})} onClick={() => this.toggleMobile(false)} type="button">{translate('eekes_form_builder.email_preview_desktop')}</button>
                        <button className={classNames({[styles.active]: this.mobile})} onClick={() => this.toggleMobile(true)} type="button">{translate('eekes_form_builder.email_preview_mobile')}</button>
                    </div>
                    <Button icon="su-sync" loading={this.loading} onClick={this.load} skin="link">
                        {translate('eekes_form_builder.email_preview_refresh')}
                    </Button>
                </div>

                {preview && preview.problem && <p className={styles.problem}>{preview.problem}</p>}

                <div className={styles.frame}>
                    {!preview && <Loader />}
                    {preview && preview.html &&
                        <iframe
                            className={classNames(styles.iframe, {[styles.mobile]: this.mobile})}
                            sandbox=""
                            srcDoc={preview.html}
                            title={translate('eekes_form_builder.email_preview')}
                        />
                    }
                </div>

                <div className={styles.test}>
                    <label className={styles.testLabel}>{translate('eekes_form_builder.email_send_test_to')}</label>
                    <div className={styles.testRow}>
                        <div className={styles.testInput}>
                            <Input onChange={this.handleRecipientChange} type="email" value={this.recipient} />
                        </div>
                        <Button disabled={!this.recipient} icon="su-envelope" loading={this.sending} onClick={this.handleSend} skin="secondary">
                            {translate('eekes_form_builder.email_send_test')}
                        </Button>
                    </div>
                    {this.testResult &&
                        <p className={this.testResult.success ? styles.success : styles.problem}>{this.testResult.message}</p>
                    }
                </div>
            </div>
        );
    }
}

export default NotificationPreview;
