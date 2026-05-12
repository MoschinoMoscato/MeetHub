<?php
 if(!isset($id)) $id = null;// Assicura che $id sia definito (passato da index.php)

 $method          = $_SERVER["REQUEST_METHOD"];
 $current_user_id = new MongoDB\BSON\ObjectId($_SESSION["user_id"]);
 $db              = getDB();

 switch($method)
 {
  // GET /api/matches - Lista tutti i match dell'utente
  case "GET":

   $matches      = $db->matches->find(["users" => $current_user_id]);
   $matches_array = [];

   foreach($matches as $match)
   {
    // Trova l'altro utente nel match
    $other_user_id = null;

    foreach($match->users as $uid)
    {
     if((string)$uid !== (string)$current_user_id)
     {
      $other_user_id = $uid;
      break;
     }
    }

    if($other_user_id)
    {
     $user = $db->users->findOne(["_id" => $other_user_id]);

     if($user)
     {
      // Recupera l'ultimo messaggio della conversazione
      $last_message = $db->messages->findOne(
      [
       '$or' =>
       [
        ["from_user_id" => $current_user_id, "to_user_id" => $other_user_id],
        ["from_user_id" => $other_user_id,   "to_user_id" => $current_user_id]
       ]
      ],
      ["sort" => ["created_at" => -1]]);

      // Conta i messaggi non letti
      $unread_count = $db->messages->countDocuments(
      [
       "from_user_id" => $other_user_id,
       "to_user_id"   => $current_user_id,
       "read"         => false
      ]);

      $matches_array[] =
      [
       "user_id"           => (string)$user->_id,
       "name"              => $user->name,
       "age"               => calcAge($user->birthdate ?? null),
       "city"              => $user->city ?? "",
       "profile_image"     => $user->profile_image ?? null,
       "last_message"      => $last_message ? $last_message->text : null,
       "last_message_time" => $last_message ? $last_message->created_at->toDateTime()->format("Y-m-d H:i:s") : null,
       "unread_count"      => $unread_count,
       "match_date"        => $match->created_at->toDateTime()->format("Y-m-d")
      ];
     }
    }
   }

   // Ordina per data dell'ultimo messaggio
   usort($matches_array, function($a, $b)
   {
    return strtotime($b["last_message_time"] ?? "1970-01-01") - strtotime($a["last_message_time"] ?? "1970-01-01");
   });

   jsonResponse(["success" => true, "data" => $matches_array]);

   break;

  // DELETE /api/matches/{userId} - Rimuovi match (unmatch)
  case "DELETE":

   if(!$id)
   {
    jsonError("ID utente richiesto", 400, "MISSING_USER_ID");
   }

   try
   {
    $target_id = new MongoDB\BSON\ObjectId($id);

    $db->matches->deleteOne(["users" => ['$all' => [$current_user_id, $target_id]]]);

    jsonResponse(["success" => true, "message" => "Match rimosso"]);
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
