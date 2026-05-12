<?php
 require_once "config.php";
 requireLogin();

 header("Content-Type: application/json");

 if($_SERVER["REQUEST_METHOD"] !== "POST")
 {
  echo json_encode(["error" => "Method not allowed"]);
  exit;
 }

 $action = $_POST["action"] ?? "";

 // Segna un singolo match come visto dall'utente corrente
 if($action === "seen_match")
 {
  $match_id_str = $_POST["match_id"] ?? "";

  if(!$match_id_str)
  {
   echo json_encode(["error" => "Missing match_id"]);
   exit;
  }

  try
  {
   $db       = getDB();
   $cur_id   = new MongoDB\BSON\ObjectId($_SESSION["user_id"]);
   $match_id = new MongoDB\BSON\ObjectId($match_id_str);

   // Aggiunge current user a seen_by solo se è davvero parte del match
   $db->matches->updateOne(
    ["_id" => $match_id, "users" => $cur_id],
    ['$addToSet' => ["seen_by" => $cur_id]]
   );

   echo json_encode(["success" => true]);
  }
  catch(Throwable $e)
  {
   echo json_encode(["error" => $e->getMessage()]);
  }

  exit;
 }

 echo json_encode(["error" => "Unknown action"]);
