import {call as ajax} from 'core/ajax';

export const getAssessmentTypes = (
    selection,
) => ajax([{
    methodname: 'report_feedback_tracker_get_assessment_types',
    args: {
        selection: selection
    },
}])[0];

export const getModuleData = (
    gradeitemid, partid,
) => ajax([{
    methodname: 'report_feedback_tracker_get_module_data',
    args: {
        gradeitemid: gradeitemid,
        partid: partid
    },
}])[0];

