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

export const init = async() => {
    window.console.log('generalfeedback.js initialised');

    document.addEventListener('click', async e => {
        if (e.target.closest(Selectors.actions.showGeneralfeedback)) {
            const target = e.target;
            const itemid = target.getAttribute('cmid');

            // Get the current general feedback text and URL.
            var generalfeedback = target.getAttribute('data-generalfeedback');
            var gfurl = target.getAttribute('data-gfurl');

            // Show a modal with a free text and a URL field.
            const modal = await ModalSaveCancel.create({
                title: 'Additional information',
                body: Templates.render('report_feedback_tracker/generalfeedback_modal',
                    {
                        generalfeedbacklabel: 'Text:',
                        generalfeedback: generalfeedback,
                        gfurllabel: 'URL:',
                        gfurl: gfurl
                    }),
            });
            modal.show();

            modal.getRoot().on(ModalEvents.save, async() => {
                // Get the general feedback text and URL.
                var generalfeedback = document.getElementById('generalfeedback').value;
                var gfurl = document.getElementById('gfurl').value;
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


    // ...
};
