<?php
 require_once "config.php";
 requireGuest();

 $error = "";

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
   $db  = getDB();
   $ip  = $_SERVER["REMOTE_ADDR"] ?? "unknown";
   $win = new MongoDB\BSON\UTCDateTime((time() - 900) * 1000); // finestra 15 min

   if($db->login_attempts->countDocuments(["ip" => $ip, "at" => ['$gte' => $win]]) >= 5)
   {
    $error = "Troppi tentativi di accesso. Riprova tra 15 minuti.";
   }
   else
   {
    $user = $db->users->findOne(["email" => strtolower($email)]);

    if($user && password_verify($password, $user->password))
    {
     $db->login_attempts->deleteMany(["ip" => $ip]);
     $_SESSION["user_id"] = (string)$user->_id;
     header("Location: " . (empty($user->profile_complete) ? "/onboarding" : "/discover"));
     exit;
    }
    else
    {
     $db->login_attempts->insertOne(["ip" => $ip, "at" => new MongoDB\BSON\UTCDateTime()]);
     $error = "Email o password non corretti.";
    }
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
  <header class="lp-header scrolled">
   <a class="lp-logo" href="/">MeetHub</a>
  </header>
  <div class="page-wrapper">
   <div class="auth-container">

    <div class="auth-hero">
     <div class="auth-hero-tag">Bentornato</div>
     <h1>Riprendi<br>da dove<br><span style="color:var(--coral)">avevi</span><br>lasciato.</h1>
     <p style="margin-top:1.5rem">I tuoi match ti stanno aspettando. Forse. Dipende da quant'è stato lungo il tuo silenzio.</p>
    </div>

    <div class="auth-form-side">
     <div style="max-width:420px; width:100%">
      <?php if(($_GET["from"] ?? "") === "register"){ ?>
      <a href="/register" style="color:var(--text-muted); font-size:0.9rem; display:flex; align-items:center; gap:0.4rem; margin-bottom:2rem">← Torna alla registrazione</a>
      <?php } else { ?>
      <a href="/" style="color:var(--text-muted); font-size:0.9rem; display:flex; align-items:center; gap:0.4rem; margin-bottom:2rem">← Torna alla home</a>
      <?php } ?>

      <h2>Accedi</h2>
      <p class="subtitle">Inserisci le tue credenziali per continuare</p>

      <?php if($error !== ""){ ?>
       <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php } ?>

      <form method="POST" id="login-form">
       <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" placeholder="tua@email.it"
               value="<?= htmlspecialchars($_POST["email"] ?? "") ?>" required>
       </div>
       <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" placeholder="••••••••" required>
       </div>
       <button type="submit" class="btn btn-primary btn-full btn-lg mt-2">Accedi</button>
      </form>
      <script>
       document.getElementById("login-form").addEventListener("submit", function(e)
       {
        e.preventDefault();

        var email = document.querySelector("input[name='email']").value;
        var password = document.querySelector("input[name='password']").value;

       var payload = JSON.stringify({
        email: email,
        password: password
       });

       var xhr = new XMLHttpRequest();
       xhr.open("POST", "/api/auth/login");
       xhr.setRequestHeader("Content-Type", "application/json");
       xhr.withCredentials = true; 

       xhr.onreadystatechange = function()
       {
        if(xhr.readyState !== XMLHttpRequest.DONE) return;

        if(xhr.status === 200)
        {
         try
         {
          var result = JSON.parse(xhr.responseText);
          if(result.success)
           window.location.href = result.data.profile_complete ? "discover.php" : "onboarding.php";
          else
           alert("Errore: " + result.error);
         }
         catch(e) { alert("Errore di comunicazione"); }
        }
        else if(xhr.status === 401)
        {
         alert("Email o password non corretti");
        }
        else
        {
         alert("Errore: " + xhr.status);
        }
       };

       xhr.send(payload);
      });
     </script>

      <p class="text-center mt-3 text-muted" style="font-size:0.85rem">
       Non hai un account? <a href="/register?from=login">Registrati</a>
      </p>
     </div>
    </div>

   </div>
  </div>
 </body>
</html>
