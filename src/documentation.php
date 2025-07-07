<?php
// /src/documentation.php
// Documentation module business logic functions for SafetyHub
// Contains all database operations and business logic for documentation management

/**
 * Get all documents with pagination and filtering
 */
function getDocuments($conn, $search = '', $tag = '', $favorites_only = false, $user_id = null, $page = 1, $per_page = 20) {
    $offset = ($page - 1) * $per_page;
    $where_conditions = [];
    $params = [];
    $types = '';
    
    // Base query with role-based visibility
    $sql = "SELECT d.*, u.firstName, u.lastName,
                   CASE 
                       WHEN df.user_id IS NOT NULL THEN 1 
                       ELSE 0 
                   END as is_favorite,
                   COUNT(df2.id) as favorite_count
            FROM documents d
            LEFT JOIN users u ON d.uploaded_by = u.id
            LEFT JOIN document_favorites df ON d.id = df.document_id AND df.user_id = ?
            LEFT JOIN document_favorites df2 ON d.id = df2.document_id
            WHERE d.archived_date IS NULL";
    
    $params[] = $user_id ?? $_SESSION['user_id'];
    $types .= 'i';
    
    // Add role-based visibility filter
    $user_role_id = $_SESSION['user_role_id'];
    if ($user_role_id == 6) { // Subcontractor
        $where_conditions[] = "d.visibility IN ('all')";
    } elseif ($user_role_id == 5) { // Employee
        $where_conditions[] = "d.visibility IN ('all', 'employees_only')";
    } elseif ($user_role_id == 4) { // Supervisor
        $where_conditions[] = "d.visibility IN ('all', 'employees_only', 'supervisors_plus')";
    } else { // Managers and Admins (1,2,3)
        // Can see all documents
    }
    
    // Search filter
    if (!empty($search)) {
        $where_conditions[] = "(d.title LIKE ? OR d.description LIKE ? OR d.tags LIKE ?)";
        $search_param = '%' . $search . '%';
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'sss';
    }
    
    // Tag filter
    if (!empty($tag)) {
        $where_conditions[] = "FIND_IN_SET(?, d.tags)";
        $params[] = $tag;
        $types .= 's';
    }
    
    // Favorites filter
    if ($favorites_only) {
        $where_conditions[] = "df.user_id IS NOT NULL";
    }
    
    // Add WHERE conditions
    if (!empty($where_conditions)) {
        $sql .= " AND " . implode(" AND ", $where_conditions);
    }
    
    $sql .= " GROUP BY d.id
              ORDER BY d.is_pinned DESC, d.date_modified DESC
              LIMIT ? OFFSET ?";
    
    $params[] = $per_page;
    $params[] = $offset;
    $types .= 'ii';
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Get Documents SQL Error: " . $conn->error);
        return [];
    }
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $documents = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $documents;
}

/**
 * Get total document count for pagination
 */
function getDocumentCount($conn, $search = '', $tag = '', $favorites_only = false, $user_id = null) {
    $where_conditions = [];
    $params = [];
    $types = '';
    
    $sql = "SELECT COUNT(DISTINCT d.id) as total
            FROM documents d
            LEFT JOIN document_favorites df ON d.id = df.document_id AND df.user_id = ?
            WHERE d.archived_date IS NULL";
    
    $params[] = $user_id ?? $_SESSION['user_id'];
    $types .= 'i';
    
    // Add role-based visibility filter
    $user_role_id = $_SESSION['user_role_id'];
    if ($user_role_id == 6) { // Subcontractor
        $where_conditions[] = "d.visibility IN ('all')";
    } elseif ($user_role_id == 5) { // Employee
        $where_conditions[] = "d.visibility IN ('all', 'employees_only')";
    } elseif ($user_role_id == 4) { // Supervisor
        $where_conditions[] = "d.visibility IN ('all', 'employees_only', 'supervisors_plus')";
    }
    
    // Search filter
    if (!empty($search)) {
        $where_conditions[] = "(d.title LIKE ? OR d.description LIKE ? OR d.tags LIKE ?)";
        $search_param = '%' . $search . '%';
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'sss';
    }
    
    // Tag filter
    if (!empty($tag)) {
        $where_conditions[] = "FIND_IN_SET(?, d.tags)";
        $params[] = $tag;
        $types .= 's';
    }
    
    // Favorites filter
    if ($favorites_only) {
        $where_conditions[] = "df.user_id IS NOT NULL";
    }
    
    // Add WHERE conditions
    if (!empty($where_conditions)) {
        $sql .= " AND " . implode(" AND ", $where_conditions);
    }
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Get Document Count SQL Error: " . $conn->error);
        return 0;
    }
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['total'] ?? 0;
}

/**
 * Add a new document
 */
function addDocument($title, $description, $tags, $access_type, $visibility, $date_modified, $file_info, $uploaded_by, $conn) {
    $file_path = '';
    $file_size = 0;
    $original_filename = '';
    
    if (isset($file_info) && $file_info['error'] == UPLOAD_ERR_OK) {
        // Validate file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_info['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime_type, DOC_ALLOWED_FILE_TYPES)) {
            error_log("Security Alert: Document upload with incorrect MIME type attempted. Name: {$file_info['name']}, Type: {$mime_type}");
            return false;
        }
        
        if ($file_info['size'] > DOC_MAX_FILE_SIZE) {
            error_log("Document file too large: {$file_info['name']}, Size: {$file_info['size']}");
            return false;
        }
        
        // Generate unique filename
        $extension = strtolower(pathinfo($file_info['name'], PATHINFO_EXTENSION));
        $filename = time() . '_' . uniqid() . '.' . $extension;
        $target_file = DOCUMENTATION_UPLOAD_DIR . $filename;
        
        if (move_uploaded_file($file_info['tmp_name'], $target_file)) {
            $file_path = $filename;
            $file_size = $file_info['size'];
            $original_filename = $file_info['name'];
        } else {
            error_log("Failed to move uploaded document file: {$file_info['name']}");
            return false;
        }
    } else {
        error_log("Document upload error: " . $file_info['error']);
        return false;
    }
    
    // Convert tags array to comma-separated string
    if (is_array($tags)) {
        $tags = implode(',', $tags);
    }
    
    $stmt = $conn->prepare("
        INSERT INTO documents (title, description, tags, access_type, visibility, 
                              file_path, file_size, original_filename, 
                              date_modified, upload_date, uploaded_by, is_pinned)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 0)
    ");
    
    if (!$stmt) {
        error_log("Add Document SQL Error: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("sssssiissi", 
        $title, $description, $tags, $access_type, $visibility,
        $file_path, $file_size, $original_filename, $date_modified, $uploaded_by
    );
    
    $success = $stmt->execute();
    if (!$success) {
        error_log("Add Document Execute Error: " . $stmt->error);
    }
    
    $document_id = $conn->insert_id;
    $stmt->close();
    
    return $success ? $document_id : false;
}

/**
 * Get document by ID
 */
function getDocumentById($id, $conn) {
    $stmt = $conn->prepare("
        SELECT d.*, u.firstName, u.lastName,
               CONCAT(u.firstName, ' ', u.lastName) as uploader_name
        FROM documents d
        LEFT JOIN users u ON d.uploaded_by = u.id
        WHERE d.id = ? AND d.archived_date IS NULL
    ");
    
    if (!$stmt) {
        error_log("Get Document By ID SQL Error: " . $conn->error);
        return null;
    }
    
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $document = $result->fetch_assoc();
    $stmt->close();
    
    return $document;
}

/**
 * Toggle document favorite status
 */
function toggleDocumentFavorite($document_id, $user_id, $conn) {
    // Check if already favorited
    $stmt = $conn->prepare("SELECT id FROM document_favorites WHERE document_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $document_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->fetch_assoc();
    $stmt->close();
    
    if ($exists) {
        // Remove favorite
        $stmt = $conn->prepare("DELETE FROM document_favorites WHERE document_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $document_id, $user_id);
        $success = $stmt->execute();
        $stmt->close();
        return $success ? 'removed' : false;
    } else {
        // Add favorite
        $stmt = $conn->prepare("INSERT INTO document_favorites (document_id, user_id, created_date) VALUES (?, ?, NOW())");
        $stmt->bind_param("ii", $document_id, $user_id);
        $success = $stmt->execute();
        $stmt->close();
        return $success ? 'added' : false;
    }
}

/**
 * Get popular tags for tag cloud
 */
function getPopularTags($conn, $limit = 20) {
    $sql = "SELECT 
                TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(d.tags, ',', numbers.n), ',', -1)) as tag,
                COUNT(*) as count
            FROM documents d
            CROSS JOIN (
                SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
                UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10
            ) numbers
            WHERE d.archived_date IS NULL 
            AND d.tags IS NOT NULL 
            AND d.tags != ''
            AND CHAR_LENGTH(d.tags) - CHAR_LENGTH(REPLACE(d.tags, ',', '')) >= numbers.n - 1
            GROUP BY tag
            HAVING tag != ''
            ORDER BY count DESC, tag ASC
            LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Get Popular Tags SQL Error: " . $conn->error);
        return [];
    }
    
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $tags = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $tags;
}

/**
 * Pin/Unpin document
 */
function toggleDocumentPin($document_id, $conn) {
    $stmt = $conn->prepare("UPDATE documents SET is_pinned = NOT is_pinned WHERE id = ?");
    if (!$stmt) {
        error_log("Toggle Document Pin SQL Error: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("i", $document_id);
    $success = $stmt->execute();
    $stmt->close();
    
    return $success;
}

/**
 * Update document pin order
 */
function updateDocumentPinOrder($document_ids, $conn) {
    $conn->autocommit(false);
    
    try {
        foreach ($document_ids as $index => $doc_id) {
            $stmt = $conn->prepare("UPDATE documents SET pin_order = ? WHERE id = ?");
            $stmt->bind_param("ii", $index, $doc_id);
            $stmt->execute();
            $stmt->close();
        }
        
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Update Document Pin Order Error: " . $e->getMessage());
        return false;
    } finally {
        $conn->autocommit(true);
    }
}

/**
 * Archive document
 */
function archiveDocument($document_id, $conn) {
    $stmt = $conn->prepare("UPDATE documents SET archived_date = NOW() WHERE id = ?");
    if (!$stmt) {
        error_log("Archive Document SQL Error: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("i", $document_id);
    $success = $stmt->execute();
    $stmt->close();
    
    return $success;
}

/**
 * Get document addendums
 */
function getDocumentAddendums($parent_id, $conn) {
    $stmt = $conn->prepare("
        SELECT d.*, u.firstName, u.lastName
        FROM documents d
        LEFT JOIN users u ON d.uploaded_by = u.id
        WHERE d.parent_document_id = ? AND d.archived_date IS NULL
        ORDER BY d.upload_date DESC
    ");
    
    if (!$stmt) {
        error_log("Get Document Addendums SQL Error: " . $conn->error);
        return [];
    }
    
    $stmt->bind_param("i", $parent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $addendums = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $addendums;
}

/**
 * Add document addendum
 */
function addDocumentAddendum($parent_id, $title, $description, $tags, $access_type, $visibility, $date_modified, $file_info, $uploaded_by, $conn) {
    $file_path = '';
    $file_size = 0;
    $original_filename = '';
    
    if (isset($file_info) && $file_info['error'] == UPLOAD_ERR_OK) {
        // Validate file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_info['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime_type, DOC_ALLOWED_FILE_TYPES)) {
            error_log("Security Alert: Addendum upload with incorrect MIME type attempted. Name: {$file_info['name']}, Type: {$mime_type}");
            return false;
        }
        
        if ($file_info['size'] > DOC_MAX_FILE_SIZE) {
            error_log("Addendum file too large: {$file_info['name']}, Size: {$file_info['size']}");
            return false;
        }
        
        // Generate unique filename
        $extension = strtolower(pathinfo($file_info['name'], PATHINFO_EXTENSION));
        $filename = time() . '_addendum_' . uniqid() . '.' . $extension;
        $target_file = DOCUMENTATION_UPLOAD_DIR . $filename;
        
        if (move_uploaded_file($file_info['tmp_name'], $target_file)) {
            $file_path = $filename;
            $file_size = $file_info['size'];
            $original_filename = $file_info['name'];
        } else {
            error_log("Failed to move uploaded addendum file: {$file_info['name']}");
            return false;
        }
    } else {
        error_log("Addendum upload error: " . $file_info['error']);
        return false;
    }
    
    // Convert tags array to comma-separated string
    if (is_array($tags)) {
        $tags = implode(',', $tags);
    }
    
    $stmt = $conn->prepare("
        INSERT INTO documents (title, description, tags, access_type, visibility, 
                              file_path, file_size, original_filename, 
                              date_modified, upload_date, uploaded_by, parent_document_id, is_pinned)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, 0)
    ");
    
    if (!$stmt) {
        error_log("Add Document Addendum SQL Error: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("sssssiissii", 
        $title, $description, $tags, $access_type, $visibility,
        $file_path, $file_size, $original_filename, $date_modified, $uploaded_by, $parent_id
    );
    
    $success = $stmt->execute();
    if (!$success) {
        error_log("Add Document Addendum Execute Error: " . $stmt->error);
    }
    
    $addendum_id = $conn->insert_id;
    $stmt->close();
    
    return $success ? $addendum_id : false;
}

?>