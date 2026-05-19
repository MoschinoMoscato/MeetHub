<?php
 // Endpoints:
 // GET    /api/users/{id}         - Dettaglio utente specifico
 // PUT    /api/users/profile      - Aggiorna profilo utente corrente
 // POST   /api/users/upload-photo - Upload foto profilo

 $method          = $_SERVER["REQUEST_METHOD"];
 $current_user_id = new MongoDB\BSON\ObjectId($_SESSION["user_id"]);
 $db              = getDB();

 switch($method)
 {
  case "GET":

   // GET /api/users/{id} - Dettaglio utente specifico
   if($id)
   {
    try
    {
     $target_id   = new MongoDB\BSON\ObjectId($id);
     $target_user = $db->users->findOne(["_id" => $target_id]);

     if(!$target_user)
     {
      jsonError("Utente non trovato", 404, "USER_NOT_FOUND");
     }

     // Solo i match reciproci possono vedere il profilo
     $is_match = (bool)$db->matches->findOne(["users" => ['$all' => [$current_user_id, $target_id]]]);

     if(!$is_match)
     {
      jsonError("Profilo visibile solo ai match", 403, "NOT_A_MATCH");
     }

     $pi = $target_user->profile_image ?? null;
     jsonResponse(
     [
      "success" => true,
      "data"    =>
      [
       "id"                => (string)$target_user->_id,
       "name"              => $target_user->name,
       "age"               => calcAge($target_user->birthdate ?? null),
       "gender"            => $target_user->gender    ?? null,
       "city"              => $target_user->city      ?? "",
       "job"               => $target_user->job       ?? "",
       "height"            => $target_user->height    ?? null,
       "bio"               => $target_user->bio       ?? "",
       "profile_image_url" => profileImageUrl($pi),
       "interests"         => $target_user->interests ?? [],
       "traits"            => $target_user->traits    ?? []
      ]
     ]);
    }
    catch(Exception $e)
    {
     jsonError("ID utente non valido", 400, "INVALID_ID");
    }
   }

   else
   {
    jsonError("Endpoint non trovato", 404, "NOT_FOUND");
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

    $image_data    = file_get_contents($file["tmp_name"]);
    $upload_result = $db->uploads->insertOne(
    [
     "owner_user_id" => $current_user_id,
     "kind"          => "profile",
     "mime_type"     => $mime_type,
     "size"          => $file["size"],
     "data"          => new MongoDB\BSON\Binary($image_data, MongoDB\BSON\Binary::TYPE_GENERIC),
     "created_at"    => new MongoDB\BSON\UTCDateTime()
    ]);

    $upload_id = (string)$upload_result->getInsertedId();

    $db->users->updateOne(
     ["_id" => $current_user_id],
     ['$set' => ["profile_image" => $upload_id, "updated_at" => new MongoDB\BSON\UTCDateTime()]]
    );

    jsonResponse(
    [
     "success" => true,
     "data"    =>
     [
      "profile_image_id"  => $upload_id,
      "profile_image_url" => "/api/uploads/" . $upload_id
     ],
     "message" => "Immagine caricata con successo"
    ]);
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

  default:
   jsonError("Metodo non supportato", 405, "METHOD_NOT_ALLOWED");
 }
?>
