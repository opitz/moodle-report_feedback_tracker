export const init = () => {
    tableSort();
};

/**
 * Sort the student table.
 */
export function tableSort() {
    window.console.log('tablesort.js initialised');

    const dataTable = document.getElementById('feedback_table');
    const headers = dataTable.querySelectorAll('th');
    const directions = Array.from(headers).map(() => 1); // Initial sort directions
    const indicators = dataTable.querySelectorAll('.indicator');

    headers.forEach((header, index) => {
        header.addEventListener('click', function() {
            const direction = directions[index];
            sortTableByColumn(dataTable, index, direction);
            directions[index] = -direction; // Toggle sort direction
            updateIndicators(index, direction);
        });
    });

    /**
     * @param {object} table
     * @param {number} columnIndex
     * @param {number} direction
     */
    function sortTableByColumn(table, columnIndex, direction) {
        const tbody = table.querySelector('tbody');
        const rowsArray = Array.from(tbody.querySelectorAll('tr'));

        const sortedRows = rowsArray.sort((rowA, rowB) => {
            const cellA = rowA.querySelectorAll('td')[columnIndex].textContent.trim();
            const cellB = rowB.querySelectorAll('td')[columnIndex].textContent.trim();

            return cellA.localeCompare(cellB, undefined, {numeric: true}) * direction;
        });

        // Remove existing rows
        while (tbody.firstChild) {
            tbody.removeChild(tbody.firstChild);
        }

        // Append sorted rows
        tbody.append(...sortedRows);
    }

    /**
     * @param {number} activeIndex
     * @param {number} direction
     */
    function updateIndicators(activeIndex, direction) {
        indicators.forEach((indicator, index) => {
            if (index === activeIndex) {
                indicator.textContent = direction === 1 ? '▲' : '▼';
            } else {
                indicator.textContent = '';
            }
        });
    }
}
