<?php
/**
 * API Users - Gestione utenti
 * Endpoints:
 * GET    /api/users/discover     - Profili da scoprire (con filtri)
 * GET    /api/users/{id}         - Dettaglio utente specifico
 * GET    /api/users              - Lista base utenti
 * PUT    /api/users/{id}         - Aggiorna profilo utente
 * DELETE /api/users/{id}         - Elimina account
 */

$method = $_SERVER['REQUEST_METHOD'];
$currentUserId = new MongoDB\BSON\ObjectId($_SESSION['user_id']);
$db = getDB();

// Helper per leggere input JSON (se non esiste già in config.php)
if (!function_exists('getJsonInput')) {
    function getJsonInput() {
        $input = json_decode(file_get_contents('php://input'), true);
        return is_array($input) ? $input : [];
    }
}

switch ($method) {
    // ============================================================
    // GET - Lettura dati
    // ============================================================
    case 'GET':
        
        // --------------------------------------------------------
        // GET /api/users/discover - Profili da scoprire (con filtri)
        // --------------------------------------------------------
        if ($id === 'discover') {
            // Parametri paginazione
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(50, (int)($_GET['limit'] ?? 12));
            $skip = ($page - 1) * $limit;
            
            // Filtro interessi
            $interests = $_GET['interests'] ?? [];
            if (is_string($interests)) {
                $interests = [$interests];
            }
            
            $user = currentUser();
            $preferences = $user->preferences ?? (object)[];
            
            // Query base: esclude se stesso e richiede profilo completo
            $query = [
                '_id' => ['$ne' => $currentUserId],
                'profile_complete' => true
            ];
            
            // Filtro per interessi
            if (!empty($interests)) {
                $query['interests'] = ['$in' => $interests];
            }
            
            // Filtro per età (dalle preferenze dell'utente)
            if (!empty($preferences->min_age) && !empty($user->birthdate)) {
                $today = new DateTime();
                $maxBirth = (clone $today)->modify('-' . $preferences->min_age . ' years');
                $minBirth = (clone $today)->modify('-' . ($preferences->max_age ?? 99) . ' years');
                $query['birthdate'] = [
                    '$lte' => $maxBirth->format('Y-m-d'),
                    '$gte' => $minBirth->format('Y-m-d')
                ];
            }
            
            // Filtro per genere preferito
            if (!empty($preferences->gender) && is_array($preferences->gender)) {
                $query['gender'] = ['$in' => $preferences->gender];
            }
            
            // Esclude i profili già visti (like o reject)
            $seen = $db->interactions->distinct('to_user_id', ['from_user_id' => $currentUserId]);
            if (!empty($seen)) {
                if (!isset($query['_id']['$nin'])) {
                    $query['_id']['$nin'] = [];
                }
                $query['_id']['$nin'] = array_merge($query['_id']['$nin'], $seen);
            }
            
            // Conta totale per paginazione
            $total = $db->users->countDocuments($query);
            
            // Recupera profili
            $users = $db->users->find($query, ['limit' => $limit, 'skip' => $skip]);
            
            $usersArray = [];
            foreach ($users as $u) {
                // Calcola distanza se disponibile
                $distance = null;
                if (!empty($user->lat) && !empty($user->lng) && !empty($u->lat) && !empty($u->lng)) {
                    $distance = haversineDistance(
                        (float)$user->lat, 
                        (float)$user->lng,
                        (float)$u->lat, 
                        (float)$u->lng
                    );
                    $distance = round($distance, 1);
                }
                
                // Filtro distanza massima (post-processing)
                $maxDist = $preferences->max_dist ?? 0;
                if ($maxDist > 0 && $distance !== null && $distance > $maxDist) {
                    continue; // Salta profili troppo distanti
                }
                
                $usersArray[] = [
                    'id' => (string)$u->_id,
                    'name' => $u->name,
                    'age' => calcAge($u->birthdate ?? null),
                    'city' => $u->city ?? '',
                    'job' => $u->job ?? '',
                    'bio' => $u->bio ?? '',
                    'profile_image' => $u->profile_image ?? null,
                    'interests' => $u->interests ?? [],
                    'traits' => $u->traits ?? [],
                    'distance' => $distance,
                    'height' => $u->height ?? null
                ];
            }
            
            jsonResponse([
                'success' => true,
                'data' => $usersArray,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
        }
        
        // --------------------------------------------------------
        // GET /api/users/{id} - Dettaglio utente specifico
        // --------------------------------------------------------
        elseif ($id && $id !== 'discover' && $id !== 'profile') {
            try {
                $targetId = new MongoDB\BSON\ObjectId($id);
                $targetUser = $db->users->findOne(['_id' => $targetId]);
                
                if (!$targetUser) {
                    jsonError('Utente non trovato', 404, 'USER_NOT_FOUND');
                }
                
                // Verifica se l'utente corrente ha già interagito con questo profilo
                $interaction = $db->interactions->findOne([
                    'from_user_id' => $currentUserId,
                    'to_user_id' => $targetId
                ]);
                
                // Verifica se è un match
                $isMatch = (bool)$db->matches->findOne([
                    'users' => ['$all' => [$currentUserId, $targetId]]
                ]);
                
                // Verifica se l'utente corrente ha messo like
                $iLiked = $interaction && $interaction->action === 'like';
                
                // Verifica se l'altro utente ha messo like
                $theyLiked = (bool)$db->interactions->findOne([
                    'from_user_id' => $targetId,
                    'to_user_id' => $currentUserId,
                    'action' => 'like'
                ]);
                
                $response = [
                    'id' => (string)$targetUser->_id,
                    'name' => $targetUser->name,
                    'email' => $targetUser->email,
                    'age' => calcAge($targetUser->birthdate ?? null),
                    'birthdate' => $targetUser->birthdate ?? null,
                    'gender' => $targetUser->gender ?? null,
                    'city' => $targetUser->city ?? '',
                    'job' => $targetUser->job ?? '',
                    'height' => $targetUser->height ?? null,
                    'bio' => $targetUser->bio ?? '',
                    'profile_image' => $targetUser->profile_image ?? null,
                    'interests' => $targetUser->interests ?? [],
                    'traits' => $targetUser->traits ?? [],
                    'is_match' => $isMatch,
                    'i_liked' => $iLiked,
                    'they_liked' => $theyLiked,
                    'profile_complete' => $targetUser->profile_complete ?? false
                ];
                
                jsonResponse(['success' => true, 'data' => $response]);
                
            } catch (Exception $e) {
                jsonError('ID utente non valido', 400, 'INVALID_ID');
            }
        }
        
        // --------------------------------------------------------
        // GET /api/users/profile - Profilo dell'utente corrente
        // --------------------------------------------------------
        elseif ($id === 'profile') {
            $user = currentUser();
            if (!$user) {
                jsonError('Utente non trovato', 404, 'USER_NOT_FOUND');
            }
            
            jsonResponse([
                'success' => true,
                'data' => [
                    'id' => (string)$user->_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'gender' => $user->gender ?? null,
                    'birthdate' => $user->birthdate ?? null,
                    'age' => calcAge($user->birthdate ?? null),
                    'bio' => $user->bio ?? '',
                    'city' => $user->city ?? '',
                    'job' => $user->job ?? '',
                    'height' => $user->height ?? null,
                    'profile_image' => $user->profile_image ?? null,
                    'interests' => $user->interests ?? [],
                    'traits' => $user->traits ?? [],
                    'preferences' => $user->preferences ?? (object)[],
                    'profile_complete' => $user->profile_complete ?? false,
                    'lat' => $user->lat ?? null,
                    'lng' => $user->lng ?? null,
                    'created_at' => $user->created_at ? $user->created_at->toDateTime()->format('Y-m-d H:i:s') : null
                ]
            ]);
        }
        
        // --------------------------------------------------------
        // GET /api/users - Lista base utenti (solo admin)
        // --------------------------------------------------------
        else {
            $limit = min(50, (int)($_GET['limit'] ?? 20));
            $users = $db->users->find([], ['limit' => $limit]);
            $usersArray = [];
            foreach ($users as $u) {
                $usersArray[] = [
                    'id' => (string)$u->_id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'profile_complete' => $u->profile_complete ?? false,
                    'created_at' => $u->created_at ? $u->created_at->toDateTime()->format('Y-m-d H:i:s') : null
                ];
            }
            jsonResponse(['success' => true, 'data' => $usersArray]);
        }
        break;
    
    // ============================================================
    // POST - Creazione dati (solo per azioni speciali)
    // ============================================================
    case 'POST':
        
        // POST /api/users/upload-photo - Upload foto profilo
        if ($id === 'upload-photo') {
            // Verifica che sia stato caricato un file
            if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
                jsonError('Nessuna immagine caricata o errore nel caricamento', 400, 'UPLOAD_ERROR');
            }
            
            $file = $_FILES['profile_image'];
            $maxSize = 5 * 1024 * 1024; // 5MB
            
            // Verifica dimensione
            if ($file['size'] > $maxSize) {
                jsonError('L\'immagine deve essere inferiore a 5MB', 400, 'FILE_TOO_LARGE');
            }
            
            // Verifica tipo file
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);
            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif'
            ];
            
            if (!isset($allowedTypes[$mimeType])) {
                jsonError('Formato immagine non supportato. Usa JPG, PNG, WEBP o GIF.', 400, 'INVALID_FORMAT');
            }
            
            // Crea cartella se non esiste
            $uploadDir = __DIR__ . '/../uploads/profiles';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Genera nome unico
            $fileName = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
            $targetPath = $uploadDir . '/' . $fileName;
            
            // Sposta file
            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                jsonError('Impossibile salvare l\'immagine', 500, 'SAVE_ERROR');
            }
            
            $profileImage = 'uploads/profiles/' . $fileName;
            
            // Aggiorna database
            $db->users->updateOne(
                ['_id' => $currentUserId],
                ['$set' => ['profile_image' => $profileImage, 'updated_at' => new MongoDB\BSON\UTCDateTime()]]
            );
            
            jsonResponse([
                'success' => true,
                'data' => ['profile_image' => $profileImage],
                'message' => 'Immagine caricata con successo'
            ]);
        }
        
        // POST /api/users/update-location - Aggiorna posizione GPS
        elseif ($id === 'update-location') {
            $input = getJsonInput();
            $lat = $input['lat'] ?? null;
            $lng = $input['lng'] ?? null;
            
            if ($lat === null || $lng === null) {
                jsonError('Latitudine e longitudine richieste', 400, 'MISSING_COORDINATES');
            }
            
            if (!is_numeric($lat) || !is_numeric($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                jsonError('Coordinate non valide', 400, 'INVALID_COORDINATES');
            }
            
            $db->users->updateOne(
                ['_id' => $currentUserId],
                ['$set' => ['lat' => (float)$lat, 'lng' => (float)$lng, 'updated_at' => new MongoDB\BSON\UTCDateTime()]]
            );
            
            jsonResponse(['success' => true, 'message' => 'Posizione aggiornata']);
        }
        
        else {
            jsonError('Endpoint non trovato', 404, 'NOT_FOUND');
        }
        break;
    
    // ============================================================
    // PUT - Aggiornamento dati
    // ============================================================
    case 'PUT':
        
        if (!$id) {
            jsonError('ID utente richiesto', 400, 'MISSING_ID');
        }
        
        // Verifica che l'utente stia modificando il proprio profilo
        if ($id !== $_SESSION['user_id'] && $id !== 'profile') {
            jsonError('Non puoi modificare altri utenti', 403, 'FORBIDDEN');
        }
        
        $input = getJsonInput();
        $updateData = [];
        
        // Campi consentiti per l'aggiornamento
        $allowedFields = [
            'bio', 'city', 'job', 'height', 
            'interests', 'traits', 'preferences',
            'name', 'profile_image'
        ];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $updateData[$field] = $input[$field];
            }
        }
        
        // Gestione speciale per profile_complete
        if (isset($input['profile_complete'])) {
            $updateData['profile_complete'] = (bool)$input['profile_complete'];
        }
        
        if (empty($updateData)) {
            jsonError('Nessun dato da aggiornare', 400, 'NO_DATA');
        }
        
        $updateData['updated_at'] = new MongoDB\BSON\UTCDateTime();
        
        $result = $db->users->updateOne(
            ['_id' => $currentUserId],
            ['$set' => $updateData]
        );
        
        jsonResponse([
            'success' => true,
            'message' => 'Profilo aggiornato con successo',
            'modified_count' => $result->getModifiedCount(),
            'updated_fields' => array_keys($updateData)
        ]);
        break;
    
    // ============================================================
    // DELETE - Eliminazione dati
    // ============================================================
    case 'DELETE':
        
        if (!$id) {
            jsonError('ID utente richiesto', 400, 'MISSING_ID');
        }
        
        // Verifica che l'utente stia eliminando il proprio account
        if ($id !== $_SESSION['user_id']) {
            jsonError('Non puoi eliminare altri utenti', 403, 'FORBIDDEN');
        }
        
        try {
            // Elimina tutte le interazioni dell'utente
            $db->interactions->deleteMany(['from_user_id' => $currentUserId]);
            $db->interactions->deleteMany(['to_user_id' => $currentUserId]);
            
            // Elimina tutti i messaggi
            $db->messages->deleteMany(['from_user_id' => $currentUserId]);
            $db->messages->deleteMany(['to_user_id' => $currentUserId]);
            
            // Elimina tutti i match
            $db->matches->deleteMany(['users' => $currentUserId]);
            
            // Elimina l'utente
            $result = $db->users->deleteOne(['_id' => $currentUserId]);
            
            if ($result->getDeletedCount() === 0) {
                jsonError('Utente non trovato', 404, 'USER_NOT_FOUND');
            }
            
            // Distruggi la sessione
            session_destroy();
            
            jsonResponse([
                'success' => true,
                'message' => 'Account eliminato con successo'
            ]);
            
        } catch (Exception $e) {
            jsonError('Errore durante l\'eliminazione dell\'account: ' . $e->getMessage(), 500, 'DELETE_ERROR');
        }
        break;
    
    // ============================================================
    // Metodo non supportato
    // ============================================================
    default:
        jsonError('Metodo non supportato', 405, 'METHOD_NOT_ALLOWED');
}
?>