<?php
 // Endpoints:
 // GET    /api/uploads/{id} - Serve binary upload
 // DELETE /api/uploads/{id} - Elimina upload (solo proprietario)

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

  $upload = $db->uploads->findOne(["_id" => $upload_id, "owner_user_id" => $current_user_id]);

  if(!$upload) { jsonError("Immagine non trovata", 404, "NOT_FOUND"); }

  $db->uploads->deleteOne(["_id" => $upload_id]);
  jsonResponse(["success" => true]);
 }

 jsonError("Metodo non supportato", 405, "METHOD_NOT_ALLOWED");
