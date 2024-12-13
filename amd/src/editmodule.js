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
            const assesstype = module.querySelector('[data-assesstype]').getAttribute('data-assesstype');
            const locked = module.querySelector('[data-locked]').getAttribute('data-locked') * 1;
            const assesstypelabel = module.querySelector('[data-label]').getAttribute('data-label');

            const formattedduedate = module.querySelector('[data-customfeedbackduedate]') ?
                module.querySelector('[data-customfeedbackduedate]').
                getAttribute('data-customfeedbackduedate') : null;

            const formattedreleaseddate = module.querySelector('[data-customfeedbackreleaseddate]') ?
                module.querySelector('[data-customfeedbackreleaseddate]').
                getAttribute('data-customfeedbackreleaseddate') : null;

            // Optional information.
            const contactElement = module.querySelector('[data-contact]');
            const methodElement = module.querySelector('[data-method]');
            const generalfeedbackElement = module.querySelector('[data-generalfeedback]');
            const hiddenElement = module.querySelector('[data-hiddenfromreport]');
            const reasonElement = module.querySelector('[data-feedbackduedatereason]');

            const contact = contactElement ? contactElement.getAttribute('data-contact') : null;
            const method = methodElement ? methodElement.getAttribute('data-method') : null;
            const generalfeedback = generalfeedbackElement ? generalfeedbackElement.getAttribute('data-generalfeedback') : null;
            const hidden = hiddenElement ? hiddenElement.getAttribute('data-hiddenfromreport') : null;

            // Get the assessment type options with the current selection.
            const selection = assesstype * 1; // Make sure it is an integer.
            const assesstypes = JSON.parse(await getAssessmentTypes(selection));

            // If assessment type is either dummy or summative set by SITS disable 'hide from student report' option.
            const assessTypeDummy = 2; // This is assess_type::ASSESS_TYPE_DUMMY.
            const hiddendisabled = (selection === assessTypeDummy) || locked;

            const feedbackduedatereason = reasonElement ? reasonElement.getAttribute('data-feedbackduedatereason') : null;

            const title = `${await getString('edit', 'report_feedback_tracker')} ${name}`;

            const today = new Date();
            // Format the date as YYYY-MM-DD
            const formattedtoday = today.toISOString().split('T')[0];

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
                        assesstype: assesstype,
                        assesstypelabel: assesstypelabel,
                        locked: locked,
                        formattedduedate: formattedduedate,
                        assesstypes: assesstypes,
                        formattedreleaseddate: formattedreleaseddate,
                        feedbackduedatereason: feedbackduedatereason,
                        today: formattedtoday
                    }),
            });
            await modal.show();

            modal.getRoot().on(ModalEvents.cancel, () => {
                modal.destroy();
            });

            modal.getRoot().on(ModalEvents.hidden, function() {
                modal.destroy();
            });
        }
    });

};
