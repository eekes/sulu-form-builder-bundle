// @flow
import React from 'react';
import {action, observable} from 'mobx';
import {AbstractListItemAction} from 'sulu-admin-bundle/views';
import SubmissionOverlay from './SubmissionOverlay';

/**
 * The eye in front of every row of the submissions list, and of the list of blocked submissions:
 * opens the submission in a modal instead of yet another page. The list it belongs to decides
 * which resource is loaded; the "mayRelease" option (set by FormAdmin) offers "Not spam".
 */
export default class ShowSubmissionItemAction extends AbstractListItemAction {
    @observable openId: ?(number | string) = null;

    getNode() {
        if (this.openId === null || this.openId === undefined) {
            return null;
        }

        return (
            <SubmissionOverlay
                id={this.openId}
                key="eekes_form_builder.show_submission"
                mayRelease={this.options.mayRelease === true}
                onClose={this.handleClose}
                onReleased={this.handleReleased}
                resourceKey={this.listStore.resourceKey}
            />
        );
    }

    getItemActionConfig() {
        return {
            icon: 'su-eye',
            onClick: this.handleClick,
            disabled: false,
        };
    }

    @action handleClick = (id: number | string) => {
        this.openId = id;
    };

    @action handleClose = () => {
        this.openId = null;
    };

    handleReleased = () => {
        // The released submission moved to the real ones.
        this.listStore.reload();
    };
}
