<?php
 if(!isset($id))          $id          = null;// Assicura che $id sia definito (passato da index.php)
 if(!isset($subresource)) $subresource = null;// Assicura che $subresource sia definito

 $method          = $_SERVER["REQUEST_METHOD"];
 $current_user_id = new MongoDB\BSON\ObjectId($_SESSION["user_id"]);
 $db              = getDB();

 switch($method)
 {
  // GET /api/messages?with={userId} - Ottieni la conversazione con un utente
  case "GET":

   $with_user_id = $_GET["with"] ?? null;
   $limit        = min(200, (int)($_GET["limit"] ?? 50));

   if(!$with_user_id)
   {
    jsonError("Parametro \"with\" richiesto", 400, "MISSING_PARAMETER");
   }

   try
   {
    $with_id  = new MongoDB\BSON\ObjectId($with_user_id);

    // Verifica che i due utenti siano match
    $is_match = $db->matches->findOne(["users" => ['$all' => [$current_user_id, $with_id]]]);

    if(!$is_match)
    {
     jsonError("Puoi vedere solo i messaggi dei tuoi match", 403, "NOT_MATCH");
    }

    $messages = $db->messages->find(
    [
     '$or' =>
     [
      ["from_user_id" => $current_user_id, "to_user_id" => $with_id],
      ["from_user_id" => $with_id,         "to_user_id" => $current_user_id]
     ]
    ],
    ["sort" => ["created_at" => -1], "limit" => $limit]);

    $messages_array = [];

    foreach($messages as $msg)
    {
     $messages_array[] =
     [
      "id"           => (string)$msg->_id,
      "text"         => $msg->text,
      "from_user_id" => (string)$msg->from_user_id,
      "to_user_id"   => (string)$msg->to_user_id,
      "is_sent"      => (string)$msg->from_user_id === (string)$current_user_id,
      "read"         => $msg->read ?? false,
      "created_at"   => $msg->created_at->toDateTime()->format("Y-m-d H:i:s")
     ];
    }

    jsonResponse(["success" => true, "data" => array_reverse($messages_array)]);
   }
   catch(Exception $e)
   {
    jsonError("ID utente non valido", 400, "INVALID_ID");
   }

   break;

  // POST /api/messages - Invia un nuovo messaggio
  case "POST":

   $input        = json_decode(file_get_contents("php://input"), true) ?? [];
   $recipient_id = $input["recipient_id"] ?? null;
   $text         = trim($input["message"] ?? "");

   if(!$recipient_id)
   {
    jsonError("Destinatario mancante", 400, "MISSING_RECIPIENT");
   }

   if(strlen($text) === 0)
   {
    jsonError("Il messaggio non può essere vuoto", 400, "EMPTY_MESSAGE");
   }

   if(strlen($text) > 1000)
   {
    jsonError("Il messaggio è troppo lungo (max 1000 caratteri)", 400, "MESSAGE_TOO_LONG");
   }

   try
   {
    $to_id = new MongoDB\BSON\ObjectId($recipient_id);

    // Verifica che i due utenti siano match
    $is_match = $db->matches->findOne(["users" => ['$all' => [$current_user_id, $to_id]]]);

    if(!$is_match)
    {
     jsonError("Puoi inviare messaggi solo ai tuoi match", 403, "NOT_MATCH");
    }

    $result = $db->messages->insertOne(
    [
     "from_user_id" => $current_user_id,
     "to_user_id"   => $to_id,
     "text"         => $text,
     "created_at"   => new MongoDB\BSON\UTCDateTime(),
     "read"         => false
    ]);

    jsonResponse(
    [
     "success" => true,
     "data"    =>
     [
      "message_id" => (string)$result->getInsertedId(),
      "created_at" => date("Y-m-d H:i:s")
     ]
    ], 201);
   }
   catch(Exception $e)
   {
    jsonError("ID destinatario non valido", 400, "INVALID_RECIPIENT");
   }

   break;

  // PUT /api/messages/{id}/read - Segna un messaggio come letto
  case "PUT":

   if(!$id || $subresource !== "read")
   {
    jsonError("Endpoint non valido", 404, "NOT_FOUND");
   }

   try
   {
    $message_id = new MongoDB\BSON\ObjectId($id);

    $result = $db->messages->updateOne(
     ["_id" => $message_id, "to_user_id" => $current_user_id, "read" => false],
     ['$set' => ["read" => true]]
    );

    jsonResponse(["success" => true, "modified_count" => $result->getModifiedCount()]);
   }
   catch(Exception $e)
   {
    jsonError("ID messaggio non valido", 400, "INVALID_ID");
   }

   break;

  // DELETE /api/messages/{id} - Elimina un messaggio
  case "DELETE":

   if(!$id)
   {
    jsonError("ID messaggio richiesto", 400, "MISSING_ID");
   }

   try
   {
    $message_id = new MongoDB\BSON\ObjectId($id);

    // Solo il mittente può eliminare il proprio messaggio
    $result = $db->messages->deleteOne(
    [
     "_id"          => $message_id,
     "from_user_id" => $current_user_id
    ]);

    if($result->getDeletedCount() === 0)
    {
     jsonError("Messaggio non trovato o non autorizzato", 404, "NOT_FOUND");
    }

    jsonResponse(["success" => true, "message" => "Messaggio eliminato"]);
   }
   catch(Exception $e)
   {
    jsonError("ID messaggio non valido", 400, "INVALID_ID");
   }

   break;

  default:
   jsonError("Metodo non supportato", 405, "METHOD_NOT_ALLOWED");
 }
?>
