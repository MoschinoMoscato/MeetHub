<?php
 ini_set("display_errors", 0);
 require_once __DIR__ . "/../config.php";

 header("Content-Type: application/json");

 if(!isset($_SESSION["user_id"]))
 {
  http_response_code(401);
  echo json_encode(["error" => "session_expired"]);
  exit;
 }

 $db              = getDB();
 $current_user_id = new MongoDB\BSON\ObjectId($_SESSION["user_id"]);
 $method          = $_SERVER["REQUEST_METHOD"];

 // ─── GET: profilo pubblico di un match ────────────────────────────────────────
 if($method === "GET")
 {
  $target_str = $_GET["user_id"] ?? "";

  if(!$target_str)
  {
   http_response_code(400);
   echo json_encode(["error" => "user_id richiesto"]);
   exit;
  }

  try { $target_id = new MongoDB\BSON\ObjectId($target_str); }
  catch(Exception $e)
  {
   http_response_code(400);
   echo json_encode(["error" => "ID non valido"]);
   exit;
  }

  // Solo i match reciproci possono vedere il profilo
  $a = $db->interactions->findOne(["from_user_id" => $current_user_id, "to_user_id" => $target_id, "action" => "like"]);
  $b = $db->interactions->findOne(["from_user_id" => $target_id, "to_user_id" => $current_user_id, "action" => "like"]);

  if(!$a || !$b)
  {
   http_response_code(403);
   echo json_encode(["error" => "Solo i match possono vedere il profilo"]);
   exit;
  }

  $user = $db->users->findOne(["_id" => $target_id], ["projection" => ["password" => 0]]);

  if(!$user)
  {
   http_response_code(404);
   echo json_encode(["error" => "Utente non trovato"]);
   exit;
  }

  $interests = [];
  if(isset($user->interests))
   foreach($user->interests as $i) $interests[] = (string)$i;

  $traits = [];
  if(isset($user->traits))
   foreach($user->traits as $t) $traits[] = (string)$t;

  echo json_encode(
  [
   "success" => true,
   "user"    =>
   [
    "id"            => (string)$user->_id,
    "name"          => $user->name          ?? "",
    "age"           => calcAge($user->birthdate ?? ""),
    "city"          => $user->city          ?? "",
    "job"           => $user->job           ?? "",
    "bio"           => $user->bio           ?? "",
    "height"        => $user->height        ?? null,
    "profile_image" => $user->profile_image ?? null,
    "gender"        => $user->gender        ?? "",
    "interests"     => $interests,
    "traits"        => $traits
   ]
  ]);
  exit;
 }

 http_response_code(405);
 echo json_encode(["error" => "Metodo non supportato"]);
