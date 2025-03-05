import {call as ajax} from 'core/ajax';

export const getAssessmentTypes = (
    selection,
) => ajax([{
    methodname: 'report_feedback_tracker_get_assessment_types',
    args: {
        selection: selection
    },
}])[0];

