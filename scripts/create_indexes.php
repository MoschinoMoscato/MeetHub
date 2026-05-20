<?php
 require_once __DIR__ . "/../config.php";

 if(PHP_SAPI !== "cli")
 {
  exit("CLI only\n");
 }

 $db = getDB();

 echo "Creazione indici MongoDB...\n\n";

 $indexes =
 [
  "interactions" =>
  [
   [["from_user_id" => 1, "to_user_id" => 1], ["name" => "idx_interactions_from_to"]],
   [["from_user_id" => 1, "action" => 1],     ["name" => "idx_interactions_from_action"]],
   [["to_user_id" => 1,  "action" => 1],      ["name" => "idx_interactions_to_action"]]
  ],
  "messages" =>
  [
   [["from_user_id" => 1, "to_user_id" => 1, "_id" => 1], ["name" => "idx_messages_from_to_id"]],
   [["to_user_id" => 1,  "from_user_id" => 1, "_id" => 1], ["name" => "idx_messages_to_from_id"]],
   [["to_user_id" => 1,  "read" => 1],                     ["name" => "idx_messages_to_read"]],
   [["image_id" => 1],                                      ["name" => "idx_messages_image_id"]]
  ],
  "matches" =>
  [
   [["users" => 1],       ["name" => "idx_matches_users"]],
   [["seen_by" => 1],     ["name" => "idx_matches_seen_by"]],
   [["created_at" => -1], ["name" => "idx_matches_created_at"]],
   [["pair_key" => 1],    ["name" => "idx_matches_pair_key", "unique" => true, "sparse" => true]]
  ],
  "uploads" =>
  [
   [["owner_user_id" => 1],              ["name" => "idx_uploads_owner"]],
   [["kind" => 1],                       ["name" => "idx_uploads_kind"]],
   [["owner_user_id" => 1, "kind" => 1], ["name" => "idx_uploads_owner_kind"]]
  ]
 ];

 foreach($indexes as $collection_name => $collection_indexes)
 {
  echo "Collection: $collection_name\n";
  $collection = $db->{$collection_name};

  foreach($collection_indexes as $index)
  {
   [$keys, $options] = $index;

   try
   {
    $name = $collection->createIndex($keys, $options);
    echo "  OK: $name\n";
   }
   catch(Throwable $e)
   {
    echo "  ERRORE: " . ($options["name"] ?? json_encode($keys)) . " -> " . $e->getMessage() . "\n";
   }
  }

  echo "\n";
 }

 echo "Fine.\n";
?>
