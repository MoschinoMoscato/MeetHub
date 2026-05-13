<?php
 ini_set("display_errors", 0);
 require_once __DIR__ . "/../config.php";

 header("Content-Type: application/json");

 if(!isset($_SESSION["user_id"]))
 {
  http_response_code(401);
  echo json_encode(["error" => "session_expired"]);
  exit;
 }

 $db     = getDB();
 $cur_id = new MongoDB\BSON\ObjectId($_SESSION["user_id"]);

 $message_items  = [];
 $match_items    = [];

 try
 {
  $pipeline = [
   ['$match' => ["to_user_id" => $cur_id, "read" => false]],
   ['$group' => ["_id" => '$from_user_id', "count" => ['$sum' => 1]]],
   ['$sort'  => ["count" => -1]],
   ['$limit' => 5]
  ];

  foreach($db->messages->aggregate($pipeline) as $row)
  {
   $sender = $db->users->findOne(["_id" => $row->_id], ["projection" => ["name" => 1, "profile_image" => 1]]);
   if($sender)
   {
    $message_items[] =
    [
     "id"    => (string)$row->_id,
     "name"  => (string)($sender->name ?? "?"),
     "img"   => $sender->profile_image ?? null,
     "count" => (int)$row->count
    ];
   }
  }
 }
 catch(Throwable $e) {}

 try
 {
  $matches_cursor = $db->matches->find(
  [
   "users"   => ['$in'  => [$cur_id]],
   "seen_by" => ['$ne'  => $cur_id]
  ],
  ["sort" => ["created_at" => -1], "limit" => 5]);

  foreach($matches_cursor as $m)
  {
   $other_id = null;
   foreach($m->users as $uid)
   {
    if((string)$uid !== (string)$cur_id) { $other_id = $uid; break; }
   }

   if($other_id)
   {
    $other = $db->users->findOne(["_id" => $other_id], ["projection" => ["name" => 1, "profile_image" => 1]]);
    if($other)
    {
     $match_items[] =
     [
      "name"     => (string)($other->name ?? "?"),
      "img"      => $other->profile_image ?? null,
      "id"       => (string)$other_id,
      "match_id" => (string)$m->_id
     ];
    }
   }
  }
 }
 catch(Throwable $e) {}

 $msg_total = array_sum(array_column($message_items, "count"));
 $total     = $msg_total + count($match_items);

 echo json_encode(
 [
  "success"       => true,
  "total"         => $total,
  "message_items" => $message_items,
  "match_items"   => $match_items
 ]);
