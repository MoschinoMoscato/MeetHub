<?php

// Assicurati che $id e $subresource siano definiti
if (!isset($id)) $id = null;
if (!isset($subresource)) $subresource = null;

$method = $_SERVER['REQUEST_METHOD'];
$currentUserId = new MongoDB\BSON\ObjectId($_SESSION['user_id']);
$db = getDB();

switch ($method) {
    // GET /api/messages?with={userId} - Ottieni conversazione
    case 'GET':
        $withUserId = $_GET['with'] ?? null;
        $limit = min(200, (int)($_GET['limit'] ?? 50));
        
        if (!$withUserId) {
            jsonError('Parametro "with" richiesto', 400, 'MISSING_PARAMETER');
        }
        
        try {
            $withId = new MongoDB\BSON\ObjectId($withUserId);
            
            // Verifica che siano match
            $isMatch = $db->matches->findOne([
                'users' => ['$all' => [$currentUserId, $withId]]
            ]);
            
            if (!$isMatch) {
                jsonError('Puoi vedere solo i messaggi dei tuoi match', 403, 'NOT_MATCH');
            }
            
            $messages = $db->messages->find(
                [
                    '$or' => [
                        ['from_user_id' => $currentUserId, 'to_user_id' => $withId],
                        ['from_user_id' => $withId, 'to_user_id' => $currentUserId],
                    ],
                ],
                ['sort' => ['created_at' => -1], 'limit' => $limit]
            );
            
            $messagesArray = [];
            foreach ($messages as $msg) {
                $messagesArray[] = [
                    'id' => (string)$msg->_id,
                    'text' => $msg->text,
                    'from_user_id' => (string)$msg->from_user_id,
                    'to_user_id' => (string)$msg->to_user_id,
                    'is_sent' => (string)$msg->from_user_id === (string)$currentUserId,
                    'read' => $msg->read ?? false,
                    'created_at' => $msg->created_at->toDateTime()->format('Y-m-d H:i:s')
                ];
            }
            
            jsonResponse([
                'success' => true,
                'data' => array_reverse($messagesArray)
            ]);
        } catch (Exception $e) {
            jsonError('ID utente non valido', 400, 'INVALID_ID');
        }
        break;
    
    // POST /api/messages - Invia nuovo messaggio
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $recipientId = $input['recipient_id'] ?? null;
        $text = trim($input['message'] ?? '');
        
        if (!$recipientId) {
            jsonError('Destinatario mancante', 400, 'MISSING_RECIPIENT');
        }
        
        if (strlen($text) === 0) {
            jsonError('Il messaggio non può essere vuoto', 400, 'EMPTY_MESSAGE');
        }
        
        if (strlen($text) > 1000) {
            jsonError('Il messaggio è troppo lungo (max 1000 caratteri)', 400, 'MESSAGE_TOO_LONG');
        }
        
        try {
            $toId = new MongoDB\BSON\ObjectId($recipientId);
            
            // Verifica match
            $isMatch = $db->matches->findOne([
                'users' => ['$all' => [$currentUserId, $toId]]
            ]);
            
            if (!$isMatch) {
                jsonError('Puoi inviare messaggi solo ai tuoi match', 403, 'NOT_MATCH');
            }
            
            $result = $db->messages->insertOne([
                'from_user_id' => $currentUserId,
                'to_user_id' => $toId,
                'text' => $text,
                'created_at' => new MongoDB\BSON\UTCDateTime(),
                'read' => false
            ]);
            
            jsonResponse([
                'success' => true,
                'data' => [
                    'message_id' => (string)$result->getInsertedId(),
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ], 201);
        } catch (Exception $e) {
            jsonError('ID destinatario non valido', 400, 'INVALID_RECIPIENT');
        }
        break;
    
    // PUT /api/messages/{id}/read - Segna come letto
    case 'PUT':
        if (!$id || $subresource !== 'read') {
            jsonError('Endpoint non valido', 404, 'NOT_FOUND');
        }
        
        try {
            $messageId = new MongoDB\BSON\ObjectId($id);
            
            $result = $db->messages->updateOne(
                [
                    '_id' => $messageId,
                    'to_user_id' => $currentUserId,
                    'read' => false
                ],
                ['$set' => ['read' => true]]
            );
            
            jsonResponse([
                'success' => true,
                'modified_count' => $result->getModifiedCount()
            ]);
        } catch (Exception $e) {
            jsonError('ID messaggio non valido', 400, 'INVALID_ID');
        }
        break;
    
    // DELETE /api/messages/{id} - Elimina messaggio
    case 'DELETE':
        if (!$id) {
            jsonError('ID messaggio richiesto', 400, 'MISSING_ID');
        }
        
        try {
            $messageId = new MongoDB\BSON\ObjectId($id);
            
            // Solo chi ha inviato può eliminare
            $result = $db->messages->deleteOne([
                '_id' => $messageId,
                'from_user_id' => $currentUserId
            ]);
            
            if ($result->getDeletedCount() === 0) {
                jsonError('Messaggio non trovato o non autorizzato', 404, 'NOT_FOUND');
            }
            
            jsonResponse([
                'success' => true,
                'message' => 'Messaggio eliminato'
            ]);
        } catch (Exception $e) {
            jsonError('ID messaggio non valido', 400, 'INVALID_ID');
        }
        break;
    
    default:
        jsonError('Metodo non supportato', 405, 'METHOD_NOT_ALLOWED');
}