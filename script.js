/**
 * Garment Manufacturing System
 * Main JavaScript file
 */

document.addEventListener('DOMContentLoaded', function() {
    // Handle form submissions with validation
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!validateForm(form)) {
                event.preventDefault();
            } else {
                // Log activity for form submissions if user is logged in
                if (typeof logUserActivity === 'function') {
                    const formId = form.id || 'unknown-form';
                    logUserActivity('submit', 'form', `Submitted form: ${formId}`);
                }
            }
        });
    });
    
    // Initialize date pickers for date inputs
    initializeDatePickers();
    
    // Setup activity logging for user interactions
    setupActivityLogging();
    
    // Initialize any charts if the page contains chart containers
    initializeCharts();
    
    // Handle data table sorting
    setupTableSorting();
    
    // Setup notifications for payment reminders
    checkPaymentReminders();
});
// File: assets/js/script.js

// ... (existing functions) ...

/**
 * Initializes table sorting for all tables with the 'sortable-table' class.
 *
 * This function looks for <th> elements with a 'sortable' class and attaches
 * click event listeners to them to handle client-side table sorting.
 * It also applies a visual indicator for the current sort order.
 */
function setupTableSorting() {
    console.log("Setting up table sorting...");
    document.querySelectorAll('.sortable-table th.sortable').forEach(headerCell => {
        headerCell.addEventListener('click', () => {
            const table = headerCell.closest('table');
            const columnIndex = Array.from(headerCell.parentNode.children).indexOf(headerCell);
            const isAscending = headerCell.classList.contains('asc'); // Check if currently sorted ascending

            // Remove existing sort classes from other headers in the same table
            table.querySelectorAll('th.sortable').forEach(th => {
                th.classList.remove('asc', 'desc');
            });

            // Toggle sort order
            if (isAscending) {
                headerCell.classList.add('desc');
                sortColumn(table, columnIndex, false); // Sort descending
            } else {
                headerCell.classList.add('asc');
                sortColumn(table, columnIndex, true); // Sort ascending
            }
        });
    });
}

/**
 * Sorts a table column based on the specified index and order.
 *
 * @param {HTMLTableElement} table - The table element to sort.
 * @param {number} columnIndex - The index of the column to sort by.
 * @param {boolean} ascending - True for ascending, false for descending.
 */
function sortColumn(table, columnIndex, ascending) {
    const tbody = table.querySelector('tbody');
    if (!tbody) return; // No tbody to sort

    const rows = Array.from(tbody.querySelectorAll('tr'));

    const sortedRows = rows.sort((a, b) => {
        const aColText = a.children[columnIndex].textContent.trim();
        const bColText = b.children[columnIndex].textContent.trim();

        // Basic numeric comparison, fallback to string comparison
        let comparison = 0;
        if (!isNaN(parseFloat(aColText)) && !isNaN(parseFloat(bColText))) {
            comparison = parseFloat(aColText) - parseFloat(bColText);
        } else {
            comparison = aColText.localeCompare(bColText);
        }

        return ascending ? comparison : -comparison;
    });

    // Re-append sorted rows to the tbody
    sortedRows.forEach(row => tbody.appendChild(row));
}


// Ensure setupTableSorting runs when the DOM is fully loaded
document.addEventListener('DOMContentLoaded', setupTableSorting);

// ... (rest of your script.js content) ...
/**
 * Validates a form before submission
 * @param {HTMLFormElement} form - The form to validate
 * @return {boolean} - Whether the form is valid
 */
function validateForm(form) {
    let isValid = true;
    
    // Check required fields
    const requiredFields = form.querySelectorAll('[required]');
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            isValid = false;
            highlightInvalidField(field, 'This field is required');
        } else {
            removeFieldHighlight(field);
        }
    });
    
    // Check email format
    const emailFields = form.querySelectorAll('input[type="email"]');
    emailFields.forEach(field => {
        if (field.value.trim() && !isValidEmail(field.value)) {
            isValid = false;
            highlightInvalidField(field, 'Please enter a valid email address');
        }
    });
    
    // Check numeric fields
    const numericFields = form.querySelectorAll('input[type="number"], input[data-type="numeric"]');
    numericFields.forEach(field => {
        if (field.value.trim() && !isValidNumber(field.value)) {
            isValid = false;
            highlightInvalidField(field, 'Please enter a valid number');
        }
    });
    
    return isValid;
}

/**
 * Highlights a field that has invalid input
 * @param {HTMLElement} field - The field to highlight
 * @param {string} message - Error message to display
 */
function highlightInvalidField(field, message) {
    field.classList.add('invalid-input');
    
    // Create or update error message
    let errorElement = field.nextElementSibling;
    if (!errorElement || !errorElement.classList.contains('error-message')) {
        errorElement = document.createElement('div');
        errorElement.classList.add('error-message');
        field.parentNode.insertBefore(errorElement, field.nextSibling);
    }
    
    errorElement.textContent = message;
}

/**
 * Removes highlighting from a valid field
 * @param {HTMLElement} field - The field to un-highlight
 */
function removeFieldHighlight(field) {
    field.classList.remove('invalid-input');
    
    // Remove error message if exists
    const errorElement = field.nextElementSibling;
    if (errorElement && errorElement.classList.contains('error-message')) {
        errorElement.remove();
    }
}

/**
 * Validates an email address format
 * @param {string} email - The email to validate
 * @return {boolean} - Whether the email is valid
 */
function isValidEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

/**
 * Validates if a string is a valid number
 * @param {string} value - The value to validate
 * @return {boolean} - Whether the value is a valid number
 */
function isValidNumber(value) {
    return !isNaN(parseFloat(value)) && isFinite(value);
}

/**
 * Initializes date picker functionality for date inputs
 */
function initializeDatePickers() {
    // This is a placeholder for date picker initialization
    // You would typically use a library like Flatpickr or similar
    const dateInputs = document.querySelectorAll('input[type="date"]');
    dateInputs.forEach(input => {
        // Initialize date picker (if using a library)
        // For now, we'll just ensure the input has the right type
        if (!input.type === 'date') {
            input.type = 'date';
        }
    });
}

/**
 * Sets up activity logging for user interactions
 */
function setupActivityLogging() {
    // Only setup if user is logged in (check for a user ID in the page)
    const userIdElement = document.getElementById('current-user-id');
    if (!userIdElement) return;
    
    const userId = userIdElement.value;
    
    // Log page visits
    logUserActivity('read', 'page', `Visited: ${document.title}`);
    
    // Track button clicks
    document.addEventListener('click', function(e) {
        const button = e.target.closest('button, .button, [role="button"]');
        if (button) {
            const buttonText = button.textContent.trim();
            const buttonId = button.id || '';
            logUserActivity('click', 'button', `Clicked: ${buttonText || buttonId || 'Unknown button'}`);
        }
    });
    
    // Track link clicks
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a');
        if (link && !link.classList.contains('no-log')) {
            const linkText = link.textContent.trim();
            const linkHref = link.getAttribute('href');
            logUserActivity('click', 'link', `Clicked: ${linkText || linkHref || 'Unknown link'}`);
        }
    });
}

/**
 * Logs user activity to the server
 * @param {string} actionType - Type of action (click, submit, etc.)
 * @param {string} module - Module or section
 * @param {string} description - Description of the action
 * @param {number|null} entityId - Related entity ID (optional)
 */
function logUserActivity(actionType, module, description, entityId = null) {
    // Don't log activity if we're not on a secure page or missing user ID
    const userIdElement = document.getElementById('current-user-id');
    if (!userIdElement) return;
    
    const userId = userIdElement.value;
    
    // Create the log data
    const logData = {
        user_id: userId,
        action_type: actionType,
        module: module,
        description: description,
        entity_id: entityId,
        timestamp: new Date().toISOString()
    };
    
    // Send the log data to the server
    fetch('../api/log-activity.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(logData)
    }).catch(error => {
        console.error('Error logging activity:', error);
    });
}

/**
 * Initializes charts on the page
 */
function initializeCharts() {
    // This is a placeholder for chart initialization
    // You would typically use a library like Chart.js
    const chartContainers = document.querySelectorAll('.chart-container');
    if (chartContainers.length === 0) return;
    
    // For each chart container, initialize a chart based on its data attributes
    chartContainers.forEach(container => {
        const chartType = container.dataset.chartType || 'bar';
        const chartId = container.id;
        
        // Example: If using Chart.js
        // new Chart(container.getContext('2d'), {
        //     type: chartType,
        //     data: {
        //         // Data would come from the page or an API
        //     },
        //     options: {
        //         // Chart options
        //     }
        // });
    });
}

/**
 * Sets up sorting functionality for data tables
 */
function setupTableSorting() {
    const tables = document.querySelectorAll('.data-table');
    tables.forEach(table => {
        const headers = table.querySelectorAll('th');
        headers.forEach(header => {
            if (header.classList.contains('sortable')) {
                header.addEventListener('click', function() {
                    const columnIndex = Array.from(header.parentNode.children).indexOf(header);
                    sortTable(table, columnIndex);
                });
            }
        });
    });
}

/**
 * Sorts a table by a specific column
 * @param {HTMLTableElement} table - The table to sort
 * @param {number} columnIndex - Index of the column to sort by
 */
function sortTable(table, columnIndex) {
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const header = table.querySelectorAll('th')[columnIndex];
    
    // Toggle sort direction
    const isAscending = !header.classList.contains('sort-asc');
    
    // Update header classes
    table.querySelectorAll('th').forEach(th => {
        th.classList.remove('sort-asc', 'sort-desc');
    });
    
    header.classList.add(isAscending ? 'sort-asc' : 'sort-desc');
    
    // Sort the rows
    rows.sort((rowA, rowB) => {
        const cellA = rowA.querySelectorAll('td')[columnIndex].textContent.trim();
        const cellB = rowB.querySelectorAll('td')[columnIndex].textContent.trim();
        
        // Check if we're sorting numbers
        if (!isNaN(parseFloat(cellA)) && !isNaN(parseFloat(cellB))) {
            return isAscending ? 
                parseFloat(cellA) - parseFloat(cellB) : 
                parseFloat(cellB) - parseFloat(cellA);
        }
        
        // Otherwise sort as strings
        return isAscending ? 
            cellA.localeCompare(cellB) : 
            cellB.localeCompare(cellA);
    });
    
    // Re-append rows in new order
    rows.forEach(row => tbody.appendChild(row));
}

/**
 * Checks for payment reminders and shows notifications
 */
function checkPaymentReminders() {
    const reminderContainer = document.getElementById('payment-reminders');
    if (!reminderContainer) return;
    
    // Fetch payment reminders from the server
    fetch('../api/get-payment-reminders.php')
        .then(response => response.json())
        .then(data => {
            if (data.reminders && data.reminders.length > 0) {
                displayPaymentReminders(data.reminders, reminderContainer);
            }
        })
        .catch(error => {
            console.error('Error fetching payment reminders:', error);
        });
}

/**
 * Displays payment reminders in the provided container
 * @param {Array} reminders - Array of reminder objects
 * @param {HTMLElement} container - Container to display reminders in
 */
function displayPaymentReminders(reminders, container) {
    container.innerHTML = '';
    
    const remindersList = document.createElement('ul');
    remindersList.className = 'reminders-list';
    
    reminders.forEach(reminder => {
        const reminderItem = document.createElement('li');
        reminderItem.className = 'reminder-item';
        
        reminderItem.innerHTML = `
            <div class="reminder-details">
                <strong>${reminder.customer_name}</strong>
                <div>Invoice #${reminder.invoice_number}</div>
                <div>Amount: ${formatCurrency(reminder.amount_due)}</div>
                <div>Due date: ${formatDate(reminder.due_date)}</div>
            </div>
            <div class="reminder-actions">
                <button class="button" onclick="markReminderAsContacted(${reminder.id})">
                    Mark as Contacted
                </button>
            </div>
        `;
        
        remindersList.appendChild(reminderItem);
    });
    
    container.appendChild(remindersList);
    container.style.display = 'block';
}

/**
 * Formats a number as currency
 * @param {number} amount - Amount to format
 * @return {string} - Formatted currency string
 */
function formatCurrency(amount) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD'
    }).format(amount);
}

/**
 * Formats a date string
 * @param {string} dateString - Date to format
 * @return {string} - Formatted date string
 */
function formatDate(dateString) {
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    }).format(date);
}

/**
 * Marks a payment reminder as contacted
 * @param {number} reminderId - ID of the reminder
 */
function markReminderAsContacted(reminderId) {
    fetch('../api/mark-reminder-contacted.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ reminder_id: reminderId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove the reminder from the UI
            const reminderElement = document.querySelector(`.reminder-item[data-id="${reminderId}"]`);
            if (reminderElement) {
                reminderElement.remove();
            }
            
            // If no more reminders, hide the container
            const remindersContainer = document.getElementById('payment-reminders');
            if (remindersContainer && remindersContainer.querySelectorAll('.reminder-item').length === 0) {
                remindersContainer.style.display = 'none';
            }
        }
    })
    .catch(error => {
        console.error('Error marking reminder as contacted:', error);
    });
}