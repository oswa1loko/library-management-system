<?php
$sidebarPage = isset($sidebarPage) ? (string) $sidebarPage : 'dashboard';
?>
<aside class="panel member-sidebar">
  <div class="member-sidebar-head">
    <div class="member-sidebar-toggle" aria-hidden="true">
      <span class="dashboard-icon icon-view" aria-hidden="true"></span>
      <span class="member-sidebar-label">Admin Menu</span>
    </div>
  </div>
  <p class="member-sidebar-section member-sidebar-label">Workspace</p>
  <nav class="member-sidebar-nav">
    <a class="member-sidebar-link <?php echo $sidebarPage === 'dashboard' ? 'is-active' : ''; ?>" href="/librarymanage/admin/dashboard.php" data-tooltip="Dashboard">
      <span class="dashboard-icon icon-view" aria-hidden="true"></span>
      <span class="member-sidebar-label">Dashboard</span>
    </a>
    <a class="member-sidebar-link <?php echo $sidebarPage === 'accounts' ? 'is-active' : ''; ?>" href="/librarymanage/admin/manage_accounts.php" data-tooltip="Accounts">
      <span class="dashboard-icon icon-edit" aria-hidden="true"></span>
      <span class="member-sidebar-label">Accounts</span>
    </a>
    <a class="member-sidebar-link <?php echo $sidebarPage === 'payments' ? 'is-active' : ''; ?>" href="/librarymanage/admin/payments_records.php" data-tooltip="Payments">
      <span class="dashboard-icon icon-payments" aria-hidden="true"></span>
      <span class="member-sidebar-label">Payments</span>
    </a>
    <a class="member-sidebar-link <?php echo $sidebarPage === 'penalties' ? 'is-active' : ''; ?>" href="/librarymanage/admin/penalties_records.php" data-tooltip="Penalties">
      <span class="dashboard-icon icon-penalties" aria-hidden="true"></span>
      <span class="member-sidebar-label">Penalties</span>
    </a>
    <a class="member-sidebar-link <?php echo $sidebarPage === 'complaints' ? 'is-active' : ''; ?>" href="/librarymanage/admin/complaints_records.php" data-tooltip="Complaints">
      <span class="dashboard-icon icon-notes" aria-hidden="true"></span>
      <span class="member-sidebar-label">Complaints</span>
    </a>
    <a class="member-sidebar-link <?php echo $sidebarPage === 'notifications' ? 'is-active' : ''; ?>" href="/librarymanage/admin/notifications.php" data-tooltip="Notifications">
      <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
      <span class="member-sidebar-label">Notifications</span>
    </a>
    <a class="member-sidebar-link <?php echo $sidebarPage === 'audit' ? 'is-active' : ''; ?>" href="/librarymanage/admin/audit_logs.php" data-tooltip="Audit Logs">
      <span class="dashboard-icon icon-checklist" aria-hidden="true"></span>
      <span class="member-sidebar-label">Audit Logs</span>
    </a>
    <a class="member-sidebar-link <?php echo $sidebarPage === 'backup' ? 'is-active' : ''; ?>" href="/librarymanage/admin/backup_restore.php" data-tooltip="Backup">
      <span class="dashboard-icon icon-tools" aria-hidden="true"></span>
      <span class="member-sidebar-label">Backup</span>
    </a>
  </nav>
  <p class="member-sidebar-section member-sidebar-label">Account</p>
  <div class="topbar-nav member-sidebar-utilities">
    <a class="member-sidebar-link" href="/librarymanage/index.php" data-tooltip="Home">
      <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
      <span class="member-sidebar-label">Home</span>
    </a>
    <a class="member-sidebar-link" href="/librarymanage/logout.php" data-tooltip="Logout">
      <span class="dashboard-icon icon-logout" aria-hidden="true"></span>
      <span class="member-sidebar-label">Logout</span>
    </a>
  </div>
</aside>
