<?php
// /src/documentation.php
// Documentation Module Core Functions
// Contains all database operations and business logic for the documentation module

/**
 * Get documents with filtering, pagination, and search
 */
function getDocuments($conn, $search = '', $tag = '', $favorites_only = false, $user_id = 0, $page = 1, $per_page = 12, $include_archived = false) {
    $offset = ($page - 1) * $per_page;
    
    // Base query - allow admins to see archived documents if requested
    $archived_condition = ($include_archived && hasDocAdminAccess()) ? "" : "AND d.archived_date IS NULL";
    
    $sql = "SELECT d.*, u.firstName, u.lastName,
                   CONCAT(u.firstName, ' ', u.lastName) as uploader_name,
                   CASE WHEN df.id IS NOT NULL THEN 1 ELSE 0 END as is_favorited
            FROM documents d
            LEFT JOIN users u ON d.uploaded_by = u.id
            LEFT JOIN document_favorites df ON d.id = df.document_id AND df.user_id = ?";
    
    $where_conditions = ["1=1"];
    $params = [$user_id]; // Always include user_id for favorites check
    $types = "i";
    
    // Apply archived condition
    if (!empty($archived_condition)) {
        $where_conditions[] = substr($archived_condition, 4); // Remove "AND "
    }
    
    // Search filter
    if (!empty($search)) {
        $where_conditions[] = "(d.title LIKE ? OR d.description LIKE ? OR d.tags LIKE ? OR d.original_filename LIKE ?)";
        $search_param = "%{$search}%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
        $types .= "ssss";
    }
    
    // Tag filter
    if (!empty($tag)) {
        $where_conditions[] = "FIND_IN_SET(?, REPLACE(d.tags, ' ', ''))";
        $params[] = $tag;
        $types .= "s";
    }
    
    // Category filter (if provided as tag)
    if (!empty($tag) && array_key_exists($tag, DOC_CATEGORIES)) {
        $where_conditions[] = "d.tags LIKE ?";
        $params[] = "%{$tag}%";
        $types .= "s";
    }
    
    // Favorites filter
    if ($favorites_only) {
        $where_conditions[] = "df.id IS NOT NULL";
    }
    
    if (!empty($where_conditions)) {
        $sql .= " WHERE " . implode(" AND ", $where_conditions);
    }
    
    // Order by: pinned first, then by upload date
    $sql .= " ORDER BY d.is_pinned DESC, d.pin_order ASC, d.upload_date DESC";
    $sql .= " LIMIT ? OFFSET ?";
    
    $params[] = $per_page;
    $params[] = $offset;
    $types .= "ii";
    
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
function getDocumentCount($conn, $search = '', $tag = '', $favorites_only = false, $user_id = 0, $include_archived = false) {
    // Base query - allow admins to see archived documents if requested
    $archived_condition = ($include_archived && hasDocAdminAccess()) ? "" : "AND d.archived_date IS NULL";
    
    $sql = "SELECT COUNT(DISTINCT d.id) as total
            FROM documents d
            LEFT JOIN document_favorites df ON d.id = df.document_id AND df.user_id = ?";
    
    $where_conditions = ["1=1"];
    $params = [$user_id]; // Always include user_id for consistency
    $types = "i";
    
    // Apply archived condition
    if (!empty($archived_condition)) {
        $where_conditions[] = substr($archived_condition, 4); // Remove "AND "
    }
    
    // Search filter
    if (!empty($search)) {
        $where_conditions[] = "(d.title LIKE ? OR d.description LIKE ? OR d.tags LIKE ? OR d.original_filename LIKE ?)";
        $search_param = "%{$search}%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
        $types .= "ssss";
    }
    
    // Tag filter
    if (!empty($tag)) {
        $where_conditions[] = "FIND_IN_SET(?, REPLACE(d.tags, ' ', ''))";
        $params[] = $tag;
        $types .= "s";
    }
    
    // Category filter (if provided as tag)
    if (!empty($tag) && array_key_exists($tag, DOC_CATEGORIES)) {
        $where_conditions[] = "d.tags LIKE ?";
        $params[] = "%{$tag}%";
        $types .= "s";
    }
    
    // Favorites filter
    if ($favorites_only) {
        $where_conditions[] = "df.id IS NOT NULL";
    }
    
    if (!empty($where_conditions)) {
        $sql .= " WHERE " . implode(" AND ", $where_conditions);
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
    // Allow admins to view archived documents
    $is_admin = hasDocAdminAccess();
    $archived_condition = $is_admin ? "" : "AND d.archived_date IS NULL";
    
    $stmt = $conn->prepare("
        SELECT d.*, u.firstName, u.lastName,
               CONCAT(u.firstName, ' ', u.lastName) as uploader_name
        FROM documents d
        LEFT JOIN users u ON d.uploaded_by = u.id
        WHERE d.id = ? $archived_condition
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
 * Unarchive document
 */
function unarchiveDocument($document_id, $conn) {
    $stmt = $conn->prepare("UPDATE documents SET archived_date = NULL WHERE id = ?");
    if (!$stmt) {
        error_log("Unarchive Document SQL Error: " . $conn->error);
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
 * Get document revisions
 */
function getDocumentRevisions($parent_id, $conn) {
    $stmt = $conn->prepare("
        SELECT d.*, u.firstName, u.lastName
        FROM documents d
        LEFT JOIN users u ON d.uploaded_by = u.id
        WHERE d.parent_document_id = ? AND d.archived_date IS NULL AND d.is_revision = 1
        ORDER BY d.upload_date ASC
    ");
    
    if (!$stmt) {
        error_log("Get Document Revisions SQL Error: " . $conn->error);
        return [];
    }
    
    $stmt->bind_param("i", $parent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $revisions = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $revisions;
}

/**
 * Update document
 */
function updateDocument($document_id, $title, $description, $tags, $access_type, $visibility, $date_modified, $conn) {
    // Convert tags array to comma-separated string
    if (is_array($tags)) {
        $tags = implode(',', $tags);
    }
    
    $stmt = $conn->prepare("
        UPDATE documents 
        SET title = ?, description = ?, tags = ?, access_type = ?, 
            visibility = ?, date_modified = ?
        WHERE id = ?
    ");
    
    if (!$stmt) {
        error_log("Update Document SQL Error: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("ssssssi", $title, $description, $tags, $access_type, $visibility, $date_modified, $document_id);
    $success = $stmt->execute();
    $stmt->close();
    
    return $success;
}

/**
 * Search documents with advanced filtering
 */
function searchDocuments($conn, $filters = [], $sort = 'upload_date', $order = 'DESC', $page = 1, $per_page = 12) {
    $offset = ($page - 1) * $per_page;
    
    $sql = "SELECT d.*, u.firstName, u.lastName,
                   CONCAT(u.firstName, ' ', u.lastName) as uploader_name
            FROM documents d
            LEFT JOIN users u ON d.uploaded_by = u.id
            WHERE d.archived_date IS NULL";
    
    $params = [];
    $types = "";
    
    // Apply filters
    if (!empty($filters['search'])) {
        $sql .= " AND (d.title LIKE ? OR d.description LIKE ? OR d.tags LIKE ? OR d.original_filename LIKE ?)";
        $search_param = "%{$filters['search']}%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
        $types .= "ssss";
    }
    
    if (!empty($filters['category'])) {
        $sql .= " AND d.tags LIKE ?";
        $params[] = "%{$filters['category']}%";
        $types .= "s";
    }
    
    if (!empty($filters['access_type'])) {
        $sql .= " AND d.access_type = ?";
        $params[] = $filters['access_type'];
        $types .= "s";
    }
    
    if (!empty($filters['uploader'])) {
        $sql .= " AND d.uploaded_by = ?";
        $params[] = $filters['uploader'];
        $types .= "i";
    }
    
    if (!empty($filters['date_from'])) {
        $sql .= " AND d.upload_date >= ?";
        $params[] = $filters['date_from'] . ' 00:00:00';
        $types .= "s";
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND d.upload_date <= ?";
        $params[] = $filters['date_to'] . ' 23:59:59';
        $types .= "s";
    }
    
    if (!empty($filters['file_type'])) {
        $sql .= " AND d.original_filename LIKE ?";
        $params[] = "%.{$filters['file_type']}";
        $types .= "s";
    }
    
    // Apply sorting
    $valid_sort_columns = ['title', 'upload_date', 'date_modified', 'file_size', 'uploader_name'];
    $sort = in_array($sort, $valid_sort_columns) ? $sort : 'upload_date';
    $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
    
    $sql .= " ORDER BY d.is_pinned DESC, d.{$sort} {$order}";
    $sql .= " LIMIT ? OFFSET ?";
    
    $params[] = $per_page;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Search Documents SQL Error: " . $conn->error);
        return [];
    }
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $documents = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $documents;
}

/**
 * Get uploaders list for filter dropdown
 */
function getDocumentUploaders($conn) {
    $sql = "SELECT DISTINCT u.id, CONCAT(u.firstName, ' ', u.lastName) as name
            FROM users u
            INNER JOIN documents d ON u.id = d.uploaded_by
            WHERE d.archived_date IS NULL
            ORDER BY name";
    
    $result = $conn->query($sql);
    if (!$result) {
        error_log("Get Document Uploaders SQL Error: " . $conn->error);
        return [];
    }
    
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Check if user can view a specific document based on visibility settings
 */
function canUserViewDocument($document, $user_role_id) {
    if (!$document) {
        return false;
    }
    
    // Admins can view all documents
    if (in_array($user_role_id, [1, 2, 3])) { // Super Admin, Admin, Manager
        return true;
    }
    
    // Check visibility settings
    switch ($document['visibility']) {
        case 'all':
            return true;
            
        case 'employees_only':
            return in_array($user_role_id, [4, 5, 6]); // Supervisor, Employee, Subcontractor
            
        case 'supervisors_plus':
            return in_array($user_role_id, [1, 2, 3, 4]); // Super Admin, Admin, Manager, Supervisor
            
        case 'managers_only':
            return in_array($user_role_id, [1, 2, 3]); // Super Admin, Admin, Manager
            
        case 'admins_only':
            return in_array($user_role_id, [1, 2]); // Super Admin, Admin only
            
        default:
            return false;
    }
}

/**
 * Delete document permanently (Super Admin only)
 * This function completely removes a document and its file from the system
 */
function deleteDocument($document_id, $conn) {
    // Get document info first to delete the physical file
    $document = getDocumentById($document_id, $conn);
    if (!$document) {
        error_log("Delete Document Error: Document ID $document_id not found");
        return false;
    }
    
    // Start transaction for data consistency
    $conn->autocommit(false);
    
    try {
        // Delete related records first (due to foreign key constraints)
        
        // 1. Delete favorites
        $stmt = $conn->prepare("DELETE FROM document_favorites WHERE document_id = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare favorites deletion: " . $conn->error);
        }
        $stmt->bind_param("i", $document_id);
        $stmt->execute();
        $stmt->close();
        
        // 2. Delete any addendums or revisions (documents that have this as parent)
        $stmt = $conn->prepare("DELETE FROM documents WHERE parent_document_id = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare related documents deletion: " . $conn->error);
        }
        $stmt->bind_param("i", $document_id);
        $stmt->execute();
        $stmt->close();
        
        // 3. Delete the main document record
        $stmt = $conn->prepare("DELETE FROM documents WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare main document deletion: " . $conn->error);
        }
        $stmt->bind_param("i", $document_id);
        $success = $stmt->execute();
        $affected_rows = $stmt->affected_rows;
        $stmt->close();
        
        if (!$success || $affected_rows === 0) {
            throw new Exception("Failed to delete document record from database");
        }
        
        // 4. Delete the physical file if it exists
        if (!empty($document['file_path'])) {
            $full_file_path = DOCUMENTATION_UPLOAD_DIR . $document['file_path'];
            if (file_exists($full_file_path)) {
                if (!unlink($full_file_path)) {
                    // Log the error but don't fail the transaction
                    error_log("Warning: Failed to delete physical file: " . $full_file_path);
                }
            }
        }
        
        // Commit the transaction
        $conn->commit();
        
        // Log successful deletion
        error_log("Document deleted successfully: ID=$document_id, File=" . $document['original_filename']);
        
        return true;
        
    } catch (Exception $e) {
        // Rollback the transaction on any error
        $conn->rollback();
        error_log("Delete Document Error: " . $e->getMessage());
        return false;
        
    } finally {
        // Always restore autocommit
        $conn->autocommit(true);
    }
}

?>