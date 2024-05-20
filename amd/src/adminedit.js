import {updateSummativeState} from './repository';
import {updateHidingState} from './repository';
import {updateFeedbackDuedate} from './repository';
import {deleteFeedbackDuedate} from './repository';
import {getString} from 'core/str';
import ModalFactory from 'core/modal_factory';

const Selectors = {
    actions: {
        toggleSummativeState: '[data-action="report_feedback_tracker/summative_checkbox"]',
        toggleHideState: '[data-action="report_feedback_tracker/hiding_checkbox"]',
        datePicker: '[data-action="report_feedback_tracker/datepicker"]',
        customHint: '[data-action="report_feedback_tracker/customhint"]',
    },
};

export const init = () => {
    window.console.log('adminedit.js initialised');

    document.addEventListener('click', async e => {
        if (e.target.closest(Selectors.actions.toggleSummativeState)) {
            const target = e.target;
            const itemid = target.getAttribute('cmid');
            var summativestate = '1';
            if (target.checked === true) {
                summativestate = '1';
            } else {
                summativestate = '0';
            }
            const response = await updateSummativeState(itemid, summativestate);
            window.console.log(response);
        }
    });

    document.addEventListener('click', async e => {
        if (e.target.closest(Selectors.actions.toggleHideState)) {
            const target = e.target;
            const itemid = target.getAttribute('cmid');
            var hiddenstate = '1';
            if (target.checked === true) {
                hiddenstate = '1';
            } else {
                hiddenstate = '0';
            }
            const response = await updateHidingState(itemid, hiddenstate);
            window.console.log(response);
        }
    });

    document.addEventListener('change', async e => {
        if (e.target.closest(Selectors.actions.datePicker)) {
            const target = e.target;
            const itemid = target.getAttribute('itemid');
            const date = new Date(e.target.value).getTime() / 1000;
            var response = '';
            if (!date) { // Delete custom date.
                response = await deleteFeedbackDuedate(itemid);
                // Hide hint.
                document.querySelector('[data-itemid="' + itemid + '"]').style.display = 'none';
                // Show message.
                getString('feedbackduedate:removedmessage', 'report_feedback_tracker')
                    .then(async (message) => {
                        const modal = await ModalFactory.create({
                            title: 'Please note:',
                            body: message,
                            footer: '',
                        });
                        modal.show();
                        return message;
                    });
            } else {
                response = await updateFeedbackDuedate(itemid, date);
                // Show hint.
                document.querySelector('[data-itemid="' + itemid + '"]').style.display = '';
            }
            window.console.log(response);
        }
    });

};