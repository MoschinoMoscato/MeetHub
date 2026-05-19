<?php
/**
 * Migrazione idempotente: sposta le immagini dal filesystem a MongoDB (collezione uploads).
 * Da eseguire una sola volta via CLI: php scripts/migrate_uploads_to_db.php
 *
 * Sicuro da rieseguire: salta i record già migrati.
 */

define("ROOT", dirname(__DIR__));
require_once ROOT . "/config.php";

$db = getDB();

$migrated = 0;
$skipped  = 0;
$errors   = 0;

// ── 1. Immagini profilo ────────────────────────────────────────────────────────
echo "=== Migrazione immagini profilo ===\n";

$users = $db->users->find(["profile_image" => ['$exists' => true, '$ne' => null, '$ne' => ""]]);

foreach($users as $user)
{
 $val = (string)($user->profile_image ?? "");
 if(!$val) { $skipped++; continue; }

 // Già migrato: contiene un ObjectId (24 hex chars)
 if(preg_match('/^[0-9a-f]{24}$/i', $val)) { $skipped++; continue; }

 $filepath = ROOT . "/" . $val;

 if(!file_exists($filepath))
 {
  echo "  [WARN] File non trovato: $filepath\n";
  $errors++;
  continue;
 }

 $data      = file_get_contents($filepath);
 $finfo     = new finfo(FILEINFO_MIME_TYPE);
 $mime_type = $finfo->buffer($data) ?: "application/octet-stream";

 try
 {
  $result = $db->uploads->insertOne(
  [
   "owner_user_id" => $user->_id,
   "kind"          => "profile",
   "mime_type"     => $mime_type,
   "size"          => strlen($data),
   "data"          => new MongoDB\BSON\Binary($data, MongoDB\BSON\Binary::TYPE_GENERIC),
   "created_at"    => new MongoDB\BSON\UTCDateTime()
  ]);

  $upload_id = (string)$result->getInsertedId();

  $db->users->updateOne(
   ["_id" => $user->_id],
   ['$set' => ["profile_image" => $upload_id]]
  );

  echo "  [OK] User {$user->_id} → upload $upload_id\n";
  $migrated++;
 }
 catch(Throwable $e)
 {
  echo "  [ERR] User {$user->_id}: " . $e->getMessage() . "\n";
  $errors++;
 }
}

// ── 2. Immagini in chat ────────────────────────────────────────────────────────
echo "\n=== Migrazione immagini chat ===\n";

$messages = $db->messages->find(["type" => "image", "image_path" => ['$exists' => true, '$ne' => null]]);

foreach($messages as $msg)
{
 // Già migrato
 if(isset($msg->upload_id)) { $skipped++; continue; }

 $image_path = (string)($msg->image_path ?? "");
 if(!$image_path) { $skipped++; continue; }

 $filepath = ROOT . "/" . $image_path;

 if(!file_exists($filepath))
 {
  echo "  [WARN] File non trovato: $filepath\n";
  $errors++;
  continue;
 }

 $data      = file_get_contents($filepath);
 $finfo     = new finfo(FILEINFO_MIME_TYPE);
 $mime_type = $finfo->buffer($data) ?: "application/octet-stream";

 try
 {
  $result = $db->uploads->insertOne(
  [
   "owner_user_id" => $msg->from_user_id,
   "kind"          => "chat_image",
   "mime_type"     => $mime_type,
   "size"          => strlen($data),
   "data"          => new MongoDB\BSON\Binary($data, MongoDB\BSON\Binary::TYPE_GENERIC),
   "created_at"    => $msg->created_at ?? new MongoDB\BSON\UTCDateTime()
  ]);

  $upload_id = $result->getInsertedId();

  $db->messages->updateOne(
   ["_id" => $msg->_id],
   ['$set' => ["upload_id" => $upload_id], '$unset' => ["image_path" => ""]]
  );

  echo "  [OK] Msg {$msg->_id} → upload " . (string)$upload_id . "\n";
  $migrated++;
 }
 catch(Throwable $e)
 {
  echo "  [ERR] Msg {$msg->_id}: " . $e->getMessage() . "\n";
  $errors++;
 }
}

echo "\n=== Riepilogo ===\n";
echo "  Migrati : $migrated\n";
echo "  Saltati : $skipped\n";
echo "  Errori  : $errors\n";
