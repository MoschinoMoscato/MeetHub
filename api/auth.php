<?php
 // $id e $subresource sono passati da api/index.php
 $method = $_SERVER["REQUEST_METHOD"];
 $input  = json_decode(file_get_contents("php://input"), true) ?? [];

 switch($method)
 {
  case "POST":

   // POST /api/auth/login - Login utente
   if($id === "login")
   {
    $email    = strtolower(trim($input["email"] ?? ""));
    $password = $input["password"] ?? "";

    if(empty($email) || empty($password))
    {
     jsonError("Email e password sono obbligatori", 400, "MISSING_FIELDS");
    }

    $db   = getDB();
    $user = $db->users->findOne(["email" => $email]);

    if(!$user || !password_verify($password, $user->password))
    {
     jsonError("Email o password non corretti", 401, "INVALID_CREDENTIALS");
    }

    $_SESSION["user_id"] = (string)$user->_id;

    jsonResponse(
    [
     "success" => true,
     "data"    =>
     [
      "user_id"          => (string)$user->_id,
      "name"             => $user->name,
      "email"            => $user->email,
      "profile_complete" => $user->profile_complete ?? false
     ]
    ]);
   }

   else
   {
    jsonError("Endpoint non trovato", 404, "NOT_FOUND");
   }

   break;

  default:
   jsonError("Metodo non supportato", 405, "METHOD_NOT_ALLOWED");
 }
?>
