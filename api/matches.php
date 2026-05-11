<?php
// Assicurati che $id sia definito
if (!isset($id)) $id = null;

$method = $_SERVER['REQUEST_METHOD'];
$currentUserId = new MongoDB\BSON\ObjectId($_SESSION['user_id']);
$db = getDB();

switch ($method) {
    // GET /api/matches - Lista tutti i match
    case 'GET':
        $matches = $db->matches->find(['users' => $currentUserId]);
        
        $matchesArray = [];
        foreach ($matches as $match) {
            // Trova l'altro utente
            $otherUserId = null;
            foreach ($match->users as $uid) {
                if ((string)$uid !== (string)$currentUserId) {
                    $otherUserId = $uid;
                    break;
                }
            }
            
            if ($otherUserId) {
                $user = $db->users->findOne(['_id' => $otherUserId]);
                if ($user) {
                    // Ultimo messaggio
                    $lastMessage = $db->messages->findOne(
                        [
                            '$or' => [
                                ['from_user_id' => $currentUserId, 'to_user_id' => $otherUserId],
                                ['from_user_id' => $otherUserId, 'to_user_id' => $currentUserId],
                            ]
                        ],
                        ['sort' => ['created_at' => -1]]
                    );
                    
                    // Messaggi non letti
                    $unreadCount = $db->messages->countDocuments([
                        'from_user_id' => $otherUserId,
                        'to_user_id' => $currentUserId,
                        'read' => false
                    ]);
                    
                    $matchesArray[] = [
                        'user_id' => (string)$user->_id,
                        'name' => $user->name,
                        'age' => calcAge($user->birthdate ?? null),
                        'city' => $user->city ?? '',
                        'profile_image' => $user->profile_image ?? null,
                        'last_message' => $lastMessage ? $lastMessage->text : null,
                        'last_message_time' => $lastMessage ? $lastMessage->created_at->toDateTime()->format('Y-m-d H:i:s') : null,
                        'unread_count' => $unreadCount,
                        'match_date' => $match->created_at->toDateTime()->format('Y-m-d')
                    ];
                }
            }
        }
        
        // Ordina per ultimo messaggio
        usort($matchesArray, function($a, $b) {
            return strtotime($b['last_message_time'] ?? '1970-01-01') - strtotime($a['last_message_time'] ?? '1970-01-01');
        });
        
        jsonResponse(['success' => true, 'data' => $matchesArray]);
        break;
    
    // DELETE /api/matches/{userId} - Rimuovi match (unmatch)
    case 'DELETE':
        if (!$id) {
            jsonError('ID utente richiesto', 400, 'MISSING_USER_ID');
        }
        
        try {
            $targetId = new MongoDB\BSON\ObjectId($id);
            
            $result = $db->matches->deleteOne([
                'users' => ['$all' => [$currentUserId, $targetId]]
            ]);
            
            // Opzionale: cancella anche i messaggi?
            // $db->messages->deleteMany([
            //     '$or' => [
            //         ['from_user_id' => $currentUserId, 'to_user_id' => $targetId],
            //         ['from_user_id' => $targetId, 'to_user_id' => $currentUserId]
            //     ]
            // ]);
            
            jsonResponse([
                'success' => true,
                'message' => 'Match rimosso'
            ]);
        } catch (Exception $e) {
            jsonError('ID utente non valido', 400, 'INVALID_USER_ID');
        }
        break;
    
    default:
        jsonError('Metodo non supportato', 405, 'METHOD_NOT_ALLOWED');
}