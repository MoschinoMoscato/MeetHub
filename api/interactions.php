<?php
 $method          = $_SERVER["REQUEST_METHOD"];
 $current_user_id = new MongoDB\BSON\ObjectId($_SESSION["user_id"]);
 $db              = getDB();

 if(!isset($id)) $id = null;

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

   // ── 1. Valida ObjectId — catch solo per parsing ────────────────────────────
   try
   {
    $to_id = new MongoDB\BSON\ObjectId($target_user_id);
   }
   catch(Exception $e)
   {
    jsonError("ID utente non valido", 400, "INVALID_USER_ID");
   }

   if((string)$current_user_id === (string)$to_id)
   {
    jsonError("Non puoi interagire con te stesso", 403, "SELF_INTERACTION");
   }

   // ── 2. Salva / aggiorna l'interazione ─────────────────────────────────────
   try
   {
    $db->interactions->updateOne(
     ["from_user_id" => $current_user_id, "to_user_id" => $to_id],
     [
      '$set'         => ["action" => $action, "updated_at" => new MongoDB\BSON\UTCDateTime()],
      '$setOnInsert' => ["created_at" => new MongoDB\BSON\UTCDateTime()]
     ],
     ["upsert" => true]
    );
   }
   catch(Throwable $e)
   {
    jsonError("Errore durante il salvataggio dell'interazione", 500, "DB_ERROR");
   }

   // ── 3. Controlla like reciproco e crea il match ───────────────────────────
   $match         = false;
   $match_user_id = null;
   $match_id      = null;

   if($action === "like")
   {
    try
    {
     $reciprocal_like = $db->interactions->findOne([
      "from_user_id" => $to_id,
      "to_user_id"   => $current_user_id,
      "action"       => "like"
     ]);

     if($reciprocal_like)
     {
      // Pair_key stabile: i due ID ordinati alfabeticamente garantiscono unicità
      $pair = [(string)$current_user_id, (string)$to_id];
      sort($pair);
      $pair_key = implode("_", $pair);

      // Cerca match esistente tramite pair_key o tramite users (match pre-esistenti)
      $existing_match = $db->matches->findOne([
       '$or' =>
       [
        ["pair_key" => $pair_key],
        ["users"    => ['$all' => [$current_user_id, $to_id]]]
       ]
      ]);

      if(!$existing_match)
      {
       // insertOne evita il bug del $all in contesto upsert
       $insert_result = $db->matches->insertOne([
        "users"      => [$current_user_id, $to_id],
        "pair_key"   => $pair_key,
        "seen_by"    => [],
        "created_at" => new MongoDB\BSON\UTCDateTime()
       ]);
       $match_id = (string)$insert_result->getInsertedId();

       // Indice pair_key — idempotente, sparse per non rompere match senza il campo
       try
       {
        $db->matches->createIndex(
         ["pair_key" => 1],
         ["unique" => true, "sparse" => true]
        );
       }
       catch(Throwable $e) {}
      }
      else
      {
       $match_id = (string)$existing_match->_id;

       // Aggiunge pair_key ai match vecchi che ne sono privi
       if(empty($existing_match->pair_key))
       {
        $db->matches->updateOne(
         ["_id" => $existing_match->_id],
         ['$set' => ["pair_key" => $pair_key]]
        );
       }
      }

      $match         = true;
      $match_user_id = (string)$to_id;
     }
    }
    catch(Throwable $e)
    {
     jsonError("Errore durante la creazione del match", 500, "MATCH_ERROR");
    }
   }

   // ── 4. Risposta ───────────────────────────────────────────────────────────
   $response =
   [
    "success" => true,
    "action"  => $action,
    "match"   => $match
   ];

   if($match)
   {
    $response["match_user_id"] = $match_user_id;
    $response["match_id"]      = $match_id;
    $response["message"]       = "È un match!";
   }

   jsonResponse($response);
   break;

  default:
   jsonError("Metodo non supportato", 405, "METHOD_NOT_ALLOWED");
 }
?>
