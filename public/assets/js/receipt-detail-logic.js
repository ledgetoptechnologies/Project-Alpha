function showEditModal() {
    document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

document.getElementById('editForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const updateBtn = document.getElementById('updateBtn');
    const editMessage = document.getElementById('editMessage');
    const formData = new FormData(this);

    updateBtn.disabled = true;
    updateBtn.textContent = 'Updating...';
    editMessage.style.display = 'none';

    try {
        const response = await fetch('/?page=receipts-handler', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            window.location.reload();
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        editMessage.style.display = 'block';
        editMessage.style.background = '#fee2e2';
        editMessage.style.border = '1px solid #fca5a5';
        editMessage.style.color = '#991b1b';
        editMessage.textContent = error.message || 'Failed to update receipt';

        updateBtn.disabled = false;
        updateBtn.textContent = 'Update';
    }
});

async function confirmDelete() {
    if (!confirm('Are you sure you want to delete this receipt? This action cannot be undone.')) {
        return;
    }

    const formData = new FormData();
    formData.append('csrf', window.receiptCsrfToken || '');
    formData.append('action', 'delete');
    formData.append('receipt_id', String(window.receiptId || ''));

    try {
        const response = await fetch('/?page=receipts-handler', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            window.location.href = '/?page=financial/expenses-list&tab=receipts';
        } else {
            alert(result.message || 'Failed to delete receipt');
        }
    } catch (error) {
        alert('Failed to delete receipt');
    }
}
