<?php
 require_once "config.php";
 requireLogin();

 $user    = currentUser();
 $error   = "";
 $success = "";

 if($_SERVER["REQUEST_METHOD"] === "POST")
 {
  $db     = getDB();
  $id     = new MongoDB\BSON\ObjectId($_SESSION["user_id"]);
  $action = $_POST["action"] ?? "";

  // Cambio password
  if($action === "change_password")
  {
   $old_pass  = $_POST["old_password"]      ?? "";
   $new_pass  = $_POST["new_password"]      ?? "";
   $confirm   = $_POST["confirm_password"]  ?? "";

   if(!$old_pass || !$new_pass || !$confirm)
    $error = "Compila tutti i campi.";
   elseif(!password_verify($old_pass, (string)($user->password ?? "")))
    $error = "La password attuale non è corretta.";
   elseif(strlen($new_pass) < 6)
    $error = "La nuova password deve avere almeno 6 caratteri.";
   elseif($new_pass !== $confirm)
    $error = "Le password non coincidono.";
   else
   {
    try
    {
     $db->users->updateOne(["_id" => $id], ['$set' => ["password" => password_hash($new_pass, PASSWORD_DEFAULT)]]);
     $success = "Password aggiornata con successo.";
    }
    catch(Throwable $e) { $error = "Errore durante l'aggiornamento."; }
   }
  }

  // Eliminazione account
  elseif($action === "delete_account")
  {
   $confirm_pass = $_POST["confirm_delete_password"] ?? "";

   if(!$confirm_pass)
    $error = "Inserisci la password per confermare.";
   elseif(!password_verify($confirm_pass, (string)($user->password ?? "")))
    $error = "Password errata. Account non eliminato.";
   else
   {
    try
    {
     // Raccogli gli image_id dei messaggi nella conversazione (anche quelli inviati da altri)
     $conv_image_ids = [];
     $conv_msgs = $db->messages->find(
     [
      '$and' =>
      [
       ['$or' => [["from_user_id" => $id], ["to_user_id" => $id]]],
       ["type" => "image"]
      ]
     ],
     ["projection" => ["image_id" => 1, "upload_id" => 1]]);

     foreach($conv_msgs as $cm)
     {
      if(!empty($cm->image_id))  $conv_image_ids[] = $cm->image_id;
      elseif(!empty($cm->upload_id)) $conv_image_ids[] = $cm->upload_id;
     }

     if(!empty($conv_image_ids))
      $db->uploads->deleteMany(["_id" => ['$in' => $conv_image_ids]]);

     // Elimina tutti gli upload di proprietà dell'utente (immagini profilo, ecc.)
     $db->uploads->deleteMany(["owner_user_id" => $id]);

     // Elimina dati dal DB
     $db->interactions->deleteMany(['$or' => [["from_user_id" => $id], ["to_user_id" => $id]]]);
     $db->matches->deleteMany(["users" => ['$in' => [$id]]]);
     $db->messages->deleteMany(['$or' => [["from_user_id" => $id], ["to_user_id" => $id]]]);
     $db->users->deleteOne(["_id" => $id]);

     session_destroy();
     header("Location: /?deleted=1");
     exit;
    }
    catch(Throwable $e) { $error = "Errore durante l'eliminazione. Riprova."; }
   }
  }
 }
?>
<!DOCTYPE html>
<html lang="it">
 <head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MeetHub – Impostazioni</title>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=1">
  <link rel="stylesheet" href="style.css?v=<?= filemtime('style.css') ?>">
 </head>
 <body>
  <?php require "header.php"; ?>
  <div class="page-wrapper">
   <div class="settings-wrap">

    <a href="/discover" class="profile-back-link">← Torna a Scopri</a>
    <h2 style="margin-bottom:2rem">Impostazioni</h2>

    <?php if($error){ ?>
     <div class="alert alert-danger mb-2"><?= htmlspecialchars($error) ?></div>
    <?php } ?>
    <?php if($success){ ?>
     <div class="alert alert-success mb-2"><?= htmlspecialchars($success) ?></div>
    <?php } ?>

    <!-- Sicurezza -->
    <div class="settings-card">
     <div class="settings-card-header">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Sicurezza
     </div>
     <form method="POST" novalidate class="settings-form">
      <input type="hidden" name="action" value="change_password">
      <div class="form-group">
       <label>Password attuale</label>
       <input type="password" name="old_password" placeholder="••••••••" required>
      </div>
      <div class="profile-edit-grid">
       <div class="form-group">
        <label>Nuova password</label>
        <input type="password" name="new_password" placeholder="Minimo 6 caratteri" required>
       </div>
       <div class="form-group">
        <label>Conferma</label>
        <input type="password" name="confirm_password" placeholder="Ripeti la password" required>
       </div>
      </div>
      <button type="submit" class="btn btn-primary">Cambia password</button>
     </form>
    </div>

    <!-- Zona pericolosa -->
    <div class="settings-card settings-danger-card">
     <div class="settings-card-header settings-card-header--danger">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      Zona pericolosa
     </div>
     <p class="text-muted" style="font-size:0.88rem; margin-bottom:1.25rem">
      L'eliminazione dell'account è permanente e irreversibile. Tutti i tuoi dati, match e messaggi verranno cancellati definitivamente.
     </p>
     <button type="button" class="btn btn-danger" onclick="document.getElementById('delete-modal').classList.add('open')">
      Elimina account
     </button>
    </div>

   </div>
  </div>

  <!-- Modal conferma eliminazione -->
  <div class="confirm-modal" id="delete-modal">
   <div class="confirm-modal-box">
    <h3>Sei sicuro?</h3>
    <p class="text-muted" style="font-size:0.9rem; margin:0.75rem 0 1.5rem">
     Questa azione è irreversibile. Inserisci la tua password per confermare l'eliminazione dell'account.
    </p>
    <form method="POST" novalidate>
     <input type="hidden" name="action" value="delete_account">
     <div class="form-group">
      <input type="password" name="confirm_delete_password" placeholder="La tua password" required autofocus>
     </div>
     <div style="display:flex; gap:1rem; margin-top:1rem">
      <button type="button" class="btn btn-ghost" style="flex:1" onclick="document.getElementById('delete-modal').classList.remove('open')">Annulla</button>
      <button type="submit" class="btn btn-danger" style="flex:1">Elimina definitivamente</button>
     </div>
    </form>
   </div>
  </div>

 </body>
</html>
