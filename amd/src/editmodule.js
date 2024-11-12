import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import Templates from 'core/templates';
import {getAssessmentTypes, updateModule} from "./repository";

const Selectors = {
    actions: {
        editModule: '[data-action="report_feedback_tracker/editmodule"]',
    },
};

export const init = async() => {
    window.console.log('editmodule.js initialised');

    document.addEventListener('click', async e => {
        if (e.target.closest(Selectors.actions.editModule)) {
            const gradeitemid = e.target.getAttribute('data-gradeitemid');
            const partid = e.target.getAttribute('data-partid');

            const module = e.target.closest('.module');

            const icon = module.querySelector('[data-icon]').innerHTML;
            const name = module.querySelector('[data-name]').innerHTML;

            const assessmenttype = module.querySelector('[data-assessmenttype]').getAttribute('data-assessmenttype');
            const locked = module.querySelector('[data-locked]').getAttribute('data-locked') * 1;
            const assessmenttypelabel = module.querySelector('[data-label]').getAttribute('data-label');
            const feedbackduedateraw = module.querySelector('[data-feedbackduedateraw]').getAttribute('data-feedbackduedateraw');

            // Format the feedback due date for the date picker.
            let date = new Date(Date.now()); // Use the current date timestamp in milliseconds by default.
            if (feedbackduedateraw < 9999999999) { // If there is a valid raw feedback date use this.
                date = new Date(feedbackduedateraw * 1000); // Convert to milliseconds
            }
            // Extract year, month, and day, and format as 'Y-m-d'
            const fullyear = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            const formatteddate = `${fullyear}-${month}-${day}`;

            // Optional information.
            const contactElement = module.querySelector('[data-contact]');
            const methodElement = module.querySelector('[data-method]');
            const generalfeedbackElement = module.querySelector('[data-generalfeedback]');
            const hiddenElement = module.querySelector('[data-hiddenfromreport]');

            const contact = contactElement ? contactElement.getAttribute('data-contact') : null;
            const method = methodElement ? methodElement.getAttribute('data-method') : null;
            const generalfeedback = generalfeedbackElement ? generalfeedbackElement.getAttribute('data-generalfeedback') : null;
            const hidden = hiddenElement ? hiddenElement.getAttribute('data-hiddenfromreport') : null;

            // Get the assessment type options with the current selection.
            const selection = assessmenttype * 1; // Make sure it is an integer.
            const assessmenttypes = JSON.parse(await getAssessmentTypes(selection));

            // Show a modal to edit.
            const modal = await ModalSaveCancel.create({
                large: true,
                title: 'Edit module',
                body: Templates.render('report_feedback_tracker/course/modedit_modal',
                    {
                        gradeitemid: gradeitemid,
                        partid: partid,
                        icon: icon,
                        name: name,
                        contact: contact,
                        method: method,
                        generalfeedback: generalfeedback,
                        hidden: hidden,
                        assessmenttype: assessmenttype,
                        assessmenttypelabel: assessmenttypelabel,
                        locked: locked,
                        feedbackduedateraw: feedbackduedateraw,
                        formatteddate: formatteddate,
                        assessmenttypes: assessmenttypes
                    }),
            });
            modal.show();

            modal.getRoot().on(ModalEvents.save, async() => {
                const gradeitemid = document.getElementById('js-gradeitemid').value;
                const partid = document.getElementById('js-partid').value;
                const contact = document.getElementById('js-contact').value;
                const method = document.getElementById('js-method').value;
                const hidden = document.getElementById('js-hidden').checked;
                const assessmenttype = document.getElementById('js-assessmenttype').value;
                const generalfeedback = document.getElementById('js-generalfeedback').value;

                // The feedback due date from date picker.
                const feedbackduedate = document.getElementById('feedbackduedate').value;
                const formatteddate = document.getElementById('js-formatteddate').value;

                // Convert the datepicker output into UNIX timestamp.
                const date = new Date(feedbackduedate);
                let feedbackduedateraw = Math.floor(date.getTime() / 1000);

                // If the date has NOT changed, mark it for NOT saving.
                if (feedbackduedate === formatteddate) {
                    feedbackduedateraw = -1;
                }

                // Update the database.
                await updateModule(gradeitemid, partid, contact, method, hidden, assessmenttype,
                    feedbackduedateraw, generalfeedback);

                // Reload the page.
                location.reload(true);
            });

            modal.getRoot().on(ModalEvents.cancel, () => {
                modal.destroy();
            });

            modal.getRoot().on(ModalEvents.hidden, function() {
                modal.destroy();
            });
        }
    });

};
