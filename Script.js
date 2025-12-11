// Global variables
let currentBudget = {total_budget: 100000, used_budget: 25000};
let spendChart, analyticsChart;
let vendorsData = [], purchaseOrdersData = [], inventoryData = [], auditLogsData = [];

// Custom Popup Functions
function showCustomPopup(type, title, message, options = {}) {
    const popup = document.getElementById('custom-popup');
    const overlay = document.getElementById('popup-overlay');
    const popupIcon = document.getElementById('custom-popup-icon');
    const popupTitle = document.getElementById('custom-popup-title');
    const popupMessage = document.getElementById('custom-popup-message');
    const popupActions = document.getElementById('custom-popup-actions');
    
    // Set popup type and styling
    popup.className = `custom-popup ${type}`;
    popupIcon.className = `custom-popup-icon ${type}`;
    
    // Set icon based on type
    let iconClass = 'fas fa-info-circle';
    switch(type) {
        case 'danger':
            iconClass = 'fas fa-exclamation-circle';
            break;
        case 'warning':
            iconClass = 'fas fa-exclamation-triangle';
            break;
        case 'success':
            iconClass = 'fas fa-check-circle';
            break;
        case 'info':
        default:
            iconClass = 'fas fa-info-circle';
    }
    popupIcon.innerHTML = `<i class="${iconClass}"></i>`;
    
    // Set title and message
    popupTitle.textContent = title;
    popupMessage.textContent = message;
    
    // Clear previous actions
    popupActions.innerHTML = '';
    
    // Add action buttons based on options
    if (options.confirm) {
        const cancelBtn = document.createElement('button');
        cancelBtn.className = 'custom-popup-btn custom-popup-btn-secondary';
        cancelBtn.textContent = options.cancelText || 'Cancel';
        cancelBtn.onclick = function() {
            hideCustomPopup();
            if (options.onCancel) options.onCancel();
        };
        popupActions.appendChild(cancelBtn);
        
        const confirmBtn = document.createElement('button');
        confirmBtn.className = `custom-popup-btn custom-popup-btn-${type}`;
        confirmBtn.textContent = options.confirmText || 'Confirm';
        confirmBtn.onclick = function() {
            hideCustomPopup();
            if (options.onConfirm) options.onConfirm();
        };
        popupActions.appendChild(confirmBtn);
    } else {
        const okBtn = document.createElement('button');
        okBtn.className = `custom-popup-btn custom-popup-btn-${type}`;
        okBtn.textContent = options.okText || 'OK';
        okBtn.onclick = function() {
            hideCustomPopup();
            if (options.onOk) options.onOk();
        };
        popupActions.appendChild(okBtn);
    }
    
    // Show popup and overlay
    popup.classList.add('show');
    overlay.classList.add('show');
    
    // Disable all interactive elements outside the popup
    const interactiveElements = document.querySelectorAll('button, input, select, textarea, a');
    interactiveElements.forEach(el => {
        if (!popup.contains(el)) {
            el.setAttribute('tabindex', '-1');
            el.style.pointerEvents = 'none';
        }
    });
}

function hideCustomPopup() {
    const popup = document.getElementById('custom-popup');
    const overlay = document.getElementById('popup-overlay');
    
    popup.classList.remove('show');
    overlay.classList.remove('show');
    
    // Re-enable all interactive elements
    const interactiveElements = document.querySelectorAll('button, input, select, textarea, a');
    interactiveElements.forEach(el => {
        el.removeAttribute('tabindex');
        el.style.pointerEvents = '';
    });
}

// Enhanced permission checking for vendors
function checkPermission(action, resource = null) {
    if (isVendor) {
        switch(action) {
            case 'view':
                // Vendors can only view their own POs
                if (resource === 'purchase-orders') {
                    return true; // But filtered to their own
                }
                return false;
            case 'add':
            case 'edit':
            case 'delete':
            case 'approve':
                showCustomPopup('warning', 'Permission Denied', 'Vendor accounts have view-only access to purchase orders assigned to their company.');
                return false;
        }
    }
    
    if (isGuest) {
        showCustomPopup('warning', 'Permission Denied', 'You do not have permission to perform this action.');
        return false;
    }
    
    // CFO-specific restrictions
    if (userRole === 'CFO') {
        if (['add', 'update', 'delete'].includes(action) && resource === 'vendors') {
            showCustomPopup('warning', 'Permission Denied', 'Only Admin users can add, edit, or delete vendors.');
            return false;
        }
    }
    
    return true;
}

// Initialize the application
document.addEventListener('DOMContentLoaded', function() {
    initSidebarNavigation();
    initModals();
    initBudgetAlertPopup();
    initSearchFilters();
    fetchDashboardData();
    fetchVendors();
    fetchPurchaseOrders();
    fetchInventory();
    fetchAuditLogs();
    setInterval(fetchBudget, 30000); // Update budget every 30 seconds
    
    // Initialize real-time KPI tracking for Admin/CFO
    if (isAdminOrCFO) {
        const kpiManager = new KpiManager();
        kpiManager.init();
    }
    
    // Show appropriate welcome message
    if (isVendor) {
        setTimeout(() => {
            showCustomPopup('info', 'Vendor Portal', `Welcome ${userName} to the vendor portal. You can view purchase orders assigned to your company.`);
        }, 1000);
    } else if (isAdminOrCFO) {
        setTimeout(() => {
            showCustomPopup('info', 'Procurement Dashboard', `Welcome ${userName}. The system is tracking 15-30% cost reduction and 25% cycle time improvement targets.`);
        }, 1000);
    }
});

// Initialize budget alert popup
function initBudgetAlertPopup() {
    const popup = document.getElementById('budget-alert-popup');
    const overlay = document.getElementById('popup-overlay');
    const dismissBtn = document.getElementById('budget-alert-dismiss');
    const reviewBtn = document.getElementById('budget-alert-review');
    
    dismissBtn.addEventListener('click', function() {
        popup.classList.remove('show');
        overlay.classList.remove('show');
    });
    
    reviewBtn.addEventListener('click', function() {
        popup.classList.remove('show');
        overlay.classList.remove('show');
        // Navigate to analytics page
        document.querySelector('[data-target="analytics"]').click();
    });
    
    // Close popup when clicking outside
    overlay.addEventListener('click', function() {
        popup.classList.remove('show');
        overlay.classList.remove('show');
    });
}

// Show budget alert popup
function showBudgetAlert(level, message) {
    const popup = document.getElementById('budget-alert-popup');
    const overlay = document.getElementById('popup-overlay');
    const icon = document.getElementById('budget-alert-icon');
    const title = document.getElementById('budget-alert-title');
    const msg = document.getElementById('budget-alert-message');
    
    // Remove previous classes
    popup.classList.remove('danger', 'warning', 'success');
    icon.classList.remove('danger', 'warning', 'success');
    
    // Set new classes and content
    popup.classList.add(level);
    icon.classList.add(level);
    
    if (level === 'danger') {
        title.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Budget Exceeded';
        icon.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
    } else if (level === 'warning') {
        title.innerHTML = '<i class="fas fa-exclamation-circle"></i> Budget Warning';
        icon.innerHTML = '<i class="fas fa-exclamation-circle"></i>';
    } else {
        title.innerHTML = '<i class="fas fa-check-circle"></i> Budget Status';
        icon.innerHTML = '<i class="fas fa-check-circle"></i>';
    }
    
    msg.textContent = message;
    popup.classList.add('show');
    overlay.classList.add('show');
    
    // Auto hide after 7 seconds for non-critical alerts
    if (level !== 'danger') {
        setTimeout(() => {
            popup.classList.remove('show');
            overlay.classList.remove('show');
        }, 7000);
    }
}

// Initialize search and filter functionality
function initSearchFilters() {
    // Vendor search and filters
    const vendorSearch = document.getElementById('vendor-search');
    const vendorCategoryFilter = document.getElementById('vendor-category-filter');
    const vendorStatusFilter = document.getElementById('vendor-status-filter');
    
    if (vendorSearch) vendorSearch.addEventListener('input', filterVendors);
    if (vendorCategoryFilter) vendorCategoryFilter.addEventListener('change', filterVendors);
    if (vendorStatusFilter) vendorStatusFilter.addEventListener('change', filterVendors);
    
    // PO search and filters
    const poSearch = document.getElementById('po-search');
    const poStatusFilter = document.getElementById('po-status-filter');
    
    if (poSearch) poSearch.addEventListener('input', filterPurchaseOrders);
    if (poStatusFilter) poStatusFilter.addEventListener('change', filterPurchaseOrders);
    
    // Inventory search and filters
    const inventorySearch = document.getElementById('inventory-search');
    const inventoryCategoryFilter = document.getElementById('inventory-category-filter');
    const inventoryStatusFilter = document.getElementById('inventory-status-filter');
    
    if (inventorySearch) inventorySearch.addEventListener('input', filterInventory);
    if (inventoryCategoryFilter) inventoryCategoryFilter.addEventListener('change', filterInventory);
    if (inventoryStatusFilter) inventoryStatusFilter.addEventListener('change', filterInventory);
    
    // Analytics period filter
    const analyticsPeriod = document.getElementById('analytics-period');
    if (analyticsPeriod) analyticsPeriod.addEventListener('change', initAnalyticsChart);
    
    // Audit log filters
    const auditFilterBtn = document.getElementById('audit-filter-btn');
    if (auditFilterBtn) auditFilterBtn.addEventListener('click', fetchAuditLogs);
    
    // Configure rules button
    const configureRulesBtn = document.getElementById('configure-rules-btn');
    if (configureRulesBtn) {
        configureRulesBtn.addEventListener('click', function() {
            showCustomPopup('info', 'Approval Rules', 'Approval rules configuration saved. CFO approval required for purchases over $' + 
                document.getElementById('cfo-approval-threshold').value);
        });
    }
}

// Sidebar navigation
function initSidebarNavigation() {
    document.querySelectorAll('.nav-item').forEach(item => {
        item.addEventListener('click', () => {
            document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
            item.classList.add('active');
            document.querySelectorAll('.module').forEach(m => m.classList.remove('active'));
            document.getElementById(item.dataset.target).classList.add('active');
            document.querySelector('.module-title').textContent = item.querySelector('span').textContent;
            
            // Load specific data when module is selected
            switch(item.dataset.target) {
                case 'dashboard':
                    fetchDashboardData();
                    break;
                case 'vendors':
                    fetchVendors();
                    break;
                case 'approvals':
                    fetchVendors(); // Also loads approvals
                    break;
                case 'purchase-orders':
                    fetchPurchaseOrders();
                    break;
                case 'inventory':
                    fetchInventory();
                    break;
                case 'analytics':
                    initAnalyticsChart();
                    fetchAuditLogs();
                    break;
            }
        });
    });
    
    // Mobile menu toggle
    document.getElementById('mobile-menu-toggle').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('open');
    });
    
    // Logout button
    document.getElementById('logout-btn').addEventListener('click', function() {
        showCustomPopup('warning', 'Confirm Logout', 'Are you sure you want to logout?', {
            confirm: true,
            cancelText: 'Cancel',
            confirmText: 'Logout',
            onCancel: () => console.log('Logout cancelled'),
            onConfirm: () => {
                window.location.href = 'logout.php';
            }
        });
    });
}

// Initialize modals
function initModals() {
    // Vendor modal
    const vendorModal = document.getElementById('vendor-modal');
    const addVendorBtn = document.getElementById('add-vendor-btn');
    const closeModalBtns = document.querySelectorAll('.close');
    
    addVendorBtn?.addEventListener('click', () => {
        if (!checkPermission('add', 'vendors')) return;
        document.getElementById('vendor-form').reset();
        document.getElementById('vendor_id').value = '';
        document.querySelector('input[name="action"]').value = 'add';
        document.getElementById('modal-title').textContent = 'Add Vendor';
        vendorModal.style.display = 'flex';
    });
    
    // PO modal
    const poModal = document.getElementById('po-modal');
    const addPoBtn = document.getElementById('add-po-btn');
    
    addPoBtn?.addEventListener('click', () => {
        if (!checkPermission('add', 'purchase-orders')) return;
        document.getElementById('po-form').reset();
        document.getElementById('po_id').value = '';
        document.getElementById('po-modal-title').textContent = 'Create Purchase Order';
        populateVendorDropdown();
        poModal.style.display = 'flex';
    });
    
    // Inventory modal
    const inventoryModal = document.getElementById('inventory-modal');
    const addInventoryBtn = document.getElementById('add-inventory-btn');
    
    addInventoryBtn?.addEventListener('click', () => {
        if (!checkPermission('add', 'inventory')) return;
        document.getElementById('inventory-form').reset();
        document.getElementById('item_id').value = '';
        document.getElementById('inventory-modal-title').textContent = 'Add Inventory Item';
        inventoryModal.style.display = 'flex';
    });
    
    // Close modals
    closeModalBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('.modal').style.display = 'none';
        });
    });
    
    // Close modal when clicking outside
    window.addEventListener('click', e => {
        if(e.target.classList.contains('modal')) {
            e.target.style.display = 'none';
        }
    });
    
    // Form submissions
    document.getElementById('vendor-form').addEventListener('submit', handleVendorSubmit);
    document.getElementById('po-form').addEventListener('submit', handlePoSubmit);
    document.getElementById('inventory-form').addEventListener('submit', handleInventorySubmit);
}

// Fetch dashboard data
function fetchDashboardData() {
    fetch('add_vendor.php?action=dashboard')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('total-vendors').textContent = data.counts.vendors;
                document.getElementById('total-pos').textContent = data.counts.purchase_orders;
                document.getElementById('total-inventory').textContent = data.counts.inventory;
                document.getElementById('low-stock-count').textContent = data.counts.low_stock;
                
                // Update recent activities
                const activitiesHtml = data.activities.map(activity => 
                    `<p><strong>${activity.timestamp}</strong> - ${activity.user_role} ${activity.action}: ${activity.details}</p>`
                ).join('');
                document.getElementById('recent-activities').innerHTML = activitiesHtml;
                
                // Initialize spend chart
                initSpendChart(data.spend_data);
            }
        })
        .catch(error => {
            console.error('Error fetching dashboard data:', error);
        });
}

// Initialize spend chart
function initSpendChart(data) {
    const ctx = document.getElementById('spendChart').getContext('2d');
    
    if(spendChart) {
        spendChart.destroy();
    }
    
    spendChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Spend by Category',
                data: data.values,
                backgroundColor: [
                    'rgba(255, 99, 132, 0.7)',
                    'rgba(54, 162, 235, 0.7)',
                    'rgba(255, 206, 86, 0.7)',
                    'rgba(75, 192, 192, 0.7)',
                    'rgba(153, 102, 255, 0.7)'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
}

// Initialize analytics chart
function initAnalyticsChart() {
    const ctx = document.getElementById('analyticsChart').getContext('2d');
    const period = document.getElementById('analytics-period').value;
    
    fetch(`add_vendor.php?action=analytics&period=${period}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if(analyticsChart) {
                    analyticsChart.destroy();
                }
                
                analyticsChart = new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: data.analytics_data.labels,
                        datasets: [{
                            data: data.analytics_data.values,
                            backgroundColor: [
                                'rgba(255, 99, 132, 0.7)',
                                'rgba(54, 162, 235, 0.7)',
                                'rgba(255, 206, 86, 0.7)',
                                'rgba(75, 192, 192, 0.7)',
                                'rgba(153, 102, 255, 0.7)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            title: {
                                display: true,
                                text: `Spend Analytics - ${period.charAt(0).toUpperCase() + period.slice(1)}`
                            }
                        }
                    }
                });
            }
        })
        .catch(error => {
            console.error('Error fetching analytics data:', error);
        });
}

// Fetch vendors
function fetchVendors() {
    let url = 'add_vendor.php?action=fetch';
    
    // If user is a vendor, only fetch their data
    if (isVendor) {
        url += '&vendor_id=' + userId;
    }
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                vendorsData = data.vendors;
                renderVendors(vendorsData);
            }
        })
        .catch(error => {
            console.error('Error fetching vendors:', error);
        });
}

// Render vendors to the table
function renderVendors(vendors) {
    const tbody = document.getElementById('vendors-list');
    const approvalTbody = document.getElementById('approvals-list');
    
    if (tbody) tbody.innerHTML = ''; 
    
    if(approvalTbody) {
        approvalTbody.innerHTML = '';
    }
    
    // Update category filter
    const categories = [...new Set(vendors.map(v => v.category))];
    const categoryFilter = document.getElementById('vendor-category-filter');
    if (categoryFilter) {
        categoryFilter.innerHTML = '<option value="">All Categories</option>' + 
            categories.map(cat => `<option value="${cat}">${cat}</option>`).join('');
    }
    
    vendors.forEach(v => {
        // For vendors table (Admin/CFO only)
        if (tbody) {
            const row = document.createElement('tr'); 
            row.dataset.id = v.vendor_id;
            
            let actionsHtml = '';
            if (isAdminOrCFO) {
                actionsHtml = `
                    <td>
                        <button class="edit-vendor btn btn-warning btn-sm">Edit</button>
                        <button class="delete-vendor btn btn-danger btn-sm">Delete</button>
                    </td>
                `;
            }
            
            row.innerHTML = `
                <td>${v.vendor_id}</td>
                <td>${v.vendor_name}</td>
                <td>${v.category}</td>
                <td>${v.contact_email}</td>
                <td>${v.contact_phone}</td>
                <td>${v.contract_start} to ${v.contract_end}</td>
                <td>${v.certification}</td>
                <td>${v.performance_score}</td>
                <td>$${v.contract_value.toLocaleString()}</td>
                <td>${v.approval_status}</td>
                ${actionsHtml}
            `;
            tbody.appendChild(row);
        }
        
        // Add to approvals table if user has permission
        if(approvalTbody && (v.approval_status === 'Pending') && isAdminOrCFO) {
            const approveDisabled = (v.contract_value + currentBudget.used_budget) > currentBudget.total_budget ? 'disabled' : '';
            const approveRow = document.createElement('tr'); 
            approveRow.dataset.id = v.vendor_id;
            approveRow.innerHTML = `
                <td>${v.vendor_id}</td>
                <td>${v.vendor_name}</td>
                <td>${v.category}</td>
                <td>$${v.contract_value.toLocaleString()}</td>
                <td>${v.approval_status}</td>
                <td class="approval-actions">
                    <button class="approve-vendor btn btn-success btn-sm" ${approveDisabled}>Approve</button>
                    <button class="reject-vendor btn btn-secondary btn-sm">Reject</button>
                </td>
            `;
            approvalTbody.appendChild(approveRow);
        }
    });
    
    // Add event listeners for vendor actions
    addVendorEventListeners();
}

// Filter vendors based on search and filters
function filterVendors() {
    const searchText = document.getElementById('vendor-search').value.toLowerCase();
    const categoryFilter = document.getElementById('vendor-category-filter').value;
    const statusFilter = document.getElementById('vendor-status-filter').value;
    
    const filteredVendors = vendorsData.filter(vendor => {
        const matchesSearch = vendor.vendor_name.toLowerCase().includes(searchText) || 
                             vendor.contact_email.toLowerCase().includes(searchText) ||
                             vendor.category.toLowerCase().includes(searchText);
        const matchesCategory = categoryFilter === '' || vendor.category === categoryFilter;
        const matchesStatus = statusFilter === '' || vendor.approval_status === statusFilter;
        
        return matchesSearch && matchesCategory && matchesStatus;
    });
    
    renderVendors(filteredVendors);
}

// Add event listeners for vendor actions
function addVendorEventListeners() {
    // Use event delegation for dynamic elements
    document.addEventListener('click', function(e) {
        // Handle vendor actions
        if (e.target.classList.contains('delete-vendor')) {
            if (!checkPermission('delete', 'vendors')) return;
            
            const row = e.target.closest('tr');
            if(!row) return;
            
            const id = row.dataset.id;
            showCustomPopup('warning', 'Delete Vendor', 'Are you sure you want to delete this vendor?', {
                confirm: true,
                cancelText: 'Cancel',
                confirmText: 'Delete',
                onConfirm: () => {
                    const formData = new FormData();
                    formData.append('action', 'delete');
                    formData.append('vendor_id', id);
                    
                    fetch('add_vendor.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showCustomPopup('success', 'Success', 'Vendor deleted successfully');
                            fetchVendors(); 
                            fetchBudget();
                            fetchDashboardData();
                        } else {
                            showCustomPopup('error', 'Error', 'Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error deleting vendor:', error);
                        showCustomPopup('error', 'Error', 'Failed to delete vendor');
                    });
                }
            });
        }
        
        if (e.target.classList.contains('edit-vendor')) {
            if (!checkPermission('edit', 'vendors')) return;
            
            const row = e.target.closest('tr');
            if(!row) return;
            
            const id = row.dataset.id;
            const vendor = vendorsData.find(v => v.vendor_id == id);
            if(vendor) {
                document.getElementById('vendor_id').value = vendor.vendor_id;
                document.getElementById('vendor_name').value = vendor.vendor_name;
                document.getElementById('category').value = vendor.category;
                document.getElementById('contact_email').value = vendor.contact_email;
                document.getElementById('contact_phone').value = vendor.contact_phone;
                document.getElementById('contract_start').value = vendor.contract_start;
                document.getElementById('contract_end').value = vendor.contract_end;
                document.getElementById('certification').value = vendor.certification;
                document.getElementById('performance').value = vendor.performance_score;
                document.getElementById('contract_value').value = vendor.contract_value;
                document.querySelector('input[name="action"]').value = 'update';
                document.getElementById('modal-title').textContent = 'Edit Vendor';
                document.getElementById('vendor-modal').style.display = 'flex';
            }
        }
        
        if (e.target.classList.contains('approve-vendor') || e.target.classList.contains('reject-vendor')) {
            if (!checkPermission('approve', 'vendors')) return;
            
            const row = e.target.closest('tr');
            if(!row) return;
            
            const id = row.dataset.id;
            const action = e.target.classList.contains('approve-vendor') ? 'approve' : 'reject';
            
            showCustomPopup('warning', `Confirm ${action}`, `Are you sure you want to ${action} this vendor?`, {
                confirm: true,
                cancelText: 'Cancel',
                confirmText: action.charAt(0).toUpperCase() + action.slice(1),
                onConfirm: () => {
                    const formData = new FormData();
                    formData.append('action', action);
                    formData.append('vendor_id', id);
                    
                    fetch('add_vendor.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showCustomPopup('success', 'Success', `Vendor ${action}d successfully`);
                            
                            if (data.budget_updated) {
                                // Update the current budget with the new value
                                currentBudget.used_budget = currentBudget.total_budget - data.remaining_budget;
                                fetchBudget();
                            }
                            
                            fetchVendors(); 
                            fetchDashboardData();
                            
                            // If approved, check if we should generate a PO
                            if(action === 'approve' && data.generate_po) {
                                showCustomPopup('info', 'Generate PO', 'Vendor approved. Would you like to generate a purchase order?', {
                                    confirm: true,
                                    cancelText: 'Later',
                                    confirmText: 'Generate PO',
                                    onConfirm: () => {
                                        // Pre-fill PO form with vendor details
                                        const vendor = vendorsData.find(v => v.vendor_id == id);
                                        document.getElementById('po-form').reset();
                                        document.getElementById('vendor_select').value = vendor.vendor_id;
                                        document.getElementById('total_amount').value = vendor.contract_value;
                                        document.getElementById('po-modal-title').textContent = 'Create Purchase Order';
                                        document.getElementById('po-modal').style.display = 'flex';
                                    }
                                });
                            }
                        } else {
                            if (data.message === "Budget Exceeded!") {
                                showBudgetAlert('danger', data.details);
                            }
                            showCustomPopup('error', 'Error', 'Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error(`Error ${action}ing vendor:`, error);
                        showCustomPopup('error', 'Error', `Failed to ${action} vendor`);
                    });
                }
            });
        }
    });
}

// Handle vendor form submission
function handleVendorSubmit(e) {
    e.preventDefault();
    if (!checkPermission('add', 'vendors')) return;
    
    const formData = new FormData(document.getElementById('vendor-form'));
    
    fetch('add_vendor.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const action = formData.get('action') === 'add' ? 'added' : 'updated';
            showCustomPopup('success', 'Success', `Vendor ${action} successfully`);
            document.getElementById('vendor-modal').style.display = 'none';
            fetchVendors();
            fetchBudget();
            fetchDashboardData();
        } else {
            showCustomPopup('error', 'Error', 'Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error saving vendor:', error);
        showCustomPopup('error', 'Error', 'Failed to save vendor');
    });
}

// Fetch purchase orders
function fetchPurchaseOrders() {
    let url = 'add_vendor.php?action=fetch_po';
    
    // If user is a vendor, only fetch their POs
    if (isVendor) {
        url += '&vendor_id=' + userId;
    }
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                purchaseOrdersData = data.purchase_orders;
                renderPurchaseOrders(purchaseOrdersData);
            }
        })
        .catch(error => {
            console.error('Error fetching purchase orders:', error);
        });
}

// Render purchase orders to the table
function renderPurchaseOrders(orders) {
    const tbody = document.getElementById('po-list');
    if (!tbody) return;
    
    tbody.innerHTML = '';
    
    orders.forEach(po => {
        const row = document.createElement('tr');
        row.dataset.id = po.po_id;
        
        let actionsHtml = '';
        if (isAdminOrCFO) {
            actionsHtml = `
                <td>
                    <button class="view-po btn btn-info btn-sm">View</button>
                    <button class="edit-po btn btn-warning btn-sm">Edit</button>
                    <button class="delete-po btn btn-danger btn-sm">Delete</button>
                    <button class="print-po btn btn-secondary btn-sm">Print</button>
                </td>
            `;
        }
        
        row.innerHTML = `
            <td>${po.po_number}</td>
            <td>${po.vendor_name}</td>
            <td>${po.issue_date}</td>
            <td>$${po.total_amount.toLocaleString()}</td>
            <td>${po.status}</td>
            ${actionsHtml}
        `;
        tbody.appendChild(row);
    });
    
    // Update dashboard counter
    document.getElementById('total-pos').textContent = orders.length;
    
    // Add event listeners for PO actions
    addPOEventListeners();
}

// Filter purchase orders based on search and filters
function filterPurchaseOrders() {
    const searchText = document.getElementById('po-search').value.toLowerCase();
    const statusFilter = document.getElementById('po-status-filter').value;
    
    const filteredOrders = purchaseOrdersData.filter(order => {
        const matchesSearch = order.po_number.toLowerCase().includes(searchText) || 
                             order.vendor_name.toLowerCase().includes(searchText);
        const matchesStatus = statusFilter === '' || order.status === statusFilter;
        
        return matchesSearch && matchesStatus;
    });
    
    renderPurchaseOrders(filteredOrders);
}

// Add event listeners for PO actions
function addPOEventListeners() {
    document.addEventListener('click', e => {
        const row = e.target.closest('tr');
        if(!row) return;
        
        const id = row.dataset.id;
        
        if(e.target.classList.contains('delete-po')) {
            if (!checkPermission('delete', 'purchase-orders')) return;
            
            showCustomPopup('warning', 'Delete PO', 'Are you sure you want to delete this purchase order?', {
                confirm: true,
                cancelText: 'Cancel',
                confirmText: 'Delete',
                onConfirm: () => {
                    const formData = new FormData();
                    formData.append('action', 'delete_po');
                    formData.append('po_id', id);
                    
                    fetch('add_vendor.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showCustomPopup('success', 'Success', 'Purchase order deleted successfully');
                            fetchPurchaseOrders();
                            fetchDashboardData();
                        } else {
                            showCustomPopup('error', 'Error', 'Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error deleting purchase order:', error);
                        showCustomPopup('error', 'Error', 'Failed to delete purchase order');
                    });
                }
            });
        }
        
        if(e.target.classList.contains('edit-po')) {
            if (!checkPermission('edit', 'purchase-orders')) return;
            
            const po = purchaseOrdersData.find(p => p.po_id == id);
            if(po) {
                document.getElementById('po_id').value = po.po_id;
                document.getElementById('po_number').value = po.po_number;
                document.getElementById('issue_date').value = po.issue_date;
                document.getElementById('total_amount').value = po.total_amount;
                document.getElementById('po_status').value = po.status;
                populateVendorDropdown(po.vendor_id);
                document.getElementById('po-modal-title').textContent = 'Edit Purchase Order';
                document.getElementById('po-modal').style.display = 'flex';
            }
        }
        
        if(e.target.classList.contains('view-po')) {
            // View PO details
            const po = purchaseOrdersData.find(p => p.po_id == id);
            if(po) {
                showCustomPopup('info', 'PO Details', 
                    `PO Number: ${po.po_number}\nVendor: ${po.vendor_name}\nAmount: $${po.total_amount}\nStatus: ${po.status}\nIssue Date: ${po.issue_date}`);
            }
        }
        
        if(e.target.classList.contains('print-po')) {
            // Print PO
            showCustomPopup('info', 'Print PO', 'Print functionality would be implemented here');
        }
    });
}

// Populate vendor dropdown
function populateVendorDropdown(selectedId = null) {
    const select = document.getElementById('vendor_select');
    if (!select) return;
    
    select.innerHTML = '<option value="">Select Vendor</option>';
    
    // Only show approved vendors for PO creation
    const approvedVendors = vendorsData.filter(v => v.approval_status === 'Approved');
    
    approvedVendors.forEach(v => {
        const option = document.createElement('option');
        option.value = v.vendor_id;
        option.textContent = v.vendor_name;
        if(selectedId && v.vendor_id == selectedId) {
            option.selected = true;
        }
        select.appendChild(option);
    });
}

// Handle PO form submission
function handlePoSubmit(e) {
    e.preventDefault();
    if (!checkPermission('add', 'purchase-orders')) return;
    
    const formData = new FormData(document.getElementById('po-form'));
    
    fetch('add_vendor.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const action = formData.get('action') === 'add_po' ? 'created' : 'updated';
            showCustomPopup('success', 'Success', `Purchase order ${action} successfully`);
            document.getElementById('po-modal').style.display = 'none';
            fetchPurchaseOrders();
            fetchDashboardData();
        } else {
            showCustomPopup('error', 'Error', 'Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error saving purchase order:', error);
        showCustomPopup('error', 'Error', 'Failed to save purchase order');
    });
}

// Fetch inventory
function fetchInventory() {
    fetch('add_vendor.php?action=fetch_inventory')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                inventoryData = data.inventory;
                renderInventory(inventoryData);
            }
        })
        .catch(error => {
            console.error('Error fetching inventory:', error);
        });
}

// Render inventory to the table
function renderInventory(inventory) {
    const tbody = document.getElementById('inventory-list');
    if (!tbody) return;
    
    tbody.innerHTML = '';
    
    // Update category filter
    const categories = [...new Set(inventory.map(item => item.category))];
    const categoryFilter = document.getElementById('inventory-category-filter');
    if (categoryFilter) {
        categoryFilter.innerHTML = '<option value="">All Categories</option>' + 
            categories.map(cat => `<option value="${cat}">${cat}</option>`).join('');
    }
    
    inventory.forEach(item => {
        const row = document.createElement('tr');
        row.dataset.id = item.item_id;
        
        // Determine stock status
        let status = '';
        if(item.current_stock <= item.reorder_point) {
            status = '<span class="low-stock">Low Stock</span>';
        }
        
        // Check for dead stock (no movement in 365 days)
        const lastMovement = new Date(item.last_movement_date);
        const today = new Date();
        const diffTime = Math.abs(today - lastMovement);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        
        if(diffDays > 365) {
            status += status ? ' <span class="dead-stock">Dead Stock</span>' : '<span class="dead-stock">Dead Stock</span>';
        }
        
        if (!status) {
            status = '<span class="status-badge status-approved">Normal</span>';
        }
        
        let actionsHtml = '';
        if (isAdminOrCFO) {
            actionsHtml = `
                <td>
                    <button class="edit-inventory btn btn-warning btn-sm">Edit</button>
                    <button class="delete-inventory btn btn-danger btn-sm">Delete</button>
                </td>
            `;
        }
        
        row.innerHTML = `
            <td>${item.item_id}</td>
            <td>${item.item_name}</td>
            <td>${item.category}</td>
            <td>${item.current_stock}</td>
            <td>${item.reorder_point}</td>
            <td>${item.last_movement_date}</td>
            <td>${status}</td>
            ${actionsHtml}
        `;
        tbody.appendChild(row);
    });
    
    // Update dashboard counter
    document.getElementById('total-inventory').textContent = inventory.length;
    
    // Count low stock items
    const lowStockCount = inventory.filter(item => item.current_stock <= item.reorder_point).length;
    document.getElementById('low-stock-count').textContent = lowStockCount;
    
    // Add event listeners for inventory actions
    addInventoryEventListeners();
}

// Filter inventory based on search and filters
function filterInventory() {
    const searchText = document.getElementById('inventory-search').value.toLowerCase();
    const categoryFilter = document.getElementById('inventory-category-filter').value;
    const statusFilter = document.getElementById('inventory-status-filter').value;
    
    const filteredInventory = inventoryData.filter(item => {
        const matchesSearch = item.item_name.toLowerCase().includes(searchText) || 
                             item.category.toLowerCase().includes(searchText);
        const matchesCategory = categoryFilter === '' || item.category === categoryFilter;
        
        // Status filter logic
        let matchesStatus = true;
        if (statusFilter !== '') {
            if (statusFilter === 'low') {
                matchesStatus = item.current_stock <= item.reorder_point;
            } else if (statusFilter === 'dead') {
                const lastMovement = new Date(item.last_movement_date);
                const today = new Date();
                const diffTime = Math.abs(today - lastMovement);
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                matchesStatus = diffDays > 365;
            }
        }
        
        return matchesSearch && matchesCategory && matchesStatus;
    });
    
    renderInventory(filteredInventory);
}

// Add event listeners for inventory actions
function addInventoryEventListeners() {
    document.addEventListener('click', e => {
        const row = e.target.closest('tr');
        if(!row) return;
        
        const id = row.dataset.id;
        
        if(e.target.classList.contains('delete-inventory')) {
            if (!checkPermission('delete', 'inventory')) return;
            
            showCustomPopup('warning', 'Delete Item', 'Are you sure you want to delete this inventory item?', {
                confirm: true,
                cancelText: 'Cancel',
                confirmText: 'Delete',
                onConfirm: () => {
                    const formData = new FormData();
                    formData.append('action', 'delete_inventory');
                    formData.append('item_id', id);
                    
                    fetch('add_vendor.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showCustomPopup('success', 'Success', 'Inventory item deleted successfully');
                            fetchInventory();
                            fetchDashboardData();
                        } else {
                            showCustomPopup('error', 'Error', 'Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error deleting inventory item:', error);
                        showCustomPopup('error', 'Error', 'Failed to delete inventory item');
                    });
                }
            });
        }
        
        if(e.target.classList.contains('edit-inventory')) {
            if (!checkPermission('edit', 'inventory')) return;
            
            const item = inventoryData.find(i => i.item_id == id);
            if(item) {
                document.getElementById('item_id').value = item.item_id;
                document.getElementById('item_name').value = item.item_name;
                document.getElementById('inventory_category').value = item.category;
                document.getElementById('current_stock').value = item.current_stock;
                document.getElementById('reorder_point').value = item.reorder_point;
                document.getElementById('last_movement_date').value = item.last_movement_date;
                document.getElementById('inventory-modal-title').textContent = 'Edit Inventory Item';
                document.getElementById('inventory-modal').style.display = 'flex';
            }
        }
    });
}

// Handle inventory form submission
function handleInventorySubmit(e) {
    e.preventDefault();
    if (!checkPermission('add', 'inventory')) return;
    
    const formData = new FormData(document.getElementById('inventory-form'));
    
    fetch('add_vendor.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const action = formData.get('action') === 'add_inventory' ? 'added' : 'updated';
            showCustomPopup('success', 'Success', `Inventory item ${action} successfully`);
            document.getElementById('inventory-modal').style.display = 'none';
            fetchInventory();
            fetchDashboardData();
        } else {
            showCustomPopup('error', 'Error', 'Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error saving inventory item:', error);
        showCustomPopup('error', 'Error', 'Failed to save inventory item');
    });
}

// Fetch audit logs
function fetchAuditLogs() {
    const userFilter = document.getElementById('audit-user-filter')?.value;
    const dateFrom = document.getElementById('audit-date-from')?.value;
    const dateTo = document.getElementById('audit-date-to')?.value;
    
    let url = 'add_vendor.php?action=fetch_audit';
    if (userFilter) url += `&user=${userFilter}`;
    if (dateFrom) url += `&from=${dateFrom}`;
    if (dateTo) url += `&to=${dateTo}`;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                auditLogsData = data.audit_logs;
                renderAuditLogs(auditLogsData);
            }
        })
        .catch(error => {
            console.error('Error fetching audit logs:', error);
        });
}

// Render audit logs to the table
function renderAuditLogs(logs) {
    const tbody = document.getElementById('audit-list');
    if (!tbody) return;
    
    tbody.innerHTML = '';
    
    logs.forEach(log => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${log.timestamp}</td>
            <td>${log.user_role}</td>
            <td>${log.action}</td>
            <td>${log.details}</td>
        `;
        tbody.appendChild(row);
    });
}

// Real-time budget
function fetchBudget(){
    fetch('add_vendor.php?action=budget')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                currentBudget = data.budget;
                const remaining = data.remaining_budget;
                const budgetEl = document.getElementById('budget-alert');
                budgetEl.textContent = `Remaining Budget: $${remaining.toLocaleString()}`;
                
                // Show appropriate budget alert
                if(remaining < 0){
                    budgetEl.className = 'alert-danger';
                    showBudgetAlert('danger', `Budget exceeded by $${Math.abs(remaining).toLocaleString()}. Cannot approve any more vendors.`);
                } else if(remaining < currentBudget.total_budget * 0.2){
                    budgetEl.className = 'alert-warning';
                    showBudgetAlert('warning', `Only $${remaining.toLocaleString()} remaining (${Math.round((remaining/currentBudget.total_budget)*100)}% of total budget).`);
                } else {
                    budgetEl.className = 'alert-success';
                }
                
                fetchVendors(); // update approve buttons based on remaining budget
            }
        })
        .catch(error => {
            console.error('Error fetching budget:', error);
        });
}

// Real-time KPI Manager
class KpiManager {
    constructor() {
        this.baselineData = {
            procurementCycle: 45, // days
            maverickSpending: 25, // percentage of total spend
            manualProcessCost: 150000, // annual cost
            avgProcessingCost: 85 // per transaction
        };
        
        this.currentMetrics = {
            procurementCycle: 45,
            maverickSpending: 25,
            costSavings: 0,
            efficiencyGain: 0
        };
        
        this.updateInterval = null;
    }

    // Initialize real-time updates
    init() {
        this.loadInitialMetrics();
        this.startRealTimeUpdates();
        
        // Update on PO creation/approval
        document.addEventListener('poCreated', () => this.updateMetrics());
        document.addEventListener('vendorApproved', () => this.updateMetrics());
        document.addEventListener('inventoryUpdated', () => this.updateMetrics());
    }

    // Load initial metrics from server
    async loadInitialMetrics() {
        try {
            const response = await fetch('get_kpi_metrics.php');
            const data = await response.json();
            
            if (data.success) {
                this.currentMetrics = { ...this.currentMetrics, ...data.metrics };
                this.updateDashboard();
            }
        } catch (error) {
            console.error('Error loading KPI metrics:', error);
        }
    }

    // Start real-time updates
    startRealTimeUpdates() {
        // Update every 30 seconds
        this.updateInterval = setInterval(() => {
            this.updateMetrics();
        }, 30000);

        // Also update when user becomes active
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.updateMetrics();
            }
        });
    }

    // Calculate all metrics
    async updateMetrics() {
        try {
            const [cycleData, spendingData, savingsData, efficiencyData] = await Promise.all([
                this.calculateProcurementCycle(),
                this.calculateMaverickSpending(),
                this.calculateCostSavings(),
                this.calculateEfficiencyGain()
            ]);

            this.currentMetrics = {
                procurementCycle: cycleData.currentCycle,
                maverickSpending: spendingData.maverickPercentage,
                costSavings: savingsData.totalSavings,
                efficiencyGain: efficiencyData.efficiencyGain
            };

            this.updateDashboard();
            this.triggerMetricUpdateEvent();

        } catch (error) {
            console.error('Error updating metrics:', error);
        }
    }

    // Calculate procurement cycle reduction
    async calculateProcurementCycle() {
        try {
            const response = await fetch('calculate_cycle_metrics.php');
            const data = await response.json();
            
            if (data.success) {
                const currentCycle = data.avgCycleTime;
                const reduction = ((this.baselineData.procurementCycle - currentCycle) / this.baselineData.procurementCycle) * 100;
                
                return {
                    currentCycle,
                    reduction: Math.max(0, reduction)
                };
            }
        } catch (error) {
            console.error('Error calculating cycle metrics:', error);
        }
        return { currentCycle: this.baselineData.procurementCycle, reduction: 0 };
    }

    // Calculate maverick spending reduction
    async calculateMaverickSpending() {
        try {
            const response = await fetch('calculate_maverick_spending.php');
            const data = await response.json();
            
            if (data.success) {
                const maverickPercentage = data.maverickPercentage;
                const reduction = ((this.baselineData.maverickSpending - maverickPercentage) / this.baselineData.maverickSpending) * 100;
                
                return {
                    maverickPercentage,
                    reduction: Math.max(0, reduction)
                };
            }
        } catch (error) {
            console.error('Error calculating maverick spending:', error);
        }
        return { maverickPercentage: this.baselineData.maverickSpending, reduction: 0 };
    }

    // Calculate cost savings
    async calculateCostSavings() {
        try {
            const response = await fetch('api/calculate_cost_savings.php');
            const data = await response.json();
            
            if (data.success) {
                return {
                    totalSavings: data.totalSavings,
                    ytdSavings: data.ytdSavings
                };
            }
        } catch (error) {
            console.error('Error calculating cost savings:', error);
        }
        return { totalSavings: 0, ytdSavings: 0 };
    }

    // Calculate efficiency gain
    async calculateEfficiencyGain() {
        try {
            const response = await fetch('api/calculate_efficiency.php');
            const data = await response.json();
            
            if (data.success) {
                return {
                    efficiencyGain: data.efficiencyGain,
                    automatedProcesses: data.automatedProcesses
                };
            }
        } catch (error) {
            console.error('Error calculating efficiency:', error);
        }
        return { efficiencyGain: 0, automatedProcesses: 0 };
    }

    // Update dashboard with new metrics
    updateDashboard() {
        const cycleReduction = ((this.baselineData.procurementCycle - this.currentMetrics.procurementCycle) / this.baselineData.procurementCycle) * 100;
        const maverickReduction = ((this.baselineData.maverickSpending - this.currentMetrics.maverickSpending) / this.baselineData.maverickSpending) * 100;
        
        // Update DOM elements
        this.updateMetricElement('cycle-reduction', Math.max(0, cycleReduction).toFixed(1) + '%');
        this.updateMetricElement('maverick-reduction', Math.max(0, maverickReduction).toFixed(1) + '%');
        this.updateMetricElement('savings-amount', '$' + this.formatNumber(this.currentMetrics.costSavings));
        this.updateMetricElement('efficiency-gain', this.currentMetrics.efficiencyGain.toFixed(1) + '%');
        
        // Update progress bars
        this.updateProgressBar('cycle-progress', cycleReduction);
        this.updateProgressBar('maverick-progress', maverickReduction);
        this.updateProgressBar('savings-progress', Math.min(100, (this.currentMetrics.costSavings / 100000) * 100));
        this.updateProgressBar('efficiency-progress', this.currentMetrics.efficiencyGain);
        
        // Update trends
        this.updateTrendIndicators();
    }

    updateMetricElement(id, value) {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = value;
            element.parentElement.classList.add('metric-updating');
            setTimeout(() => {
                element.parentElement.classList.remove('metric-updating');
            }, 500);
        }
    }

    updateProgressBar(id, percentage) {
        const element = document.getElementById(id);
        if (element) {
            const width = Math.min(100, Math.max(0, percentage));
            element.style.width = width + '%';
            
            // Change color based on performance
            if (width >= 70) element.style.background = '#28a745';
            else if (width >= 40) element.style.background = '#ffc107';
            else element.style.background = '#dc3545';
        }
    }

    updateTrendIndicators() {
        // This would compare with previous values to show trend arrows
        // For now, using placeholder logic
        const trends = {
            cycle: '↗️',
            maverick: '↘️',
            savings: '↗️',
            efficiency: '↗️'
        };
        
        document.getElementById('cycle-trend').textContent = trends.cycle;
        document.getElementById('maverick-trend').textContent = trends.maverick;
        document.getElementById('savings-trend').textContent = trends.savings;
        document.getElementById('efficiency-trend').textContent = trends.efficiency;
    }

    formatNumber(num) {
        if (num >= 1000000) {
            return (num / 1000000).toFixed(1) + 'M';
        } else if (num >= 1000) {
            return (num / 1000).toFixed(1) + 'K';
        }
        return num.toFixed(0);
    }

    triggerMetricUpdateEvent() {
        const event = new CustomEvent('metricsUpdated', {
            detail: this.currentMetrics
        });
        document.dispatchEvent(event);
    }
}

// Override default alert and confirm
window.alert = function(message) {
    showCustomPopup('info', 'Information', message);
};

window.confirm = function(message) {
    return new Promise(resolve => {
        showCustomPopup('warning', 'Confirmation', message, {
            confirm: true,
            cancelText: 'Cancel',
            confirmText: 'OK',
            onCancel: () => resolve(false),
            onConfirm: () => resolve(true)
        });
    });
};

// Initial fetch
fetchBudget();