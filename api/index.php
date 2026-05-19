<?php
 session_start();
 require_once "../config.php";

 // Header per le risposte API
 header("Content-Type: application/json");


 // Helper per risposta JSON di successo
 function jsonResponse($data, $status = 200)
 {
  http_response_code($status);
  echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  exit;
 }

 // Helper per risposta JSON di errore
 function jsonError($message, $status = 400, $code = null)
 {
  $response = ["success" => false, "error" => $message];
  if($code) $response["code"] = $code;
  jsonResponse($response, $status);
 }

 // Routing manuale - supporta sia PATH_INFO che query string
 $request_uri = $_SERVER["REQUEST_URI"];
 $script_name = $_SERVER["SCRIPT_NAME"];
 $path        = str_replace($script_name, "", $request_uri);
 $path        = strtok($path, "?");
 $path        = trim($path, "/");

 $segments    = explode("/", $path);
 $resource    = $segments[0] ?? "";
 $id          = $segments[1] ?? null;
 $subresource = $segments[2] ?? null;

 // Verifica autenticazione (escluso per l'endpoint auth)
 $public_endpoints = ["auth"];

 if(!in_array($resource, $public_endpoints) && !isset($_SESSION["user_id"]))
 {
  jsonError("Non autenticato", 401, "UNAUTHORIZED");
 }

 // Routing verso i file di gestione
 switch($resource)
 {
  case "auth":
   require_once __DIR__ . "/auth.php";
   break;

  case "users":
   require_once __DIR__ . "/users.php";
   break;

  case "messages":
   require_once __DIR__ . "/messages.php";
   break;

  case "interactions":
   require_once __DIR__ . "/interactions.php";
   break;

  case "matches":
   require_once __DIR__ . "/matches.php";
   break;

  default:
   jsonError("Endpoint non trovato", 404, "NOT_FOUND");
 }
?>
