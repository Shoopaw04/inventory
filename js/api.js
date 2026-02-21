const basePath = '../api/';

// Global API configuration
window.API_BASE = basePath;

/**
 * 
 * @param {string} endpoint - 
 * @param {object} data - 
 * @returns {Promise<object>} -
 */
async function postJSON(endpoint, data) {
  try {
    const res = await fetch(basePath + endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify(data)
    });
    
    // Check if response is ok
    if (!res.ok) {
      return {
        success: false,
        error: `HTTP ${res.status}: ${res.statusText}`
      };
    }
    
    const text = await res.text();
    
    // Check if response looks like PHP source code (not executed)
    if (text.trim().startsWith('<?php') || text.includes('/* -------')) {
      return {
        success: false,
        error: `PHP file '${endpoint}' is not being executed by the server. Make sure you're accessing via http://localhost (not file://) and PHP is enabled.`
      };
    }
    
    // Check if response is HTML error page
    if (text.trim().startsWith('<!DOCTYPE') || text.includes('<html')) {
      return {
        success: false,
        error: `Server returned HTML error page for '${endpoint}'. The API endpoint might not exist.`
      };
    }
    

    try {
      return JSON.parse(text);
    } catch (parseError) {
      console.error('Raw response:', text);
      return {
        success: false,
        error: `Invalid JSON response from '${endpoint}'. Raw response logged to console.`
      };
    }
    
  } catch (error) {
    console.error('POST Error:', error);
    return {
      success: false,
      error: error.message || 'Network error occurred'
    };
  }
}

/**
 * GET JSON data from a PHP endpoint
 * @param {string} endpoint - The PHP file name (e.g., 'inventory.php')
 * @returns {Promise<object>} - Parsed JSON response
 */
async function getJSON(endpoint) {
  try {
    const res = await fetch(basePath + endpoint, {
      credentials: 'include'
    });
    
    // Check if response is ok
    if (!res.ok) {
      return {
        success: false,
        error: `HTTP ${res.status}: ${res.statusText} - Check if ${endpoint} exists`
      };
    }
    
    const text = await res.text();


    if (text.trim().startsWith('<?php') || text.includes('/* -------')) {
      return {
        success: false,
        error: `PHP file '${endpoint}' is not being executed by the server. Make sure you're accessing via http://localhost (not file://) and PHP is enabled.`
      };
    }
    
    // Check if response is HTML error page
    if (text.trim().startsWith('<!DOCTYPE') || text.includes('<html')) {
      return {
        success: false,
        error: `Server returned HTML error page for '${endpoint}'. The API endpoint might not exist.`
      };
    }
   
   
    try {
      return JSON.parse(text);
    } catch (parseError) {
      console.error('Raw response:', text);
      return {
        success: false,
        error: `Invalid JSON response from '${endpoint}'. Raw response logged to console.`
      };
    }
    
  } catch (error) {
    console.error('GET Error:', error);
    return {
      success: false,
      error: error.message || 'Network error occurred'
    };
  }
}

/**
 * Get user sessions with optional filters
 * @param {object} filters - Optional filters (user_id, status, limit, offset)
 * @returns {Promise<object>} - Sessions data with statistics
 */
async function getUserSessions(filters = {}) {
  const params = new URLSearchParams();
  
  if (filters.user_id) params.append('user_id', filters.user_id);
  if (filters.status) params.append('status', filters.status);
  if (filters.limit) params.append('limit', filters.limit);
  if (filters.offset) params.append('offset', filters.offset);
  
  const queryString = params.toString();
  const endpoint = queryString ? `user_sessions.php?${queryString}` : 'user_sessions.php';
  
  return await getJSON(endpoint);
}

/**
 * Terminate a user session
 * @param {string} sessionId - Session ID to terminate
 * @returns {Promise<object>} - Result of termination
 */
async function terminateUserSession(sessionId) {
  try {
    const res = await fetch(basePath + `user_sessions.php?session_id=${sessionId}`, {
      method: 'DELETE',
      credentials: 'include'
    });
    
    if (!res.ok) {
      return {
        success: false,
        error: `HTTP ${res.status}: ${res.statusText}`
      };
    }
    
    const text = await res.text();
    
    if (text.trim().startsWith('<?php') || text.includes('/* -------')) {
      return {
        success: false,
        error: `PHP file 'user_sessions.php' is not being executed by the server.`
      };
    }
    
    if (text.trim().startsWith('<!DOCTYPE') || text.includes('<html')) {
      return {
        success: false,
        error: `Server returned HTML error page for 'user_sessions.php'.`
      };
    }
    
    try {
      return JSON.parse(text);
    } catch (parseError) {
      console.error('Raw response:', text);
      return {
        success: false,
        error: `Invalid JSON response from 'user_sessions.php'. Raw response logged to console.`
      };
    }
    
  } catch (error) {
    console.error('DELETE Error:', error);
    return {
      success: false,
      error: error.message || 'Network error occurred'
    };
  }
}

/**
 * Get activity logs with optional filters
 * @param {object} filters - Optional filters (user_id, activity_type, date_from, date_to, limit, offset)
 * @returns {Promise<object>} - Activity logs data with statistics
 */
async function getActivityLogs(filters = {}) {
  const params = new URLSearchParams();
  
  if (filters.user_id) params.append('user_id', filters.user_id);
  if (filters.activity_type) params.append('activity_type', filters.activity_type);
  if (filters.date_from) params.append('date_from', filters.date_from);
  if (filters.date_to) params.append('date_to', filters.date_to);
  if (filters.limit) params.append('limit', filters.limit);
  if (filters.offset) params.append('offset', filters.offset);
  
  const queryString = params.toString();
  const endpoint = queryString ? `activity_logs.php?${queryString}` : 'activity_logs.php';
  
  return await getJSON(endpoint);
}

/**
 * Get users list with optional filters
 * @param {object} filters - Optional filters (search, role, status, limit, offset)
 * @returns {Promise<object>} - Users data with statistics
 */
async function getUsers(filters = {}) {
  const params = new URLSearchParams();
  
  if (filters.search) params.append('search', filters.search);
  if (filters.role) params.append('role', filters.role);
  if (filters.status) params.append('status', filters.status);
  if (filters.limit) params.append('limit', filters.limit);
  if (filters.offset) params.append('offset', filters.offset);
  
  const queryString = params.toString();
  const endpoint = queryString ? `user_management.php?${queryString}` : 'user_management.php';
  
  return await getJSON(endpoint);
}

/**
 * Get available roles
 * @returns {Promise<object>} - Roles data
 */
async function getRoles() {
  return await getJSON('user_management.php?action=roles');
}

/**
 * Get available permissions
 * @returns {Promise<object>} - Permissions data
 */
async function getPermissions() {
  return await getJSON('user_management.php?action=permissions');
}

/**
 * Get user details with activities and sessions
 * @param {number} userId - User ID
 * @returns {Promise<object>} - User details data
 */
async function getUserDetails(userId) {
  return await getJSON(`user_management.php?action=user_details&user_id=${userId}`);
}

/**
 * Create a new user
 * @param {object} userData - User data (username, password, role_id, status)
 * @returns {Promise<object>} - Result of user creation
 */
async function createUser(userData) {
  return await postJSON('user_management.php', {
    action: 'create_user',
    ...userData
  });
}

/**
 * Update user information
 * @param {object} userData - User data (user_id, username, role_id, status)
 * @returns {Promise<object>} - Result of user update
 */
async function updateUser(userData) {
  return await postJSON('user_management.php', {
    action: 'update_user',
    ...userData
  });
}

/**
 * Reset user password
 * @param {number} userId - User ID
 * @param {string} newPassword - New password
 * @returns {Promise<object>} - Result of password reset
 */
async function resetUserPassword(userId, newPassword) {
  return await postJSON('user_management.php', {
    action: 'reset_password',
    user_id: userId,
    new_password: newPassword
  });
}

/**
 * Delete a user
 * @param {number} userId - User ID
 * @returns {Promise<object>} - Result of user deletion
 */
async function deleteUser(userId) {
  try {
    const res = await fetch(basePath + `user_management.php?user_id=${userId}`, {
      method: 'DELETE',
      credentials: 'include'
    });
    
    if (!res.ok) {
      return {
        success: false,
        error: `HTTP ${res.status}: ${res.statusText}`
      };
    }
    
    const text = await res.text();
    
    if (text.trim().startsWith('<?php') || text.includes('/* -------')) {
      return {
        success: false,
        error: `PHP file 'user_management.php' is not being executed by the server.`
      };
    }
    
    if (text.trim().startsWith('<!DOCTYPE') || text.includes('<html')) {
      return {
        success: false,
        error: `Server returned HTML error page for 'user_management.php'.`
      };
    }
    
    try {
      return JSON.parse(text);
    } catch (parseError) {
      console.error('Raw response:', text);
      return {
        success: false,
        error: `Invalid JSON response from 'user_management.php'. Raw response logged to console.`
      };
    }
    
  } catch (error) {
    console.error('DELETE Error:', error);
    return {
      success: false,
      error: error.message || 'Network error occurred'
    };
  }
}

/**
 * Perform bulk action on users
 * @param {string} action - Bulk action (activate, deactivate, delete)
 * @param {array} userIds - Array of user IDs
 * @returns {Promise<object>} - Result of bulk action
 */
async function bulkUserAction(action, userIds) {
  return await postJSON('user_management.php', {
    action: 'bulk_action',
    bulk_action: action,
    user_ids: userIds
  });
}

// Make globally accessible to HTML scripts
console.log('api.js: Defining global functions...');
window.postJSON = postJSON;
window.getJSON = getJSON;
console.log('api.js: postJSON and getJSON defined');
window.getUserSessions = getUserSessions;
console.log('api.js: getUserSessions defined');
window.terminateUserSession = terminateUserSession;
console.log('api.js: terminateUserSession defined');
window.getActivityLogs = getActivityLogs;
console.log('api.js: getActivityLogs defined');
window.getUsers = getUsers;
console.log('api.js: getUsers defined');
window.getRoles = getRoles;
console.log('api.js: getRoles defined');
window.getPermissions = getPermissions;
console.log('api.js: getPermissions defined');
window.getUserDetails = getUserDetails;
console.log('api.js: getUserDetails defined');
window.createUser = createUser;
console.log('api.js: createUser defined');
window.updateUser = updateUser;
console.log('api.js: updateUser defined');
window.resetUserPassword = resetUserPassword;
console.log('api.js: resetUserPassword defined');
window.deleteUser = deleteUser;
console.log('api.js: deleteUser defined');
window.bulkUserAction = bulkUserAction;
console.log('api.js: bulkUserAction defined');
/**
 * Get audit logs
 * @param {object} filters - Optional filters (user_id, entity, action_type, date_from, date_to, limit, offset)
 */
async function getAuditLogs(filters = {}) {
  const params = new URLSearchParams();
  if (filters.user_id) params.append('user_id', filters.user_id);
  if (filters.entity) params.append('entity', filters.entity);
  if (filters.action_type) params.append('action_type', filters.action_type);
  if (filters.date_from) params.append('date_from', filters.date_from);
  if (filters.date_to) params.append('date_to', filters.date_to);
  if (filters.limit) params.append('limit', filters.limit);
  if (filters.offset) params.append('offset', filters.offset);
  const qs = params.toString();
  const endpoint = qs ? `audit_logs.php?${qs}` : 'audit_logs.php';
  return await getJSON(endpoint);
}

window.getAuditLogs = getAuditLogs;
console.log('api.js: getAuditLogs defined');
console.log('api.js: All functions defined successfully');