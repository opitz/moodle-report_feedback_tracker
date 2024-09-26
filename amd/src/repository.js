import {call as ajax} from 'core/ajax';

export const updateAssessmentType = (
    itemid,
    partname,
    assessmenttype,
) => ajax([{
    methodname: 'report_feedback_tracker_save_assessment_type',
    args: {
        itemid: itemid,
        partname: partname,
        assessmenttype: assessmenttype
    },
}])[0];

export const updateCohortState = (
    itemid,
    partname,
    cohortstate,
) => ajax([{
    methodname: 'report_feedback_tracker_save_cohort_state',
    args: {
        itemid: itemid,
        partname: partname,
        cohortstate: cohortstate
    },
}])[0];

export const updateHidingState = (
    itemid,
    partname,
    hidingstate,
) => ajax([{
    methodname: 'report_feedback_tracker_save_hiding_state',
    args: {
        itemid: itemid,
        partname: partname,
        hidingstate: hidingstate
    },
}])[0];

export const updateFeedbackDuedate = (
    itemid,
    partname,
    duedate,
    duedatereason,
) => ajax([{
    methodname: 'report_feedback_tracker_save_feedback_duedate',
    args: {
        itemid: itemid,
        partname: partname,
        duedate: duedate,
        duedatereason: duedatereason
    },
}])[0];

export const deleteFeedbackDuedate = (
    itemid,
    partname,
) => ajax([{
    methodname: 'report_feedback_tracker_delete_feedback_duedate',
    args: {
        itemid: itemid,
        partname: partname
    },
}])[0];

export const updateGeneralFeedback = (
    itemid,
    partname,
    generalfeedback,
    gfurl,
) => ajax([{
    methodname: 'report_feedback_tracker_update_general_feedback',
    args: {
        itemid: itemid,
        partname: partname,
        generalfeedback: generalfeedback,
        gfurl: gfurl
    },
}])[0];

export const renderStudentFeedback = (
    studentid,
    courseid,
) => ajax([{
    methodname: 'report_feedback_tracker_render_student_feedback',
    args: {
        studentid: studentid,
        courseid: courseid
    },
}])[0];
