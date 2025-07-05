// File: assets/js/utils.js

/**
 * Displays a toast notification.
 * This function expects a #toast-container element in your HTML (e.g., in footer.php or header.php).
 *
 * @param {string} type - 'success', 'error', 'info', or 'warning'.
 * @param {string} message - The message to display.
 * @param {number} duration - Duration in milliseconds (default: 3000).
 */
function showToast(type, message, duration = 5000) {
    const toastContainer = document.getElementById('toast-container');
    if (!toastContainer) {
        console.error('Toast container not found. Please add <div id="toast-container"></div> to your HTML.');
        // Fallback to alert if container not found
        alert(type.toUpperCase() + ': ' + message);
        return;
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type} show`; // 'show' class for animation
    toast.innerHTML = `
        <div class="toast-icon"></div>
        <div class="toast-content">
            <div class="toast-message">${message}</div>
        </div>
        <button class="toast-close-btn">&times;</button>
    `;

    // Add icon based on type (Font Awesome)
    const iconDiv = toast.querySelector('.toast-icon');
    if (type === 'success') {
        iconDiv.innerHTML = '<i class="fas fa-check-circle"></i>';
    } else if (type === 'error') {
        iconDiv.innerHTML = '<i class="fas fa-times-circle"></i>';
    } else if (type === 'info') {
        iconDiv.innerHTML = '<i class="fas fa-info-circle"></i>';
    } else if (type === 'warning') {
        iconDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
    }

    // Append to container
    toastContainer.appendChild(toast);

    // Auto-hide after duration
    setTimeout(() => {
        toast.classList.remove('show');
        toast.classList.add('hide'); // For fade-out animation
        toast.addEventListener('transitionend', () => toast.remove(), { once: true }); // Remove after animation
    }, duration);

    // Close button functionality
    toast.querySelector('.toast-close-btn').addEventListener('click', () => {
        toast.classList.remove('show');
        toast.classList.add('hide');
        toast.addEventListener('transitionend', () => toast.remove(), { once: true });
    });
}

/**
 * Displays a global loading indicator overlay.
 * This function expects a #loading-overlay element in your HTML (e.g., in footer.php).
 */
function showLoading() {
    let loadingOverlay = document.getElementById('loading-overlay');
    if (!loadingOverlay) {
        loadingOverlay = document.createElement('div');
        loadingOverlay.id = 'loading-overlay';
        loadingOverlay.innerHTML = '<div class="spinner-border" role="status"></div><div class="loading-text">Loading...</div>';
        document.body.appendChild(loadingOverlay);
    }
    loadingOverlay.style.display = 'flex'; // Use flex for centering
    loadingOverlay.classList.add('show-loading'); // For animation
}

/**
 * Hides the global loading indicator overlay.
 */
function hideLoading() {
    const loadingOverlay = document.getElementById('loading-overlay');
    if (loadingOverlay) {
        loadingOverlay.classList.remove('show-loading');
        loadingOverlay.classList.add('hide-loading'); // For fade-out animation
        loadingOverlay.addEventListener('transitionend', () => {
            if (loadingOverlay.classList.contains('hide-loading')) {
                loadingOverlay.style.display = 'none';
                loadingOverlay.classList.remove('hide-loading');
            }
        }, { once: true });
    }
}

// --- Common Utility Functions (keep or move as needed) ---

/**
 * Formats a number as currency.
 * @param {number} amount
 * @param {string} currencySymbol
 * @returns {string}
 */
function formatCurrency(amount, currencySymbol = 'Rs. ') {
    if (isNaN(amount) || amount === null) {
        return currencySymbol + '0.00';
    }
    return currencySymbol + parseFloat(amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/**
 * Formats a date string (YYYY-MM-DD) to a more readable format.
 * @param {string} dateString
 * @returns {string}
 */
function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const options = { year: 'numeric', month: 'short', day: 'numeric' };
    try {
        return new Date(dateString).toLocaleDateString(undefined, options);
    } catch (e) {
        return dateString; // Return original if invalid date
    }
}

/**
 * Placeholder for activity logging via AJAX.
 * This function makes a call to log-activity.php.
 * Ensure current user ID is available (e.g., in a hidden input on the page).
 * @param {string} action_type
 * @param {string} module
 * @param {string} description
 * @param {number|null} entity_id
 */
function logUserActivity(action_type, module, description, entity_id = null) {
    const currentUserIdElement = document.getElementById('current-user-id');
    const userId = currentUserIdElement ? parseInt(currentUserIdElement.value) : null;

    fetch('../api/log-activity.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            user_id: userId,
            action_type: action_type,
            module: module,
            description: description,
            entity_id: entity_id
        }),
    })
    .then(response => response.json())
    .then(data => {
        // console.log('Activity logged:', data);
    })
    .catch((error) => {
        console.error('Error logging activity:', error);
    });
}

/**
 * Sets up table sorting for tables with class 'sortable-table'.
 * Assumes th elements with 'sortable' class.
 * (Copied from script.js and refined for common use)
 */
function setupTableSorting() {
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
 * Helper function to sort table rows.
 * @param {HTMLTableElement} table - The table element to sort.
 * @param {number} columnIndex - The index of the column to sort by.
 * @param {boolean} ascending - True for ascending, false for descending.
 */
function sortColumn(table, columnIndex, ascending) {
    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    const rows = Array.from(tbody.querySelectorAll('tr'));

    const sortedRows = rows.sort((a, b) => {
        const aColText = a.children[columnIndex].textContent.trim();
        const bColText = b.children[columnIndex].textContent.trim();

        // Attempt numeric comparison, fallback to string comparison
        let comparison = 0;
        const aNum = parseFloat(aColText.replace(/[^0-9.-]/g, '')); // Remove non-numeric chars for robust parsing
        const bNum = parseFloat(bColText.replace(/[^0-9.-]/g, ''));

        if (!isNaN(aNum) && !isNaN(bNum) && aColText.match(/^-?\d+(\.\d+)?(,\d+)*$/) && bColText.match(/^-?\d+(\.\d+)?(,\d+)*$/)) {
            // Only compare as numbers if they genuinely look like numbers (with optional thousands comma)
            comparison = aNum - bNum;
        } else {
            comparison = aColText.localeCompare(bColText);
        }

        return ascending ? comparison : -comparison;
    });

    // Re-append sorted rows to the tbody
    sortedRows.forEach(row => tbody.appendChild(row));
}


// --- DOMContentLoaded listener to initialize universal functions ---
document.addEventListener('DOMContentLoaded', function() {
    setupTableSorting(); // Initialize table sorting on load
    // Any other global initializations
});

// Add these helper functions for form validation to utils.js
// so they can be reused across all forms
/**
 * Displays a validation error message below the input element.
 * @param {HTMLElement} element - The input element to show error for.
 * @param {string} message - The error message.
 */
function showValidationError(element, message) {
    // Find the closest .form-group or parent container for error placement
    const formGroup = element.closest('.form-group') || element.parentElement;
    const existingError = formGroup ? formGroup.querySelector('.validation-error') : null;
    if (existingError) {
        existingError.remove();
    }

    element.classList.add('invalid-input');

    const errorElement = document.createElement('div');
    errorElement.className = 'validation-error';
    errorElement.textContent = message;

    if (formGroup) {
        formGroup.appendChild(errorElement);
    } else {
        element.parentElement.appendChild(errorElement); // Fallback
    }
    element.focus();
}

/**
 * Removes a validation error message and styling from an input element.
 * @param {HTMLElement} element - The input element to remove error from.
 */
function removeValidationError(element) {
    element.classList.remove('invalid-input');
    const formGroup = element.closest('.form-group') || element.parentElement;
    const errorElement = formGroup ? formGroup.querySelector('.validation-error') : null;
    if (errorElement) {
        errorElement.remove();
    }
}