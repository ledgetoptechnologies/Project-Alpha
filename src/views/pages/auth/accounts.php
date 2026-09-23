<?php
// src/views/pages/auth/accounts.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/acl.php';

// Ensure user is logged in and is an admin
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    echo '<section><div style="padding:20px;background:#fff1f2;color:#881337;border:1px solid #fca5a5;border-radius:8px;margin:16px 0">';
    echo '<h3 style="margin:0 0 8px">Access Denied</h3>';
    echo '<p style="margin:0">You must be an admin to manage accounts. <a href="/">Return to Dashboard</a></p>';
    echo '</div></section>';
    return;
}

$csrf = csrf_token();
$activeProjects = [];
try {
    $projectStmt = $pdo->query("SELECT id,name FROM projects WHERE status NOT IN ('completed','cancelled') ORDER BY name");
    $activeProjects = $projectStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    @error_log('[accounts] project load failed: ' . $e->getMessage());
}

// Fetch all users
try {
    $stmt = $pdo->query("SELECT u.id,u.email,u.username,u.role,u.is_disabled,u.force_password_reset,u.created_at,
        tm.id team_member_id,tm.profile_source,tm.last_synced_at,ep.employment_status
        FROM users u
        LEFT JOIN team_members tm ON tm.user_id=u.id
        LEFT JOIN employee_profiles ep ON ep.user_id=u.id
        ORDER BY u.created_at DESC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $stmt = $pdo->query('SELECT id, email, username, role, is_disabled, force_password_reset, created_at FROM users ORDER BY created_at DESC');
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($users as &$fallbackUser){$fallbackUser['team_member_id']=null;$fallbackUser['profile_source']='pa';$fallbackUser['last_synced_at']=null;$fallbackUser['employment_status']=null;}unset($fallbackUser);
}

// Canonical permission catalog for the create-page grid
require_once __DIR__ . '/../../../utils/permission_catalog.php';
$permissionGroupsCreate = permission_catalog();

$availableRoles = [];
try {
    $roleStmt = $pdo->query('SELECT id, name, description, is_system, organization_id FROM roles WHERE organization_id IS NULL OR is_system = 1 ORDER BY CASE name WHEN "member" THEN 0 WHEN "staff" THEN 1 WHEN "owner" THEN 2 WHEN "admin" THEN 3 ELSE 4 END, is_system DESC, name');
    $availableRoles = $roleStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    @error_log('[accounts] role load failed: ' . $e->getMessage());
}

if (empty($availableRoles)) {
    $availableRoles = [
        ['id' => 0, 'name' => 'member', 'description' => 'Default user access.', 'is_system' => 1, 'organization_id' => null],
        ['id' => -1, 'name' => 'admin', 'description' => 'Full administrative access.', 'is_system' => 1, 'organization_id' => null],
    ];
}

$defaultCreateRoleId = (int)($availableRoles[0]['id'] ?? 0);
foreach ($availableRoles as $roleRow) {
    if (($roleRow['name'] ?? '') === 'member') {
        $defaultCreateRoleId = (int)$roleRow['id'];
        break;
    }
}

$roleDefaults = [];
$roleMeta = [];
$flatCreatePerms = [];
foreach ($permissionGroupsCreate as $group => $permissions) {
    foreach ($permissions as $perm) {
        $flatCreatePerms[$perm] = true;
    }
}

foreach ($availableRoles as $roleRow) {
    $roleId = (int)$roleRow['id'];
    $roleName = (string)$roleRow['name'];
    $roleMeta[(string)$roleId] = [
        'name' => $roleName,
        'isAdmin' => $roleName === 'admin',
        'isEmployee' => $roleName === 'employee',
    ];
    $roleDefaults[(string)$roleId] = [];
    foreach ($flatCreatePerms as $perm => $_) {
        $roleDefaults[(string)$roleId][$perm] = $roleName === 'admin';
    }
}

try {
    $ids = array_values(array_filter(array_map(static fn($r) => (int)$r['id'], $availableRoles), static fn($id) => $id > 0));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rpStmt = $pdo->prepare("SELECT role_id, permission, allowed FROM role_permissions WHERE role_id IN ($placeholders)");
        $rpStmt->execute($ids);
        $rawRolePerms = [];
        foreach ($rpStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rawRolePerms[(int)$row['role_id']][(string)$row['permission']] = (bool)$row['allowed'];
        }
        foreach ($availableRoles as $roleRow) {
            $roleId = (int)$roleRow['id'];
            $roleName = (string)$roleRow['name'];
            if ($roleName === 'admin') {
                continue;
            }
            foreach ($flatCreatePerms as $perm => $_) {
                $module = explode('.', $perm, 2)[0] ?? $perm;
                $roleDefaults[(string)$roleId][$perm] =
                    $rawRolePerms[$roleId][$perm]
                    ?? $rawRolePerms[$roleId][$module . '.*']
                    ?? false;
            }
        }
    }
} catch (Throwable $e) {
    @error_log('[accounts] role defaults load failed: ' . $e->getMessage());
}

// Build member-role defaults map for the create-page JS UX hint.
// Fallback: core modules get true, everything else false.
$memberDefaults = [];
try {
    $stmt = $pdo->prepare("SELECT rp.permission, rp.allowed FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.name = 'member' AND r.is_system = 1");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rows)) {
        foreach ($rows as $row) {
            $memberDefaults[$row['permission']] = (bool)$row['allowed'];
        }
    }
} catch (Throwable $e) {
    $rows = [];
}

if (empty($memberDefaults)) {
    $memberTrueGroups = ['Quotes', 'Contracts', 'Invoices', 'Clients', 'Projects', 'Jobs', 'Organizations', 'Public Links'];
    foreach ($permissionGroupsCreate as $group => $permissions) {
        foreach ($permissions as $perm) {
            $memberDefaults[$perm] = in_array($group, $memberTrueGroups, true);
        }
    }
}

if (!isset($roleDefaults[(string)$defaultCreateRoleId]) || empty($roleDefaults[(string)$defaultCreateRoleId])) {
    $roleDefaults[(string)$defaultCreateRoleId] = $memberDefaults;
}
?>
<section>
  <h2>Account Management</h2>

  <?php if (isset($_GET['created']) && $_GET['created'] === '1'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">User created successfully.</div>
  <?php elseif (isset($_GET['deleted']) && $_GET['deleted'] === '1'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">User deleted successfully.</div>
  <?php elseif (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">User updated successfully.</div>
  <?php elseif (isset($_GET['pwd_reset']) && $_GET['pwd_reset'] === '1'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Password reset successfully.</div>
  <?php elseif (isset($_GET['disabled']) && $_GET['disabled'] === '1'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">User disabled successfully.</div>
  <?php elseif (isset($_GET['enabled']) && $_GET['enabled'] === '1'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">User enabled successfully.</div>
  <?php elseif (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>

  <?php if (!isset($_GET['action']) || $_GET['action'] !== 'create'): ?>
  <div style="display:flex;justify-content:space-between;align-items:center;margin:16px 0">
    <p style="color:#6b7280">Manage user accounts, roles, and permissions</p>
    <a href="/?page=accounts&action=create" style="padding:10px 16px;border-radius:8px;background:var(--nav-accent);color:#fff;text-decoration:none;font-weight:600">+ Create User</a>
  </div>
  <?php endif; ?>

  <?php if (isset($_GET['action']) && $_GET['action'] === 'create'): ?>
    <!-- Create User Form -->
    <style>
      .pa-create-layout { max-width: 980px; }
      .pa-create-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; }
      .pa-create-card h3 { margin: 0 0 16px 0; }
      .pa-create-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
      .pa-create-grid label { display: flex; flex-direction: column; gap: 4px; }
      .pa-create-grid input,
      .pa-create-grid select { width: 100%; box-sizing: border-box; }
      .pa-employee-projects { display: grid; gap: 10px; margin-top: 14px; }
      .pa-employee-project { display: grid; grid-template-columns: minmax(220px, 1fr) 180px; gap: 16px; align-items: center; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; }
      .pa-employee-project > label { display: flex; flex-direction: row; align-items: center; gap: 8px; }
      #employee-profile-panel[hidden] { display: none; }
      .pa-create-actionbar { display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap; }
      #permissions-panel {
        transition: opacity 180ms ease, max-height 220ms ease, padding 180ms ease, margin 180ms ease, border-width 180ms ease;
        max-height: 5000px;
        overflow: hidden;
      }
      #permissions-panel.pa-hidden {
        opacity: 0;
        max-height: 0;
        padding-top: 0 !important;
        padding-bottom: 0 !important;
        margin-bottom: 0 !important;
        border-width: 0 !important;
        pointer-events: none;
      }
      @media (max-width: 720px) {
        .pa-create-grid { grid-template-columns: 1fr; }
      }
    </style>

    <script>
      window.PA_USER_DEFAULTS = <?php echo json_encode($memberDefaults, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
      window.PA_ROLE_DEFAULTS = <?php echo json_encode($roleDefaults, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
      window.PA_ROLE_META = <?php echo json_encode($roleMeta, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
      // INTENTIONAL: UX hint only. Enforcement is server-side in acl_middleware.php.
    </script>

    <div class="pa-create-layout">
      <h3 style="margin-top:0">Create New User</h3>

      <form method="post" action="/?page=accounts-create" id="create-account">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">

        <div class="pa-create-card" style="margin-bottom:16px;">
          <h3>Account Details</h3>
          <div class="pa-create-grid">
            <label>
              <span style="font-weight:600">Email *</span>
              <input required type="email" name="email" class="input" placeholder="user@example.com">
            </label>

            <label>
              <span style="font-weight:600">Username</span>
              <input type="text" name="username" class="input" placeholder="Optional">
            </label>

            <label>
              <span style="font-weight:600">Role *</span>
              <select required name="role_id" id="account-role-select" class="input">
                <?php foreach ($availableRoles as $roleRow): ?>
                  <?php
                    $roleId = (int)$roleRow['id'];
                    $roleName = (string)$roleRow['name'];
                    $roleLabel = ucwords(str_replace('_', ' ', $roleName));
                    $roleScope = (int)($roleRow['is_system'] ?? 0) === 1 ? 'System' : 'Custom';
                  ?>
                  <option value="<?php echo $roleId; ?>" <?php echo $roleId === $defaultCreateRoleId ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($roleLabel . ' (' . $roleScope . ')'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label>
              <span style="font-weight:600">Password *</span>
              <input required minlength="8" type="password" name="password" class="input" placeholder="Min 8 characters">
            </label>
          </div>

          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:16px;">
            <input type="checkbox" name="force_reset" value="1">
            <span>Force password change on first login</span>
          </label>

          <label style="display:block;margin-top:16px;">
            <span style="display:block;font-weight:600;margin-bottom:4px;">New worker compensation policy</span>
            <select name="worker_compensation_policy" class="input">
              <option value="needs_setup">Needs setup (recommended)</option>
              <option value="rules">Pay using compensation rules</option>
              <option value="nonpayable">Do not create compensation</option>
            </select>
            <small>Used when the selected role creates a worker profile. Account access does not determine pay.</small>
          </label>

        </div>

        <div id="employee-profile-panel" class="pa-create-card" style="margin-bottom:16px;" hidden>
          <h3>Employee Profile</h3>
          <p style="margin:0 0 16px;color:#6b7280;font-size:14px;">The Employee role applies the self-service Workforce ACL. Direct client and invoice information stays hidden; employees only see projects assigned here.</p>
          <div class="pa-create-grid">
            <label>
              <span style="font-weight:600">First name *</span>
              <input type="text" name="employee_first_name" class="input" data-employee-required autocomplete="given-name">
            </label>
            <label>
              <span style="font-weight:600">Last name</span>
              <input type="text" name="employee_last_name" class="input" autocomplete="family-name">
            </label>
            <label>
              <span style="font-weight:600">Hourly pay rate</span>
              <input type="number" name="employee_hourly_rate" class="input" min="0" step="0.0001" placeholder="Use business fallback">
            </label>
            <label style="display:flex;flex-direction:row;align-items:center;gap:8px;align-self:end;padding-bottom:10px;">
              <input type="checkbox" name="employee_can_view_pay" value="1" checked style="width:auto">
              <span>Employee can view their pay accruals</span>
            </label>
          </div>
          <h4 style="margin:20px 0 6px">Project assignments</h4>
          <p style="margin:0;color:#6b7280;font-size:13px;">Only assigned projects appear in the employee's time tracker. A project-specific pay rate is optional.</p>
          <div class="pa-employee-projects">
            <?php foreach ($activeProjects as $project): ?>
              <div class="pa-employee-project">
                <label><input type="checkbox" name="employee_project_ids[]" value="<?php echo (int)$project['id']; ?>" style="width:auto"><span><?php echo htmlspecialchars((string)$project['name']); ?></span></label>
                <input type="number" name="employee_project_rates[<?php echo (int)$project['id']; ?>]" class="input" min="0" step="0.0001" placeholder="Optional pay rate">
              </div>
            <?php endforeach; ?>
            <?php if (!$activeProjects): ?><p style="margin:0;color:#6b7280">No active projects are available.</p><?php endif; ?>
          </div>
        </div>

        <div id="permissions-panel" class="pa-create-card" style="margin-bottom:16px;">
          <h3>Permissions</h3>
          <p style="margin:0 0 16px 0;color:#6b7280;font-size:14px;">Set what this user can access. Admins always have full access — these settings apply to non-admin users only.</p>

          <div id="admin-permissions-note" style="display:none;padding:16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;color:#0c4a6e;">
            <p style="margin:0;">Admins have full access to everything. Per-permission settings don't apply to admin accounts.</p>
          </div>

          <div id="permissions-grid">
            <div style="display:grid;gap:12px;">
              <?php foreach ($permissionGroupsCreate as $group => $permissions): ?>
                <fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:12px;background:#fff;">
                  <legend style="padding:0 6px;font-weight:600;font-size:13px;"><?php echo htmlspecialchars($group); ?></legend>
                  <div style="margin-bottom:8px;display:flex;gap:8px;">
                    <button type="button" onclick="selectAllInSection(this, 'allow')" style="padding:4px 10px;border-radius:4px;border:1px solid #d1d5db;background:#f0fdf4;color:#166534;font-size:12px;cursor:pointer;">Allow All</button>
                    <button type="button" onclick="selectAllInSection(this, 'deny')" style="padding:4px 10px;border-radius:4px;border:1px solid #d1d5db;background:#fef2f2;color:#991b1b;font-size:12px;cursor:pointer;">Deny All</button>
                  </div>
                  <div style="display:grid;gap:6px;grid-template-columns:1fr 1fr 2fr;">
                    <div style="font-size:11px;font-weight:600;color:#6b7280;">Allow</div>
                    <div style="font-size:11px;font-weight:600;color:#6b7280;">Deny</div>
                    <div style="font-size:11px;font-weight:600;color:#6b7280;">Permission</div>
                    <?php foreach ($permissions as $perm): ?>
                      <?php
                      $allowKey = 'allow_' . str_replace('.', '_', $perm);
                      $denyKey  = 'deny_' . str_replace('.', '_', $perm);
                      $label    = ucfirst(str_replace('_', ' ', explode('.', $perm, 2)[1] ?? $perm));
                      $defaultAllowed = !empty($memberDefaults[$perm]);
                      ?>
                      <label style="display:flex;align-items:center;justify-content:center;cursor:pointer;">
                        <input type="checkbox" name="<?php echo htmlspecialchars($allowKey); ?>" value="1" <?php if ($defaultAllowed) echo 'checked'; ?>>
                      </label>
                      <label style="display:flex;align-items:center;justify-content:center;cursor:pointer;">
                        <input type="checkbox" name="<?php echo htmlspecialchars($denyKey); ?>" value="1" <?php if (!$defaultAllowed) echo 'checked'; ?>>
                      </label>
                      <div style="font-size:12px;"><?php echo htmlspecialchars($label); ?> <span style="color:#9ca3af;font-size:11px;">(<?php echo htmlspecialchars($perm); ?>)</span></div>
                    <?php endforeach; ?>
                  </div>
                </fieldset>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="pa-create-actionbar">
          <button type="submit" style="padding:10px 16px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer;">Create User</button>
          <button type="button" id="reset-to-defaults" style="padding:10px 16px;border-radius:8px;border:1px solid #d1d5db;background:#fff;color:#374151;cursor:pointer;">Reset to Defaults</button>
          <a href="/?page=accounts" style="padding:10px 16px;border-radius:8px;border:1px solid #ddd;background:#fff;text-decoration:none;color:#374151;">Cancel</a>
        </div>
      </form>
    </div>

    <script>
    function selectAllInSection(btn, type) {
      var fieldset = btn.closest('fieldset');
      var checkboxes = fieldset.querySelectorAll('input[type="checkbox"]');
      checkboxes.forEach(function(cb) {
        if (type === 'allow' && cb.name.startsWith('allow_')) {
          cb.checked = true;
          var denyName = cb.name.replace('allow_', 'deny_');
          var denyCb = fieldset.querySelector('input[name="' + denyName + '"]');
          if (denyCb) denyCb.checked = false;
        } else if (type === 'deny' && cb.name.startsWith('deny_')) {
          cb.checked = true;
          var allowName = cb.name.replace('deny_', 'allow_');
          var allowCb = fieldset.querySelector('input[name="' + allowName + '"]');
          if (allowCb) allowCb.checked = false;
        }
      });
    }

    function initAccountCreateForm() {
      var roleSelect = document.getElementById('account-role-select');
      var panel = document.getElementById('permissions-panel');
      var grid = document.getElementById('permissions-grid');
      var adminNote = document.getElementById('admin-permissions-note');
      var employeePanel = document.getElementById('employee-profile-panel');
      if (!roleSelect || roleSelect.dataset.accountCreateReady === '1') return;
      roleSelect.dataset.accountCreateReady = '1';

      function selectedRoleMeta() {
        if (!roleSelect || !window.PA_ROLE_META) return {};
        return window.PA_ROLE_META[roleSelect.value] || {};
      }

      function selectedRoleDefaults() {
        if (roleSelect && window.PA_ROLE_DEFAULTS && window.PA_ROLE_DEFAULTS[roleSelect.value]) {
          return window.PA_ROLE_DEFAULTS[roleSelect.value];
        }
        return window.PA_USER_DEFAULTS || {};
      }

      function applyRoleDefaults() {
        var defaults = selectedRoleDefaults();
        Object.keys(defaults).forEach(function(perm) {
          var key = perm.replace(/\./g, '_');
          var allowCb = document.querySelector('input[name="allow_' + key + '"]');
          var denyCb  = document.querySelector('input[name="deny_' + key + '"]');
          if (allowCb && denyCb) {
            var allowed = !!defaults[perm];
            allowCb.checked = allowed;
            denyCb.checked = !allowed;
          }
        });
      }

      function updateForRole() {
        if (!roleSelect || !panel) return;
        var meta = selectedRoleMeta();
        if (employeePanel) {
          employeePanel.hidden = !meta.isEmployee;
          employeePanel.querySelectorAll('[data-employee-required]').forEach(function(input) {
            input.required = !!meta.isEmployee;
          });
        }
        if (meta.isAdmin) {
          panel.classList.add('pa-hidden');
        } else {
          panel.classList.remove('pa-hidden');
          if (adminNote) adminNote.style.display = 'none';
          if (grid) grid.style.display = 'block';
          applyRoleDefaults();
        }
      }

      var resetBtn = document.getElementById('reset-to-defaults');
      if (resetBtn) {
        resetBtn.addEventListener('click', function() {
          applyRoleDefaults();
        });
      }

      // Mutual exclusion: every permission is either allow or deny.
      document.querySelectorAll('input[name^="allow_"]').forEach(function(cb) {
        cb.addEventListener('change', function() {
          var denyName = this.name.replace('allow_', 'deny_');
          var denyCb = document.querySelector('input[name="' + denyName + '"]');
          if (!denyCb) return;
          if (this.checked) {
            denyCb.checked = false;
          } else {
            denyCb.checked = true;
          }
        });
      });
      document.querySelectorAll('input[name^="deny_"]').forEach(function(cb) {
        cb.addEventListener('change', function() {
          var allowName = this.name.replace('deny_', 'allow_');
          var allowCb = document.querySelector('input[name="' + allowName + '"]');
          if (!allowCb) return;
          if (this.checked) {
            allowCb.checked = false;
          } else {
            allowCb.checked = true;
          }
        });
      });

      if (roleSelect) roleSelect.addEventListener('change', function() {
        updateForRole();
      });
      updateForRole();
    }
    initAccountCreateForm.pageInitializerId = 'account-create-form';
    if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
      window.ProjectAlpha.registerPage('accounts', initAccountCreateForm);
    } else if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initAccountCreateForm, { once: true });
    } else {
      initAccountCreateForm();
    }
    </script>
  <?php else: ?>
    <!-- Users List -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
      <table class="pa-table">
        <thead>
          <tr style="background:#f9fafb;border-bottom:1px solid #e5e7eb">
            <th style="padding:12px;text-align:left;font-weight:600">Email</th>
            <th style="padding:12px;text-align:left;font-weight:600">Username</th>
            <th style="padding:12px;text-align:left;font-weight:600">Role</th>
            <th style="padding:12px;text-align:left;font-weight:600">Status</th>
            <th style="padding:12px;text-align:left;font-weight:600">Workforce identity</th>
            <th style="padding:12px;text-align:left;font-weight:600">Created</th>
            <th style="padding:12px;text-align:right;font-weight:600">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $user): ?>
          <tr style="border-bottom:1px solid #e5e7eb">
            <td style="padding:12px"><?php echo htmlspecialchars($user['email']); ?></td>
            <td style="padding:12px"><?php echo htmlspecialchars($user['username'] ?? '-'); ?></td>
            <td style="padding:12px">
              <?php
                $displayRole = $user['role'] === 'user' ? 'member' : $user['role'];
                $displayRoleLabel = ucwords(str_replace('_', ' ', (string)$displayRole));
              ?>
              <span style="padding:4px 8px;border-radius:4px;font-size:12px;font-weight:600;
                <?php echo $user['role'] === 'admin' ? 'background:#dbeafe;color:#1e40af' : 'background:#f3f4f6;color:#374151'; ?>">
                <?php echo htmlspecialchars($displayRoleLabel); ?>
              </span>
            </td>
            <td style="padding:12px">
              <?php if (($user['is_disabled'] ?? 0)): ?>
                <span style="padding:4px 8px;border-radius:4px;font-size:12px;font-weight:600;background:#fee2e2;color:#991b1b">Disabled</span>
              <?php else: ?>
                <span style="padding:4px 8px;border-radius:4px;font-size:12px;font-weight:600;background:#d1fae5;color:#065f46">Active</span>
              <?php endif; ?>
            </td>
            <td style="padding:12px">
              <?php if(($user['role'] ?? '') === 'employee' && !empty($user['employment_status'])): ?>
                <span style="display:inline-flex;padding:4px 8px;border-radius:999px;background:#e0f2fe;color:#075985;font-size:12px;font-weight:700">Workforce employee</span>
                <small style="display:block;margin-top:4px;color:#64748b"><?php echo htmlspecialchars(ucfirst((string)$user['employment_status'])); ?> · uses this PA account for timekeeping</small>
              <?php elseif(!empty($user['team_member_id'])): ?>
                <span style="display:inline-flex;padding:4px 8px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:12px;font-weight:700">PA account</span>
              <?php else: ?>
                <span style="color:#92400e">Team-member record required</span>
              <?php endif; ?>
            </td>
            <td style="padding:12px;color:#6b7280"><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
            <td style="padding:12px;text-align:right">
              <a href="/?page=account-edit&id=<?php echo $user['id']; ?>" data-skip-nav style="padding:6px 12px;border-radius:6px;border:1px solid #ddd;background:#fff;text-decoration:none;color:#374151;font-size:14px">Edit</a>
            </td>
          </tr>
          <?php endforeach; ?>

          <?php if (empty($users)): ?>
          <tr>
            <td colspan="7" style="padding:40px;text-align:center;color:#6b7280">No users found.</td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
