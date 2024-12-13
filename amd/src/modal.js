export const init = async() => {

    const assessmentTypeSelector = document.getElementById('assesstype');
    const hidingCheckbox = document.getElementById('hidden');
    const customFeedbackDuedateCheckbox = document.getElementById('customfeedbackduedatecheckbox');
    const feedbackduedateform = document.getElementById('js-feedbackduedate');
    const customFeedbackReleaseddateCheckbox = document.getElementById('customfeedbackreleaseddatecheckbox');
    const feedbackreleaseddateform = document.getElementById('js-feedbackreleaseddate');

    if (assessmentTypeSelector) {
        assessmentTypeSelector.addEventListener('change', async(e) => {
            const assessmentType = e.target.value;
            const assessTypeDummy = 2; // This is assess_type::ASSESS_TYPE_DUMMY.

            if (parseInt(assessmentType, 10) === assessTypeDummy) {
                hidingCheckbox.checked = true; // Dummy assessments are hidden from the student report.
                hidingCheckbox.disabled = true; // Changing hiding state is disabled.
            } else {
                hidingCheckbox.checked = false; // Other assessments are shown to students by default.
                hidingCheckbox.disabled = false; // Changing hiding state is enabled.
            }
        });
    }

    customFeedbackDuedateCheckbox.addEventListener('change', function() {
        const reasonField = document.getElementById('reason');
        const feedbackduedatepicker = document.getElementById('feedbackduedate');
        if (customFeedbackDuedateCheckbox.checked === true) {
            feedbackduedateform.classList.remove('d-none'); // Show feedback due date input fields.
            reasonField.required = true; // Reason is a required field.
            feedbackduedatepicker.required = true; // Date is required.
        } else {
            feedbackduedateform.classList.add('d-none'); // Hide feedback due date input fields.
            reasonField.required = false; // Reason is no longer a required field.
            feedbackduedatepicker.required = false; // Date is no longer required.
        }
    });

    customFeedbackReleaseddateCheckbox.addEventListener('change', function() {
        const feedbackreleaseddatepicker = document.getElementById('feedbackreleaseddate');
        if (customFeedbackReleaseddateCheckbox.checked === true) {
            feedbackreleaseddateform.classList.remove('d-none'); // Show feedback released date input fields.
            feedbackreleaseddatepicker.required = true; // Date is required.
        } else {
            feedbackreleaseddateform.classList.add('d-none'); // Hide feedback released date input fields.
            feedbackreleaseddatepicker.required = false; // Date is no longer required.
        }
    });
};