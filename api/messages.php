<?php
 ini_set("display_errors", 0);
 require_once __DIR__ . "/../config.php";

 header("Content-Type: application/json");

 // Auth: API risponde sempre JSON
 if(!isset($_SESSION["user_id"]))
 {
  http_response_code(401);
  echo json_encode(["error" => "session_expired"]);
  exit;
 }

 $db              = getDB();
 $current_user_id = new MongoDB\BSON\ObjectId($_SESSION["user_id"]);
 $method          = $_SERVER["REQUEST_METHOD"];

 // Verifica match reciproco tra current_user e other
 function verifyMatch($db, $current_user_id, $other_id_str)
 {
  try { $other_id = new MongoDB\BSON\ObjectId($other_id_str); }
  catch(Exception $e) { return false; }

  $a = $db->interactions->findOne(["from_user_id" => $current_user_id, "to_user_id" => $other_id, "action" => "like"]);
  if(!$a) return false;
  $b = $db->interactions->findOne(["from_user_id" => $other_id, "to_user_id" => $current_user_id, "action" => "like"]);
  return (bool)$b;
 }

 // ─── GET: recupera messaggi della conversazione ───────────────────────────────
 if($method === "GET")
 {
  $with_str = $_GET["conversation_with"] ?? "";

  if(!$with_str || !verifyMatch($db, $current_user_id, $with_str))
  {
   http_response_code(403);
   echo json_encode(["error" => "Accesso negato"]);
   exit;
  }

  $other_id = new MongoDB\BSON\ObjectId($with_str);

  $db->messages->updateMany(
   ["from_user_id" => $other_id, "to_user_id" => $current_user_id, "read" => false],
   ['$set' => ["read" => true]]
  );

  $cursor = $db->messages->find(
  [
   '$or' =>
   [
    ["from_user_id" => $current_user_id, "to_user_id" => $other_id],
    ["from_user_id" => $other_id, "to_user_id" => $current_user_id]
   ]
  ],
  ["sort" => ["created_at" => 1], "limit" => 200]);

  $messages = [];
  foreach($cursor as $msg)
  {
   $image_url = null;
   if(($msg->type ?? "text") === "image")
   {
    if(isset($msg->image_id))                               // campo nuovo
     $image_url = "/api/uploads/" . (string)$msg->image_id;
    elseif(isset($msg->upload_id))                          // fallback temp legacy
     $image_url = "/api/uploads/" . (string)$msg->upload_id;
    elseif(isset($msg->image_path) && $msg->image_path)    // fallback filesystem legacy
     $image_url = "/" . $msg->image_path;
   }

   $messages[] =
   [
    "id"        => (string)$msg->_id,
    "from"      => (string)$msg->from_user_id,
    "type"      => $msg->type ?? "text",
    "text"      => $msg->text ?? null,
    "image_url" => $image_url,
    "image_id"  => isset($msg->image_id) ? (string)$msg->image_id
                 : (isset($msg->upload_id) ? (string)$msg->upload_id : null),
    "read"      => $msg->read ?? false,
    "edited"    => $msg->edited ?? false
   ];
  }

  echo json_encode(["success" => true, "messages" => $messages]);
  exit;
 }

 // ─── POST: invia messaggio (testo o immagine) ─────────────────────────────────
 if($method === "POST")
 {
  $recipient_id_str = $_POST["recipient_id"] ?? "";
  $type             = $_POST["type"]         ?? "text";

  if(!$recipient_id_str || !verifyMatch($db, $current_user_id, $recipient_id_str))
  {
   http_response_code(403);
   echo json_encode(["error" => "Destinatario non valido"]);
   exit;
  }

  $recipient_id = new MongoDB\BSON\ObjectId($recipient_id_str);

  if($type === "image")
  {
   if(!isset($_FILES["image"]) || $_FILES["image"]["error"] !== UPLOAD_ERR_OK)
   {
    http_response_code(400);
    echo json_encode(["error" => "Nessuna immagine ricevuta"]);
    exit;
   }

   $file         = $_FILES["image"];
   $allowed_mime = ["image/jpeg", "image/png", "image/gif", "image/webp"];
   $mime         = mime_content_type($file["tmp_name"]);

   if(!$mime || !in_array($mime, $allowed_mime))
   {
    http_response_code(400);
    echo json_encode(["error" => "Tipo di file non supportato"]);
    exit;
   }

   if($file["size"] > 5 * 1024 * 1024)
   {
    http_response_code(400);
    echo json_encode(["error" => "Immagine troppo grande (max 5 MB)"]);
    exit;
   }

   $image_data    = file_get_contents($file["tmp_name"]);
   $upload_result = $db->uploads->insertOne(
   [
    "owner_user_id" => $current_user_id,
    "kind"          => "chat_image",
    "mime_type"     => $mime,
    "size"          => $file["size"],
    "data"          => new MongoDB\BSON\Binary($image_data, MongoDB\BSON\Binary::TYPE_GENERIC),
    "created_at"    => new MongoDB\BSON\UTCDateTime()
   ]);

   $image_id = $upload_result->getInsertedId();

   try
   {
    $db->messages->insertOne(
    [
     "from_user_id" => $current_user_id,
     "to_user_id"   => $recipient_id,
     "type"         => "image",
     "image_id"     => $image_id,
     "text"         => null,
     "created_at"   => new MongoDB\BSON\UTCDateTime(),
     "read"         => false
    ]);
    echo json_encode(["success" => true]);
   }
   catch(Throwable $e)
   {
    $db->uploads->deleteOne(["_id" => $image_id]);
    http_response_code(500);
    echo json_encode(["error" => "Errore database"]);
   }
  }
  else
  {
   $text = trim($_POST["message"] ?? "");

   if($text === "")
   {
    http_response_code(400);
    echo json_encode(["error" => "Messaggio vuoto"]);
    exit;
   }

   try
   {
    $db->messages->insertOne(
    [
     "from_user_id" => $current_user_id,
     "to_user_id"   => $recipient_id,
     "type"         => "text",
     "text"         => $text,
     "created_at"   => new MongoDB\BSON\UTCDateTime(),
     "read"         => false
    ]);
    echo json_encode(["success" => true]);
   }
   catch(Throwable $e)
   {
    http_response_code(500);
    echo json_encode(["error" => "Errore database"]);
   }
  }
  exit;
 }

 // ─── PUT: modifica messaggio o segna come letto ──────────────────────────────
 if($method === "PUT")
 {
  $input = getJsonInput();

  // Modifica testo di un messaggio
  if(isset($input["message_id"]))
  {
   $msg_id_str = $input["message_id"] ?? "";
   $new_text   = trim($input["text"]       ?? "");

   if(!$msg_id_str || $new_text === "")
   {
    http_response_code(400);
    echo json_encode(["error" => "Dati mancanti"]);
    exit;
   }

   if(mb_strlen($new_text) > 1000)
   {
    http_response_code(400);
    echo json_encode(["error" => "Messaggio troppo lungo"]);
    exit;
   }

   try { $msg_id = new MongoDB\BSON\ObjectId($msg_id_str); }
   catch(Exception $e)
   {
    http_response_code(400);
    echo json_encode(["error" => "ID non valido"]);
    exit;
   }

   $msg = $db->messages->findOne(["_id" => $msg_id, "from_user_id" => $current_user_id, "type" => "text"]);

   if(!$msg)
   {
    http_response_code(404);
    echo json_encode(["error" => "Messaggio non trovato"]);
    exit;
   }

   try
   {
    $db->messages->updateOne(
     ["_id" => $msg_id],
     ['$set' => ["text" => $new_text, "edited" => true, "edited_at" => new MongoDB\BSON\UTCDateTime()]]
    );
    echo json_encode(["success" => true]);
   }
   catch(Throwable $e)
   {
    http_response_code(500);
    echo json_encode(["error" => "Errore database"]);
   }
   exit;
  }

  // Segna messaggi come letti
  $with_str = $input["conversation_with"] ?? "";

  if(!$with_str || !verifyMatch($db, $current_user_id, $with_str))
  {
   http_response_code(403);
   echo json_encode(["error" => "Accesso negato"]);
   exit;
  }

  $other_id = new MongoDB\BSON\ObjectId($with_str);
  $result   = $db->messages->updateMany(
   ["from_user_id" => $other_id, "to_user_id" => $current_user_id, "read" => false],
   ['$set' => ["read" => true]]
  );

  echo json_encode(["success" => true, "updated" => $result->getModifiedCount()]);
  exit;
 }

 // ─── DELETE: elimina un proprio messaggio ─────────────────────────────────────
 if($method === "DELETE")
 {
  $msg_id_str = $_GET["message_id"] ?? "";

  if(!$msg_id_str)
  {
   http_response_code(400);
   echo json_encode(["error" => "message_id richiesto"]);
   exit;
  }

  try { $msg_id = new MongoDB\BSON\ObjectId($msg_id_str); }
  catch(Exception $e)
  {
   http_response_code(400);
   echo json_encode(["error" => "ID non valido"]);
   exit;
  }

  $msg = $db->messages->findOne(["_id" => $msg_id, "from_user_id" => $current_user_id]);

  if(!$msg)
  {
   http_response_code(404);
   echo json_encode(["error" => "Messaggio non trovato"]);
   exit;
  }

  if(isset($msg->image_id) && $msg->image_id)                 // campo nuovo
  {
   try { $db->uploads->deleteOne(["_id" => $msg->image_id]); }
   catch(Throwable $e) {}
  }
  elseif(isset($msg->upload_id) && $msg->upload_id)           // fallback temp legacy
  {
   try { $db->uploads->deleteOne(["_id" => $msg->upload_id]); }
   catch(Throwable $e) {}
  }

  // Pulizia fallback filesystem legacy
  if(isset($msg->image_path) && $msg->image_path)
  {
   $fp = __DIR__ . "/../" . $msg->image_path;
   if(file_exists($fp)) @unlink($fp);
  }

  $db->messages->deleteOne(["_id" => $msg_id]);
  echo json_encode(["success" => true]);
  exit;
 }

 http_response_code(405);
 echo json_encode(["error" => "Metodo non supportato"]);
