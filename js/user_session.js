  // Debug: Check if functions are loaded
  console.log('getUserSessions available:', typeof getUserSessions);
  console.log('terminateUserSession available:', typeof terminateUserSession);
  console.log('AuthHelper available:', typeof AuthHelper);
  console.log('postJSON available:', typeof postJSON);
  console.log('getJSON available:', typeof getJSON);
  
  // Fallback: Define functions if not loaded from api.js
  if (typeof getUserSessions === 'undefined') {
      console.log('getUserSessions not found, defining fallback');
      window.getUserSessions = async function(filters = {}) {
          const params = new URLSearchParams();
          if (filters.user_id) params.append('user_id', filters.user_id);
          if (filters.status) params.append('status', filters.status);
          if (filters.limit) params.append('limit', filters.limit);
          if (filters.offset) params.append('offset', filters.offset);
          
          const queryString = params.toString();
          const endpoint = queryString ? `../api/user_sessions.php?${queryString}` : '../api/user_sessions.php';
          
          const response = await fetch(endpoint);
          return await response.json();
      };
  }
  
  if (typeof terminateUserSession === 'undefined') {
      console.log('terminateUserSession not found, defining fallback');
      window.terminateUserSession = async function(sessionId) {
          const response = await fetch(`../api/user_sessions.php?session_id=${sessionId}`, {
              method: 'DELETE'
          });
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
              loadSessions();
              
              // Set up periodic session validation
              setInterval(async () => {
                  try {
                      const response = await fetch(`${(window.API_BASE||'../api/')}me.php`);
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

  async function loadSessions() {
      try {
          const userFilter = document.getElementById('userFilter').value;
          const statusFilter = document.getElementById('statusFilter').value;
          
          const filters = {
              limit: pageSize,
              offset: currentPage * pageSize
          };
          
          if (userFilter) filters.user_id = userFilter;
          if (statusFilter) filters.status = statusFilter;

          const data = await getUserSessions(filters);

          if (data.success) {
              displaySessions(data.data);
              updateStatistics(data.statistics);
              updatePagination(data.pagination);
          } else {
              showError(data.error || 'Failed to load sessions');
          }
      } catch (error) {
          console.error('Error loading sessions:', error);
          showError('Failed to load sessions');
      }
  }

  function displaySessions(sessions) {
      const content = document.getElementById('sessionsContent');
      
      if (sessions.length === 0) {
          content.innerHTML = '<div class="no-data">No sessions found</div>';
          return;
      }

      const tableHTML = `
          <div class="table-content">
              <table class="sessions-table">
                  <thead>
                      <tr>
                          <th>User</th>
                          <th>Role</th>
                          <th>Status</th>
                          <th>Session Started</th>
                          <th>Last Activity</th>
                          <th>Duration</th>
                          <th>IP Address</th>
                          <th>User Agent</th>
                          <th>Actions</th>
                      </tr>
                  </thead>
                  <tbody>
                      ${sessions.map(session => `
                          <tr>
                              <td>${session.User_name || 'Unknown'}</td>
                              <td>${session.Role_name || 'Unknown'}</td>
                              <td><span class="status-badge status-${session.session_status}">${session.session_status}</span></td>
                              <td>${formatDateTime(session.Created_at)}</td>
                              <td>${formatDateTime(session.Last_seen)}</td>
                              <td>${formatDuration(session.session_duration_minutes)}</td>
                              <td class="ip-address">${session.Ip_Address || 'N/A'}</td>
                              <td class="user-agent" title="${session.User_Agent || 'N/A'}">${session.User_Agent || 'N/A'}</td>
                              <td>
                                  <button class="action-btn" onclick="terminateSession('${session.Session_ID}')" 
                                          title="Terminate Session">Terminate</button>
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
      document.getElementById('activeSessions').textContent = stats.active_sessions || 0;
      document.getElementById('idleSessions').textContent = stats.idle_sessions || 0;
      document.getElementById('expiredSessions').textContent = stats.expired_sessions || 0;
      document.getElementById('uniqueUsers').textContent = stats.unique_users || 0;
  }

  function updatePagination(pagination) {
      // Simple pagination - could be enhanced with more controls
      const hasMore = pagination.has_more;
      const totalPages = Math.ceil(pagination.total / pageSize);
      
      // You could add pagination controls here if needed
  }

  async function terminateSession(sessionId) {
      if (!confirm('Are you sure you want to terminate this session?')) {
          return;
      }

      try {
          const data = await terminateUserSession(sessionId);

          if (data.success) {
              if (data.current_session_terminated) {
                  alert('Your session has been terminated. You will be redirected to login.');
                  window.location.href = 'login.html';
              } else {
                  alert('Session terminated successfully');
                  loadSessions(); // Refresh the list
              }
          } else {
              alert(data.error || 'Failed to terminate session');
          }
      } catch (error) {
          console.error('Error terminating session:', error);
          alert('Failed to terminate session');
      }
  }

  function showError(message) {
      const content = document.getElementById('sessionsContent');
      content.innerHTML = `<div class="error-message">${message}</div>`;
  }

  function formatDateTime(dateString) {
      if (!dateString) return 'N/A';
      const date = new Date(dateString);
      return date.toLocaleString();
  }

  function formatDuration(minutes) {
      if (!minutes) return 'N/A';
      
      const hours = Math.floor(minutes / 60);
      const mins = minutes % 60;
      
      if (hours > 0) {
          return `${hours}h ${mins}m`;
      } else {
          return `${mins}m`;
      }
  }

  // Event listeners for filters
  document.getElementById('userFilter').addEventListener('change', loadSessions);
  document.getElementById('statusFilter').addEventListener('change', loadSessions);