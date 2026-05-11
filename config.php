<?php
// MeetHub Configuration
define('APP_NAME', 'MeetHub');
define('APP_VERSION', '1.0.0');

// MongoDB Configuration
define('MONGO_HOST', 'mongodb://10.10.13.2:27017');
define('MONGO_DB', 'Meethub');

//Session time
ini_set('session.cookie_lifetime', 0);
ini_set('session.gc_maxlifetime', 0);

// Session
session_start();
require 'vendor/autoload.php';

// MongoDB Connection
function getDB() {
    static $db = null;
    if ($db === null) {
        try {
            $client = new MongoDB\Client(MONGO_HOST);
            $db = $client->selectDatabase(MONGO_DB);
        } catch (Exception $e) {
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $db;
}

// Helper: current user
function currentUser() {
    if (!isset($_SESSION['user_id'])) return null;
    $db = getDB();
    $user = $db->users->findOne(['_id' => new MongoDB\BSON\ObjectId($_SESSION['user_id'])]);
    return $user;
}

// Helper: require login
function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }
}

// Helper: require guest
function requireGuest() {
    if (isset($_SESSION['user_id'])) {
        header('Location: discover.php');
        exit;
    }
}

// Helper: format age from birthdate
function calcAge($birthdate) {
    if (empty($birthdate)) return 0;
    try {
        $birth = new DateTime((string)$birthdate);
        $today = new DateTime();
        return $birth->diff($today)->y;
    } catch (Exception $e) {
        return 0;
    }
}

// Helper: distance between two lat/lng (km)
function haversineDistance($lat1, $lon1, $lat2, $lon2) {
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2)*sin($dLat/2) + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)*sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}

// Gestione input JSON per API
function getJsonInput() {
    $input = json_decode(file_get_contents('php://input'), true);
    return is_array($input) ? $input : [];
}
?>