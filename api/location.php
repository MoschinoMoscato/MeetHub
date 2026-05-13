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

 if($_SERVER["REQUEST_METHOD"] !== "POST")
 {
  http_response_code(405);
  echo json_encode(["error" => "Metodo non supportato"]);
  exit;
 }

 $lat = $_POST["lat"] ?? null;
 $lng = $_POST["lng"] ?? null;

 if($lat === null || $lng === null || !is_numeric($lat) || !is_numeric($lng))
 {
  http_response_code(400);
  echo json_encode(["error" => "Coordinate non valide"]);
  exit;
 }

 $lat = (float)$lat;
 $lng = (float)$lng;

 if($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180)
 {
  http_response_code(400);
  echo json_encode(["error" => "Coordinate fuori range"]);
  exit;
 }

 try
 {
  $db = getDB();
  $id = new MongoDB\BSON\ObjectId($_SESSION["user_id"]);
  $db->users->updateOne(
   ["_id" => $id],
   ['$set' => ["lat" => $lat, "lng" => $lng, "location_updated_at" => new MongoDB\BSON\UTCDateTime()]]
  );
  echo json_encode(["success" => true]);
 }
 catch(Throwable $e)
 {
  http_response_code(500);
  echo json_encode(["error" => "Errore database"]);
 }
