<?php
 // Endpoints:
 // GET    /api/uploads/{id} - Serve binary upload (con controllo autorizzazioni)
 // DELETE /api/uploads/{id} - Elimina upload (proprietario o autore messaggio)

 $method          = $_SERVER["REQUEST_METHOD"];
 $current_user_id = new MongoDB\BSON\ObjectId($_SESSION["user_id"]);
 $db              = getDB();

 if($method === "GET")
 {
  if(!$id) { jsonError("ID richiesto", 400, "MISSING_ID"); }

  try { $upload_id = new MongoDB\BSON\ObjectId($id); }
  catch(Exception $e) { jsonError("ID non valido", 400, "INVALID_ID"); }

  $upload = $db->uploads->findOne(["_id" => $upload_id]);
  if(!$upload) { jsonError("Immagine non trovata", 404, "NOT_FOUND"); }

  // ── Controllo autorizzazioni ──────────────────────────────────────────────
  $authorized = false;

  if((string)$upload->owner_user_id === (string)$current_user_id)
  {
   $authorized = true;
  }
  elseif($upload->kind === "profile")
  {
   // Visibile ai match reciproci
   $authorized = (bool)$db->matches->findOne([
    "users" => ['$all' => [$current_user_id, $upload->owner_user_id]]
   ]);
  }
  elseif($upload->kind === "chat_image")
  {
   // Visibile ai partecipanti alla conversazione in cui è stato inviato
   $msg = $db->messages->findOne([
    '$or' =>
    [
     ["image_id"  => $upload_id, "from_user_id" => $current_user_id],
     ["image_id"  => $upload_id, "to_user_id"   => $current_user_id],
     ["upload_id" => $upload_id, "from_user_id" => $current_user_id],
     ["upload_id" => $upload_id, "to_user_id"   => $current_user_id]
    ]
   ]);
   $authorized = (bool)$msg;
  }

  if(!$authorized)
  {
   jsonError("Non autorizzato", 403, "FORBIDDEN");
  }

  // Sovrascrive application/json impostato da api/index.php
  header("Content-Type: " . ($upload->mime_type ?? "application/octet-stream"), true);
  header("Cache-Control: public, max-age=31536000, immutable");
  echo $upload->data->getData();
  exit;
 }

 if($method === "DELETE")
 {
  if(!$id) { jsonError("ID richiesto", 400, "MISSING_ID"); }

  try { $upload_id = new MongoDB\BSON\ObjectId($id); }
  catch(Exception $e) { jsonError("ID non valido", 400, "INVALID_ID"); }

  $upload = $db->uploads->findOne(["_id" => $upload_id]);
  if(!$upload) { jsonError("Immagine non trovata", 404, "NOT_FOUND"); }

  $authorized = (string)$upload->owner_user_id === (string)$current_user_id;

  if(!$authorized)
  {
   // Fallback: upload collegato a un messaggio inviato dall'utente corrente
   $msg = $db->messages->findOne([
    '$or' =>
    [
     ["image_id"  => $upload_id, "from_user_id" => $current_user_id],
     ["upload_id" => $upload_id, "from_user_id" => $current_user_id]
    ]
   ]);
   $authorized = (bool)$msg;
  }

  if(!$authorized)
  {
   jsonError("Non autorizzato", 403, "FORBIDDEN");
  }

  $db->uploads->deleteOne(["_id" => $upload_id]);
  jsonResponse(["success" => true]);
 }

 jsonError("Metodo non supportato", 405, "METHOD_NOT_ALLOWED");
