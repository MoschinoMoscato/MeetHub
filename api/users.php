<?php
 // Endpoints:
 // GET    /api/users/discover     - Profili da scoprire (con filtri e paginazione)
 // GET    /api/users/{id}         - Dettaglio utente specifico
 // GET    /api/users/profile      - Profilo dell'utente corrente
 // GET    /api/users              - Lista base utenti
 // PUT    /api/users/{id}         - Aggiorna profilo utente
 // POST   /api/users/upload-photo - Upload foto profilo
 // POST   /api/users/update-location - Aggiorna posizione GPS
 // DELETE /api/users/{id}         - Elimina account

 $method          = $_SERVER["REQUEST_METHOD"];
 $current_user_id = new MongoDB\BSON\ObjectId($_SESSION["user_id"]);
 $db              = getDB();

 switch($method)
 {
  case "GET":

   // GET /api/users/discover - Profili da scoprire
   if($id === "discover")
   {
    $page  = max(1, (int)($_GET["page"]  ?? 1));
    $limit = min(50, (int)($_GET["limit"] ?? 12));
    $skip  = ($page - 1) * $limit;

    $interests = $_GET["interests"] ?? [];

    if(is_string($interests))
    {
     $interests = [$interests];
    }

    $user        = currentUser();
    $preferences = $user->preferences ?? (object)[];

    // Query base: esclude se stesso e richiede profilo completo
    $query =
    [
     "_id"              => ['$ne' => $current_user_id],
     "profile_complete" => true
    ];

    if(!empty($interests))
    {
     $query["interests"] = ['$in' => $interests];
    }

    // Filtro per età dalle preferenze utente
    if(!empty($preferences->min_age) && !empty($user->birthdate))
    {
     $today     = new DateTime();
     $max_birth = (clone $today)->modify("-" . $preferences->min_age . " years");
     $min_birth = (clone $today)->modify("-" . ($preferences->max_age ?? 99) . " years");
     $query["birthdate"] =
     [
      '$lte' => $max_birth->format("Y-m-d"),
      '$gte' => $min_birth->format("Y-m-d")
     ];
    }

    if(!empty($preferences->gender) && is_array($preferences->gender))
    {
     $query["gender"] = ['$in' => $preferences->gender];
    }

    // Esclude i profili già visti (like o reject)
    $seen = $db->interactions->distinct("to_user_id", ["from_user_id" => $current_user_id]);

    if(!empty($seen))
    {
     if(!isset($query["_id"]['$nin']))
     {
      $query["_id"]['$nin'] = [];
     }
     $query["_id"]['$nin'] = array_merge($query["_id"]['$nin'], $seen);
    }

    $total = $db->users->countDocuments($query);// Conta totale per la paginazione
    $users = $db->users->find($query, ["limit" => $limit, "skip" => $skip]);

    $users_array = [];

    foreach($users as $u)
    {
     // Calcola la distanza se le coordinate sono disponibili
     $distance = null;

     if(!empty($user->lat) && !empty($user->lng) && !empty($u->lat) && !empty($u->lng))
     {
      $distance = haversineDistance((float)$user->lat, (float)$user->lng, (float)$u->lat, (float)$u->lng);
      $distance = round($distance, 1);
     }

     // Filtro distanza massima (post-processing)
     $max_dist = $preferences->max_dist ?? 0;

     if($max_dist > 0 && $distance !== null && $distance > $max_dist)
     {
      continue;// Salta i profili troppo distanti
     }

     $users_array[] =
     [
      "id"            => (string)$u->_id,
      "name"          => $u->name,
      "age"           => calcAge($u->birthdate ?? null),
      "city"          => $u->city     ?? "",
      "job"           => $u->job      ?? "",
      "bio"           => $u->bio      ?? "",
      "profile_image" => $u->profile_image ?? null,
      "interests"     => $u->interests ?? [],
      "traits"        => $u->traits    ?? [],
      "distance"      => $distance,
      "height"        => $u->height    ?? null
     ];
    }

    jsonResponse(
    [
     "success"    => true,
     "data"       => $users_array,
     "pagination" =>
     [
      "page"  => $page,
      "limit" => $limit,
      "total" => $total,
      "pages" => ceil($total / $limit)
     ]
    ]);
   }

   // GET /api/users/{id} - Dettaglio utente specifico
   elseif($id && $id !== "discover" && $id !== "profile")
   {
    try
    {
     $target_id   = new MongoDB\BSON\ObjectId($id);
     $target_user = $db->users->findOne(["_id" => $target_id]);

     if(!$target_user)
     {
      jsonError("Utente non trovato", 404, "USER_NOT_FOUND");
     }

     // Verifica se l'utente corrente ha già interagito con questo profilo
     $interaction = $db->interactions->findOne(
     [
      "from_user_id" => $current_user_id,
      "to_user_id"   => $target_id
     ]);

     $is_match  = (bool)$db->matches->findOne(["users" => ['$all' => [$current_user_id, $target_id]]]);
     $i_liked   = $interaction && $interaction->action === "like";
     $they_liked = (bool)$db->interactions->findOne(
     [
      "from_user_id" => $target_id,
      "to_user_id"   => $current_user_id,
      "action"       => "like"
     ]);

     jsonResponse(
     [
      "success" => true,
      "data"    =>
      [
       "id"               => (string)$target_user->_id,
       "name"             => $target_user->name,
       "email"            => $target_user->email,
       "age"              => calcAge($target_user->birthdate ?? null),
       "birthdate"        => $target_user->birthdate ?? null,
       "gender"           => $target_user->gender    ?? null,
       "city"             => $target_user->city      ?? "",
       "job"              => $target_user->job       ?? "",
       "height"           => $target_user->height    ?? null,
       "bio"              => $target_user->bio       ?? "",
       "profile_image"    => $target_user->profile_image ?? null,
       "interests"        => $target_user->interests ?? [],
       "traits"           => $target_user->traits    ?? [],
       "is_match"         => $is_match,
       "i_liked"          => $i_liked,
       "they_liked"       => $they_liked,
       "profile_complete" => $target_user->profile_complete ?? false
      ]
     ]);
    }
    catch(Exception $e)
    {
     jsonError("ID utente non valido", 400, "INVALID_ID");
    }
   }

   // GET /api/users/profile - Profilo dell'utente corrente
   elseif($id === "profile")
   {
    $user = currentUser();

    if(!$user)
    {
     jsonError("Utente non trovato", 404, "USER_NOT_FOUND");
    }

    jsonResponse(
    [
     "success" => true,
     "data"    =>
     [
      "id"               => (string)$user->_id,
      "name"             => $user->name,
      "email"            => $user->email,
      "gender"           => $user->gender    ?? null,
      "birthdate"        => $user->birthdate ?? null,
      "age"              => calcAge($user->birthdate ?? null),
      "bio"              => $user->bio        ?? "",
      "city"             => $user->city       ?? "",
      "job"              => $user->job        ?? "",
      "height"           => $user->height     ?? null,
      "profile_image"    => $user->profile_image ?? null,
      "interests"        => $user->interests  ?? [],
      "traits"           => $user->traits     ?? [],
      "preferences"      => $user->preferences ?? (object)[],
      "profile_complete" => $user->profile_complete ?? false,
      "lat"              => $user->lat ?? null,
      "lng"              => $user->lng ?? null,
      "created_at"       => $user->created_at ? $user->created_at->toDateTime()->format("Y-m-d H:i:s") : null
     ]
    ]);
   }

   // GET /api/users - Lista base utenti
   else
   {
    $limit = min(50, (int)($_GET["limit"] ?? 20));
    $users = $db->users->find([], ["limit" => $limit]);
    $users_array = [];

    foreach($users as $u)
    {
     $users_array[] =
     [
      "id"               => (string)$u->_id,
      "name"             => $u->name,
      "email"            => $u->email,
      "profile_complete" => $u->profile_complete ?? false,
      "created_at"       => $u->created_at ? $u->created_at->toDateTime()->format("Y-m-d H:i:s") : null
     ];
    }

    jsonResponse(["success" => true, "data" => $users_array]);
   }

   break;

  case "POST":

   // POST /api/users/upload-photo - Upload foto profilo
   if($id === "upload-photo")
   {
    if(!isset($_FILES["profile_image"]) || $_FILES["profile_image"]["error"] !== UPLOAD_ERR_OK)
    {
     jsonError("Nessuna immagine caricata o errore nel caricamento", 400, "UPLOAD_ERROR");
    }

    $file     = $_FILES["profile_image"];
    $max_size = 5 * 1024 * 1024;// 5MB

    if($file["size"] > $max_size)
    {
     jsonError("L'immagine deve essere inferiore a 5MB", 400, "FILE_TOO_LARGE");
    }

    $finfo     = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $finfo->file($file["tmp_name"]);
    $allowed_types =
    [
     "image/jpeg" => "jpg",
     "image/png"  => "png",
     "image/webp" => "webp",
     "image/gif"  => "gif"
    ];

    if(!isset($allowed_types[$mime_type]))
    {
     jsonError("Formato immagine non supportato. Usa JPG, PNG, WEBP o GIF.", 400, "INVALID_FORMAT");
    }

    $upload_dir = __DIR__ . "/../uploads/profiles";

    if(!is_dir($upload_dir))
    {
     mkdir($upload_dir, 0755, true);
    }

    $file_name   = bin2hex(random_bytes(16)) . "." . $allowed_types[$mime_type];
    $target_path = $upload_dir . "/" . $file_name;

    if(!move_uploaded_file($file["tmp_name"], $target_path))
    {
     jsonError("Impossibile salvare l'immagine", 500, "SAVE_ERROR");
    }

    $profile_image = "uploads/profiles/" . $file_name;

    $db->users->updateOne(
     ["_id" => $current_user_id],
     ['$set' => ["profile_image" => $profile_image, "updated_at" => new MongoDB\BSON\UTCDateTime()]]
    );

    jsonResponse(
    [
     "success" => true,
     "data"    => ["profile_image" => $profile_image],
     "message" => "Immagine caricata con successo"
    ]);
   }

   // POST /api/users/update-location - Aggiorna posizione GPS
   elseif($id === "update-location")
   {
    $input = getJsonInput();
    $lat   = $input["lat"] ?? null;
    $lng   = $input["lng"] ?? null;

    if($lat === null || $lng === null)
    {
     jsonError("Latitudine e longitudine richieste", 400, "MISSING_COORDINATES");
    }

    if(!is_numeric($lat) || !is_numeric($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180)
    {
     jsonError("Coordinate non valide", 400, "INVALID_COORDINATES");
    }

    $db->users->updateOne(
     ["_id" => $current_user_id],
     ['$set' => ["lat" => (float)$lat, "lng" => (float)$lng, "updated_at" => new MongoDB\BSON\UTCDateTime()]]
    );

    jsonResponse(["success" => true, "message" => "Posizione aggiornata"]);
   }
   else
   {
    jsonError("Endpoint non trovato", 404, "NOT_FOUND");
   }

   break;

  case "PUT":

   if(!$id)
   {
    jsonError("ID utente richiesto", 400, "MISSING_ID");
   }

   if($id !== $_SESSION["user_id"] && $id !== "profile")
   {
    jsonError("Non puoi modificare altri utenti", 403, "FORBIDDEN");
   }

   $input       = getJsonInput();
   $update_data = [];

   // Campi consentiti per l'aggiornamento
   $allowed_fields = ["bio", "city", "job", "height", "interests", "traits", "preferences", "name", "profile_image"];

   foreach($allowed_fields as $field)
   {
    if(array_key_exists($field, $input))
    {
     $update_data[$field] = $input[$field];
    }
   }

   if(isset($input["profile_complete"]))
   {
    $update_data["profile_complete"] = (bool)$input["profile_complete"];
   }

   if(empty($update_data))
   {
    jsonError("Nessun dato da aggiornare", 400, "NO_DATA");
   }

   $update_data["updated_at"] = new MongoDB\BSON\UTCDateTime();

   $result = $db->users->updateOne(["_id" => $current_user_id], ['$set' => $update_data]);

   jsonResponse(
   [
    "success"        => true,
    "message"        => "Profilo aggiornato con successo",
    "modified_count" => $result->getModifiedCount(),
    "updated_fields" => array_keys($update_data)
   ]);

   break;

  case "DELETE":

   if(!$id)
   {
    jsonError("ID utente richiesto", 400, "MISSING_ID");
   }

   if($id !== $_SESSION["user_id"])
   {
    jsonError("Non puoi eliminare altri utenti", 403, "FORBIDDEN");
   }

   try
   {
    // Eliminazione di tutte le interazioni, messaggi, match e account
    $db->interactions->deleteMany(["from_user_id" => $current_user_id]);
    $db->interactions->deleteMany(["to_user_id"   => $current_user_id]);
    $db->messages->deleteMany(["from_user_id"     => $current_user_id]);
    $db->messages->deleteMany(["to_user_id"       => $current_user_id]);
    $db->matches->deleteMany(["users"             => $current_user_id]);

    $result = $db->users->deleteOne(["_id" => $current_user_id]);

    if($result->getDeletedCount() === 0)
    {
     jsonError("Utente non trovato", 404, "USER_NOT_FOUND");
    }

    session_destroy();

    jsonResponse(["success" => true, "message" => "Account eliminato con successo"]);
   }
   catch(Exception $e)
   {
    jsonError("Errore durante l'eliminazione dell'account: " . $e->getMessage(), 500, "DELETE_ERROR");
   }

   break;

  default:
   jsonError("Metodo non supportato", 405, "METHOD_NOT_ALLOWED");
 }
?>
