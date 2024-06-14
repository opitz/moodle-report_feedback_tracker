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
            var gfdate = target.getAttribute('data-gfdate');

            // Show a modal with a text field, a URL field and a checkbox.
            const modal = await ModalSaveCancel.create({
                title: await getString('generalfeedback', 'report_feedback_tracker'),
                body: Templates.render('report_feedback_tracker/generalfeedback_modal',
                    {
                        generalfeedbacklabel: await getString('generalfeedback:text', 'report_feedback_tracker'),
                        generalfeedback: generalfeedback,
                        gfurllabel: await getString('generalfeedback:url', 'report_feedback_tracker'),
                        gfurl: gfurl,
                        gfdatelabel: await getString('generalfeedback:only', 'report_feedback_tracker'),
                        gfdate: gfdate
                    }),
            });
            modal.show();

            modal.getRoot().on(ModalEvents.save, async() => {
                // Get the general feedback text, URL and optional date.
                const generalfeedback = document.getElementById('generalfeedback').value;
                const gfurl = document.getElementById('gfurl').value;
                const gfdate = document.getElementById('gfdate').checked;

                // Update the database.
                const response = await updateGeneralFeedback(itemid, generalfeedback, gfurl, gfdate);

                // Update the screen elements.
                target.setAttribute('data-generalfeedback', generalfeedback);
                target.setAttribute('data-gfurl', gfurl);
                target.setAttribute('data-gfdate', gfdate);
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
