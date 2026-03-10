<?php
$sidebarPage = isset($sidebarPage) ? (string) $sidebarPage : 'dashboard';
?>
<aside class="panel member-sidebar">
  <div class="member-sidebar-head">
    <button type="button" class="member-sidebar-toggle js-sidebar-toggle" aria-expanded="true" aria-label="Collapse sidebar">
      <span class="dashboard-icon icon-view" aria-hidden="true"></span>
      <span class="member-sidebar-label">Main Menu</span>
    </button>
  </div>
  <p class="member-sidebar-section member-sidebar-label">Main</p>
  <nav class="member-sidebar-nav">
    <a class="member-sidebar-link <?php echo $sidebarPage === 'dashboard' ? 'is-active' : ''; ?>" href="/librarymanage/librarian/dashboard.php" data-tooltip="Dashboard">
      <span class="dashboard-icon icon-view" aria-hidden="true"></span>
      <span class="member-sidebar-label">Dashboard</span>
    </a>
    <a class="member-sidebar-link <?php echo $sidebarPage === 'borrows' ? 'is-active' : ''; ?>" href="/librarymanage/librarian/manage_borrows.php" data-tooltip="Borrow Desk">
      <span class="dashboard-icon icon-checklist" aria-hidden="true"></span>
      <span class="member-sidebar-label">Borrow Desk</span>
    </a>
    <a class="member-sidebar-link <?php echo $sidebarPage === 'books' ? 'is-active' : ''; ?>" href="/librarymanage/librarian/manage_books.php" data-tooltip="Books">
      <span class="dashboard-icon icon-books" aria-hidden="true"></span>
      <span class="member-sidebar-label">Books</span>
    </a>
    <a class="member-sidebar-link <?php echo $sidebarPage === 'penalties' ? 'is-active' : ''; ?>" href="/librarymanage/librarian/manage_penalties.php" data-tooltip="Penalties">
      <span class="dashboard-icon icon-penalties" aria-hidden="true"></span>
      <span class="member-sidebar-label">Penalties</span>
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
