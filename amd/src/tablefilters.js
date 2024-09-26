export const init = () => {
    tableFilters();
    initialiseTextFilters();
};

/**
 * Filter the student table.
 */
export function tableFilters() {
    window.console.log('tablefilters.js initialised');

    const dataTable = document.getElementById('feedback-table');
    const rows = dataTable ? dataTable.getElementsByTagName('tr') : [];

    const filterAcademicYear = document.getElementById('filteracademicyear');
    const filterCourse = document.getElementById('filtercourse');
    const filterType = document.getElementById('filtertype');
    const filterFeedback = document.getElementById('filterfeedback');
    const filterMethod = document.getElementById('filtermethod');
    const filterSummative = document.getElementById('filtersummative');

    const originalCourseOptions = filterCourse ? Array.from(filterCourse.options) : null;

    const getFilterValues = () => ({
        academicyearValue: filterAcademicYear ? filterAcademicYear.value : null,
        courseValue: filterCourse ? filterCourse.value : null,
        typeValue: filterType ? filterType.value : null,
        feedbackValue: filterFeedback ? filterFeedback.value : null,
        methodValue: filterMethod ? filterMethod.value : null,
        summativeValue: filterSummative ? filterSummative.value : null
    });

    const rowMatchesFilters = (row, filterValues) => {
        const academicyearColumn = row.querySelector('.col-academicyear');
        const courseColumn = row.querySelector('.col-course');
        const typeColumn = row.querySelector('.col-assessment');
        const feedbackColumn = row.querySelector('.col-feedback');
        const methodColumn = row.querySelector('.col-method');
        const summativeColumn = row.querySelector('.col-summative');

        return (
            (!academicyearColumn || academicyearColumn.getAttribute('data-filter-academicyear') ===
                filterValues.academicyearValue || !filterValues.academicyearValue) &&
            (!courseColumn || courseColumn.getAttribute('data-filter') ===
                filterValues.courseValue || !filterValues.courseValue) &&
            (!typeColumn || typeColumn.getAttribute('data-filter-assessment') ===
                filterValues.typeValue || !filterValues.typeValue) &&
            (!feedbackColumn || feedbackColumn.getAttribute('data-filter') ===
                filterValues.feedbackValue || !filterValues.feedbackValue) &&
            (!methodColumn || methodColumn.getAttribute('data-filter') ===
                filterValues.methodValue || !filterValues.methodValue) &&
            (!summativeColumn || summativeColumn.getAttribute('data-filter') ===
                filterValues.summativeValue || !filterValues.summativeValue)
        );
    };

    const filterTable = () => {
        const filterValues = getFilterValues();
        Array.from(rows).forEach(row => {
            row.style.display = rowMatchesFilters(row, filterValues) ? '' : 'none';
        });
    };

    // Update course filter options to only show courses of a filtered academic year if any.
    const updateCourseOptions = () => {
        const selectedAcademicYear = filterAcademicYear.value;
        filterCourse.innerHTML = '';

        const filteredOptions = originalCourseOptions.filter(option => {
            const filterData = option.getAttribute('data-filter-academicyear');
            return !selectedAcademicYear || !filterData || filterData === selectedAcademicYear;
        });

        filteredOptions.forEach(option => {
            filterCourse.appendChild(option);
        });
    };

    // Add event listeners to filter dropdowns.
    [filterAcademicYear, filterCourse, filterType, filterFeedback, filterMethod, filterSummative].forEach(filter => {
        if (filter) {
            filter.addEventListener('change', filterTable);
        }
    });

    if (filterAcademicYear) {
        filterAcademicYear.addEventListener('change', () => {
            updateCourseOptions();
            filterTable();
        });
    }
}
/**
 * Initialise the text filter.
 */
export function initialiseTextFilters() {
    const dataTable = document.getElementById('feedback-table');
    const filterResponsibilityInputs = dataTable ? dataTable.querySelectorAll('.filterresponsibility-input') : [];
    const filterGeneralFeedbackInputs = dataTable ? dataTable.querySelectorAll('.filtergeneralfeedback-input') : [];

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
        const cell = row.querySelector('.col-responsibility');
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
        const cell = row.querySelector('.col-generalfeedback');
        const cellText = cell ? cell.textContent.toLowerCase() : '';
        if (cellText.includes(filterValue)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

