<?php
$method = $_SERVER['REQUEST_METHOD'];
$currentUserId = new MongoDB\BSON\ObjectId($_SESSION['user_id']);
$db = getDB();

// Assicurati che $id sia definito (passato da index.php)
if (!isset($id)) {
    $id = null;
}

switch ($method) {
    // POST /api/interactions/like - Metti like
    // POST /api/interactions/reject - Rifiuta
    case 'POST':
        $action = $id;
        
        if (!in_array($action, ['like', 'reject'])) {
            jsonError('Azione non valida', 400, 'INVALID_ACTION');
        }
        
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $targetUserId = $input['user_id'] ?? null;
        
        if (!$targetUserId) {
            jsonError('ID utente mancante', 400, 'MISSING_USER_ID');
        }
        
        try {
            $toId = new MongoDB\BSON\ObjectId($targetUserId);
            
            if ((string)$currentUserId === (string)$toId) {
                jsonError('Non puoi interagire con te stesso', 403, 'SELF_INTERACTION');
            }
            
            // Salva interazione
            $db->interactions->updateOne(
                ['from_user_id' => $currentUserId, 'to_user_id' => $toId],
                ['$set' => [
                    'action' => $action,
                    'updated_at' => new MongoDB\BSON\UTCDateTime()
                ], '$setOnInsert' => [
                    'created_at' => new MongoDB\BSON\UTCDateTime()
                ]],
                ['upsert' => true]
            );
            
            $match = false;
            $matchUserId = null;
            
            if ($action === 'like') {
                // Verifica like reciproco
                $reciprocalLike = $db->interactions->findOne([
                    'from_user_id' => $toId,
                    'to_user_id' => $currentUserId,
                    'action' => 'like'
                ]);
                
                if ($reciprocalLike) {
                    // Crea match
                    $db->matches->updateOne(
                        ['users' => ['$all' => [$currentUserId, $toId]]],
                        ['$setOnInsert' => [
                            'users' => [$currentUserId, $toId],
                            'created_at' => new MongoDB\BSON\UTCDateTime()
                        ]],
                        ['upsert' => true]
                    );
                    $match = true;
                    $matchUserId = (string)$toId;
                }
            }
            
            $response = [
                'success' => true,
                'action' => $action,
                'match' => $match
            ];
            
            if ($match) {
                $response['match_user_id'] = $matchUserId;
                $response['message'] = 'È un match!';
            }
            
            jsonResponse($response);
        } catch (Exception $e) {
            jsonError('ID utente non valido', 400, 'INVALID_USER_ID');
        }
        break;
    
    // GET /api/interactions/likes - Chi ha messo like a me
    // GET /api/interactions/liked - Chi ho messo like
    case 'GET':
        if ($id === 'likes') {
            // Chi ha messo like a me (non ancora match)
            $likes = $db->interactions->find([
                'to_user_id' => $currentUserId,
                'action' => 'like',
                'from_user_id' => ['$nin' => 
                    $db->interactions->distinct('to_user_id', [
                        'from_user_id' => $currentUserId,
                        'action' => 'like'
                    ])
                ]
            ]);
            
            $likesArray = [];
            foreach ($likes as $like) {
                $user = $db->users->findOne(['_id' => $like->from_user_id]);
                if ($user) {
                    $likesArray[] = [
                        'user_id' => (string)$user->_id,
                        'name' => $user->name,
                        'age' => calcAge($user->birthdate ?? null),
                        'profile_image' => $user->profile_image ?? null
                    ];
                }
            }
            
            jsonResponse(['success' => true, 'data' => $likesArray]);
            
        } elseif ($id === 'liked') {
            // Chi ho messo like io
            $liked = $db->interactions->find([
                'from_user_id' => $currentUserId,
                'action' => 'like'
            ]);
            
            $likedArray = [];
            foreach ($liked as $like) {
                $user = $db->users->findOne(['_id' => $like->to_user_id]);
                if ($user) {
                    $likedArray[] = [
                        'user_id' => (string)$user->_id,
                        'name' => $user->name,
                        'profile_image' => $user->profile_image ?? null,
                        'created_at' => $like->created_at->toDateTime()->format('Y-m-d H:i:s')
                    ];
                }
            }
            
            jsonResponse(['success' => true, 'data' => $likedArray]);
        } else {
            jsonError('Endpoint non valido', 404, 'NOT_FOUND');
        }
        break;
    
    // DELETE /api/interactions/{userId} - Rimuovi interazione (dislike)
    case 'DELETE':
        if (!$id) {
            jsonError('ID utente richiesto', 400, 'MISSING_USER_ID');
        }
        
        try {
            $targetId = new MongoDB\BSON\ObjectId($id);
            
            $result = $db->interactions->deleteOne([
                'from_user_id' => $currentUserId,
                'to_user_id' => $targetId
            ]);
            
            // Rimuovi anche eventuale match
            $db->matches->deleteOne([
                'users' => ['$all' => [$currentUserId, $targetId]]
            ]);
            
            jsonResponse([
                'success' => true,
                'deleted' => $result->getDeletedCount() > 0
            ]);
        } catch (Exception $e) {
            jsonError('ID utente non valido', 400, 'INVALID_USER_ID');
        }
        break;
    
    default:
        jsonError('Metodo non supportato', 405, 'METHOD_NOT_ALLOWED');
}