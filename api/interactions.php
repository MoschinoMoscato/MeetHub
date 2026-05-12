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

  // GET /api/interactions/likes - Chi ha messo like a me (senza match)
  // GET /api/interactions/liked - Chi ho messo like io
  case "GET":

   if($id === "likes")
   {
    $likes = $db->interactions->find(
    [
     "to_user_id" => $current_user_id,
     "action"     => "like",
     "from_user_id" =>
     [
      "$nin" => $db->interactions->distinct("to_user_id",
      [
       "from_user_id" => $current_user_id,
       "action"       => "like"
      ])
     ]
    ]);

    $likes_array = [];

    foreach($likes as $like)
    {
     $user = $db->users->findOne(["_id" => $like->from_user_id]);

     if($user)
     {
      $likes_array[] =
      [
       "user_id"       => (string)$user->_id,
       "name"          => $user->name,
       "age"           => calcAge($user->birthdate ?? null),
       "profile_image" => $user->profile_image ?? null
      ];
     }
    }

    jsonResponse(["success" => true, "data" => $likes_array]);
   }
   elseif($id === "liked")
   {
    $liked = $db->interactions->find(["from_user_id" => $current_user_id, "action" => "like"]);

    $liked_array = [];

    foreach($liked as $like)
    {
     $user = $db->users->findOne(["_id" => $like->to_user_id]);

     if($user)
     {
      $liked_array[] =
      [
       "user_id"       => (string)$user->_id,
       "name"          => $user->name,
       "profile_image" => $user->profile_image ?? null,
       "created_at"    => $like->created_at->toDateTime()->format("Y-m-d H:i:s")
      ];
     }
    }

    jsonResponse(["success" => true, "data" => $liked_array]);
   }
   else
   {
    jsonError("Endpoint non valido", 404, "NOT_FOUND");
   }

   break;

  // DELETE /api/interactions/{userId} - Rimuovi interazione
  case "DELETE":

   if(!$id)
   {
    jsonError("ID utente richiesto", 400, "MISSING_USER_ID");
   }

   try
   {
    $target_id = new MongoDB\BSON\ObjectId($id);

    $result = $db->interactions->deleteOne(
    [
     "from_user_id" => $current_user_id,
     "to_user_id"   => $target_id
    ]);

    // Rimuove anche l'eventuale match
    $db->matches->deleteOne(["users" => ['$all' => [$current_user_id, $target_id]]]);

    jsonResponse(["success" => true, "deleted" => $result->getDeletedCount() > 0]);
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
