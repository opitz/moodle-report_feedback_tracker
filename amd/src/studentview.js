import {renderStudentFeedback} from './repository';
import {initialiseTextFilters, tableFilters} from './tablefilters';
import {tableSort} from './tablesort';

export const init = () => {
    window.console.log('studentview.js initialised');

    document.getElementById('report_feedback_tracker_studentdd').addEventListener('change', async function() {
        var courseId = document.getElementById('feedback_tracker_studentdd').getAttribute('data-value');
        var studentId = this.value;
        if (studentId) {
            // Render the feedback table for the user using AJAX and push the result to the page.
            document.getElementById('feedbacktable').innerHTML = await renderStudentFeedback(studentId, courseId);

            // Re-initialise the student table filter and sorting.
            tableFilters();
            tableSort();
            initialiseTextFilters();
        }
    });

};
