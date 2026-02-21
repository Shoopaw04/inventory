let currentPage = 0;
const pageSize = 50;
let currentUserId = null;
let roles = [];
let selectedUsers = new Set();

// Check authentication and role
document.addEventListener('DOMContentLoaded', async function() {
    try {
        const user = await AuthHelper.requireAuthAndRole(['Admin']);
        if (user) {
            await loadRoles();
            await loadUsers();
            
            // Set up periodic session validation
            setInterval(async () => {
                try {
                    const response = await fetch(`${(window.API_BASE||'../api/')}me.php`, { credentials: 'include' });
                    if (!response.ok) {
                        window.location.href = 'login.html';
                    }
                } catch (error) {
                    console.error('Session validation failed:', error);
                    window.location.href = 'login.html';
                }
            }, 30000); // Check every 30 seconds
        }
    } catch (error) {
        console.error('Auth error:', error);
        window.location.href = 'login.html';
    }
});

async function loadRoles() {
    try {
        const data = await getRoles();
        if (data.success) {
            roles = data.data;
            const roleSelect = document.getElementById('roleFilter');
            const roleFormSelect = document.getElementById('roleId');
            
            // Clear existing options
            roleSelect.innerHTML = '<option value="">All Roles</option>';
            roleFormSelect.innerHTML = '<option value="">Select Role</option>';
            
            roles.forEach(role => {
                roleSelect.innerHTML += `<option value="${role.Role_ID}">${role.Role_name}</option>`;
                roleFormSelect.innerHTML += `<option value="${role.Role_ID}">${role.Role_name}</option>`;
            });
        }
    } catch (error) {
        console.error('Error loading roles:', error);
    }
}

async function loadUsers() {
    try {
        const search = document.getElementById('searchInput').value;
        const role = document.getElementById('roleFilter').value;
        const status = document.getElementById('statusFilter').value;
        
        const filters = {
            limit: pageSize,
            offset: currentPage * pageSize
        };
        
        if (search) filters.search = search;
        if (role) filters.role = role;
        if (status) filters.status = status;

        const data = await getUsers(filters);

        if (data.success) {
            displayUsers(data.data);
            updateStatistics(data.statistics);
            updatePagination(data.pagination);
        } else {
            showError(data.error || 'Failed to load users');
        }
    } catch (error) {
        console.error('Error loading users:', error);
        showError('Failed to load users');
    }
}

function displayUsers(users) {
    const content = document.getElementById('usersContent');
    
    if (users.length === 0) {
        content.innerHTML = '<div class="no-data">No users found</div>';
        return;
    }

    const tableHTML = `
        <div class="table-content">
            <table class="users-table">
                <thead>
                    <tr>
                        <th class="checkbox-cell">
                            <input type="checkbox" id="selectAllTable" onchange="toggleSelectAll()">
                        </th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Active Sessions</th>
                        <th>Last Login</th>
                        <th>Total Activities</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    ${users.map(user => `
                        <tr>
                            <td class="checkbox-cell">
                                <input type="checkbox" class="user-checkbox" value="${user.User_ID}" onchange="toggleUserSelection(${user.User_ID})">
                            </td>
                            <td>
                                <div class="user-info">
                                    <div class="user-avatar">${user.User_name.charAt(0).toUpperCase()}</div>
                                    <div class="user-details">
                                        <h4>${user.User_name}</h4>
                                        <p>ID: ${user.User_ID}</p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="role-badge">${user.Role_name || 'No Role'}</span>
                            </td>
                            <td>
                                <span class="status-badge status-${user.Status}">${user.Status}</span>
                            </td>
                            <td>${user.active_sessions || 0}</td>
                            <td>${formatDateTime(user.last_login)}</td>
                            <td>${user.total_activities || 0}</td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn btn-primary btn-sm" onclick="viewUserDetails(${user.User_ID})" title="View Details">👁️</button>
                                    <button class="btn btn-success btn-sm" onclick="openEditUserModal(${user.User_ID})" title="Edit User">✏️</button>
                                    <button class="btn btn-warning btn-sm" onclick="resetPassword(${user.User_ID})" title="Reset Password">🔑</button>
                                    <button class="btn btn-danger btn-sm" onclick="handleDeleteUser(${user.User_ID})" title="Delete User">🗑️</button>
                                </div>
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
    
    content.innerHTML = tableHTML;
}

function updateStatistics(stats) {
    document.getElementById('totalUsers').textContent = stats.total_users || 0;
    document.getElementById('activeUsers').textContent = stats.active_users || 0;
    document.getElementById('inactiveUsers').textContent = stats.inactive_users || 0;
    document.getElementById('uniqueRoles').textContent = stats.unique_roles || 0;
}

function updatePagination(pagination) {
    // You could add pagination controls here if needed
}

function toggleSelectAll() {
    const selectAll = document.getElementById('selectAllTable');
    const checkboxes = document.querySelectorAll('.user-checkbox');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
        if (selectAll.checked) {
            selectedUsers.add(parseInt(checkbox.value));
        } else {
            selectedUsers.delete(parseInt(checkbox.value));
        }
    });
}

function toggleUserSelection(userId) {
    if (selectedUsers.has(userId)) {
        selectedUsers.delete(userId);
    } else {
        selectedUsers.add(userId);
    }
}

async function bulkAction(action) {
    if (selectedUsers.size === 0) {
        alert('Please select users first');
        return;
    }

    if (!confirm(`Are you sure you want to ${action} ${selectedUsers.size} user(s)?`)) {
        return;
    }

    try {
        const data = await bulkUserAction(action, Array.from(selectedUsers));
        
        if (data.success) {
            alert(data.message);
            selectedUsers.clear();
            loadUsers();
        } else {
            alert(data.error || 'Failed to perform bulk action');
        }
    } catch (error) {
        console.error('Error performing bulk action:', error);
        alert('Failed to perform bulk action');
    }
}

function openCreateUserModal() {
    document.getElementById('modalTitle').textContent = 'Add New User';
    document.getElementById('userForm').reset();
    currentUserId = null;
    // Show password field and make required when creating
    const pwd = document.getElementById('password');
    pwd.required = true;
    const pwdGroup = pwd.closest('.form-group');
    if (pwdGroup) pwdGroup.style.display = '';
    document.getElementById('userModal').style.display = 'block';
}

async function openEditUserModal(userId) {
    try {
        const resp = await getUserDetails(userId);
        if (!resp.success) {
            alert(resp.error || 'Failed to load user');
            return;
        }
        const u = resp.data.user;
        currentUserId = u.User_ID;
        document.getElementById('modalTitle').textContent = 'Edit User';
        document.getElementById('username').value = u.User_name || '';
        document.getElementById('roleId').value = u.Role_ID || '';
        document.getElementById('status').value = u.Status || 'active';
        // Hide password field for edit and not required
        const pwd = document.getElementById('password');
        pwd.required = false;
        const pwdGroup = pwd.closest('.form-group');
        if (pwdGroup) pwdGroup.style.display = 'none';
        document.getElementById('userModal').style.display = 'block';
    } catch (e) {
        console.error('Failed to open edit modal:', e);
        alert('Failed to open edit modal');
    }
}

function viewUserDetails(userId) {
    currentUserId = userId;
    document.getElementById('userDetailsModal').style.display = 'block';
    loadUserDetails(userId);
}

async function loadUserDetails(userId) {
    try {
        const data = await getUserDetails(userId);
        
        if (data.success) {
            displayUserDetails(data.data);
        } else {
            showError(data.error || 'Failed to load user details');
        }
    } catch (error) {
        console.error('Error loading user details:', error);
        showError('Failed to load user details');
    }
}

function displayUserDetails(data) {
    const content = document.getElementById('userDetailsContent');
    const user = data.user;
    const activities = data.activities;
    const sessions = data.sessions;

    content.innerHTML = `
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
            <div>
                <h4>User Information</h4>
                <div class="user-info" style="margin-bottom: 20px;">
                    <div class="user-avatar" style="width: 60px; height: 60px; font-size: 1.5rem;">${user.User_name.charAt(0).toUpperCase()}</div>
                    <div class="user-details">
                        <h3>${user.User_name}</h3>
                        <p><strong>Role:</strong> ${user.Role_name}</p>
                        <p><strong>Status:</strong> <span class="status-badge status-${user.Status}">${user.Status}</span></p>
                        <p><strong>Created:</strong> ${formatDateTime(user.Created_at)}</p>
                    </div>
                </div>
                
                <h4>Recent Activity</h4>
                <div style="max-height: 200px; overflow-y: auto;">
                    ${activities.length > 0 ? activities.map(activity => `
                        <div style="padding: 10px; border-bottom: 1px solid #eee;">
                            <strong>${activity.Activity_type}</strong><br>
                            <small>${formatDateTime(activity.Time)}</small>
                        </div>
                    `).join('') : '<p>No recent activity</p>'}
                </div>
            </div>
            
            <div>
                <h4>Active Sessions</h4>
                <div style="max-height: 200px; overflow-y: auto;">
                    ${sessions.length > 0 ? sessions.map(session => `
                        <div style="padding: 10px; border-bottom: 1px solid #eee;">
                            <strong>Session:</strong> ${session.Session_ID.substring(0, 8)}...<br>
                            <small>Last seen: ${formatDateTime(session.Last_seen)}</small><br>
                            <small>IP: ${session.Ip_Address}</small>
                        </div>
                    `).join('') : '<p>No active sessions</p>'}
                </div>
            </div>
        </div>
    `;
}

function resetPassword(userId) {
    currentUserId = userId;
    document.getElementById('passwordModal').style.display = 'block';
}

async function handleDeleteUser(userId) {
    if (!confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
        return;
    }

    try {
        const data = await window.deleteUser(userId);
        
        if (data.success) {
            alert(data.message || 'User deleted successfully');
            loadUsers();
        } else {
            alert(data.error || 'Failed to delete user');
        }
    } catch (error) {
        console.error('Error deleting user:', error);
        alert('Failed to delete user');
    }
}

// Form submissions
document.getElementById('userForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = {
        username: document.getElementById('username').value,
        role_id: document.getElementById('roleId').value,
        status: document.getElementById('status').value
    };

    try {
        let data;
        if (currentUserId) {
            data = await updateUser({ user_id: currentUserId, ...formData });
        } else {
            data = await createUser({ ...formData, password: document.getElementById('password').value });
        }
        
        if (data.success) {
            alert(currentUserId ? 'User updated successfully' : 'User created successfully');
            closeUserModal();
            loadUsers();
        } else {
            alert(data.error || (currentUserId ? 'Failed to update user' : 'Failed to create user'));
        }
    } catch (error) {
        console.error('Error saving user:', error);
        alert('Failed to save user');
    }
});

document.getElementById('passwordForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const newPassword = document.getElementById('newPassword').value;
    const confirmPassword = document.getElementById('confirmPassword').value;

    if (newPassword !== confirmPassword) {
        alert('Passwords do not match');
        return;
    }

    try {
        const data = await resetUserPassword(currentUserId, newPassword);
        
        if (data.success) {
            alert('Password reset successfully');
            closePasswordModal();
        } else {
            alert(data.error || 'Failed to reset password');
        }
    } catch (error) {
        console.error('Error resetting password:', error);
        alert('Failed to reset password');
    }
});

// Modal functions
function closeUserModal() {
    document.getElementById('userModal').style.display = 'none';
}

function closeUserDetailsModal() {
    document.getElementById('userDetailsModal').style.display = 'none';
}

function closePasswordModal() {
    document.getElementById('passwordModal').style.display = 'none';
}

function showError(message) {
    const content = document.getElementById('usersContent');
    content.innerHTML = `<div class="error-message">${message}</div>`;
}

function formatDateTime(dateTime) {
    if (!dateTime) return 'Never';
    return new Date(dateTime).toLocaleString();
}

// Close modals when clicking outside
window.onclick = function(event) {
    const userModal = document.getElementById('userModal');
    const userDetailsModal = document.getElementById('userDetailsModal');
    const passwordModal = document.getElementById('passwordModal');
    
    if (event.target === userModal) {
        closeUserModal();
    }
    if (event.target === userDetailsModal) {
        closeUserDetailsModal();
    }
    if (event.target === passwordModal) {
        closePasswordModal();
    }
}

// Expose functions for inline handlers used in user-management.html
window.loadUsers = loadUsers;
window.toggleSelectAll = toggleSelectAll;
window.bulkAction = bulkAction;
window.openCreateUserModal = openCreateUserModal;
window.openEditUserModal = openEditUserModal;
window.viewUserDetails = viewUserDetails;
window.resetPassword = resetPassword;
window.handleDeleteUser = handleDeleteUser;