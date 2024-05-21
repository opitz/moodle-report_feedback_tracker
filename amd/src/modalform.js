//import Modal from 'core/modal';
import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import Templates from 'core/templates';
import {updateGeneralFeedback} from "./repository";

const Selectors = {
    actions: {
        showGeneralfeedback: '[data-action="report_feedback_tracker/showgeneralfeedback"]',
        generalFeedback: '[data-action="report_feedback_tracker/generalfeedback"]',
    },
};

export const init = async () => {
    window.console.log('modalform.js initialised');

    document.addEventListener('click', async e => {
        if (e.target.closest(Selectors.actions.showGeneralfeedback)) {
            const target = e.target;
            const itemid = target.getAttribute('cmid');

            // Get the current general feedback text.
            var generalfeedback = target.getAttribute('data-generalfeedback');

            // Show a modal with a free text and a URL field.
            const modal = await ModalSaveCancel.create({
                title: 'General Feedback',
                body: Templates.render('report_feedback_tracker/modal_form',
                    {
                        generalfeedbacklabel: 'Feedback text:',
                        generalfeedback: generalfeedback,
                        gfurllabel: 'Feedback URL:',
                        gfurl: 'http://www.weltsensation.com'
                    }),
//                footer: 'An example footer content',
            });
            modal.show();

            modal.getRoot().on(ModalEvents.save, async () => {
                // Get the general feedback text.
                var generalfeedback = document.getElementById('generalfeedback').value;
                // Update the database.
                const response = await updateGeneralFeedback(itemid, generalfeedback);
                // Update the screen elements.
                target.setAttribute('data-generalfeedback', generalfeedback);
                document.getElementById('generalfeedbacktext').innerHTML = generalfeedback;
                window.console.log(response);
            });

            modal.getRoot().on(ModalEvents.cancel, () => {
                window.console.log('cancelled!');
                modal.destroy();
                // ...
            });

            modal.getRoot().on(ModalEvents.hidden, function() {
                modal.destroy();
            });
        }
    });


    // ...
};
