<?php
 // Configurazione app
 define("APP_NAME", "MeetHub");
 define("APP_VERSION", "1.0.0");

 // Configurazione MongoDB (usa variabile d'ambiente su Railway, fallback locale)
 define("MONGO_HOST", getenv("MONGO_HOST") ?: "mongodb://10.10.13.2:27017");
 define("MONGO_DB",   getenv("MONGO_DB")   ?: "Meethub");

 // Durata sessione
 ini_set("session.cookie_lifetime", 0);
 ini_set("session.gc_maxlifetime", 0);

 // Avvio sessione e autoload Composer
 session_start();
 require __DIR__ . "/vendor/autoload.php";

 // Connessione al database MongoDB (singleton)
 function getDB()
 {
  static $db = null;

  if($db === null)
  {
   try
   {
    $client = new MongoDB\Client(MONGO_HOST);
    $db = $client->selectDatabase(MONGO_DB);
   }
   catch(Exception $e)
   {
    die(json_encode(["error" => "Errore di connessione al database."]));
   }
  }

  return $db;
 }

 // Recupera l'utente corrente dalla sessione
 function currentUser()
 {
  if(!isset($_SESSION["user_id"])) return null;

  $db = getDB();
  $user = $db->users->findOne(["_id" => new MongoDB\BSON\ObjectId($_SESSION["user_id"])]);
  return $user;
 }

 // Reindirizza alla login se non autenticato
 function requireLogin()
 {
  if(!isset($_SESSION["user_id"]))
  {
   $is_ajax = (isset($_GET["ajax"]) && $_GET["ajax"] == 1)
           || (isset($_SERVER["HTTP_X_REQUESTED_WITH"])
               && strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) === "xmlhttprequest");

   if($is_ajax)
   {
    http_response_code(401);
    header("Content-Type: application/json");
    echo json_encode(["error" => "session_expired"]);
    exit;
   }

   header("Location: index.php");
   exit;
  }
 }

 // Reindirizza alla discover se già autenticato
 function requireGuest()
 {
  if(isset($_SESSION["user_id"]))
  {
   header("Location: discover.php");
   exit;
  }
 }

 // Calcola l'età dalla data di nascita
 function calcAge($birthdate)
 {
  if(empty($birthdate)) return 0;

  try
  {
   $birth = new DateTime((string)$birthdate);
   $today = new DateTime();
   return $birth->diff($today)->y;
  }
  catch(Exception $e)
  {
   return 0;
  }
 }

 // Calcola la distanza in km tra due coordinate (formula di Haversine)
 function haversineDistance($lat1, $lon1, $lat2, $lon2)
 {
  $R = 6371;
  $dLat = deg2rad($lat2 - $lat1);
  $dLon = deg2rad($lon2 - $lon1);
  $a = sin($dLat/2)*sin($dLat/2) + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)*sin($dLon/2);
  $c = 2 * atan2(sqrt($a), sqrt(1-$a));
  return $R * $c;
 }

 // Decodifica l'input JSON della richiesta
 function getJsonInput()
 {
  $input = json_decode(file_get_contents("php://input"), true);
  return is_array($input) ? $input : [];
 }
?>
