<?php

function book_incident_type_options(): array
{
    return [
        'lost' => 'Lost',
        'damaged' => 'Damaged',
    ];
}

function book_incident_severity_options(): array
{
    return [
        'minor' => 'Minor',
        'major' => 'Major',
        'severe' => 'Severe',
    ];
}

function book_incident_workflow_options(): array
{
    return [
        'reported' => 'Reported',
        'under_review' => 'Under Review',
        'awaiting_settlement' => 'Awaiting Settlement',
        'resolved' => 'Resolved',
        'rejected' => 'Rejected',
    ];
}

function book_incident_resolution_options(): array
{
    return [
        'none' => 'No inventory action yet',
        'return_to_shelf' => 'Return to shelf',
        'send_for_repair' => 'Send for repair',
        'write_off_lost' => 'Write off as lost',
        'write_off_damaged' => 'Write off as damaged',
    ];
}

function book_incident_settlement_options(): array
{
    return [
        'pending' => 'Pending',
        'not_required' => 'Not required',
        'paid' => 'Paid',
        'replacement_submitted' => 'Replacement submitted',
        'waived' => 'Waived',
    ];
}

function book_incident_workflow_label(string $value): string
{
    $options = book_incident_workflow_options();
    return $options[$value] ?? ucfirst(str_replace('_', ' ', trim($value)));
}

function book_incident_type_label(string $value): string
{
    $options = book_incident_type_options();
    return $options[$value] ?? ucfirst(trim($value));
}

function book_incident_severity_label(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '-';
    }

    $options = book_incident_severity_options();
    return $options[$value] ?? ucfirst($value);
}

function book_incident_resolution_label(string $value): string
{
    $options = book_incident_resolution_options();
    return $options[$value] ?? ucfirst(str_replace('_', ' ', trim($value)));
}

function book_incident_settlement_label(string $value): string
{
    $options = book_incident_settlement_options();
    return $options[$value] ?? ucfirst(str_replace('_', ' ', trim($value)));
}

function book_incident_next_actor_label(string $workflowStatus, string $settlementStatus = 'pending'): string
{
    $workflowStatus = trim($workflowStatus);
    $settlementStatus = trim($settlementStatus);

    if ($workflowStatus === 'reported') {
        return 'Next: Librarian review';
    }

    if ($workflowStatus === 'under_review') {
        return 'Next: Librarian decision';
    }

    if ($workflowStatus === 'awaiting_settlement') {
        return $settlementStatus === 'pending'
            ? 'Next: Student payment or admin update'
            : 'Next: Admin closeout';
    }

    if ($workflowStatus === 'resolved') {
        return 'Closed';
    }

    if ($workflowStatus === 'rejected') {
        return 'Closed';
    }

    return 'In progress';
}

function book_incident_status_dot_class(string $value): string
{
    $value = trim($value);
    $map = [
        'reported' => 'pending',
        'under_review' => 'due',
        'awaiting_settlement' => 'warning',
        'resolved' => 'approved',
        'rejected' => 'overdue',
        'paid' => 'approved',
        'replacement_submitted' => 'due',
        'waived' => 'approved',
        'pending' => 'pending',
        'not_required' => 'approved',
    ];

    return $map[$value] ?? 'pending';
}

function book_incident_summary(mysqli $conn): array
{
    $query = $conn->query("
        SELECT
            COUNT(*) AS total_incidents,
            COALESCE(SUM(CASE WHEN workflow_status IN ('reported', 'under_review', 'awaiting_settlement') THEN 1 ELSE 0 END), 0) AS open_incidents,
            COALESCE(SUM(CASE WHEN incident_type = 'lost' THEN 1 ELSE 0 END), 0) AS lost_incidents,
            COALESCE(SUM(CASE WHEN incident_type = 'damaged' THEN 1 ELSE 0 END), 0) AS damaged_incidents,
            COALESCE(SUM(CASE WHEN settlement_status = 'pending' AND assessed_fee > 0 THEN assessed_fee ELSE 0 END), 0) AS pending_fees
        FROM book_incidents
    ");

    return $query instanceof mysqli_result ? ($query->fetch_assoc() ?: []) : [];
}

function member_book_incident_summary(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total_incidents,
            COALESCE(SUM(CASE WHEN workflow_status IN ('reported', 'under_review', 'awaiting_settlement') THEN 1 ELSE 0 END), 0) AS active_incidents,
            COALESCE(SUM(CASE WHEN settlement_status = 'pending' AND assessed_fee > 0 THEN assessed_fee ELSE 0 END), 0) AS pending_fees
        FROM book_incidents
        WHERE user_id = ?
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return $summary;
}

function get_member_reportable_borrows(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare("
        SELECT
            br.id,
            br.book_id,
            br.status,
            br.borrow_date,
            br.due_date,
            br.request_batch,
            b.title,
            b.author,
            (
                SELECT COUNT(*)
                FROM book_incidents bi
                WHERE bi.borrow_id = br.id
                  AND bi.workflow_status IN ('reported', 'under_review', 'awaiting_settlement')
            ) AS open_incident_count
        FROM borrows br
        JOIN books b ON b.id = br.book_id
        WHERE br.user_id = ?
          AND br.status IN ('borrowed', 'return_requested')
        ORDER BY COALESCE(br.approved_at, br.borrow_date, br.created_at) DESC, br.id DESC
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function get_member_incidents(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare("
        SELECT
            bi.*,
            b.title,
            b.author,
            br.status AS borrow_status,
            reviewer.fullname AS reviewed_by_name,
            resolver.fullname AS resolved_by_name
        FROM book_incidents bi
        JOIN books b ON b.id = bi.book_id
        JOIN borrows br ON br.id = bi.borrow_id
        LEFT JOIN users reviewer ON reviewer.id = bi.reviewed_by
        LEFT JOIN users resolver ON resolver.id = bi.resolved_by
        WHERE bi.user_id = ?
        ORDER BY bi.reported_at DESC, bi.id DESC
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function create_member_book_incident(mysqli $conn, int $userId, string $userRole, array $data): array
{
    $borrowId = (int) ($data['borrow_id'] ?? 0);
    $incidentType = trim((string) ($data['incident_type'] ?? ''));
    $severity = trim((string) ($data['severity'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));

    if ($borrowId <= 0) {
        return ['ok' => false, 'message' => 'Select a borrowed item first.'];
    }

    if (!isset(book_incident_type_options()[$incidentType])) {
        return ['ok' => false, 'message' => 'Select a valid incident type.'];
    }

    if ($incidentType === 'damaged' && !isset(book_incident_severity_options()[$severity])) {
        return ['ok' => false, 'message' => 'Select the damage severity.'];
    }

    if ($description === '') {
        return ['ok' => false, 'message' => 'Please describe what happened to the book.'];
    }

    $borrowStmt = $conn->prepare("
        SELECT br.book_id, br.status, b.title
        FROM borrows br
        JOIN books b ON b.id = br.book_id
        WHERE br.id = ?
          AND br.user_id = ?
        LIMIT 1
    ");
    $borrowStmt->bind_param('ii', $borrowId, $userId);
    $borrowStmt->execute();
    $borrow = $borrowStmt->get_result()->fetch_assoc();
    $borrowStmt->close();

    if (!$borrow) {
        return ['ok' => false, 'message' => 'The selected borrow record was not found.'];
    }

    $borrowStatus = (string) ($borrow['status'] ?? '');
    if (!in_array($borrowStatus, ['borrowed', 'return_requested'], true)) {
        return ['ok' => false, 'message' => 'Only active borrowed items can be reported right now.'];
    }

    $duplicateStmt = $conn->prepare("
        SELECT id
        FROM book_incidents
        WHERE borrow_id = ?
          AND workflow_status IN ('reported', 'under_review', 'awaiting_settlement')
        LIMIT 1
    ");
    $duplicateStmt->bind_param('i', $borrowId);
    $duplicateStmt->execute();
    $duplicate = $duplicateStmt->get_result()->fetch_assoc();
    $duplicateStmt->close();

    if ($duplicate) {
        return ['ok' => false, 'message' => 'This borrowed item already has an active lost or damaged report.'];
    }

    $reportedAt = date('Y-m-d H:i:s');
    $normalizedRole = canonical_role($userRole);
    $bookId = (int) ($borrow['book_id'] ?? 0);
    $severityValue = $severity !== '' ? $severity : null;
    $insertStmt = $conn->prepare("
        INSERT INTO book_incidents (
            borrow_id,
            user_id,
            book_id,
            reported_by_role,
            incident_type,
            severity,
            description,
            workflow_status,
            settlement_status,
            reported_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'reported', 'pending', ?)
    ");
    $insertStmt->bind_param(
        'iiisssss',
        $borrowId,
        $userId,
        $bookId,
        $normalizedRole,
        $incidentType,
        $severityValue,
        $description,
        $reportedAt
    );
    $ok = $insertStmt->execute();
    $incidentId = (int) $insertStmt->insert_id;
    $insertStmt->close();

    if (!$ok) {
        return ['ok' => false, 'message' => 'Unable to save the incident report right now.'];
    }

    $bookTitle = trim((string) ($borrow['title'] ?? 'the selected book'));
    create_notification(
        $conn,
        'librarian',
        'New Book Incident Report',
        role_label($normalizedRole) . ' reported a ' . book_incident_type_label($incidentType) . ' incident for ' . ($bookTitle !== '' ? $bookTitle : 'a borrowed book') . '.',
        'warning'
    );
    create_notification(
        $conn,
        'admin',
        'New Book Incident Report',
        'Incident #' . $incidentId . ' for ' . ($bookTitle !== '' ? $bookTitle : 'a borrowed book') . ' is waiting for review.',
        'warning'
    );
    audit_log($conn, 'book_incident.reported', [
        'incident_id' => $incidentId,
        'borrow_id' => $borrowId,
        'book_id' => (int) $borrow['book_id'],
        'incident_type' => $incidentType,
        'severity' => $severity,
    ]);

    return ['ok' => true, 'message' => 'Incident report submitted. The librarian can now review it.'];
}

function get_librarian_incidents(mysqli $conn, string $statusFilter = '', string $typeFilter = ''): array
{
    $conditions = [];
    $params = [];
    $types = '';

    if ($statusFilter !== '' && isset(book_incident_workflow_options()[$statusFilter])) {
        $conditions[] = 'bi.workflow_status = ?';
        $params[] = $statusFilter;
        $types .= 's';
    }

    if ($typeFilter !== '' && isset(book_incident_type_options()[$typeFilter])) {
        $conditions[] = 'bi.incident_type = ?';
        $params[] = $typeFilter;
        $types .= 's';
    }

    $sql = "
        SELECT
            bi.*,
            u.fullname,
            u.username,
            u.role,
            b.title,
            b.author,
            br.status AS borrow_status,
            br.borrow_date,
            br.due_date,
            reviewer.fullname AS reviewed_by_name,
            resolver.fullname AS resolved_by_name
        FROM book_incidents bi
        JOIN users u ON u.id = bi.user_id
        JOIN books b ON b.id = bi.book_id
        JOIN borrows br ON br.id = bi.borrow_id
        LEFT JOIN users reviewer ON reviewer.id = bi.reviewed_by
        LEFT JOIN users resolver ON resolver.id = bi.resolved_by
    ";

    if ($conditions !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= " ORDER BY
        CASE bi.workflow_status
            WHEN 'reported' THEN 1
            WHEN 'under_review' THEN 2
            WHEN 'awaiting_settlement' THEN 3
            WHEN 'resolved' THEN 4
            ELSE 5
        END,
        bi.reported_at DESC,
        bi.id DESC
    ";

    $stmt = $conn->prepare($sql);
    if (count($params) === 1) {
        $stmt->bind_param($types, $params[0]);
    } elseif (count($params) === 2) {
        $stmt->bind_param($types, $params[0], $params[1]);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function apply_book_incident_resolution(mysqli $conn, array $incident, string $resolvedAt): array
{
    $borrowId = (int) ($incident['borrow_id'] ?? 0);
    $bookId = (int) ($incident['book_id'] ?? 0);
    $borrowStatus = trim((string) ($incident['borrow_status'] ?? ''));
    $resolutionAction = trim((string) ($incident['resolution_action'] ?? 'none'));
    $inventoryAppliedAt = trim((string) ($incident['inventory_applied_at'] ?? ''));
    $borrowClosedAt = trim((string) ($incident['borrow_closed_at'] ?? ''));

    if ($borrowId <= 0 || $bookId <= 0) {
        return ['ok' => false, 'message' => 'Incident is missing its linked borrow or book record.'];
    }

    $shouldCloseBorrow = $borrowClosedAt === '' && in_array($borrowStatus, ['borrowed', 'return_requested'], true);
    if ($shouldCloseBorrow) {
        $returnDate = date('Y-m-d', strtotime($resolvedAt));
        $closeStmt = $conn->prepare("
            UPDATE borrows
            SET status = 'returned',
                return_date = ?,
                returned_at = ?,
                return_requested_at = CASE
                    WHEN return_requested_at IS NULL THEN ?
                    ELSE return_requested_at
                END
            WHERE id = ?
              AND status IN ('borrowed', 'return_requested')
        ");
        $closeStmt->bind_param('sssi', $returnDate, $resolvedAt, $resolvedAt, $borrowId);
        $closeStmt->execute();
        $closeStmt->close();
    }

    if ($inventoryAppliedAt !== '') {
        return ['ok' => true];
    }

    if ($resolutionAction === 'return_to_shelf') {
        $stockStmt = $conn->prepare("
            UPDATE books
            SET qty_available = LEAST(qty_total, qty_available + 1)
            WHERE id = ?
        ");
        $stockStmt->bind_param('i', $bookId);
        $stockStmt->execute();
        $stockStmt->close();
        return ['ok' => true];
    }

    if (in_array($resolutionAction, ['write_off_lost', 'write_off_damaged'], true)) {
        $stockStmt = $conn->prepare("
            UPDATE books
            SET
                qty_total = GREATEST(qty_total - 1, 0),
                qty_available = LEAST(qty_available, GREATEST(qty_total - 1, 0))
            WHERE id = ?
        ");
        $stockStmt->bind_param('i', $bookId);
        $stockStmt->execute();
        $stockStmt->close();
        return ['ok' => true];
    }

    return ['ok' => true];
}

function update_librarian_book_incident(mysqli $conn, int $incidentId, int $actorUserId, array $data): array
{
    $workflowStatus = trim((string) ($data['workflow_status'] ?? ''));
    $resolutionAction = trim((string) ($data['resolution_action'] ?? 'none'));
    $settlementStatus = trim((string) ($data['settlement_status'] ?? 'pending'));
    $severity = trim((string) ($data['severity'] ?? ''));
    $resolutionNotes = trim((string) ($data['resolution_notes'] ?? ''));
    $assessedFee = max(0, round((float) ($data['assessed_fee'] ?? 0), 2));

    if ($incidentId <= 0) {
        return ['ok' => false, 'message' => 'Incident record is missing.'];
    }

    if (!isset(book_incident_workflow_options()[$workflowStatus])) {
        return ['ok' => false, 'message' => 'Select a valid workflow status.'];
    }

    if (!isset(book_incident_resolution_options()[$resolutionAction])) {
        return ['ok' => false, 'message' => 'Select a valid resolution action.'];
    }

    if (!isset(book_incident_settlement_options()[$settlementStatus])) {
        return ['ok' => false, 'message' => 'Select a valid settlement status.'];
    }

    $incidentStmt = $conn->prepare("
        SELECT bi.*, br.status AS borrow_status, b.title, u.role AS member_role
        FROM book_incidents bi
        JOIN borrows br ON br.id = bi.borrow_id
        JOIN books b ON b.id = bi.book_id
        JOIN users u ON u.id = bi.user_id
        WHERE bi.id = ?
        LIMIT 1
    ");
    $incidentStmt->bind_param('i', $incidentId);
    $incidentStmt->execute();
    $incident = $incidentStmt->get_result()->fetch_assoc();
    $incidentStmt->close();

    if (!$incident) {
        return ['ok' => false, 'message' => 'Incident record was not found.'];
    }

    $currentWorkflow = trim((string) ($incident['workflow_status'] ?? ''));
    if (in_array($currentWorkflow, ['resolved', 'rejected'], true)) {
        return ['ok' => false, 'message' => 'This incident is already closed and can no longer be edited by the librarian.'];
    }

    if (in_array($workflowStatus, ['awaiting_settlement', 'resolved'], true) && $resolutionAction === 'none') {
        return ['ok' => false, 'message' => 'Choose the final inventory action before resolving the incident.'];
    }

    if ($workflowStatus === 'resolved' && $assessedFee <= 0 && $settlementStatus === 'pending') {
        $settlementStatus = 'not_required';
    }

    if ($workflowStatus === 'awaiting_settlement' && $resolutionAction === 'none') {
        return ['ok' => false, 'message' => 'Choose how the book inventory will be handled before sending the case to settlement.'];
    }

    $reviewedAt = date('Y-m-d H:i:s');
    $inventoryHandledAt = in_array($workflowStatus, ['awaiting_settlement', 'resolved'], true) ? $reviewedAt : null;
    $resolvedAt = $workflowStatus === 'resolved' ? $reviewedAt : null;

    $conn->begin_transaction();

    try {
        $workingIncident = $incident;
        $workingIncident['resolution_action'] = $resolutionAction;

        if ($inventoryHandledAt !== null) {
            $applyResult = apply_book_incident_resolution($conn, $workingIncident, $inventoryHandledAt);
            if (($applyResult['ok'] ?? false) !== true) {
                throw new RuntimeException((string) ($applyResult['message'] ?? 'resolution_failed'));
            }
        }

        $severityValue = $severity !== '' ? $severity : null;
        $resolvedBy = $resolvedAt !== null ? $actorUserId : null;
        $resolvedAtValue = trim((string) ($incident['resolved_at'] ?? '')) !== '' ? (string) $incident['resolved_at'] : $resolvedAt;
        $resolvedByValue = (int) ($incident['resolved_by'] ?? 0) > 0 ? (int) $incident['resolved_by'] : $resolvedBy;
        $inventoryAppliedAtValue = trim((string) ($incident['inventory_applied_at'] ?? '')) !== '' ? (string) $incident['inventory_applied_at'] : $inventoryHandledAt;
        $borrowClosedAtValue = trim((string) ($incident['borrow_closed_at'] ?? '')) !== '' ? (string) $incident['borrow_closed_at'] : $inventoryHandledAt;
        $updateStmt = $conn->prepare("
            UPDATE book_incidents
            SET severity = ?,
                workflow_status = ?,
                resolution_action = ?,
                settlement_status = ?,
                assessed_fee = ?,
                resolution_notes = ?,
                reviewed_at = ?,
                reviewed_by = ?,
                resolved_at = ?,
                resolved_by = ?,
                inventory_applied_at = ?,
                borrow_closed_at = ?
            WHERE id = ?
        ");
        $updateStmt->bind_param(
            'ssssdssissssi',
            $severityValue,
            $workflowStatus,
            $resolutionAction,
            $settlementStatus,
            $assessedFee,
            $resolutionNotes,
            $reviewedAt,
            $actorUserId,
            $resolvedAtValue,
            $resolvedByValue,
            $inventoryAppliedAtValue,
            $borrowClosedAtValue,
            $incidentId
        );
        $updateStmt->execute();
        $updateStmt->close();

        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'Unable to update this incident right now.'];
    }

    $bookTitle = trim((string) ($incident['title'] ?? 'the selected book'));
    $memberRole = canonical_role((string) ($incident['member_role'] ?? ''));
    if (in_array($memberRole, ['student', 'faculty'], true)) {
        create_notification(
            $conn,
            $memberRole,
            'Book Incident Updated',
            'Your ' . strtolower(book_incident_type_label((string) $incident['incident_type'])) . ' report for ' . ($bookTitle !== '' ? $bookTitle : 'your borrowed book') . ' is now ' . strtolower(book_incident_workflow_label($workflowStatus)) . '.',
            $workflowStatus === 'rejected' ? 'warning' : 'info',
            (int) $incident['user_id']
        );
    }
    create_notification(
        $conn,
        'admin',
        'Book Incident Reviewed',
        'Incident #' . $incidentId . ' for ' . ($bookTitle !== '' ? $bookTitle : 'a borrowed book') . ' is now ' . strtolower(book_incident_workflow_label($workflowStatus)) . '.',
        $workflowStatus === 'resolved' ? 'info' : 'warning'
    );
    audit_log($conn, 'book_incident.reviewed', [
        'incident_id' => $incidentId,
        'workflow_status' => $workflowStatus,
        'resolution_action' => $resolutionAction,
        'settlement_status' => $settlementStatus,
        'assessed_fee' => $assessedFee,
    ]);

    return ['ok' => true, 'message' => 'Incident workflow updated successfully.'];
}

function get_admin_incidents(mysqli $conn, string $settlementFilter = ''): array
{
    $sql = "
        SELECT
            bi.*,
            u.fullname,
            u.username,
            u.role,
            b.title,
            b.author
        FROM book_incidents bi
        JOIN users u ON u.id = bi.user_id
        JOIN books b ON b.id = bi.book_id
    ";

    if ($settlementFilter !== '' && isset(book_incident_settlement_options()[$settlementFilter])) {
        $sql .= " WHERE bi.settlement_status = ?";
    }

    $sql .= " ORDER BY bi.reported_at DESC, bi.id DESC";

    $stmt = $conn->prepare($sql);
    if ($settlementFilter !== '' && isset(book_incident_settlement_options()[$settlementFilter])) {
        $stmt->bind_param('s', $settlementFilter);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function update_admin_incident_settlement(mysqli $conn, int $incidentId, int $actorUserId, array $data): array
{
    $settlementStatus = trim((string) ($data['settlement_status'] ?? ''));
    $resolutionNotes = trim((string) ($data['resolution_notes'] ?? ''));

    if ($incidentId <= 0) {
        return ['ok' => false, 'message' => 'Incident record is missing.'];
    }

    if (!isset(book_incident_settlement_options()[$settlementStatus])) {
        return ['ok' => false, 'message' => 'Select a valid settlement status.'];
    }

    $incidentStmt = $conn->prepare("
        SELECT bi.*, b.title, u.role AS member_role
        FROM book_incidents bi
        JOIN books b ON b.id = bi.book_id
        JOIN users u ON u.id = bi.user_id
        WHERE bi.id = ?
        LIMIT 1
    ");
    $incidentStmt->bind_param('i', $incidentId);
    $incidentStmt->execute();
    $incident = $incidentStmt->get_result()->fetch_assoc();
    $incidentStmt->close();

    if (!$incident) {
        return ['ok' => false, 'message' => 'Incident record was not found.'];
    }

    if (!in_array((string) ($incident['workflow_status'] ?? ''), ['awaiting_settlement', 'resolved'], true)) {
        return ['ok' => false, 'message' => 'Admin settlement can only be updated after librarian review.'];
    }

    $newWorkflow = (float) ($incident['assessed_fee'] ?? 0) > 0 && $settlementStatus === 'pending'
        ? 'awaiting_settlement'
        : 'resolved';

    $updateStmt = $conn->prepare("
        UPDATE book_incidents
        SET settlement_status = ?,
            workflow_status = ?,
            resolution_notes = CASE
                WHEN ? <> '' THEN CONCAT(COALESCE(resolution_notes, ''), CASE WHEN COALESCE(resolution_notes, '') = '' THEN '' ELSE '\n\n' END, ?)
                ELSE resolution_notes
            END,
            resolved_at = CASE
                WHEN ? = 'resolved' AND resolved_at IS NULL THEN ?
                ELSE resolved_at
            END,
            resolved_by = CASE
                WHEN ? = 'resolved' THEN ?
                ELSE resolved_by
            END
        WHERE id = ?
    ");
    $resolvedAt = date('Y-m-d H:i:s');
    $updateStmt->bind_param(
        'ssssssisi',
        $settlementStatus,
        $newWorkflow,
        $resolutionNotes,
        $resolutionNotes,
        $newWorkflow,
        $resolvedAt,
        $newWorkflow,
        $actorUserId,
        $incidentId
    );
    $ok = $updateStmt->execute();
    $updateStmt->close();

    if (!$ok) {
        return ['ok' => false, 'message' => 'Unable to update the settlement status right now.'];
    }

    $bookTitle = trim((string) ($incident['title'] ?? 'the selected book'));
    $memberRole = canonical_role((string) ($incident['member_role'] ?? ''));
    if (in_array($memberRole, ['student', 'faculty'], true)) {
        create_notification(
            $conn,
            $memberRole,
            'Book Incident Settlement Updated',
            'The settlement status for your incident on ' . ($bookTitle !== '' ? $bookTitle : 'your borrowed book') . ' is now ' . strtolower(book_incident_settlement_label($settlementStatus)) . '.',
            $settlementStatus === 'pending' ? 'warning' : 'info',
            (int) $incident['user_id']
        );
    }
    audit_log($conn, 'book_incident.settlement_updated', [
        'incident_id' => $incidentId,
        'settlement_status' => $settlementStatus,
        'workflow_status' => $newWorkflow,
    ]);

    return ['ok' => true, 'message' => 'Settlement status updated successfully.'];
}
