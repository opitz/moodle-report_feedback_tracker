export const init = () => {
    selectCoursesByAcademicYear();
};

/**
 * Filter the student table.
 */
export function selectCoursesByAcademicYear() {
    window.console.log('academicyears.js initialised');

    document.addEventListener('click', async e => {
        if (e.target.closest('[data-action="select-ay"]')) {
            const target = e.target;
            const filterAcademicYear = target.getAttribute('data-value');
            filterCoursesByAcademicYear(filterAcademicYear);
        }
    });

}

/**
 * Filter the coruses by Academic Year.
 * @param {string} filterAcademicYear
 */
function filterCoursesByAcademicYear(filterAcademicYear) {
    window.console.log("filtering by: " + filterAcademicYear);
    const dataArea = document.getElementById('courses_area');
    const courses = dataArea.getElementsByClassName('course_row');

    window.console.log(courses);

    const getFilterValues = () => ({
        academicyearValue: filterAcademicYear ? filterAcademicYear : null
    });

    const rowMatchesFilters = (courses, filterValues) => {
        const academicYear = courses.querySelector('[data-action="select-ay"]');

        return (
            (!academicYear || academicYear.getAttribute('data-ay') ===
                filterValues.academicyearValue || !filterValues.academicyearValue));
    };

    const filterCourses = () => {
        const filterValues = getFilterValues();
        Array.from(courses).forEach(course => {
            course.style.display = rowMatchesFilters(course, filterValues) ? '' : 'none';
        });
    };

    filterCourses();
}