
export const init = () => {
    window.console.log('studentfilter.js initialised');

    const dataTable = document.getElementById('user_feedback_table');
    const rows = dataTable.getElementsByTagName('tr');

    const filterCourse = document.getElementById('filtercourse');
    const filterFeedback = document.getElementById('filterfeedback');
    const filterMethod = document.getElementById('filtermethod');
    const filterSummative = document.getElementById('filtersummative');
    const filterType = document.getElementById('filtertype');

    const filterTable = () => {

        const courseValue = filterCourse.value;
        const feedbackValue = filterFeedback.value;
        const methodValue = filterMethod.value;
        const summativeValue = filterSummative.value;
        const typeValue = filterType.value;

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
                (!typeColumn || typeColumn.getAttribute('data-filter') === typeValue || !typeValue)
            );

            row.style.display = match ? '' : 'none';
        });
    };

    // Add event listeners to filter dropdowns.
    filterCourse.addEventListener('change', filterTable);
    filterFeedback.addEventListener('change', filterTable);
    filterMethod.addEventListener('change', filterTable);
    filterSummative.addEventListener('change', filterTable);
    filterType.addEventListener('change', filterTable);
};
