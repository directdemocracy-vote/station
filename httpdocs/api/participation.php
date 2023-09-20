<?php
require_once '../../php/database.php';

function error($message) {
  die("{\"error\":\"$message\"}");
}

function stripped_key($key, $type) {
  $stripped = str_replace("-----BEGIN $type KEY-----", "", $key);
  $stripped = str_replace("-----END $type KEY-----", "", $stripped);
  $stripped = str_replace("\r\n", '', $stripped);
  $stripped = str_replace("\n", '', $stripped);
  return $stripped;
}

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: content-type");

if (!isset($_GET['referendum']))
  error("Missing referendum argument");

$mysqli = new mysqli($database_host, $database_username, $database_password, $database_name);
if ($mysqli->connect_errno)
  error("Failed to connect to MySQL database: $mysqli->connect_error ($mysqli->connect_errno)");
$mysqli->set_charset('utf8mb4');

$referendum = $mysqli->escape_string($_GET['referendum']);
$fingerprint = sha1($referendum);
$result = $mysqli->query ("SELECT publicKey, published FROM participation WHERE referendumFingerprint='$fingerprint'") or error($mysqli->error);
$p = $result->fetch_assoc();
if ($p) {
  $publicKey = $p['publicKey'];
  $published = intval($p['published']);
  $result->free();
} else {
  $config = array("digest_alg" => "sha256", "private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA);
  $keyPair = openssl_pkey_new($config);
  openssl_pkey_export($keyPair, $pk);
  $privateKey = stripped_key($pk, 'PRIVATE'); 
  $details = openssl_pkey_get_details($keyPair);
  $publicKey = stripped_key($details["key"], 'PUBLIC');
  $published = intval(microtime(true) * 1000);
  $query = "INSERT INTO participation(referendum, referendumFingerprint, publicKey, privateKey, published) "
          ."VALUES('$referendum', '$fingerprint', '$publicKey', '$privateKey', $published)";
  $mysqli->query($query) or error($mysqli->error);
}
$mysqli->close();
$participation = array();
$participation['schema'] = 'https://directdemocracy.vote/json-schema/0.0.2/participation.schema.json';
$participation['key'] = stripped_key(file_get_contents('../../id_rsa.pub'), 'PUBLIC');
$participation['signature'] = '';
$participation['published'] = $published;
$participation['referendum'] = $referendum;
$participation['blindKey'] = $publicKey;
$data = json_encode($participation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$pk = openssl_get_privatekey("file://../../id_rsa");
if ($pk === FALSE)
  error("Failed to read private key.");
$signature = '';
$success = openssl_sign($data, $signature, $pk, OPENSSL_ALGO_SHA256);
if ($success === FALSE)
  error("Failed to sign participation.");
$participation['signature'] = base64_encode($signature);
$data = json_encode($participation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
die($data);
?>