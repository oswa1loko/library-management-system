<?php

function upload_book_incident_photo(array $file): array
{
    if (empty($file['name'])) {
        return ['path' => '', 'error' => ''];
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowed, true)) {
        return ['path' => '', 'error' => 'Only JPG, JPEG, PNG, and WEBP incident photos are allowed.'];
    }

    $directory = __DIR__ . '/../uploads/incident_photos';
    if (!ensure_upload_directory($directory)) {
        return ['path' => '', 'error' => 'Incident photo folder could not be created.'];
    }

    $filename = 'incident_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $target = $directory . '/' . $filename;

    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $target)) {
        return ['path' => '', 'error' => 'Incident photo upload failed.'];
    }

    return ['path' => 'uploads/incident_photos/' . $filename, 'error' => ''];
}

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
        'open' => 'Under Review',
        'for_payment' => 'Waiting for Payment',
        'closed' => 'Resolved',
    ];
}

function book_incident_normalize_workflow_status(?string $value): string
{
    $value = trim((string) $value);

    return match ($value) {
        'reported', 'under_review', 'open' => 'open',
        'awaiting_settlement', 'for_payment' => 'for_payment',
        'resolved', 'rejected', 'closed' => 'closed',
        default => $value !== '' ? $value : 'open',
    };
}

function book_incident_resolution_options(): array
{
    return [
        'none' => 'Pending inventory action',
        'write_off_lost' => 'Write off as lost',
        'write_off_damaged' => 'Write off as damaged',
        'return_to_shelf' => 'Recovered: return to shelf',
    ];
}

function book_incident_settlement_options(): array
{
    return [
        'pending' => 'Pending',
        'paid' => 'Paid',
        'waived' => 'Waived',
    ];
}

function book_incident_workflow_label(string $value): string
{
    $normalized = book_incident_normalize_workflow_status($value);
    $options = book_incident_workflow_options();
    return $options[$normalized] ?? ucfirst(str_replace('_', ' ', trim($value)));
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
    $value = trim($value);
    $options = book_incident_resolution_options() + [
        'send_for_repair' => 'Send for repair',
    ];
    return $options[$value] ?? ucfirst(str_replace('_', ' ', $value));
}

function book_incident_settlement_label(string $value): string
{
    $value = trim($value);
    $options = book_incident_settlement_options() + [
        'not_required' => 'Not required',
        'replacement_submitted' => 'Replacement submitted',
    ];
    return $options[$value] ?? ucfirst(str_replace('_', ' ', $value));
}

function book_incident_workflow_form_options(?string $currentValue = null): array
{
    $options = book_incident_workflow_options();
    $currentValue = trim((string) $currentValue);
    if ($currentValue !== '' && !isset($options[$currentValue])) {
        $options[$currentValue] = book_incident_workflow_label($currentValue);
    }

    return $options;
}

function book_incident_resolution_form_options(?string $currentValue = null): array
{
    $options = book_incident_resolution_options();
    $options['none'] = 'Select final inventory action';
    $currentValue = trim((string) $currentValue);
    if ($currentValue !== '' && !isset($options[$currentValue])) {
        $options[$currentValue] = book_incident_resolution_label($currentValue);
    }

    return $options;
}

function book_incident_default_resolution_action(?string $incidentType, ?string $currentValue = null): string
{
    $currentValue = trim((string) $currentValue);
    if ($currentValue !== '' && $currentValue !== 'none') {
        return $currentValue;
    }

    $incidentType = trim((string) $incidentType);
    return match ($incidentType) {
        'lost' => 'write_off_lost',
        'damaged' => 'write_off_damaged',
        default => $currentValue !== '' ? $currentValue : 'none',
    };
}

function book_incident_settlement_form_options(?string $currentValue = null): array
{
    $options = book_incident_settlement_options();
    $currentValue = trim((string) $currentValue);
    if ($currentValue !== '' && !isset($options[$currentValue])) {
        $options[$currentValue] = book_incident_settlement_label($currentValue);
    }

    return $options;
}

function book_incident_admin_settlement_form_options(?string $currentValue = null): array
{
    $options = [
        'pending' => 'Pending',
        'waived' => 'Waived',
    ];
    $currentValue = trim((string) $currentValue);
    if ($currentValue !== '' && !isset($options[$currentValue])) {
        $options[$currentValue] = book_incident_settlement_label($currentValue);
    }

    return $options;
}

function book_incident_next_actor_label(string $workflowStatus, string $settlementStatus = 'pending'): string
{
    $workflowStatus = book_incident_normalize_workflow_status($workflowStatus);
    $settlementStatus = trim($settlementStatus);

    if ($workflowStatus === 'open') {
        return 'Librarian review';
    }

    if ($workflowStatus === 'for_payment') {
        return $settlementStatus === 'pending'
            ? 'Student payment'
            : 'Admin closeout';
    }

    if ($workflowStatus === 'closed') {
        return 'Closed';
    }

    return 'In progress';
}

function book_incident_status_dot_class(string $value): string
{
    $value = book_incident_normalize_workflow_status($value);
    $map = [
        'open' => 'pending',
        'for_payment' => 'warning',
        'closed' => 'approved',
        'paid' => 'approved',
        'waived' => 'approved',
        'pending' => 'pending',
        'not_required' => 'approved',
    ];

    return $map[$value] ?? 'pending';
}

function book_incident_payment_stage_label(array $incident): string
{
    $settlementStatus = trim((string) ($incident['settlement_status'] ?? 'pending'));
    $workflowStatus = book_incident_normalize_workflow_status((string) ($incident['workflow_status'] ?? 'open'));
    $latestPaymentStatus = trim((string) ($incident['latest_payment_status'] ?? ''));
    $assessedFee = round((float) ($incident['assessed_fee'] ?? 0), 2);

    if ($settlementStatus === 'paid') {
        return 'Paid';
    }

    if ($settlementStatus === 'waived' || $assessedFee <= 0) {
        return 'No Payment Needed';
    }

    if ($latestPaymentStatus === 'pending') {
        return 'Payment Submitted';
    }

    if ($latestPaymentStatus === 'approved') {
        return 'Paid';
    }

    if ($latestPaymentStatus === 'rejected') {
        return 'Payment Rejected';
    }

    if ($workflowStatus === 'for_payment') {
        return 'Awaiting Payment';
    }

    return 'Under Review';
}

function book_incident_payment_stage_dot_class(array $incident): string
{
    $label = book_incident_payment_stage_label($incident);
    $map = [
        'Paid' => 'approved',
        'No Payment Needed' => 'approved',
        'Payment Submitted' => 'warning',
        'Payment Rejected' => 'overdue',
        'Awaiting Payment' => 'pending',
        'Under Review' => 'due',
    ];

    return $map[$label] ?? 'pending';
}

function book_incident_can_accept_payment_submission(array $incident): bool
{
    $workflowStatus = book_incident_normalize_workflow_status((string) ($incident['workflow_status'] ?? 'open'));
    $settlementStatus = trim((string) ($incident['settlement_status'] ?? 'pending'));
    $assessedFee = round((float) ($incident['assessed_fee'] ?? 0), 2);
    $latestPaymentStatus = trim((string) ($incident['latest_payment_status'] ?? ''));

    return $workflowStatus === 'for_payment'
        && $settlementStatus === 'pending'
        && $assessedFee > 0
        && $latestPaymentStatus !== 'pending';
}

function book_incident_payment_block_reason(array $incident): string
{
    $workflowStatus = book_incident_normalize_workflow_status((string) ($incident['workflow_status'] ?? 'open'));
    $settlementStatus = trim((string) ($incident['settlement_status'] ?? 'pending'));
    $assessedFee = round((float) ($incident['assessed_fee'] ?? 0), 2);
    $latestPaymentStatus = trim((string) ($incident['latest_payment_status'] ?? ''));

    if ($workflowStatus !== 'for_payment') {
        return 'Waiting for librarian review';
    }

    if ($settlementStatus !== 'pending') {
        return 'Already settled or no payment required';
    }

    if ($assessedFee <= 0) {
        return 'No assessed fee';
    }

    if ($latestPaymentStatus === 'pending') {
        return 'Payment already pending admin review';
    }

    return '';
}

function book_incident_summary(mysqli $conn): array
{
    $query = $conn->query("
        SELECT
            COUNT(*) AS total_incidents,
            COALESCE(SUM(CASE WHEN workflow_status IN ('open', 'for_payment', 'reported', 'under_review', 'awaiting_settlement') THEN 1 ELSE 0 END), 0) AS open_incidents,
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
            COALESCE(SUM(CASE WHEN workflow_status IN ('open', 'for_payment', 'reported', 'under_review', 'awaiting_settlement') THEN 1 ELSE 0 END), 0) AS active_incidents,
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
            br.book_copy_id,
            br.status,
            br.borrow_date,
            br.due_date,
            br.request_batch,
            b.title,
            b.author,
            bc.copy_id,
            bc.barcode,
            (
                SELECT COUNT(*)
                FROM book_incidents bi
                WHERE bi.borrow_id = br.id
                  AND bi.workflow_status IN ('open', 'for_payment', 'reported', 'under_review', 'awaiting_settlement')
            ) AS open_incident_count
        FROM borrows br
        JOIN books b ON b.id = br.book_id
        LEFT JOIN book_copies bc ON bc.id = br.book_copy_id
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
            bc.copy_id,
            bc.barcode,
            br.status AS borrow_status,
            (
                SELECT pay.status
                FROM payments pay
                WHERE pay.incident_id = bi.id
                ORDER BY pay.id DESC
                LIMIT 1
            ) AS latest_payment_status,
            reviewer.fullname AS reviewed_by_name,
            resolver.fullname AS resolved_by_name
        FROM book_incidents bi
        JOIN books b ON b.id = bi.book_id
        JOIN borrows br ON br.id = bi.borrow_id
        LEFT JOIN book_copies bc ON bc.id = COALESCE(bi.book_copy_id, br.book_copy_id)
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
    $description = trim((string) ($data['description'] ?? ''));
    $incidentPhotoFile = is_array($data['incident_photo_file'] ?? null) ? $data['incident_photo_file'] : [];

    if ($borrowId <= 0) {
        return ['ok' => false, 'message' => 'Select a borrowed item first.'];
    }

    if (!isset(book_incident_type_options()[$incidentType])) {
        return ['ok' => false, 'message' => 'Select a valid incident type.'];
    }

    if ($description === '') {
        return ['ok' => false, 'message' => 'Please describe what happened to the book.'];
    }

    if ($incidentType === 'damaged' && empty($incidentPhotoFile['name'])) {
        return ['ok' => false, 'message' => 'Upload a clear photo of the damaged book before submitting the report.'];
    }

    $photoUpload = upload_book_incident_photo($incidentPhotoFile);
    if (($photoUpload['error'] ?? '') !== '') {
        return ['ok' => false, 'message' => (string) $photoUpload['error']];
    }

    $borrowStmt = $conn->prepare("
        SELECT br.book_id, br.book_copy_id, br.status, b.title, bc.copy_id, bc.barcode
        FROM borrows br
        JOIN books b ON b.id = br.book_id
        LEFT JOIN book_copies bc ON bc.id = br.book_copy_id
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
          AND workflow_status IN ('open', 'for_payment', 'reported', 'under_review', 'awaiting_settlement')
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
    $bookCopyId = (int) ($borrow['book_copy_id'] ?? 0);
    $severityValue = null;
    $incidentPhotoPath = trim((string) ($photoUpload['path'] ?? '')) !== '' ? (string) $photoUpload['path'] : null;
    $insertStmt = $conn->prepare("
        INSERT INTO book_incidents (
            borrow_id,
            user_id,
            book_id,
            book_copy_id,
            reported_by_role,
            incident_type,
            severity,
            description,
            incident_photo_path,
            workflow_status,
            settlement_status,
            reported_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', 'pending', ?)
    ");
    $insertStmt->bind_param(
        'iiiissssss',
        $borrowId,
        $userId,
        $bookId,
        $bookCopyId,
        $normalizedRole,
        $incidentType,
        $severityValue,
        $description,
        $incidentPhotoPath,
        $reportedAt
    );
    $ok = $insertStmt->execute();
    $incidentId = (int) $insertStmt->insert_id;
    $insertStmt->close();

    if (!$ok) {
        if ($incidentPhotoPath !== null) {
            remove_relative_file($incidentPhotoPath);
        }
        return ['ok' => false, 'message' => 'Unable to save the incident report right now.'];
    }

    $bookTitle = trim((string) ($borrow['title'] ?? 'the selected book'));
    $copyLabel = trim((string) ($borrow['copy_id'] ?? ''));
    if ($copyLabel !== '') {
        $bookTitle .= ' (' . $copyLabel . ')';
    }
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
        'severity' => '',
    ]);

    return ['ok' => true, 'message' => 'Incident report submitted. The librarian can now review it.'];
}

function get_librarian_incidents(mysqli $conn, string $statusFilter = '', string $typeFilter = ''): array
{
    $conditions = [];
    $params = [];
    $types = '';

    if ($statusFilter !== '' && isset(book_incident_workflow_options()[$statusFilter])) {
        if ($statusFilter === 'open') {
            $conditions[] = "bi.workflow_status IN ('open', 'reported', 'under_review')";
        } elseif ($statusFilter === 'for_payment') {
            $conditions[] = "bi.workflow_status IN ('for_payment', 'awaiting_settlement')";
        } elseif ($statusFilter === 'closed') {
            $conditions[] = "bi.workflow_status IN ('closed', 'resolved', 'rejected')";
        }
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
            COALESCE(bi.book_copy_id, br.book_copy_id) AS effective_book_copy_id,
            bc.copy_id,
            bc.barcode,
            br.status AS borrow_status,
            br.borrow_date,
            br.due_date,
            (
                SELECT pay.status
                FROM payments pay
                WHERE pay.incident_id = bi.id
                ORDER BY pay.id DESC
                LIMIT 1
            ) AS latest_payment_status,
            reviewer.fullname AS reviewed_by_name,
            resolver.fullname AS resolved_by_name
        FROM book_incidents bi
        JOIN users u ON u.id = bi.user_id
        JOIN books b ON b.id = bi.book_id
        JOIN borrows br ON br.id = bi.borrow_id
        LEFT JOIN book_copies bc ON bc.id = COALESCE(bi.book_copy_id, br.book_copy_id)
        LEFT JOIN users reviewer ON reviewer.id = bi.reviewed_by
        LEFT JOIN users resolver ON resolver.id = bi.resolved_by
    ";

    if ($conditions !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= " ORDER BY
        CASE bi.workflow_status
            WHEN 'open' THEN 1
            WHEN 'reported' THEN 1
            WHEN 'under_review' THEN 1
            WHEN 'for_payment' THEN 2
            WHEN 'awaiting_settlement' THEN 2
            WHEN 'closed' THEN 3
            WHEN 'resolved' THEN 3
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
    $bookCopyId = (int) ($incident['book_copy_id'] ?? $incident['effective_book_copy_id'] ?? 0);
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

    if ($bookCopyId > 0) {
        if ($resolutionAction === 'return_to_shelf') {
            set_book_copy_status($conn, $bookCopyId, 'available');
        } elseif ($resolutionAction === 'write_off_lost') {
            set_book_copy_status($conn, $bookCopyId, 'lost');
        } elseif ($resolutionAction === 'write_off_damaged') {
            set_book_copy_status($conn, $bookCopyId, 'damaged');
        }

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
    $workflowStatus = book_incident_normalize_workflow_status((string) ($data['workflow_status'] ?? ''));
    $resolutionAction = trim((string) ($data['resolution_action'] ?? 'none'));
    $settlementStatus = trim((string) ($data['settlement_status'] ?? ''));
    $severity = trim((string) ($data['severity'] ?? ''));
    $resolutionNotes = trim((string) ($data['resolution_notes'] ?? ''));
    $assessedFee = max(0, round((float) ($data['assessed_fee'] ?? 0), 2));

    if ($incidentId <= 0) {
        return ['ok' => false, 'message' => 'Incident record is missing.'];
    }

    if (!isset(book_incident_workflow_form_options($workflowStatus)[$workflowStatus])) {
        return ['ok' => false, 'message' => 'Select a valid workflow status.'];
    }

    if (!isset(book_incident_resolution_form_options($resolutionAction)[$resolutionAction])) {
        return ['ok' => false, 'message' => 'Select a valid resolution action.'];
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

    $currentWorkflow = book_incident_normalize_workflow_status((string) ($incident['workflow_status'] ?? ''));
    if ($currentWorkflow === 'closed') {
        return ['ok' => false, 'message' => 'This incident is already closed and can no longer be edited by the librarian.'];
    }

    if (in_array($workflowStatus, ['for_payment', 'closed'], true) && $resolutionAction === 'none') {
        return ['ok' => false, 'message' => 'Choose the final inventory action before moving the incident forward.'];
    }

    if ($assessedFee > 0) {
        $settlementStatus = 'pending';
        if ($workflowStatus === 'closed') {
            return ['ok' => false, 'message' => 'Incidents with assessed fees must stay in For Payment until payment is approved or waived.'];
        }
        $workflowStatus = 'for_payment';
    } elseif ($workflowStatus === 'for_payment' && $assessedFee <= 0) {
        return ['ok' => false, 'message' => 'Set a fee first or close the incident as waived if no payment is needed.'];
    } elseif ($workflowStatus === 'closed') {
        $settlementStatus = 'waived';
    } else {
        $settlementStatus = $settlementStatus === 'paid' ? 'paid' : 'pending';
    }

    $reviewedAt = date('Y-m-d H:i:s');
    $inventoryHandledAt = in_array($workflowStatus, ['for_payment', 'closed'], true) ? $reviewedAt : null;
    $resolvedAt = $workflowStatus === 'closed' ? $reviewedAt : null;

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
            $workflowStatus === 'closed' ? 'info' : 'warning',
            (int) $incident['user_id']
        );
    }
    create_notification(
        $conn,
        'admin',
        'Book Incident Reviewed',
        'Incident #' . $incidentId . ' for ' . ($bookTitle !== '' ? $bookTitle : 'a borrowed book') . ' is now ' . strtolower(book_incident_workflow_label($workflowStatus)) . '.',
        $workflowStatus === 'closed' ? 'info' : 'warning'
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
            b.author,
            (
                SELECT pay.id
                FROM payments pay
                WHERE pay.incident_id = bi.id
                ORDER BY pay.id DESC
                LIMIT 1
            ) AS latest_payment_id,
            (
                SELECT pay.status
                FROM payments pay
                WHERE pay.incident_id = bi.id
                ORDER BY pay.id DESC
                LIMIT 1
            ) AS latest_payment_status,
            (
                SELECT pay.proof_path
                FROM payments pay
                WHERE pay.incident_id = bi.id
                ORDER BY pay.id DESC
                LIMIT 1
            ) AS latest_payment_proof_path,
            (
                SELECT pay.amount
                FROM payments pay
                WHERE pay.incident_id = bi.id
                ORDER BY pay.id DESC
                LIMIT 1
            ) AS latest_payment_amount
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

function review_admin_incident_payment(mysqli $conn, int $incidentId, int $paymentId, int $actorUserId, string $decision): array
{
    $decision = $decision === 'reject' ? 'reject' : 'approve';

    if ($incidentId <= 0 || $paymentId <= 0) {
        return ['ok' => false, 'message' => 'Payment review data is incomplete.'];
    }

    $fetch = $conn->prepare("
        SELECT
            pay.id,
            pay.user_id,
            pay.amount,
            pay.proof_path,
            pay.status,
            pay.incident_id,
            bi.assessed_fee,
            bi.settlement_status,
            bi.workflow_status,
            bi.book_id,
            bi.resolution_notes,
            b.title,
            u.role AS member_role
        FROM payments pay
        JOIN book_incidents bi ON bi.id = pay.incident_id
        JOIN books b ON b.id = bi.book_id
        JOIN users u ON u.id = pay.user_id
        WHERE pay.id = ?
          AND pay.incident_id = ?
        LIMIT 1
    ");
    $fetch->bind_param('ii', $paymentId, $incidentId);
    $fetch->execute();
    $current = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    if (!$current) {
        return ['ok' => false, 'message' => 'The selected incident payment was not found.'];
    }

    if ((string) ($current['status'] ?? '') !== 'pending') {
        return ['ok' => false, 'message' => 'Only pending incident payments can be reviewed.'];
    }

    $normalizedWorkflow = book_incident_normalize_workflow_status((string) ($current['workflow_status'] ?? ''));
    if ((string) ($current['settlement_status'] ?? '') !== 'pending') {
        return ['ok' => false, 'message' => 'This incident no longer needs payment approval.'];
    }
    $paymentAmount = round((float) ($current['amount'] ?? 0), 2);
    if ($paymentAmount <= 0) {
        return ['ok' => false, 'message' => 'This incident payment does not have a valid amount.'];
    }
    if ($normalizedWorkflow === 'closed' && (string) ($current['settlement_status'] ?? '') !== 'pending') {
        return ['ok' => false, 'message' => 'This incident is already closed.'];
    }

    $bookTitle = trim((string) ($current['title'] ?? 'your borrowed book'));
    $memberRole = canonical_role((string) ($current['member_role'] ?? ''));

    if ($decision === 'reject') {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE payments SET status = 'rejected', proof_path = NULL WHERE id = ? AND status = 'pending'");
            $stmt->bind_param('i', $paymentId);
            $stmt->execute();
            $changed = $stmt->affected_rows === 1;
            $stmt->close();

            if ($changed) {
                audit_log($conn, 'book_incident.payment_rejected', [
                    'incident_id' => $incidentId,
                    'payment_id' => $paymentId,
                ]);

                if (in_array($memberRole, ['student', 'faculty'], true)) {
                    create_notification(
                        $conn,
                        $memberRole,
                        'Incident Payment Rejected',
                        'Your payment proof for ' . ($bookTitle !== '' ? $bookTitle : 'your book incident') . ' was rejected. Please upload a new proof.',
                        'critical',
                        (int) $current['user_id']
                    );
                }
            }

            $conn->commit();
            if (!empty($current['proof_path'])) {
                remove_relative_file((string) $current['proof_path']);
            }
        } catch (Throwable $exception) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Unable to reject this incident payment right now.'];
        }

        return ['ok' => true, 'message' => 'Incident payment rejected successfully.'];
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE payments SET status = 'approved' WHERE id = ? AND status = 'pending'");
        $stmt->bind_param('i', $paymentId);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();

        $resolvedAt = date('Y-m-d H:i:s');
        $incidentSync = $conn->prepare("
            UPDATE book_incidents
            SET assessed_fee = ?,
                settlement_status = 'paid',
                workflow_status = 'closed',
                resolved_at = CASE WHEN resolved_at IS NULL THEN ? ELSE resolved_at END,
                resolved_by = CASE WHEN resolved_by IS NULL THEN ? ELSE resolved_by END
            WHERE id = ?
        ");
        $incidentSync->bind_param('dsii', $paymentAmount, $resolvedAt, $actorUserId, $incidentId);
        $incidentSync->execute();
        $incidentSync->close();

        if ($changed) {
            audit_log($conn, 'book_incident.payment_approved', [
                'incident_id' => $incidentId,
                'payment_id' => $paymentId,
            ]);

            if (in_array($memberRole, ['student', 'faculty'], true)) {
                create_notification(
                    $conn,
                    $memberRole,
                    'Incident Payment Approved',
                    'Your payment proof for ' . ($bookTitle !== '' ? $bookTitle : 'your book incident') . ' was approved.',
                    'info',
                    (int) $current['user_id']
                );
            }
        }

        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'Unable to approve this incident payment right now.'];
    }

    return ['ok' => true, 'message' => 'Incident payment approved successfully.'];
}

function update_admin_incident_settlement(mysqli $conn, int $incidentId, int $actorUserId, array $data): array
{
    $settlementStatus = trim((string) ($data['settlement_status'] ?? ''));
    $resolutionNotes = trim((string) ($data['resolution_notes'] ?? ''));

    if ($incidentId <= 0) {
        return ['ok' => false, 'message' => 'Incident record is missing.'];
    }

    if (!isset(book_incident_settlement_form_options($settlementStatus)[$settlementStatus])) {
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

    if (!in_array(book_incident_normalize_workflow_status((string) ($incident['workflow_status'] ?? '')), ['for_payment', 'closed'], true)) {
        return ['ok' => false, 'message' => 'Admin settlement can only be updated after librarian review.'];
    }

    $newWorkflow = (float) ($incident['assessed_fee'] ?? 0) > 0 && $settlementStatus === 'pending'
        ? 'for_payment'
        : 'closed';

    $updateStmt = $conn->prepare("
        UPDATE book_incidents
        SET settlement_status = ?,
            workflow_status = ?,
            resolution_notes = CASE
                WHEN ? <> '' THEN CONCAT(COALESCE(resolution_notes, ''), CASE WHEN COALESCE(resolution_notes, '') = '' THEN '' ELSE '\n\n' END, ?)
                ELSE resolution_notes
            END,
            resolved_at = CASE
                WHEN ? = 'closed' AND resolved_at IS NULL THEN ?
                ELSE resolved_at
            END,
            resolved_by = CASE
                WHEN ? = 'closed' THEN ?
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
