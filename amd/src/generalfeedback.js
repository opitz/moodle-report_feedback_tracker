import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import Templates from 'core/templates';
import {updateGeneralFeedback} from "./repository";
import {getString} from 'core/str';

const Selectors = {
    actions: {
        showGeneralfeedback: '[data-action="report_feedback_tracker/showgeneralfeedback"]',
        generalFeedback: '[data-action="report_feedback_tracker/generalfeedback"]',
    },
};

export const init = async() => {
    window.console.log('generalfeedback.js initialised');

    document.addEventListener('click', async e => {
        if (e.target.closest(Selectors.actions.showGeneralfeedback)) {
            const target = e.target;
            const itemid = target.getAttribute('cmid');

            // Get the current values for general feedback text, URL and date.
            var generalfeedback = target.getAttribute('data-generalfeedback');
            var gfurl = target.getAttribute('data-gfurl');

            // Show a modal with a text field and a URL field.
            const modal = await ModalSaveCancel.create({
                title: await getString('generalfeedback', 'report_feedback_tracker'),
                body: Templates.render('report_feedback_tracker/generalfeedback_modal',
                    {
                        generalfeedbacklabel: await getString('generalfeedback:text', 'report_feedback_tracker'),
                        generalfeedback: generalfeedback,
                        gfurllabel: await getString('generalfeedback:url', 'report_feedback_tracker'),
                        gfurl: gfurl
                    }),
            });
            modal.show();

            modal.getRoot().on(ModalEvents.save, async() => {
                // Get the general feedback text, URL and optional date.
                const generalfeedback = document.getElementById('generalfeedback').value;
                const gfurl = document.getElementById('gfurl').value;

                // Update the database.
                const response = await updateGeneralFeedback(itemid, generalfeedback, gfurl);

                // Update the screen elements.
                target.setAttribute('data-generalfeedback', generalfeedback);
                target.setAttribute('data-gfurl', gfurl);
                document.getElementById('generalfeedbacktext_' + itemid).innerHTML = generalfeedback;

                window.console.log(response);
            });

            modal.getRoot().on(ModalEvents.cancel, () => {
                window.console.log('cancelled!');
                modal.destroy();
            });

            modal.getRoot().on(ModalEvents.hidden, function() {
                modal.destroy();
            });
        }
    });

};
