<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

require_admin_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
if (!is_array($data)) {
    json_response(['ok' => false, 'error' => 'invalid_json'], 400);
    exit;
}

$entity = strtolower(trim((string) ($data['entity'] ?? '')));
$entityId = trim((string) ($data['id'] ?? ''));
$action = strtolower(trim((string) ($data['action'] ?? '')));

$validEntities = ['artist' => 'ARTISTS', 'album' => 'ALBUMS', 'track' => 'TRACKS'];
if (!isset($validEntities[$entity]) || $entityId === '') {
    json_response(['ok' => false, 'error' => 'invalid_entity_or_id'], 400);
    exit;
}

$newStatus = null;
if ($action === 'approve') {
    $newStatus = CATALOG_STATUS_APPROVED;
} elseif ($action === 'reject') {
    $newStatus = CATALOG_STATUS_REJECTED;
} else {
    json_response(['ok' => false, 'error' => 'invalid_action'], 400);
    exit;
}

$user = current_user();
if ($user === null) {
    json_response(['ok' => false, 'error' => 'unauthorized'], 401);
    exit;
}
$reviewerId = $user['user_id'];

$table = $validEntities[$entity];
$idColumn = $entity === 'artist' ? 'artist_id' : ($entity === 'album' ? 'album_id' : 'track_id');

try {
    $pdo = db();
    $existsSql = "SELECT 1 FROM {$table} WHERE {$idColumn} = :id LIMIT 1";
    $exists = $pdo->prepare($existsSql);
    $exists->execute([':id' => $entityId]);
    if ($exists->fetchColumn() === false) {
        json_response(['ok' => false, 'error' => 'not_found'], 404);
        exit;
    }

    $sql = "
        UPDATE {$table}
        SET status = :status,
            reviewed_by = :reviewed_by
        WHERE {$idColumn} = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':status' => $newStatus,
        ':reviewed_by' => $reviewerId,
        ':id' => $entityId,
    ]);
    json_response(['ok' => true, 'entity' => $entity, 'id' => $entityId, 'status' => $newStatus]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'server_error'], 500);
}
