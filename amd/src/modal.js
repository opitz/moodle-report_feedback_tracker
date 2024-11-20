export const init = async() => {

    const assessmentTypeSelector = document.getElementById('assessmenttype');
    const feedbackDuedatePicker = document.getElementById('feedbackduedate');
    const hidingCheckbox = document.getElementById('hidden');
    const previousFeedbackDuedate = document.getElementById('previousfeedbackduedate');
    const reason = document.getElementById('js-reason');

    if (assessmentTypeSelector) {
        assessmentTypeSelector.addEventListener('change', async(e) => {
            const assessmentType = e.target.value;
            // TODO: Use assess_type::ASSESS_TYPE_DUMMY and assess_type::ASSESS_TYPE_SUMMATIVE.
            const assessTypeDummy = 2;

            if (assessmentType * 1 === assessTypeDummy) {
                hidingCheckbox.checked = true; // Dummy assessments are hidden from students.
                hidingCheckbox.disabled = true;
            } else {
                hidingCheckbox.disabled = false;
            }
        });
    }

    if (feedbackDuedatePicker) {
        feedbackDuedatePicker.addEventListener('change', function() {
            if (feedbackDuedatePicker.value === previousFeedbackDuedate.value || !feedbackDuedatePicker.value) {
                reason.classList.add('d-none'); // Hide the input field for a reason.
            } else {
                reason.classList.remove('d-none'); // Show an input field for a reason.
            }
        });
    }
};