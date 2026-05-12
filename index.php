<?php
 require_once "config.php";
 requireGuest();

 $error = "";// Variabile per eventuali messaggi di errore

 if($_SERVER["REQUEST_METHOD"] === "POST")
 {
  $email    = trim($_POST["email"] ?? "");
  $password = $_POST["password"] ?? "";

  if(!$email || !$password)
  {
   $error = "Compila tutti i campi.";
  }
  else
  {
   $db   = getDB();
   $user = $db->users->findOne(["email" => strtolower($email)]);

   if($user && password_verify($password, $user->password))
   {
    $_SESSION["user_id"] = (string)$user->_id;

    // Reindirizzo all'onboarding se il profilo non è ancora completo
    if(empty($user->profile_complete))
    {
     header("Location: onboarding.php");
    }
    else
    {
     header("Location: discover.php");
    }
    exit;
   }
   else
   {
    $error = "Email o password non corretti.";
   }
  }
 }
?>

<!DOCTYPE html>

<html lang="it">
 <head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MeetHub – Accedi</title>
  <link rel="stylesheet" href="style.css">
 </head>

 <body>
  <div class="page-wrapper">
   <div class="auth-container">

    <!--- Hero Side --->
    <div class="auth-hero">
     <div class="auth-hero-tag">✨ Trova la tua metà</div>
     <h1>Il tuo<br>prossimo<br><span style="color:var(--coral)">grande amore</span><br>è qui.</h1>
     <p style="margin-top:1.5rem">Connettiti con persone reali, crea connessioni vere. MeetHub è il posto dove le storie d'amore iniziano.</p>

     <div class="floating-cards">
      <div class="mini-profile-card">
       <div class="mini-avatar">👩</div>
       <div class="name">Sofia, 26</div>
       <div class="age">Milano</div>
      </div>
      <div class="mini-profile-card" style="margin-top:2rem">
       <div class="mini-avatar">👨</div>
       <div class="name">Marco, 29</div>
       <div class="age">Roma</div>
      </div>
     </div>
    </div>

    <!--- Form Side --->
    <div class="auth-form-side">
     <div style="max-width:420px; width:100%">
      <div style="font-family:'Playfair Display',serif; font-size:2rem; font-weight:900; background:linear-gradient(135deg,var(--coral),var(--gold)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; margin-bottom:2rem">MeetHub</div>

      <h2>Bentornato 👋</h2>
      <p class="subtitle">Accedi al tuo account per continuare</p>

      <?php if($error !== ""){ ?>
       <div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php } ?>

      <form method="POST">
       <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" placeholder="tua@email.it" value="<?= htmlspecialchars($_POST["email"] ?? "") ?>" required>
       </div>
       <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" placeholder="••••••••" required>
       </div>
       <button type="submit" class="btn btn-primary btn-full btn-lg">Accedi</button>
      </form>

      <div class="divider">oppure</div>

      <a href="register.php" class="btn btn-outline btn-full">Crea un account</a>

      <p class="text-center mt-3 text-muted" style="font-size:0.85rem">
       Registrandoti accetti i nostri <a href="#">Termini di Servizio</a> e la <a href="#">Privacy Policy</a>.
      </p>
     </div>
    </div>

   </div>
  </div>
 </body>
</html>
