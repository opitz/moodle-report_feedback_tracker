import {updateSummativeState} from './repository';
import {updateHidingState} from './repository';
import {updateFeedbackDuedate} from './repository';
import {deleteFeedbackDuedate} from './repository';
import {getString} from 'core/str';
import Modal from 'core/modal';
import ModalEvents from 'core/modal_events';
import ModalSaveCancel from 'core/modal_save_cancel';
import Templates from 'core/templates';

const Selectors = {
    actions: {
        toggleSummativeState: '[data-action="report_feedback_tracker/summative_checkbox"]',
        toggleHideState: '[data-action="report_feedback_tracker/hiding_checkbox"]',
        datePicker: '[data-action="report_feedback_tracker/datepicker"]',
        customHint: '[data-action="report_feedback_tracker/customhint"]',
        dummy: '[data-action="report_feedback_tracker/dummy"]',
    },
};

export const init = () => {
    window.console.log('adminedit.js initialised');

    document.addEventListener('click', async e => {
        if (e.target.closest(Selectors.actions.toggleSummativeState)) {
            const target = e.target;
            const itemid = target.getAttribute('cmid');
            let summativestate = '1';
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
            let hiddenstate = '1';
            if (target.checked === true) {
                hiddenstate = '1';
            } else {
                hiddenstate = '0';
            }
            const response = await updateHidingState(itemid, hiddenstate);
            window.console.log(response);
        }
    });

    document.addEventListener('change', async(e) => {
        if (e.target.closest(Selectors.actions.datePicker)) {
            try {
                const target = e.target;
                const itemid = target.getAttribute('itemid');
                const date = new Date(e.target.value).getTime() / 1000;
                const deadlinedays = target.getAttribute('data-deadlinedays');
                const duedate = target.parentElement.parentElement.parentElement.parentElement.
                querySelector('.col_duedate').innerHTML;

                let response = '';
                if (!date) { // Delete custom date.
                    response = await deleteFeedbackDuedate(itemid);

                    // Hide hint.
                    const hintElement = document.querySelector(`[data-itemid="${itemid}"]`);
                    if (hintElement) {
                        hintElement.style.display = 'none';
                    }

                    // Set datepicker to default date.
                    e.target.value = getDefaultDueDate(duedate, deadlinedays);

                    // Show message.
                    const message = await getString('feedbackduedate:removedmessage', 'report_feedback_tracker');
                    const modal = await Modal.create({
                        title: await getString('pleasenote', 'report_feedback_tracker'),
                        body: message,
                        footer: '',
                    });
                    modal.show();
                } else { // Update custom date.
                    // Get a reason for a manual due date.
                    createReasonModal(itemid, date);
                }

                // Log response to console.
                window.console.log(response);
            } catch (error) {
                window.console.error('An error occurred:', error);
            }
        }
    });

};

/**
 * Get the default feedback due date.
 *
 * @param {string} duedate
 * @param {number} deadlinedays
 * @returns {string}
 */
function getDefaultDueDate(duedate, deadlinedays) {
    // Parse the given date string to create a Date object
    const duedateobject = new Date(duedate);
    // Add the deadline days to the Date object.
    duedateobject.setDate(duedateobject.getDate() + (deadlinedays * 1));

    const year = duedateobject.getFullYear();
    const month = String(duedateobject.getMonth() + 1).padStart(2, '0'); // Months are zero-based
    const day = String(duedateobject.getDate()).padStart(2, '0');

    // Format the date as yyyy-mm-dd.
    return `${year}-${month}-${day}`;
}

/**
 * Create a modal to collect a reason.
 *
 * @param {string} itemid
 * @param {string} date
 * @returns {Promise<void>}
 */
async function createReasonModal(itemid, date) {
    // Show a modal with a text field.
    const modal = await ModalSaveCancel.create({
        title: await getString('feedbackduedate:reason', 'report_feedback_tracker'),
        body: await Templates.render('report_feedback_tracker/duedatereason_modal',
            {
                reason: ''
            }),
    });

    const root = modal.getRoot();

    root.on(ModalEvents.save, async(e) => {
        const duedatereason = document.getElementById('duedatereason').value;
        const reasonError = modal.getRoot().find('#reason-error');

        // Check if the reason input is empty
        if (duedatereason.trim() === '') {
            e.preventDefault();
            reasonError.removeClass('d-none').addClass('d-flex');
        } else {
            reasonError.removeClass('d-flex').addClass('d-none');

            // Update custom date.
            let response = await updateFeedbackDuedate(itemid, date, duedatereason);

            // Show hint.
            const hintElement = document.querySelector(`[data-itemid="${itemid}"]`);
            if (hintElement) {
                hintElement.style.display = '';
            }

            window.console.log(response);
        }
    });

    modal.show();

    root.on(ModalEvents.cancel, () => {
        window.console.log('cancelled!');
        modal.destroy();
    });

    root.on(ModalEvents.hidden, function() {
        modal.destroy();
    });

}