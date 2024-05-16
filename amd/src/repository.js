import {call as summativeItem} from 'core/ajax';
import {call as hideItem} from 'core/ajax';
import {call as pickDate} from 'core/ajax';

export const updateSummativeState = (
    itemid,
    summativestate,
) => summativeItem([{
    methodname: 'report_feedback_tracker_save_summative_state',
    args: {
        itemid: itemid,
        summativestate: summativestate
    },
}])[0];

export const updateHidingState = (
    itemid,
    hidingstate,
) => hideItem([{
    methodname: 'report_feedback_tracker_save_hiding_state',
    args: {
        itemid: itemid,
        hidingstate: hidingstate
    },
}])[0];

export const updateFeedbackDuedate = (
    itemid,
    duedate,
) => pickDate([{
    methodname: 'report_feedback_tracker_save_feedback_duedate',
    args: {
        itemid: itemid,
        duedate: duedate
    },
}])[0];

export const deleteFeedbackDuedate = (
    itemid,
) => pickDate([{
    methodname: 'report_feedback_tracker_delete_feedback_duedate',
    args: {
        itemid: itemid
    },
}])[0];
