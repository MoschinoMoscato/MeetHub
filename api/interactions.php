<?php
 $method          = $_SERVER["REQUEST_METHOD"];
 $current_user_id = new MongoDB\BSON\ObjectId($_SESSION["user_id"]);
 $db              = getDB();

 if(!isset($id)) $id = null;// Assicura che $id sia definito (passato da index.php)

 switch($method)
 {
  // POST /api/interactions/like   - Metti like
  // POST /api/interactions/reject - Rifiuta profilo
  case "POST":

   $action = $id;

   if(!in_array($action, ["like", "reject"]))
   {
    jsonError("Azione non valida", 400, "INVALID_ACTION");
   }

   $input          = json_decode(file_get_contents("php://input"), true) ?? [];
   $target_user_id = $input["user_id"] ?? null;

   if(!$target_user_id)
   {
    jsonError("ID utente mancante", 400, "MISSING_USER_ID");
   }

   try
   {
    $to_id = new MongoDB\BSON\ObjectId($target_user_id);

    if((string)$current_user_id === (string)$to_id)
    {
     jsonError("Non puoi interagire con te stesso", 403, "SELF_INTERACTION");
    }

    // Salva o aggiorna l'interazione
    $db->interactions->updateOne(
     ["from_user_id" => $current_user_id, "to_user_id" => $to_id],
     [
      '$set'         => ["action" => $action, "updated_at" => new MongoDB\BSON\UTCDateTime()],
      '$setOnInsert' => ["created_at" => new MongoDB\BSON\UTCDateTime()]
     ],
     ["upsert" => true]
    );

    $match        = false;
    $match_user_id = null;

    if($action === "like")
    {
     // Verifica se c'è un like reciproco
     $reciprocal_like = $db->interactions->findOne(
     [
      "from_user_id" => $to_id,
      "to_user_id"   => $current_user_id,
      "action"       => "like"
     ]);

     if($reciprocal_like)
     {
      // Crea il match
      $db->matches->updateOne(
       ["users" => ['$all' => [$current_user_id, $to_id]]],
       ['$setOnInsert' =>
       [
        "users"      => [$current_user_id, $to_id],
        "created_at" => new MongoDB\BSON\UTCDateTime()
       ]],
       ["upsert" => true]
      );

      $match         = true;
      $match_user_id = (string)$to_id;
     }
    }

    $response =
    [
     "success" => true,
     "action"  => $action,
     "match"   => $match
    ];

    if($match)
    {
     $response["match_user_id"] = $match_user_id;
     $response["message"]       = "È un match!";
    }

    jsonResponse($response);
   }
   catch(Exception $e)
   {
    jsonError("ID utente non valido", 400, "INVALID_USER_ID");
   }

   break;

  default:
   jsonError("Metodo non supportato", 405, "METHOD_NOT_ALLOWED");
 }
?>
