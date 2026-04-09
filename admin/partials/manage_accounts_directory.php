<div class="panel" data-filter-panel data-manage-accounts-shell>
  <div class="toolbar manage-users-toolbar">
    <form method="get" class="toolbar grow manage-users-filters js-auto-submit-filters">
      <div class="grow">
        <label for="search">Search</label>
        <input id="search" name="search" value="<?php echo h($search); ?>" placeholder="Search name, email, or username">
      </div>
      <div>
        <label for="role_filter">Role</label>
        <div class="ui-select-shell">
          <select id="role_filter" name="role" class="ui-select">
            <option value="">All roles</option>
            <?php foreach ($rolesAllowed as $roleOption): ?>
              <option value="<?php echo h($roleOption); ?>" <?php echo $roleFilter === $roleOption ? 'selected' : ''; ?>><?php echo h(role_label($roleOption)); ?></option>
            <?php endforeach; ?>
          </select>
          <span class="ui-select-caret" aria-hidden="true"></span>
        </div>
      </div>
      <div class="inline-actions">
        <a class="button secondary" href="manage_accounts.php" data-manage-accounts-reset>Reset</a>
      </div>
    </form>
  </div>
  <div class="inline-actions chips-row manage-users-summary">
    <span class="chip">Showing role: <?php echo h($roleFilter !== '' ? role_label($roleFilter) : 'All'); ?></span>
    <span class="chip">Search term: <?php echo h($search !== '' ? $search : 'None'); ?></span>
    <span class="chip">Records loaded: <?php echo (int) $users->num_rows; ?></span>
  </div>
  <div class="inline-actions chips-row manage-users-printbar">
    <div class="manage-users-print-control">
      <label for="printAction" class="manage-users-print-label">Print options</label>
      <div class="manage-users-print-shell">
        <select id="printAction" class="manage-users-print-select">
          <option value="">Choose report to print</option>
          <option value="all">Print All Users</option>
          <option value="student">Print Student</option>
          <option value="faculty">Print Faculty</option>
          <option value="librarian">Print Librarian</option>
          <option value="admin">Print Admin</option>
          <option value="selected">Print Selected</option>
        </select>
        <span class="manage-users-print-caret" aria-hidden="true"></span>
      </div>
    </div>
    <span class="muted">Choosing an option opens the matching print preview.</span>
  </div>
  <div class="table-wrap table-wrap-top manage-users-directory-table">
    <table>
      <thead>
        <tr>
          <th><input type="checkbox" id="selectAllUsers" aria-label="Select all users"></th>
          <th>ID</th>
          <th>Full Name</th>
          <th>Email</th>
          <th>Username</th>
          <th>Role</th>
          <th>Status</th>
          <th>Access</th>
          <th>Course</th>
          <th>Created</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($users->num_rows === 0): ?>
          <tr><td colspan="11" class="muted">No users matched your filters.</td></tr>
        <?php endif; ?>
        <?php while ($user = $users->fetch_assoc()): ?>
          <tr>
            <td>
              <input
                type="checkbox"
                class="user-print-check"
                value="<?php echo (int) $user['id']; ?>"
                aria-label="Select <?php echo h($user['fullname']); ?> for printing"
              >
            </td>
            <td><?php echo (int) $user['id']; ?></td>
            <td>
              <strong class="label-block"><?php echo h($user['fullname']); ?></strong>
              <span class="muted">User ID #<?php echo (int) $user['id']; ?></span>
            </td>
            <td><?php echo h($user['email']); ?></td>
            <td><?php echo h($user['username']); ?></td>
            <td><span class="badge manage-users-role-badge"><?php echo h(role_label((string) $user['role'])); ?></span></td>
            <td>
              <?php if ((string) ($user['account_status'] ?? 'active') === 'inactive'): ?>
                <span class="badge complaint-status-badge manage-users-status-badge manage-users-status-badge-inactive">Inactive</span>
              <?php else: ?>
                <span class="badge complaint-status-badge manage-users-status-badge complaint-status-resolved">Active</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ((int) ($user['password_setup_required'] ?? 0) === 1): ?>
                <span class="badge complaint-status-badge manage-users-access-badge manage-users-access-badge-pending">Pending Setup</span>
              <?php else: ?>
                <span class="badge complaint-status-badge manage-users-access-badge manage-users-access-badge-ready">Active</span>
              <?php endif; ?>
            </td>
            <td><?php echo h((string) (($user['role'] ?? '') === 'student' ? ($user['course'] ?? '-') : '-')); ?></td>
            <td><?php echo h(format_display_date((string) $user['created_at'])); ?></td>
            <td>
              <div class="inline-actions manage-users-actions">
                <?php if ((int) ($user['active_borrow_count'] ?? 0) > 0 || (int) ($user['unpaid_penalty_count'] ?? 0) > 0 || (int) ($user['pending_payment_count'] ?? 0) > 0): ?>
                  <span class="chip">Delete locked</span>
                <?php endif; ?>
                <?php if (($user['role'] ?? '') === 'student'): ?>
                  <a class="button secondary" href="view_user.php?id=<?php echo (int) $user['id']; ?>">View Profile</a>
                <?php endif; ?>
                <a class="button secondary" href="edit_user.php?id=<?php echo (int) $user['id']; ?>">Edit</a>
                <form method="post" class="inline-form">
                  <input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>">
                  <input type="hidden" name="status" value="<?php echo (string) ($user['account_status'] ?? 'active') === 'inactive' ? 'active' : 'inactive'; ?>">
                  <?php if ((string) ($user['account_status'] ?? 'active') === 'inactive'): ?>
                    <button type="submit" class="button secondary" name="set_status" value="1">Reactivate</button>
                  <?php else: ?>
                    <button type="submit" class="button secondary" name="set_status" value="1">Deactivate</button>
                  <?php endif; ?>
                </form>
                <?php if ((int) ($user['password_setup_required'] ?? 0) === 1): ?>
                  <form method="post" class="inline-form">
                    <input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>">
                    <button type="submit" class="button secondary" name="send_invite" value="1">Resend Invite</button>
                  </form>
                <?php endif; ?>
                <form method="post" class="inline-form js-confirm-delete-user">
                  <input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>">
                  <button
                    type="submit"
                    class="danger"
                    name="delete"
                    value="1"
                    <?php echo ((int) ($user['active_borrow_count'] ?? 0) > 0 || (int) ($user['unpaid_penalty_count'] ?? 0) > 0 || (int) ($user['pending_payment_count'] ?? 0) > 0) ? 'disabled title="Resolve active borrow, penalty, and payment records first."' : ''; ?>
                  >Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
