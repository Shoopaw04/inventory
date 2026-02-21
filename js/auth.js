// Simple frontend auth/role helper
// Uses ../api/me.php and ../api/logout.php

(function() {
  async function fetchJSON(url, options) {
    try {
      const res = await fetch(url, options);
      const text = await res.text();
      try { return JSON.parse(text); } catch (e) { return { success: false, error: 'Invalid JSON' }; }
    } catch (e) {
      return { success: false, error: e.message };
    }
  }

  function normalizeRole(roleName) {
    return (roleName || '').toLowerCase().trim();
  }

  function roleIn(allowedRoles, roleName) {
    const normalized = normalizeRole(roleName);
    const set = new Set(allowedRoles.map(r => normalizeRole(r)));
    return set.has(normalized);
  }

  async function getCurrentUser() {
    const json = await fetchJSON('../api/me.php');
    if (json && json.success && json.data) {
      // Add client-side validation to prevent role switching
      const storedRole = sessionStorage.getItem('userRole');
      const currentRole = json.data.Role_name;
      if (storedRole && storedRole !== currentRole) {
        console.warn('Role mismatch detected, clearing session');
        sessionStorage.clear();
        return null;
      }
      sessionStorage.setItem('userRole', currentRole);
      return json.data;
    }
    return null;
  }

  async function requireAuthAndRole(allowedRoles, redirectTo = 'login.html') {
    const user = await getCurrentUser();
    if (!user) {
      window.location.href = redirectTo;
      return null;
    }
    if (Array.isArray(allowedRoles) && allowedRoles.length > 0) {
      if (!roleIn(allowedRoles, user.Role_name)) {
        alert('Access denied for your role.');
        window.location.href = 'login.html';
        return null;
      }
    }
    return user;
  }

  async function logoutAndRedirect(redirectTo = 'login.html') {
    await fetchJSON('../api/logout.php');
    window.location.href = redirectTo;
  }

  window.AuthHelper = {
    getCurrentUser,
    requireAuthAndRole,
    logoutAndRedirect
  };
})();


