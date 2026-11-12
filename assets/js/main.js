/**
 * DDU Attendance System - Main JavaScript File
 */

// DOM Ready Function
document.addEventListener('DOMContentLoaded', function() {
    initializeComponents();
    setupEventListeners();
    checkNotifications();
});

/**
 * Initialize all components
 */
function initializeComponents() {
    // Initialize tooltips
    initTooltips();
    
    // Initialize popovers
    initPopovers();
    
    // Initialize form validation
    initFormValidation();
    
    // Initialize date pickers
    initDatePickers();
    
    // Initialize data tables
    initDataTables();
    
    // Initialize select2
    initSelect2();
    
    // Initialize charts
    initCharts();
    
    // Set up auto-logout timer
    setupAutoLogout();
}

/**
 * Initialize Bootstrap tooltips
 */
function initTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

/**
 * Initialize Bootstrap popovers
 */
function initPopovers() {
    const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });
}

/**
 * Initialize form validation
 */
function initFormValidation() {
    // Enable Bootstrap validation
    const forms = document.querySelectorAll('.needs-validation');
    
    Array.from(forms).forEach(function(form) {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            
            form.classList.add('was-validated');
        }, false);
    });
    
    // Password strength checker
    const passwordInputs = document.querySelectorAll('input[type="password"]');
    passwordInputs.forEach(input => {
        input.addEventListener('input', function() {
            checkPasswordStrength(this.value);
        });
    });
}

/**
 * Check password strength
 */
function checkPasswordStrength(password) {
    const strengthMeter = document.getElementById('password-strength');
    if (!strengthMeter) return;
    
    let strength = 0;
    let message = '';
    let color = 'danger';
    
    // Check password criteria
    if (password.length >= 8) strength++;
    if (/[A-Z]/.test(password)) strength++;
    if (/[a-z]/.test(password)) strength++;
    if (/[0-9]/.test(password)) strength++;
    if (/[^A-Za-z0-9]/.test(password)) strength++;
    
    // Determine strength level
    switch(strength) {
        case 0:
        case 1:
            message = 'Very Weak';
            color = 'danger';
            break;
        case 2:
            message = 'Weak';
            color = 'warning';
            break;
        case 3:
            message = 'Medium';
            color = 'info';
            break;
        case 4:
            message = 'Strong';
            color = 'primary';
            break;
        case 5:
            message = 'Very Strong';
            color = 'success';
            break;
    }
    
    // Update UI
    strengthMeter.textContent = message;
    strengthMeter.className = `badge bg-${color}`;
}

/**
 * Initialize date pickers
 */
function initDatePickers() {
    const dateInputs = document.querySelectorAll('input[type="date"]');
    dateInputs.forEach(input => {
        // Set max date to today for attendance dates
        if (input.name.includes('date') && !input.hasAttribute('data-future')) {
            input.max = new Date().toISOString().split('T')[0];
        }
        
        // Set min date to today for future dates
        if (input.hasAttribute('data-future')) {
            input.min = new Date().toISOString().split('T')[0];
        }
    });
}

/**
 * Initialize DataTables
 */
function initDataTables() {
    const tables = document.querySelectorAll('.data-table');
    
    tables.forEach(table => {
        if ($.fn.DataTable.isDataTable(table)) {
            return;
        }
        
        $(table).DataTable({
            pageLength: 25,
            responsive: true,
            dom: '<"top"lf>rt<"bottom"ip><"clear">',
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search...",
                lengthMenu: "_MENU_ records per page",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                infoEmpty: "No entries found",
                infoFiltered: "(filtered from _MAX_ total entries)",
                zeroRecords: "No matching records found",
                paginate: {
                    first: "First",
                    last: "Last",
                    next: "Next",
                    previous: "Previous"
                }
            }
        });
    });
}

/**
 * Initialize Select2
 */
function initSelect2() {
    const selects = document.querySelectorAll('.select2');
    
    selects.forEach(select => {
        $(select).select2({
            placeholder: "Select...",
            allowClear: true,
            width: '100%'
        });
    });
}

/**
 * Initialize charts
 */
function initCharts() {
    const chartElements = document.querySelectorAll('.chart-container');
    
    chartElements.forEach(container => {
        const canvas = container.querySelector('canvas');
        if (!canvas) return;
        
        const ctx = canvas.getContext('2d');
        const type = canvas.getAttribute('data-type') || 'bar';
        const data = JSON.parse(canvas.getAttribute('data-data') || '{}');
        const options = JSON.parse(canvas.getAttribute('data-options') || '{}');
        
        new Chart(ctx, {
            type: type,
            data: data,
            options: options
        });
    });
}

/**
 * Setup event listeners
 */
function setupEventListeners() {
    // Confirm before delete
    setupDeleteConfirmations();
    
    // Copy to clipboard
    setupClipboard();
    
    // Print functionality
    setupPrintButtons();
    
    // Export functionality
    setupExportButtons();
    
    // Toggle password visibility
    setupPasswordToggles();
    
    // Load more functionality
    setupLoadMore();
    
    // Search functionality
    setupSearch();
    
    // Modal handlers
    setupModalHandlers();
    
    // Attendance marking
    setupAttendanceMarking();
    
    // QR Code scanning
    setupQRCodeScanner();
}

/**
 * Setup delete confirmations
 */
function setupDeleteConfirmations() {
    const deleteButtons = document.querySelectorAll('.btn-delete, [data-action="delete"]');
    
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            const message = this.getAttribute('data-message') || 'Are you sure you want to delete this item?';
            
            if (!confirm(message)) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    });
}

/**
 * Setup clipboard copying
 */
function setupClipboard() {
    const copyButtons = document.querySelectorAll('[data-copy]');
    
    copyButtons.forEach(button => {
        button.addEventListener('click', function() {
            const targetId = this.getAttribute('data-copy');
            const targetElement = document.getElementById(targetId);
            
            if (targetElement) {
                const text = targetElement.textContent || targetElement.value;
                copyToClipboard(text);
                
                // Show success feedback
                const originalText = this.innerHTML;
                this.innerHTML = '<i class="fas fa-check"></i> Copied!';
                
                setTimeout(() => {
                    this.innerHTML = originalText;
                }, 2000);
            }
        });
    });
}

/**
 * Copy text to clipboard
 */
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        console.log('Text copied to clipboard');
    }).catch(err => {
        console.error('Failed to copy text: ', err);
    });
}

/**
 * Setup print buttons
 */
function setupPrintButtons() {
    const printButtons = document.querySelectorAll('.btn-print, [data-action="print"]');
    
    printButtons.forEach(button => {
        button.addEventListener('click', function() {
            window.print();
        });
    });
}

/**
 * Setup export buttons
 */
function setupExportButtons() {
    const exportButtons = document.querySelectorAll('[data-export]');
    
    exportButtons.forEach(button => {
        button.addEventListener('click', function() {
            const format = this.getAttribute('data-export');
            const tableId = this.getAttribute('data-table');
            
            if (tableId) {
                exportTableToFormat(tableId, format);
            }
        });
    });
}

/**
 * Export table to different formats
 */
function exportTableToFormat(tableId, format) {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    switch(format) {
        case 'excel':
            exportToExcel(table);
            break;
        case 'csv':
            exportToCSV(table);
            break;
        case 'pdf':
            exportToPDF(table);
            break;
    }
}

/**
 * Export to Excel
 */
function exportToExcel(table) {
    const html = table.outerHTML;
    const blob = new Blob([html], {type: 'application/vnd.ms-excel'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    
    a.href = url;
    a.download = 'export-' + new Date().toISOString().split('T')[0] + '.xls';
    a.click();
    
    URL.revokeObjectURL(url);
}

/**
 * Export to CSV
 */
function exportToCSV(table) {
    const rows = table.querySelectorAll('tr');
    const csv = [];
    
    rows.forEach(row => {
        const rowData = [];
        const cols = row.querySelectorAll('td, th');
        
        cols.forEach(col => {
            let text = col.textContent.trim();
            text = text.replace(/"/g, '""');
            rowData.push(`"${text}"`);
        });
        
        csv.push(rowData.join(','));
    });
    
    const csvString = csv.join('\n');
    const blob = new Blob([csvString], {type: 'text/csv'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    
    a.href = url;
    a.download = 'export-' + new Date().toISOString().split('T')[0] + '.csv';
    a.click();
    
    URL.revokeObjectURL(url);
}

/**
 * Export to PDF (requires jsPDF)
 */
function exportToPDF(table) {
    alert('PDF export requires jsPDF library. Please install it or use server-side PDF generation.');
}

/**
 * Setup password visibility toggles
 */
function setupPasswordToggles() {
    const toggleButtons = document.querySelectorAll('.toggle-password');
    
    toggleButtons.forEach(button => {
        button.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const targetInput = document.getElementById(targetId);
            
            if (targetInput) {
                const type = targetInput.getAttribute('type') === 'password' ? 'text' : 'password';
                targetInput.setAttribute('type', type);
                
                // Toggle icon
                const icon = this.querySelector('i');
                if (type === 'text') {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            }
        });
    });
}

/**
 * Setup load more functionality
 */
function setupLoadMore() {
    const loadMoreButtons = document.querySelectorAll('.load-more');
    
    loadMoreButtons.forEach(button => {
        button.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const targetElement = document.getElementById(targetId);
            const url = this.getAttribute('data-url');
            const page = parseInt(this.getAttribute('data-page') || '1') + 1;
            
            if (targetElement && url) {
                loadMoreContent(targetElement, url, page, this);
            }
        });
    });
}

/**
 * Load more content via AJAX
 */
async function loadMoreContent(target, url, page, button) {
    try {
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
        
        const response = await fetch(url + '?page=' + page);
        const html = await response.text();
        
        target.innerHTML += html;
        button.setAttribute('data-page', page);
        button.disabled = false;
        button.innerHTML = 'Load More';
        
        // Hide button if no more content
        if (html.trim() === '') {
            button.style.display = 'none';
        }
    } catch (error) {
        console.error('Error loading more content:', error);
        button.disabled = false;
        button.innerHTML = 'Load More';
    }
}

/**
 * Setup search functionality
 */
function setupSearch() {
    const searchInputs = document.querySelectorAll('.search-input');
    
    searchInputs.forEach(input => {
        input.addEventListener('input', debounce(function() {
            const searchTerm = this.value.toLowerCase();
            const targetId = this.getAttribute('data-target');
            const targetElement = document.getElementById(targetId);
            
            if (targetElement) {
                const items = targetElement.querySelectorAll('.search-item');
                
                items.forEach(item => {
                    const text = item.textContent.toLowerCase();
                    item.style.display = text.includes(searchTerm) ? '' : 'none';
                });
            }
        }, 300));
    });
}

/**
 * Debounce function
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Setup modal handlers
 */
function setupModalHandlers() {
    // Auto-focus first input in modal
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        modal.addEventListener('shown.bs.modal', function() {
            const input = this.querySelector('input:not([type="hidden"]):not([disabled])');
            if (input) input.focus();
        });
    });
    
    // Clear form on modal hide
    modals.forEach(modal => {
        modal.addEventListener('hidden.bs.modal', function() {
            const form = this.querySelector('form');
            if (form) form.reset();
        });
    });
}

/**
 * Setup attendance marking
 */
function setupAttendanceMarking() {
    const attendanceToggles = document.querySelectorAll('.attendance-toggle');
    
    attendanceToggles.forEach(toggle => {
        toggle.addEventListener('change', function() {
            const studentId = this.getAttribute('data-student-id');
            const status = this.checked ? 'present' : 'absent';
            
            // Update UI
            const row = this.closest('tr');
            if (row) {
                row.classList.toggle('table-success', status === 'present');
                row.classList.toggle('table-danger', status === 'absent');
            }
            
            // Send update via AJAX if needed
            if (this.hasAttribute('data-ajax')) {
                updateAttendance(studentId, status);
            }
        });
    });
    
    // Mark all buttons
    const markAllButtons = document.querySelectorAll('[data-mark-all]');
    markAllButtons.forEach(button => {
        button.addEventListener('click', function() {
            const status = this.getAttribute('data-mark-all');
            const containerId = this.getAttribute('data-target');
            const container = document.getElementById(containerId);
            
            if (container) {
                const toggles = container.querySelectorAll('.attendance-toggle');
                toggles.forEach(toggle => {
                    toggle.checked = (status === 'present');
                    toggle.dispatchEvent(new Event('change'));
                });
            }
        });
    });
}

/**
 * Update attendance via AJAX
 */
async function updateAttendance(studentId, status) {
    try {
        const response = await fetch('api/attendance.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                student_id: studentId,
                status: status,
                course_id: document.getElementById('course_id')?.value,
                date: document.getElementById('attendance_date')?.value
            })
        });
        
        const result = await response.json();
        
        if (!result.success) {
            alert('Error updating attendance: ' + result.message);
        }
    } catch (error) {
        console.error('Error updating attendance:', error);
    }
}

/**
 * Setup QR code scanner
 */
function setupQRCodeScanner() {
    const qrScannerButtons = document.querySelectorAll('.btn-scan-qr');
    
    qrScannerButtons.forEach(button => {
        button.addEventListener('click', function() {
            startQRCodeScanner();
        });
    });
}

/**
 * Start QR code scanner
 */
function startQRCodeScanner() {
    if (!('BarcodeDetector' in window)) {
        alert('QR code scanning is not supported in your browser. Please use a modern browser.');
        return;
    }
    
    const video = document.createElement('video');
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    
    // Request camera access
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
        .then(function(stream) {
            video.srcObject = stream;
            video.setAttribute('playsinline', true);
            video.play();
            
            // Create scanner UI
            createScannerUI(video, canvas, ctx, stream);
        })
        .catch(function(err) {
            alert('Camera access denied: ' + err.message);
        });
}

/**
 * Create scanner UI
 */
function createScannerUI(video, canvas, ctx, stream) {
    const modal = document.createElement('div');
    modal.className = 'modal fade show d-block';
    modal.style.backgroundColor = 'rgba(0,0,0,0.5)';
    
    modal.innerHTML = `
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Scan QR Code</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div id="qr-scanner-view"></div>
                    <div id="qr-result" class="mt-3"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    const scannerView = modal.querySelector('#qr-scanner-view');
    scannerView.appendChild(video);
    
    // Start scanning
    scanQRCode(video, canvas, ctx, modal, stream);
    
    // Close modal handler
    modal.querySelector('.btn-close').addEventListener('click', function() {
        stopScanner(stream, modal);
    });
    
    modal.querySelector('.btn-secondary').addEventListener('click', function() {
        stopScanner(stream, modal);
    });
}

/**
 * Scan QR code
 */
function scanQRCode(video, canvas, ctx, modal, stream) {
    const barcodeDetector = new BarcodeDetector({ formats: ['qr_code'] });
    
    function detect() {
        if (video.readyState === video.HAVE_ENOUGH_DATA) {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            barcodeDetector.detect(canvas)
                .then(barcodes => {
                    if (barcodes.length > 0) {
                        const qrData = barcodes[0].rawValue;
                        processQRData(qrData, modal, stream);
                    } else {
                        requestAnimationFrame(detect);
                    }
                })
                .catch(err => {
                    console.error('Barcode detection error:', err);
                    requestAnimationFrame(detect);
                });
        } else {
            requestAnimationFrame(detect);
        }
    }
    
    detect();
}

/**
 * Process QR data
 */
function processQRData(qrData, modal, stream) {
    try {
        const data = JSON.parse(atob(qrData));
        
        if (data.student_id) {
            // Mark student as present
            const resultDiv = modal.querySelector('#qr-result');
            resultDiv.innerHTML = `
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Student ID: ${data.student_id} marked present!
                </div>
            `;
            
            // Update attendance
            updateAttendance(data.student_id, 'present');
            
            // Close after 2 seconds
            setTimeout(() => {
                stopScanner(stream, modal);
            }, 2000);
        }
    } catch (error) {
        console.error('Error processing QR data:', error);
    }
}

/**
 * Stop scanner
 */
function stopScanner(stream, modal) {
    stream.getTracks().forEach(track => track.stop());
    modal.remove();
}

/**
 * Check for notifications
 */
function checkNotifications() {
    // Check for new notifications every 30 seconds
    setInterval(async () => {
        try {
            const response = await fetch('api/notifications.php?unread=true');
            const notifications = await response.json();
            
            if (notifications.length > 0) {
                updateNotificationBadge(notifications.length);
                showNotificationToast(notifications[0]);
            }
        } catch (error) {
            console.error('Error checking notifications:', error);
        }
    }, 30000);
}

/**
 * Update notification badge
 */
function updateNotificationBadge(count) {
    const badge = document.querySelector('.notification-badge');
    if (badge) {
        badge.textContent = count;
        badge.style.display = count > 0 ? 'block' : 'none';
    }
}

/**
 * Show notification toast
 */
function showNotificationToast(notification) {
    const toastContainer = document.querySelector('.toast-container');
    if (!toastContainer) {
        const container = document.createElement('div');
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        document.body.appendChild(container);
    }
    
    const toastId = 'toast-' + Date.now();
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.setAttribute('role', 'alert');
    toast.setAttribute('id', toastId);
    
    toast.innerHTML = `
        <div class="toast-header">
            <strong class="me-auto">${notification.title}</strong>
            <small class="text-muted">Just now</small>
            <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body">
            ${notification.message}
        </div>
    `;
    
    document.querySelector('.toast-container').appendChild(toast);
    
    const bsToast = new bootstrap.Toast(toast);
    bsToast.show();
    
    // Remove toast after it's hidden
    toast.addEventListener('hidden.bs.toast', function() {
        this.remove();
    });
}

/**
 * Setup auto-logout timer
 */
function setupAutoLogout() {
    let timeout;
    const timeoutMinutes = 30; // Match PHP session timeout
    
    function resetTimer() {
        clearTimeout(timeout);
        timeout = setTimeout(logout, timeoutMinutes * 60 * 1000);
    }
    
    function logout() {
        window.location.href = 'logout.php?timeout=true';
    }
    
    // Reset timer on user activity
    document.addEventListener('mousemove', resetTimer);
    document.addEventListener('keypress', resetTimer);
    document.addEventListener('click', resetTimer);
    document.addEventListener('scroll', resetTimer);
    
    // Start timer
    resetTimer();
}

/**
 * Format date to local string
 */
function formatDate(date) {
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
}

/**
 * Format time to local string
 */
function formatTime(date) {
    return new Date(date).toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit'
    });
}

/**
 * Show loading spinner
 */
function showLoading(selector = 'body') {
    const container = document.querySelector(selector);
    const spinner = document.createElement('div');
    spinner.className = 'spinner-container';
    spinner.innerHTML = '<div class="spinner"></div>';
    container.appendChild(spinner);
}

/**
 * Hide loading spinner
 */
function hideLoading(selector = 'body') {
    const spinner = document.querySelector(selector + ' .spinner-container');
    if (spinner) {
        spinner.remove();
    }
}

/**
 * Show success message
 */
function showSuccess(message, duration = 3000) {
    showMessage(message, 'success', duration);
}

/**
 * Show error message
 */
function showError(message, duration = 5000) {
    showMessage(message, 'danger', duration);
}

/**
 * Show message
 */
function showMessage(message, type = 'info', duration = 3000) {
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} alert-dismissible fade show position-fixed top-0 end-0 m-3`;
    alert.style.zIndex = '9999';
    alert.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(alert);
    
    if (duration > 0) {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getInstance(alert);
            if (bsAlert) bsAlert.close();
        }, duration);
    }
}

/**
 * Validate Ethiopian phone number
 */
function validateEthiopianPhone(phone) {
    const pattern = /^(09|07)\d{8}$/;
    return pattern.test(phone);
}

/**
 * Validate email
 */
function validateEmail(email) {
    const pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return pattern.test(email);
}

/**
 * Format file size
 */
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Export functions for use in other scripts
window.DDU = {
    initComponents: initializeComponents,
    formatDate: formatDate,
    formatTime: formatTime,
    showLoading: showLoading,
    hideLoading: hideLoading,
    showSuccess: showSuccess,
    showError: showError,
    validateEthiopianPhone: validateEthiopianPhone,
    validateEmail: validateEmail,
    formatFileSize: formatFileSize
};