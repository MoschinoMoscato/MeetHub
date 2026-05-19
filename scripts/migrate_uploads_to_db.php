<?php
/**
 * Migrazione idempotente: sposta le immagini dal filesystem a MongoDB (collection uploads).
 *
 * Uso:
 *   php scripts/migrate_uploads_to_db.php --dry-run   (simulazione, nessuna modifica)
 *   php scripts/migrate_uploads_to_db.php --apply     (migrazione reale)
 *
 * Idempotente: salta record già migrati (profile_image_id / image_id già presenti).
 * Non cancella mai file locali né i campi legacy.
 */

$args = array_slice($argv ?? [], 1);

if(!in_array("--dry-run", $args) && !in_array("--apply", $args))
{
 echo "Uso:\n";
 echo "  php scripts/migrate_uploads_to_db.php --dry-run\n";
 echo "  php scripts/migrate_uploads_to_db.php --apply\n";
 exit(1);
}

$dry_run = in_array("--dry-run", $args);

define("ROOT", dirname(__DIR__));
require_once ROOT . "/config.php";

$db = getDB();

$mode = $dry_run ? "[DRY-RUN]" : "[APPLY]";

echo "$mode Avvio migrazione immagini dal filesystem a MongoDB\n";
echo str_repeat("─", 60) . "\n";

// ── Contatori ──────────────────────────────────────────────────────────────────
$stats =
[
 "users_found"    => 0,
 "users_migrate"  => 0,
 "users_migrated" => 0,
 "users_skipped"  => 0,
 "msgs_found"     => 0,
 "msgs_migrate"   => 0,
 "msgs_migrated"  => 0,
 "msgs_skipped"   => 0,
 "files_missing"  => 0,
 "errors"         => 0
];

// ── 1. Immagini profilo (users.profile_image → users.profile_image_id) ─────────
echo "\n=== 1. Immagini profilo ===\n";

$users_cursor = $db->users->find(["profile_image" => ['$exists' => true, '$ne' => null, '$ne' => ""]]);

foreach($users_cursor as $user)
{
 $stats["users_found"]++;

 // Già migrato: profile_image_id presente
 if(!empty($user->profile_image_id))
 {
  echo "  [SKIP] User {$user->_id} — profile_image_id già presente\n";
  $stats["users_skipped"]++;
  continue;
 }

 $legacy_path = (string)($user->profile_image ?? "");
 if(!$legacy_path) { $stats["users_skipped"]++; continue; }

 // Non migrare ObjectId finiti per errore nel campo legacy
 if(preg_match('/^[0-9a-f]{24}$/i', $legacy_path)) {
  echo "  [SKIP] User {$user->_id} — profile_image sembra già un ObjectId (non migrato)\n";
  $stats["users_skipped"]++;
  continue;
 }

 $filepath = ROOT . "/" . $legacy_path;
 $stats["users_migrate"]++;

 if(!file_exists($filepath))
 {
  echo "  [WARN] File non trovato: $filepath\n";
  $stats["files_missing"]++;
  continue;
 }

 echo "  [OK] User {$user->_id}: $legacy_path";

 if(!$dry_run)
 {
  try
  {
   $data      = file_get_contents($filepath);
   $finfo     = new finfo(FILEINFO_MIME_TYPE);
   $mime_type = $finfo->buffer($data) ?: "application/octet-stream";

   $result = $db->uploads->insertOne(
   [
    "owner_user_id" => $user->_id,
    "kind"          => "profile",
    "mime_type"     => $mime_type,
    "size"          => strlen($data),
    "data"          => new MongoDB\BSON\Binary($data, MongoDB\BSON\Binary::TYPE_GENERIC),
    "created_at"    => new MongoDB\BSON\UTCDateTime()
   ]);

   $new_id = $result->getInsertedId();

   $db->users->updateOne(
    ["_id" => $user->_id],
    ['$set' => ["profile_image_id" => $new_id]]
   );

   echo " → upload $new_id\n";
   $stats["users_migrated"]++;
  }
  catch(Throwable $e)
  {
   echo " [ERR] " . $e->getMessage() . "\n";
   $stats["errors"]++;
  }
 }
 else
 {
  echo " → verrebbe creato upload\n";
  $stats["users_migrated"]++;
 }
}

// ── 2. Immagini chat (messages.image_path → messages.image_id) ─────────────────
echo "\n=== 2. Immagini chat ===\n";

$msgs_cursor = $db->messages->find(
[
 "type"       => "image",
 "image_path" => ['$exists' => true, '$ne' => null, '$ne' => ""]
]);

foreach($msgs_cursor as $msg)
{
 $stats["msgs_found"]++;

 // Già migrato: image_id presente
 if(!empty($msg->image_id))
 {
  echo "  [SKIP] Msg {$msg->_id} — image_id già presente\n";
  $stats["msgs_skipped"]++;
  continue;
 }

 $legacy_path = (string)($msg->image_path ?? "");
 if(!$legacy_path) { $stats["msgs_skipped"]++; continue; }

 $filepath = ROOT . "/" . $legacy_path;
 $stats["msgs_migrate"]++;

 if(!file_exists($filepath))
 {
  echo "  [WARN] File non trovato: $filepath\n";
  $stats["files_missing"]++;
  continue;
 }

 echo "  [OK] Msg {$msg->_id}: $legacy_path";

 if(!$dry_run)
 {
  try
  {
   $data      = file_get_contents($filepath);
   $finfo     = new finfo(FILEINFO_MIME_TYPE);
   $mime_type = $finfo->buffer($data) ?: "application/octet-stream";

   $result = $db->uploads->insertOne(
   [
    "owner_user_id" => $msg->from_user_id,
    "kind"          => "chat_image",
    "mime_type"     => $mime_type,
    "size"          => strlen($data),
    "data"          => new MongoDB\BSON\Binary($data, MongoDB\BSON\Binary::TYPE_GENERIC),
    "created_at"    => $msg->created_at ?? new MongoDB\BSON\UTCDateTime()
   ]);

   $new_id = $result->getInsertedId();

   $db->messages->updateOne(
    ["_id" => $msg->_id],
    ['$set' => ["image_id" => $new_id]]
   );

   echo " → upload $new_id\n";
   $stats["msgs_migrated"]++;
  }
  catch(Throwable $e)
  {
   echo " [ERR] " . $e->getMessage() . "\n";
   $stats["errors"]++;
  }
 }
 else
 {
  echo " → verrebbe creato upload\n";
  $stats["msgs_migrated"]++;
 }
}

// ── 3. Indici ──────────────────────────────────────────────────────────────────
if(!$dry_run)
{
 echo "\n=== 3. Creazione indici ===\n";
 try
 {
  $db->uploads->createIndex(["owner_user_id" => 1]);
  $db->uploads->createIndex(["kind" => 1]);
  $db->messages->createIndex(["image_id" => 1]);
  $db->users->createIndex(["profile_image_id" => 1]);
  echo "  [OK] Indici creati\n";
 }
 catch(Throwable $e) { echo "  [WARN] Indici: " . $e->getMessage() . "\n"; }
}

// ── Report finale ──────────────────────────────────────────────────────────────
echo "\n" . str_repeat("─", 60) . "\n";
echo "$mode Report finale\n";
echo str_repeat("─", 60) . "\n";
echo "  Utenti trovati con profile_image: {$stats['users_found']}\n";
echo "  Utenti da migrare:                {$stats['users_migrate']}\n";
echo "  Utenti " . ($dry_run ? "da migrare" : "migrati") . ":             {$stats['users_migrated']}\n";
echo "  Utenti saltati (già ok):          {$stats['users_skipped']}\n";
echo "\n";
echo "  Messaggi trovati con image_path:  {$stats['msgs_found']}\n";
echo "  Messaggi da migrare:              {$stats['msgs_migrate']}\n";
echo "  Messaggi " . ($dry_run ? "da migrare" : "migrati") . ":           {$stats['msgs_migrated']}\n";
echo "  Messaggi saltati (già ok):        {$stats['msgs_skipped']}\n";
echo "\n";
echo "  File non trovati:                 {$stats['files_missing']}\n";
echo "  Errori:                           {$stats['errors']}\n";

if($dry_run)
 echo "\n[DRY-RUN] Nessuna modifica effettuata. Riesegui con --apply per applicare.\n";
else
 echo "\n[APPLY] Migrazione completata. I campi legacy (profile_image, image_path) restano intatti come fallback.\n";
