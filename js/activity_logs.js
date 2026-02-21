// Debug: Check if functions are loaded
console.log('getActivityLogs available:', typeof getActivityLogs);
console.log('AuthHelper available:', typeof AuthHelper);
console.log('postJSON available:', typeof postJSON);
console.log('getJSON available:', typeof getJSON);

// Fallback: Define function if not loaded from api.js
if (typeof getActivityLogs === 'undefined') {
    console.log('getActivityLogs not found, defining fallback');
    window.getActivityLogs = async function(filters = {}) {
        const params = new URLSearchParams();
        if (filters.user_id) params.append('user_id', filters.user_id);
        if (filters.activity_type) params.append('activity_type', filters.activity_type);
        if (filters.date_from) params.append('date_from', filters.date_from);
        if (filters.date_to) params.append('date_to', filters.date_to);
        if (filters.limit) params.append('limit', filters.limit);
        if (filters.offset) params.append('offset', filters.offset);
        const queryString = params.toString();
        const endpoint = queryString ? `${(window.API_BASE||'../api/')}activity_logs.php?${queryString}&_t=${Date.now()}` : `${(window.API_BASE||'../api/')}activity_logs.php?_t=${Date.now()}`;
        const response = await fetch(endpoint, { credentials: 'include' });
        return await response.json();
    };
}
let currentPage = 0;
const pageSize = 50;

// Check authentication and role
document.addEventListener('DOMContentLoaded', async function() {
    try {
        const user = await AuthHelper.requireAuthAndRole(['Admin']);
        if (user) {
            loadActivityLogs();
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

async function loadActivityLogs() {
    try {
        const userFilter = document.getElementById('userFilter').value;
        const activityFilter = document.getElementById('activityFilter').value;
        const dateFrom = document.getElementById('dateFrom').value;
        const dateTo = document.getElementById('dateTo').value;
        const filters = { limit: pageSize, offset: currentPage * pageSize };
        if (userFilter) filters.user_id = userFilter;
        if (activityFilter) filters.activity_type = activityFilter;
        if (dateFrom) filters.date_from = dateFrom;
        if (dateTo) filters.date_to = dateTo;
        const data = await getActivityLogs(filters);
        if (data.success) {
            displayActivityLogs(data.data);
            updateStatistics(data.statistics);
            updateBreakdowns(data.activity_breakdown, data.user_breakdown);
            updatePagination(data.pagination);
        } else {
            showError(data.error || 'Failed to load activity logs');
        }
    } catch (error) {
        console.error('Error loading activity logs:', error);
        showError('Failed to load activity logs');
    }
}

function displayActivityLogs(activities) {
    const content = document.getElementById('logsContent');
    if (!activities || activities.length === 0) {
        content.innerHTML = '<div class="no-data">No activity logs found</div>';
        return;
    }
    const tableHTML = `
        <div class="table-content">
            <table class="logs-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Activity Type</th>
                    </tr>
                </thead>
                <tbody>
                    ${activities.map(activity => `
                        <tr>
                            <td>${formatDateTime(activity.Time)}</td>
                            <td>${activity.User_name || 'Unknown'}</td>
                            <td>${activity.Role_name || 'Unknown'}</td>
                            <td><span class="activity-type">${activity.Activity_type || 'N/A'}</span></td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
    content.innerHTML = tableHTML;
}

function updateStatistics(stats) {
    document.getElementById('totalActivities').textContent = stats.total_activities || 0;
    document.getElementById('activitiesToday').textContent = stats.activities_today || 0;
    document.getElementById('activitiesWeek').textContent = stats.activities_week || 0;
    document.getElementById('activitiesMonth').textContent = stats.activities_month || 0;
    document.getElementById('uniqueUsers').textContent = stats.unique_users || 0;
}

function updateBreakdowns(activityBreakdown, userBreakdown) {
    const activityList = document.getElementById('activityBreakdown');
    if (activityBreakdown && activityBreakdown.length > 0) {
        activityList.innerHTML = activityBreakdown.map(item => `
            <li class="breakdown-item">
                <span class="breakdown-label">${item.Activity_type}</span>
                <span class="breakdown-count">${item.count}</span>
            </li>
        `).join('');
    } else {
        activityList.innerHTML = '<li class="breakdown-item"><span class="breakdown-label">No data available</span></li>';
    }
    const userList = document.getElementById('userBreakdown');
    if (userBreakdown && userBreakdown.length > 0) {
        userList.innerHTML = userBreakdown.map(item => `
            <li class="breakdown-item">
                <span class="breakdown-label">${item.User_name} (${item.Role_name})</span>
                <span class="breakdown-count">${item.activity_count}</span>
            </li>
        `).join('');
    } else {
        userList.innerHTML = '<li class="breakdown-item"><span class="breakdown-label">No data available</span></li>';
    }
}

function updatePagination(pagination) {
    const hasMore = pagination.has_more;
    const totalPages = Math.ceil(pagination.total / pageSize);
}

function showError(message) {
    const content = document.getElementById('logsContent');
    content.innerHTML = `<div class="error-message">${message}</div>`;
}

function formatDateTime(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleString();
}

document.getElementById('userFilter').addEventListener('change', loadActivityLogs);
document.getElementById('activityFilter').addEventListener('change', loadActivityLogs);
document.getElementById('dateFrom').addEventListener('change', loadActivityLogs);
document.getElementById('dateTo').addEventListener('change', loadActivityLogs);

document.addEventListener('DOMContentLoaded', function() {
    const today = new Date();
    const weekAgo = new Date(today.getTime() - 7 * 24 * 60 * 60 * 1000);
    document.getElementById('dateTo').value = today.toISOString().split('T')[0];
    document.getElementById('dateFrom').value = weekAgo.toISOString().split('T')[0];
});

