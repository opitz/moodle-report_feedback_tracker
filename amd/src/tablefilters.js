export const init = () => {
    tableFilters();
    initialiseTextFilters();
};

/**
 * Filter the student table.
 */
export function tableFilters() {
    window.console.log('tablefilters.js initialised');

    const dataTable = document.getElementById('feedback_table');
    const rows = dataTable.getElementsByTagName('tr');

    const filterCourse = document.getElementById('filtercourse');
    const filterFeedback = document.getElementById('filterfeedback');
    const filterMethod = document.getElementById('filtermethod');
    const filterSummative = document.getElementById('filtersummative');
    const filterType = document.getElementById('filtertype');

    const filterTable = () => {

        const courseValue = filterCourse ? filterCourse.value : null;
        const feedbackValue = filterFeedback ? filterFeedback.value : null;
        const methodValue = filterMethod ? filterMethod.value : null;
        const summativeValue = filterSummative ? filterSummative.value : null;
        const typeValue = filterType ? filterType.value : null;

        rows.forEach(row => {
            const courseColumn = row.querySelector('.col_course');
            const feedbackColumn = row.querySelector('.col_feedback');
            const methodColumn = row.querySelector('.col_method');
            const summativeColumn = row.querySelector('.col_summative');
            const typeColumn = row.querySelector('.col_assessment');

            const match = (
                (!courseColumn || courseColumn.getAttribute('data-filter') === courseValue || !courseValue) &&
                (!feedbackColumn || feedbackColumn.getAttribute('data-filter') === feedbackValue || !feedbackValue) &&
                (!methodColumn || methodColumn.getAttribute('data-filter') === methodValue || !methodValue) &&
                (!summativeColumn || summativeColumn.getAttribute('data-filter') === summativeValue || !summativeValue) &&
                (!typeColumn || typeColumn.getAttribute('data-filter-assessment') === typeValue || !typeValue)
            );

            row.style.display = match ? '' : 'none';
        });
    };

    // Add event listeners to filter dropdowns.
    if (filterCourse) {
        filterCourse.addEventListener('change', filterTable);
    }
    if (filterFeedback) {
        filterFeedback.addEventListener('change', filterTable);
    }
    if (filterMethod) {
        filterMethod.addEventListener('change', filterTable);
    }
    if (filterSummative) {
        filterSummative.addEventListener('change', filterTable);
    }
    if (filterType) {
        filterType.addEventListener('change', filterTable);
    }

}

/**
 * Initialise the text filter.
 */
export function initialiseTextFilters() {
    const dataTable = document.getElementById('feedback_table');
    const filterResponsibilityInputs = dataTable.querySelectorAll('.filterresponsibility-input');
    const filterGeneralFeedbackInputs = dataTable.querySelectorAll('.filtergeneralfeedback-input');

    filterResponsibilityInputs.forEach(input => {
        input.addEventListener('input', function() {
            const columnIndex = input.getAttribute('data-column');
            const filterValue = input.value.toLowerCase();

            filterResponsibilityTableByColumn(dataTable, columnIndex, filterValue);
        });
    });

    filterGeneralFeedbackInputs.forEach(input => {
        input.addEventListener('input', function() {
            const columnIndex = input.getAttribute('data-column');
            const filterValue = input.value.toLowerCase();

            filterGeneralFeedbackTableByColumn(dataTable, columnIndex, filterValue);
        });
    });
}

/**
 * Filter the responsibility table column while one types.
 *
 * @param {string} dataTable
 * @param {number} columnIndex
 * @param {string} filterValue
 */
function filterResponsibilityTableByColumn(dataTable, columnIndex, filterValue) {
    const tbody = dataTable.querySelector('tbody');
    const rows = tbody.querySelectorAll('tr');

    rows.forEach(row => {
        const cell = row.querySelector('.col_responsibility');
        const cellText = cell ? cell.textContent.toLowerCase() : '';
        if (cellText.includes(filterValue)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

/**
 * Filter the general feedback table column while one types.
 *
 * @param {string} dataTable
 * @param {number} columnIndex
 * @param {string} filterValue
 */
function filterGeneralFeedbackTableByColumn(dataTable, columnIndex, filterValue) {
    const tbody = dataTable.querySelector('tbody');
    const rows = tbody.querySelectorAll('tr');

    rows.forEach(row => {
        const cell = row.querySelector('.col_generalfeedback');
        const cellText = cell ? cell.textContent.toLowerCase() : '';
        if (cellText.includes(filterValue)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

