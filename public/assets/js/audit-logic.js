function initAuditReportPage() {
    const presetButtons = document.querySelectorAll('.preset-btn');
    const startDateInput = document.querySelector('input[name="start_date"]');
    const endDateInput = document.querySelector('input[name="end_date"]');
    const auditForm = document.getElementById('auditForm');
    const addEmailBtn = document.getElementById('addEmailBtn');
    const emailContainer = document.getElementById('emailContainer');
    const scheduleForm = document.getElementById('auditScheduleForm');

    // Handle preset date buttons
    if (auditForm && auditForm.dataset.datePresetsInitialized !== '1') {
        auditForm.dataset.datePresetsInitialized = '1';
        presetButtons.forEach(button => {
            button.addEventListener('click', function (e) {
                e.preventDefault();
                const preset = this.dataset.preset;
                const today = new Date();
                let startDate, endDate;

                switch (preset) {
                    case 'last-month':
                        startDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                        endDate = new Date(today.getFullYear(), today.getMonth(), 0);
                        break;
                    case 'last-quarter':
                        const quarter = Math.floor(today.getMonth() / 3);
                        startDate = new Date(today.getFullYear(), (quarter - 1) * 3, 1);
                        endDate = new Date(today.getFullYear(), quarter * 3, 0);
                        break;
                    case 'all-time':
                        startDate = new Date(2020, 0, 1);
                        endDate = today;
                        break;
                    case 'this-year':
                        startDate = new Date(today.getFullYear(), 0, 1);
                        endDate = today;
                        break;
                }

                if (startDate && endDate && startDateInput && endDateInput) {
                    startDateInput.value = startDate.toISOString().split('T')[0];
                    endDateInput.value = endDate.toISOString().split('T')[0];
                }
            });
        });
    }

    if (!addEmailBtn || !emailContainer || !scheduleForm) {
        return;
    }
    if (scheduleForm.dataset.scheduleInitialized === '1') {
        return;
    }
    scheduleForm.dataset.scheduleInitialized = '1';

    // Handle add email button
    addEmailBtn.addEventListener('click', function (e) {
        e.preventDefault();
        const emailInputs = emailContainer.querySelectorAll('input[type="email"]');
        if (emailInputs.length < 5) {
            const newInput = document.createElement('input');
            newInput.type = 'email';
            newInput.name = 'schedule_email[]';
            newInput.placeholder = 'name@example.com';
            newInput.className = 'input';
            emailContainer.appendChild(newInput);

            // Update button visibility
            updateAddEmailButton();
        }
    });

    function updateAddEmailButton() {
        const emailInputs = emailContainer.querySelectorAll('input[type="email"]');
        addEmailBtn.hidden = emailInputs.length >= 5;
    }

    // Allow removing empty email fields by clicking backspace on empty input
    emailContainer.addEventListener('keydown', function (e) {
        if (e.key === 'Backspace' && e.target.value === '' && e.target.tagName === 'INPUT') {
            const emailInputs = emailContainer.querySelectorAll('input[type="email"]');
            if (emailInputs.length > 1) {
                e.target.remove();
                updateAddEmailButton();
            }
        }
    });

    updateAddEmailButton();
}
initAuditReportPage.pageInitializerId = 'audit-report';

if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
    window.ProjectAlpha.registerPage('financial/reports', initAuditReportPage);
} else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAuditReportPage, { once: true });
} else {
    initAuditReportPage();
}
