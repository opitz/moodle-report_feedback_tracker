import Modal from 'core/modal';
import ModalEvents from 'core/modal_events';
import Templates from 'core/templates';
import {getAssessmentTypes} from "./repository";
import {get_string as getString} from 'core/str';

const Selectors = {
    actions: {
        editModule: '[data-action="report_feedback_tracker/editmodule"]',
    },
};

export const init = async() => {

    document.addEventListener('click', async e => {
        if (e.target.closest(Selectors.actions.editModule)) {
            const sesskey = M.cfg.sesskey;
            const courseid = document.getElementById('courseid').value;
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

            // If assessment type is either dummy or summative set by SITS disable 'hide from student report' option.
            // TODO: Use assess_type::ASSESS_TYPE_DUMMY.
            const assessTypeDummy = 2;
            const hiddendisabled = (selection === assessTypeDummy) || locked;

            const cohortfeedback = module.querySelector('.js-cohortfeedback');

            const title = `${await getString('edit', 'report_feedback_tracker')} ${name}`;

            // Show a modal to edit.
            const modal = await Modal.create({
                title: title,
                removeOnClose: true,
                body: Templates.render('report_feedback_tracker/course/modedit_modal',
                    {
                        sesskey: sesskey,
                        courseid: courseid,
                        gradeitemid: gradeitemid,
                        partid: partid,
                        icon: icon,
                        name: name,
                        contact: contact,
                        method: method,
                        generalfeedback: generalfeedback,
                        hidden: hidden,
                        hiddendisabled: hiddendisabled,
                        assessmenttype: assessmenttype,
                        assessmenttypelabel: assessmenttypelabel,
                        locked: locked,
                        feedbackduedateraw: feedbackduedateraw,
                        formatteddate: formatteddate,
                        assessmenttypes: assessmenttypes,
                        cohortfeedback: cohortfeedback
                    }),
            });
            modal.show();

            modal.getRoot().on(ModalEvents.cancel, () => {
                modal.destroy();
            });

            modal.getRoot().on(ModalEvents.hidden, function() {
                modal.destroy();
            });
        }
    });

};
