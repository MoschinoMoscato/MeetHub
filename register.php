<?php
 require_once "config.php";
 requireGuest();

 $error   = "";// Variabile per eventuali messaggi di errore
 $success = "";

 if($_SERVER["REQUEST_METHOD"] === "POST")
 {
  $name      = trim($_POST["name"] ?? "");
  $email     = strtolower(trim($_POST["email"] ?? ""));
  $password  = $_POST["password"] ?? "";
  $confirm   = $_POST["confirm"] ?? "";
  $gender    = $_POST["gender"] ?? "";
  $birthdate = $_POST["birthdate"] ?? "";

  // Controllo che i campi obbligatori non siano vuoti
  if(!$name || !$email || !$password || !$gender || !$birthdate)
  {
   $error = "Compila tutti i campi obbligatori.";
  }
  elseif(!filter_var($email, FILTER_VALIDATE_EMAIL))
  {
   $error = "Inserisci un'email valida.";
  }
  elseif(strlen($password) < 6)
  {
   $error = "La password deve avere almeno 6 caratteri.";
  }
  elseif($password !== $confirm)
  {
   $error = "Le password non coincidono.";
  }
  else
  {
   $db       = getDB();
   $existing = $db->users->findOne(["email" => $email]);// Controllo se l'email è già registrata

   if($existing)
   {
    $error = "Questa email è già registrata.";
   }
   else
   {
    $user_id = $db->users->insertOne(
    [
     "name"             => $name,
     "email"            => $email,
     "password"         => password_hash($password, PASSWORD_DEFAULT),
     "gender"           => $gender,
     "birthdate"        => $birthdate,
     "created_at"       => new MongoDB\BSON\UTCDateTime(),
     "profile_complete" => false,
     "bio"              => "",
     "city"             => "",
     "job"              => "",
     "height"           => null,
     "profile_image"    => null,
     "interests"        => [],
     "traits"           => [],
     "preferences"      =>
     [
      "gender"   => [],
      "min_age"  => 18,
      "max_age"  => 99,
      "max_dist" => 50
     ],
     "lat" => null,
     "lng" => null
    ])->getInsertedId();

    $_SESSION["user_id"] = (string)$user_id;
    header("Location: /onboarding");
    exit;
   }
  }
 }
?>

<!DOCTYPE html>

<html lang="it">
 <head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MeetHub – Registrati</title>
  <link rel="stylesheet" href="style.css">
 </head>

 <body>
  <header class="lp-header scrolled">
   <a class="lp-logo" href="/">MeetHub</a>
  </header>
  <div class="page-wrapper">
   <div class="auth-container">

    <div class="auth-hero">
     <div class="auth-hero-tag">Inizia ora</div>
     <h1>Crea il<br>tuo<br><span style="color:var(--coral)">profilo</span><br>perfetto.</h1>
     <p style="margin-top:1.5rem">Bastano pochi minuti per iniziare. Scegli chi sei, cosa cerchi, e lascia che MeetHub faccia il resto.</p>
    </div>

    <div class="auth-form-side">
     <div style="max-width:460px; width:100%">
      <?php if(($_GET["from"] ?? "") === "login"){ ?>
      <a href="/login?from=register" style="color:var(--text-muted); font-size:0.9rem; display:flex; align-items:center; gap:0.4rem; margin-bottom:2rem">← Torna al login</a>
      <?php } else { ?>
      <a href="/" style="color:var(--text-muted); font-size:0.9rem; display:flex; align-items:center; gap:0.4rem; margin-bottom:2rem">← Torna alla home</a>
      <?php } ?>

      <h2>Registrati</h2>
      <p class="subtitle">Unisciti a migliaia di persone che cercano amore</p>

      <?php if($error !== ""){ ?>
       <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php } ?>

      <form method="POST">
       <div class="form-group">
        <label>Nome</label>
        <input type="text" name="name" placeholder="Come ti chiami?" value="<?= htmlspecialchars($_POST["name"] ?? "") ?>" required>
       </div>
       <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" placeholder="tua@email.it" value="<?= htmlspecialchars($_POST["email"] ?? "") ?>" required>
       </div>
       <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem">
        <div class="form-group">
         <label>Genere</label>
         <select name="gender" required>
          <option value="">Seleziona...</option>
          <option value="uomo" <?= ($_POST["gender"] ?? "") === "uomo" ? "selected" : "" ?>>Uomo</option>
          <option value="donna" <?= ($_POST["gender"] ?? "") === "donna" ? "selected" : "" ?>>Donna</option>
          <option value="non-binario" <?= ($_POST["gender"] ?? "") === "non-binario" ? "selected" : "" ?>>Non-binario</option>
          <option value="altro" <?= ($_POST["gender"] ?? "") === "altro" ? "selected" : "" ?>>Altro</option>
         </select>
        </div>
        <div class="form-group">
         <label>Data di nascita</label>
         <input type="date" name="birthdate" value="<?= htmlspecialchars($_POST["birthdate"] ?? "") ?>" required max="<?= date("Y-m-d", strtotime("-18 years")) ?>">
        </div>
       </div>
       <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" placeholder="Minimo 6 caratteri" required>
       </div>
       <div class="form-group">
        <label>Conferma Password</label>
        <input type="password" name="confirm" placeholder="Ripeti la password" required>
       </div>
       <button type="submit" class="btn btn-primary btn-full btn-lg mt-2">Crea Account</button>
      </form>

      <p class="text-center mt-3 text-muted" style="font-size:0.85rem">
       Hai già un account? <a href="/login?from=register">Accedi</a>
      </p>
     </div>
    </div>

   </div>
  </div>
 </body>
</html>
