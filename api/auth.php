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

   // POST /api/auth/register - Registrazione utente
   elseif($id === "register")
   {
    $required = ["name", "email", "password", "gender", "birthdate"];
    $missing  = [];

    foreach($required as $field)
    {
     if(empty($input[$field]))
     {
      $missing[] = $field;
     }
    }

    if(!empty($missing))
    {
     jsonError("Campi mancanti: " . implode(", ", $missing), 400, "MISSING_FIELDS");
    }

    if(strlen($input["password"]) < 6)
    {
     jsonError("La password deve avere almeno 6 caratteri", 400, "WEAK_PASSWORD");
    }

    if(!filter_var($input["email"], FILTER_VALIDATE_EMAIL))
    {
     jsonError("Email non valida", 400, "INVALID_EMAIL");
    }

    $db       = getDB();
    $existing = $db->users->findOne(["email" => strtolower($input["email"])]);

    if($existing)
    {
     jsonError("Email già registrata", 409, "EMAIL_EXISTS");
    }

    $user_id = $db->users->insertOne(
    [
     "name"             => $input["name"],
     "email"            => strtolower($input["email"]),
     "password"         => password_hash($input["password"], PASSWORD_DEFAULT),
     "gender"           => $input["gender"],
     "birthdate"        => $input["birthdate"],
     "created_at"       => new MongoDB\BSON\UTCDateTime(),
     "profile_complete" => false,
     "bio"              => "",
     "city"             => "",
     "job"              => "",
     "height"           => null,
     "interests"        => [],
     "traits"           => [],
     "preferences"      => (object)[],
     "profile_image"    => null,
     "lat"              => null,
     "lng"              => null
    ])->getInsertedId();

    $_SESSION["user_id"] = (string)$user_id;

    jsonResponse(
    [
     "success" => true,
     "data"    =>
     [
      "user_id" => (string)$user_id,
      "name"    => $input["name"],
      "email"   => $input["email"]
     ]
    ], 201);
   }

   else
   {
    jsonError("Azione non valida", 400, "INVALID_ACTION");
   }

   break;

  case "GET":

   // GET /api/auth/me - Dati dell'utente corrente
   if($id === "me")
   {
    $user = currentUser();

    if(!$user)
    {
     jsonError("Utente non trovato", 404, "USER_NOT_FOUND");
    }

    jsonResponse(
    [
     "success" => true,
     "data"    =>
     [
      "id"               => (string)$user->_id,
      "name"             => $user->name,
      "email"            => $user->email,
      "gender"           => $user->gender   ?? null,
      "birthdate"        => $user->birthdate ?? null,
      "age"              => calcAge($user->birthdate ?? null),
      "bio"              => $user->bio        ?? "",
      "city"             => $user->city       ?? "",
      "job"              => $user->job        ?? "",
      "height"           => $user->height     ?? null,
      "profile_image"    => $user->profile_image ?? null,
      "interests"        => $user->interests  ?? [],
      "traits"           => $user->traits     ?? [],
      "preferences"      => $user->preferences ?? (object)[],
      "profile_complete" => $user->profile_complete ?? false
     ]
    ]);
   }
   else
   {
    jsonError("Endpoint non valido", 404, "NOT_FOUND");
   }

   break;

  case "DELETE":

   // DELETE /api/auth/logout - Logout utente
   if($id === "logout")
   {
    session_destroy();
    jsonResponse(["success" => true, "message" => "Logout effettuato"]);
   }
   else
   {
    jsonError("Endpoint non valido", 404, "NOT_FOUND");
   }

   break;

  default:
   jsonError("Metodo non supportato", 405, "METHOD_NOT_ALLOWED");
 }
?>
