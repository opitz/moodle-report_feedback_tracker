import {call as ajax} from 'core/ajax';

export const updateModule = (
    gradeitemid,
    partid,
    contact,
    method,
    hidden,
    assesstype,
    feedbackduedate,
    generalfeedback,
) => ajax([{
    methodname: 'report_feedback_tracker_update_module',
    args: {
        gradeitemid: gradeitemid,
        partid: partid,
        contact: contact,
        method: method,
        hidden: hidden,
        assesstype: assesstype,
        feedbackduedate: feedbackduedate,
        generalfeedback: generalfeedback
    },
}])[0];

export const getAssessmentTypes = (
    selection,
) => ajax([{
    methodname: 'report_feedback_tracker_get_assessment_types',
    args: {
        selection: selection
    },
}])[0];

